<?php
/**
 * Project finance: the donor workplan and budget (clusters → budget lines), funds received, expenses with receipts,
 * and field requisitions (request → operations review → admin approval → finance payment → accountability → verified).
 * Only the team lead (admin) and finance see the finance pages; everyone with an account can make requisitions.
 * Money is stored in whole UGX.
 */

/* ------------------------------------------------------------------
 * Roles
 * ------------------------------------------------------------------ */

/** role => [label, what they can do] */
function admin_roles(): array
{
    return [
        'admin'      => ['Team lead (admin)', 'Everything, including project finance, final approval of requisitions and admin users'],
        'finance'    => ['Finance', 'Project finance, paying requisitions and checking accountability — plus the control panel'],
        'operations' => ['Operations manager', 'Reviews requisitions first — plus the control panel'],
        'team'       => ['Team member', 'The control panel and their own requisitions — no project finance'],
        'field'      => ['Field staff', 'Only their own requisitions and accountability'],
        'pending'    => ['Waiting for access', 'Signed up themselves — no access until the team lead gives them a role'],
    ];
}

function admin_role_label(string $role): string
{
    return admin_roles()[$role][0] ?? ucfirst($role);
}

/** What a role may do: panel, finance, review, approve, disburse, users. */
function role_can(string $role, string $perm): bool
{
    $map = [
        'panel'    => ['admin', 'finance', 'operations', 'team'],
        'finance'  => ['admin', 'finance'],
        'review'   => ['admin', 'operations'],
        'approve'  => ['admin'],
        'disburse' => ['admin', 'finance'],
        'users'    => ['admin'],
        'all_reqs' => ['admin', 'finance', 'operations'],
    ];
    return in_array($role, $map[$perm] ?? [], true);
}

/* ------------------------------------------------------------------
 * Project, clusters, lines
 * ------------------------------------------------------------------ */

function fin_project(?int $id = null): ?array
{
    if ($id) {
        $st = db()->prepare('SELECT * FROM fin_projects WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    return db()->query('SELECT * FROM fin_projects ORDER BY is_active DESC, id LIMIT 1')->fetch() ?: null;
}

function fin_clusters(int $projectId): array
{
    $st = db()->prepare('SELECT * FROM fin_clusters WHERE project_id = ? ORDER BY sort, id');
    $st->execute([$projectId]);
    return $st->fetchAll();
}

function fin_lines(int $projectId): array
{
    $st = db()->prepare('SELECT l.*, c.name cluster_name, c.code cluster_code FROM fin_lines l JOIN fin_clusters c ON c.id = l.cluster_id WHERE l.project_id = ? ORDER BY c.sort, c.id, l.sort, l.id');
    $st->execute([$projectId]);
    return $st->fetchAll();
}

function fin_months(): array
{
    return ['Sep' => 'September', 'Oct' => 'October', 'Nov' => 'November', 'Dec' => 'December', 'Jan' => 'January', 'Feb' => 'February', 'Mar' => 'March', 'Apr' => 'April'];
}

function fin_methods(): array
{
    return ['mobile_money' => 'Mobile Money', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'card' => 'Card'];
}

function ugx($n): string
{
    return 'UGX ' . number_format((int) round((float) $n));
}

function usd_of(int $ugx, array $project): string
{
    $rate = (float) $project['exchange_rate'] ?: 3735.91;
    return '$' . number_format($ugx / $rate, 2);
}

/**
 * Money per budget line: budget, spent (expenses), advances (paid requisitions not yet verified),
 * committed (approved, not paid), pending (waiting for review/approval) and available (budget minus all of those).
 */
function fin_line_stats(int $projectId): array
{
    $pdo = db();
    $out = [];
    $st = $pdo->prepare('SELECT id, amount FROM fin_lines WHERE project_id = ?');
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $l) {
        $out[(int) $l['id']] = ['budget' => (int) $l['amount'], 'spent' => 0, 'advances' => 0, 'committed' => 0, 'pending' => 0];
    }
    $st = $pdo->prepare('SELECT line_id, SUM(amount) s FROM fin_expenses WHERE project_id = ? GROUP BY line_id');
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $r) {
        if (isset($out[(int) $r['line_id']])) {
            $out[(int) $r['line_id']]['spent'] = (int) $r['s'];
        }
    }
    $st = $pdo->prepare("SELECT i.line_id, r.status, SUM(i.amount) s FROM fin_req_items i JOIN fin_requisitions r ON r.id = i.requisition_id
        WHERE r.project_id = ? AND r.status IN ('submitted', 'reviewed', 'approved', 'disbursed', 'accounted') GROUP BY i.line_id, r.status");
    $st->execute([$projectId]);
    $bucket = ['submitted' => 'pending', 'reviewed' => 'pending', 'approved' => 'committed', 'disbursed' => 'advances', 'accounted' => 'advances'];
    foreach ($st->fetchAll() as $r) {
        if (isset($out[(int) $r['line_id']])) {
            $out[(int) $r['line_id']][$bucket[$r['status']]] += (int) $r['s'];
        }
    }
    foreach ($out as &$s) {
        $s['used'] = $s['spent'] + $s['advances'];
        // Money waiting for review or approval is reserved too, so the same budget can't be promised twice
        $s['available'] = $s['budget'] - $s['spent'] - $s['advances'] - $s['committed'] - $s['pending'];
        $s['pct'] = $s['budget'] ? round($s['used'] / $s['budget'] * 100, 1) : ($s['used'] ? 100 : 0);
    }
    return $out;
}

/** The same figures added up per cluster, plus totals for the project ('total'). */
function fin_cluster_stats(int $projectId, ?array $lineStats = null): array
{
    $lineStats ??= fin_line_stats($projectId);
    $out = ['total' => ['budget' => 0, 'spent' => 0, 'advances' => 0, 'committed' => 0, 'pending' => 0, 'used' => 0, 'available' => 0]];
    $st = db()->prepare('SELECT id, cluster_id FROM fin_lines WHERE project_id = ?');
    $st->execute([$projectId]);
    foreach ($st->fetchAll() as $l) {
        $cid = (int) $l['cluster_id'];
        $out[$cid] ??= ['budget' => 0, 'spent' => 0, 'advances' => 0, 'committed' => 0, 'pending' => 0, 'used' => 0, 'available' => 0];
        foreach (['budget', 'spent', 'advances', 'committed', 'pending', 'used', 'available'] as $k) {
            $out[$cid][$k] += $lineStats[(int) $l['id']][$k] ?? 0;
            $out['total'][$k] += $lineStats[(int) $l['id']][$k] ?? 0;
        }
    }
    foreach ($out as &$s) {
        $s['pct'] = $s['budget'] ? round($s['used'] / $s['budget'] * 100, 1) : 0;
    }
    return $out;
}

function fin_income_total(int $projectId): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM fin_income WHERE project_id = ?');
    $st->execute([$projectId]);
    return (int) $st->fetchColumn();
}

