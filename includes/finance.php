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
