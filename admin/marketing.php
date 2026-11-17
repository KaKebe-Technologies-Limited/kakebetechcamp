<?php
require __DIR__ . '/_init.php';
$admin = require_admin();

// One-off (19 Oct 2026): invitation to Session 1 of the MentorMe360 Online Mentorship Programme, ready to send to
// everyone registered on the website. Created the first time this page is opened that day (the flag stops duplicates).
if (date('Y-m-d') <= '2026-10-19' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_mentor_s1', '1')") === 1) {
    $sync = mk_sync_website_people();
    db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_from_name', 'Kakebe Technologies')");   // sent as Kakebe Technologies
    $meet = 'https://meet.google.com/xsk-swoi-nvf';
    $flyer = base_url('assets/img/email/mentorship-session-1.jpg');
    $strip = base_url('assets/img/email/partners-strip.png');
    $body = '<img src="' . $flyer . '" alt="MentorMe360 Mentorship Session 1 — Monday 19 October, 8:00 – 9:00 PM">'
        . '<p>Dear {first_name},</p>'
        . '<p>The <strong>MentorMe360 Online Mentorship Programme</strong> — sponsored by <strong>UNDP, the European Union, the Spotlight Initiative, BetterLife International Organisation and Oroma TV</strong> — runs <strong>every Monday from 8:00 to 9:00 PM (EAT)</strong> on Google Meet.</p>'
        . '<p>Because you signed up for <strong>Kakebe Tech Camp 2026</strong> or the <strong>Mentorship Program</strong>, you are already part of it — congratulations! The programme prepares you for the camp in Kitgum (14th – 23rd December), and the first session is <strong>tonight</strong>.</p>'
        . '<h2>Session 1 — tonight</h2>'
        . '<ul>'
        . '<li>🎯 <strong>Theme:</strong> Building Self-Awareness, Confidence and Responsible Leadership</li>'
        . '<li>📅 <strong>Date:</strong> Monday 19th October 2026</li>'
        . '<li>⏰ <strong>Time:</strong> 8:00 – 9:00 PM (EAT)</li>'
        . '<li>💻 <strong>Join:</strong> <a href="' . $meet . '">meet.google.com/xsk-swoi-nvf</a></li>'
        . '<li>🎙️ <strong>Guest:</strong> Ms. Brenda Okello, Executive Director, Meraki Action Initiative</li>'
        . '<li>🎤 <strong>Moderator:</strong> Sedrick Otolo, Team Lead, Kakebe</li>'
        . '</ul>'
        . '<p>We will talk about personal growth, emotional intelligence, accountability and becoming a positive role model.</p>'
        . '<blockquote><strong>📌 Please note</strong><br>'
        . '• At least <strong>75% attendance</strong> of the Monday sessions is key to your programming for the Tech Camp.<br>'
        . '• <strong>Attendance is taken during each session</strong> — the link is shared live, so please join on time.<br>'
        . '• There will be <strong>assessments every couple of weeks</strong>, and <strong>certificates</strong> at the end of the programme.<br>'
        . '• The programme is <strong>fully paid for by UNDP and our partners</strong> — take this special opportunity seriously and make the most of it.</blockquote>'
        . '<p>Come with your questions, and feel free to share the link with friends — the session is open to all young people.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>See you tonight,<br><strong>Kakebe Technologies</strong><br>Kakebe Tech Camp 2026 Team</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    $webLists = implode(',', [mk_list_id('Website · Tech Camp participants'), mk_list_id('Website · Mentorship & DBIP')]);
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'MentorMe360 Session 1 — tonight, Mon 19 Oct, 8 PM',
        '{first_name}, join tonight’s MentorMe360 mentorship session — 8 PM',
        'Sponsored by UNDP, the EU, the Spotlight Initiative, BetterLife and Oroma TV · every Monday, 8:00 – 9:00 PM',
        mk_clean_body($body), 'Join on Google Meet', $meet, $sync['list_id'], $webLists, $admin['id'], now(), now(),
    ]);
}

