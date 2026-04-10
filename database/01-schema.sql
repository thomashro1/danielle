CREATE TABLE IF NOT EXISTS backoffice_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    vorname VARCHAR(100) DEFAULT NULL,
    nachname VARCHAR(100) DEFAULT NULL,
    adresse VARCHAR(255) DEFAULT NULL,
    telefon VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    berufs_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_backoffice_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anrede VARCHAR(50) DEFAULT NULL,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    adresse VARCHAR(255) DEFAULT NULL,
    telefon VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    kontakt_anrede VARCHAR(50) DEFAULT NULL,
    kontakt_vorname VARCHAR(100) DEFAULT NULL,
    kontakt_nachname VARCHAR(100) DEFAULT NULL,
    kontakt_adresse VARCHAR(255) DEFAULT NULL,
    kontakt_telefon VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(150) NOT NULL,
    kundenkennung_flag TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    status_id INT NOT NULL,
    status_bezeichnung VARCHAR(150) DEFAULT NULL,
    notiz TEXT DEFAULT NULL,
    backoffice_user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_customer_status_customer (customer_id),
    KEY idx_customer_status_status (status_id),
    KEY idx_customer_status_backoffice_user (backoffice_user_id),
    CONSTRAINT fk_customer_status_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_status_status FOREIGN KEY (status_id) REFERENCES status (id),
    CONSTRAINT fk_customer_status_backoffice_user FOREIGN KEY (backoffice_user_id) REFERENCES backoffice_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imap_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    host VARCHAR(255) DEFAULT NULL,
    port INT NOT NULL DEFAULT 993,
    encryption VARCHAR(20) NOT NULL DEFAULT 'ssl',
    username VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) DEFAULT NULL,
    mailbox VARCHAR(255) NOT NULL DEFAULT 'INBOX'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(150) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS followup_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(150) NOT NULL,
    color_code VARCHAR(20) NOT NULL DEFAULT '#dde4ff'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type_id INT NOT NULL,
    due_date DATETIME NOT NULL,
    note TEXT DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'neu',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_followups_customer (customer_id),
    KEY idx_followups_type (type_id),
    CONSTRAINT fk_followups_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_followups_type FOREIGN KEY (type_id) REFERENCES followup_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS netzwerkpartner_typen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bezeichnung VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_netzwerkpartner_typen_bezeichnung (bezeichnung)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS netzwerkpartner (
    id INT AUTO_INCREMENT PRIMARY KEY,
    typ_id INT NOT NULL,
    name_firma VARCHAR(255) NOT NULL,
    vorname VARCHAR(100) DEFAULT NULL,
    nachname VARCHAR(100) DEFAULT NULL,
    adresse VARCHAR(255) DEFAULT NULL,
    telefon VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_netzwerkpartner_typ (typ_id),
    CONSTRAINT fk_netzwerkpartner_typ FOREIGN KEY (typ_id) REFERENCES netzwerkpartner_typen (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kunden_netzwerkpartner (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    netzwerkpartner_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kunden_netzwerkpartner (customer_id, netzwerkpartner_id),
    KEY idx_kunden_netzwerkpartner_partner (netzwerkpartner_id),
    CONSTRAINT fk_kunden_netzwerkpartner_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_kunden_netzwerkpartner_partner FOREIGN KEY (netzwerkpartner_id) REFERENCES netzwerkpartner (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    content TEXT DEFAULT NULL,
    backoffice_user_id INT DEFAULT NULL,
    backoffice_username VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_customer_notes_customer (customer_id),
    KEY idx_customer_notes_backoffice_user (backoffice_user_id),
    CONSTRAINT fk_customer_notes_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_notes_backoffice_user FOREIGN KEY (backoffice_user_id) REFERENCES backoffice_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
    content_base64 LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) NOT NULL DEFAULT 'backoffice',
    KEY idx_documents_customer (customer_id),
    CONSTRAINT fk_documents_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
