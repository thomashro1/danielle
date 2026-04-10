<?php
require_once __DIR__ . '/app_config.php';
// backend_notiz.php – Kundennotizen (Betreff + Text) pro Kunde

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
    echo json_encode([
        "success" => false,
        "error"   => "DB-Verbindung fehlgeschlagen"
    ]);
    exit;
}

// ===== Auth-Helfer =====
function require_backoffice() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'backoffice') {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "Nicht autorisiert (Backoffice)"]);
        exit;
    }
}

function current_backoffice_user() {
    $id   = $_SESSION['backoffice_user_id'] ?? ($_SESSION['user_id'] ?? null);
    $name = $_SESSION['backoffice_username'] ?? ($_SESSION['username'] ?? '');
    return [$id, $name];
}

// ===== Routing =====
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

header("Content-Type: application/json; charset=utf-8");

switch ($action) {

    // ----------------------------------------------------------
    // Liste aller Notizen zu einem Kunden
    // GET backend_notiz.php?action=list&customer_id=123
    // ----------------------------------------------------------
    case 'list':
        require_backoffice();

        $customerId = intval($_GET['customer_id'] ?? 0);
        if ($customerId <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige Kunden-ID"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    subject,
                    content,
                    backoffice_user_id,
                    backoffice_username,
                    created_at,
                    updated_at
                FROM customer_notes
                WHERE customer_id = ?
                ORDER BY created_at DESC, id DESC
            ");
            $stmt->execute([$customerId]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "notes"   => $notes
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                "success" => false,
                "error"   => "DB-Fehler beim Laden der Notizen"
            ]);
        }
        exit;

    // ----------------------------------------------------------
    // Notiz speichern (neu oder Update)
    // POST backend_notiz.php?action=save
    // Body JSON: { id, customer_id, subject, content }
    // ----------------------------------------------------------
    case 'save':
        require_backoffice();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];

        $id         = intval($input['id'] ?? 0);
        $customerId = intval($input['customer_id'] ?? 0);
        $subject    = trim($input['subject'] ?? '');
        $content    = trim($input['content'] ?? '');

        if ($customerId <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige Kunden-ID"]);
            exit;
        }
        if ($subject === '') {
            echo json_encode(["success" => false, "error" => "Betreff darf nicht leer sein"]);
            exit;
        }

        list($userId, $username) = current_backoffice_user();

        try {
            if ($id > 0) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE customer_notes
                    SET subject = :subject,
                        content = :content,
                        backoffice_user_id = :uid,
                        backoffice_username = :uname,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':subject' => $subject,
                    ':content' => $content,
                    ':uid'     => $userId,
                    ':uname'   => $username,
                    ':id'      => $id
                ]);
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO customer_notes
                        (customer_id, subject, content,
                         backoffice_user_id, backoffice_username,
                         created_at, updated_at)
                    VALUES
                        (:cid, :subject, :content,
                         :uid, :uname,
                         NOW(), NOW())
                ");
                $stmt->execute([
                    ':cid'     => $customerId,
                    ':subject' => $subject,
                    ':content' => $content,
                    ':uid'     => $userId,
                    ':uname'   => $username
                ]);
                $id = intval($pdo->lastInsertId());
            }

            echo json_encode([
                "success" => true,
                "id"      => $id
            ]);
        } catch (PDOException $e) {
            echo json_encode([
                "success" => false,
                "error"   => "DB-Fehler beim Speichern der Notiz"
            ]);
        }
        exit;

    // ----------------------------------------------------------
    // Notiz löschen
    // POST backend_notiz.php?action=delete
    // Body JSON: { id }
    // ----------------------------------------------------------
    case 'delete':
        require_backoffice();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];

        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(["success" => false, "error" => "Ungültige Notiz-ID"]);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM customer_notes WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(["success" => true]);
        } catch (PDOException $e) {
            echo json_encode([
                "success" => false,
                "error"   => "DB-Fehler beim Löschen der Notiz"
            ]);
        }
        exit;
}

// Fallback
http_response_code(404);
echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
