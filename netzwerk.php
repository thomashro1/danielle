<?php
require_once __DIR__ . '/app_config.php';
// netzwerk.php – Backoffice-Verwaltung für Netzwerkpartner und Typen

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set((string) app_env('APP_TIMEZONE', 'Europe/Berlin'));

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = app_connect_pdo();
}

// HINWEIS: Ich gehe von $pdo (PDO) als DB-Verbindung aus.
// Wenn du mysqli benutzt, musst du nur die DB-Statements anpassen.

function post($key, $default = null) {
    return $_POST[$key] ?? $default;
}

$action = $_GET['action'] ?? 'list';
$sub    = $_GET['sub'] ?? ''; // 'typen' oder '' (Partner)

// -------------------------------------------------------
// 1) NETZWERKPARTNER-TYPEN VERWALTUNG
// -------------------------------------------------------
if ($sub === 'typen') {

    // Speichern (Insert/Update)
    if (!empty($_POST['save_typ'])) {
        $id  = (int)post('id', 0);
        $bez = trim(post('bezeichnung', ''));

        if ($bez !== '') {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    "UPDATE netzwerkpartner_typen SET bezeichnung = ? WHERE id = ?"
                );
                $stmt->execute([$bez, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO netzwerkpartner_typen (bezeichnung) VALUES (?)"
                );
                $stmt->execute([$bez]);
            }
        }

        header('Location: careconnect.php?page=netzwerk&sub=typen');
        exit;
    }

    // Löschen
    if (isset($_GET['delete'])) {
        $id = (int)$_GET['delete'];
        $stmt = $pdo->prepare("DELETE FROM netzwerkpartner_typen WHERE id = ?");
        $stmt->execute([$id]);

        header('Location: careconnect.php?page=netzwerk&sub=typen');
        exit;
    }

    // Edit-Datensatz laden (optional)
    $editTyp = null;
    if (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $stmt = $pdo->prepare("SELECT * FROM netzwerkpartner_typen WHERE id = ?");
        $stmt->execute([$id]);
        $editTyp = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Liste Typen
    $typen = $pdo->query(
        "SELECT * FROM netzwerkpartner_typen ORDER BY bezeichnung ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h1>Netzwerk &raquo; Netzwerkpartnertypen</h1>

    <div class="card" style="max-width:600px;margin-bottom:2rem;">
        <div class="card-header">
            <strong><?= $editTyp ? 'Typ bearbeiten' : 'Neuen Typ anlegen' ?></strong>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="id"
                       value="<?= htmlspecialchars($editTyp['id'] ?? 0) ?>">

                <div class="form-group" style="margin-bottom:1rem;">
                    <label>Bezeichnung</label>
                    <input type="text" name="bezeichnung" class="form-control"
                           value="<?= htmlspecialchars($editTyp['bezeichnung'] ?? '') ?>"
                           required>
                </div>

                <button type="submit" name="save_typ" class="btn btn-primary">
                    Speichern
                </button>
                <?php if ($editTyp): ?>
                    <a href="careconnect.php?page=netzwerk&sub=typen"
                       class="btn btn-secondary">Abbrechen</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card" style="max-width:600px;">
        <div class="card-header">
            <strong>Vorhandene Typen</strong>
        </div>
        <div class="card-body">
            <?php if (!$typen): ?>
                <p>Keine Typen vorhanden.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Bezeichnung</th>
                        <th style="width:150px;">Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($typen as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>
                            <td><?= htmlspecialchars($t['bezeichnung']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-light"
                                   href="careconnect.php?page=netzwerk&sub=typen&edit=<?= (int)$t['id'] ?>">
                                    Bearbeiten
                                </a>
                                <a class="btn btn-sm btn-danger"
                                   onclick="return confirm('Typ wirklich löschen?');"
                                   href="careconnect.php?page=netzwerk&sub=typen&delete=<?= (int)$t['id'] ?>">
                                    Löschen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return;
}

// -------------------------------------------------------
// 2) NETZWERKPARTNER VERWALTUNG
// -------------------------------------------------------

// Typen für Select
$typenStmt = $pdo->query(
    "SELECT * FROM netzwerkpartner_typen ORDER BY bezeichnung ASC"
);
$typen = $typenStmt->fetchAll(PDO::FETCH_ASSOC);

// Speichern Partner
if (!empty($_POST['save_partner'])) {
    $id        = (int)post('id', 0);
    $typ_id    = (int)post('typ_id', 0);
    $nameFirma = trim(post('name_firma', ''));
    $vorname   = trim(post('vorname', ''));
    $nachname  = trim(post('nachname', ''));
    $adresse   = trim(post('adresse', ''));
    $telefon   = trim(post('telefon', ''));
    $email     = trim(post('email', ''));

    if ($typ_id > 0 && $nameFirma !== '') {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE netzwerkpartner
                   SET typ_id = ?, name_firma = ?, vorname = ?, nachname = ?,
                       adresse = ?, telefon = ?, email = ?
                 WHERE id = ?
            ");
            $stmt->execute([
                $typ_id, $nameFirma, $vorname, $nachname,
                $adresse, $telefon, $email, $id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO netzwerkpartner
                    (typ_id, name_firma, vorname, nachname, adresse, telefon, email)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $typ_id, $nameFirma, $vorname, $nachname,
                $adresse, $telefon, $email
            ]);
        }
    }

    header('Location: careconnect.php?page=netzwerk');
    exit;
}

// Löschen Partner
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM netzwerkpartner WHERE id = ?");
    $stmt->execute([$id]);

    // Zuordnungen werden per FK automatisch gelöscht
    header('Location: careconnect.php?page=netzwerk');
    exit;
}

// Edit-Partner laden
$editPartner = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM netzwerkpartner WHERE id = ?");
    $stmt->execute([$id]);
    $editPartner = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Liste aller Partner
$stmt = $pdo->query("
    SELECT p.*, t.bezeichnung AS typ_bezeichnung
      FROM netzwerkpartner p
 LEFT JOIN netzwerkpartner_typen t ON t.id = p.typ_id
  ORDER BY t.bezeichnung ASC, p.name_firma ASC
");
$partnerListe = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<h1>Netzwerk</h1>

<div style="display:flex;gap:2rem;flex-wrap:wrap;align-items:flex-start;">

    <!-- Formular -->
    <div class="card" style="flex:1;min-width:320px;max-width:480px;">
        <div class="card-header">
            <strong><?= $editPartner ? 'Netzwerkpartner bearbeiten' : 'Neuen Netzwerkpartner anlegen' ?></strong>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="id"
                       value="<?= htmlspecialchars($editPartner['id'] ?? 0) ?>">

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label>Typ</label>
                    <select name="typ_id" class="form-control" required>
                        <option value="">Bitte wählen…</option>
                        <?php foreach ($typen as $t): ?>
                            <option value="<?= (int)$t['id'] ?>"
                                <?= isset($editPartner['typ_id']) && (int)$editPartner['typ_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['bezeichnung']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>
                        Typen verwalten:
                        <a href="careconnect.php?page=netzwerk&sub=typen">
                            zu den Netzwerkpartnertypen
                        </a>
                    </small>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label>Name / Firma</label>
                    <input type="text" name="name_firma" class="form-control"
                           value="<?= htmlspecialchars($editPartner['name_firma'] ?? '') ?>"
                           required>
                </div>

                <div style="display:flex;gap:0.5rem;margin-bottom:0.75rem;">
                    <div class="form-group" style="flex:1;">
                        <label>Vorname</label>
                        <input type="text" name="vorname" class="form-control"
                               value="<?= htmlspecialchars($editPartner['vorname'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Nachname</label>
                        <input type="text" name="nachname" class="form-control"
                               value="<?= htmlspecialchars($editPartner['nachname'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label>Adresse</label>
                    <textarea name="adresse" class="form-control" rows="3"><?= htmlspecialchars($editPartner['adresse'] ?? '') ?></textarea>
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label>Telefon</label>
                    <input type="text" name="telefon" class="form-control"
                           value="<?= htmlspecialchars($editPartner['telefon'] ?? '') ?>">
                </div>

                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label>E-Mail</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($editPartner['email'] ?? '') ?>">
                </div>

                <button type="submit" name="save_partner" class="btn btn-primary">
                    Speichern
                </button>
                <?php if ($editPartner): ?>
                    <a href="careconnect.php?page=netzwerk"
                       class="btn btn-secondary">Abbrechen</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Liste -->
    <div class="card" style="flex:2;min-width:320px;">
        <div class="card-header">
            <strong>Netzwerkpartner</strong>
        </div>
        <div class="card-body">
            <?php if (!$partnerListe): ?>
                <p>Keine Netzwerkpartner erfasst.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>Typ</th>
                        <th>Name / Firma</th>
                        <th>Kontakt</th>
                        <th>Adresse</th>
                        <th style="width:180px;">Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($partnerListe as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['typ_bezeichnung'] ?? '') ?></td>
                            <td>
                                <?= htmlspecialchars($p['name_firma']) ?><br>
                                <small>
                                    <?= htmlspecialchars(trim(($p['vorname'] ?? '') . ' ' . ($p['nachname'] ?? ''))) ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($p['telefon'])): ?>
                                    <div>Tel: <?= htmlspecialchars($p['telefon']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($p['email'])): ?>
                                    <div>
                                        E-Mail:
                                        <a href="mailto:<?= htmlspecialchars($p['email']) ?>">
                                            <?= htmlspecialchars($p['email']) ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="max-width:250px;white-space:pre-wrap;">
                                <?= nl2br(htmlspecialchars($p['adresse'] ?? '')) ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-light"
                                   href="careconnect.php?page=netzwerk&edit=<?= (int)$p['id'] ?>">
                                    Bearbeiten
                                </a>
                                <a class="btn btn-sm btn-danger"
                                   onclick="return confirm('Netzwerkpartner wirklich löschen?');"
                                   href="careconnect.php?page=netzwerk&delete=<?= (int)$p['id'] ?>">
                                    Löschen
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

</div>
