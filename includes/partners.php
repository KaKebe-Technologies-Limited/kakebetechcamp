<?php
/**
 * Partners: companies, organisations and individuals who want to contribute to or partner with Kakebe Tech Camp.
 * They tell us how from /partner; the team follows up from Admin → Partners.
 */

/** Ways to partner: name => [icon, short description]. Names are stored comma-separated, so no commas in them. */
function partner_ways(): array
{
    return [
        'Financial sponsorship'    => ['fa-hand-holding-dollar', 'Fund the camp or one of its programmes'],
        'Sponsor young innovators' => ['fa-user-graduate', 'Pay for places for young people'],
        'In-kind support'          => ['fa-box-open', 'Equipment, devices, internet, transport, meals or venue'],
        'Trainers & mentors'       => ['fa-person-chalkboard', 'Share your team\'s expertise with campers'],
        'Internships & jobs'       => ['fa-briefcase', 'Host interns or hire talent after camp'],
        'Prizes & awards'          => ['fa-trophy', 'Reward the best Demo Day innovations'],
        'Media & publicity'        => ['fa-bullhorn', 'Coverage, promotion and content'],
        'Exhibit at the camp'      => ['fa-store', 'Showcase your products or services'],
        'Talk to the team first'   => ['fa-comments', 'Learn more about the camp before deciding'],
        'Something else'           => ['fa-handshake', 'Tell us your idea'],
    ];
}

function partner_types(): array
{
    return ['Company / business', 'NGO or development partner', 'Government or public agency', 'University or school', 'Media house', 'Faith-based or community organisation', 'Individual / philanthropist', 'Other'];
}

function partner_budgets(): array
{
    return ['Not sure yet — let\'s discuss', 'In-kind support only', 'Under UGX 1 million', 'UGX 1 – 5 million', 'UGX 5 – 20 million', 'Over UGX 20 million'];
}

function partner_contact_prefs(): array
{
    return ['Phone call', 'WhatsApp', 'Email', 'Meeting (in person or online)'];
}

function partner_statuses(): array
{
    return ['new' => 'New', 'contacted' => 'Contacted', 'talks' => 'In discussion', 'confirmed' => 'Confirmed partner', 'declined' => 'Not now'];
}

function partner_reference(int $id): string
{
    return 'PTN26-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function find_partner(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM partners WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Confirmation to the person who filled in the form. */
function tpl_partner_received(array $p): array
{
    $inner = '<p><strong>Hi ' . first_name($p['contact_name']) . ',</strong></p>'
        . '<p>Thank you for your interest in partnering with <strong>Kakebe Tech Camp 2026</strong> (' . e(camp()['dates']) . ', Kitgum) on behalf of <strong>' . e($p['organization']) . '</strong>. 🤝 We have received your details.</p>'
        . kt_detail([
            '🆔 Reference' => $p['reference'],
            '🤝 How you would like to partner' => str_replace(',', ', ', (string) $p['ways']),
            '💡 Your contribution' => $p['contribution'],
        ], 'navy')
        . '<p>Our team will get in touch soon to talk about how we can work together to give 800 young people from across Uganda the skills and confidence to build their future.</p>'
        . '<p><strong>The Kakebe Technologies team</strong></p>';
    return ['🤝 Thank you for your interest in partnering with Kakebe Tech Camp 2026 — ' . $p['reference'], kt_email('Partnerships', $inner, 'We received your partnership interest')];
}

/** Alert to the team. */
function tpl_admin_partner(array $p): array
{
    $inner = '<p><strong>Hi Team,</strong></p><p>A new <strong>partner</strong> wants to work with Kakebe Tech Camp 2026. Please get in touch with them.</p>'
        . kt_detail([
            '🆔 Reference' => $p['reference'],
            '🏢 Organisation' => $p['organization'] . ' (' . $p['org_type'] . ')',
            '👤 Contact person' => $p['contact_name'] . ($p['job_title'] ? ' · ' . $p['job_title'] : ''),
            '📧 Email' => $p['email'],
            '📞 Phone' => "<a href='" . e(tel_link($p['phone'])) . "' style='color:#0F2557;font-weight:700;'>" . e($p['phone']) . "</a> · <a href='https://wa.me/" . e(intl_digits($p['phone'])) . "' style='color:#128C7E;font-weight:700;'>WhatsApp</a>",
            '📍 Based in' => $p['location'],
            '🌐 Website' => (string) $p['website'],
            '🤝 Ways to partner' => str_replace(',', ', ', (string) $p['ways']),
            '💡 Contribution' => $p['contribution'],
            '💰 Estimated value' => (string) $p['budget'],
            '☎️ Prefers' => (string) $p['contact_pref'],
            '📣 Heard about us' => (string) $p['referred_by'],
        ], '', ['📞 Phone'])
        . kt_btn('Open in control panel', base_url('admin/partner.php?id=' . (int) $p['id']), 'navy');
    return ['🤝 New partner interest — ' . $p['organization'] . ' (' . $p['reference'] . ')', kt_email('Partnerships', $inner, $p['organization'] . ' wants to partner with Kakebe Tech Camp')];
}