/** Colour class for a "% of budget used" bar. */
function fin_pct_class(float $pct): string
{
    return $pct > 100 ? 'over' : ($pct >= 90 ? 'high' : ($pct > 0 ? 'ok' : 'none'));
}

function fin_bar(float $pct): string
{
    return '<div class="fin-bar ' . fin_pct_class($pct) . '"><i style="width:' . min(100, max($pct > 0 ? 2 : 0, $pct)) . '%"></i></div>';
}

/** Audit trail entry. */
function fin_log(int $projectId, string $entity, int $entityId, string $action, ?string $comment, int $adminId): void
{
    db()->prepare('INSERT INTO fin_log (project_id, entity, entity_id, action, comment, admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$projectId, $entity, $entityId, $action, $comment ? mb_substr($comment, 0, 2000) : null, $adminId, now()]);
}

/* ------------------------------------------------------------------
 * Requisitions
 * ------------------------------------------------------------------ */

/** status => [label, badge class] */
function req_statuses(): array
{
    return [
        'submitted' => ['Waiting for operations review', 'st-pending'],
        'reviewed'  => ['Waiting for admin approval', 'st-pending'],
        'approved'  => ['Approved — waiting for payment', 'st-booked'],
        'disbursed' => ['Paid — accountability due', 'st-booked'],
        'accounted' => ['Accountability sent — finance to verify', 'st-booked'],
        'closed'    => ['Complete', 'st-confirmed'],
        'rejected'  => ['Rejected', 'st-cancelled'],
        'cancelled' => ['Cancelled', 'st-waitlisted'],
    ];
}

function req_reference(int $id): string
{
    return 'REQ-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function find_requisition(int $id): ?array
{
    $st = db()->prepare('SELECT r.*, a.name requester_name, a.email requester_email FROM fin_requisitions r LEFT JOIN admins a ON a.id = r.requested_by WHERE r.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function req_items(int $reqId): array
{
    $st = db()->prepare('SELECT i.*, l.code line_code, l.item line_item FROM fin_req_items i LEFT JOIN fin_lines l ON l.id = i.line_id WHERE i.requisition_id = ? ORDER BY i.id');
    $st->execute([$reqId]);
    return $st->fetchAll();
}

/** Accountability is due this many days after payment. */
function req_accountability_days(): int
{
    return 7;
}

function req_overdue(array $r): bool
{
    return $r['status'] === 'disbursed' && $r['disbursed_at'] && strtotime($r['disbursed_at']) < time() - req_accountability_days() * 86400;
}

/** Email everyone with one of these roles (e.g. the next approvers). */
function fin_notify_roles(array $roles, string $subject, string $html, ?int $exceptAdmin = null): void
{
    $in = implode(',', array_fill(0, count($roles), '?'));
    $st = db()->prepare("SELECT id, email FROM admins WHERE role IN ($in)");
    $st->execute($roles);
    $to = [];
    foreach ($st->fetchAll() as $a) {
        if ((int) $a['id'] !== (int) $exceptAdmin) {
            $to[] = $a['email'];
        }
    }
    if ($to) {
        send_mail($to, $subject, $html);
    }
}

/** Email about a requisition, with its key facts and a button to open it. */
function tpl_requisition(array $r, string $headline, string $intro): array
{
    $inner = '<p><strong>' . e($headline) . '</strong></p><p>' . $intro . '</p>'
        . kt_detail([
            '🆔 Requisition' => $r['reference'],
            '📝 Purpose' => $r['title'],
            '👤 Requested by' => (string) ($r['requester_name'] ?? ''),
            '📍 Where' => (string) $r['location'],
            '📅 Needed on' => $r['needed_on'] ? date('D j M Y', strtotime($r['needed_on'])) : '',
            '💰 Amount' => ugx($r['total']),
        ])
        . kt_btn('Open the requisition', base_url('admin/requisition.php?id=' . (int) $r['id']), 'navy');
    return [$r['reference'] . ' · ' . $headline, kt_email('Requisitions', $inner)];
}

/* ------------------------------------------------------------------
 * Attachments (receipts, invoices, proof of payment)
 * ------------------------------------------------------------------ */

function fin_upload_dir(): string
{
    return STORAGE . '/uploads/finance';
}

function fin_allowed_types(): array
{
    return ['pdf' => '%PDF', 'jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG", 'webp' => 'RIFF', 'heic' => '', 'docx' => "PK\x03\x04", 'xlsx' => "PK\x03\x04"];
}

/**
 * Save the files posted as $field[] and attach them to (owner type, owner id).
 * Returns [number saved, list of problems].
 */
function fin_store_files(string $field, string $ownerType, int $ownerId, int $adminId): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files || !is_array($files['name'])) {
        return [0, []];
    }
    $saved = 0;
    $problems = [];
    $dir = fin_upload_dir() . '/' . date('Ym');
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
            $problems[] = $name . ': upload failed (it may be too large)';
            continue;
        }
        if ($files['size'][$i] > 10 * 1024 * 1024) {
            $problems[] = $name . ': larger than 10 MB';
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = fin_allowed_types();
        $head = (string) file_get_contents($files['tmp_name'][$i], false, null, 0, 8);
        if (!isset($types[$ext]) || ($types[$ext] !== '' && !str_starts_with($head, $types[$ext]))) {
            $problems[] = $name . ': use a photo (JPG, PNG, WEBP), a PDF, Word or Excel file';
            continue;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $problems[] = 'The upload folder could not be created on the server';
            break;
        }
        $stored = date('Ym') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], fin_upload_dir() . '/' . $stored)) {
            $problems[] = $name . ': could not be saved';
            continue;
        }
        db()->prepare('INSERT INTO fin_files (owner_type, owner_id, file, original_name, size, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ownerType, $ownerId, $stored, mb_substr(basename($name), 0, 200), (int) $files['size'][$i], $adminId, now()]);
        $saved++;
    }
    return [$saved, $problems];
}

