<?php
/**
 * PDO connection + automatic schema installation and upgrades.
 * The database and tables are created on first run (database/schema.sql is provided
 * for hosts where you prefer to import manually).
 */

const SCHEMA_VERSION = 14;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = $GLOBALS['config']['db'];
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset']);
    $name = str_replace('`', '', $c['name']);

    try {
        $pdo = new PDO($dsn . ';dbname=' . $name, $c['user'], $c['pass'], $opts);
    } catch (PDOException $e) {
        // 1049 = unknown database: try to create it (works locally; on shared hosting create it in cPanel).
        if ((int) ($e->errorInfo[1] ?? 0) !== 1049) {
            throw $e;
        }
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$name`");
    }

    $pdo->exec("SET time_zone = '" . date('P') . "'");
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = 0;
    try {
        $version = (int) $pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn();
        if ($version >= SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException $e) {
        // settings table missing — fresh install
    }

    foreach (schema_statements() as $sql) {
        $pdo->exec($sql);
    }
    if ($version === 1) {
        migrate_v1_to_v2($pdo);
    }
    if ($version >= 1) {
        // Columns added after the first release (v3: email verification, v4: passwords & sponsorship).
        $add = [
            'email_verified'     => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER email',
            'password_hash'      => 'VARCHAR(255) NULL AFTER email_verified',
            'funding'            => "VARCHAR(20) NOT NULL DEFAULT 'self' AFTER total_amount",
            'sponsor_id'         => 'INT UNSIGNED NULL AFTER funding',
            'sponsor_name'       => 'VARCHAR(150) NULL AFTER sponsor_id',
            'sponsor_decided_at' => 'DATETIME NULL AFTER sponsor_name',
            'sponsor_note'       => 'VARCHAR(255) NULL AFTER sponsor_decided_at',
            'auth_provider'      => "VARCHAR(20) NOT NULL DEFAULT 'email' AFTER password_hash",
            'google_sub'         => 'VARCHAR(64) NULL AFTER auth_provider',
            'reminded_at'        => 'DATETIME NULL AFTER last_login_at', // v8: last payment reminder
        ];
        foreach ($add as $col => $def) {
            if (!column_exists($pdo, 'registrations', $col)) {
                $pdo->exec("ALTER TABLE registrations ADD COLUMN `$col` $def");
            }
        }
    }

    if ($version >= 1 && $version < 6) {
        // v6: camp fees are paid in full — part-paid registrations go back to "Registered" until the balance is cleared.
        $pdo->exec("UPDATE registrations SET status = 'pending' WHERE status = 'booked'");
    }
    if ($version >= 1 && $version < 7) {
        // v7: Derrick is copied on registration alerts too (he was already on payment alerts).
        $pdo->exec("UPDATE settings SET svalue = CONCAT(svalue, ', derricklamarh@gmail.com')
                    WHERE skey = 'notify_registration_cc' AND svalue <> '' AND svalue NOT LIKE '%derricklamarh@gmail.com%'");
    }

    if ($version >= 1) {
        // v10: card payments keep Pesapal's confirmation code and the masked card number
        foreach (['provider_ref' => 'VARCHAR(80) NULL AFTER provider_txn_id', 'payer_account' => 'VARCHAR(80) NULL AFTER provider_ref'] as $col => $def) {
            if (!column_exists($pdo, 'payments', $col)) {
                $pdo->exec("ALTER TABLE payments ADD COLUMN `$col` $def");
            }
        }
    }
    if ($version >= 1 && $version < 9) {
        // v9: one sponsored innovator = UGX 150,000 (fees, park experience and sports attire), about $40.
        $pdo->exec("UPDATE settings SET svalue = '150000' WHERE skey = 'sponsor_child_amount' AND svalue = '120000'");
    }

    $defaults = default_settings();
    $stmt = $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO team_members (name, role, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, ?)');
        foreach (default_team() as $i => [$name, $role]) {
            $ins->execute([$name, $role, ($i + 1) * 10, date('Y-m-d H:i:s')]);
        }
    }

    $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
        ->execute([(string) SCHEMA_VERSION]);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Upgrade an existing v1 registrations table to the payments-enabled v2 layout. */
function migrate_v1_to_v2(PDO $pdo): void
{
    $add = [
        'interests'     => "VARCHAR(200) NULL AFTER country",
        'jersey_size'   => "VARCHAR(10) NULL AFTER interests",
        'park_visit'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER jersey_size",
        'mentorship'    => "TINYINT(1) NOT NULL DEFAULT 1 AFTER park_visit",
        'camp_amount'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER mentorship",
        'jersey_amount' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER camp_amount",
        'park_amount'   => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER jersey_amount",
        'total_amount'  => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER park_amount",
        'last_login_at' => "DATETIME NULL AFTER user_agent",
    ];
    foreach ($add as $col => $def) {
        if (!column_exists($pdo, 'registrations', $col)) {
            $pdo->exec("ALTER TABLE registrations ADD COLUMN `$col` $def");
        }
    }
    if (column_exists($pdo, 'registrations', 'interest')) {
        $pdo->exec("UPDATE registrations SET interests = interest WHERE interests IS NULL AND interest IS NOT NULL");
    }
    $pdo->exec("UPDATE registrations SET camp_amount = 100000, jersey_amount = 20000, total_amount = 120000 WHERE total_amount = 0");
    $pdo->exec("UPDATE registrations SET status = 'cancelled' WHERE status = 'rejected'");
    $pdo->exec("UPDATE registrations SET status = 'pending' WHERE status = 'approved'");
    $pdo->exec("UPDATE registrations SET payment_status = 'unpaid' WHERE payment_status = 'na'");
}

function schema_statements(): array
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS settings (
            skey   VARCHAR(64) NOT NULL PRIMARY KEY,
            svalue TEXT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS admins (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(120) NOT NULL,
            email         VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            last_login_at DATETIME NULL,
            created_at    DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS registrations (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference      VARCHAR(20) NULL UNIQUE,
            program        VARCHAR(30) NOT NULL DEFAULT 'techcamp',
            full_name      VARCHAR(150) NOT NULL,
            age            TINYINT UNSIGNED NOT NULL,
            gender         VARCHAR(30) NULL,
            email          VARCHAR(190) NOT NULL,
            email_verified TINYINT(1) NOT NULL DEFAULT 0,
            password_hash  VARCHAR(255) NULL,
            auth_provider  VARCHAR(20) NOT NULL DEFAULT 'email',
            google_sub     VARCHAR(64) NULL,
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
            reminded_at    DATETIME NULL,
            created_at     DATETIME NOT NULL,
            updated_at     DATETIME NULL,
            KEY idx_status (status),
            KEY idx_pay (payment_status),
            KEY idx_email (email),
            KEY idx_created (created_at)
        ) $t",

        "CREATE TABLE IF NOT EXISTS payments (
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
            provider_ref    VARCHAR(80) NULL,
            payer_account   VARCHAR(80) NULL,
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
        ) $t",

        "CREATE TABLE IF NOT EXISTS donations (
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
        ) $t",

        "CREATE TABLE IF NOT EXISTS sponsors (
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
        ) $t",

        "CREATE TABLE IF NOT EXISTS password_resets (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL,
            token_hash      CHAR(64) NOT NULL,
            expires_at      DATETIME NOT NULL,
            used_at         DATETIME NULL,
            ip              VARCHAR(45) NULL,
            created_at      DATETIME NOT NULL,
            KEY idx_token (token_hash),
            KEY idx_ip (ip, created_at)
        ) $t",

        "CREATE TABLE IF NOT EXISTS team_members (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(120) NOT NULL,
            role       VARCHAR(120) NOT NULL,
            photo      VARCHAR(100) NULL,
            bio        VARCHAR(300) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS email_verifications (
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
        ) $t",

        "CREATE TABLE IF NOT EXISTS login_codes (
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
        ) $t",

        "CREATE TABLE IF NOT EXISTS messages (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(120) NOT NULL,
            email      VARCHAR(190) NOT NULL,
            phone      VARCHAR(40) NULL,
            message    TEXT NOT NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            ip         VARCHAR(45) NULL,
            created_at DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS email_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient  VARCHAR(255) NOT NULL,
            subject    VARCHAR(255) NOT NULL,
            status     VARCHAR(20) NOT NULL,
            error      TEXT NULL,
            created_at DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS login_attempts (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip         VARCHAR(45) NOT NULL,
            email      VARCHAR(190) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ip_time (ip, created_at)
        ) $t",

        // v11: email marketing — contacts, lists, campaigns and one row per person per campaign
        "CREATE TABLE IF NOT EXISTS mk_contacts (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email           VARCHAR(190) NOT NULL UNIQUE,
            name            VARCHAR(150) NULL,
            status          VARCHAR(16) NOT NULL DEFAULT 'valid',
            reason          VARCHAR(120) NULL,
            domain          VARCHAR(120) NOT NULL DEFAULT '',
            domain_checked  TINYINT(1) NOT NULL DEFAULT 0,
            token           CHAR(24) NOT NULL,
            created_at      DATETIME NOT NULL,
            unsubscribed_at DATETIME NULL,
            KEY idx_status (status),
            KEY idx_domain (domain)
        ) $t",

        "CREATE TABLE IF NOT EXISTS mk_lists (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS mk_list_contacts (
            list_id    INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (list_id, contact_id),
            KEY idx_contact (contact_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS mk_campaigns (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(150) NOT NULL,
            subject     VARCHAR(200) NOT NULL DEFAULT '',
            preheader   VARCHAR(200) NULL,
            body        MEDIUMTEXT NULL,
            cta_label   VARCHAR(80) NULL,
            cta_url     VARCHAR(500) NULL,
            list_id     INT UNSIGNED NULL,
            status      VARCHAR(16) NOT NULL DEFAULT 'draft',
            created_by  INT UNSIGNED NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NULL,
            queued_at   DATETIME NULL,
            finished_at DATETIME NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS mk_sends (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            contact_id  INT UNSIGNED NOT NULL,
            email       VARCHAR(190) NOT NULL,
            batch       INT UNSIGNED NOT NULL DEFAULT 1,
            status      VARCHAR(12) NOT NULL DEFAULT 'queued',
            error       VARCHAR(255) NULL,
            token       CHAR(24) NOT NULL,
            sent_at     DATETIME NULL,
            opened_at   DATETIME NULL,
            open_count  INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_campaign_contact (campaign_id, contact_id),
            UNIQUE KEY uq_token (token),
            KEY idx_campaign_batch (campaign_id, batch, status),
            KEY idx_sent (status, sent_at)
        ) $t",

        // v12: Mentorship Program & Digital Bridge Internship (DBIP) registrations, confirmed by email
        "CREATE TABLE IF NOT EXISTS mentorship_registrations (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference    VARCHAR(20) NULL UNIQUE,
            full_name    VARCHAR(150) NOT NULL,
            email        VARCHAR(190) NOT NULL UNIQUE,
            phone        VARCHAR(40) NOT NULL,
            whatsapp     VARCHAR(40) NOT NULL,
            location     VARCHAR(120) NOT NULL DEFAULT '',
            tracks       VARCHAR(255) NOT NULL DEFAULT '',
            status       VARCHAR(16) NOT NULL DEFAULT 'pending',
            notes        VARCHAR(255) NULL,
            ip           VARCHAR(45) NULL,
            link_sent_at DATETIME NULL,
            confirmed_at DATETIME NULL,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NULL,
            KEY idx_status (status),
            KEY idx_ip (ip, created_at)
        ) $t",

        // v13: first-party website statistics — page views and clicks (no cookies, no personal data)
        "CREATE TABLE IF NOT EXISTS site_events (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type       VARCHAR(8) NOT NULL,
            visitor    CHAR(16) NOT NULL,
            session    CHAR(16) NOT NULL,
            path       VARCHAR(190) NOT NULL,
            label      VARCHAR(120) NULL,
            source     VARCHAR(60) NOT NULL DEFAULT '',
            device     VARCHAR(10) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            KEY idx_type_time (type, created_at),
            KEY idx_time (created_at)
        ) $t",

        // v14: "I will be there" flyers people made — kept so the team can reuse them
        "CREATE TABLE IF NOT EXISTS flyers (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NULL,
            reference       VARCHAR(20) NOT NULL DEFAULT '',
            name            VARCHAR(80) NOT NULL DEFAULT '',
            file            VARCHAR(120) NOT NULL,
            thumb           VARCHAR(120) NULL,
            bytes           INT UNSIGNED NOT NULL DEFAULT 0,
            saves           INT UNSIGNED NOT NULL DEFAULT 1,
            ip              VARCHAR(45) NULL,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            KEY idx_registration (registration_id),
            KEY idx_ip (ip, created_at)
        ) $t",
    ];
}

function default_settings(): array
{
    return [
        'registration_open'      => '1',
        'camp_fee'               => '100000',
        'jersey_fee'             => '20000',
        'park_fee'               => '20000',
        'park_name'              => 'Aruu Falls',
        'sponsor_child_amount'   => '150000',
        'usd_rate'               => '3750',
        'camp_capacity'          => '300',
        'notify_emails'          => env('MAIL_NOTIFY', 'info@kakebetechcamp.com'),
        'notify_registration_cc' => 'sedricksedu2@gmail.com, komabono1998@gmail.com, jeromeoscar2002@gmail.com, derricklamarh@gmail.com',
        'notify_payment_cc'      => 'sedricksedu2@gmail.com, komabono1998@gmail.com, jeromeoscar2002@gmail.com, derricklamarh@gmail.com',
        'wa_reminder_message'    => 'Hello {first_name} 👋

Thank you for registering for Kakebe Tech Camp 2026 — we truly appreciate it and we are excited to have you with us! 🎉

We are now preparing for camp (accommodation, meals, jerseys and learning materials) and we plan for every participant whose payment is complete. Kindly complete your payment of *{balance}* so that we can include you in the preparations.

When you are ready to pay, use this link to make your payment (Mobile Money or card — it takes about a minute):
{pay_link}

Your code number: *{reference}*
Camp dates: {camp_dates}, Kitgum.

If you have already paid or need any help, just reply to this message. Thank you, and see you at camp! 🚀

Kakebe Tech Camp Team',
        'ga_measurement_id'      => 'G-H4TEE2S6RG',
        'wa_welcome_message'     => 'Hello {first_name} 👋

My name is Moses Komakech, and I am the Head of Comms for Kakebe Tech Camp 2026.

Thank you so much for registering for the Tech Camp! 🎉 Your reference number is *{reference}*. We are truly excited to walk this journey with you — ten days of learning, building and innovating together in Kitgum, {camp_dates}.

If you have any questions at any time, please reach out to us on this WhatsApp line — we are always happy to help.

Do you know someone who would love this too? Please recommend them — they can register here: {register_link}

Welcome to the Kakebe family! 🚀
Moses Komakech
Head of Comms, Kakebe Tech Camp',
        'ga_property_id'         => '',
        'ga_service_account'     => '',
        'applicant_confirmation' => '1',
        'mail_transport'         => env('SMTP_PASS') !== '' ? 'smtp' : 'log',
        'smtp_host'              => env('SMTP_HOST', 'smtp.gmail.com'),
        'smtp_port'              => env('SMTP_PORT', '587'),
        'smtp_secure'            => env('SMTP_SECURE', 'tls'),
        'smtp_user'              => env('SMTP_USER'),
        'smtp_pass'              => '',
        'mail_from_email'        => env('SMTP_USER'),
        'mail_from_name'         => env('MAIL_FROM_NAME', 'Kakebe Tech Camp'),
        'contact_phone'          => '0779 712 990',
        'contact_whatsapp'       => '256779712990',
        'whatsapp_group_link'    => 'https://chat.whatsapp.com/JVCkOIp5eBp8GiFDEPn2FL?s=cl&p=i&mlu=4&ilr=4',
        'contact_email'          => env('MAIL_OFFICIAL', 'info@kakebetechcamp.com'),
        'org_website'            => 'https://kakebe.tech',
        'social_tiktok'          => '',
        'social_x'               => '',
        'social_linkedin'        => '',
        'social_facebook'        => '',
        'social_instagram'       => '',
        'social_youtube'         => '',
    ];
}

function default_team(): array
{
    return [
        ['Sedrick Otolo', 'Team Lead'],
        ['Krina Style', 'Ambassador'],
        ['Geovia Sharon Ayo (Jojo)', 'Public Relations'],
        ['Odida Jackson', 'Head of Programs'],
        ['Oscar Jerome Okello', 'Operations Lead'],
        ['Bodo Desderio', 'Technical Lead'],
        ['Komackech Moses', 'Head of Communications'],
    ];
}
