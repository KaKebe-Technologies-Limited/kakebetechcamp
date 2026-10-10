<?php
/**
 * Volunteer trainers for Kakebe Tech Camp: people aged 22–40 with at least a Bachelor's degree who offer their
 * expertise during the camp trainings. Volunteers receive accommodation, travel and allowances.
 * Applications (with a CV) come from /volunteers and are reviewed in Admin → Volunteer trainers.
 */

/** The training fields volunteers choose from (up to three). */
function volunteer_fields(): array
{
    return [
        'Digital Content Marketing' => 'fa-bullhorn',
        'Personal Branding'         => 'fa-id-badge',
        'AI Skills'                 => 'fa-microchip',
        'Robotics'                  => 'fa-robot',
        'Entrepreneurship'          => 'fa-lightbulb',
        'Sports & Fitness'          => 'fa-person-running',
        'Video Gaming'              => 'fa-gamepad',
    ];
}

/** Highest qualifications accepted (a Bachelor's degree is the minimum). */
function volunteer_qualifications(): array
{
    return ["Bachelor's degree", 'Postgraduate diploma', "Master's degree", 'PhD / Doctorate'];
}

function volunteer_experience_options(): array
{
    return ['Less than 1 year', '1 – 3 years', '3 – 5 years', '5 – 10 years', 'More than 10 years'];
}

function volunteer_availability_options(): array
{
    return [
        'full' => 'The full camp (' . camp()['dates_short'] . ')',
        'part' => 'Part of the camp',
    ];
}

function volunteer_statuses(): array
{
    return ['new' => 'New', 'shortlisted' => 'Shortlisted', 'accepted' => 'Accepted', 'declined' => 'Not selected'];
}

function volunteer_reference(int $id): string
{
    return 'VOL26-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function find_volunteer(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM volunteers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Age in whole years today. */
function volunteer_age(string $dob): int
{
    return (int) (new DateTime($dob))->diff(new DateTime('today'))->y;
}

function volunteer_cv_dir(): string
{
    return STORAGE . '/uploads/cvs';
}

/**
 * Check the uploaded CV: PDF or Word, up to 5 MB, and the file really is what it says.
 * Returns [extension, error message].
 */
function check_cv_upload(string $field = 'cv', int $maxMb = 5): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please attach your CV (PDF or Word).'];
    }
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > $maxMb * 1024 * 1024) {
        return [null, "Your CV is too large — the maximum size is {$maxMb} MB."];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'The CV upload failed. Please try again.'];
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    $head = (string) file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = match ($ext) {
        'pdf'  => str_starts_with($head, '%PDF'),
        'docx' => str_starts_with($head, "PK\x03\x04"),
        'doc'  => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        default => false,
    };
    return $ok ? [$ext, null] : [null, 'Please attach your CV as a PDF or Word document (.pdf, .docx or .doc).'];
}

function volunteer_cv_path(array $v): ?string
{
    $file = (string) ($v['cv_file'] ?? '');
    $path = volunteer_cv_dir() . '/' . $file;
    return preg_match('/^[a-f0-9]{32}\.(pdf|docx|doc)$/', $file) && is_file($path) ? $path : null;
}

function volunteer_cv_mime(string $file): string
{
    return [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
    ][strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** "Akello-Grace-CV.pdf" — a friendly file name for downloads and attachments. */
function volunteer_cv_name(array $v): string
{
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $v['full_name']), '-') ?: 'Volunteer';
    return $slug . '-CV.' . strtolower(pathinfo((string) $v['cv_file'], PATHINFO_EXTENSION));
}

/** Thank-you email to the applicant. */
function tpl_volunteer_received(array $v): array
{
    $inner = '<p><strong>Hi ' . first_name($v['full_name']) . ',</strong></p>'
        . '<p>Thank you for applying to volunteer as a trainer at <strong>Kakebe Tech Camp 2026</strong> (' . e(camp()['dates']) . ', Kitgum). We have received your application and CV. 🙌</p>'
        . kt_detail([
            '🆔 Application number' => $v['reference'],
            '🎯 Fields' => str_replace(',', ', ', (string) $v['fields']),
            '🎓 Qualification' => $v['qualification'] . ' — ' . $v['course'],
            '📅 Availability' => volunteer_availability_options()[$v['availability']] ?? $v['availability'],
        ], 'navy')
        . '<p>Our team will review all applications and contact shortlisted volunteers by email or phone. Volunteer trainers receive accommodation, travel and allowances — above all, it is a chance to share your skills with young innovators from across Uganda.</p>'
        . '<p><strong>The Kakebe Technologies team</strong></p>';
    return ['🙌 We received your volunteer trainer application — ' . $v['reference'], kt_email('Volunteer Trainers', $inner, 'Thank you for offering your skills to Kakebe Tech Camp 2026')];
}

/** Alert to the team, with the CV attached. */
function tpl_admin_volunteer(array $v): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>A new <strong>volunteer trainer</strong> has applied for Kakebe Tech Camp 2026. Their CV is attached.</p>'
        . kt_detail([
            '🆔 Application' => $v['reference'],
            '👤 Name' => $v['full_name'] . ' (' . volunteer_age($v['dob']) . ($v['gender'] ? ', ' . $v['gender'] : '') . ')',
            '📧 Email' => $v['email'],
            '📞 Phone' => "<a href='" . e(tel_link($v['phone'])) . "' style='color:#0F2557;font-weight:700;'>" . e($v['phone']) . "</a> · <a href='https://wa.me/" . e(intl_digits($v['phone'])) . "' style='color:#128C7E;font-weight:700;'>WhatsApp</a>",
            '📍 Based in' => $v['location'] . ($v['nationality'] ? ' · ' . $v['nationality'] : ''),
            '🎯 Fields' => str_replace(',', ', ', (string) $v['fields']),
            '🎓 Qualification' => $v['qualification'] . ' in ' . $v['course'] . ' — ' . $v['institution'] . ' (' . $v['grad_year'] . ')',
            '💼 Experience' => $v['experience'] . ($v['job_role'] ? ' · ' . $v['job_role'] : ''),
            '📅 Availability' => volunteer_availability_options()[$v['availability']] ?? $v['availability'],
            '🔗 LinkedIn / portfolio' => $v['portfolio_url'] ?? '',
            '🤝 Recommended by' => $v['referred_by'] ?? '',
            '💬 About them' => $v['bio'],
        ], '', ['📞 Phone'])
        . kt_btn('Open in control panel', base_url('admin/volunteer.php?id=' . (int) $v['id']), 'navy');
    return ['🧑‍🏫 Volunteer trainer application — ' . $v['full_name'] . ' (' . $v['reference'] . ')', kt_email('Volunteer Trainers', $inner)];
}