// One-off (19–25 Oct 2026): thank-you to the volunteer trainers who applied, ready to send to the volunteers list.
if (date('Y-m-d') <= '2026-10-25' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_volunteer_thanks', '1')") === 1) {
    mk_sync_website_people();
    db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_from_name', 'Kakebe Technologies')");
    $meet = 'https://meet.google.com/xsk-swoi-nvf';
    $strip = base_url('assets/img/email/partners-strip.png');
    $body = '<p>Dear {first_name},</p>'
        . '<p>Thank you for applying to volunteer as a trainer at <strong>Kakebe Tech Camp 2026</strong> (14th – 23rd December, Kitgum). We received a very high number of applications, and we are truly grateful that you chose to offer your skills and time to young innovators from across Uganda.</p>'
        . '<h2>What happens next</h2>'
        . '<p>Our team is carefully reviewing every application. We will get back to you on the <strong>shortlisting by the end of this week — Friday 23rd October 2026</strong>.</p>'
        . '<h2>Join our online mentorship programme</h2>'
        . '<p>In the meantime, you are warmly invited to take part in the <strong>MentorMe360 Online Mentorship Programme</strong> — the journey towards the Tech Camp, sponsored by <strong>UNDP, the European Union, the Spotlight Initiative, BetterLife International Organisation and Oroma TV</strong>.</p>'
        . '<ul>'
        . '<li>📅 <strong>When:</strong> every Monday</li>'
        . '<li>⏰ <strong>Time:</strong> 8:00 – 9:00 PM (EAT)</li>'
        . '<li>💻 <strong>Where:</strong> Google Meet — <a href="' . $meet . '">meet.google.com/xsk-swoi-nvf</a></li>'
        . '</ul>'
        . '<p>It is a good opportunity to meet the team, get to know the programme, and connect with the young people taking part in the camp.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>Thank you once again for your interest and commitment.</p>'
        . '<p>Warm regards,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>';
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Volunteer trainers — thank you for applying',
        'Thank you for applying to volunteer, {first_name} — Kakebe Tech Camp 2026',
        'Shortlisting news by Friday 23 October · join our Monday mentorship sessions in the meantime',
        mk_clean_body($body), 'Join a Monday session', $meet, mk_list_id('Website · Volunteer trainers'), (string) mk_list_id('Website · Volunteer trainers'), $admin['id'], now(), now(),
    ]);
}

// One-off (16 Nov 2026): Session 5 invitation — "Beyond the Classroom: Tech, Confidence & Leadership" — to all registered
// participants and volunteer trainers (tonight, 8:00–9:30 PM EAT on Google Meet).
if (date('Y-m-d') <= '2026-11-17' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_session5', '1')") === 1) {
    mk_sync_website_people();
    db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_from_name', 'Kakebe Technologies')");
    $meet5    = 'https://meet.google.com/xsk-swoi-nvf';
    $flyer5   = base_url('assets/img/email/session5-flyer.webp');
    $strip    = base_url('assets/img/email/partners-strip.png');
    $body5 = '<img src="' . $flyer5 . '" alt="Kakebe Tech Camp · Session 5 — Beyond the Classroom: Tech, Confidence &amp; Leadership · Mon 16 Nov, 8:00–9:30 PM">'
        . '<p>Dear {first_name},</p>'
        . '<p>Tonight we bring you <strong>Session 5</strong> of the <strong>MentorMe360 Online Mentorship Programme</strong> — and this one is special.</p>'
        . '<h2>Beyond the Classroom: Tech, Confidence &amp; Leadership</h2>'
        . '<ul>'
        . '<li>📅 <strong>Date:</strong> Monday 16th November 2026 — <em>tonight</em></li>'
        . '<li>⏰ <strong>Time:</strong> 8:00 – 9:30 PM (EAT / Nairobi time)</li>'
        . '<li>💻 <strong>Platform:</strong> Google Meet — <a href="' . $meet5 . '">meet.google.com/xsk-swoi-nvf</a></li>'
        . '</ul>'
        . '<h3>Tonight\'s guests</h3>'
        . '<ul>'
        . '<li>🎙️ <strong>Shillah Ainembabazi</strong> — President, Uganda National Students Association (UNSA)</li>'
        . '<li>🎙️ <strong>Migel Brian Odong</strong> — President, Acholi Students Union</li>'
        . '<li>🎤 <strong>Session Host:</strong> Lokii Willy Sam</li>'
        . '</ul>'
        . '<p>We will talk about how <strong>technology, confidence and leadership</strong> go together — and what it means to lead your generation beyond the classroom.</p>'
        . '<p>The session runs <strong>tonight from 8:00 to 9:30 PM</strong>. Attendance is taken live, so please join on time.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation">'
        . '<p>See you tonight,<br><strong>Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    $everyone = mk_list_id('Website · Everyone registered');
    $vols5    = mk_list_id('Website · Volunteer trainers');
    $lists5   = implode(',', [$everyone, $vols5]);
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Session 5 — Beyond the Classroom (tonight, Mon 16 Nov)',
        '{first_name}, join Session 5 tonight — Beyond the Classroom | 8–9:30 PM',
        'Guests: Shillah Ainembabazi (UNSA) & Migel Brian Odong (Acholi Students Union) · Google Meet',
        mk_clean_body($body5), 'Join Session 5 on Google Meet', $meet5, $everyone, $lists5, $admin['id'], now(), now(),
    ]);
}

