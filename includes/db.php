<?php
/**
 * PDO connection + automatic schema installation and upgrades.
 * The database and tables are created on first run (database/schema.sql is provided
 * for hosts where you prefer to import manually).
 */

const SCHEMA_VERSION = 3;

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
    if ($version >= 1 && $version < 3 && !column_exists($pdo, 'registrations', 'email_verified')) {
        $pdo->exec("ALTER TABLE registrations ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email");
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
        'min_deposit_percent'    => '50',
        'sponsor_child_amount'   => '120000',
        'camp_capacity'          => '300',
        'notify_emails'          => '',
        'applicant_confirmation' => '1',
        'mail_transport'         => 'log',
        'smtp_host'              => 'smtp.gmail.com',
        'smtp_port'              => '587',
        'smtp_secure'            => 'tls',
        'smtp_user'              => '',
        'smtp_pass'              => '',
        'mail_from_email'        => '',
        'mail_from_name'         => 'Kakebe Tech Camp',
        'contact_phone'          => '0779 712 990',
        'contact_whatsapp'       => '256779712990',
        'contact_email'          => '',
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
        ['Odida Jackson', 'Head of Programs'],
        ['Oscar Jerome Okello', 'Operations Lead'],
        ['Bodo Desderio', 'Technical Lead'],
        ['Komackech Moses', 'Head of Communications'],
        ['Geovia Sharon Ayo (Jojo)', 'Public Relations'],
        ['Krina Style', 'Ambassador'],
    ];
}
