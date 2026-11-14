<?php
/**
 * PDO connection + automatic schema installation and upgrades.
 * The database and tables are created on first run (database/schema.sql is provided
 * for hosts where you prefer to import manually).
 */

const SCHEMA_VERSION = 38;

/** Prepare + execute a query in one call. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

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
            'ticket_sent_at'     => 'DATETIME NULL AFTER reminded_at',   // v17: last time the camp ticket was emailed
            'checked_in_at'      => 'DATETIME NULL AFTER ticket_sent_at', // v18: checked in at camp (ticket scanned by staff)
            'checked_in_by'      => 'INT UNSIGNED NULL AFTER checked_in_at',
            'pay_deadline'       => 'DATE NULL AFTER reminded_at',          // v33: pay by this date (set when the payment email goes out)
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
    if ($version >= 1 && !column_exists($pdo, 'donations', 'beneficiaries')) {
        // v16: the names of the people a sponsor pays for
        $pdo->exec('ALTER TABLE donations ADD COLUMN beneficiaries TEXT NULL AFTER children');
    }
    if ($version >= 19 && !column_exists($pdo, 'volunteers', 'thanked_at')) {
        // v20: when the volunteer applicant was thanked (email / WhatsApp from Moses)
        $pdo->exec('ALTER TABLE volunteers ADD COLUMN thanked_at DATETIME NULL AFTER status');
    }
    if ($version >= 1 && !column_exists($pdo, 'admins', 'role')) {
        // v28: roles — the owner becomes team lead (admin); everyone else keeps the control panel as "team"
        $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'team' AFTER password_hash");
        $pdo->exec("UPDATE admins SET role = 'admin' WHERE email = 'ot.sedrick@gmail.com'");
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn() === 0) {
        $pdo->exec("UPDATE admins SET role = 'admin' ORDER BY id LIMIT 1");   // there is always a team lead
    }
    if ($version < 28) {
        fin_seed_undp($pdo);   // the Kakebe x UNDP workplan and budget
    }
    if ($version >= 1 && !column_exists($pdo, 'admins', 'signature_file')) {
        // v29: staff sign up themselves (role "pending" until the team lead gives access), signatures,
        // per-item explanations in accountability, approval adjustments and signed accountability PDFs
        $pdo->exec('ALTER TABLE admins ADD COLUMN phone VARCHAR(40) NULL AFTER role, ADD COLUMN job_title VARCHAR(120) NULL AFTER phone, ADD COLUMN signature_file VARCHAR(80) NULL AFTER job_title');
    }
    if ($version >= 1 && !column_exists($pdo, 'mk_campaigns', 'list_ids')) {
        // v32: a campaign can go to several lists and to people picked one by one
        $pdo->exec('ALTER TABLE mk_campaigns ADD COLUMN list_ids VARCHAR(500) NULL AFTER list_id, ADD COLUMN contact_ids MEDIUMTEXT NULL AFTER list_ids');
    }
    if ($version >= 1 && !column_exists($pdo, 'admins', 'features')) {
        // v30: which parts of the control panel each person can see (empty = the defaults for their role)
        $pdo->exec('ALTER TABLE admins ADD COLUMN features VARCHAR(500) NULL AFTER signature_file');
    }
    if ($version >= 28 && !column_exists($pdo, 'fin_requisitions', 'type')) {
        // v30: refund requests (money already spent, receipts attached when asking) and the phone of people paid directly
        $pdo->exec("ALTER TABLE fin_requisitions ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT 'advance' AFTER requested_by");
        $pdo->exec('ALTER TABLE fin_expenses ADD COLUMN payee_phone VARCHAR(40) NULL AFTER payee');
    }
    if ($version >= 28 && !column_exists($pdo, 'fin_req_items', 'note')) {
        $pdo->exec('ALTER TABLE fin_req_items ADD COLUMN note VARCHAR(1000) NULL AFTER payee');
        $pdo->exec('ALTER TABLE fin_requisitions ADD COLUMN original_total INT UNSIGNED NULL AFTER balance_note, ADD COLUMN adjust_note VARCHAR(1000) NULL AFTER original_total,
            ADD COLUMN signed_at DATETIME NULL AFTER adjust_note, ADD COLUMN sig_requester VARCHAR(80) NULL AFTER signed_at, ADD COLUMN sig_approver VARCHAR(80) NULL AFTER sig_requester,
            ADD COLUMN sig_payer VARCHAR(80) NULL AFTER sig_approver, ADD COLUMN sig_verifier VARCHAR(80) NULL AFTER sig_payer');
    }
    if ($version >= 37 && $version < 38) {
        // v38: replace 8 placeholder groups with the 25 real NASA Space Apps projects (Lira 2026)
        $pdo->exec("DELETE FROM nasa_groups");
        $ins = $pdo->prepare("INSERT INTO nasa_groups (name, description, capacity, sort_order, created_at) VALUES (?, ?, 4, ?, NOW())");
        $projects = [
            ['01. AgroShift — Intelligent Crop Rotation and Climate-Smart Farming', 'A digital agricultural decision-support platform comparing crop rotations using NASA Earth observations and local soil data. Enter your farm location, current crop and priorities to compare rotation sequences. The platform explains the trade-offs and uncertainty for each option using documented agronomic rules. For farmers, cooperatives and extension workers across Uganda.'],
            ['02. Karamoja CropResilience — Crop Rotation Under Dryland Conditions', 'A simulator comparing crop rotation resilience under dry, typical and wet rainfall scenarios for dryland Uganda. Analyze historical NASA rainfall data to model sorghum, millet, legume and other rotation options. Compare estimated water requirements and suitability under each scenario with visible assumptions. For agricultural research and farmer support in Karamoja and Ugandan drylands.'],
            ['03. TerraGuard — Protecting Soil Health through Sustainable Crop Management', 'A soil-focused rotation comparison platform examining crop diversity, legume inclusion and soil cover among rotation options. Provide your soil type and cropping history, then compare candidate sequences using a documented framework. The tool clearly contrasts soil-management indicators, trade-offs and guidance for field verification. For agricultural training, extension work and soil-conservation education across Uganda.'],
            ['04. AquaCrop Intelligence — Water-Smart Crop Rotation and Agricultural Planning', 'A crop-rotation planning app comparing estimated seasonal water requirements with NASA-observed climate conditions. Select your farm location and candidate sequences, then view estimated water demand versus rainfall context. A simplified water-demand model highlights potential constraints for each rotation option. For rain-fed farms and communities considering supplementary irrigation in Uganda.'],
            ['05. CassavaCycle — Sustainable Cassava-Based Farming Systems', 'A specialized rotation planning tool for cassava-growing systems examining sequences with legumes, cereals and local crops. Enter cassava location, soil info and cropping history to evaluate documented rotation options. The tool explains soil, water and crop-management considerations for each sequence with source-backed logic. For cassava farmers, cooperatives and extension workers across Uganda.'],
            ['06. ClimateLens Uganda — A Scientific Platform for Understanding Climate Trends', 'An interactive climate research platform analyzing historical rainfall and temperature observations for Ugandan locations. It plots time-series data, estimates trend direction, magnitude and statistical significance for selected study areas. Compare two locations side by side and review uncertainty and limitations of the analysis. For schools, universities, environmental researchers and district planning teams in Uganda.'],
            ['07. Victoria BlueWatch — Understanding Environmental Changes in Lake Victoria', 'A lake research interface investigating long-term trends in NASA-measured variables such as surface temperature. Select a Lake Victoria study area, retrieve historical satellite data and estimate trend direction and significance. Maps, charts and explanations clearly communicate what the measurements do and do not show. For Lake Victoria research, environmental education and university studies in Uganda.'],
            ['08. KaramGreen — Tracking Long-Term Vegetation Changes in Dryland Uganda', 'A satellite vegetation trend analysis platform using historical vegetation indices and seasonally aware comparisons. Select a dryland study area, load historical measurements and estimate long-term trend direction and magnitude. Compare two regions and review interpretation limits with significance testing. For environmental research, land-restoration studies and science education in Karamoja and other drylands.'],
            ['09. Kampala HeatScope — Investigating Urban Surface Temperature Changes', 'An environmental platform investigating historical land-surface temperature trends in and around Kampala. Retrieve NASA temperature observations, plot comparable time series and evaluate trend direction and significance. Compare urban and nearby areas with clear descriptions of measurement limitations. For urban environmental studies, civil engineering, architecture and planning education in Uganda.'],
            ['10. Teso RadarFlood — Understanding Floodplain Conditions Through Satellite Radar', 'An interactive radar-image interpretation tool comparing synthetic aperture radar observations of floodplain landscapes. Select a Teso study area and date, then compare processed radar scenes for water-surface characteristics. Results are checked against reference observations and explained with documented limitations. For GIS, hydrology and remote-sensing education in Uganda.'],
            ['11. WetlandSAR — Understanding Wetland Surfaces Through Radar', 'An interactive scientific explorer for investigating radar responses in Ugandan wetland landscapes. Select a documented wetland study area and compare calibrated radar observations for open water, vegetation and other surfaces. The emphasis is on interpretation and evidence, not automated classification of degradation. For GIS students, environmental researchers and wetland managers across Uganda.'],
            ['12. RadarLab Africa — An Interactive Laboratory for Satellite Radar Science', 'A hands-on learning environment demonstrating radar backscatter, viewing geometry and the meaning of image changes. Load a sample radar scene, inspect backscatter values at selected locations and work through guided comparisons. Includes a worked analytical task based on the official 2026 SAR challenge with an evidence trail. For Ugandan university and technical-institution learners in remote sensing and GIS.'],
            ['13. FireTrace — Historical Burning Activity in Northern Uganda', 'An interactive historical burning calendar harmonizing MODIS and VIIRS active-fire hotspot records for selected areas. Choose a district, load historical fire data from both sensors and apply a documented harmonization method. View aggregated monthly burning calendars, maps and explanations of sensor limitations. For environmental learning and land management research in Northern Uganda.'],
            ['14. Karamoja BurnCalendar — Understanding Seasonal Burning Patterns', 'A tool focused on the seasonality of satellite-observed burning using harmonized MODIS and VIIRS hotspot records. Define study areas in Karamoja and compare fire observations across months, years and subregions. Build monthly fire-activity calendars and compare seasonal profiles with variability and detection limitations shown. For education and research into seasonal fire patterns across Karamoja landscapes.'],
            ['15. FireAnomaly 256 — Detecting Unusual Historical Fire Activity', 'A platform identifying anomalies in historical burning activity by comparing harmonized MODIS and VIIRS records against a baseline. Select a geographic area, calculate the historical distribution and compare it against a chosen period. Visualize anomalies and describe the uncertainty in comparisons using a documented method. For exploring unusual past fire activity in Ugandan districts and supporting environmental education.'],
            ['16. LunaQuest Junior — Build and Manage a Moon Settlement', 'An interactive resource-management game where students establish a lunar settlement and keep a crew safe under engineering constraints. Choose crew size, habitat and resource allocation, then simulate resource consumption over a short mission. Make engineering decisions on power, food, shielding and water recycling with live feedback on each choice. For science clubs and classrooms across Uganda exploring engineering principles in a low-bandwidth browser game.'],
            ['17. RedPlanet Survival — Responding to Emergencies in a Mars Habitat', 'A Mars habitat crisis game centered on responding to unexpected incidents while maintaining essential mission systems. Initialize a simulated Mars crew, then face events such as power failure, water malfunction or radiation exposure risk. Choose among limited mitigation actions and see outcomes explained with engineering and scientific logic. A practical science-club game for Ugandan secondary schools and introductory engineering courses.'],
            ['18. HabitatX — Engineering a Sustainable Lunar Outpost', 'An interactive configuration game where learners choose lunar outpost systems and see how design decisions affect mission viability. Select habitat configurations, shielding and power systems while applying simplified resource costs and engineering constraints. Test your design against an event or requirement, review trade-offs and redesign the outpost. For Ugandan civil, mechanical and electrical engineering students learning systems design.'],
            ['19. LunaSolar Navigator — Comparing Lunar Solar Power Conditions', 'A location-and-date browser comparing the apparent Sun position and illumination conditions at selected lunar sites. Load candidate lunar landing coordinates, choose a site and date, and compute Sun-position geometry using validated resources. Display a horizon-style view and compare two sites, with solar-power implications and calculation assumptions explained. For astronomy, physics and engineering education in Ugandan universities and schools.'],
            ['20. EarthLink Moon — Exploring Direct-to-Earth Visibility from the Moon', 'An educational lunar site browser centered on showing Earth visibility and comparing direct-to-Earth communication windows. Choose a documented lunar site and date range, compute Earth position relative to the local lunar horizon and visualize visibility. Compare two sites and explore how terrain and system constraints affect practical communications. For Ugandan students learning applied orbital geometry and mission communications.'],
            ['21. Space Relics Museum — Stories of NASA Equipment Left on the Moon and Mars', 'A browsable digital exhibit telling verified stories of NASA hardware remaining on the Moon, Mars and in deep space. Each exhibit covers purpose, mission journey, scientific contribution and present status with approved images and source attribution. An interactive timeline or location-based collection presents five documented objects with science explanations. For Ugandan schools and science clubs that may not have access to physical space museums.'],
            ['22. MissionTales — Interactive Space Equipment Stories for Young Learners', 'A guided storybook translating verified histories of abandoned NASA equipment into illustrated, age-appropriate learning journeys. Three hardware stories from NASA sources are structured into mission goal, scientific work and outcome with original illustrations. Interactive choices and short knowledge checks bring each story to life and distinguish fact from creative narrative. A lightweight school resource suitable for English and Ugandan local-language versions.'],
            ['23. MarsPath 360 — Mapping Routes for a Future Marswalk', 'A layered Martian map comparing candidate routes to a destination using terrain and scientific observations from NASA missions. Import elevation, imagery and mission data, then mark a start point and destination to compare possible routes. Routes are evaluated using a transparent slope/distance method with risks, limitations and supporting data shown. For Ugandan GIS and civil engineering students learning planetary route planning and geospatial analysis.'],
            ['24. RedPlanet Expedition — Planning Scientific Stops on a Marswalk', 'A mission-oriented Marswalk planner connecting scientific points of interest, terrain layers and route-planning constraints. Choose a Mars study area with geological or imaging data, identify documented science locations and construct a multi-stop route. Display distance, terrain considerations and a simple time or resource budget with the proposed science rationale explained. For Ugandan geology, GIS and engineering students planning simulated planetary field missions.'],
            ['25. MissionCraft — Designing a Space Mission Under Real Engineering Constraints', 'A game where participants design a simulated space mission by selecting objectives and technical systems while balancing real constraints. Choose a mission objective, instruments, spacecraft systems and communication options with mass, power and budget limits applied. See how choices change mission feasibility and science capability, with explainable scores and redesign options. An engineering design game for Ugandan STEM clubs and university students interested in satellites and space systems.'],
        ];
        foreach ($projects as $i => [$name, $desc]) {
            $ins->execute([$name, $desc, ($i + 1) * 10]);
        }
        // 25 groups × 4 participants = 100 total spots
        $pdo->exec("INSERT INTO settings (skey, svalue) VALUES ('nasa_total_cap', '100') ON DUPLICATE KEY UPDATE svalue = '100'");
    }
    if ($version >= 1 && $version < 37) {
        // v37: NASA Space Apps Challenge 2026 — groups and registrations
        $pdo->exec("CREATE TABLE IF NOT EXISTS nasa_groups (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(150) NOT NULL,
            description TEXT NULL,
            capacity   TINYINT UNSIGNED NOT NULL DEFAULT 4,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS nasa_registrations (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference  VARCHAR(20) NOT NULL UNIQUE,
            full_name  VARCHAR(150) NOT NULL,
            email      VARCHAR(190) NOT NULL,
            phone      VARCHAR(40) NOT NULL,
            group_id   INT UNSIGNED NOT NULL,
            ip         VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_group (group_id),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Placeholder groups — replace with actual challenge areas in Admin → NASA Space Apps
        $ins = $pdo->prepare("INSERT INTO nasa_groups (name, description, capacity, sort_order, created_at) VALUES (?, ?, 4, ?, NOW())");
        $placeholders = [
            ['Earth & Environment', 'Explore challenges around climate change, land use, water resources and environmental monitoring using NASA satellite data.'],
            ['Space Exploration', 'Design solutions for deep space travel, lunar missions, Mars exploration and the future of human spaceflight.'],
            ['Technology & Engineering', 'Build hardware, software or systems that solve real problems in space technology, robotics or engineering.'],
            ['Data Analytics & AI', 'Use NASA open datasets and machine learning to uncover insights, build predictive models or visualise complex space data.'],
            ['Life Sciences', 'Investigate how living organisms survive in space: health, nutrition, agriculture and biological systems beyond Earth.'],
            ['Climate & Weather', 'Tackle weather prediction, disaster response, ocean temperatures and atmospheric science with NASA Earth observation data.'],
            ['Astronomy & Astrophysics', 'Dive into the cosmos: exoplanets, black holes, the Big Bang and the tools that help us understand the universe.'],
            ['Arts, Education & Outreach', 'Communicate space science to the public through storytelling, art, games, apps or educational tools.'],
        ];
        foreach ($placeholders as $i => [$name, $desc]) {
            $ins->execute([$name, $desc, ($i + 1) * 10]);
        }
    }
    if ($version >= 1 && $version < 36) {
        // v36: volunteer applications closed again (shortlisting has started); reopen any time in Admin → Volunteer trainers
        $pdo->exec("INSERT INTO settings (skey, svalue) VALUES ('volunteers_open', '0') ON DUPLICATE KEY UPDATE svalue = '0'");
    }
    if ($version >= 1 && $version < 27) {
        // v27: volunteer applications closed (high numbers); reopen any time in Admin → Volunteer trainers
        $pdo->exec("INSERT INTO settings (skey, svalue) VALUES ('volunteers_open', '0') ON DUPLICATE KEY UPDATE svalue = '0'");
    }
    if ($version < 26) {
        // v26: attendance opens from Admin → Attendance; on this update it is open for one more hour
        $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES ('attendance_open_until', ?), ('attendance_opened_at', ?)")
            ->execute([date('Y-m-d H:i:s', time() + 3600), date('Y-m-d 00:00:00')]);
    }
    if ($version >= 1 && $version < 23) {
        // v23: the camp now gathers 800 young people (was 300)
        $pdo->exec("UPDATE settings SET svalue = '800' WHERE skey = 'camp_capacity' AND svalue = '300'");
    }
    if ($version >= 1 && !column_exists($pdo, 'volunteers', 'ai_score')) {
        // v35: AI review of volunteer applications — score, best field, summary, notes, shortlist rank and cost
        try {
            $pdo->exec("ALTER TABLE volunteers
            ADD COLUMN ai_score TINYINT UNSIGNED NULL AFTER admin_notes, ADD COLUMN ai_field VARCHAR(60) NULL AFTER ai_score,
            ADD COLUMN ai_summary VARCHAR(700) NULL AFTER ai_field, ADD COLUMN ai_notes TEXT NULL AFTER ai_summary,
            ADD COLUMN ai_meets TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_notes, ADD COLUMN ai_rank SMALLINT UNSIGNED NULL AFTER ai_meets,
            ADD COLUMN ai_pick TINYINT(1) NOT NULL DEFAULT 0 AFTER ai_rank, ADD COLUMN ai_cost DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER ai_pick,
            ADD COLUMN ai_scored_at DATETIME NULL AFTER ai_cost, ADD COLUMN ai_error VARCHAR(255) NULL AFTER ai_scored_at,
            ADD KEY idx_ai (ai_score)");
        } catch (PDOException $e) {
            if (!in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061], true)) {   // another request added them first
                throw $e;
            }
        }
    }
    if ($version >= 1 && $version < 34) {
        // v34: the full camp package is UGX 140,000 — the park excursion is part of every package. Added to registrations
        // that have paid nothing yet (part-paid and fully paid registrations keep the package they paid for).
        $parkFee = (int) ($pdo->query("SELECT svalue FROM settings WHERE skey = 'park_fee'")->fetchColumn() ?: 20000);
        $pdo->prepare("UPDATE registrations SET park_visit = 1, park_amount = ?, total_amount = camp_amount + jersey_amount + ?, updated_at = NOW()
                       WHERE park_visit = 0 AND amount_paid = 0 AND payment_status = 'unpaid' AND status <> 'cancelled'")->execute([$parkFee, $parkFee]);
    }
    if ($version >= 1 && !column_exists($pdo, 'flyers', 'x_posted_at')) {
        // v33: flyers copied for posting on the camp's X account (and by whom)
        $pdo->exec('ALTER TABLE flyers ADD COLUMN x_posted_at DATETIME NULL AFTER ip, ADD COLUMN x_posted_by INT UNSIGNED NULL AFTER x_posted_at');
    }
    if ($version >= 1 && !column_exists($pdo, 'donations', 'thanked_at')) {
        // v22: when the sponsor was sent the thank-you message
        $pdo->exec('ALTER TABLE donations ADD COLUMN thanked_at DATETIME NULL AFTER status');
    }
    if ($version >= 1 && !column_exists($pdo, 'email_log', 'duration_ms')) {
        // v21: how many people each email went to, and how long the mail server took to accept it
        $pdo->exec('ALTER TABLE email_log ADD COLUMN recipients SMALLINT UNSIGNED NULL AFTER subject, ADD COLUMN duration_ms INT UNSIGNED NULL AFTER status');
    }
    if ($version >= 12 && !column_exists($pdo, 'mentorship_registrations', 'referred_by')) {
        // v15: who recommended them for the Mentorship Program & DBIP
        $pdo->exec('ALTER TABLE mentorship_registrations ADD COLUMN referred_by VARCHAR(150) NULL AFTER tracks');
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
            role          VARCHAR(20) NOT NULL DEFAULT 'team',
            phone         VARCHAR(40) NULL,
            job_title     VARCHAR(120) NULL,
            signature_file VARCHAR(80) NULL,
            features      VARCHAR(500) NULL,
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
            pay_deadline   DATE NULL,
            ticket_sent_at DATETIME NULL,
            checked_in_at  DATETIME NULL,
            checked_in_by  INT UNSIGNED NULL,
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
            beneficiaries TEXT NULL,
            amount       INT UNSIGNED NOT NULL,
            amount_paid  INT UNSIGNED NOT NULL DEFAULT 0,
            message      TEXT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            status       VARCHAR(20) NOT NULL DEFAULT 'pending',
            thanked_at   DATETIME NULL,
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
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient   VARCHAR(255) NOT NULL,
            subject     VARCHAR(255) NOT NULL,
            recipients  SMALLINT UNSIGNED NULL,
            status      VARCHAR(20) NOT NULL,
            duration_ms INT UNSIGNED NULL,
            error       TEXT NULL,
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
            list_ids    VARCHAR(500) NULL,
            contact_ids MEDIUMTEXT NULL,
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
            referred_by  VARCHAR(150) NULL,
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

        // v19: volunteer trainers (applications with a CV)
        "CREATE TABLE IF NOT EXISTS volunteers (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference     VARCHAR(20) NULL UNIQUE,
            full_name     VARCHAR(150) NOT NULL,
            email         VARCHAR(190) NOT NULL UNIQUE,
            phone         VARCHAR(40) NOT NULL,
            gender        VARCHAR(30) NULL,
            dob           DATE NOT NULL,
            nationality   VARCHAR(80) NULL,
            location      VARCHAR(120) NOT NULL,
            qualification VARCHAR(60) NOT NULL,
            course        VARCHAR(150) NOT NULL,
            institution   VARCHAR(150) NOT NULL,
            grad_year     SMALLINT UNSIGNED NOT NULL,
            fields        VARCHAR(255) NOT NULL,
            experience    VARCHAR(40) NOT NULL,
            job_role      VARCHAR(150) NULL,
            portfolio_url VARCHAR(255) NULL,
            bio           TEXT NOT NULL,
            availability  VARCHAR(10) NOT NULL,
            referred_by   VARCHAR(150) NULL,
            cv_file       VARCHAR(80) NOT NULL,
            cv_size       INT UNSIGNED NOT NULL DEFAULT 0,
            status        VARCHAR(16) NOT NULL DEFAULT 'new',
            thanked_at    DATETIME NULL,
            admin_notes   TEXT NULL,
            ai_score      TINYINT UNSIGNED NULL,
            ai_field      VARCHAR(60) NULL,
            ai_summary    VARCHAR(700) NULL,
            ai_notes      TEXT NULL,
            ai_meets      TINYINT(1) NOT NULL DEFAULT 1,
            ai_rank       SMALLINT UNSIGNED NULL,
            ai_pick       TINYINT(1) NOT NULL DEFAULT 0,
            ai_cost       DECIMAL(10,4) NOT NULL DEFAULT 0,
            ai_scored_at  DATETIME NULL,
            ai_error      VARCHAR(255) NULL,
            ip            VARCHAR(45) NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NULL,
            KEY idx_status (status),
            KEY idx_ai (ai_score),
            KEY idx_ip (ip, created_at)
        ) $t",

        // v28: project finance — workplan clusters and budget lines, funds received, expenses, requisitions, receipts
        "CREATE TABLE IF NOT EXISTS fin_projects (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name           VARCHAR(200) NOT NULL,
            donor          VARCHAR(120) NULL,
            funding_source VARCHAR(160) NULL,
            location       VARCHAR(120) NULL,
            exchange_rate  DECIMAL(12,4) NOT NULL DEFAULT 3735.91,
            starts_on      DATE NULL,
            ends_on        DATE NULL,
            outcome        TEXT NULL,
            is_active      TINYINT(1) NOT NULL DEFAULT 1,
            created_at     DATETIME NOT NULL
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_clusters (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id      INT UNSIGNED NOT NULL,
            code            VARCHAR(20) NOT NULL,
            name            VARCHAR(200) NOT NULL,
            description     TEXT NULL,
            output          TEXT NULL,
            months          VARCHAR(60) NULL,
            responsible     VARCHAR(120) NULL,
            budget_category VARCHAR(80) NULL,
            target_label    VARCHAR(120) NULL,
            target_value    INT UNSIGNED NULL,
            reached         INT UNSIGNED NOT NULL DEFAULT 0,
            progress        TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sort            INT NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            KEY idx_project (project_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_lines (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id  INT UNSIGNED NOT NULL,
            cluster_id  INT UNSIGNED NOT NULL,
            code        VARCHAR(20) NOT NULL,
            item        VARCHAR(200) NOT NULL,
            description TEXT NULL,
            units       DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_cost   INT UNSIGNED NOT NULL DEFAULT 0,
            amount      INT UNSIGNED NOT NULL DEFAULT 0,
            sort        INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NULL,
            KEY idx_cluster (cluster_id),
            KEY idx_project (project_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_income (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id  INT UNSIGNED NOT NULL,
            received_on DATE NOT NULL,
            source      VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            amount      INT UNSIGNED NOT NULL,
            method      VARCHAR(20) NULL,
            reference   VARCHAR(120) NULL,
            recorded_by INT UNSIGNED NULL,
            created_at  DATETIME NOT NULL,
            KEY idx_project (project_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_expenses (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id     INT UNSIGNED NOT NULL,
            line_id        INT UNSIGNED NOT NULL,
            spent_on       DATE NOT NULL,
            payee          VARCHAR(150) NOT NULL,
            payee_phone    VARCHAR(40) NULL,
            description    VARCHAR(500) NOT NULL,
            amount         INT UNSIGNED NOT NULL,
            method         VARCHAR(20) NULL,
            reference      VARCHAR(120) NULL,
            invoice_no     VARCHAR(80) NULL,
            location       VARCHAR(150) NULL,
            spent_by       VARCHAR(150) NULL,
            requisition_id INT UNSIGNED NULL,
            recorded_by    INT UNSIGNED NULL,
            created_at     DATETIME NOT NULL,
            KEY idx_project (project_id, spent_on),
            KEY idx_line (line_id),
            KEY idx_req (requisition_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_requisitions (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id       INT UNSIGNED NOT NULL,
            reference        VARCHAR(20) NULL UNIQUE,
            requested_by     INT UNSIGNED NOT NULL,
            type             VARCHAR(10) NOT NULL DEFAULT 'advance',
            title            VARCHAR(200) NOT NULL,
            cluster_id       INT UNSIGNED NULL,
            location         VARCHAR(150) NULL,
            needed_on        DATE NULL,
            justification    TEXT NULL,
            total            INT UNSIGNED NOT NULL DEFAULT 0,
            status           VARCHAR(16) NOT NULL DEFAULT 'submitted',
            reviewed_by      INT UNSIGNED NULL,
            reviewed_at      DATETIME NULL,
            approved_by      INT UNSIGNED NULL,
            approved_at      DATETIME NULL,
            disbursed_by     INT UNSIGNED NULL,
            disbursed_at     DATETIME NULL,
            disbursed_amount INT UNSIGNED NULL,
            disburse_method  VARCHAR(20) NULL,
            disburse_ref     VARCHAR(120) NULL,
            accounted_at     DATETIME NULL,
            actual_total     INT UNSIGNED NULL,
            accountability   TEXT NULL,
            verified_by      INT UNSIGNED NULL,
            verified_at      DATETIME NULL,
            balance_note     VARCHAR(500) NULL,
            original_total   INT UNSIGNED NULL,
            adjust_note      VARCHAR(1000) NULL,
            signed_at        DATETIME NULL,
            sig_requester    VARCHAR(80) NULL,
            sig_approver     VARCHAR(80) NULL,
            sig_payer        VARCHAR(80) NULL,
            sig_verifier     VARCHAR(80) NULL,
            created_at       DATETIME NOT NULL,
            updated_at       DATETIME NULL,
            KEY idx_project_status (project_id, status),
            KEY idx_requester (requested_by)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_req_items (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            requisition_id INT UNSIGNED NOT NULL,
            line_id        INT UNSIGNED NOT NULL,
            description    VARCHAR(300) NOT NULL,
            qty            DECIMAL(12,2) NOT NULL DEFAULT 1,
            unit_cost      INT UNSIGNED NOT NULL DEFAULT 0,
            amount         INT UNSIGNED NOT NULL DEFAULT 0,
            actual_amount  INT UNSIGNED NULL,
            payee          VARCHAR(150) NULL,
            note           VARCHAR(1000) NULL,
            KEY idx_req (requisition_id),
            KEY idx_line (line_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_files (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_type    VARCHAR(20) NOT NULL,
            owner_id      INT UNSIGNED NOT NULL,
            file          VARCHAR(80) NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            size          INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by   INT UNSIGNED NULL,
            created_at    DATETIME NOT NULL,
            KEY idx_owner (owner_type, owner_id)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            entity     VARCHAR(20) NOT NULL,
            entity_id  INT UNSIGNED NOT NULL,
            action     VARCHAR(40) NOT NULL,
            comment    TEXT NULL,
            admin_id   INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_entity (entity, entity_id),
            KEY idx_project (project_id, created_at)
        ) $t",

        "CREATE TABLE IF NOT EXISTS fin_updates (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cluster_id INT UNSIGNED NOT NULL,
            progress   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            reached    INT UNSIGNED NULL,
            note       TEXT NULL,
            admin_id   INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_cluster (cluster_id, created_at)
        ) $t",

        // v31: phone calls to participants — who is assigned to call each person, and every call made
        "CREATE TABLE IF NOT EXISTS call_list (
            registration_id INT UNSIGNED NOT NULL PRIMARY KEY,
            assigned_to     INT UNSIGNED NULL,
            assigned_at     DATETIME NULL,
            last_outcome    VARCHAR(20) NULL,
            last_response   VARCHAR(20) NULL,
            promise_date    DATE NULL,
            last_comment    VARCHAR(1000) NULL,
            attempts        INT UNSIGNED NOT NULL DEFAULT 0,
            last_called_at  DATETIME NULL,
            last_called_by  INT UNSIGNED NULL,
            KEY idx_assigned (assigned_to),
            KEY idx_outcome (last_outcome)
        ) $t",

        "CREATE TABLE IF NOT EXISTS call_logs (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            registration_id INT UNSIGNED NOT NULL,
            admin_id        INT UNSIGNED NOT NULL,
            outcome         VARCHAR(20) NOT NULL,
            response        VARCHAR(20) NULL,
            promise_date    DATE NULL,
            comment         VARCHAR(1000) NULL,
            created_at      DATETIME NOT NULL,
            KEY idx_reg (registration_id, created_at),
            KEY idx_admin (admin_id, created_at)
        ) $t",

        // v25: quick attendance (/attendance) — a name and the time it was entered
        "CREATE TABLE IF NOT EXISTS attendance (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name  VARCHAR(150) NOT NULL,
            ip         VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_created (created_at),
            KEY idx_ip (ip, created_at)
        ) $t",

        // v24: organisations and people who want to partner with the camp (/partner)
        "CREATE TABLE IF NOT EXISTS partners (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference    VARCHAR(20) NULL UNIQUE,
            organization VARCHAR(150) NOT NULL,
            org_type     VARCHAR(60) NOT NULL,
            contact_name VARCHAR(150) NOT NULL,
            job_title    VARCHAR(120) NULL,
            email        VARCHAR(190) NOT NULL,
            phone        VARCHAR(40) NOT NULL,
            location     VARCHAR(120) NOT NULL,
            website      VARCHAR(255) NULL,
            ways         VARCHAR(500) NOT NULL,
            contribution TEXT NOT NULL,
            budget       VARCHAR(60) NULL,
            contact_pref VARCHAR(60) NULL,
            referred_by  VARCHAR(150) NULL,
            status       VARCHAR(16) NOT NULL DEFAULT 'new',
            admin_notes  TEXT NULL,
            ip           VARCHAR(45) NULL,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NULL,
            KEY idx_status (status),
            KEY idx_ip (ip, created_at)
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
            x_posted_at     DATETIME NULL,
            x_posted_by     INT UNSIGNED NULL,
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
        'pay_popup'              => '1',
        'nasa_whatsapp_url'      => '',
        'nasa_total_cap'         => '48',
        'camp_fee'               => '100000',
        'jersey_fee'             => '20000',
        'park_fee'               => '20000',
        'park_name'              => 'Aruu Falls',
        'sponsor_child_amount'   => '150000',
        'usd_rate'               => '3750',
        'camp_capacity'          => '800',
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
