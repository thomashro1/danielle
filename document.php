<?php
require_once __DIR__ . '/app_config.php';
// document.php – gemeinsame Dokumenten-API für Backoffice UND Kunden

session_start();
date_default_timezone_set((string) app_env('APP_TIMEZONE', 'Europe/Berlin'));

$action = $_GET['action'] ?? '';

// Für alle JSON-Actions schon mal Header setzen
if ($action !== 'view') {
    header("Content-Type: application/json; charset=utf-8");
}

// DB verbinden
try {
    $pdo = app_connect_pdo();
} catch (Exception $e) {
    if ($action === 'view') {
        header("Content-Type: application/json; charset=utf-8");
    }
    echo json_encode(["success" => false, "error" => "DB-Verbindung fehlgeschlagen"]);
    exit;
}

// Rollen
function is_backoffice() {
    return ($_SESSION['role'] ?? '') === 'backoffice' && !empty($_SESSION['backoffice_user_id']);
}
function is_customer() {
    return ($_SESSION['role'] ?? '') === 'customer' && !empty($_SESSION['customer_id']);
}
function require_any_auth_json() {
    if (!is_backoffice() && !is_customer()) {
        echo json_encode(["success" => false, "error" => "Nicht autorisiert"]);
        exit;
    }
}

// ======================================================================
// UPLOAD
// ======================================================================
if ($action === 'upload') {
    require_any_auth_json();

    // customer_id bestimmen
    if (is_backoffice()) {
        $customerId = intval($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            echo json_encode(["success" => false, "error" => "customer_id fehlt"]);
            exit;
        }
    } else {
        $customerId = intval($_SESSION['customer_id']);
    }

    $label = trim($_POST['label'] ?? '');
    $note  = trim($_POST['note'] ?? '');
    if ($label === '') $label = null;
    if ($note === '') $note = null;

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "error" => "Datei-Upload fehlgeschlagen"]);
        exit;
    }

    $tmpName  = $_FILES['file']['tmp_name'];
    $filename = $_FILES['file']['name'];
    $mime     = $_FILES['file']['type'] ?: 'application/octet-stream';

    $bin = @file_get_contents($tmpName);
    if ($bin === false) {
        echo json_encode(["success" => false, "error" => "Datei konnte nicht gelesen werden"]);
        exit;
    }

    $b64       = base64_encode($bin);
    $createdAt = date('Y-m-d H:i:s');
    $createdBy = is_backoffice()
        ? ($_SESSION['backoffice_username'] ?? 'backoffice')
        : 'customer';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO documents
                (customer_id, label, note, filename, mime_type, content_base64, created_at, created_by)
            VALUES
                (:cid, :label, :note, :fn, :mime, :b64, :createdAt, :createdBy)
        ");
        $stmt->execute([
            ':cid'       => $customerId,
            ':label'     => $label,
            ':note'      => $note,
            ':fn'        => $filename,
            ':mime'      => $mime,
            ':b64'       => $b64,
            ':createdAt' => $createdAt,
            ':createdBy' => $createdBy
        ]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler beim Upload: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
    exit;
}

// ======================================================================
// LIST
// ======================================================================
if ($action === 'list') {
    require_any_auth_json();

    if (is_backoffice()) {
        $customerId = intval($_GET['customer_id'] ?? 0);
        if ($customerId <= 0) {
            echo json_encode(["success" => false, "error" => "customer_id fehlt"]);
            exit;
        }
    } else {
        $customerId = intval($_SESSION['customer_id']);
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, label, note, filename, mime_type, created_at, created_by
            FROM documents
            WHERE customer_id = :cid
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute([':cid' => $customerId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler beim Lesen: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true, "documents" => $docs]);
    exit;
}

// ======================================================================
// VIEW – Binary-Ausgabe eines Dokuments
// ======================================================================
if ($action === 'view') {
    require_any_auth_json();

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "Ungültige ID"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, customer_id, filename, mime_type, content_base64
            FROM documents
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "SQL-Fehler beim Lesen: ".$e->getMessage()]);
        exit;
    }

    if (!$doc) {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["success" => false, "error" => "Dokument nicht gefunden"]);
        exit;
    }

    // Kunde darf nur eigene Dokumente sehen
    if (is_customer()) {
        $own = intval($_SESSION['customer_id'] ?? 0);
        if ($doc['customer_id'] != $own) {
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode(["success" => false, "error" => "Nicht autorisiert"]);
            exit;
        }
    }

    $bin  = base64_decode($doc['content_base64']);
    $mime = $doc['mime_type'] ?: 'application/octet-stream';

    header("Content-Type: ".$mime);
    header('Content-Disposition: inline; filename="'.basename($doc['filename']).'"');
    header("Content-Length: ".strlen($bin));
    echo $bin;
    exit;
}

// ======================================================================
// DELETE – nur Backoffice
// ======================================================================
if ($action === 'delete') {
    if (!is_backoffice()) {
        echo json_encode(["success" => false, "error" => "Nicht autorisiert"]);
        exit;
    }

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    $id   = intval($data['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(["success" => false, "error" => "Ungültige ID"]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = :id");
        $stmt->execute([':id' => $id]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "SQL-Fehler beim Löschen: ".$e->getMessage()]);
        exit;
    }

    echo json_encode(["success" => true]);
    exit;
}

// ======================================================================
// Fallback
// ======================================================================
echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
