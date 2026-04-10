<?php
require_once __DIR__ . '/app_config.php';

session_start();
date_default_timezone_set((string) app_env('APP_TIMEZONE', 'Europe/Berlin'));
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = app_connect_pdo();
} catch (PDOException $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "DB-Verbindung fehlgeschlagen"]);
    exit;
}

// --- Helpers ---
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);
if (!is_array($input)) $input = [];

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function require_backoffice() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'backoffice') {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "Nicht autorisiert (Backoffice)"]);
        exit;
    }
}

function require_customer() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "Nicht autorisiert (Kunde)"]);
        exit;
    }
}

function normalize_datetime($str) {
    if (!$str) return null;
    $s = str_replace('T', ' ', $str);
    $s = substr($s, 0, 19);
    if (strlen($s) == 10) $s .= " 00:00:00";
    if (strlen($s) == 16) $s .= ":00";
    return $s;
}

function fetch_customer_network_partners(PDO $pdo, int $customerId): array {
    $stmt = $pdo->prepare("
        SELECT kn.id AS zuordnung_id,
               p.id AS partner_id,
               p.typ_id,
               t.bezeichnung AS typ_bezeichnung,
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
         WHERE kn.customer_id = ?
      ORDER BY t.bezeichnung ASC, p.name_firma ASC, p.nachname ASC, p.vorname ASC
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function app_setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function app_setting_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$key, $value]);
}

function app_setting_int(PDO $pdo, string $key, int $default = 0, int $min = 0, int $max = 365): int
{
    $value = app_setting_get($pdo, $key, (string) $default);
    $intValue = is_numeric($value) ? intval($value) : $default;
    if ($intValue < $min) {
        return $min;
    }
    if ($intValue > $max) {
        return $max;
    }
    return $intValue;
}

function backoffice_alert_followup_days(PDO $pdo): int
{
    return app_setting_int($pdo, 'backoffice_alert_followup_days', 3, 0, 365);
}

function format_customer_name(array $row): string
{
    return trim(
        ($row['anrede'] ?? '') . ' ' .
        ($row['vorname'] ?? '') . ' ' .
        ($row['nachname'] ?? '')
    );
}

header("Content-Type: application/json; charset=utf-8");

