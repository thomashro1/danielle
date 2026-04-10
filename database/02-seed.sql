INSERT INTO backoffice_users (
    id, username, password, vorname, nachname, adresse, telefon, email, berufs_id
) VALUES (
    1, 'demo.admin', 'demo1234', 'Danielle', 'Admin', 'Musterstrasse 1, 10115 Berlin', '030 123456', 'backoffice@example.com', 101
)
ON DUPLICATE KEY UPDATE
    username = VALUES(username),
    password = VALUES(password),
    vorname = VALUES(vorname),
    nachname = VALUES(nachname),
    adresse = VALUES(adresse),
    telefon = VALUES(telefon),
    email = VALUES(email),
    berufs_id = VALUES(berufs_id);

INSERT INTO customers (
    id, anrede, vorname, nachname, adresse, telefon, email, password,
    kontakt_anrede, kontakt_vorname, kontakt_nachname, kontakt_adresse, kontakt_telefon
) VALUES (
    1, 'Frau', 'Mia', 'Beispiel', 'Beispielweg 12, 10117 Berlin', '0170 1234567', 'kunde@example.com', 'demo1234',
    'Herr', 'Tom', 'Beispiel', 'Kontaktweg 7, 10117 Berlin', '0170 7654321'
)
ON DUPLICATE KEY UPDATE
    anrede = VALUES(anrede),
    vorname = VALUES(vorname),
    nachname = VALUES(nachname),
    adresse = VALUES(adresse),
    telefon = VALUES(telefon),
    email = VALUES(email),
    password = VALUES(password),
    kontakt_anrede = VALUES(kontakt_anrede),
    kontakt_vorname = VALUES(kontakt_vorname),
    kontakt_nachname = VALUES(kontakt_nachname),
    kontakt_adresse = VALUES(kontakt_adresse),
    kontakt_telefon = VALUES(kontakt_telefon);

INSERT INTO status (id, bezeichnung, kundenkennung_flag) VALUES
    (1, 'Neu aufgenommen', 1),
    (2, 'Unterlagen eingereicht', 1),
    (3, 'Interne Pruefung', 0),
    (4, 'Rueckfrage offen', 1),
    (5, 'Abgeschlossen', 1)
ON DUPLICATE KEY UPDATE
    bezeichnung = VALUES(bezeichnung),
    kundenkennung_flag = VALUES(kundenkennung_flag);

INSERT INTO customer_status (
    id, customer_id, status_id, status_bezeichnung, notiz, backoffice_user_id, created_at, updated_at
) VALUES
    (1, 1, 1, 'Neu aufgenommen', 'Erstanlage im lokalen Testsystem.', 1, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),
    (2, 1, 2, 'Unterlagen eingereicht', 'Die Musterunterlagen wurden erfolgreich hinterlegt.', 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY)
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    status_id = VALUES(status_id),
    status_bezeichnung = VALUES(status_bezeichnung),
    notiz = VALUES(notiz),
    backoffice_user_id = VALUES(backoffice_user_id),
    updated_at = VALUES(updated_at);

INSERT INTO imap_settings (id, host, port, encryption, username, password, mailbox) VALUES
    (1, '', 993, 'ssl', '', '', 'INBOX')
ON DUPLICATE KEY UPDATE
    host = VALUES(host),
    port = VALUES(port),
    encryption = VALUES(encryption),
    username = VALUES(username),
    password = VALUES(password),
    mailbox = VALUES(mailbox);

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('backoffice_alert_followup_days', '3')
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO followup_types (id, bezeichnung, color_code) VALUES
    (1, 'Telefonat', '#ffd8a8'),
    (2, 'E-Mail', '#b2f2bb'),
    (3, 'Termin', '#a5d8ff')
ON DUPLICATE KEY UPDATE
    bezeichnung = VALUES(bezeichnung),
    color_code = VALUES(color_code);

INSERT INTO followups (
    id, customer_id, type_id, due_date, note, `status`
) VALUES
    (1, 1, 1, NOW() + INTERVAL 1 DAY, 'Rueckruf zur Abstimmung der naechsten Schritte.', 'neu'),
    (2, 1, 2, NOW() - INTERVAL 1 DAY, 'Unterlagenbestaetigung wurde verschickt.', 'erledigt')
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    type_id = VALUES(type_id),
    due_date = VALUES(due_date),
    note = VALUES(note),
    `status` = VALUES(`status`);

INSERT INTO netzwerkpartner_typen (id, bezeichnung) VALUES
    (1, 'Pflegedienst'),
    (2, 'Arztpraxis')
ON DUPLICATE KEY UPDATE
    bezeichnung = VALUES(bezeichnung);

INSERT INTO netzwerkpartner (
    id, typ_id, name_firma, vorname, nachname, adresse, telefon, email
) VALUES
    (1, 1, 'Care Partner Berlin', 'Julia', 'Schmidt', 'Netzwerkallee 4, 10119 Berlin', '030 222333', 'j.schmidt@carepartner.test'),
    (2, 2, 'Praxis Dr. Meyer', 'Martin', 'Meyer', 'Praxisweg 9, 10119 Berlin', '030 555777', 'praxis@meyer.test')
ON DUPLICATE KEY UPDATE
    typ_id = VALUES(typ_id),
    name_firma = VALUES(name_firma),
    vorname = VALUES(vorname),
    nachname = VALUES(nachname),
    adresse = VALUES(adresse),
    telefon = VALUES(telefon),
    email = VALUES(email);

INSERT INTO kunden_netzwerkpartner (
    id, customer_id, netzwerkpartner_id
) VALUES
    (1, 1, 1),
    (2, 1, 2)
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    netzwerkpartner_id = VALUES(netzwerkpartner_id);

INSERT INTO customer_notes (
    id, customer_id, subject, content, backoffice_user_id, backoffice_username, created_at, updated_at
) VALUES
    (1, 1, 'Demo-Notiz', 'Diese Notiz wurde beim lokalen Bootstrap angelegt.', 1, 'demo.admin', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY)
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    subject = VALUES(subject),
    content = VALUES(content),
    backoffice_user_id = VALUES(backoffice_user_id),
    backoffice_username = VALUES(backoffice_username),
    updated_at = VALUES(updated_at);

INSERT INTO documents (
    id, customer_id, label, filename, mime_type, content_base64, created_at, created_by
) VALUES
    (1, 1, 'Willkommensdokument', 'willkommen.txt', 'text/plain', TO_BASE64('Willkommen im lokalen DANIELLE-Testsystem.'), NOW() - INTERVAL 1 DAY, 'demo.admin')
ON DUPLICATE KEY UPDATE
    customer_id = VALUES(customer_id),
    label = VALUES(label),
    filename = VALUES(filename),
    mime_type = VALUES(mime_type),
    content_base64 = VALUES(content_base64),
    created_at = VALUES(created_at),
    created_by = VALUES(created_by);
