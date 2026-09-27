-- Kakebe Tech Camp 2026 — database schema (v4)
-- The app creates/upgrades these tables automatically on first visit.
-- Only import this manually if your host does not allow that (phpMyAdmin → Import).

CREATE TABLE IF NOT EXISTS settings (
    skey   VARCHAR(64) NOT NULL PRIMARY KEY,
    svalue TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference      VARCHAR(20) NULL UNIQUE,
    program        VARCHAR(30) NOT NULL DEFAULT 'techcamp',
    full_name      VARCHAR(150) NOT NULL,
    age            TINYINT UNSIGNED NOT NULL,
    gender         VARCHAR(30) NULL,
    email          VARCHAR(190) NOT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    password_hash  VARCHAR(255) NULL,
    phone          VARCHAR(40) NOT NULL,
    district       VARCHAR(100) NOT NULL,
    country        VARCHAR(100) NOT NULL,
    interests      VARCHAR(200) NULL,
    jersey_size    VARCHAR(10) NULL,
    park_visit     TINYINT(1) NOT NULL DEFAULT 0,
    mentorship     TINYINT(1) NOT NULL DEFAULT 1,
    camp_amount    INT UNSIGNED NOT NULL DEFAULT 0,
    jersey_amount  INT UNSIGNED NOT NULL DEFAULT 0,
    park_amount    INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount   INT UNSIGNED NOT NULL DEFAULT 0,
    funding        VARCHAR(20) NOT NULL DEFAULT 'self',
    sponsor_id     INT UNSIGNED NULL,
    sponsor_name   VARCHAR(150) NULL,
    sponsor_decided_at DATETIME NULL,
    sponsor_note   VARCHAR(255) NULL,
    source         VARCHAR(40) NOT NULL,
    source_other   VARCHAR(150) NULL,
    referred_by    VARCHAR(150) NULL,
    motivation     TEXT NULL,
    photo          VARCHAR(100) NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'pending',
    payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    amount_paid    INT UNSIGNED NOT NULL DEFAULT 0,
    payment_ref    VARCHAR(100) NULL,
    paid_at        DATETIME NULL,
    admin_notes    TEXT NULL,
    ip             VARCHAR(45) NULL,
    user_agent     VARCHAR(255) NULL,
    last_login_at  DATETIME NULL,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NULL,
    KEY idx_status (status),
    KEY idx_pay (payment_status),
    KEY idx_email (email),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NULL,
    donation_id     INT UNSIGNED NULL,
    purpose         VARCHAR(20) NOT NULL DEFAULT 'camp',
    amount          INT UNSIGNED NOT NULL,
    currency        VARCHAR(5) NOT NULL DEFAULT 'UGX',
    method          VARCHAR(20) NOT NULL DEFAULT 'mobile_money',
    provider        VARCHAR(20) NOT NULL DEFAULT 'iotec',
    payer_name      VARCHAR(150) NULL,
    payer_phone     VARCHAR(40) NULL,
    payer_email     VARCHAR(190) NULL,
    external_id     VARCHAR(64) NULL UNIQUE,
    provider_txn_id VARCHAR(64) NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    provider_status VARCHAR(40) NULL,
    message         VARCHAR(255) NULL,
    redirect_url    TEXT NULL,
    notes           TEXT NULL,
    recorded_by     INT UNSIGNED NULL,
    receipt_sent    TINYINT(1) NOT NULL DEFAULT 0,
    ip              VARCHAR(45) NULL,
    checked_at      DATETIME NULL,
    created_at      DATETIME NOT NULL,
    completed_at    DATETIME NULL,
    KEY idx_reg (registration_id),
    KEY idx_don (donation_id),
    KEY idx_status (status),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS donations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference    VARCHAR(20) NULL UNIQUE,
    donor_name   VARCHAR(150) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    phone        VARCHAR(40) NOT NULL,
    organization VARCHAR(150) NULL,
    children     INT UNSIGNED NOT NULL DEFAULT 0,
    amount       INT UNSIGNED NOT NULL,
    amount_paid  INT UNSIGNED NOT NULL DEFAULT 0,
    message      TEXT NULL,
    is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
    status       VARCHAR(20) NOT NULL DEFAULT 'pending',
    ip           VARCHAR(45) NULL,
    created_at   DATETIME NOT NULL,
    paid_at      DATETIME NULL,
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sponsors (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    organization VARCHAR(150) NULL,
    email        VARCHAR(190) NULL,
    phone        VARCHAR(40) NULL,
    seats        INT UNSIGNED NOT NULL DEFAULT 0,
    notes        VARCHAR(255) NULL,
    source       VARCHAR(20) NOT NULL DEFAULT 'admin',
    donation_id  INT UNSIGNED NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL,
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    ip              VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL,
    KEY idx_token (token_hash),
    KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS team_members (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    role       VARCHAR(120) NOT NULL,
    photo      VARCHAR(100) NULL,
    bio        VARCHAR(300) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(190) NOT NULL,
    code_hash   VARCHAR(255) NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    verified_at DATETIME NULL,
    ip          VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_email (email),
    KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_codes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    code_hash       VARCHAR(255) NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    ip              VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL,
    KEY idx_reg (registration_id),
    KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    email      VARCHAR(190) NOT NULL,
    phone      VARCHAR(40) NULL,
    message    TEXT NOT NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(255) NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    status     VARCHAR(20) NOT NULL,
    error      TEXT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45) NOT NULL,
    email      VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (skey, svalue) VALUES
  ('registration_open', '1'),
  ('camp_fee', '100000'),
  ('jersey_fee', '20000'),
  ('park_fee', '20000'),
  ('park_name', 'Aruu Falls'),
  ('min_deposit_percent', '50'),
  ('sponsor_child_amount', '120000'),
  ('camp_capacity', '300'),
  ('notify_emails', 'info@kakebetechcamp.com'),
  ('applicant_confirmation', '1'),
  ('mail_transport', 'log'),
  ('smtp_host', 'smtp.gmail.com'),
  ('smtp_port', '587'),
  ('smtp_secure', 'tls'),
  ('smtp_user', ''),
  ('smtp_pass', ''),
  ('mail_from_email', ''),
  ('mail_from_name', 'Kakebe Tech Camp'),
  ('contact_phone', '0779 712 990'),
  ('contact_whatsapp', '256779712990'),
  ('contact_email', 'info@kakebetechcamp.com'),
  ('org_website', 'https://kakebe.tech'),
  ('social_tiktok', ''),
  ('social_x', ''),
  ('social_linkedin', ''),
  ('social_facebook', ''),
  ('social_instagram', ''),
  ('social_youtube', ''),
  ('schema_version', '4');

INSERT INTO team_members (name, role, sort_order, is_active, created_at) VALUES
  ('Sedrick Otolo', 'Team Lead', 10, 1, NOW()),
  ('Krina Style', 'Ambassador', 20, 1, NOW()),
  ('Geovia Sharon Ayo (Jojo)', 'Public Relations', 30, 1, NOW()),
  ('Odida Jackson', 'Head of Programs', 40, 1, NOW()),
  ('Oscar Jerome Okello', 'Operations Lead', 50, 1, NOW()),
  ('Bodo Desderio', 'Technical Lead', 60, 1, NOW()),
  ('Komackech Moses', 'Head of Communications', 70, 1, NOW());
