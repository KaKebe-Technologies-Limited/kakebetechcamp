<?php
require __DIR__ . '/includes/bootstrap.php';

$regOpen  = setting('registration_open', '1') === '1';
$seatCap  = seat_capacity();
$seatsLeft = seats_left();
$regFull  = $seatsLeft <= 0;
$seatPct  = (int) round(($seatCap - $seatsLeft) / $seatCap * 100);
$almostFull = !$regFull && $seatsLeft <= max(10, (int) ceil($seatCap * 0.1));
$f        = fees();
$c        = camp();
$phone    = setting('contact_phone', '0779 712 990');
$email    = setting('contact_email');
$website  = setting('org_website');
$socials  = social_links();
$team     = team_members();
$base     = $f['camp'] + $f['jersey'];
$_SESSION['form_rendered_at'] = time();
$me       = current_participant();
$sponsorList = active_sponsors();

// Registration step 1: the applicant opens the link we emailed them → their email is confirmed for this browser.
$self = strtok($_SERVER['REQUEST_URI'], '?');
if (isset($_GET['restart'])) {
    unset($_SESSION['reg_email'], $_SESSION['google_profile']);
    redirect($self . '#register');
}
if (isset($_GET['continue'])) {
    redirect($self . '#regForm');
}
if (isset($_GET['verify'])) {
    $tok = (string) $_GET['verify'];
    $row = null;
    if (preg_match('/^[a-f0-9]{64}$/', $tok)) {
        $st = db()->prepare('SELECT * FROM email_verifications WHERE code_hash = ? AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $st->execute([hash('sha256', $tok), now()]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        $_SESSION['reg_notice'] = ['error', 'That registration link has expired or is not valid. Enter your email below to get a new one.'];
    } else {
        $dup = db()->prepare("SELECT COUNT(*) FROM registrations WHERE email = ? AND status <> 'cancelled'");
        $dup->execute([$row['email']]);
        if ((int) $dup->fetchColumn() > 0) {
            $_SESSION['reg_notice'] = ['error', 'This email is already registered. Use "My registration" at the top of the page to view it.'];
        } else {
            db()->prepare('UPDATE email_verifications SET verified_at = COALESCE(verified_at, ?) WHERE id = ?')->execute([now(), $row['id']]);
            $_SESSION['verified_emails'][$row['email']] = time();
            $_SESSION['reg_email'] = $row['email'];
            redirect($self . '#regForm'); // straight to the short form
        }
    }
    redirect($self . '#register');
}
$regEmail = (string) ($_SESSION['reg_email'] ?? '');
$regVerified = $regEmail !== '' && email_verified_in_session($regEmail);
$gProfile = $regVerified ? google_profile_for($regEmail) : null;
$regNotice = $_SESSION['reg_notice'] ?? null;
unset($_SESSION['reg_notice']);

$img = fn(string $name) => 'assets/img/' . $name;

$details = [
    'mentorship' => [
        'title'  => 'Mentorship Program',
        'badge'  => 'Before camp · open to all',
        'image'  => $img('mentors.jpg'),
        'icon'   => 'fa-people-arrows',
        'desc'   => 'The Mentorship Program is the foundation of the ecosystem. It connects young people with experienced industry professionals across business, technology, content creation, and personal development. It runs before the camp — online and in person in Lira, Gulu and Kitgum — and is open to all. Everyone who registers for Kakebe Tech Camp 2026 is enrolled automatically, free.',
        'facts'  => [
            ['fa-calendar-days', 'When', 'October – November 2026 · online every Monday, 8:00 – 9:30 PM (from 5th October)'],
            ['fa-location-dot', 'Where', 'Online, and in person in Lira, Gulu and Kitgum'],
            ['fa-users', 'Who', 'Open to all · free · Tech Camp participants are enrolled automatically'],
            ['fa-certificate', 'Certificate', 'For everyone with at least 75% attendance — plus an in-person closing session at selected locations'],
        ],
        'output' => 'Participants mentored in entrepreneurship, digital marketing, IT, AI, and personal branding — gaining skills, confidence, and a clear pathway to the next stage of the ecosystem.',
        'expect' => ['Weekly sessions led by great speakers and professionals', 'Practical skills development', 'Mentors in your field, virtually or in person, through the Digital Bridge Internship', 'A certificate and a supportive community of young people who share your ambition'],
        'cta'    => 'Register free for Mentorship & DBIP',
        'href'   => 'mentorship',
    ],
    'internship' => [
        'title'  => 'Digital Bridge Internship Program',
        'badge'  => 'Before camp · open to all',
        'image'  => $img('team-red.jpg'),
        'icon'   => 'fa-briefcase',
        'desc'   => 'The Digital Bridge Internship Program (DBIP) provides hands-on experience by connecting participants with mentors, companies, businesses, creators, development partners and entrepreneurs — virtually or in person — so they practise the skills they learn in the Mentorship Program. Interns work on real projects, build their portfolios, and expand their professional networks. It runs before the camp — online and in person in Lira, Gulu and Kitgum — and is open to all. Tech Camp participants join free.',
        'facts'  => [
            ['fa-calendar-days', 'When', 'October – November 2026'],
            ['fa-location-dot', 'Where', 'Online, and in person in Lira, Gulu and Kitgum'],
            ['fa-users', 'Who', 'Open to all · free · register together with the Mentorship Program'],
        ],
        'output' => 'Interns placed with companies, businesses, and creators, gaining practical experience in software development, business, content creation, and digital marketing.',
        'expect' => ['Virtual and physical work with real organisations', 'Mentorship from industry professionals', 'Networking opportunities', 'A pathway to employment or entrepreneurship'],
        'cta'    => 'Register free for Mentorship & DBIP',
        'href'   => 'mentorship',
    ],
    'trees' => [
        'title'  => 'Tree Planting Activity',
        'badge'  => '1 Day',
        'image'  => $img('team-lineup.jpg'),
        'icon'   => 'fa-seedling',
        'desc'   => 'A one-day environmental activity bringing together all teams to plant trees and promote environmental awareness.',
        'facts'  => [
            ['fa-calendar-days', 'When', 'One day during the program — date to be announced'],
            ['fa-users', 'Who', 'All ecosystem teams and participants'],
            ['fa-tree', 'Target', '1,000+ tree seedlings'],
        ],
        'output' => 'Over 1,000 tree seedlings planted, contributing to greener and healthier communities.',
        'expect' => ['A day of community service', 'Environmental education', 'Team bonding'],
    ],
    'techcamp' => [
        'title'  => 'Kakebe Tech Camp 2026',
        'badge'  => 'Flagship',
        'image'  => $img('robotics.jpg'),
        'icon'   => 'fa-campground',
        'desc'   => 'The Tech Camp is the flagship event of the ecosystem. It brings together young people from across Uganda for an intensive 10-day residential experience — where the ecosystem comes together to learn, build, innovate, and celebrate.',
        'facts'  => [
            ['fa-calendar-days', 'When', $c['dates'] . ' (10 days)'],
            ['fa-location-dot', 'Where', 'Kitgum, Northern Uganda — residential camp'],
            ['fa-users', 'Who', 'Young people aged 14 – 30 from across Uganda'],
        ],
        'output' => '300 young people aged 14 to 30 trained in AI and Software Development, Content Creation, Entrepreneurship, Video Gaming, Robotics and Digital Marketing. Participants build market-ready innovations and present them at Demo Day.',
        'expect' => ['Hands-on training and expert-led sessions', 'Hackathons and networking', 'Accommodation, meals, camp materials and a free camp shirt', 'Certificate of completion', 'Free Mentorship & Digital Bridge Internship (Oct – Nov)'],
    ],
];

$tracks = [
    ['fa-code', 'AI & Software Development', 'Write real code, build apps and explore how artificial intelligence solves everyday problems.'],
    ['fa-video', 'Content Creation & Production', 'Plan, shoot and edit compelling videos and stories — and grow an audience online.'],
    ['fa-lightbulb', 'Entrepreneurship & Innovation', 'Turn ideas into ventures: problem solving, business models and pitching.'],
    ['fa-gamepad', 'Video Gaming & Development', 'Discover game design and development and the growing world of gaming.'],
    ['fa-robot', 'Robotics & Automation', 'Assemble, wire and program robots and smart devices with your own hands.'],
    ['fa-bullhorn', 'Digital Marketing & Branding', 'Grow brands online with social media, storytelling and personal branding.'],
];

$gallery = [
    ['graduation', 'Tech Camp graduation celebration', 'g-wide g-tall'],
    ['robotics', 'Hands-on robotics session', ''],
    ['smiles', 'Guests at a Kakebe event', ''],
    ['crowd', 'Youth gathering in Northern Uganda', 'g-wide'],
    ['camp-group', 'Camp participants and mentors', ''],
    ['mentors', 'Our mentors and facilitators', ''],
    ['officials', 'Leaders and partners at a Kakebe event', 'g-wide'],
    ['team-lineup', 'The Kakebe team', ''],
    ['pageant', 'Guests at a Kakebe event', ''],
    ['team-red', 'Participants at the Kakebe office', 'g-wide'],
];

$faqs = [
    ['Who can apply?', 'Young people aged 14–30 who are passionate about technology, innovation, creativity, and entrepreneurship. We especially encourage girls, refugees, and persons with disabilities to apply.'],
    ['What does it cost to attend?', 'The camp package is ' . format_ugx($base) . ' — the camp fee (' . format_ugx($f['camp']) . ') covering training, accommodation and meals, plus the sports jersey every participant receives (' . format_ugx($f['jersey']) . '). The camp shirt is free and the ' . $f['park_name'] . ' excursion is optional (' . format_ugx($f['park']) . '). Pay the full package in one payment — right after registering or later from your dashboard — and your camp ticket is emailed as soon as it is paid.'],
    ['I have registered. How do I check my registration?', 'Click "My registration" at the top of the website and enter the email and phone number you registered with, or log in to the participant portal. There you can view your details, complete any payment (Mobile Money or card) and download receipts and your ticket.'],
    ['What do I get with my registration?', 'Ten days of residential training in Kitgum (accommodation and meals included), camp materials, a free camp shirt, your sports jersey, hackathons, Demo Day, a certificate — plus free enrolment in the Mentorship Program and Digital Bridge Internship (October – November 2026).'],
    ['Can I join the mentorship or internship without coming to camp?', 'Yes. The Mentorship Program and the Digital Bridge Internship run before the camp (October – November 2026), online and in person in Lira, Gulu and Kitgum, and they are open to all. Camp participants are enrolled automatically. If you are not coming to camp, register free at ' . base_url('mentorship') . ' — choose up to three internship fields and confirm your email. Sessions are online every Monday, 8:00 – 9:30 PM.'],
    ['When will I get my camp ticket?', 'Once your package is fully paid, your camp ticket (with your photo and a QR code) is available in your email and portal. Upload a clear photo when registering or from your portal.'],
    ['Can I sponsor a young person?', 'Yes! Use the "Sponsor an innovator" section to cover a participant\'s package (' . format_ugx($f['sponsor_child']) . ' per innovator) or give any amount. You receive a PDF receipt by email.'],
];

$contacts = [
    ['Komackech Moses Santos', 'Head of Communications', '0779 712 990'],
    ['Geovia Ayo (Jojo)', 'Public Relations', '0759 526 143'],
    ['Oscar Jerome Okello', 'Operations Lead', '+256 707 711 682'],
];

// ---- SEO: one canonical address, search snippet text and structured data (schema.org) ----
$siteUrl   = base_url('/');
$pageTitle = 'Kakebe Tech Camp 2026 – Youth Tech Camp in Kitgum, Uganda';
$pageDesc  = 'A 10-day residential tech camp in Kitgum, Northern Uganda (14–23 Dec 2026) for ages 14–30: AI, coding, robotics, gaming, content creation & more.';
$shareDesc = '10 days of AI, software, content creation, entrepreneurship, gaming & robotics in Kitgum. ' . $c['dates'] . '. Ages 14–30.';
$shareImg  = base_url('assets/img/techcamp-logo-pdf.jpg');
$private   = array_intersect_key($_GET, array_flip(['verify', 'continue', 'restart'])); // personal links never go in search results
$robots    = $private ? 'noindex, nofollow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
$orgId     = $siteUrl . '#organization';
$eventId   = $siteUrl . '#event';
$supportTel = substr(tel_link($phone), 4);

$jsonLd = ['@context' => 'https://schema.org', '@graph' => [
    array_filter([
        '@type' => 'Organization', '@id' => $orgId,
        'name' => 'Kakebe Technologies Limited', 'alternateName' => 'Kakebe Ecosystem',
        'url' => $website ?: $siteUrl,
        'logo' => ['@type' => 'ImageObject', 'url' => base_url('assets/img/icons/icon-512.png'), 'width' => 512, 'height' => 512],
        'email' => $email ?: null,
        'telephone' => $supportTel,
        'sameAs' => array_values(array_unique(array_filter(array_merge([$website], array_column($socials, 'url'))))) ?: null,
        'contactPoint' => array_filter(['@type' => 'ContactPoint', 'contactType' => 'customer support', 'telephone' => $supportTel, 'email' => $email ?: null, 'areaServed' => 'UG', 'availableLanguage' => ['English']]),
    ]),
    [
        '@type' => 'WebSite', '@id' => $siteUrl . '#website', 'url' => $siteUrl,
        'name' => 'Kakebe Tech Camp 2026', 'alternateName' => ['Kakebe Tech Camp', 'KTC 2026'],
        'inLanguage' => 'en', 'publisher' => ['@id' => $orgId],
    ],
    [
        '@type' => 'WebPage', '@id' => $siteUrl . '#webpage', 'url' => $siteUrl,
        'name' => $pageTitle, 'description' => $pageDesc, 'inLanguage' => 'en',
        'isPartOf' => ['@id' => $siteUrl . '#website'], 'about' => ['@id' => $eventId],
        'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $shareImg, 'width' => 1200, 'height' => 630],
    ],
    [
        '@type' => 'EducationEvent', '@id' => $eventId,
        'name' => 'Kakebe Tech Camp 2026',
        'description' => 'A 10-day residential tech camp in Kitgum for young people aged 14–30: AI & software development, content creation, entrepreneurship & innovation, video gaming, robotics & automation and digital marketing — with free mentorship and the Digital Bridge Internship (' . $c['mentorship'] . ').',
        'startDate' => $c['start_date'], 'endDate' => $c['end_date'],
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'location' => [
            '@type' => 'Place', 'name' => 'Kakebe Tech Camp, Kitgum',
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Kitgum', 'addressRegion' => 'Northern Region', 'addressCountry' => 'UG'],
        ],
        'image' => [$shareImg, base_url('assets/img/robotics.jpg'), base_url('assets/img/graduation.jpg')],
        'organizer' => ['@id' => $orgId],
        'performer' => ['@type' => 'PerformingGroup', 'name' => 'Kakebe mentors and facilitators'],
        'offers' => [
            '@type' => 'Offer', 'name' => 'Camp package (camp fee + sports jersey)',
            'price' => (string) $base, 'priceCurrency' => 'UGX',
            'url' => $siteUrl . '#register',
            'availability' => $regOpen && !$regFull ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        ],
        'maximumAttendeeCapacity' => $seatCap,
        'remainingAttendeeCapacity' => $seatsLeft,
        'typicalAgeRange' => '14-30',
        'audience' => ['@type' => 'PeopleAudience', 'suggestedMinAge' => 14, 'suggestedMaxAge' => 30],
        'educationalLevel' => 'Beginner',
        'teaches' => array_column($tracks, 1),
        'inLanguage' => 'en',
        'isAccessibleForFree' => false,
    ],
    [
        '@type' => 'FAQPage', '@id' => $siteUrl . '#faq',
        'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faqs),
    ],
]];

$navLeft = [['#home', 'Home'], ['#about', 'About'], ['#programs', 'Programs'], ['#techcamp', 'Tech Camp'], ['#sponsor', 'Sponsor']];
$navRight = [['#schedule', 'Schedule'], ['#team', 'Team'], ['#faq', 'FAQ'], ['#contact', 'Contact']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?= ga_tag() ?>
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($pageDesc) ?>">
  <meta name="robots" content="<?= $robots ?>">
  <link rel="canonical" href="<?= e($siteUrl) ?>">
  <meta name="author" content="Kakebe Technologies Limited">
  <meta name="theme-color" content="#E11D2A">
  <script>document.documentElement.classList.add('js')</script>

  <!-- Social sharing (WhatsApp, Facebook, LinkedIn, X) -->
  <meta property="og:type" content="website">
  <meta property="og:locale" content="en_GB">
  <meta property="og:site_name" content="Kakebe Tech Camp 2026">
  <meta property="og:title" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda">
  <meta property="og:description" content="<?= e($shareDesc) ?>">
  <meta property="og:url" content="<?= e($siteUrl) ?>">
  <meta property="og:image" content="<?= e($shareImg) ?>">
  <meta property="og:image:secure_url" content="<?= e($shareImg) ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda. Learn. Build. Innovate.">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda">
  <meta name="twitter:description" content="<?= e($shareDesc) ?>">
  <meta name="twitter:image" content="<?= e($shareImg) ?>">
  <meta name="twitter:image:alt" content="Kakebe Tech Camp 2026 logo">

  <!-- Icons -->
  <link rel="icon" href="favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/icons/favicon-32.png">
  <link rel="icon" type="image/png" sizes="96x96" href="assets/img/icons/favicon-96.png">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/img/icons/icon-192.png">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
  <link rel="manifest" href="site.webmanifest">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Caveat:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/site-v2.css?v=<?= filemtime(__DIR__ . '/assets/css/site-v2.css') ?>">
  <!-- Icon font loads without blocking the first paint -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer" crossorigin="anonymous" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer"></noscript>
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
</head>
<body>

<!-- ============ TOP BAR ============ -->
<div class="topbar">
  <div class="container topbar-inner">
    <ul class="topbar-info">
      <li><?= e($c['dates']) ?></li>
      <li>Kitgum, Northern Uganda</li>
      <li>Support: <a href="<?= e(tel_link($phone)) ?>"><?= e($phone) ?></a></li>
    </ul>
    <div class="topbar-right">
      <a href="pay.php"><i class="fa-solid fa-id-badge"></i> My registration</a>
      <a href="portal/<?= $me ? '' : 'login.php' ?>"><i class="fa-solid fa-user"></i> <?= $me ? 'My portal' : 'Portal login' ?></a>
      <?php if ($socials): ?>
      <span class="topbar-socials">
        <?php foreach ($socials as $s): ?><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($s['label']) ?>"><i class="fa-brands <?= e($s['icon']) ?>"></i></a><?php endforeach; ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ HEADER (logo centred, menu on both sides) ============ -->
<header class="header v2" id="header">
  <div class="container header-grid">
    <nav class="nav-side left" aria-label="Main">
      <ul><?php foreach ($navLeft as [$href, $label]): ?><li><a href="<?= $href ?>" data-nav><?= $label ?></a></li><?php endforeach; ?></ul>
    </nav>
    <a href="#home" class="brand-center" aria-label="Kakebe Tech Camp 2026 — home">
      <img src="assets/img/techcamp-logo-560.webp" srcset="assets/img/techcamp-logo-560.webp 560w, assets/img/techcamp-logo.webp 1774w" sizes="272px" alt="Kakebe Tech Camp 2026 logo" width="560" height="280" fetchpriority="high">
    </a>
    <div class="nav-side right">
      <ul><?php foreach ($navRight as [$href, $label]): ?><li><a href="<?= $href ?>" data-nav><?= $label ?></a></li><?php endforeach; ?></ul>
      <a href="#register" class="btn btn-primary btn-sm">Register</a>
    </div>
    <a href="pay.php" class="mob-pay" aria-label="My registration"><i class="fa-solid fa-id-badge"></i></a>
    <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu"><span></span><span></span><span></span></button>
  </div>
</header>
<nav class="mobile-menu" id="mobileMenu" aria-label="Mobile">
  <ul>
    <?php foreach (array_merge($navLeft, $navRight) as [$href, $label]): ?><li><a href="<?= $href ?>" data-nav><?= $label ?></a></li><?php endforeach; ?>
    <li><a href="mentorship"><i class="fa-solid fa-people-arrows"></i> Free mentorship &amp; DBIP</a></li>
    <li><a href="pay.php"><i class="fa-solid fa-id-badge"></i> My registration</a></li>
    <li><a href="portal/<?= $me ? '' : 'login.php' ?>"><i class="fa-solid fa-user"></i> Participant portal</a></li>
  </ul>
  <a href="#register" class="btn btn-primary btn-block">Register Now <i class="fa-solid fa-arrow-right"></i></a>
</nav>

<main>

<!-- ============ HERO ============ -->
<section class="hero v2" id="home">
  <div class="hero-deco" aria-hidden="true">
    <span class="deco-dots d1"></span><span class="deco-dots d2"></span>
  </div>
  <div class="container hero-grid">
    <div class="hero-content">
      <p class="script reveal">Let's gather in Northern Uganda</p>
      <h1 class="hero-title reveal"><span class="sr-only">Kakebe Tech Camp 2026, Kitgum: </span>Learn. Build. <span>Innovate.</span></h1>
      <p class="hero-lead reveal">A 10-day residential tech camp in <strong>Kitgum</strong> for young people aged <strong>14–30</strong> — AI &amp; software, content creation, entrepreneurship, gaming, robotics and digital marketing, plus <strong>free mentorship &amp; internships</strong> from October.</p>
      <div class="hero-cta reveal">
        <a href="#register" class="btn btn-primary btn-lg">Register Now <i class="fa-solid fa-arrow-right"></i></a>
        <a href="#sponsor" class="btn btn-ghost btn-lg">Sponsor an innovator</a>
      </div>
      <div class="countdown reveal" id="countdown" data-target="<?= e($c['start_iso']) ?>" aria-live="polite">
        <div class="cd-label">Camp starts in</div>
        <div class="cd-boxes">
          <div class="cd-box"><b data-cd="d">--</b><span>Days</span></div>
          <div class="cd-box"><b data-cd="h">--</b><span>Hours</span></div>
          <div class="cd-box"><b data-cd="m">--</b><span>Mins</span></div>
          <div class="cd-box"><b data-cd="s">--</b><span>Secs</span></div>
        </div>
      </div>
    </div>

    <div class="hero-visual reveal">
      <div class="hero-blob"></div>
      <div class="hero-ring"></div>
      <figure class="hero-photo main"><img src="assets/img/robotics.jpg" srcset="assets/img/robotics-sm.jpg 720w, assets/img/robotics.jpg 1600w" sizes="(max-width: 900px) 70vw, 420px" alt="Young people assembling a robot during a hands-on Kakebe Tech Camp robotics session" width="1600" height="1200" fetchpriority="high"></figure>
      <figure class="hero-photo small"><img src="assets/img/smiles-sm.jpg" alt="Smiling young women at a Kakebe event in Northern Uganda" width="720" height="480" decoding="async"></figure>
      <?php if ($regFull): ?><div class="fee-badge"><span>All</span><b><?= $seatCap ?><small>SEATS TAKEN</small></b><em>Registration full</em></div>
      <?php else: ?><div class="fee-badge"><span>Only</span><b class="js-seats-left"><?= $seatsLeft ?></b><small class="fb-sub">SEATS LEFT</small><em>of <?= $seatCap ?> · Kitgum</em></div><?php endif; ?>
      <div class="float-card fc-1"><div><b>10 Days</b><small>14 – 23 December</small></div></div>
      <div class="float-card fc-2"><div><b>Ages 14 – 30</b><small>300 young innovators</small></div></div>
    </div>
  </div>

  <div class="container">
    <div class="quickbar reveal">
      <div class="qb-item"><div><small>Dates</small><b><?= e($c['dates_short']) ?></b></div></div>
      <div class="qb-item"><div><small>Location</small><b>Kitgum · Residential</b></div></div>
      <div class="qb-item"><div><small>Who</small><b>Youth aged 14 – 30</b></div></div>
      <div class="qb-item"><div><small>Learning tracks</small><b>6 tracks · pick 2</b></div></div>
      <a href="#register" class="qb-btn">Register <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ============ PROGRAMS ============ -->
<section class="section programs" id="programs">
  <div class="container programs-grid">
    <div class="programs-intro reveal">
      <h2 class="title">One sign-up.<br>A whole <span class="hl">journey.</span></h2>
      <p>Kakebe Tech Camp in December is the main event. The journey starts earlier: from October to November the Mentorship Program and the Digital Bridge Internship run online and in person in Lira, Gulu and Kitgum — open to all, and included free when you register for the camp.</p>
      <ul class="mini-list">
        <li><span><b>The camp:</b> ten residential days in Kitgum, <?= e($c['dates_short']) ?></span></li>
        <li><span><b>Before camp:</b> mentorship &amp; internship, online and in Lira, Gulu and Kitgum</span></li>
        <li><span><b>Open to all</b> — camp participants are enrolled automatically</span></li>
      </ul>
      <a href="#register" class="btn btn-primary">Start Your Journey <i class="fa-solid fa-arrow-right"></i></a>
      <p class="programs-alt">Only want the mentorship or internship? <a href="mentorship">Register free for Mentorship &amp; DBIP</a> — online every Monday, 8:00 – 9:30 PM.</p>
    </div>
    <div class="eco-grid" role="list">
      <?php
      $eco = [
          ['techcamp', '01', 'Main event', $c['dates_short'], 'Ten residential days in Kitgum: learn, build, innovate and present at Demo Day.'],
          ['mentorship', '02', 'Before camp', 'Oct – Nov 2026 · open to all', 'Weekly guidance from industry professionals — online and in person in Lira, Gulu and Kitgum.'],
          ['internship', '03', 'Before camp', 'Oct – Nov 2026 · open to all', 'Real projects with companies, businesses and creators — online and in person.'],
          ['trees', '04', '1 Day', 'Date to be announced', 'All teams plant 1,000+ seedlings for greener, healthier communities.'],
      ];
      foreach ($eco as [$key, $num, $badge, $when, $blurb]): $d = $details[$key]; ?>
      <button type="button" class="eco-card reveal<?= $key === 'techcamp' ? ' featured' : '' ?>" data-program="<?= e($key) ?>" role="listitem" aria-label="View details: <?= e($d['title']) ?>">
        <span class="eco-top"><span class="eco-icon"><i class="fa-solid <?= e($d['icon']) ?>"></i></span><span class="eco-badge"><?= e($badge) ?></span></span>
        <small class="eco-when"><?= e($when) ?></small>
        <b class="eco-title"><?= e($d['title']) ?></b>
        <span class="eco-text"><?= e($blurb) ?></span>
        <span class="eco-more">View details <i class="fa-solid fa-arrow-right"></i></span>
        <span class="eco-num"><?= $num ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ ABOUT ============ -->
<section class="about-v2" id="about">
  <div class="about-bg" aria-hidden="true"></div>
  <div class="container">
    <div class="about-copy reveal">
      <h2>About Kakebe Tech Camp 2026</h2>
      <p>From <?= e($c['dates']) ?>, Kakebe Tech Camp brings 300 young people aged 14 to 30 from across Uganda to Kitgum for ten days of living, learning and building together. Each camper follows two of six learning tracks — AI and software development, content creation, entrepreneurship, video gaming, robotics and digital marketing — taught in small groups by people who practise these skills every day. Mornings are hands-on workshops; afternoons turn into studio time where ideas become prototypes, videos, games and business plans.</p>
      <p>As the days go on, campers form teams around real problems in their communities, test their solutions through hackathons and expert feedback, and close the camp by presenting their work at Demo Day before judges, partners and guests. Between sessions there is sport, networking and an optional visit to Aruu Falls. Every camper is also enrolled in the free Mentorship Program and Digital Bridge Internship, which run before the camp from October to November — online and in person in Lira, Gulu and Kitgum — so they arrive prepared and leave with skills, a certificate and a network that lasts well beyond December.</p>
      <a href="#register" class="btn btn-white">Register for the camp <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ============ TECH CAMP: TRACKS + PACKAGE ============ -->
<section class="section tracks" id="techcamp">
  <div class="container">
    <div class="section-head center reveal">
      <h2 class="title">Six tracks. Ten days. <span class="hl">Endless possibilities.</span></h2>
      <p>Pick up to two learning tracks. 300 young people from across Uganda learn, build market-ready innovations and present them at Demo Day.</p>
    </div>
    <div class="track-grid six">
      <?php foreach ($tracks as $i => [$icon, $title, $desc]): ?>
      <article class="track-card reveal">
        <span class="track-icon"><i class="fa-solid <?= $icon ?>"></i></span>
        <h3><?= e($title) ?></h3>
        <p><?= e($desc) ?></p>
        <span class="track-num">0<?= $i + 1 ?></span>
      </article>
      <?php endforeach; ?>
    </div>

    <div class="camp-grid">
      <div class="camp-content reveal">
        <h3 class="title-sm">Everything you need for 10 unforgettable days</h3>
        <ul class="include-grid">
          <li>Accommodation</li>
          <li>Meals</li>
          <li>Training &amp; classes</li>
          <li>Camp materials</li>
          <li>Free camp shirt</li>
          <li>Hackathons</li>
          <li>Expert-led sessions</li>
          <li>Networking</li>
          <li>Certificate of completion</li>
          <li>Free mentorship &amp; internship</li>
        </ul>
        <div class="camp-float-inline"><div><b>Demo Day</b><span>Present your innovation to judges, partners and guests.</span></div></div>
      </div>
      <div class="price-table reveal">
        <div class="pt-head"><b>At a glance</b><small>Kakebe Tech Camp 2026 in brief</small></div>
        <ul class="pt-rows glance">
          <li><span>Dates</span><b><?= e($c['dates_short']) ?></b></li>
          <li><span>Venue</span><b>Kitgum · Residential</b></li>
          <li><span>Who</span><b>Ages 14 – 30</b></li>
          <li><span>Learning tracks</span><b>6 · choose 2</b></li>
          <li><span>Mentorship &amp; internship</span><b>Oct – Nov · free</b></li>
          <li><span><?= e($f['park_name']) ?> excursion</span><b>Optional</b></li>
        </ul>
        <a href="#register" class="btn btn-primary btn-block">Register Now <i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </div>
  </div>
</section>

<!-- ============ STATS ============ -->
<section class="stats" aria-label="Program impact targets">
  <div class="container">
    <div class="stats-inner reveal">
      <div class="stats-head">
        <h2>Let's make Northern Uganda's tech dreams come true</h2>
      </div>
      <div class="stats-grid">
        <div class="stat"><b data-count="500" data-suffix="+">0</b><span>Youth &amp; community members</span></div>
        <div class="stat"><b data-count="300">0</b><span>Tech Camp participants</span></div>
        <div class="stat"><b data-count="100" data-suffix="+">0</b><span>Youth mentored</span></div>
        <div class="stat"><b data-count="100">0</b><span>Interns placed</span></div>
        <div class="stat"><b data-count="1000" data-suffix="+">0</b><span>Trees planted</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ============ SCHEDULE ============ -->
<section class="section schedule" id="schedule">
  <div class="container">
    <div class="section-head center reveal">
      <h2 class="title">Your roadmap from <span class="hl">October to December</span></h2>
      <p>Before the camp, the Mentorship Program and the Digital Bridge Internship run from October to November — online and in person in Lira, Gulu and Kitgum, open to all — leading up to the Tech Camp in Kitgum from <?= e($c['dates']) ?>.</p>
    </div>
    <div class="sessions">
      <div class="session-card online reveal">
        <span class="s-tag">Online · Open to all</span>
        <h3>Weekly mentorship sessions</h3>
        <div class="s-time"><b>Every Monday</b><span>8:00 – 9:30 PM</span></div>
        <p>From <b>5th October</b> to the end of November 2026, with experienced industry professionals. Open to all, and included free for every Tech Camp participant.</p>
        <a href="#register" class="btn btn-light btn-sm">Register to join <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <?php foreach ([['Lira', '2 weeks', 'in October and November'], ['Gulu', 'October – November', '· dates to be announced'], ['Kitgum', 'Last week', 'of October and November']] as [$city, $when, $rest]): ?>
      <div class="session-card city reveal">
        <span class="s-city"><?= $city ?></span>
        <h3>In-person sessions</h3>
        <p><b><?= $when ?></b> <?= $rest ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <ol class="timeline">
      <li class="tl-item reveal"><span class="tl-dot">1</span><div class="tl-card"><small>5th October 2026</small><h4>Mentorship kicks off</h4><p>Online sessions every Monday, 8:00 – 9:30 PM, plus in-person sessions in Lira, Gulu and Kitgum. Open to all. <a href="mentorship">Register free</a></p></div></li>
      <li class="tl-item reveal"><span class="tl-dot">2</span><div class="tl-card"><small>October – November 2026</small><h4>Digital Bridge Internship</h4><p>Participants work on real projects with companies, businesses and creators — online and in person in Lira, Gulu and Kitgum.</p></div></li>
      <li class="tl-item reveal"><span class="tl-dot">3</span><div class="tl-card"><small>One day · date to be announced</small><h4>Tree planting activity</h4><p>All teams come together to plant 1,000+ seedlings and promote environmental awareness.</p></div></li>
      <li class="tl-item reveal featured"><span class="tl-dot">4</span><div class="tl-card"><small><?= e($c['dates']) ?></small><h4>Kakebe Tech Camp · Kitgum</h4><p>10 days of hands-on training, hackathons, networking and expert sessions — residential.</p></div></li>
      <li class="tl-item reveal"><span class="tl-dot">5</span><div class="tl-card"><small>Camp finale</small><h4>Demo Day &amp; certificates</h4><p>Participants present their market-ready innovations and celebrate their achievements.</p></div></li>
    </ol>
  </div>
</section>

<!-- ============ CORE TEAM ============ -->
<section class="section team-sec" id="team">
  <div class="container">
    <div class="section-head center reveal">
      <h2 class="title">Meet the <span class="hl">core team</span></h2>
      <p>A passionate team from Kakebe Technologies making Kakebe Tech Camp 2026 happen.</p>
    </div>
    <div class="team-grid">
      <?php foreach ($team as $m): $ph = team_photo_url($m); ?>
      <article class="member reveal">
        <div class="member-photo">
          <?php if ($ph): ?><img src="<?= e($ph) ?>" alt="<?= e($m['name']) ?>" loading="lazy"><?php else: ?><span class="member-ini"><?= e(initials($m['name'])) ?></span><?php endif; ?>
        </div>
        <h3><?= e($m['name']) ?></h3>
        <p><?= e($m['role']) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ WHY JOIN ============ -->
<section class="section why">
  <div class="container why-grid">
    <div class="why-content reveal">
      <h2 class="title">Why you should be part of the <span class="hl">Kakebe Ecosystem</span></h2>
      <p>Whether you are a student, a creator or an aspiring entrepreneur, the ecosystem gives you the skills, confidence and network to build what matters for your community.</p>
      <div class="why-list">
        <div class="why-item"><div><b>Learn from experts</b><p>Weekly guidance and one-on-one mentorship.</p></div></div>
        <div class="why-item"><div><b>Real work experience</b><p>Build a portfolio with real organisations.</p></div></div>
        <div class="why-item"><div><b>Build real products</b><p>Create market-ready innovations at camp.</p></div></div>
        <div class="why-item"><div><b>A national network</b><p>Meet young innovators from across Uganda.</p></div></div>
        <div class="why-item"><div><b>Certificate</b><p>Recognition for completing the camp.</p></div></div>
        <div class="why-item"><div><b>Inclusive by design</b><p>Girls, refugees &amp; PWDs strongly encouraged.</p></div></div>
      </div>
    </div>
    <div class="why-media reveal">
      <div class="why-blob"></div>
      <figure class="why-circle"><img src="assets/img/mentors.jpg" alt="Three Kakebe team members smiling at a registration desk" loading="lazy" width="1600" height="1067"></figure>
      <div class="why-float"><b>14 – 30</b><span>years old? You're<br>who we're looking for.</span></div>
    </div>
  </div>
</section>

<!-- ============ GALLERY ============ -->
<section class="section gallery" id="gallery">
  <div class="container">
    <div class="section-head center reveal">
      <h2 class="title">Moments from the <span class="hl">Kakebe community</span></h2>
      <p>A glimpse of the energy, learning and celebration you can expect.</p>
    </div>
    <div class="gallery-grid">
      <?php foreach ($gallery as $i => [$file, $caption, $cls]): ?>
      <button type="button" class="g-item reveal <?= e($cls) ?>" data-index="<?= $i ?>" data-full="assets/img/<?= e($file) ?>.jpg" data-caption="<?= e($caption) ?>" aria-label="Open photo: <?= e($caption) ?>">
        <img src="assets/img/<?= e($file) ?><?= str_contains($cls, 'g-tall') ? '' : '-sm' ?>.jpg" alt="<?= e($caption) ?>" loading="lazy">
        <span class="g-cap"><?= e($caption) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ REGISTER ============ -->
<section class="section register" id="register">
  <div class="container register-grid">
    <aside class="register-info reveal">
      <h2>Secure your place at <span>Kakebe Tech Camp</span></h2>
      <p>It takes about a minute — and also enrols you free in the Mentorship Program and Digital Bridge Internship.</p>
      <ol class="steps">
        <li><span>1</span><div><b>Continue with Google</b><small>Or enter your email and open the link we send you.</small></div></li>
        <li><span>2</span><div><b>Add a few details</b><small>Age, phone, district and your tracks.</small></div></li>
        <li><span>3</span><div><b>Pay now or later</b><small>Pay in full right away, or later from your dashboard.</small></div></li>
        <li><span>4</span><div><b>Get your ticket</b><small>Emailed as soon as your package is paid.</small></div></li>
      </ol>
      <div class="summary-card perks">
        <h4>What you get</h4>
        <ul>
          <li><span>10 days of residential training in Kitgum</span></li>
          <li><span>Accommodation, meals &amp; camp materials</span></li>
          <li><span>Sports jersey &amp; a free camp shirt</span></li>
          <li><span>Free mentorship &amp; internship (Oct – Nov)</span></li>
          <li><span>Certificate &amp; Demo Day</span></li>
        </ul>
      </div>
      <div class="help-box">
        <i class="fa-brands fa-whatsapp"></i>
        <div><small>Need help registering?</small><a href="<?= e(whatsapp_link('Hello, I need help registering for Kakebe Tech Camp 2026.')) ?>" target="_blank" rel="noopener"><?= e($phone) ?></a></div>
      </div>
    </aside>

    <div class="register-card reveal">
      <?php if ($regOpen && !$regFull): ?>
      <div class="seats-meter<?= $almostFull ? ' urgent' : '' ?>">
        <div class="sm-top"><b><span class="js-seats-left"><?= number_format($seatsLeft) ?></span> of <?= number_format($seatCap) ?> seats left</b><small class="js-seats-note"><?= $almostFull ? 'Almost full — register now' : 'Strictly ' . $seatCap . ' participants' ?></small></div>
        <div class="sm-bar" role="meter" aria-label="Seats taken" aria-valuemin="0" aria-valuemax="<?= $seatCap ?>" aria-valuenow="<?= $seatCap - $seatsLeft ?>"><i class="js-seats-bar" style="width: <?= $seatPct ?>%"></i></div>
      </div>
      <?php endif; ?>
      <?php if (!$regOpen): ?>
        <div class="form-closed">
          <span><i class="fa-solid fa-lock"></i></span>
          <h3>Registration is currently closed</h3>
          <p>Already registered? <a href="pay.php">Pay or view your registration</a>. For help call <a href="<?= e(tel_link($phone)) ?>"><?= e($phone) ?></a>.</p>
        </div>
      <?php elseif ($regFull): ?>
        <div class="form-closed">
          <span><i class="fa-solid fa-users"></i></span>
          <h3>All <?= $seatCap ?> seats are taken</h3>
          <p>Kakebe Tech Camp 2026 is full — thank you for the amazing response! Already registered? <a href="pay.php">View your registration</a>. If a seat opens up we'll announce it; you can also message us on <a href="<?= e(whatsapp_link('Hello, is there any seat left at Kakebe Tech Camp 2026?')) ?>" target="_blank" rel="noopener">WhatsApp</a>.</p>
        </div>
      <?php elseif (!$regVerified): ?>
      <div class="email-gate" id="emailGate">
        <div class="form-head">
          <h3>Start your registration</h3>
          <ol class="mini-steps" aria-label="How to register"><li><b>1</b> <?= google_enabled() ? 'Continue with Google' : 'Confirm your email' ?></li><li><b>2</b> Add your details</li><li><b>3</b> Pay now or later</li></ol>
          <p><?= google_enabled() ? 'Tap <b>Continue with Google</b> — we\'ll bring you straight back here to add your age, phone, district and tracks. It takes about a minute.' : 'Enter your email and we\'ll send you a secure link — open it to add your age, phone, district and tracks.' ?></p>
        </div>
        <?php if ($regNotice): ?><div class="form-alert <?= $regNotice[0] === 'ok' ? 'ok' : '' ?>"><?= e($regNotice[1]) ?></div><?php endif; ?>
        <?php if (google_enabled()): ?>
        <?= google_button('register', 'api/google-auth.php', 'continue_with', true) ?>
        <div class="or-sep"><span>or use your email address</span></div>
        <?php endif; ?>
        <form id="gateForm" class="gate-email<?= google_enabled() ? ' secondary' : '' ?>" novalidate>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="field">
            <label for="g_email" class="sr-only">Your email address</label>
            <div class="gate-row">
              <input id="g_email" name="email" type="email" maxlength="190" autocomplete="email" placeholder="Your email address" required>
              <button type="submit" class="btn <?= google_enabled() ? 'btn-ghost' : 'btn-primary' ?>" id="gateBtn"><span class="btn-label">Continue</span><span class="btn-loading"><span class="spinner"></span></span></button>
            </div>
            <span class="err" data-err="email"></span>
          </div>
          <p class="gate-note">We'll email you a link to create your account.</p>
          <div class="form-alert" id="gateAlert" role="alert" hidden></div>
        </form>
        <div class="gate-sent" id="gateSent" hidden>
          <div class="success-icon"><i class="fa-regular fa-envelope"></i></div>
          <h3>Check your inbox</h3>
          <p>We've sent a link to create your account to <b id="gateEmailShow"></b>. Open the email and tap <b>Continue my registration</b> to add your details. The link works for 24 hours.</p>
          <p class="muted small">Can't find it? Check your spam or promotions folder, <button type="button" class="link-btn" id="gateResend">resend the link</button> or <button type="button" class="link-btn" id="gateChange">use a different email</button>.</p>
        </div>
        <p class="gate-foot muted small">Already registered? <a href="pay.php">View your registration</a></p>
      </div>
      <?php else: ?>
      <?php $gName = mb_strlen(trim((string) ($gProfile['name'] ?? ''))) >= 3 ? trim($gProfile['name']) : ''; ?>
      <form id="regForm" class="reg-form" novalidate data-method="<?= $gProfile ? 'google' : 'email' ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="email" value="<?= e($regEmail) ?>">
        <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="reg-who">
          <?php if (!empty($gProfile['picture'])): ?><img src="<?= e($gProfile['picture']) ?>" alt="" referrerpolicy="no-referrer" width="46" height="46"><?php else: ?><span class="who-ini"><?= e(initials($gName ?: $regEmail)) ?></span><?php endif; ?>
          <div><b><?= $gName ? 'Hi ' . e(explode(' ', $gName)[0]) . ', almost done!' : 'Almost done!' ?></b><small><?= e($regEmail) ?> <span class="verified-tag"><i class="fa-solid fa-circle-check"></i> <?= $gProfile ? 'Google' : 'Confirmed' ?></span></small></div>
          <a href="?restart=1#register" class="who-change">Change</a>
        </div>
        <?php if ($regNotice): ?><div class="form-alert <?= $regNotice[0] === 'ok' ? 'ok' : '' ?>"><?= e($regNotice[1]) ?></div><?php endif; ?>

        <div class="form-grid">
          <?php if ($gName): ?>
          <input type="hidden" name="full_name" value="<?= e($gName) ?>">
          <?php else: ?>
          <div class="field full">
            <label for="f_name">Full name</label>
            <input id="f_name" name="full_name" type="text" maxlength="150" autocomplete="name" placeholder="e.g. Akello Grace" required>
            <span class="err" data-err="full_name"></span>
          </div>
          <?php endif; ?>
          <div class="field">
            <label for="f_age">Age</label>
            <input id="f_age" name="age" type="number" inputmode="numeric" min="14" max="30" placeholder="14 – 30" required>
            <span class="err" data-err="age"></span>
          </div>
          <div class="field">
            <label for="f_phone">Phone number</label>
            <input id="f_phone" name="phone" type="tel" maxlength="40" autocomplete="tel" placeholder="e.g. 0772 123 456" required>
            <span class="err" data-err="phone"></span>
          </div>
          <div class="field">
            <label for="f_district">District</label>
            <input id="f_district" name="district" type="text" maxlength="100" autocomplete="off" placeholder="Type your district" required>
            <span class="err" data-err="district"></span>
          </div>
          <div class="field">
            <label for="f_jersey">Jersey size</label>
            <select id="f_jersey" name="jersey_size" required><option value="">Select…</option><?php foreach (jersey_sizes() as $s): ?><option><?= $s ?></option><?php endforeach; ?></select>
            <span class="err" data-err="jersey_size"></span>
          </div>
          <div class="field full">
            <label for="f_ref" class="lbl-row">Who recommended you? <small>optional</small></label>
            <input id="f_ref" name="referred_by" type="text" maxlength="150" autocomplete="off" placeholder="Name of the person who told you about the camp">
          </div>
        </div>

        <div class="field full">
          <label class="lbl-row">Your tracks <small id="trackHint">choose up to 2</small></label>
          <div class="track-pick" id="trackPick">
            <?php foreach ($tracks as [$icon, $title]): ?>
            <label class="tpk"><input type="checkbox" name="interests[]" value="<?= e($title) ?>"><span><i class="fa-solid <?= $icon ?>"></i><b><?= e($title) ?></b><em class="fa-solid fa-circle-check"></em></span></label>
            <?php endforeach; ?>
          </div>
          <span class="err" data-err="interests"></span>
        </div>

        <div class="field full">
          <label>Payment</label>
          <div class="funding-options three" id="payChoice">
            <label class="fund-opt"><input type="radio" name="pay_when" value="now" checked><span><b>Pay now</b><small>Mobile Money or card, right after this step</small></span></label>
            <label class="fund-opt"><input type="radio" name="pay_when" value="later"><span><b>Pay later</b><small>We email you the details — pay from your dashboard</small></span></label>
            <label class="fund-opt"><input type="radio" name="pay_when" value="sponsored"><span><b>I'm sponsored</b><small>Someone else is paying for me</small></span></label>
          </div>
        </div>

        <div class="sponsor-pick" id="sponsorPick" hidden>
          <div class="field full">
            <label for="f_sponsor">Who is sponsoring you?</label>
            <select id="f_sponsor" name="sponsor_id">
              <option value="">Select your sponsor…</option>
              <?php foreach ($sponsorList as $sp): ?><option value="<?= (int) $sp['id'] ?>"><?= e(sponsor_label($sp)) ?></option><?php endforeach; ?>
              <option value="other">My sponsor is not listed</option>
            </select>
            <span class="err" data-err="sponsor_id"></span>
          </div>
          <div class="field full" id="sponsorOtherWrap" hidden>
            <label for="f_sponsor_other">Sponsor's name</label>
            <input id="f_sponsor_other" name="sponsor_other" type="text" maxlength="150" placeholder="Full name of the person or organisation">
            <span class="err" data-err="sponsor_other"></span>
          </div>
          <p class="hint sponsor-note">We confirm your sponsor first. Once approved, your ticket is emailed to you — nothing to pay.</p>
        </div>

        <div class="pkg-line" id="orderSummary" data-camp="<?= $f['camp'] ?>" data-jersey="<?= $f['jersey'] ?>" data-park="<?= $f['park'] ?>">
          <div class="pkg-sum"><span><b>Camp package</b><small>Camp fee, meals &amp; stay + jersey · shirt free</small></span><strong class="js-total"><?= e(format_ugx($base)) ?></strong></div>
          <label class="park-check"><input type="checkbox" name="park_visit" value="1" id="f_park"> Add the <?= e($f['park_name']) ?> excursion <em>+<?= e(format_ugx($f['park'])) ?></em></label>
          <p class="pkg-note" id="pkgNote">Paid in full in one payment — your camp ticket is emailed as soon as it's paid.</p>
        </div>

        <div class="form-alert" id="formAlert" role="alert" hidden></div>
        <button type="submit" class="btn btn-primary btn-lg btn-block" id="regSubmit">
          <span class="btn-label"><span id="regSubmitText">Register &amp; pay <span class="js-total"><?= e(format_ugx($base)) ?></span></span> <i class="fa-solid fa-arrow-right"></i></span>
          <span class="btn-loading"><span class="spinner"></span> Saving…</span>
        </button>
        <p class="fine-print">By registering you confirm your details are correct and agree to be contacted about the camp. You're also enrolled free in the Mentorship Program &amp; Digital Bridge Internship (<?= e($c['mentorship']) ?>).</p>
      </form>

      <div class="form-success" id="regSuccess" hidden>
        <div class="success-icon"><i class="fa-solid fa-check"></i></div>
        <h3>You're registered, <span id="successName"></span>! 🎉</h3>
        <div class="ref-box"><small>Your reference number</small><b id="successRef">—</b></div>
        <?php if ($waGroup = whatsapp_group_link()): ?>
        <a class="wa-group" id="waGroup" href="<?= e($waGroup) ?>" target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp"></i>
          <span><b>Join the Tech Camp WhatsApp group</b><small>Updates, announcements and your fellow innovators — tap to join</small></span>
          <i class="fa-solid fa-arrow-right"></i>
        </a>
        <?php endif; ?>
        <p id="laterNote">We've emailed your registration and payment details to <b class="js-success-email"></b>. Pay <b id="successTotal"></b> any time before camp — your ticket is sent as soon as it's paid.</p>
        <div class="review-note" id="reviewNote" hidden>
          <b>Your sponsorship is being reviewed</b>
          <p>You told us <span id="successSponsor">your sponsor</span> is covering your camp fees. We'll confirm this and email you once your place is approved — nothing to pay in the meantime.</p>
        </div>
        <div class="flyer-ask" id="flyerAsk">
          <b>📸 Would you like an “I will be there” flyer?</b>
          <p>Upload your best photo and we'll design a flyer with your name — ready to share on WhatsApp and social media.</p>
          <div class="flyer-ask-actions"><a class="btn btn-primary btn-sm" href="flyer.php">Yes, make my flyer</a><button type="button" class="btn btn-ghost btn-sm" id="flyerNo">No thanks</button></div>
        </div>
        <div class="success-actions">
          <a class="btn btn-primary" id="payNowBtn" href="#">Pay now</a>
          <a class="btn btn-ghost" href="portal/">Open my dashboard</a>
          <a class="btn btn-ghost" id="shareWa" href="#" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Invite friends</a>
        </div>
        <p class="portal-note">Come back any time: <b>Portal login</b> at the top of this site, with Google or your email.</p>
        <p class="portal-note"><a id="regAnother" href="?restart=1#register">Register someone else</a></p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============ SPONSOR AN INNOVATOR ============ -->
<?php $wall = donor_wall(10); ?>
<section class="section sponsor" id="sponsor">
  <div class="container sponsor-grid">
    <div class="sponsor-info reveal">
      <h2 class="title">Sponsor an innovator. <span class="hl">Change a future.</span></h2>
      <p class="lead">Across Uganda there are creative, determined young people with big ideas — and no way to pay for a place at camp. Your gift puts one of them in the room: learning, building, connnecting and going home with the skills and confidence to become a change maker in their community in tech, business, creative industry and impact.</p>
      <div class="sponsor-price">
        <b><?= e(format_ugx($f['sponsor_child'])) ?> <small>about $<?= approx_usd($f['sponsor_child']) ?></small></b>
        <span>sponsors one innovator's full camp package</span>
      </div>
      <ul class="sponsor-impact">
        <li><div><b>Camp fees</b><p>Training, accommodation and meals for the whole camp in Kitgum.</p></div></li>
        <li><div><b>Park experience</b><p>The <?= e($f['park_name']) ?> excursion with fellow campers.</p></div></li>
        <li><div><b>Sports attire</b><p>Camp sportswear for games and team activities.</p></div></li>
        <li><div><b>Skills and mentorship</b><p>Hands-on learning in AI, software, content creation, entrepreneurship, gaming and robotics — then two months of mentorship and internship.</p></div></li>
      </ul>
      <figure class="sponsor-photo"><img src="assets/img/camp-visit.jpg" alt="Kakebe Tech Camp participants in red camp shirts during a camp visit" loading="lazy" decoding="async" width="1000" height="563"></figure>

      <div class="donor-wall">
        <div class="dw-head">
          <h3>Thank you to our donors</h3>
          <?php if ($wall['count']): ?><span><?= $wall['count'] ?> donor<?= $wall['count'] === 1 ? '' : 's' ?> · <?= e(format_ugx($wall['total'])) ?> given</span><?php endif; ?>
        </div>
        <?php if ($wall['donors']): ?>
        <ol class="dw-list">
          <?php foreach ($wall['donors'] as $i => $d): ?>
          <li>
            <span class="dw-rank"><?= $i + 1 ?></span>
            <span class="dw-name"><b><?= e($d['name']) ?></b><?php if ($d['organization']): ?><small><?= e($d['organization']) ?></small><?php endif; ?></span>
            <span class="dw-amt"><?= e(format_ugx($d['amount'])) ?></span>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($wall['count'] > count($wall['donors'])): ?><p class="dw-more">and <?= $wall['count'] - count($wall['donors']) ?> more generous donor<?= $wall['count'] - count($wall['donors']) === 1 ? '' : 's' ?></p><?php endif; ?>
        <?php else: ?>
        <p class="dw-empty">Be the first to sponsor an innovator — your name will appear here.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="sponsor-card reveal">
      <form class="js-pay-form js-sponsor" action="api/donate.php" data-status="api/payment-status.php" data-kind="donation" data-per-child="<?= $f['sponsor_child'] ?>" data-usd-rate="<?= max(1, (int) setting('usd_rate', 3750)) ?>" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="pay-body">
          <h3>Sponsor an innovator</h3>
          <p class="muted">Give from anywhere in the world — sponsor one or more innovators, or give any amount.</p>
          <div class="form-grid">
            <div class="field full">
              <label>How would you like to give?</label>
              <div class="funding-options give-mode">
                <label class="fund-opt"><input type="radio" name="give_mode" value="sponsor" checked><span><b>Sponsor innovators</b><small><?= e(format_ugx($f['sponsor_child'])) ?> (about $<?= approx_usd($f['sponsor_child']) ?>) each</small></span></label>
                <label class="fund-opt"><input type="radio" name="give_mode" value="custom"><span><b>Give any amount</b><small>You choose how much to give</small></span></label>
              </div>
            </div>
            <div class="field full js-sponsor-count">
              <label for="s_children">Number of innovators</label>
              <select id="s_children" name="children">
                <?php foreach ([1, 2, 3, 4, 5, 10, 20] as $k): ?><option value="<?= $k ?>"><?= $k ?> innovator<?= $k > 1 ? 's' : '' ?> — <?= e(format_ugx($k * $f['sponsor_child'])) ?> (about $<?= number_format(approx_usd($k * $f['sponsor_child'])) ?>)</option><?php endforeach; ?>
              </select>
            </div>
            <div class="field full js-custom-amount" hidden>
              <label for="s_amount">Amount you'd like to give (UGX)</label>
              <input id="s_amount" name="amount" type="text" inputmode="numeric" autocomplete="off" placeholder="e.g. 50,000" value="" data-min="1000" data-max="50000000">
              <div class="amount-chips"><?php foreach ([20000, 50000, 100000, 250000, 500000] as $quick): ?><button type="button" class="amount-chip" data-amount="<?= $quick ?>"><?= number_format($quick) ?></button><?php endforeach; ?></div>
              <span class="hint">Any amount from UGX 1,000 · about <span class="js-usd-inline">$0</span></span>
              <span class="err" data-err="amount"></span>
            </div>
            <div class="field"><label for="s_name">Your name <span class="req">*</span></label><input id="s_name" name="donor_name" type="text" maxlength="150" autocomplete="name" required><span class="err" data-err="donor_name"></span></div>
            <div class="field"><label for="s_org">Organisation <span class="opt">(optional)</span></label><input id="s_org" name="organization" type="text" maxlength="150" autocomplete="organization"></div>
            <div class="field"><label for="s_email">Email <span class="req">*</span></label><input id="s_email" name="donor_email" type="email" maxlength="190" autocomplete="email" required><span class="err" data-err="donor_email"></span></div>
            <div class="field"><label for="s_phone">Phone <span class="opt">(optional)</span></label><input id="s_phone" name="donor_phone" type="tel" maxlength="40" autocomplete="tel"><span class="err" data-err="donor_phone"></span></div>
            <div class="field full"><label for="s_msg">Message <span class="opt">(optional)</span></label><textarea id="s_msg" name="message" rows="2" maxlength="1000" placeholder="A note of encouragement for the campers…"></textarea></div>
          </div>
          <label class="check"><input type="checkbox" name="anonymous" value="1"><span>Keep my gift anonymous — don't show my name on the donors list</span></label>
          <label class="lbl">Payment method</label>
          <div class="methods">
            <label class="method"><input type="radio" name="method" value="mobile_money" checked><span><i class="fa-solid fa-mobile-screen-button"></i><b>Mobile Money</b><small>MTN · Airtel (Uganda)</small></span></label>
            <label class="method"><input type="radio" name="method" value="card"><span><i class="fa-regular fa-credit-card"></i><b>Card</b><small>Visa · Mastercard</small></span></label>
          </div>
          <p class="hint sponsor-abroad">Giving from outside Uganda? Choose <b>Card</b>.</p>
          <div class="field js-mm"><label for="s_payphone">Mobile Money number</label><input id="s_payphone" name="pay_phone" type="tel" value="" placeholder="e.g. 0772 123 456"><span class="err" data-err="pay_phone"></span></div>
          <p class="hint js-card" hidden><i class="fa-solid fa-lock"></i> <?= e(card_page_hint()) ?></p>
          <div class="form-alert" hidden></div>
          <button class="btn btn-primary btn-block js-pay-btn" type="submit"><span class="btn-label">Give <span class="js-amt"><?= e(format_ugx($f['sponsor_child'])) ?></span></span><span class="btn-loading"><span class="spinner"></span> Starting payment…</span></button>
          <p class="secure">About <span class="js-usd">$<?= approx_usd($f['sponsor_child']) ?></span> · your receipt is emailed straight away</p>
        </div>
        <div class="pay-state" hidden></div>
      </form>
    </div>
  </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section faq" id="faq">
  <div class="container faq-grid">
    <div class="faq-intro reveal">
      <h2 class="title">Questions? We've got <span class="hl">answers</span></h2>
      <p>Everything you need to know about Kakebe Tech Camp 2026. Can't find your answer? Talk to our support line.</p>
      <div class="faq-cta">
        <a href="<?= e(tel_link($phone)) ?>" class="btn btn-navy"><i class="fa-solid fa-phone"></i> <?= e($phone) ?></a>
        <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener" class="btn btn-ghost"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
      </div>
      <div class="facts-card">
        <h4>Quick facts</h4>
        <ul>
          <li><span>Dates</span><b><?= e($c['dates']) ?></b></li>
          <li><span>Venue</span><b>Kitgum · Residential</b></li>
          <li><span>Ages</span><b>14 – 30</b></li>
          <li><span>Duration</span><b>10 days</b></li>
          <li><span>Learning tracks</span><b>6 · choose 2</b></li>
          <li><span>Mentorship &amp; internship</span><b>Free · Oct – Nov</b></li>
        </ul>
      </div>
    </div>
    <div class="accordion reveal" id="faqList">
      <?php foreach ($faqs as $i => [$q, $a]): ?>
      <div class="acc-item<?= $i === 0 ? ' open' : '' ?>">
        <h3><button type="button" class="acc-btn" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>" aria-controls="faq-<?= $i ?>" id="faq-btn-<?= $i ?>"><span><?= e($q) ?></span><i class="fa-solid fa-plus"></i></button></h3>
        <div class="acc-panel" id="faq-<?= $i ?>" role="region" aria-labelledby="faq-btn-<?= $i ?>"><div><p><?= e($a) ?></p></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ CONTACT ============ -->
<section class="section contact" id="contact">
  <div class="container">
    <div class="section-head center reveal">
      <h2 class="title">Talk to the <span class="hl">Kakebe team</span></h2>
      <p>Reach out for registration support, payments, sponsorships, partnerships or media enquiries.</p>
    </div>
    <div class="contact-grid">
      <div class="team-list">
        <?php foreach ($contacts as [$name, $role, $tel]):
            $waNum = preg_replace('/\D/', '', $tel);
            if (str_starts_with($waNum, '0')) { $waNum = '256' . substr($waNum, 1); } ?>
        <article class="team-card reveal">
          <span class="avatar"><?= e(initials($name)) ?></span>
          <div class="team-info"><h3><?= e($name) ?></h3><small><?= e($role) ?></small><a href="<?= e(tel_link($tel)) ?>"><?= e($tel) ?></a></div>
          <div class="team-actions">
            <a href="<?= e(tel_link($tel)) ?>" aria-label="Call <?= e($name) ?>"><i class="fa-solid fa-phone"></i></a>
            <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener" aria-label="WhatsApp <?= e($name) ?>" class="wa"><i class="fa-brands fa-whatsapp"></i></a>
          </div>
        </article>
        <?php endforeach; ?>
        <div class="location-card reveal">
          <div><b>Kakebe Tech Camp 2026</b><span>Kitgum District, Northern Uganda · <?= e($c['dates']) ?></span></div>
        </div>
      </div>
      <form class="contact-form reveal" id="contactForm" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="hp" aria-hidden="true"><label>Company <input type="text" name="company" tabindex="-1" autocomplete="off"></label></div>
        <h3>Send us a message</h3>
        <p>We usually respond within one working day.</p>
        <div class="form-grid">
          <div class="field"><label for="c_name">Your name <span class="req">*</span></label><input id="c_name" name="name" type="text" maxlength="120" required><span class="err" data-err="name"></span></div>
          <div class="field"><label for="c_phone">Phone</label><input id="c_phone" name="phone" type="tel" maxlength="40"><span class="err" data-err="phone"></span></div>
          <div class="field full"><label for="c_email">Email <span class="req">*</span></label><input id="c_email" name="email" type="email" maxlength="190" required><span class="err" data-err="email"></span></div>
          <div class="field full"><label for="c_msg">Message <span class="req">*</span></label><textarea id="c_msg" name="message" rows="4" maxlength="3000" required></textarea><span class="err" data-err="message"></span></div>
        </div>
        <div class="form-alert" id="contactAlert" role="alert" hidden></div>
        <button type="submit" class="btn btn-navy btn-block" id="contactSubmit">
          <span class="btn-label">Send Message <i class="fa-solid fa-paper-plane"></i></span>
          <span class="btn-loading"><span class="spinner"></span> Sending…</span>
        </button>
      </form>
    </div>
  </div>
</section>

<!-- ============ CTA ============ -->
<section class="cta-wrap">
  <div class="container">
    <div class="cta-banner reveal">
      <div class="cta-text">
        <p class="script light"><?= $regFull ? 'All ' . $seatCap . ' seats are taken' : 'Only <span class="js-seats-left">' . $seatsLeft . '</span> of ' . $seatCap . ' seats left' ?></p>
        <h2>Ready to learn, build &amp; innovate this December?</h2>
        <p>Join young innovators from across Uganda in Kitgum, <?= e($c['dates']) ?>.</p>
        <div class="cta-actions">
          <a href="#register" class="btn btn-white btn-lg">Register Now <i class="fa-solid fa-arrow-right"></i></a>
          <a href="<?= e(tel_link($phone)) ?>" class="cta-phone"><i class="fa-solid fa-phone-volume"></i> <?= e($phone) ?></a>
        </div>
      </div>
      <div class="cta-media"><img src="assets/img/building-group-sm.jpg" alt="" loading="lazy" decoding="async" width="720" height="405"></div>
    </div>
  </div>
</section>

</main>

<footer class="footer">
  <div class="container footer-grid">
    <div class="f-brand">
      <img src="assets/img/techcamp-logo-560.webp" alt="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda" class="f-logo" width="560" height="280" loading="lazy" decoding="async">
      <p>The Kakebe Ecosystem builds innovators, problem solvers and nation builders from Northern Uganda through mentorship, internships and the national Kakebe Tech Camp.</p>
      <?php if ($socials): ?><div class="f-socials"><?php foreach ($socials as $s): ?><a href="<?= e($s['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($s['label']) ?>"><i class="fa-brands <?= e($s['icon']) ?>"></i></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div>
      <h4>Explore</h4>
      <ul>
        <li><a href="#about">About the ecosystem</a></li>
        <li><a href="#techcamp">Tech Camp 2026</a></li>
        <li><a href="#schedule">Schedule</a></li>
        <li><a href="#team">Core team</a></li>
        <li><a href="#sponsor">Sponsor an innovator</a></li>
      </ul>
    </div>
    <div>
      <h4>Participants</h4>
      <ul>
        <li><a href="#register">Register</a></li>
        <li><a href="pay.php">My registration</a></li>
        <li><a href="portal/login.php">Participant portal</a></li>
        <li><a href="#faq">FAQ</a></li>
      </ul>
    </div>
    <div>
      <h4>Get in touch</h4>
      <ul class="f-contact">
        <li><i class="fa-solid fa-headset"></i> <a href="<?= e(tel_link($phone)) ?>">Support: <?= e($phone) ?></a></li>
        <li><i class="fa-brands fa-whatsapp"></i> <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener">WhatsApp us</a></li>
        <?php if ($email): ?><li><i class="fa-regular fa-envelope"></i> <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></li><?php endif; ?>
        <li><i class="fa-solid fa-location-dot"></i> Kitgum, Northern Uganda</li>
        <?php if ($website): ?><li><i class="fa-solid fa-globe"></i> <a href="<?= e($website) ?>" target="_blank" rel="noopener"><?= e(preg_replace('~^https?://(www\.)?~', '', $website)) ?></a></li><?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="container">
      <p>© <?= date('Y') ?> Kakebe Technologies Limited. All rights reserved.</p>
      <p>Learn. Build. Innovate.</p>
    </div>
  </div>
</footer>

<a href="<?= e(whatsapp_link('Hello Kakebe Tech Camp team!')) ?>" class="wa-float" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
<button class="to-top" id="toTop" aria-label="Back to top"><i class="fa-solid fa-arrow-up"></i></button>

<div class="modal" id="programModal" hidden>
  <div class="modal-backdrop" data-close></div>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pmTitle">
    <button class="modal-close" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    <div class="pm-hero icon-hero"><span class="pm-icon"><i id="pmIcon" class="fa-solid fa-campground"></i></span><span class="pm-badge" id="pmBadge"></span></div>
    <div class="pm-body">
      <h3 id="pmTitle"></h3>
      <p id="pmDesc"></p>
      <div class="pm-facts" id="pmFacts"></div>
      <div class="pm-output"><div><b>Output</b><p id="pmOutput"></p></div></div>
      <h4>What to expect</h4>
      <ul class="pm-expect" id="pmExpect"></ul>
      <a href="#register" class="btn btn-primary" id="pmApply">Register for Tech Camp <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</div>

<div class="lightbox" id="lightbox" hidden>
  <button class="lb-close" data-lb="close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
  <button class="lb-nav prev" data-lb="prev" aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>
  <figure><img id="lbImg" src="" alt=""><figcaption id="lbCap"></figcaption></figure>
  <button class="lb-nav next" data-lb="next" aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script id="programData" type="application/json"><?= json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
<script src="assets/js/payment.js?v=<?= filemtime(__DIR__ . '/assets/js/payment.js') ?>"></script>
<?php if (google_enabled() && $regOpen && !$regFull && !$regVerified): ?>
<script src="assets/js/google.js?v=<?= filemtime(__DIR__ . '/assets/js/google.js') ?>"></script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
</body>
</html>