// One-off (23 Oct – 10 Nov 2026): camp fee reminder (full package UGX 140,000, or 50% to start) to Tech Camp participants
// who have not paid anything yet. Each person gets their own payment links and 10 days from the day their email is sent.
// Yesterday's version of the draft is replaced if nobody has edited or sent it.
if (date('Y-m-d') <= '2026-11-10' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_fee_reminder2', '1')") === 1) {
    mk_sync_website_people();
    db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_from_name', 'Kakebe Technologies')");
    $phone = e((string) setting('contact_phone', '0779 712 990'));
    $park = e(fees()['park_name']);
    $body = '<p>Dear {first_name},</p>'
        . '<p>Thank you for registering for <strong>Kakebe Tech Camp 2026</strong>, taking place from <strong>14th to 23rd December 2026 in Kitgum</strong>. We are truly glad that you chose to be part of this journey with young innovators from across Uganda.</p>'
        . '<p>As preparations for the camp move ahead, we would like to kindly remind you that <strong>places at the camp are confirmed on payment of the camp fee</strong>. According to our records, your payment has not yet been received.</p>'
        . '<h2>You have 10 days to pay</h2>'
        . '<p>We are giving every registered participant <strong>10 days from today — until {pay_deadline}</strong> — to make their payment and secure their place.</p>'
        . '<ul>'
        . '<li>🆔 <strong>Registration number:</strong> {reference}</li>'
        . '<li>💰 <strong>Full camp package:</strong> {amount_due} — training, accommodation and meals, your sports jersey and the ' . $park . ' excursion (the camp shirt is free)</li>'
        . '<li>✅ <strong>To secure your place:</strong> pay the full {amount_due}, or at least 50% ({half_amount}) now and the balance before the camp</li>'
        . '<li>📅 <strong>Pay by:</strong> {pay_deadline}</li>'
        . '</ul>'
        . '<h2>Pay with your personal link</h2>'
        . '<p>Choose one of the buttons below. Each one opens your own payment page — no login needed — where you can pay with <strong>MTN or Airtel Mobile Money</strong> or a <strong>Visa / Mastercard</strong> card.</p>'
        . '<p>{pay_buttons}</p>'
        . '<p>Your receipt is emailed to you as soon as your payment goes through, and your <strong>camp ticket</strong> is emailed once the full package is paid.</p>'
        . '<p>Please note that places are limited, so registrations that have not been paid for by <strong>{pay_deadline}</strong> may not be guaranteed a place at the camp.</p>'
        . '<p>If you have any questions or need help with your payment, simply reply to this email or call / WhatsApp us on <strong>' . $phone . '</strong>. If you have just made your payment, thank you — please ignore this reminder.</p>'
        . '<p>We look forward to welcoming you to Kitgum in December!</p>'
        . '<p>Warm regards,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>';
    $unpaid = mk_list_id('Website · Tech Camp — not paid yet');
    $fields = [
        'Camp fee — 10 days to pay (not paid yet)',
        '{first_name}, please complete your Kakebe Tech Camp 2026 payment within 10 days',
        'Full package {amount_due}, or 50% ({half_amount}) to start — pay by {pay_deadline} with your personal link',
        mk_clean_body($body), null, null, $unpaid, (string) $unpaid,
    ];
    $old = db()->query("SELECT c.id FROM mk_campaigns c WHERE c.name = 'Camp fee — 10 days to pay (not paid yet)' AND c.status = 'draft' AND c.updated_at = c.created_at
                        AND NOT EXISTS (SELECT 1 FROM mk_sends s WHERE s.campaign_id = c.id) ORDER BY c.id DESC LIMIT 1")->fetchColumn();
    if ($old) {
        q('UPDATE mk_campaigns SET name = ?, subject = ?, preheader = ?, body = ?, cta_label = ?, cta_url = ?, list_id = ?, list_ids = ? WHERE id = ?', array_merge($fields, [$old]));
    } else {
        q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($fields, [$admin['id'], now(), now()]));
    }
}

