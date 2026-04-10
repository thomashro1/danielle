<?php
require_once __DIR__ . '/app_config.php';
// mail.php - IMAP-Reader mit MIME/Attachment-Unterstützung

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
    echo json_encode(
        ["success" => false, "error" => "DB-Verbindung fehlgeschlagen"],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// === Auth-Helfer ===
function require_backoffice() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'backoffice') {
        http_response_code(401);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            ["success" => false, "error" => "Nicht autorisiert (Backoffice)"],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}

// IMAP-Verbindung herstellen (Settings aus DB)
function open_imap($pdo, &$error = null) {
    if (!function_exists('imap_open')) {
        $error = "Die PHP-IMAP-Erweiterung ist lokal nicht aktiv.";
        return false;
    }

    $stmt = $pdo->query("SELECT host, port, encryption, username, password, mailbox FROM imap_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $error = "Keine IMAP-Einstellungen vorhanden.";
        return false;
    }

    $host       = $settings['host'];
    $port       = (int)$settings['port'];
    $encryption = strtolower($settings['encryption']);
    $username   = $settings['username'];
    $password   = $settings['password'];
    $mailbox    = $settings['mailbox'] ?: 'INBOX';

    if (!$host || !$username || !$password) {
        $error = "IMAP-Einstellungen unvollständig.";
        return false;
    }

    $flags = "/imap";
    if ($encryption === 'ssl') {
        $flags .= "/ssl";
    } elseif ($encryption === 'tls') {
        $flags .= "/tls";
    } else {
        $flags .= "/novalidate-cert";
    }

    $mboxString = "{" . $host . ":" . $port . $flags . "}" . $mailbox;

    $inbox = @imap_open($mboxString, $username, $password);
    if (!$inbox) {
        $error = imap_last_error() ?: "IMAP-Verbindung fehlgeschlagen.";
        return false;
    }

    return $inbox;
}

// MIME-Parts rekursiv einsammeln
function collect_parts($imap, $msgno, $part, $partno, &$bodyHtml, &$bodyText, &$attachments) {

    // ---------- TEXT-/HTML-Body ----------
    if ($part->type == 0) { // TEXT

        // Charset aus den Part-Parametern lesen (Standard UTF-8)
        $charset = 'UTF-8';
        if (isset($part->parameters)) {
            foreach ($part->parameters as $obj) {
                if (strtolower($obj->attribute) === 'charset' && !empty($obj->value)) {
                    $charset = $obj->value;
                    break;
                }
            }
        }

        $data = imap_fetchbody($imap, $msgno, $partno);

        // Encoding (BASE64 / QUOTED-PRINTABLE)
        if ($part->encoding == 3) { // BASE64
            $data = base64_decode($data);
        } elseif ($part->encoding == 4) { // QUOTED-PRINTABLE
            $data = quoted_printable_decode($data);
        }

        // In UTF-8 konvertieren, falls nötig
        $charsetUpper = strtoupper($charset);
        if ($charsetUpper && $charsetUpper !== 'UTF-8') {
            if (function_exists('mb_convert_encoding')) {
                $data = @mb_convert_encoding($data, 'UTF-8', $charsetUpper);
            } elseif (function_exists('iconv')) {
                $converted = @iconv($charsetUpper, 'UTF-8//TRANSLIT', $data);
                if ($converted !== false) {
                    $data = $converted;
                }
            }
        }

        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';

        if ($subtype === 'html') {
            if ($bodyHtml === '') {
                $bodyHtml = $data;
            }
        } else { // plain etc.
            if ($bodyText === '') {
                $bodyText = $data;
            }
        }
    }

    // ---------- Anhänge / Inline ----------
    $filename = null;
    if (isset($part->dparameters)) {
        foreach ($part->dparameters as $obj) {
            if (strtolower($obj->attribute) == "filename") {
                $filename = $obj->value;
                break;
            }
        }
    }
    if (!$filename && isset($part->parameters)) {
        foreach ($part->parameters as $obj) {
            if (strtolower($obj->attribute) == "name") {
                $filename = $obj->value;
                break;
            }
        }
    }

    $disposition = isset($part->disposition) ? strtolower($part->disposition) : '';
    $isAttachment = $filename !== null && in_array($disposition, ['attachment', 'inline']);

    if ($isAttachment) {
        $attachmentData = imap_fetchbody($imap, $msgno, $partno);
        if ($part->encoding == 3) {
            $attachmentData = base64_decode($attachmentData);
        } elseif ($part->encoding == 4) {
            $attachmentData = quoted_printable_decode($attachmentData);
        }

        $typeMap = [
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'other'
        ];
        $primary = isset($typeMap[$part->type]) ? $typeMap[$part->type] : 'application';
        $sub = isset($part->subtype) ? strtolower($part->subtype) : 'octet-stream';
        $mimeType = $primary . '/' . $sub;

        $attachments[] = [
            "filename" => $filename ?: "attachment",
            "data"     => base64_encode($attachmentData),
            "mime"     => $mimeType
        ];
    }

    // ---------- Unterparts rekursiv ----------
    if (isset($part->parts) && count($part->parts)) {
        $i = 1;
        foreach ($part->parts as $subpart) {
            collect_parts($imap, $msgno, $subpart, $partno . "." . $i, $bodyHtml, $bodyText, $attachments);
            $i++;
        }
    }
}