// =======================================================
// ROUTING
// =======================================================
switch ($action) {

    // ---------------------------------------------------
    // AUTH
    // ---------------------------------------------------
    case 'login_backoffice':
        if ($method !== 'POST') break;
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM backoffice_users WHERE username = ? AND password = ? LIMIT 1");
        $stmt->execute([$username, $password]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(["success" => false, "error" => "Login fehlgeschlagen"]);
            exit;
        }

        $_SESSION['role'] = 'backoffice';
        $_SESSION['backoffice_user_id'] = $user['id'];
        $_SESSION['backoffice_username'] = $user['username'];

        echo json_encode(["success" => true, "user" => [
            "id" => $user['id'],
            "username" => $user['username'],
            "vorname" => $user['vorname'] ?? "",
            "nachname" => $user['nachname'] ?? ""
        ]]);
        exit;

    case 'login_customer':
        if ($method !== 'POST') break;
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? AND password = ? LIMIT 1");
        $stmt->execute([$email, $password]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$c) {
            echo json_encode(["success" => false, "error" => "Login fehlgeschlagen"]);
            exit;
        }

        $_SESSION['role'] = 'customer';
        $_SESSION['customer_id'] = $c['id'];

        echo json_encode(["success" => true, "customer_id" => $c['id']]);
        exit;

    case 'logout':
        session_unset();
        session_destroy();
        session_start();
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // BACKOFFICE: KUNDEN
    // ---------------------------------------------------
    case 'customers_list':
        require_backoffice();
        $stmt = $pdo->query("SELECT id, email, anrede, vorname, nachname, adresse, telefon
                             FROM customers
                             ORDER BY nachname, vorname");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "customers" => $rows]);
        exit;

    case 'customer_get':
        require_backoffice();
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            echo json_encode(["success" => false, "error" => "Kunde nicht gefunden"]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT cs.id, cs.customer_id, cs.status_id, cs.notiz,
                   cs.created_at, cs.updated_at,
                   s.bezeichnung AS status_bezeichnung,
                   b.username AS backoffice_username
            FROM customer_status cs
            JOIN status s ON s.id = cs.status_id
            LEFT JOIN backoffice_users b ON b.id = cs.backoffice_user_id
            WHERE cs.customer_id = ?
            ORDER BY cs.created_at DESC
        ");
        $stmt->execute([$id]);
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "customer" => $c, "statuses" => $statuses]);
        exit;

        // ---------------------------------------------------
    // KUNDE SPEICHERN (neu oder update)
    // ---------------------------------------------------
        // ---------------------------------------------------
    // KUNDE SPEICHERN (neu oder update) – ohne created_at / updated_at
    // ---------------------------------------------------
    case 'customer_save':
        require_backoffice();
        header("Content-Type: application/json; charset=utf-8");

        if ($method !== 'POST') {
            echo json_encode([
                "success" => false,
                "error"   => "Methode nicht erlaubt"
            ]);
            exit;
        }

        // $input kommt aus dem Code ganz oben:
        global $input;

        $id               = isset($input['id']) ? (int)$input['id'] : 0;
        $anrede           = trim($input['anrede']           ?? '');
        $vorname          = trim($input['vorname']          ?? '');
        $nachname         = trim($input['nachname']         ?? '');
        $adresse          = trim($input['adresse']          ?? '');
        $telefon          = trim($input['telefon']          ?? '');
        $email            = trim($input['email']            ?? '');
        $password         = trim($input['password']         ?? '');

        $kontakt_anrede   = trim($input['kontakt_anrede']   ?? '');
        $kontakt_vorname  = trim($input['kontakt_vorname']  ?? '');
        $kontakt_nachname = trim($input['kontakt_nachname'] ?? '');
        $kontakt_adresse  = trim($input['kontakt_adresse']  ?? '');
        $kontakt_telefon  = trim($input['kontakt_telefon']  ?? '');

        try {
            if ($id > 0) {
                // UPDATE
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET
                        anrede           = :anrede,
                        vorname          = :vorname,
                        nachname         = :nachname,
                        adresse          = :adresse,
                        telefon          = :telefon,
                        email            = :email,
                        password         = :password,
                        kontakt_anrede   = :kontakt_anrede,
                        kontakt_vorname  = :kontakt_vorname,
                        kontakt_nachname = :kontakt_nachname,
                        kontakt_adresse  = :kontakt_adresse,
                        kontakt_telefon  = :kontakt_telefon
                    WHERE id = :id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':anrede'           => $anrede,
                    ':vorname'          => $vorname,
                    ':nachname'         => $nachname,
                    ':adresse'          => $adresse,
                    ':telefon'          => $telefon,
                    ':email'            => $email,
                    ':password'         => $password,
                    ':kontakt_anrede'   => $kontakt_anrede,
                    ':kontakt_vorname'  => $kontakt_vorname,
                    ':kontakt_nachname' => $kontakt_nachname,
                    ':kontakt_adresse'  => $kontakt_adresse,
                    ':kontakt_telefon'  => $kontakt_telefon,
                    ':id'               => $id
                ]);

            } else {
                // INSERT
                $stmt = $pdo->prepare("
                    INSERT INTO customers
                        (anrede, vorname, nachname, adresse, telefon, email, password,
                         kontakt_anrede, kontakt_vorname, kontakt_nachname,
                         kontakt_adresse, kontakt_telefon)
                    VALUES
                        (:anrede, :vorname, :nachname, :adresse, :telefon, :email, :password,
                         :kontakt_anrede, :kontakt_vorname, :kontakt_nachname,
                         :kontakt_adresse, :kontakt_telefon)
                ");

                $stmt->execute([
                    ':anrede'           => $anrede,
                    ':vorname'          => $vorname,
                    ':nachname'         => $nachname,
                    ':adresse'          => $adresse,
                    ':telefon'          => $telefon,
                    ':email'            => $email,
                    ':password'         => $password,
                    ':kontakt_anrede'   => $kontakt_anrede,
                    ':kontakt_vorname'  => $kontakt_vorname,
                    ':kontakt_nachname' => $kontakt_nachname,
                    ':kontakt_adresse'  => $kontakt_adresse,
                    ':kontakt_telefon'  => $kontakt_telefon
                ]);

                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode([
                "success" => true,
                "id"      => $id
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "error"   => "DB-Fehler: " . $e->getMessage()
            ]);
        }

        exit;



    case 'customer_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // KUNDENPORTAL
    // ---------------------------------------------------
    case 'customer_self_get':
        require_customer();
        $id = $_SESSION['customer_id'];
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            echo json_encode(["success" => false, "error" => "Kunde nicht gefunden"]);
            exit;
        }
        echo json_encode(["success" => true, "customer" => $c]);
        exit;

    case 'customer_self_network':
        require_customer();
        $customerId = (int)($_SESSION['customer_id'] ?? 0);
        echo json_encode([
            "success" => true,
            "partners" => fetch_customer_network_partners($pdo, $customerId)
        ]);
        exit;

    case 'customer_self_update':
        require_customer();
        if ($method !== 'POST') break;
        $id = $_SESSION['customer_id'];

        $anrede  = $input['anrede'] ?? '';
        $vorname = $input['vorname'] ?? '';
        $nachname = $input['nachname'] ?? '';
        $adresse = $input['adresse'] ?? '';
        $telefon = $input['telefon'] ?? '';
        $email   = $input['email'] ?? '';
        $password = trim((string)($input['password'] ?? ''));

        if ($password === '') {
            $stmt = $pdo->prepare("SELECT password FROM customers WHERE id = ?");
            $stmt->execute([$id]);
            $password = (string)($stmt->fetchColumn() ?? '');
        }

        $stmt = $pdo->prepare("
            UPDATE customers SET
                anrede = ?, vorname = ?, nachname = ?, adresse = ?, telefon = ?,
                email = ?, password = ?
            WHERE id = ?
        ");
        $stmt->execute([$anrede, $vorname, $nachname, $adresse, $telefon, $email, $password, $id]);
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // STATUS-MASTER & CUSTOMER-STATUS
    // ---------------------------------------------------
    case 'status_list':
        require_backoffice();
        $stmt = $pdo->query("SELECT id, bezeichnung, kundenkennung_flag FROM status ORDER BY bezeichnung");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "statuses" => $rows]);
        exit;

    case 'status_save':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        $bez = $input['bezeichnung'] ?? '';
        $flag = intval($input['kundenkennung_flag'] ?? 0);

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE status SET bezeichnung = ?, kundenkennung_flag = ? WHERE id = ?");
            $stmt->execute([$bez, $flag, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO status (bezeichnung, kundenkennung_flag) VALUES (?, ?)");
            $stmt->execute([$bez, $flag]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(["success" => true, "id" => $id]);
        exit;

    case 'status_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM status WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;

    case 'customer_status_save':
        require_backoffice();
        if ($method !== 'POST') break;

        $id          = intval($input['id'] ?? 0);
        $customer_id = intval($input['customer_id'] ?? 0);
        $status_id   = intval($input['status_id'] ?? 0);
        $notiz       = $input['notiz'] ?? '';
        $bo_user_id  = $_SESSION['backoffice_user_id'] ?? null;

        if ($customer_id <= 0 || $status_id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige Daten"]);
            exit;
        }

        // Bezeichnung aus status-Tabelle holen, damit status_bezeichnung befüllt wird
        try {
            $stmt = $pdo->prepare("SELECT bezeichnung FROM status WHERE id = ?");
            $stmt->execute([$status_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(["success" => false, "error" => "Status nicht gefunden"]);
                exit;
            }
            $status_bezeichnung = $row['bezeichnung'];
        } catch (PDOException $e) {
            echo json_encode(["success" => false, "error" => "SQL-Fehler (Status): ".$e->getMessage()]);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE customer_status
                       SET status_id = ?, status_bezeichnung = ?, notiz = ?, backoffice_user_id = ?
                     WHERE id = ?
                ");
                $stmt->execute([$status_id, $status_bezeichnung, $notiz, $bo_user_id, $id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_status
                        (customer_id, status_id, status_bezeichnung, notiz, backoffice_user_id)
                    VALUES (?,?,?,?,?)
                ");
                $stmt->execute([$customer_id, $status_id, $status_bezeichnung, $notiz, $bo_user_id]);
                $id = $pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            echo json_encode([
                "success" => false,
                "error"   => "SQL-Fehler: " . $e->getMessage()
            ]);
            exit;
        }

        echo json_encode(["success" => true, "id" => $id]);
        exit;



    case 'customer_status_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM customer_status WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // BACKOFFICE USER
    // ---------------------------------------------------
    case 'backoffice_users_list':
        require_backoffice();
        $stmt = $pdo->query("SELECT id, username, vorname, nachname, email, berufs_id FROM backoffice_users ORDER BY username");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "users" => $rows]);
        exit;

    case 'backoffice_user_get':
        require_backoffice();
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM backoffice_users WHERE id = ?");
        $stmt->execute([$id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            echo json_encode(["success" => false, "error" => "Benutzer nicht gefunden"]);
            exit;
        }
        echo json_encode(["success" => true, "user" => $u]);
        exit;

    case 'backoffice_user_save':
        require_backoffice();
        if ($method !== 'POST') break;

        $id = intval($input['id'] ?? 0);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $vorname  = $input['vorname'] ?? '';
        $nachname = $input['nachname'] ?? '';
        $adresse  = $input['adresse'] ?? '';
        $telefon  = $input['telefon'] ?? '';
        $email    = $input['email'] ?? '';
        $berufs_id = $input['berufs_id'] ?? null;

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE backoffice_users
                   SET username = ?, password = ?, vorname = ?, nachname = ?,
                       adresse = ?, telefon = ?, email = ?, berufs_id = ?
                 WHERE id = ?
            ");
            $stmt->execute([$username, $password, $vorname, $nachname,
                $adresse, $telefon, $email, $berufs_id, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO backoffice_users
                    (username, password, vorname, nachname, adresse, telefon, email, berufs_id)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$username, $password, $vorname, $nachname,
                $adresse, $telefon, $email, $berufs_id]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(["success" => true, "id" => $id]);
        exit;

    case 'backoffice_user_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM backoffice_users WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // IMAP/KIM SETTINGS
    // ---------------------------------------------------
    case 'imap_get_settings':
        require_backoffice();
        $stmt = $pdo->query("SELECT id, host, port, encryption, username, password, mailbox FROM imap_settings LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
            $settings = [
                "id" => null,
                "host" => "",
                "port" => 993,
                "encryption" => "ssl",
                "username" => "",
                "password" => "",
                "mailbox" => "INBOX"
            ];
        }
        echo json_encode(["success" => true, "settings" => $settings]);
        exit;

    case 'imap_save_settings':
        require_backoffice();
        if ($method !== 'POST') break;

        $host       = $input['host'] ?? '';
        $port       = intval($input['port'] ?? 993);
        $encryption = $input['encryption'] ?? 'ssl';
        $username   = $input['username'] ?? '';
        $password   = $input['password'] ?? '';
        $mailbox    = $input['mailbox'] ?? 'INBOX';

        $stmt = $pdo->query("SELECT id FROM imap_settings LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stmt = $pdo->prepare("
                UPDATE imap_settings
                   SET host = ?, port = ?, encryption = ?, username = ?, password = ?, mailbox = ?
                 WHERE id = ?
            ");
            $stmt->execute([$host, $port, $encryption, $username, $password, $mailbox, $row['id']]);
            $id = $row['id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO imap_settings (host, port, encryption, username, password, mailbox)
                VALUES (?,?,?,?,?,?)
            ");
            $stmt->execute([$host, $port, $encryption, $username, $password, $mailbox]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(["success" => true, "id" => $id]);
        exit;

    case 'backoffice_alert_settings_get':
        require_backoffice();
        $days = backoffice_alert_followup_days($pdo);
        echo json_encode([
            "success" => true,
            "settings" => [
                "followup_age_days" => $days
            ]
        ]);
        exit;

    case 'backoffice_alert_settings_save':
        require_backoffice();
        if ($method !== 'POST') break;

        $days = intval($input['followup_age_days'] ?? 3);
        if ($days < 0) {
            $days = 0;
        }
        if ($days > 365) {
            $days = 365;
        }

        app_setting_set($pdo, 'backoffice_alert_followup_days', (string) $days);

        echo json_encode([
            "success" => true,
            "settings" => [
                "followup_age_days" => $days
            ]
        ]);
        exit;

    case 'backoffice_alerts_feed':
        require_backoffice();

        $customerStatusLimit = intval($_GET['status_limit'] ?? 6);
        $followupLimit = intval($_GET['followup_limit'] ?? 6);
        $documentLimit = intval($_GET['document_limit'] ?? 6);
        $customerStatusLimit = max(1, min($customerStatusLimit, 12));
        $followupLimit = max(1, min($followupLimit, 12));
        $documentLimit = max(1, min($documentLimit, 12));
        $followupDays = backoffice_alert_followup_days($pdo);

        $customerStatusTotalStmt = $pdo->query("
            SELECT COUNT(*)
            FROM customer_status cs
            JOIN status s ON s.id = cs.status_id
            WHERE cs.backoffice_user_id IS NULL
              AND s.kundenkennung_flag = 1
        ");
        $customerStatusTotal = intval($customerStatusTotalStmt->fetchColumn() ?: 0);

        $customerStatusStmt = $pdo->query("
            SELECT cs.id,
                   cs.customer_id,
                   cs.status_id,
                   COALESCE(NULLIF(cs.status_bezeichnung, ''), s.bezeichnung) AS status_bezeichnung,
                   cs.notiz,
                   cs.created_at,
                   c.anrede,
                   c.vorname,
                   c.nachname
            FROM customer_status cs
            JOIN status s ON s.id = cs.status_id
            JOIN customers c ON c.id = cs.customer_id
            WHERE cs.backoffice_user_id IS NULL
              AND s.kundenkennung_flag = 1
            ORDER BY cs.created_at DESC, cs.id DESC
            LIMIT {$customerStatusLimit}
        ");
        $customerStatuses = $customerStatusStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($customerStatuses as &$customerStatus) {
            $customerStatus['customer_name'] = format_customer_name($customerStatus);
        }
        unset($customerStatus);

        $followupCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM followups f
            WHERE f.`status` = 'neu'
              AND TIMESTAMPDIFF(DAY, f.created_at, NOW()) >= ?
        ");
        $followupCountStmt->execute([$followupDays]);
        $followupTotal = intval($followupCountStmt->fetchColumn() ?: 0);

        $followupStmt = $pdo->prepare("
            SELECT f.id,
                   f.customer_id,
                   f.type_id,
                   f.due_date,
                   f.note,
                   f.`status`,
                   f.created_at,
                   t.bezeichnung AS typ_bezeichnung,
                   t.color_code,
                   c.anrede,
                   c.vorname,
                   c.nachname,
                   TIMESTAMPDIFF(DAY, f.created_at, NOW()) AS age_days
            FROM followups f
            JOIN customers c ON c.id = f.customer_id
            JOIN followup_types t ON t.id = f.type_id
            WHERE f.`status` = 'neu'
              AND TIMESTAMPDIFF(DAY, f.created_at, NOW()) >= ?
            ORDER BY f.due_date ASC, f.created_at ASC, f.id ASC
            LIMIT {$followupLimit}
        ");
        $followupStmt->execute([$followupDays]);
        $followups = $followupStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($followups as &$followup) {
            $followup['customer_name'] = format_customer_name($followup);
        }
        unset($followup);

        $documentCountStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM documents d
            WHERE d.created_by IN ('customer', 'customer-api')
              AND TIMESTAMPDIFF(DAY, d.created_at, NOW()) <= ?
        ");
        $documentCountStmt->execute([$followupDays]);
        $documentTotal = intval($documentCountStmt->fetchColumn() ?: 0);

        $documentStmt = $pdo->prepare("
            SELECT d.id,
                   d.customer_id,
                   d.label,
                   d.filename,
                   d.mime_type,
                   d.created_at,
                   d.created_by,
                   c.anrede,
                   c.vorname,
                   c.nachname,
                   TIMESTAMPDIFF(DAY, d.created_at, NOW()) AS age_days
            FROM documents d
            JOIN customers c ON c.id = d.customer_id
            WHERE d.created_by IN ('customer', 'customer-api')
              AND TIMESTAMPDIFF(DAY, d.created_at, NOW()) <= ?
            ORDER BY d.created_at DESC, d.id DESC
            LIMIT {$documentLimit}
        ");
        $documentStmt->execute([$followupDays]);
        $documents = $documentStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$document) {
            $document['customer_name'] = format_customer_name($document);
        }
        unset($document);

        echo json_encode([
            "success" => true,
            "generated_at" => date('c'),
            "settings" => [
                "followup_age_days" => $followupDays,
                "customer_status_limit" => $customerStatusLimit,
                "followup_limit" => $followupLimit,
                "document_limit" => $documentLimit
            ],
            "customer_status_total" => $customerStatusTotal,
            "customer_statuses" => $customerStatuses,
            "followup_total" => $followupTotal,
            "followups" => $followups,
            "document_total" => $documentTotal,
            "documents" => $documents
        ]);
        exit;

    // ---------------------------------------------------
    // WIEDERVORLAGE-TYPEN
    // ---------------------------------------------------
    case 'followup_types_list':
        require_backoffice();
        $stmt = $pdo->query("SELECT id, bezeichnung, color_code FROM followup_types ORDER BY bezeichnung");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["success" => true, "types" => $rows]);
        exit;

    case 'followup_type_save':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        $bez = $input['bezeichnung'] ?? '';
        $color = $input['color_code'] ?? '#dde4ff';

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE followup_types SET bezeichnung = ?, color_code = ? WHERE id = ?");
            $stmt->execute([$bez, $color, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO followup_types (bezeichnung, color_code) VALUES (?, ?)");
            $stmt->execute([$bez, $color]);
            $id = $pdo->lastInsertId();
        }

        echo json_encode(["success" => true, "id" => $id]);
        exit;

    case 'followup_type_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM followup_types WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;

    // ---------------------------------------------------
    // WIEDERVORLAGEN
    // ---------------------------------------------------
    case 'followup_list':
        require_backoffice();
        $customerId = intval($_GET['customer_id'] ?? 0);

        if ($customerId > 0) {
            $stmt = $pdo->prepare("
                SELECT f.id, f.customer_id, f.type_id, f.due_date, f.note, f.`status`,
                       c.anrede, c.vorname, c.nachname,
                       t.bezeichnung AS typ_bezeichnung,
                       t.color_code
                FROM followups f
                JOIN customers c ON c.id = f.customer_id
                JOIN followup_types t ON t.id = f.type_id
                WHERE f.customer_id = ?
                ORDER BY (f.`status` = 'erledigt'), f.due_date ASC
            ");
            $stmt->execute([$customerId]);
        } else {
            $stmt = $pdo->query("
                SELECT f.id, f.customer_id, f.type_id, f.due_date, f.note, f.`status`,
                       c.anrede, c.vorname, c.nachname,
                       t.bezeichnung AS typ_bezeichnung,
                       t.color_code
                FROM followups f
                JOIN customers c ON c.id = f.customer_id
                JOIN followup_types t ON t.id = f.type_id
                ORDER BY (f.`status` = 'erledigt'), f.due_date ASC
            ");
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['customer_name'] = trim(($r['anrede'] ?? '') . ' ' . ($r['vorname'] ?? '') . ' ' . ($r['nachname'] ?? ''));
        }
        echo json_encode(["success" => true, "followups" => $rows]);
        exit;

    case 'followup_get':
        require_backoffice();
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT f.id, f.customer_id, f.type_id, f.due_date, f.note, f.`status`,
                   c.anrede, c.vorname, c.nachname,
                   t.bezeichnung AS typ_bezeichnung,
                   t.color_code
            FROM followups f
            JOIN customers c ON c.id = f.customer_id
            JOIN followup_types t ON t.id = f.type_id
            WHERE f.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$f) {
            echo json_encode(["success" => false, "error" => "Wiedervorlage nicht gefunden"]);
            exit;
        }
        $f['customer_name'] = trim(($f['anrede'] ?? '') . ' ' . ($f['vorname'] ?? '') . ' ' . ($f['nachname'] ?? ''));
        echo json_encode(["success" => true, "followup" => $f]);
        exit;

    case 'followup_save':
        require_backoffice();
        if ($method !== 'POST') break;

        $id = intval($input['id'] ?? 0);
        $customer_id = intval($input['customer_id'] ?? 0);
        $type_id     = intval($input['type_id'] ?? 0);
        $due_date    = normalize_datetime($input['due_date'] ?? '');
        $note        = $input['note'] ?? '';
        $status      = $input['status'] ?? 'neu';
        $new_due     = normalize_datetime($input['new_due_date'] ?? '');

        if ($customer_id <= 0 || $type_id <= 0 || !$due_date) {
            echo json_encode(["success" => false, "error" => "Ungültige Daten"]);
            exit;
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE followups
                   SET customer_id = ?, type_id = ?, due_date = ?, note = ?, `status` = ?
                 WHERE id = ?
            ");
            $stmt->execute([$customer_id, $type_id, $due_date, $note, $status, $id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO followups (customer_id, type_id, due_date, note, `status`)
                VALUES (?,?,?,?,?)
            ");
            $stmt->execute([$customer_id, $type_id, $due_date, $note, $status]);
            $id = $pdo->lastInsertId();
        }

        $newId = null;
        if ($new_due) {
            $stmt = $pdo->prepare("
                INSERT INTO followups (customer_id, type_id, due_date, note, `status`)
                VALUES (?,?,?,?, 'neu')
            ");
            $stmt->execute([$customer_id, $type_id, $new_due, $note]);
            $newId = $pdo->lastInsertId();
        }

        echo json_encode(["success" => true, "id" => $id, "new_id" => $newId]);
        exit;

    case 'followup_delete':
        require_backoffice();
        if ($method !== 'POST') break;
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige ID"]);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM followups WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(["success" => true]);
        exit;
        // ---------------------------------------------------
    // KUNDE: Problem melden -> erzeugt Wiedervorlage
    // ---------------------------------------------------
    case 'customer_problem':
        require_customer();
        if ($method !== 'POST') break;

        $customer_id = $_SESSION['customer_id'];
        $note = $input['note'] ?? '';

        // Standardwerte laut Spezifikation
        $type_id  = 1;             // Typ mit ID = 1
        $status   = 'neu';         // Status neu
        $due_date = date('Y-m-d H:i:s');  // jetzt

        if (!$note) {
            echo json_encode(["success" => false, "error" => "Bitte eine Problem-Beschreibung eingeben."]);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO followups (customer_id, type_id, due_date, note, `status`)
            VALUES (?,?,?,?,?)
        ");
        $stmt->execute([$customer_id, $type_id, $due_date, $note, $status]);
        $id = $pdo->lastInsertId();

        echo json_encode(["success" => true, "id" => $id]);
        exit;
        case 'network_types_list':
        require_backoffice();
        try {
            $stmt = $pdo->query("
                SELECT id, bezeichnung
                FROM netzwerkpartner_typen
                ORDER BY bezeichnung ASC
            ");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'types'   => $types
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Laden der Netzwerkpartnertypen.'
            ]);
        }
        exit;


    case 'network_type_save':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id   = isset($input['id']) ? (int)$input['id'] : 0;
            $bez  = trim($input['bezeichnung'] ?? '');

            if ($bez === '') {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Bezeichnung darf nicht leer sein.'
                ]);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE netzwerkpartner_typen
                       SET bezeichnung = ?
                     WHERE id = ?
                ");
                $stmt->execute([$bez, $id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO netzwerkpartner_typen (bezeichnung)
                    VALUES (?)
                ");
                $stmt->execute([$bez]);
                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode([
                'success' => true,
                'id'      => $id
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Speichern des Netzwerkpartnertyps.'
            ]);
        }
        exit;


    case 'network_type_delete':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id    = isset($input['id']) ? (int)$input['id'] : 0;

            if ($id <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige ID.'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM netzwerkpartner_typen WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                'success' => true
            ]);
        } catch (PDOException $e) {
            // z.B. FK-Constraint, wenn noch Partner diesen Typ nutzen
            echo json_encode([
                'success' => false,
                'error'   => 'Typ kann nicht gelöscht werden (vermutlich noch in Verwendung).'
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Löschen des Netzwerkpartnertyps.'
            ]);
        }
        exit;
        case 'network_partners_list':
        require_backoffice();
        try {
            $stmt = $pdo->query("
                SELECT p.id,
                       p.typ_id,
                       t.bezeichnung AS typ_bezeichnung,
                       p.name_firma,
                       p.vorname,
                       p.nachname,
                       p.adresse,
                       p.telefon,
                       p.email
                  FROM netzwerkpartner p
             LEFT JOIN netzwerkpartner_typen t
                    ON t.id = p.typ_id
              ORDER BY t.bezeichnung ASC, p.name_firma ASC
            ");
            $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'  => true,
                'partners' => $partners
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Laden der Netzwerkpartner.'
            ]);
        }
        exit;


    case 'network_partner_get':
        require_backoffice();
        try {
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige ID.'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id,
                       typ_id,
                       name_firma,
                       vorname,
                       nachname,
                       adresse,
                       telefon,
                       email
                  FROM netzwerkpartner
                 WHERE id = ?
            ");
            $stmt->execute([$id]);
            $partner = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$partner) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Netzwerkpartner nicht gefunden.'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'partner' => $partner
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Laden des Netzwerkpartners.'
            ]);
        }
        exit;


    case 'network_partner_save':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $id        = isset($input['id']) ? (int)$input['id'] : 0;
            $typ_id    = isset($input['typ_id']) ? (int)$input['typ_id'] : 0;
            $nameFirma = trim($input['name_firma'] ?? '');
            $vorname   = trim($input['vorname'] ?? '');
            $nachname  = trim($input['nachname'] ?? '');
            $adresse   = trim($input['adresse'] ?? '');
            $telefon   = trim($input['telefon'] ?? '');
            $email     = trim($input['email'] ?? '');

            if ($typ_id <= 0 || $nameFirma === '') {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Typ und Name/Firma sind Pflichtfelder.'
                ]);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE netzwerkpartner
                       SET typ_id     = ?,
                           name_firma = ?,
                           vorname    = ?,
                           nachname   = ?,
                           adresse    = ?,
                           telefon    = ?,
                           email      = ?
                     WHERE id = ?
                ");
                $stmt->execute([
                    $typ_id,
                    $nameFirma,
                    $vorname,
                    $nachname,
                    $adresse,
                    $telefon,
                    $email,
                    $id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO netzwerkpartner
                        (typ_id, name_firma, vorname, nachname, adresse, telefon, email)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $typ_id,
                    $nameFirma,
                    $vorname,
                    $nachname,
                    $adresse,
                    $telefon,
                    $email
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            echo json_encode([
                'success' => true,
                'id'      => $id
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Speichern des Netzwerkpartners.'
            ]);
        }
        exit;


    case 'network_partner_delete':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $id    = isset($input['id']) ? (int)$input['id'] : 0;

            if ($id <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige ID.'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM netzwerkpartner WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode([
                'success' => true
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Löschen des Netzwerkpartners.'
            ]);
        }
        exit;
       case 'customer_network_list':
        require_backoffice();
        try {
            $customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
            if ($customerId <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige Kunden-ID.'
                ]);
                exit;
            }

            echo json_encode([
                'success'  => true,
                'partners' => fetch_customer_network_partners($pdo, $customerId)
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Laden der Netzwerkpartner für diesen Kunden.'
            ]);
        }
        exit;
        case 'customer_network_add':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $customerId       = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
            $netzwerkpartnerId = isset($input['netzwerkpartner_id']) ? (int)$input['netzwerkpartner_id'] : 0;

            if ($customerId <= 0 || $netzwerkpartnerId <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige IDs für Kunde oder Netzwerkpartner.'
                ]);
                exit;
            }

            // Doppelte Zuordnung vermeiden
            $check = $pdo->prepare("
                SELECT id
                  FROM kunden_netzwerkpartner
                 WHERE customer_id = ? AND netzwerkpartner_id = ?
            ");
            $check->execute([$customerId, $netzwerkpartnerId]);
            $existing = $check->fetchColumn();

            if ($existing) {
                // still success, nur kein neues Insert
                echo json_encode([
                    'success' => true,
                    'id'      => (int)$existing
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO kunden_netzwerkpartner (customer_id, netzwerkpartner_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$customerId, $netzwerkpartnerId]);
            $id = (int)$pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'id'      => $id
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Zuordnen des Netzwerkpartners.'
            ]);
        }
        exit;
        case 'customer_network_remove':
        require_backoffice();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $id         = isset($input['id']) ? (int)$input['id'] : 0;
            $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;

            if ($id <= 0 || $customerId <= 0) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Ungültige Zuordnungs- oder Kunden-ID.'
                ]);
                exit;
            }

            $stmt = $pdo->prepare("
                DELETE FROM kunden_netzwerkpartner
                 WHERE id = ? AND customer_id = ?
            ");
            $stmt->execute([$id, $customerId]);

            if ($stmt->rowCount() < 1) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Zuordnung nicht gefunden.'
                ]);
                exit;
            }

            echo json_encode([
                'success' => true
            ]);
        } catch (Throwable $e) {
            echo json_encode([
                'success' => false,
                'error'   => 'Fehler beim Entfernen der Netzwerkpartner-Zuordnung.'
            ]);
        }
        exit;

}

// Fallback
http_response_code(404);
echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