// ── Draft 1 of 2 for volunteers (Nov 2026): regret notice — not selected, we value you, more in 4 days
if (date('Y-m-d') <= '2026-12-05' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_vol_rejection_nov26', '1')") === 1) {
    mk_sync_website_people();
    $volList = mk_list_id('Website · Volunteer trainers');
    $strip   = base_url('assets/img/email/partners-strip.png');
    $body = '<p>Dear {first_name},</p>'
        . '<p>On behalf of the entire Kakebe Tech Camp 2026 team, we want to begin by saying — <strong>thank you</strong>. Thank you for taking the time, the energy, and the courage to apply as a Volunteer Trainer for <strong>Kakebe Tech Camp 2026</strong>.</p>'
        . '<p>We received an extraordinary number of applications this year, and reviewing every single one has been both inspiring and genuinely difficult. The talent, passion, and commitment we saw — including in your application — truly gave us hope for the future of Uganda\'s technology and innovation ecosystem.</p>'
        . '<h2>An update on your application</h2>'
        . '<p>After a thorough and careful review process, we regret to inform you that <strong>we are unable to offer you a place as a Volunteer Trainer for Kakebe Tech Camp 2026</strong> at this time.</p>'
        . '<p>This was one of the hardest decisions our team has had to make, and we want you to know clearly: <strong>this outcome is in no way a reflection of your worth, your skills, or your potential</strong>. The number of exceptional applicants this year far exceeded the positions available, and many people who clearly deserved a place could not be accommodated — including you.</p>'
        . '<h2>We see you. We value you.</h2>'
        . '<p>Your interest in Kakebe, your willingness to serve, and your commitment to young people does not go unnoticed. We are determined to find meaningful ways to keep you connected to our mission — because talented, passionate individuals like you are exactly who this movement needs.</p>'
        . '<blockquote><strong>What happens next:</strong> Within the next <strong>4 days</strong>, we will be reaching out with an exciting opportunity for you to still be a meaningful part of Kakebe Tech Camp 2026. Please watch your inbox — we look forward to sharing it with you.</blockquote>'
        . '<p>Thank you again for your trust in us and for everything you bring. This is not goodbye — it is the beginning of something we hope to build together.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>With warm regards,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Volunteers — application update (not selected, Nov 2026)',
        '{first_name}, an important update on your Kakebe Tech Camp 2026 volunteer application',
        'We value your interest deeply — and we have something exciting to share with you in the next 4 days',
        mk_clean_body($body), null, null, $volList, (string) $volList, $admin['id'], now(), now(),
    ]);
}

// ── Draft 2 of 2 for volunteers (Nov 2026): attend the camp at a special rate of UGX 80,000 as participant + peer trainer
if (date('Y-m-d') <= '2026-12-05' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_vol_attend_discount_nov26', '1')") === 1) {
    mk_sync_website_people();
    $volList = mk_list_id('Website · Volunteer trainers');
    $strip   = base_url('assets/img/email/partners-strip.png');
    $regUrl  = base_url('register');
    $body = '<p>Dear {first_name},</p>'
        . '<p>A few days ago, we shared an update on your Volunteer Trainer application for Kakebe Tech Camp 2026, and we promised to come back with something special. <strong>We are keeping that promise today.</strong></p>'
        . '<h2>An exclusive invitation — just for you</h2>'
        . '<p>We know how much you care about technology, education, and making a difference in the lives of young people. Your application showed that clearly. And while the volunteer trainer slots were limited, <strong>your Kakebe journey does not have to end here.</strong></p>'
        . '<h2>Attend Kakebe Tech Camp 2026 at a special rate</h2>'
        . '<p>As a sincere token of our appreciation for your time and your belief in what we are building, we are offering all our volunteer applicants the opportunity to <strong>attend the full Kakebe Tech Camp 2026 at an exclusive discounted rate of UGX 80,000</strong>.</p>'
        . '<ul>'
        . '<li>📅 <strong>Dates:</strong> 14th – 23rd December 2026 (10 days)</li>'
        . '<li>📍 <strong>Venue:</strong> Kitgum, Northern Uganda</li>'
        . '<li>💰 <strong>Your exclusive rate:</strong> UGX 80,000 (for volunteer applicants only)</li>'
        . '<li>🏕️ <strong>Includes:</strong> full programme, accommodation, meals, all training sessions and activities</li>'
        . '</ul>'
        . '<h2>Your role at the camp — more than just a participant</h2>'
        . '<p>Attending as a participant does not mean you sit on the sidelines. You will have the opportunity to <strong>serve as a Peer Trainer or Mentor</strong> during sessions in your area of expertise. Your knowledge and experience have real value — and the young innovators at the camp will benefit greatly from having someone like you in the room.</p>'
        . '<p>This is a chance to <strong>learn, lead, connect, and leave a lasting legacy</strong> — all in one extraordinary week in Kitgum.</p>'
        . '<blockquote>⚡ <strong>Important:</strong> This offer is <em>exclusively for our volunteer applicants</em> and places are strictly limited. We encourage you to register and secure your place as soon as possible.</blockquote>'
        . '<p>We truly hope to see you in Kitgum this December. This is your moment to be part of something extraordinary.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>Warm regards,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Volunteers — attend the camp at UGX 80,000 (exclusive offer, Nov 2026)',
        '{first_name}, you\'re still invited — attend Kakebe Tech Camp 2026 at a special rate',
        'Exclusive rate: UGX 80,000 for the full 10-day camp · serve as peer trainer in your field of expertise',
        mk_clean_body($body), 'Register at the discounted rate', $regUrl, $volList, (string) $volList, $admin['id'], now(), now(),
    ]);
}