function fin_files(string $ownerType, int $ownerId): array
{
    $st = db()->prepare('SELECT f.*, a.name uploader FROM fin_files f LEFT JOIN admins a ON a.id = f.uploaded_by WHERE owner_type = ? AND owner_id = ? ORDER BY f.id');
    $st->execute([$ownerType, $ownerId]);
    return $st->fetchAll();
}

/** Links to a list of attachments. */
function fin_files_html(array $files): string
{
    if (!$files) {
        return '<span class="muted small">No files</span>';
    }
    $html = '<div class="fin-files">';
    foreach ($files as $f) {
        $img = preg_match('/\.(jpe?g|png|webp)$/i', $f['file']);
        $html .= '<a href="fin-file.php?id=' . (int) $f['id'] . '" target="_blank" title="' . e($f['original_name']) . '"><i class="fa-regular ' . ($img ? 'fa-image' : 'fa-file-lines') . '"></i> ' . e(mb_strimwidth($f['original_name'], 0, 28, '…')) . '</a>';
    }
    return $html . '</div>';
}

/* ------------------------------------------------------------------
 * The Kakebe x UNDP workplan (EU Spotlight) — loaded once from the approved workplan spreadsheet
 * ------------------------------------------------------------------ */

function fin_seed_undp(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM fin_projects')->fetchColumn() > 0) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $outcome = 'OUTCOME 2 — Enhanced awareness of gender-equitable social norms, attitudes and behaviours that prevent SGBV and advance SRHR in schools, and greater AGYW groups influence and agency to work on ending VAWG in communities, institutions and refugee settlements (Project 01001936).';
    $output = 'Output P3.2.2 — Community advocacy platforms established/strengthened to implement strategies and interventions for social norms change. Indicator 3.2.2.1: number of approaches and public interventions, including sports, drama and dance and multimedia, promoted with project support. Target: 4 interventions and approaches promoted in communities.';
    $pdo->prepare('INSERT INTO fin_projects (name, donor, funding_source, location, exchange_rate, starts_on, ends_on, outcome, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
        ->execute(['Safe and Clean Kitgum — Kakebe x UNDP Workplan', 'UNDP', 'EU Spotlight Initiative — UNDP', 'Kitgum', 3735.91, '2026-09-01', '2026-12-31', $outcome, $now]);
    $pid = (int) $pdo->lastInsertId();

    // [code, name, description, months, target, target value, budget category, lines: [code, item, description, units, unit cost UGX]]
    $clusters = [
        ['A1', 'MentorMe360 Online Mentorship Programme', 'Conduct an online mentorship programme supporting 100 young people through personal and social development, innovation, entrepreneurship, gender and GBV-focused mentorship, with one-on-one follow-up. Includes mobilisation and onboarding.', 'Sep,Oct,Nov,Dec', 'young people mentored', 100, 'Learning costs', [
            ['A.1.2', 'Participant Mobilisation', 'Digital mobilisation, outreach, participant recruitment and communication for 100 young people', 1, 600000],
            ['A.1.4', 'Participant Onboarding', 'Registration, orientation, onboarding communication and introduction to the online mentorship programme', 100, 10000],
            ['A.1.5', 'Online Mentorship Sessions', 'Internet/data support and online session facilitation for mentorship sessions covering personal and social development, innovation, entrepreneurship, gender and GBV', 8, 200000],
            ['A.1.6', 'Mentor Facilitation', 'Facilitation for mentors delivering specialised online mentorship sessions', 8, 150000],
            ['A.1.8', 'Learning Materials', 'Digital learning resources, mentorship guides, session materials and participant resources', 100, 5000],
            ['A.1.9', 'One-on-One Follow-up', 'Individual follow-up with participants through calls, messaging and online communication', 100, 10000],
            ['A.1.11', 'Monitoring, Documentation & Reporting', 'Participant tracking, attendance records, feedback collection, documentation and activity reporting', 1, 300000],
            ['A.1.12', 'Digital Recognition & Completion', 'Digital certificates and recognition for participating youth and mentors', 1, 150000],
            ['A.1.13', 'Programme Coordination & Support', 'Coordination, scheduling, technical support and operational follow-up throughout the mentorship programme', 1, 765820],
        ]],
        ['A2', 'Digital Bridge Internship Programme', 'Provide practical ICT and workplace experience for 50+ youth, integrating digital basics, life skills, SRHR, GBV prevention, financial literacy, positive masculinities and safeguarding.', 'Sep,Oct,Nov,Dec', 'youth interns', 50, 'Learning costs', [
            ['A.2.1', 'Youth Mobilisation & Recruitment', 'Outreach, communication and recruitment of 50+ youth for the internship programme', 1, 500000],
            ['A.2.2', 'Participant Onboarding & Orientation', 'Registration, induction, programme orientation and safeguarding briefing for participating youth', 50, 20000],
            ['A.2.3', 'Digital Skills Training', 'Practical ICT training covering digital basics, workplace tools and digital productivity', 4, 500000],
            ['A.2.4', 'Workplace & Life Skills Sessions', 'Training on workplace readiness, communication, teamwork, professional conduct and life skills', 2, 300000],
            ['A.2.5', 'SRHR, GBV & Positive Masculinities Sessions', 'Facilitated learning sessions covering SRHR, GBV prevention, positive masculinities and safeguarding', 2, 300000],
            ['A.2.6', 'Financial Literacy Sessions', 'Practical sessions covering budgeting, saving, financial planning and responsible financial practices', 2, 200000],
            ['A.2.7', 'Internship Practical Experience', 'Practical workplace exposure, supervised assignments and hands-on ICT experience for participating youth', 50, 40000],
            ['A.2.7b', 'Mentorship & Supervision', 'Mentor support, participant guidance, supervision and workplace follow-up', 1, 500000],
            ['A.2.7c', 'Learning Materials & Resources', 'Digital learning resources, training materials, stationery and participant reference materials', 1, 500000],
            ['A.2.8', 'Monitoring, Documentation & Reporting', 'Attendance tracking, participant feedback, internship monitoring, documentation and reporting', 1, 300000],
            ['A.2.9', 'Completion & Recognition', 'Certificates and recognition for participating youth upon completion of the programme', 1, 500000],
        ]],
        ['A3', 'Kakebe Tech Camp', 'Flagship youth programme providing intensive digital skills, innovation, entrepreneurship, robotics, digital content creation and community engagement for 200 young people, including Community Day, online voting, Top 10 recognition and post-camp innovation/content follow-up through April 2027.', 'Oct,Nov,Dec', 'young people at camp', 200, 'Learning costs', [
            ['A.5.1', 'Participant Mobilisation & Onboarding', 'Recruitment, registration and onboarding of youth', 200, 10000],
            ['A.5.2', 'Facilitators & Mentors', 'Facilitation for digital skills, innovation, entrepreneurship and robotics sessions', 10, 200000],
            ['A.5.3', 'Meals & Refreshments', 'Food, drinking water and refreshments for participants', 200, 20000],
            ['A.5.4', 'Training & Practical Materials', 'ICT, innovation, entrepreneurship and robotics learning materials', 1, 800000],
            ['A.5.5', 'Digital Content Production', 'Content creation and technical production support', 1, 800000],
            ['A.5.6', 'Camp Logistics', 'Venue, transport and essential operational logistics', 1, 1500000],
            ['A.5.7', 'Community Day', 'Community engagement, exhibition and outreach activities', 1, 1000000],
            ['A.5.8', 'Top 10 Recognition', 'Certificates, awards and recognition for Top 10 participants', 10, 100000],
            ['A.5.9', 'Documentation & Photography', 'Photography, videography and programme documentation', 1, 800000],
            ['A.5.10', 'Visibility Materials', 'Banners, branding and participant visibility materials', 1, 500000],
            ['A.5.12', 'Post-Camp Follow-up', 'Innovation and digital content mentorship and follow-up through April 2027', 1, 500000],
        ]],
        ['TP', 'Tree Planting Initiative', 'Environmental action engaging 100+ households in fruit-tree planting, awareness and community stewardship, linking three children to each household for long-term stewardship, monitoring and follow-up. Includes 4 environmental awareness sessions.', 'Oct,Nov,Dec', 'households engaged', 100, 'Learning costs', [
            ['A.3.2', 'Tree Seedlings', 'Procuring fruit-tree seedlings', 1000, 1000],
            ['A.3.4', 'Lunch Facilitation for 2 Kakebe staff (2 × 4)', 'Food and water', 8, 50000],
            ['A.3.5', 'Facilitation of two Kakebe staff for mobilisation in schools (2 × 4)', 'Transport and airtime', 8, 250000],
            ['A.3.7', 'Tree monitoring and follow-up with a vet', 'Transport, airtime and refreshments', 4, 150000],
            ['A.3.8', 'Documentation and media coverage', 'Content creation and production, posting on social media', 1, 300000],
        ]],
        ['A4', 'Results, Learning and Visibility', 'Results measurement, monitoring, learning, beneficiary feedback, documentation, knowledge generation and programme visibility.', 'Dec', 'learning products', 0, 'Learning costs', [
            ['A.4.1', 'Results Measurement & Monitoring', 'Field monitoring, results tracking and verification', 1, 500000],
            ['A.4.2', 'Beneficiary Feedback', 'Participant feedback collection, analysis and documentation', 1, 500000],
            ['A.4.3', 'Learning & Reflection', 'Learning review, reflection sessions and lessons documentation', 1, 450000],
            ['A.4.4', 'Documentation & Knowledge Generation', 'Case studies, success stories, photos, videos and learning products', 1, 800000],
            ['A.4.5', 'Programme Visibility', 'Design, production and dissemination of programme visibility materials', 1, 500000],
            ['A.4.6', 'Final Reporting', 'Results compilation, report production and supporting documentation', 1, 400000],
            ['A.4.7', 'Communication & Data', 'Internet, data and communication for monitoring and reporting', 1, 264000],
        ]],
        ['E', 'Internet/Data & Communication (Coordination & Support)', 'Monthly internet connection and project communication.', 'Sep,Oct,Nov', '', 0, 'Coordination & support', [
            ['E.6', 'Internet/data and project communication', 'Monthly internet connection', 3, 150000],
        ]],
        ['D', 'Programme Management — Staff Costs', 'Programme management costs (project office): staff.', 'Oct,Nov,Dec', '', 0, 'Staff costs', [
            ['D.1', 'Program Lead', 'Months', 3, 700000],
            ['D.2', 'MEAL & Program Coordinator', 'Months', 3, 700000],
            ['D.3', 'Project Officer', 'Months', 3, 650000],
        ]],
    ];
    $insC = $pdo->prepare('INSERT INTO fin_clusters (project_id, code, name, description, output, months, responsible, budget_category, target_label, target_value, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insL = $pdo->prepare('INSERT INTO fin_lines (project_id, cluster_id, code, item, description, units, unit_cost, amount, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($clusters as $ci => [$code, $name, $desc, $months, $tLabel, $tValue, $cat, $lines]) {
        $insC->execute([$pid, $code, $name, $desc, $code === 'D' ? 'Programme management costs (project office)' : $output, $months, 'Kakebe', $cat, $tLabel ?: null, $tValue ?: null, ($ci + 1) * 10, $now]);
        $cid = (int) $pdo->lastInsertId();
        foreach ($lines as $li => [$lc, $item, $ldesc, $units, $cost]) {
            $insL->execute([$pid, $cid, $lc, $item, $ldesc, $units, $cost, (int) round($units * $cost), ($li + 1) * 10, $now]);
        }
    }
}

/* ------------------------------------------------------------------
 * Signatures (drawn on screen or uploaded, kept with the account)
 * ------------------------------------------------------------------ */

function signature_dir(): string
{
    return STORAGE . '/uploads/signatures';
}

function admin_signature_path(?string $file): ?string
{
    $path = signature_dir() . '/' . (string) $file;
    return $file && preg_match('/^[a-z0-9_\-]+\.png$/', $file) && is_file($path) ? $path : null;
}

/**
 * Save a signature for an account from a drawn image (data:image/png;base64,…) or an uploaded image file.
 * Always stored as a PNG. Returns an error message, or null when saved.
 */
function save_signature(int $adminId, ?string $dataUrl, ?array $upload = null): ?string
{
    if ($dataUrl && preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) > 600 * 1024 || !str_starts_with($bytes, "\x89PNG") || !($img = @imagecreatefromstring($bytes))) {
            return 'The drawn signature could not be read. Please draw it again.';
        }
    } elseif ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($upload['tmp_name'])) {
        $info = @getimagesize($upload['tmp_name']);
        if ($upload['size'] > 3 * 1024 * 1024 || !$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return 'Upload a photo of your signature (JPG, PNG or WEBP, up to 3 MB).';
        }
        $img = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if (!$img) {
            return 'The signature image could not be read.';
        }
    } else {
        return 'Draw your signature or upload a photo of it.';
    }
    // Fit into at most 900 × 300 px, keeping transparency
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 900 / $w, 300 / $h);
    $out = imagecreatetruecolor(max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale)));
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    if (!is_dir(signature_dir()) && !@mkdir(signature_dir(), 0755, true)) {
        return 'The signature folder could not be created on the server.';
    }
    $file = $adminId . '-' . bin2hex(random_bytes(6)) . '.png';
    imagepng($out, signature_dir() . '/' . $file);
    imagedestroy($img);
    imagedestroy($out);
    $old = (string) db()->query('SELECT signature_file FROM admins WHERE id = ' . $adminId)->fetchColumn();
    db()->prepare('UPDATE admins SET signature_file = ? WHERE id = ?')->execute([$file, $adminId]);
    if ($old && ($p = admin_signature_path($old))) {
        @unlink($p);   // signed documents keep their own copy (see signature_snapshot)
    }
    return null;
}

