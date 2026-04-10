<?php
require_once __DIR__ . '/app_config.php';

date_default_timezone_set((string) app_env('APP_TIMEZONE', 'Europe/Berlin'));

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action !== 'document_download') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $pdo = app_connect_pdo();
} catch (Throwable $e) {
    app_json_response([
        'success' => false,
        'error' => 'DB-Verbindung fehlgeschlagen',
    ], 500);
}

function customer_api_require_method(string $expectedMethod): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== strtoupper($expectedMethod)) {
        app_json_response([
            'success' => false,
            'error' => 'Methode nicht erlaubt',
        ], 405);
    }
}

function customer_api_issue_token(PDO $pdo, int $customerId, ?string $deviceName = null): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        INSERT INTO customer_api_tokens (customer_id, token_hash, device_name, created_at, last_used_at, expires_at, revoked_at)
        VALUES (:customer_id, :token_hash, :device_name, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), NULL)
    ");
    $stmt->execute([
        ':customer_id' => $customerId,
        ':token_hash' => $tokenHash,
        ':device_name' => $deviceName !== '' ? $deviceName : null,
    ]);

    return $token;
}

function customer_api_require_customer(PDO $pdo): array
{
    $token = app_bearer_token();
    if ($token === null || $token === '') {
        app_json_response([
            'success' => false,
            'error' => 'Nicht autorisiert',
        ], 401);
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT t.id AS token_id,
               t.customer_id,
               c.*
        FROM customer_api_tokens t
        JOIN customers c ON c.id = t.customer_id
        WHERE t.token_hash = :token_hash
          AND t.revoked_at IS NULL
          AND t.expires_at >= NOW()
        LIMIT 1
    ");
    $stmt->execute([
        ':token_hash' => $tokenHash,
    ]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        app_json_response([
            'success' => false,
            'error' => 'Nicht autorisiert',
        ], 401);
    }

    $touch = $pdo->prepare('UPDATE customer_api_tokens SET last_used_at = NOW() WHERE id = :id');
    $touch->execute([
        ':id' => $customer['token_id'],
    ]);

    $customer['_token_hash'] = $tokenHash;
    return $customer;
}

function customer_api_pick_problem_type_id(PDO $pdo): int
{
    $preferred = $pdo->query('SELECT id FROM followup_types WHERE id = 1 LIMIT 1')->fetchColumn();
    if ($preferred) {
        return (int) $preferred;
    }

    $fallback = $pdo->query('SELECT id FROM followup_types ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($fallback) {
        return (int) $fallback;
    }

    app_json_response([
        'success' => false,
        'error' => 'Kein Wiedervorlagetyp konfiguriert',
    ], 500);
}

function customer_api_fetch_network(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare("
        SELECT kn.id AS assignment_id,
               p.id AS partner_id,
               p.typ_id,
               t.bezeichnung AS type_label,
               p.name_firma,
               p.vorname,
               p.nachname,
               p.adresse,
               p.telefon,
               p.email
          FROM kunden_netzwerkpartner kn
          JOIN netzwerkpartner p
            ON p.id = kn.netzwerkpartner_id
     LEFT JOIN netzwerkpartner_typen t
            ON t.id = p.typ_id
         WHERE kn.customer_id = :customer_id
      ORDER BY t.bezeichnung ASC, p.name_firma ASC, p.nachname ASC, p.vorname ASC
    ");
    $stmt->execute([
        ':customer_id' => $customerId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

switch ($action) {
    case 'ping':
        app_json_response([
            'success' => true,
            'server_time' => date('c'),
        ]);
        break;

    case 'login':
        customer_api_require_method('POST');
        $input = app_json_input();
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $deviceName = trim((string) ($input['device_name'] ?? ''));

        if ($email === '' || $password === '') {
            app_json_response([
                'success' => false,
                'error' => 'E-Mail und Passwort sind erforderlich',
            ], 422);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE email = :email LIMIT 1');
        $stmt->execute([
            ':email' => $email,
        ]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer || !app_password_matches($password, $customer['password'] ?? null)) {
            app_json_response([
                'success' => false,
                'error' => 'Login fehlgeschlagen',
            ], 401);
        }

        $token = customer_api_issue_token($pdo, (int) $customer['id'], $deviceName);
        unset($customer['password']);

        app_json_response([
            'success' => true,
            'token' => $token,
            'customer' => $customer,
        ]);
        break;

    case 'logout':
        customer_api_require_method('POST');
        $customer = customer_api_require_customer($pdo);
        $stmt = $pdo->prepare('UPDATE customer_api_tokens SET revoked_at = NOW() WHERE token_hash = :token_hash');
        $stmt->execute([
            ':token_hash' => $customer['_token_hash'],
        ]);
        app_json_response([
            'success' => true,
        ]);
        break;

    case 'me':
        $customer = customer_api_require_customer($pdo);
        unset($customer['password'], $customer['_token_hash'], $customer['token_id']);
        app_json_response([
            'success' => true,
            'customer' => $customer,
        ]);
        break;

    case 'me_update':
        customer_api_require_method('POST');
        $customer = customer_api_require_customer($pdo);
        $input = app_json_input();
        $newPassword = trim((string) ($input['password'] ?? ''));
        $passwordToStore = $newPassword !== '' ? $newPassword : (string) ($customer['password'] ?? '');

        $stmt = $pdo->prepare("
            UPDATE customers
            SET anrede = :anrede,
                vorname = :vorname,
                nachname = :nachname,
                adresse = :adresse,
                telefon = :telefon,
                email = :email,
                password = :password
            WHERE id = :id
        ");
        $stmt->execute([
            ':anrede' => trim((string) ($input['anrede'] ?? '')),
            ':vorname' => trim((string) ($input['vorname'] ?? '')),
            ':nachname' => trim((string) ($input['nachname'] ?? '')),
            ':adresse' => trim((string) ($input['adresse'] ?? '')),
            ':telefon' => trim((string) ($input['telefon'] ?? '')),
            ':email' => trim((string) ($input['email'] ?? '')),
            ':password' => $passwordToStore,
            ':id' => (int) $customer['id'],
        ]);

        $fresh = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $fresh->execute([
            ':id' => (int) $customer['id'],
        ]);
        $updated = $fresh->fetch(PDO::FETCH_ASSOC) ?: [];
        unset($updated['password']);

        app_json_response([
            'success' => true,
            'customer' => $updated,
        ]);
        break;

    case 'status_types':
        customer_api_require_customer($pdo);
        $stmt = $pdo->query("
            SELECT id, bezeichnung
            FROM status
            WHERE kundenkennung_flag = 1
            ORDER BY bezeichnung ASC
        ");
        app_json_response([
            'success' => true,
            'types' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;

    case 'status_list':
        $customer = customer_api_require_customer($pdo);
        $stmt = $pdo->prepare("
            SELECT cs.id,
                   cs.status_id,
                   cs.notiz AS note,
                   cs.created_at,
                   cs.updated_at,
                   s.bezeichnung,
                   COALESCE(b.username, 'Kunde') AS user
            FROM customer_status cs
            JOIN status s ON s.id = cs.status_id
            LEFT JOIN backoffice_users b ON b.id = cs.backoffice_user_id
            WHERE cs.customer_id = :customer_id
              AND s.kundenkennung_flag = 1
            ORDER BY cs.created_at DESC, cs.id DESC
        ");
        $stmt->execute([
            ':customer_id' => (int) $customer['id'],
        ]);
        app_json_response([
            'success' => true,
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        break;

    case 'status_add':
        customer_api_require_method('POST');
        $customer = customer_api_require_customer($pdo);
        $input = app_json_input();
        $statusId = (int) ($input['status_id'] ?? 0);
        $note = trim((string) ($input['note'] ?? ''));

        if ($statusId <= 0) {
            app_json_response([
                'success' => false,
                'error' => 'Bitte einen Status auswählen',
            ], 422);
        }

        $check = $pdo->prepare("
            SELECT bezeichnung
            FROM status
            WHERE id = :id
              AND kundenkennung_flag = 1
            LIMIT 1
        ");
        $check->execute([
            ':id' => $statusId,
        ]);
        $statusRow = $check->fetch(PDO::FETCH_ASSOC);
        if (!$statusRow) {
            app_json_response([
                'success' => false,
                'error' => 'Ungültiger Status',
            ], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO customer_status (customer_id, status_id, status_bezeichnung, notiz, backoffice_user_id, created_at, updated_at)
            VALUES (:customer_id, :status_id, :status_bezeichnung, :note, NULL, NOW(), NOW())
        ");
        $stmt->execute([
            ':customer_id' => (int) $customer['id'],
            ':status_id' => $statusId,
            ':status_bezeichnung' => $statusRow['bezeichnung'],
            ':note' => $note,
        ]);

        app_json_response([
            'success' => true,
            'id' => (int) $pdo->lastInsertId(),
        ]);
        break;

    case 'documents_list':
        $customer = customer_api_require_customer($pdo);
        $stmt = $pdo->prepare("
            SELECT id, label, note, filename, mime_type, created_at, created_by
            FROM documents
            WHERE customer_id = :customer_id
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute([
            ':customer_id' => (int) $customer['id'],
        ]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $appBaseUrl = rtrim((string) app_env('APP_BASE_URL', ''), '/');
        foreach ($documents as &$document) {
            $document['download_path'] = 'customer_api.php?action=document_download&id=' . rawurlencode((string) $document['id']);
            $document['download_url'] = $appBaseUrl !== ''
                ? $appBaseUrl . '/' . $document['download_path']
                : null;
        }

        app_json_response([
            'success' => true,
            'documents' => $documents,
        ]);
        break;

    case 'document_download':
        $customer = customer_api_require_customer($pdo);
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            app_json_response([
                'success' => false,
                'error' => 'Ungültige Dokument-ID',
            ], 422);
        }

        $stmt = $pdo->prepare("
            SELECT id, customer_id, filename, mime_type, content_base64
            FROM documents
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $id,
        ]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document || (int) $document['customer_id'] !== (int) $customer['id']) {
            app_json_response([
                'success' => false,
                'error' => 'Dokument nicht gefunden',
            ], 404);
        }

        $binary = base64_decode((string) $document['content_base64'], true);
        if ($binary === false) {
            app_json_response([
                'success' => false,
                'error' => 'Dokument konnte nicht dekodiert werden',
            ], 500);
        }

        header('Content-Type: ' . ($document['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename((string) $document['filename']) . '"');
        header('Content-Length: ' . strlen($binary));
        echo $binary;
        exit;

    case 'document_upload':
        customer_api_require_method('POST');
        $customer = customer_api_require_customer($pdo);
        $label = trim((string) ($_POST['label'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            app_json_response([
                'success' => false,
                'error' => 'Datei-Upload fehlgeschlagen',
            ], 422);
        }

        $tmpName = $_FILES['file']['tmp_name'];
        $filename = $_FILES['file']['name'] ?: 'upload.bin';
        $mime = $_FILES['file']['type'] ?: 'application/octet-stream';
        $binary = @file_get_contents($tmpName);
        if ($binary === false) {
            app_json_response([
                'success' => false,
                'error' => 'Datei konnte nicht gelesen werden',
            ], 500);
        }

        $stmt = $pdo->prepare("
            INSERT INTO documents (customer_id, label, note, filename, mime_type, content_base64, created_at, created_by)
            VALUES (:customer_id, :label, :note, :filename, :mime_type, :content_base64, NOW(), 'customer-api')
        ");
        $stmt->execute([
            ':customer_id' => (int) $customer['id'],
            ':label' => $label !== '' ? $label : null,
            ':note' => $note !== '' ? $note : null,
            ':filename' => $filename,
            ':mime_type' => $mime,
            ':content_base64' => base64_encode($binary),
        ]);

        app_json_response([
            'success' => true,
            'id' => (int) $pdo->lastInsertId(),
        ]);
        break;

    case 'problem_report':
        customer_api_require_method('POST');
        $customer = customer_api_require_customer($pdo);
        $input = app_json_input();
        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            app_json_response([
                'success' => false,
                'error' => 'Bitte eine Problembeschreibung eingeben',
            ], 422);
        }

        $typeId = customer_api_pick_problem_type_id($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO followups (customer_id, type_id, due_date, note, `status`)
            VALUES (:customer_id, :type_id, NOW(), :note, 'neu')
        ");
        $stmt->execute([
            ':customer_id' => (int) $customer['id'],
            ':type_id' => $typeId,
            ':note' => $note,
        ]);

        app_json_response([
            'success' => true,
            'id' => (int) $pdo->lastInsertId(),
        ]);
        break;

    case 'network_list':
        $customer = customer_api_require_customer($pdo);
        app_json_response([
            'success' => true,
            'partners' => customer_api_fetch_network($pdo, (int) $customer['id']),
        ]);
        break;

    case 'home_summary':
        $customer = customer_api_require_customer($pdo);
        $documentsCount = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE customer_id = :customer_id');
        $documentsCount->execute([
            ':customer_id' => (int) $customer['id'],
        ]);

        $statusCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM customer_status cs
            JOIN status s ON s.id = cs.status_id
            WHERE cs.customer_id = :customer_id
              AND s.kundenkennung_flag = 1
        ");
        $statusCount->execute([
            ':customer_id' => (int) $customer['id'],
        ]);

        app_json_response([
            'success' => true,
            'summary' => [
                'customer_id' => (int) $customer['id'],
                'documents_count' => (int) $documentsCount->fetchColumn(),
                'status_count' => (int) $statusCount->fetchColumn(),
            ],
        ]);
        break;
}

app_json_response([
    'success' => false,
    'error' => 'Unbekannte Aktion',
], 404);