// ── Canva Africa partnership announcement (Nov 2026): $15,000 partnership, every participant gets Canva Pro
if (date('Y-m-d') <= '2026-11-25' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_canva_partnership_nov26', '1')") === 1) {
    mk_sync_website_people();
    $everyone = mk_list_id('Website · Everyone registered');
    $strip    = base_url('assets/img/email/partners-strip.png');
    $body = '<p>Dear {first_name},</p>'
        . '<p>We have some incredible news to share with you today — news that makes your decision to be part of <strong>Kakebe Tech Camp 2026</strong> even more valuable and exciting.</p>'
        . '<h2>🎉 Kakebe Tech Camp partners with Canva Africa</h2>'
        . '<p>We are absolutely thrilled to announce that <strong>Kakebe Technologies has entered into a landmark partnership with Canva Africa</strong>, valued at over <strong>USD 15,000</strong>. This partnership has been built specifically to empower every young innovator who attends Kakebe Tech Camp 2026 — and that includes you.</p>'
        . '<h2>What this means for you</h2>'
        . '<p>As part of this powerful partnership, <strong>every confirmed camp participant will receive a full Canva Pro account</strong> — giving you professional-grade access to one of the world\'s most widely used design and branding platforms.</p>'
        . '<ul>'
        . '<li>🎨 <strong>Canva Pro access</strong> — premium templates, brand kits, background remover, AI design tools and much more</li>'
        . '<li>🖥️ <strong>Design, branding &amp; digital content creation</strong> — skills that every innovator, entrepreneur and leader needs today</li>'
        . '<li>📜 <strong>Recognition</strong> from both Kakebe Technologies and Canva Africa</li>'
        . '<li>🚀 <strong>A powerful head start</strong> in digital marketing, personal branding and content creation</li>'
        . '</ul>'
        . '<blockquote>⭐ <strong>Your place, your Canva Pro.</strong><br>This opportunity is <strong>exclusively for confirmed, attending camp participants</strong>. Your Canva Pro account will be set up ahead of the camp — but only for those who have confirmed their participation and are present at the camp in Kitgum.</blockquote>'
        . '<p>We are building something extraordinary this December, and you are right at the heart of it. Stay tuned — more exciting announcements are coming your way very soon.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>With excitement,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Canva Africa partnership — $15,000 · every participant gets Canva Pro (Nov 2026)',
        '🎉 {first_name}, big news — Kakebe Tech Camp partners with Canva Africa',
        'USD 15,000 partnership · every confirmed participant gets a full Canva Pro account',
        mk_clean_body($body), null, null, $everyone, (string) $everyone, $admin['id'], now(), now(),
    ]);
}

