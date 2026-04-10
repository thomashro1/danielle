<?php
require_once __DIR__ . '/app_config.php';
// customer_status.php - API nur für das Kunden-Frontend

session_start();
date_default_timezone_set((string) app_env('APP_TIMEZONE', 'Europe/Berlin'));

header("Content-Type: application/json; charset=utf-8");

try {
    $pdo = app_connect_pdo();
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "DB-Verbindung fehlgeschlagen"]);
    exit;
}

$action = $_GET['action'] ?? '';

function require_customer_api() {
    if (empty($_SESSION['customer_id'])) {
        echo json_encode(["success" => false, "error" => "Nicht eingeloggt"]);
        exit;
    }
}

// ---------------------------------------------------------------------
// Annahmen zur DB-Struktur (passen zu deiner careconnect.php):
//  - Tabelle status: id, bezeichnung, kundenkennung_flag
//  - Tabelle customer_status: id, customer_id, status_id, notiz,
//        backoffice_user_id, created_at, updated_at
//  - Tabelle backoffice_users: id, username, ...
// ---------------------------------------------------------------------

// =====================================================================
// STATUS-TYPEN für Kunden (nur kundenkennung_flag = 1)
// =====================================================================
if ($action === 'types') {
    require_customer_api();

    try {
        $stmt = $pdo->query("
            SELECT id, bezeichnung
            FROM status
            WHERE kundenkennung_flag = 1
            ORDER BY bezeichnung
        ");
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true, "types" => $types]);
    exit;
}

// =====================================================================
// STATUS-HISTORIE für eingeloggten Kunden (nur kundenkennung_flag = 1)
// =====================================================================
if ($action === 'list') {
    require_customer_api();
    $customerId = (int)$_SESSION['customer_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT cs.id,
                   cs.notiz,
                   cs.created_at,
                   cs.updated_at,
                   s.bezeichnung,
                   COALESCE(b.username, 'Kunde') AS user
            FROM customer_status cs
            JOIN status s ON cs.status_id = s.id
            LEFT JOIN backoffice_users b ON cs.backoffice_user_id = b.id
            WHERE cs.customer_id = :cid
              AND s.kundenkennung_flag = 1
            ORDER BY cs.created_at DESC, cs.id DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true, "items" => $items]);
    exit;
}

// =====================================================================
// NEUEN STATUS setzen (nur Status mit kundenkennung_flag = 1 erlaubt)
// =====================================================================
if ($action === 'add') {
    require_customer_api();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $statusId   = (int)($input['status_id'] ?? 0);
    $note       = trim($input['note'] ?? '');
    $customerId = (int)$_SESSION['customer_id'];

    if ($statusId <= 0) {
        echo json_encode(["success" => false, "error" => "Bitte einen Status auswählen"]);
        exit;
    }

    try {
        // Status muss existieren und kundenkennung_flag = 1 haben
        $check = $pdo->prepare("
            SELECT bezeichnung
            FROM status
            WHERE id = :id
              AND kundenkennung_flag = 1
        ");
        $check->execute([':id' => $statusId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(["success" => false, "error" => "Ungültiger Status"]);
            exit;
        }
        $status_bezeichnung = $row['bezeichnung'];

        // backoffice_user_id = NULL, damit klar ist, dass der Kunde gesetzt hat
        $stmt = $pdo->prepare("
            INSERT INTO customer_status
                (customer_id, status_id, status_bezeichnung, notiz, backoffice_user_id, created_at, updated_at)
            VALUES
                (:cid, :sid, :bez, :note, NULL, NOW(), NOW())
        ");
        $stmt->execute([
            ':cid'  => $customerId,
            ':sid'  => $statusId,
            ':bez'  => $status_bezeichnung,
            ':note' => $note
        ]);
        $id = $pdo->lastInsertId();
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true, "id" => $id]);
    exit;
}


// =====================================================================
// Fallback
// =====================================================================
echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