/** A copy of the person's current signature for one signed step of a requisition (so later changes don't alter it). */
function signature_snapshot(int $adminId): ?string
{
    $file = (string) db()->query('SELECT signature_file FROM admins WHERE id = ' . $adminId)->fetchColumn();
    $src = admin_signature_path($file);
    if (!$src) {
        return null;
    }
    $dir = fin_upload_dir() . '/sig';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    $copy = 'sig/' . bin2hex(random_bytes(16)) . '.png';
    return @copy($src, fin_upload_dir() . '/' . $copy) ? $copy : null;
}

function signature_snapshot_path(?string $copy): ?string
{
    return $copy && preg_match('~^sig/[a-f0-9]{32}\.png$~', $copy) && is_file(fin_upload_dir() . '/' . $copy) ? fin_upload_dir() . '/' . $copy : null;
}

/* ------------------------------------------------------------------
 * The A4 requisition & accountability PDF (with receipt photos on the following pages)
 * ------------------------------------------------------------------ */

function requisition_pdf(array $r): string
{
    $red = [225, 29, 42];
    $navy = [15, 37, 87];
    $ink = [16, 25, 53];
    $gray = [110, 116, 140];
    $soft = [244, 246, 251];
    $line = [221, 225, 236];
    $project = fin_project((int) $r['project_id']);
    $items = req_items((int) $r['id']);
    $names = array_column(db()->query('SELECT id, name FROM admins')->fetchAll(), 'name', 'id');
    $cluster = $r['cluster_id'] ? db()->query('SELECT code, name FROM fin_clusters WHERE id = ' . (int) $r['cluster_id'])->fetch() : null;
    $receipts = fin_files('req_receipt', (int) $r['id']);
    $proofs = fin_files('req_payment', (int) $r['id']);
    $isPhoto = fn($f) => (bool) preg_match('/\.(jpe?g|png|webp)$/i', $f['file']);
    $money = fn($n) => $n === null ? '—' : 'UGX ' . number_format((int) $n);
    $dt = fn($d) => $d ? date('j M Y', strtotime($d)) : '—';
    $statusLabel = req_statuses()[$r['status']][0] ?? $r['status'];

    $pdf = new SimplePdf(595, 842);
    $footer = function () use ($pdf, $gray, $r) {
        $pdf->fill($gray);
        $pdf->text(36, 822, 'Kakebe Technologies Limited · ' . $r['reference'] . ' · generated ' . date('j M Y, g:i A'), 7.5);
    };
    $label = function (float $x, float $y, string $t) use ($pdf, $gray) {
        $pdf->fill($gray);
        $pdf->text($x, $y, mb_strtoupper($t), 7.5, true);
    };

    // Header
    $pdf->fill($red);
    $pdf->rect(0, 0, 595, 86);
    $pdf->fill($navy);
    $pdf->rect(0, 86, 595, 5);
    $pdf->jpeg(ROOT . '/assets/img/pdf-logo.jpg', 36, 28, 150, 31);
    $pdf->fill([255, 255, 255]);
    $pdf->textRight(559, 40, 'REQUISITION & ACCOUNTABILITY', 15, true);
    $pdf->fill([255, 222, 218]);
    $pdf->textRight(559, 56, $r['reference'] . ' · ' . $statusLabel, 9.5, true);
    $pdf->textRight(559, 70, mb_strimwidth((string) $project['name'], 0, 70, '…'), 8);

    // Details
    $y = 116;
    $pdf->fill($ink);
    $y = $pdf->paragraph(36, $y, 523, $r['title'], 13, 16, true) + 4;
    $rows = [
        ['Requested by', (string) $r['requester_name'], 'Activity', $cluster ? $cluster['code'] . ' · ' . $cluster['name'] : '—'],
        ['Date requested', $dt($r['created_at']), 'Where', (string) $r['location']],
        ['Needed on', $dt($r['needed_on']), 'Paid', $r['disbursed_at'] ? $dt($r['disbursed_at']) . ' · ' . (fin_methods()[$r['disburse_method']] ?? '') . ($r['disburse_ref'] ? ' · ' . $r['disburse_ref'] : '') : '—'],
    ];
    foreach ($rows as [$l1, $v1, $l2, $v2]) {
        $label(36, $y, $l1);
        $label(300, $y, $l2);
        $pdf->fill($ink);
        $pdf->text(36, $y + 12, mb_strimwidth($v1, 0, 48, '…'), 9.5, true);
        $pdf->text(300, $y + 12, mb_strimwidth($v2, 0, 52, '…'), 9.5, true);
        $y += 30;
    }

    // Money summary
    $balance = $r['actual_total'] !== null && $r['disbursed_amount'] !== null ? (int) $r['disbursed_amount'] - (int) $r['actual_total'] : null;
    $boxes = [
        ['Requested', $r['original_total'] ?? $r['total']],
        ['Approved', in_array($r['status'], ['submitted', 'reviewed', 'rejected', 'cancelled'], true) ? null : $r['total']],
        ['Paid', $r['disbursed_amount']],
        ['Spent', $r['actual_total']],
        [$balance !== null && $balance < 0 ? 'Overspent' : 'Balance to return', $balance === null ? null : abs($balance)],
    ];
    $bw = 523 / 5;
    foreach ($boxes as $i => [$l, $v]) {
        $x = 36 + $i * $bw;
        $pdf->fill($i === 3 ? [255, 242, 242] : $soft);
        $pdf->rect($x + 2, $y, $bw - 4, 42);
        $label($x + 10, $y + 14, $l);
        $pdf->fill($i === 3 ? $red : $ink);
        $pdf->text($x + 10, $y + 32, $money($v), 10.5, true);
    }
    $y += 58;

    // Items table
    $cols = [[36, 18, '#'], [54, 190, 'Item & budget line'], [244, 70, 'Approved'], [314, 70, 'Spent'], [384, 175, 'What the money was spent on']];
    $head = function () use (&$y, $pdf, $cols, $navy) {
        $pdf->fill($navy);
        $pdf->rect(36, $y, 523, 20);
        $pdf->fill([255, 255, 255]);
        foreach ($cols as $i => [$x, $w, $t]) {
            in_array($i, [2, 3], true) ? $pdf->textRight($x + $w - 6, $y + 13.5, $t, 8, true) : $pdf->text($x + 5, $y + 13.5, $t, 8, true);
        }
        $y += 20;
    };
    $head();
    foreach ($items as $n => $it) {
        $a = $pdf->wrap($it['description'], 180, 8.5, true);
        $b = $pdf->wrap($it['line_code'] . ' ' . $it['line_item'] . ($it['payee'] ? ' · paid to ' . $it['payee'] : ''), 180, 7.5);
        $c = $pdf->wrap((string) ($it['note'] ?: '—'), 165, 8);
        $h = max(count($a) * 11 + count($b) * 10, count($c) * 10.5) + 12;
        if ($y + $h > 790) {
            $footer();
            $pdf->addPage();
            $y = 40;
            $head();
        }
        if ($n % 2) {
            $pdf->fill($soft);
            $pdf->rect(36, $y, 523, $h);
        }
        $pdf->fill($ink);
        $pdf->text(41, $y + 13, (string) ($n + 1), 8.5);
        $yy = $y + 13;
        foreach ($a as $t) {
            $pdf->text(59, $yy, $t, 8.5, true);
            $yy += 11;
        }
        $pdf->fill($gray);
        foreach ($b as $t) {
            $pdf->text(59, $yy, $t, 7.5);
            $yy += 10;
        }
        $pdf->fill($ink);
        $pdf->textRight(308, $y + 13, number_format((int) $it['amount']), 8.5);
        $pdf->textRight(378, $y + 13, $it['actual_amount'] !== null ? number_format((int) $it['actual_amount']) : '—', 8.5, true);
        $yy = $y + 13;
        foreach ($c as $t) {
            $pdf->text(389, $yy, $t, 8);
            $yy += 10.5;
        }
        $pdf->stroke($line);
        $pdf->line(36, $y + $h, 559, $y + $h, 0.5);
        $y += $h;
    }
    $pdf->fill([255, 242, 242]);
    $pdf->rect(36, $y, 523, 20);
    $pdf->fill($ink);
    $pdf->text(59, $y + 13.5, 'Total (UGX)', 9, true);
    $pdf->textRight(308, $y + 13.5, number_format((int) $r['total']), 9, true);
    $pdf->textRight(378, $y + 13.5, $r['actual_total'] !== null ? number_format((int) $r['actual_total']) : '—', 9, true);
    $y += 32;

    // Notes
    $notes = array_filter([
        'Justification' => (string) $r['justification'],
        'Adjusted at approval' => $r['adjust_note'] ? $r['adjust_note'] . ($r['original_total'] ? ' (requested ' . $money($r['original_total']) . ', approved ' . $money($r['total']) . ')' : '') : '',
        'Accountability report' => (string) $r['accountability'],
        'Balance settled' => (string) $r['balance_note'],
    ]);
    foreach ($notes as $l => $t) {
        if ($y > 740) {
            $footer();
            $pdf->addPage();
            $y = 40;
        }
        $label(36, $y, $l);
        $pdf->fill($ink);
        $y = $pdf->paragraph(36, $y + 12, 523, $t, 9, 12) + 6;
    }
    $photoCount = count(array_filter($receipts, $isPhoto)) + count(array_filter($proofs, $isPhoto));
    $others = array_filter($receipts, fn($f) => !$isPhoto($f));
    $label(36, $y, 'Receipts');
    $pdf->fill($ink);
    $y = $pdf->paragraph(36, $y + 12, 523, count($receipts) . ' receipt' . (count($receipts) === 1 ? '' : 's') . ' attached'
        . ($photoCount ? ' — the photos and scans are on the following page' . ($photoCount === 1 ? '' : 's') . '.' : '.')
        . ($others ? ' Also attached in the system (not images): ' . implode(', ', array_column($others, 'original_name')) . '.' : ''), 9, 12) + 10;

    // Signatures
    if ($y > 680) {
        $footer();
        $pdf->addPage();
        $y = 40;
    }
    $sigs = [
        ['Requested & accounted by', $r['requested_by'], $r['signed_at'] ?: $r['accounted_at'], $r['sig_requester']],
        ['Approved by (team lead)', $r['approved_by'], $r['approved_at'], $r['sig_approver']],
        ['Paid by (finance)', $r['disbursed_by'], $r['disbursed_at'], $r['sig_payer']],
        ['Verified by (finance)', $r['verified_by'], $r['verified_at'], $r['sig_verifier']],
    ];
    $sw = 523 / 4;
    foreach ($sigs as $i => [$l, $who, $when, $sig]) {
        $x = 36 + $i * $sw;
        $pdf->stroke($line);
        $pdf->rect($x + 3, $y, $sw - 6, 108, 's');
        $label($x + 10, $y + 14, $l);
        if ($path = signature_snapshot_path($sig)) {
            $pdf->photoFit($path, $x + 10, $y + 22, $sw - 26, 44, 600);
        } elseif ($who && $when) {
            $pdf->fill($gray);
            $pdf->text($x + 10, $y + 50, '(no signature on file)', 7.5);
        }
        $pdf->stroke($line);
        $pdf->line($x + 10, $y + 70, $x + $sw - 13, $y + 70, 0.6);
        $pdf->fill($ink);
        $pdf->text($x + 10, $y + 84, mb_strimwidth($who ? (string) ($names[$who] ?? '') : '', 0, 24, '…'), 8.5, true);
        $pdf->fill($gray);
        $pdf->text($x + 10, $y + 98, $when ? date('j M Y, g:i A', strtotime($when)) : 'Not yet', 7.5);
    }
    $footer();

    // One page per receipt / proof-of-payment photo
    $pages = array_merge(
        array_map(fn($f) => ['Receipt', $f], array_values(array_filter($receipts, $isPhoto))),
        array_map(fn($f) => ['Proof of payment', $f], array_values(array_filter($proofs, $isPhoto)))
    );
    foreach ($pages as $i => [$kind, $f]) {
        $pdf->addPage();
        $pdf->fill($navy);
        $pdf->rect(0, 0, 595, 54);
        $pdf->fill([255, 255, 255]);
        $pdf->text(36, 25, $kind . ' ' . ($i + 1) . ' of ' . count($pages) . ' · ' . $r['reference'], 12, true);
        $pdf->fill([200, 210, 235]);
        $pdf->text(36, 41, mb_strimwidth($f['original_name'], 0, 60, '…') . ' · uploaded ' . date('j M Y', strtotime($f['created_at'])) . ($f['uploader'] ? ' by ' . $f['uploader'] : ''), 8.5);
        if (!$pdf->photoFit(fin_upload_dir() . '/' . $f['file'], 36, 72, 523, 730)) {
            $pdf->fill($gray);
            $pdf->text(36, 100, 'This image could not be placed in the PDF — open it in the control panel.', 10);
        }
        $footer();
    }
    return $pdf->output();
}