// ── Canva commitment + payment nudge (Nov 2026): pay at least UGX 50,000 to confirm spot and get Canva Pro from 1 Dec
if (date('Y-m-d') <= '2026-11-25' && db()->exec("INSERT IGNORE INTO settings (skey, svalue) VALUES ('mk_seed_canva_payment_nov26', '1')") === 1) {
    mk_sync_website_people();
    $unpaid = mk_list_id('Website · Tech Camp — not paid yet');
    $strip  = base_url('assets/img/email/partners-strip.png');
    $phone  = e((string) setting('contact_phone', '0779 712 990'));
    $body = '<p>Dear {first_name},</p>'
        . '<p>Earlier today, we shared exciting news about our new partnership with <strong>Canva Africa</strong> — and the <strong>Canva Pro account</strong> that every confirmed Kakebe Tech Camp 2026 participant will receive. If you missed it, check your inbox!</p>'
        . '<p>Now, here is exactly what you need to do to <strong>claim yours</strong>.</p>'
        . '<h2>Confirm your place. Unlock your Canva Pro.</h2>'
        . '<p>To finalise our partnership with Canva Africa and officially submit your details for account activation, we need to confirm who is attending the camp. <strong>Canva Pro access starts on 1st December 2026</strong> — giving you a full two weeks of premium access <em>before</em> the camp even begins in Kitgum.</p>'
        . '<p><strong>All you need to do is commit by paying a minimum of UGX 50,000 to confirm your place.</strong> You can clear the remaining balance of your camp fee at any point before the camp.</p>'
        . '<h2>Here is what happens when you pay</h2>'
        . '<ul>'
        . '<li>✅ Your place at Kakebe Tech Camp 2026 is <strong>confirmed and secured</strong></li>'
        . '<li>📧 Your email is <strong>submitted to Canva Africa</strong> for Canva Pro activation on <strong>1st December 2026</strong></li>'
        . '<li>🎨 You get <strong>full Canva Pro access from 1st December</strong> — two weeks before the camp starts</li>'
        . '<li>💰 Pay the remaining balance of your <strong>{amount_due}</strong> at any time before 14th December</li>'
        . '<li>🏕️ Arrive in Kitgum with your Canva Pro already active and ready to use</li>'
        . '</ul>'
        . '<h2>Pay now with your personal link</h2>'
        . '<p>Use the button below — your own secure payment page, no login needed. Pay with <strong>MTN or Airtel Mobile Money</strong>, or a <strong>Visa / Mastercard</strong>.</p>'
        . '<p>{pay_buttons}</p>'
        . '<blockquote>⚡ <strong>Act now.</strong> We will be submitting the final list to Canva Africa very soon. Only participants who have made a payment of at least UGX 50,000 will be included. Do not miss this opportunity.</blockquote>'
        . '<p>If you have already made your payment — <strong>thank you!</strong> Your Canva Pro is on its way. If you have any questions or need help with payment, reply to this email or call / WhatsApp us on <strong>' . $phone . '</strong>.</p>'
        . '<h3>With the support of</h3>'
        . '<img src="' . $strip . '" alt="Kakebe Technologies, UNDP, European Union, United Nations, Spotlight Initiative, BetterLife International Organisation and Oroma TV">'
        . '<p>Warm regards,<br><strong>The Kakebe Tech Camp 2026 Team</strong><br>Kakebe Technologies</p>'
        . '<p><em>Be informed. Get inspired. Be part of the movement!</em></p>';
    q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        'Canva Pro — pay UGX 50,000 to confirm your place and unlock access from 1 Dec (Nov 2026)',
        '{first_name}, pay UGX 50,000 now to confirm your place and unlock Canva Pro from 1st December',
        'Confirm your spot · get Canva Pro from 1 December · pay the balance before the camp',
        mk_clean_body($body), 'Pay now and secure your place', null, $unpaid, (string) $unpaid, $admin['id'], now(), now(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'new') {
        $name = trim((string) ($_POST['name'] ?? '')) ?: 'New campaign · ' . date('j M Y');
        q('INSERT INTO mk_campaigns (name, created_by, created_at, updated_at) VALUES (?, ?, ?, ?)', [mb_substr($name, 0, 150), $admin['id'], now(), now()]);
        redirect('marketing-campaign.php?id=' . (int) db()->lastInsertId());
    }

    if ($action === 'duplicate' && ($c = find_campaign((int) ($_POST['id'] ?? 0)))) {
        q('INSERT INTO mk_campaigns (name, subject, preheader, body, cta_label, cta_url, list_id, list_ids, contact_ids, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [mb_substr('Copy of ' . $c['name'], 0, 150), $c['subject'], $c['preheader'], $c['body'], $c['cta_label'], $c['cta_url'], $c['list_id'], $c['list_ids'], $c['contact_ids'], $admin['id'], now(), now()]);
        flash('Campaign copied — edit it and send it to a new audience.');
        redirect('marketing-campaign.php?id=' . (int) db()->lastInsertId());
    }

    if ($action === 'delete' && ($c = find_campaign((int) ($_POST['id'] ?? 0)))) {
        q('DELETE FROM mk_sends WHERE campaign_id = ?', [$c['id']]);
        q('DELETE FROM mk_campaigns WHERE id = ?', [$c['id']]);
        flash('Campaign "' . $c['name'] . '" deleted.');
        redirect('marketing.php');
    }

    if ($action === 'settings') {
        setting_set('mk_from_name', mb_substr(trim((string) ($_POST['mk_from_name'] ?? '')), 0, 80));
        $reply = strtolower(trim((string) ($_POST['mk_reply_to'] ?? '')));
        setting_set('mk_reply_to', filter_var($reply, FILTER_VALIDATE_EMAIL) ? $reply : '');
        setting_set('mk_daily_limit', (string) max(1, min(100000, (int) ($_POST['mk_daily_limit'] ?? 500))));
        setting_set('mk_batch_size', (string) max(50, min(2000, (int) ($_POST['mk_batch_size'] ?? 500))));
        flash('Sending settings saved.');
        redirect('marketing.php#settings');
    }
    redirect('marketing.php');
}

$cfg = mk_settings();
$contacts = mk_contact_stats();
$sent24 = mk_sent_last_24h();
$totals = q("SELECT COUNT(*) sent, COALESCE(SUM(opened_at IS NOT NULL), 0) opened FROM mk_sends WHERE status = 'sent'")->fetch();
$openRate = $totals['sent'] ? round($totals['opened'] / $totals['sent'] * 100, 1) : 0;
$campaigns = q("SELECT c.*, l.name list_name,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id) recipients,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.status = 'sent') sent,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.status = 'queued') queued,
        (SELECT COUNT(*) FROM mk_sends s WHERE s.campaign_id = c.id AND s.opened_at IS NOT NULL) opened
    FROM mk_campaigns c LEFT JOIN mk_lists l ON l.id = c.list_id ORDER BY c.id DESC")->fetchAll();
$transport = setting('mail_transport', 'log');
$bulkSmtp = env('MK_SMTP_HOST') !== '';
$smtp = mk_smtp_config();
$sentSeries = days_series("SELECT DATE(sent_at) d, COUNT(*) v FROM mk_sends WHERE status = 'sent' AND sent_at >= ? GROUP BY DATE(sent_at)", 14);
$openSeries = days_series("SELECT DATE(opened_at) d, COUNT(*) v FROM mk_sends WHERE opened_at >= ? GROUP BY DATE(opened_at)", 14);

admin_header('Email campaigns', 'campaigns', 'Compose rich emails and send them to your contacts in groups — everyone gets their own copy');
?>
<?php if ($transport === 'log'): ?>
<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i><span>Email sending is switched off (log only) — campaigns will be recorded but nothing is delivered. <a href="settings.php#email">Settings → Email</a></span></div>
<?php endif; ?>

<div class="kpis">
  <a class="kpi" href="marketing-contacts.php?status=valid"><span class="kpi-icon blue"><i class="fa-solid fa-address-book"></i></span><div><small>Ready to receive</small><b><?= number_format($contacts['valid']) ?></b><em><?= number_format($contacts['invalid']) ?> filtered out · <?= number_format($contacts['unsubscribed']) ?> unsubscribed</em></div></a>
  <div class="kpi"><span class="kpi-icon amber"><i class="fa-solid fa-gauge-high"></i></span><div><small>Sent in 24 hours</small><b><?= number_format($sent24) ?></b><em>of <?= number_format($cfg['daily_limit']) ?> allowed · <?= number_format(mk_daily_left()) ?> left</em></div></div>
  <div class="kpi"><span class="kpi-icon green"><i class="fa-solid fa-paper-plane"></i></span><div><small>Emails sent</small><b><?= number_format((int) $totals['sent']) ?></b><em>across <?= count($campaigns) ?> campaign<?= count($campaigns) === 1 ? '' : 's' ?></em></div></div>
  <div class="kpi"><span class="kpi-icon navy"><i class="fa-solid fa-check-double mk-blue"></i></span><div><small>Opened</small><b><?= number_format((int) $totals['opened']) ?></b><em><?= $openRate ?>% open rate</em></div></div>
</div>

<section class="card">
    <div class="card-head list-head">
      <h3><i class="fa-solid fa-bullhorn"></i> Campaigns</h3>
      <form method="post" class="mk-new"><?= csrf_field() ?><input type="hidden" name="action" value="new">
        <input type="text" name="name" placeholder="Campaign name, e.g. Mentorship intake — October" maxlength="150">
        <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-plus"></i> New campaign</button>
      </form>
    </div>
    <?php if ($campaigns): ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Campaign</th><th>Audience</th><th>Status</th><th>Sent</th><th>Opened</th><th>Created</th><th class="row-actions"></th></tr></thead>
        <tbody>
        <?php foreach ($campaigns as $c):
            $rate = $c['sent'] ? round($c['opened'] / $c['sent'] * 100) : 0;
            if ((int) $c['recipients'] === 0) { [$label, $cls] = ['Draft', 'st-waitlisted']; }
            elseif ((int) $c['queued'] === 0) { [$label, $cls] = ['Sent', 'st-confirmed']; }
            elseif ((int) $c['sent'] === 0) { [$label, $cls] = ['Ready to send', 'st-booked']; }
            else { [$label, $cls] = ['Partly sent', 'st-pending']; }
        ?>
          <tr class="row-link" data-href="marketing-campaign.php?id=<?= (int) $c['id'] ?>">
            <td><b><?= e($c['name']) ?></b><small class="block muted"><?= e($c['subject'] ?: 'No subject yet') ?></small></td>
            <td class="small"><?= e(mk_audience_label($c)) ?></td>
            <td><span class="badge <?= $cls ?>"><?= $label ?></span></td>
            <td class="nowrap"><b><?= number_format((int) $c['sent']) ?></b><?php if ($c['recipients']): ?> <span class="muted">/ <?= number_format((int) $c['recipients']) ?></span><?php endif; ?></td>
            <td class="nowrap"><?php if ($c['sent']): ?><i class="fa-solid fa-check-double mk-blue"></i> <b><?= number_format((int) $c['opened']) ?></b> <span class="muted"><?= $rate ?>%</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="nowrap muted"><?= e(date('j M Y', strtotime($c['created_at']))) ?></td>
            <td class="nowrap row-actions">
              <a class="btn btn-light btn-sm" href="marketing-campaign.php?id=<?= (int) $c['id'] ?>"><i class="fa-regular fa-pen-to-square"></i> Open</a>
              <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-light btn-sm" type="submit" title="Make a copy"><i class="fa-regular fa-copy"></i></button></form>
              <form method="post" class="inline-form" data-confirm="Delete this campaign and its statistics? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger btn-sm" type="submit" title="Delete"><i class="fa-regular fa-trash-can"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty-state"><i class="fa-regular fa-envelope-open"></i><p>No campaigns yet. <?= $contacts['valid'] ? 'Give your first one a name above.' : 'Start by importing your contacts.' ?></p><?php if (!$contacts['valid']): ?><a class="btn btn-primary" href="marketing-contacts.php"><i class="fa-solid fa-file-import"></i> Import contacts</a><?php endif; ?></div>
    <?php endif; ?>
</section>

<div class="grid-2 align-start">
  <section class="card" id="settings">
    <div class="card-head"><h3><i class="fa-solid fa-sliders"></i> Sending settings</h3></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <div class="row-2">
        <label>Sender name <input type="text" name="mk_from_name" value="<?= e(setting('mk_from_name')) ?>" placeholder="Kakebe Technologies" maxlength="80"></label>
        <label>Replies go to <input type="email" name="mk_reply_to" value="<?= e(setting('mk_reply_to')) ?>" placeholder="<?= e(setting('contact_email')) ?>"></label>
      </div>
      <div class="row-2">
        <label>Daily limit <small class="muted">emails per 24 hours</small><input type="number" name="mk_daily_limit" min="1" max="100000" value="<?= (int) $cfg['daily_limit'] ?>"></label>
        <label>Group size <small class="muted">emails per group (50–2000)</small><input type="number" name="mk_batch_size" min="50" max="2000" value="<?= (int) $cfg['batch_size'] ?>"></label>
      </div>
      <dl class="details one">
        <div><dt>Sent through</dt><dd class="small"><?= $bulkSmtp ? '<span class="ok-text"><i class="fa-solid fa-circle-check"></i> Bulk email service</span> · ' . e($smtp['host']) : 'The site email account' . ($smtp['user'] ? ' · ' . e($smtp['user']) : '') . ' <span class="muted">(Settings → Email)</span>' ?></dd></div>
        <div><dt>From address</dt><dd class="small"><?= e($cfg['from_name']) ?> &lt;<?= e($smtp['from_email'] ?: ($smtp['user'] ?: '—')) ?>&gt;</dd></div>
      </dl>
      <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save settings</button></div>
    </form>
  </section>

  <div class="stack-cards">
  <section class="card">
    <div class="card-head"><h3><i class="fa-solid fa-chart-column"></i> Last 14 days</h3></div>
    <p class="small muted mk-chart-label">Emails sent</p>
    <?= bar_chart($sentSeries, '', 'mk-sent') ?>
    <p class="small muted mk-chart-label">Opens <i class="fa-solid fa-check-double mk-blue"></i></p>
    <?= bar_chart($openSeries, '', 'mk-opens') ?>
  </section>
  <section class="card muted-card">
    <div class="card-head"><h3><i class="fa-solid fa-circle-info"></i> Good to know</h3></div>
    <ul class="mk-tips">
      <li><b>Gmail allows about 500 emails a day.</b> Keep the daily limit at 500 while sending from Gmail, or connect a bulk email service (Brevo, Mailgun, Amazon SES, Zoho ZeptoMail…) with the <code>MK_SMTP_*</code> lines in the <code>.env</code> file, then raise the limit.</li>
      <li><b>Everyone gets their own copy</b> — no shared To, CC or BCC, so no one sees other addresses.</li>
      <li><b>Every email has an Unsubscribe link</b> (also the one-click button in Gmail). People who unsubscribe are never emailed again.</li>
      <li><b>Blue ticks</b> <i class="fa-solid fa-check-double mk-blue"></i> show when an email is opened with images on. Some apps block images or open them automatically, so treat open numbers as a guide.</li>
      <li>Email people who know Kakebe or asked to hear from you. Cold lists get emails flagged as spam and can block the account.</li>
    </ul>
  </section>
  </div>
</div>
<?php admin_footer();