// === Routing ===
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // --------- Liste von Mails ----------
    case 'list':
        require_backoffice();
        header("Content-Type: application/json; charset=utf-8");

        $error = null;
        $inbox = open_imap($pdo, $error);
        if (!$inbox) {
            echo json_encode(
                ["success" => false, "error" => $error],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 50;

        $numMessages = imap_num_msg($inbox);
        if ($numMessages <= 0) {
            imap_close($inbox);
            echo json_encode(
                ["success" => true, "messages" => []],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        $start = max(1, $numMessages - $limit + 1);
        $range = $start . ":" . $numMessages;

        $overview = imap_fetch_overview($inbox, $range, 0);
        $messages = [];

        if ($overview) {
            // neueste zuerst
            foreach (array_reverse($overview) as $ov) {
                $msgno = $ov->msgno;
                $uid = function_exists('imap_uid') ? imap_uid($inbox, $msgno) : null;

                $subject = isset($ov->subject) ? imap_utf8($ov->subject) : "(kein Betreff)";
                $from    = isset($ov->from)    ? imap_utf8($ov->from)    : "";

                $messages[] = [
                    "msgno"   => $msgno,
                    "uid"     => $uid,
                    "subject" => $subject,
                    "from"    => $from,
                    "date"    => $ov->date ?? "",
                    "seen"    => !empty($ov->seen)
                ];
            }
        }

        imap_close($inbox);
        echo json_encode(
            ["success" => true, "messages" => $messages],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;

    // --------- Einzelne Mail lesen (mit MIME/Attachments) ----------
    case 'get':
        require_backoffice();
        header("Content-Type: application/json; charset=utf-8");

        $msgno = intval($_GET['msgno'] ?? 0);
        if ($msgno <= 0) {
            echo json_encode(
                ["success" => false, "error" => "Ungültige Nachrichtennummer"],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        $error = null;
        $inbox = open_imap($pdo, $error);
        if (!$inbox) {
            echo json_encode(
                ["success" => false, "error" => $error],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        $numMessages = imap_num_msg($inbox);
        if ($msgno > $numMessages) {
            imap_close($inbox);
            echo json_encode(
                ["success" => false, "error" => "Nachricht nicht gefunden"],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            exit;
        }

        $overview = imap_fetch_overview($inbox, $msgno, 0);
        $ov = $overview ? $overview[0] : null;

        $structure = imap_fetchstructure($inbox, $msgno);
        $bodyHtml = "";
        $bodyText = "";
        $attachments = [];

        if ($structure) {
            if (isset($structure->parts) && count($structure->parts)) {
                // WICHTIG: Top-Level-Parts sind 1,2,3...
                $i = 1;
                foreach ($structure->parts as $part) {
                    collect_parts($inbox, $msgno, $part, (string)$i, $bodyHtml, $bodyText, $attachments);
                    $i++;
                }
            } else {
                // Einteilige Nachricht
                collect_parts($inbox, $msgno, $structure, "1", $bodyHtml, $bodyText, $attachments);
            }
        } else {
            $bodyText = quoted_printable_decode(imap_body($inbox, $msgno));
        }

        imap_close($inbox);

        // Body wählen: HTML bevorzugt
        if ($bodyHtml !== "") {
            $body = $bodyHtml;
        } elseif ($bodyText !== "") {
            $body = nl2br(htmlspecialchars($bodyText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        } else {
            $body = "";
        }

        $subject = $ov && isset($ov->subject) ? imap_utf8($ov->subject) : "(kein Betreff)";
        $from    = $ov && isset($ov->from)    ? imap_utf8($ov->from)    : "";
        $to      = $ov && isset($ov->to)      ? imap_utf8($ov->to)      : "";
        $date    = $ov && isset($ov->date)    ? $ov->date               : "";

        echo json_encode(
            [
                "success" => true,
                "message" => [
                    "msgno"       => $msgno,
                    "subject"     => $subject,
                    "from"        => $from,
                    "to"          => $to,
                    "date"        => $date,
                    "body"        => $body,
                    "attachments" => $attachments
                ]
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
}

// Fallback
header("Content-Type: application/json; charset=utf-8");
http_response_code(404);
echo json_encode(
    ["success" => false, "error" => "Unbekannte Aktion"],
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
);
