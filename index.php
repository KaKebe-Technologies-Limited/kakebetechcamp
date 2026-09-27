<?php
require __DIR__ . '/includes/bootstrap.php';

$regOpen  = setting('registration_open', '1') === '1';
$f        = fees();
$c        = camp();
$phone    = setting('contact_phone', '0779 712 990');
$email    = setting('contact_email');
$website  = setting('org_website');
$socials  = social_links();
$team     = team_members();
$base     = $f['camp'] + $f['jersey'];
$sandbox  = iotec()['sandbox'];
$_SESSION['form_rendered_at'] = time();
$me       = current_participant();

$img = fn(string $name) => 'assets/img/' . $name;

$details = [
    'mentorship' => [
        'title'  => 'Mentorship Program',
        'badge'  => 'Free with Tech Camp',
        'image'  => $img('mentors.jpg'),
        'icon'   => 'fa-people-arrows',
        'desc'   => 'The Mentorship Program is the foundation of the ecosystem. It connects young people with experienced industry professionals across business, technology, content creation, and personal development. Everyone who registers for Kakebe Tech Camp 2026 is automatically enrolled — completely free.',
        'facts'  => [
            ['fa-calendar-days', 'When', 'October – November 2026 · online every Monday, 8:00 – 9:30 PM (from 5th October)'],
            ['fa-location-dot', 'Physical sessions', 'Lira, Kitgum and Kampala'],
            ['fa-tag', 'Cost', 'Free for Tech Camp participants'],
        ],
        'output' => 'Participants mentored in entrepreneurship, digital marketing, IT, AI, and personal branding — gaining skills, confidence, and a clear pathway to the next stage of the ecosystem.',
        'expect' => ['Weekly guidance from industry experts', 'Practical skills development', 'One-on-one mentorship', 'A supportive community of young people who share your ambition'],
    ],
    'internship' => [
        'title'  => 'Digital Bridge Internship Program',
        'badge'  => 'Free with Tech Camp',
        'image'  => $img('team-red.jpg'),
        'icon'   => 'fa-briefcase',
        'desc'   => 'The Digital Bridge Internship Program (DBIP) provides hands-on experience by connecting participants with companies, businesses, creators, and entrepreneurs. Interns work on real projects, build their portfolios, and expand their professional networks. Tech Camp participants join free.',
        'facts'  => [
            ['fa-calendar-days', 'When', 'October – November 2026'],
            ['fa-user-tie', 'Guided by', 'Experienced industry professionals'],
            ['fa-tag', 'Cost', 'Free for Tech Camp participants'],
        ],
        'output' => 'Interns placed with companies, businesses, and creators, gaining practical experience in software development, business, content creation, and digital marketing.',
        'expect' => ['Virtual and physical work with real organisations', 'Mentorship from industry professionals', 'Networking opportunities', 'A pathway to employment or entrepreneurship'],
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
    ['What does it cost to attend?', 'The camp package is ' . format_ugx($base) . ' — the camp fee (' . format_ugx($f['camp']) . ') covering training, accommodation and meals, plus the sports jersey every participant receives (' . format_ugx($f['jersey']) . '). The camp shirt is free and the ' . $f['park_name'] . ' excursion is optional (' . format_ugx($f['park']) . '). You can pay in instalments: half secures your place and the rest can follow before camp.'],
    ['I have registered. How do I check my registration?', 'Click "My registration" at the top of the website and enter the email and phone number you registered with, or log in to the participant portal. There you can view your details, complete any payment (Mobile Money or card) and download receipts and your ticket.'],
    ['What do I get with my registration?', 'Ten days of residential training in Kitgum (accommodation and meals included), camp materials, a free camp shirt, your sports jersey, hackathons, Demo Day, a certificate — plus free enrolment in the Mentorship Program and Digital Bridge Internship (October – November 2026).'],
    ['When will I get my camp ticket?', 'Once your package is fully paid, your camp ticket (with your photo and a QR code) is available in your email and portal. Upload a clear photo when registering or from your portal.'],
    ['Can I sponsor a young person?', 'Yes! Use the "Sponsor a child" section to cover a participant\'s package (' . format_ugx($f['sponsor_child']) . ' per child) or give any amount. You receive a PDF receipt by email.'],
];

$contacts = [
    ['Komackech Moses Santos', 'Head of Communications', '0779 712 990'],
    ['Geovia Ayo (Jojo)', 'Public Relations', '0759 526 143'],
    ['Oscar Jerome Okello', 'Operations Lead', '+256 707 711 682'],
];

$eventLd = [
    '@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Kakebe Tech Camp 2026',
    'startDate' => $c['start_date'], 'endDate' => $c['end_date'],
    'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'location' => ['@type' => 'Place', 'name' => 'Kitgum (Residential Camp)', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Kitgum', 'addressCountry' => 'UG']],
    'image' => [base_url('assets/img/techcamp-logo-pdf.jpg')],
    'description' => 'A 10-day residential tech camp for youth aged 14–30 in Northern Uganda: AI & software development, content creation, entrepreneurship, video gaming, robotics and digital marketing.',
    'offers' => ['@type' => 'Offer', 'price' => (string) $base, 'priceCurrency' => 'UGX', 'url' => base_url('#register'), 'availability' => 'https://schema.org/InStock'],
    'organizer' => ['@type' => 'Organization', 'name' => 'Kakebe Technologies Limited', 'url' => $website ?: base_url()],
];

$navLeft = [['#home', 'Home'], ['#about', 'About'], ['#programs', 'Programs'], ['#techcamp', 'Tech Camp'], ['#sponsor', 'Sponsor']];
$navRight = [['#schedule', 'Schedule'], ['#team', 'Team'], ['#faq', 'FAQ'], ['#contact', 'Contact']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kakebe Tech Camp 2026 · Learn. Build. Innovate. | Northern Uganda</title>
  <meta name="description" content="Kakebe Tech Camp 2026 — a 10-day residential tech camp in Kitgum (<?= e($c['dates']) ?>) for youth aged 14–30, with free mentorship and internships. AI, content creation, entrepreneurship, gaming, robotics & digital marketing.">
  <meta name="theme-color" content="#E11D2A">
  <script>document.documentElement.classList.add('js')</script>
  <meta property="og:type" content="website">
  <meta property="og:title" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda">
  <meta property="og:description" content="10 days of AI, software, content creation, entrepreneurship, gaming & robotics in Kitgum. <?= e($c['dates']) ?>. Ages 14–30.">
  <meta property="og:site_name" content="Kakebe Tech Camp 2026">
  <meta property="og:image" content="<?= e(base_url('assets/img/techcamp-logo-pdf.jpg')) ?>">
  <meta property="og:image:secure_url" content="<?= e(base_url('assets/img/techcamp-logo-pdf.jpg')) ?>">
  <meta property="og:image:type" content="image/jpeg">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda. Learn. Build. Innovate.">
  <meta property="og:url" content="<?= e(base_url()) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Kakebe Tech Camp 2026 — Let's Gather in Northern Uganda">
  <meta name="twitter:description" content="10 days of AI, software, content creation, entrepreneurship, gaming & robotics in Kitgum. <?= e($c['dates']) ?>. Ages 14–30.">
  <meta name="twitter:image" content="<?= e(base_url('assets/img/techcamp-logo-pdf.jpg')) ?>">
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Caveat:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="assets/css/site-v2.css?v=<?= filemtime(__DIR__ . '/assets/css/site-v2.css') ?>">
  <script type="application/ld+json"><?= json_encode($eventLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>

<!-- ============ TOP BAR ============ -->
<div class="topbar">
  <div class="container topbar-inner">
    <ul class="topbar-info">
      <li><i class="fa-regular fa-calendar"></i> <?= e($c['dates']) ?></li>
      <li><i class="fa-solid fa-location-dot"></i> Kitgum, Northern Uganda</li>
      <li><i class="fa-solid fa-headset"></i> Support: <a href="<?= e(tel_link($phone)) ?>"><?= e($phone) ?></a></li>
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
      <img src="assets/img/techcamp-logo.webp" alt="Kakebe Tech Camp 2026" width="1774" height="887">
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
    <span class="float-icon fi-2"><i class="fa-solid fa-play"></i></span>
    <span class="float-icon fi-3"><i class="fa-solid fa-gamepad"></i></span>
  </div>
  <div class="container hero-grid">
    <div class="hero-content">
      <p class="script reveal">Let's gather in Northern Uganda</p>
      <h1 class="hero-title reveal">Learn. Build. <span>Innovate.</span></h1>
      <p class="hero-lead reveal">A 10-day residential tech camp in <strong>Kitgum</strong> for young people aged <strong>14–30</strong> — AI &amp; software, content creation, entrepreneurship, gaming, robotics and digital marketing, plus <strong>free mentorship &amp; internships</strong> from October.</p>
      <div class="hero-cta reveal">
        <a href="#register" class="btn btn-primary btn-lg">Register Now <i class="fa-solid fa-arrow-right"></i></a>
        <a href="#sponsor" class="btn btn-ghost btn-lg"><span class="play"><i class="fa-solid fa-heart"></i></span> Sponsor a child</a>
      </div>
      <div class="countdown reveal" id="countdown" data-target="<?= e($c['start_iso']) ?>" aria-live="polite">
        <div class="cd-label"><i class="fa-solid fa-hourglass-half"></i> Camp starts in</div>
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
      <figure class="hero-photo main"><img src="assets/img/robotics.jpg" alt="Participants assembling a robot during a Kakebe hands-on session" width="1600" height="1200" fetchpriority="high"></figure>
      <figure class="hero-photo small"><img src="assets/img/smiles-sm.jpg" alt="Smiling young women at a Kakebe event" width="720" height="480"></figure>
      <div class="fee-badge"><span>Only</span><b>300<small>SEATS</small></b><em>Kitgum 2026</em></div>
      <div class="float-card fc-1"><span class="fc-icon"><i class="fa-solid fa-campground"></i></span><div><b>10 Days</b><small>14 – 23 December</small></div></div>
      <div class="float-card fc-2"><span class="fc-icon blue"><i class="fa-solid fa-users"></i></span><div><b>Ages 14 – 30</b><small>300 young innovators</small></div></div>
    </div>
  </div>

  <div class="container">
    <div class="quickbar reveal">
      <div class="qb-item"><span class="qb-icon"><i class="fa-regular fa-calendar-check"></i></span><div><small>Dates</small><b><?= e($c['dates_short']) ?></b></div></div>
      <div class="qb-item"><span class="qb-icon"><i class="fa-solid fa-location-dot"></i></span><div><small>Location</small><b>Kitgum · Residential</b></div></div>
      <div class="qb-item"><span class="qb-icon"><i class="fa-solid fa-user-group"></i></span><div><small>Who</small><b>Youth aged 14 – 30</b></div></div>
      <div class="qb-item"><span class="qb-icon"><i class="fa-solid fa-lightbulb"></i></span><div><small>Learning tracks</small><b>6 tracks · pick 2</b></div></div>
      <a href="#register" class="qb-btn">Register <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ============ PROGRAMS ============ -->
<section class="section programs" id="programs">
  <div class="container programs-grid">
    <div class="programs-intro reveal">
      <span class="chip"><i class="fa-solid fa-diagram-project"></i> The Ecosystem</span>
      <h2 class="title">One sign-up.<br>A whole <span class="hl">journey.</span></h2>
      <p>Register for Kakebe Tech Camp 2026 and you are automatically enrolled — free — in the Mentorship Program and the Digital Bridge Internship from October to November, guided by experienced industry professionals.</p>
      <ul class="mini-list">
        <li><i class="fa-solid fa-circle-check"></i><span>Mentorship &amp; internship <b>free with the camp</b></span></li>
        <li><i class="fa-solid fa-circle-check"></i><span>Hands-on learning with <b>industry experts</b></span></li>
      </ul>
      <a href="#register" class="btn btn-primary">Start Your Journey <i class="fa-solid fa-arrow-right"></i></a>
    </div>
    <div class="eco-grid" role="list">
      <?php
      $eco = [
          ['mentorship', '01', 'Free', 'Oct – Nov 2026', 'Weekly guidance and one-on-one mentorship from experienced industry professionals.'],
          ['internship', '02', 'Free', 'Oct – Nov 2026', 'Hands-on work on real projects with companies, businesses and creators.'],
          ['trees', '03', '1 Day', 'Date to be announced', 'All teams plant 1,000+ seedlings for greener, healthier communities.'],
          ['techcamp', '04', 'Flagship', $c['dates_short'], 'Ten residential days in Kitgum: learn, build, innovate and present at Demo Day.'],
      ];
      foreach ($eco as [$key, $num, $badge, $when, $blurb]): $d = $details[$key]; ?>
      <button type="button" class="eco-card reveal<?= $key === 'techcamp' ? ' featured' : '' ?>" data-program="<?= e($key) ?>" role="listitem" aria-label="View details: <?= e($d['title']) ?>">
        <span class="eco-top"><span class="eco-icon"><i class="fa-solid <?= e($d['icon']) ?>"></i></span><span class="eco-badge"><?= e($badge) ?></span></span>
        <small class="eco-when"><i class="fa-regular fa-calendar"></i> <?= e($when) ?></small>
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
<section class="section about" id="about">
  <div class="container about-grid">
    <div class="about-media reveal">
      <figure class="about-img main"><img src="assets/img/crowd.jpg" alt="Hundreds of young people gathered in front of a building in Northern Uganda" loading="lazy" width="1600" height="1067"></figure>
      <figure class="about-img sub"><img src="assets/img/camp-group-sm.jpg" alt="Kakebe participants and mentors" loading="lazy" width="720" height="405"></figure>
      <div class="about-badge"><b data-count="500" data-suffix="+">500+</b><span>Youth &amp; community members</span></div>
      <span class="about-dots" aria-hidden="true"></span>
    </div>
    <div class="about-content reveal">
      <span class="chip"><i class="fa-solid fa-seedling"></i> Get to know us</span>
      <h2 class="title">Building innovators, problem solvers &amp; <span class="hl">nation builders</span></h2>
      <p class="lead">The <strong>Kakebe Ecosystem Program</strong> builds youth innovation, digital skills, and economic empowerment in Northern Uganda.</p>
      <p>It connects young people to mentorship, practical experience, industry networks, and a national tech camp — running from <strong>October to December 2026</strong> and reaching over <strong>500 youth and community members</strong>.</p>
      <div class="about-features">
        <div class="af-item"><span class="af-icon"><i class="fa-solid fa-chalkboard-user"></i></span><div><b>Expert Mentorship</b><p>Guidance from professionals in business, tech &amp; content.</p></div></div>
        <div class="af-item"><span class="af-icon blue"><i class="fa-solid fa-laptop-code"></i></span><div><b>Real Experience</b><p>Internships on real projects with real organisations.</p></div></div>
      </div>
      <div class="about-box">
        <div class="ab-award"><i class="fa-solid fa-trophy"></i><b>Oct – Dec</b><span>2026 Program</span></div>
        <ul class="check-list">
          <li><i class="fa-solid fa-check"></i> Mentorship, internships &amp; a national tech camp</li>
          <li><i class="fa-solid fa-check"></i> Skills in AI, software, content &amp; business</li>
          <li><i class="fa-solid fa-check"></i> Industry networks across Uganda</li>
        </ul>
      </div>
      <a href="#register" class="btn btn-primary">Join the Ecosystem <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>

<!-- ============ TECH CAMP: TRACKS + PACKAGE ============ -->
<section class="section tracks" id="techcamp">
  <div class="container">
    <div class="section-head center reveal">
      <span class="chip"><i class="fa-solid fa-campground"></i> Kakebe Tech Camp 2026</span>
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
        <span class="chip"><i class="fa-solid fa-gift"></i> What's included</span>
        <h3 class="title-sm">Everything you need for 10 unforgettable days</h3>
        <ul class="include-grid">
          <li><i class="fa-solid fa-bed"></i> Accommodation</li>
          <li><i class="fa-solid fa-utensils"></i> Meals</li>
          <li><i class="fa-solid fa-chalkboard"></i> Training &amp; classes</li>
          <li><i class="fa-solid fa-box-open"></i> Camp materials</li>
          <li><i class="fa-solid fa-shirt"></i> Free camp shirt</li>
          <li><i class="fa-solid fa-laptop-code"></i> Hackathons</li>
          <li><i class="fa-solid fa-microphone-lines"></i> Expert-led sessions</li>
          <li><i class="fa-solid fa-handshake"></i> Networking</li>
          <li><i class="fa-solid fa-certificate"></i> Certificate of completion</li>
          <li><i class="fa-solid fa-people-arrows"></i> Free mentorship &amp; internship</li>
        </ul>
        <div class="camp-float-inline"><i class="fa-solid fa-award"></i><div><b>Demo Day</b><span>Present your innovation to judges, partners and guests.</span></div></div>
      </div>
      <div class="price-table reveal">
        <div class="pt-head"><span>Kakebe Tech Camp 2026</span><b>At a glance</b><small>Everything you need to know</small></div>
        <ul class="pt-rows glance">
          <li><span><i class="fa-regular fa-calendar"></i> Dates</span><b><?= e($c['dates_short']) ?></b></li>
          <li><span><i class="fa-solid fa-location-dot"></i> Venue</span><b>Kitgum · Residential</b></li>
          <li><span><i class="fa-solid fa-user-group"></i> Who</span><b>Ages 14 – 30</b></li>
          <li><span><i class="fa-solid fa-lightbulb"></i> Learning tracks</span><b>6 · choose 2</b></li>
          <li><span><i class="fa-solid fa-people-arrows"></i> Mentorship &amp; internship</span><b>Oct – Nov · free</b></li>
          <li><span><i class="fa-solid fa-water"></i> <?= e($f['park_name']) ?> excursion</span><b>Optional</b></li>
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
        <span class="chip light"><i class="fa-solid fa-bullseye"></i> Our 2026 targets</span>
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
      <span class="chip"><i class="fa-regular fa-calendar"></i> Program calendar</span>
      <h2 class="title">Your roadmap from <span class="hl">October to December</span></h2>
      <p>Mentorship and the Digital Bridge Internship run from October to November, leading up to the Tech Camp in Kitgum from <?= e($c['dates']) ?>.</p>
    </div>
    <div class="sessions">
      <div class="session-card online reveal">
        <span class="s-tag"><i class="fa-solid fa-wifi"></i> Online · Free</span>
        <h3>Weekly mentorship sessions</h3>
        <div class="s-time"><b>Every Monday</b><span>8:00 – 9:30 PM</span></div>
        <p>From <b>5th October</b> to the end of November 2026, with experienced industry professionals. Included free for every Tech Camp participant.</p>
        <a href="#register" class="btn btn-light btn-sm">Register to join <i class="fa-solid fa-arrow-right"></i></a>
      </div>
      <?php foreach ([['Lira', '2 weeks', 'in October and November'], ['Kitgum', 'Last week', 'of October and November'], ['Kampala', '4 sessions', 'in October']] as [$city, $when, $rest]): ?>
      <div class="session-card city reveal">
        <span class="s-city"><i class="fa-solid fa-location-dot"></i> <?= $city ?></span>
        <h3>Physical sessions</h3>
        <p><b><?= $when ?></b> <?= $rest ?></p>
        <span class="s-foot"><i class="fa-solid fa-people-group"></i> In-person mentorship</span>
      </div>
      <?php endforeach; ?>
    </div>
    <ol class="timeline">
      <li class="tl-item reveal"><span class="tl-dot"><i class="fa-solid fa-flag"></i></span><div class="tl-card"><small>5th October 2026</small><h4>Mentorship kicks off</h4><p>Online sessions every Monday, 8:00 – 9:30 PM, plus physical sessions in Lira, Kitgum and Kampala.</p></div></li>
      <li class="tl-item reveal"><span class="tl-dot"><i class="fa-solid fa-briefcase"></i></span><div class="tl-card"><small>October – November 2026</small><h4>Digital Bridge Internship</h4><p>Participants work on real projects with companies, businesses and creators.</p></div></li>
      <li class="tl-item reveal"><span class="tl-dot"><i class="fa-solid fa-seedling"></i></span><div class="tl-card"><small>One day · date to be announced</small><h4>Tree planting activity</h4><p>All teams come together to plant 1,000+ seedlings and promote environmental awareness.</p></div></li>
      <li class="tl-item reveal featured"><span class="tl-dot"><i class="fa-solid fa-campground"></i></span><div class="tl-card"><small><?= e($c['dates']) ?></small><h4>Kakebe Tech Camp · Kitgum</h4><p>10 days of hands-on training, hackathons, networking and expert sessions — residential.</p></div></li>
      <li class="tl-item reveal"><span class="tl-dot"><i class="fa-solid fa-trophy"></i></span><div class="tl-card"><small>Camp finale</small><h4>Demo Day &amp; certificates</h4><p>Participants present their market-ready innovations and celebrate their achievements.</p></div></li>
    </ol>
  </div>
</section>

<!-- ============ CORE TEAM ============ -->
<section class="section team-sec" id="team">
  <div class="container">
    <div class="section-head center reveal">
      <span class="chip"><i class="fa-solid fa-people-group"></i> The people behind the camp</span>
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
      <span class="chip"><i class="fa-solid fa-star"></i> Why join</span>
      <h2 class="title">Why you should be part of the <span class="hl">Kakebe Ecosystem</span></h2>
      <p>Whether you are a student, a creator or an aspiring entrepreneur, the ecosystem gives you the skills, confidence and network to build what matters for your community.</p>
      <div class="why-list">
        <div class="why-item"><span><i class="fa-solid fa-user-tie"></i></span><div><b>Learn from experts</b><p>Weekly guidance and one-on-one mentorship.</p></div></div>
        <div class="why-item"><span><i class="fa-solid fa-briefcase"></i></span><div><b>Real work experience</b><p>Build a portfolio with real organisations.</p></div></div>
        <div class="why-item"><span><i class="fa-solid fa-rocket"></i></span><div><b>Build real products</b><p>Create market-ready innovations at camp.</p></div></div>
        <div class="why-item"><span><i class="fa-solid fa-people-group"></i></span><div><b>A national network</b><p>Meet young innovators from across Uganda.</p></div></div>
        <div class="why-item"><span><i class="fa-solid fa-certificate"></i></span><div><b>Certificate</b><p>Recognition for completing the camp.</p></div></div>
        <div class="why-item"><span><i class="fa-solid fa-hand-holding-heart"></i></span><div><b>Inclusive by design</b><p>Girls, refugees &amp; PWDs strongly encouraged.</p></div></div>
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
      <span class="chip"><i class="fa-regular fa-images"></i> Gallery</span>
      <h2 class="title">Moments from the <span class="hl">Kakebe community</span></h2>
      <p>A glimpse of the energy, learning and celebration you can expect.</p>
    </div>
    <div class="gallery-grid">
      <?php foreach ($gallery as $i => [$file, $caption, $cls]): ?>
      <button type="button" class="g-item reveal <?= e($cls) ?>" data-index="<?= $i ?>" data-full="assets/img/<?= e($file) ?>.jpg" data-caption="<?= e($caption) ?>" aria-label="Open photo: <?= e($caption) ?>">
        <img src="assets/img/<?= e($file) ?><?= str_contains($cls, 'g-tall') ? '' : '-sm' ?>.jpg" alt="<?= e($caption) ?>" loading="lazy">
        <span class="g-cap"><i class="fa-solid fa-magnifying-glass-plus"></i> <?= e($caption) ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ============ REGISTER ============ -->
<section class="section register" id="register">
  <div class="container register-grid">
    <aside class="register-info reveal">
      <span class="chip light"><i class="fa-solid fa-pen-to-square"></i> Registration</span>
      <h2>Secure your place at <span>Kakebe Tech Camp</span></h2>
      <p>Registration takes about 2 minutes and also enrols you free in the Mentorship Program and Digital Bridge Internship.</p>
      <ol class="steps">
        <li><span>1</span><div><b>Fill in your details</b><small>Tell us about yourself and pick your learning tracks.</small></div></li>
        <li><span>2</span><div><b>Confirm your email</b><small>Enter the 6-digit code we send to your inbox.</small></div></li>
        <li><span>3</span><div><b>Secure your place</b><small>Complete your camp package now or later — instalments welcome.</small></div></li>
        <li><span>4</span><div><b>Get your ticket</b><small>Your camp ticket arrives by email, ready for check-in.</small></div></li>
      </ol>
      <div class="summary-card perks">
        <h4><i class="fa-solid fa-gift"></i> What you get</h4>
        <ul>
          <li><span><i class="fa-solid fa-campground"></i> 10 days of residential training in Kitgum</span></li>
          <li><span><i class="fa-solid fa-utensils"></i> Accommodation, meals &amp; camp materials</span></li>
          <li><span><i class="fa-solid fa-shirt"></i> Sports jersey &amp; a free camp shirt</span></li>
          <li><span><i class="fa-solid fa-people-arrows"></i> Free mentorship &amp; internship (Oct – Nov)</span></li>
          <li><span><i class="fa-solid fa-certificate"></i> Certificate &amp; Demo Day</span></li>
        </ul>
      </div>
      <div class="help-box">
        <i class="fa-brands fa-whatsapp"></i>
        <div><small>Need help registering?</small><a href="<?= e(whatsapp_link('Hello, I need help registering for Kakebe Tech Camp 2026.')) ?>" target="_blank" rel="noopener"><?= e($phone) ?></a></div>
      </div>
    </aside>

    <div class="register-card reveal">
      <?php if (!$regOpen): ?>
        <div class="form-closed">
          <span><i class="fa-solid fa-lock"></i></span>
          <h3>Registration is currently closed</h3>
          <p>Already registered? <a href="pay.php">Pay or view your registration</a>. For help call <a href="<?= e(tel_link($phone)) ?>"><?= e($phone) ?></a>.</p>
        </div>
      <?php else: ?>
      <form id="regForm" class="reg-form" novalidate enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="form-head">
          <h3>Tech Camp registration</h3>
          <p>Fields marked <span class="req">*</span> are required. Already registered? <a href="pay.php">View your registration</a>.</p>
        </div>

        <div class="form-section"><span>1</span> Your details</div>
        <div class="form-grid">
          <div class="field full">
            <label for="f_name">Full name <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-regular fa-user"></i><input id="f_name" name="full_name" type="text" maxlength="150" autocomplete="name" placeholder="e.g. Akello Grace" required></div>
            <span class="err" data-err="full_name"></span>
          </div>
          <div class="field">
            <label for="f_age">Age <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-cake-candles"></i><input id="f_age" name="age" type="number" inputmode="numeric" min="14" max="30" placeholder="14 – 30" required></div>
            <span class="err" data-err="age"></span>
          </div>
          <div class="field">
            <label for="f_gender">Gender</label>
            <div class="input-icon"><i class="fa-solid fa-venus-mars"></i><select id="f_gender" name="gender"><option value="">Select…</option><?php foreach (genders() as $g): ?><option><?= e($g) ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="field full" id="emailField">
            <label for="f_email">Email address <span class="req">*</span> <span class="verified-tag" id="emailVerified" hidden><i class="fa-solid fa-circle-check"></i> Verified</span></label>
            <div class="email-row">
              <div class="input-icon"><i class="fa-regular fa-envelope"></i><input id="f_email" name="email" type="email" maxlength="190" autocomplete="email" placeholder="you@example.com" required></div>
              <button type="button" class="btn-verify" id="sendCode"><span class="btn-label">Send code</span><span class="btn-loading"><span class="spinner"></span></span></button>
            </div>
            <div class="code-row" id="codeRow" hidden>
              <input id="f_code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="6-digit code" aria-label="Verification code">
              <button type="button" class="btn-verify navy" id="checkCode"><span class="btn-label">Confirm</span><span class="btn-loading"><span class="spinner"></span></span></button>
              <button type="button" class="link-btn" id="resendCode">Resend code</button>
            </div>
            <span class="hint" id="emailHint">We'll email you a 6-digit code to confirm this address is yours.</span>
            <span class="err" data-err="email"></span>
          </div>
          <div class="field">
            <label for="f_phone">Phone number <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-phone"></i><input id="f_phone" name="phone" type="tel" maxlength="40" autocomplete="tel" placeholder="e.g. 0772 123 456" required></div>
            <span class="err" data-err="phone"></span>
          </div>
          <div class="field">
            <label for="f_district">District of origin <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-map-pin"></i><input id="f_district" name="district" type="text" maxlength="100" list="districts" placeholder="e.g. Kitgum" required></div>
            <datalist id="districts"><?php foreach (['Kitgum','Gulu','Lira','Pader','Agago','Lamwo','Amuru','Nwoya','Omoro','Oyam','Kole','Apac','Dokolo','Alebtong','Otuke','Amolatar','Kwania','Adjumani','Moyo','Yumbe','Koboko','Arua','Nebbi','Moroto','Kotido','Kampala','Wakiso','Mukono','Jinja','Mbarara'] as $dname): ?><option value="<?= $dname ?>"><?php endforeach; ?></datalist>
            <span class="err" data-err="district"></span>
          </div>
          <div class="field">
            <label for="f_country">Country <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-earth-africa"></i><input id="f_country" name="country" type="text" maxlength="100" list="countries" value="Uganda" required></div>
            <datalist id="countries"><?php foreach (['Uganda','Kenya','South Sudan','Tanzania','Rwanda','DR Congo','Burundi','Ethiopia','Somalia','Sudan'] as $cn): ?><option value="<?= $cn ?>"><?php endforeach; ?></datalist>
            <span class="err" data-err="country"></span>
          </div>
        </div>

        <div class="form-section"><span>2</span> Learning tracks <small>Choose up to 2</small></div>
        <div class="field full">
          <div class="track-pick" id="trackPick">
            <?php foreach ($tracks as [$icon, $title]): ?>
            <label class="tpk"><input type="checkbox" name="interests[]" value="<?= e($title) ?>"><span><i class="fa-solid <?= $icon ?>"></i><b><?= e($title) ?></b><em class="fa-solid fa-circle-check"></em></span></label>
            <?php endforeach; ?>
          </div>
          <span class="hint" id="trackHint">0 of 2 selected</span>
          <span class="err" data-err="interests"></span>
        </div>

        <div class="form-section"><span>3</span> Camp package</div>
        <div class="pkg-options" id="orderSummary" data-camp="<?= $f['camp'] ?>" data-jersey="<?= $f['jersey'] ?>" data-park="<?= $f['park'] ?>" data-pct="<?= $f['deposit_pct'] ?>">
          <div class="pkg-opt locked"><i class="fa-solid fa-campground"></i><div><b>Camp fee</b><small>Training, accommodation &amp; meals · required</small></div><strong><?= e(format_ugx($f['camp'])) ?></strong></div>
          <div class="pkg-opt locked"><i class="fa-solid fa-person-running"></i><div><b>Sports jersey vest</b><small>Required for all participants</small></div><strong><?= e(format_ugx($f['jersey'])) ?></strong></div>
          <div class="pkg-opt locked free"><i class="fa-solid fa-shirt"></i><div><b>Camp shirt</b><small>Included for everyone</small></div><strong>FREE</strong></div>
          <label class="pkg-opt toggle"><input type="checkbox" name="park_visit" value="1" id="f_park"><i class="fa-solid fa-water"></i><div><b><?= e($f['park_name']) ?> park visit</b><small>Optional excursion — tick to add</small></div><strong>+<?= e(format_ugx($f['park'])) ?></strong></label>
        </div>
        <div class="form-grid">
          <div class="field">
            <label for="f_jersey">Jersey / shirt size <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-shirt"></i><select id="f_jersey" name="jersey_size" required><option value="">Select size…</option><?php foreach (jersey_sizes() as $s): ?><option><?= $s ?></option><?php endforeach; ?></select></div>
            <span class="err" data-err="jersey_size"></span>
          </div>
          <div class="field pkg-total-field"><label>Package total</label><div class="pkg-total"><b class="js-total"><?= e(format_ugx($base)) ?></b><small>Instalments welcome</small></div></div>
        </div>
        <label class="check mentor-check">
          <input type="checkbox" name="mentorship" value="1" checked>
          <span><b>Enrol me free in the Mentorship Program &amp; Digital Bridge Internship</b> (October – November 2026, with experienced industry professionals).</span>
        </label>

        <div class="form-section"><span>4</span> A little more about you</div>
        <div class="form-grid">
          <div class="field">
            <label for="f_source">How did you know about the program? <span class="req">*</span></label>
            <div class="input-icon"><i class="fa-solid fa-bullhorn"></i><select id="f_source" name="source" required><option value="">Select…</option><?php foreach (sources() as $s): ?><option><?= e($s) ?></option><?php endforeach; ?></select></div>
            <span class="err" data-err="source"></span>
          </div>
          <div class="field">
            <label for="f_ref">Who referred you? <span class="opt">(optional)</span></label>
            <div class="input-icon"><i class="fa-solid fa-user-plus"></i><input id="f_ref" name="referred_by" type="text" maxlength="150" placeholder="Name of the person who invited you"></div>
          </div>
          <div class="field full is-hidden" id="sourceOtherWrap">
            <label for="f_source_other">Please specify <span class="req">*</span></label>
            <input id="f_source_other" name="source_other" type="text" maxlength="150" placeholder="Where did you hear about us?">
            <span class="err" data-err="source_other"></span>
          </div>
          <div class="field full">
            <label for="f_motivation">Why do you want to join? <span class="opt">(optional)</span></label>
            <textarea id="f_motivation" name="motivation" rows="3" maxlength="1000" placeholder="Tell us briefly what you hope to learn or build…"></textarea>
          </div>
          <div class="field full">
            <label>Passport photo <span class="opt">(optional — used on your camp ticket)</span></label>
            <label class="upload" for="f_photo">
              <input id="f_photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
              <span class="upload-preview" id="photoPreview"><i class="fa-regular fa-image"></i></span>
              <span class="upload-text"><b id="photoName">Click to upload a photo</b><small>JPG, PNG or WEBP · max 3 MB</small></span>
            </label>
            <span class="err" data-err="photo"></span>
          </div>
          <div class="field full">
            <label class="check">
              <input type="checkbox" name="consent" value="1" required>
              <span>I confirm that the information provided is accurate and I agree to be contacted by Kakebe about this program. <span class="req">*</span></span>
            </label>
            <span class="err" data-err="consent"></span>
          </div>
        </div>

        <div class="form-alert" id="formAlert" role="alert" hidden></div>
        <button type="submit" class="btn btn-primary btn-lg btn-block" id="regSubmit">
          <span class="btn-label">Register &amp; continue <i class="fa-solid fa-arrow-right"></i></span>
          <span class="btn-loading"><span class="spinner"></span> Submitting…</span>
        </button>
      </form>

      <div class="form-success" id="regSuccess" hidden>
        <div class="success-icon"><i class="fa-solid fa-check"></i></div>
        <h3>You're registered, <span id="successName"></span>! 🎉</h3>
        <p>A confirmation has been sent to <b id="successEmail"></b>.</p>
        <div class="ref-box"><small>Your reference number</small><b id="successRef">—</b></div>
        <div class="checkout-box">
          <div class="cb-row"><span>Package total</span><b id="successTotal">—</b></div>
          <div class="cb-row"><span>Book your slot with</span><b id="successDeposit">—</b></div>
        </div>
        <p class="checkout-q">How would you like to pay?</p>
        <div class="checkout-choices">
          <a class="choice now" id="payNowBtn" href="#"><i class="fa-solid fa-bolt"></i><b>Pay now</b><small>Mobile Money or card · book your slot today</small></a>
          <button type="button" class="choice later" id="payLaterBtn"><i class="fa-regular fa-clock"></i><b>Pay later</b><small>We'll keep your registration — pay any time</small></button>
        </div>
        <div class="later-note" id="laterNote" hidden>
          <i class="fa-solid fa-circle-check"></i>
          <p>No problem! Your registration is saved. When you're ready, click <b>My registration</b> at the top of this site and enter your email and phone number — or use the payment link in your email.</p>
        </div>
        <div class="success-actions">
          <a class="btn btn-ghost" id="shareWa" href="#" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Invite friends</a>
          <button type="button" class="btn btn-ghost" id="regAnother">Register someone else</button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============ SPONSOR A CHILD ============ -->
<section class="section sponsor" id="sponsor">
  <div class="container sponsor-grid">
    <div class="sponsor-info reveal">
      <span class="chip"><i class="fa-solid fa-heart"></i> Sponsor a child</span>
      <h2 class="title">Give a young innovator <span class="hl">a seat at camp</span></h2>
      <p class="lead">Many talented young people in Northern Uganda can't afford the camp. <?= e(format_ugx($f['sponsor_child'])) ?> covers one participant's full package — camp fee, accommodation, meals, training and sports jersey.</p>
      <ul class="sponsor-impact">
        <li><span><i class="fa-solid fa-graduation-cap"></i></span><div><b>10 days of hands-on learning</b><p>AI, software, content, entrepreneurship, gaming, robotics.</p></div></li>
        <li><span><i class="fa-solid fa-people-arrows"></i></span><div><b>Mentorship &amp; internship</b><p>Two months of guidance from industry professionals.</p></div></li>
        <li><span><i class="fa-solid fa-file-invoice"></i></span><div><b>Instant PDF receipt</b><p>Transparent receipt emailed as soon as you give.</p></div></li>
      </ul>
      <figure class="sponsor-photo"><img src="assets/img/graduation-sm.jpg" alt="Tech Camp graduates celebrating" loading="lazy"></figure>
    </div>

    <div class="sponsor-card reveal">
      <form class="js-pay-form js-sponsor" action="api/donate.php" data-status="api/payment-status.php" data-kind="donation" data-per-child="<?= $f['sponsor_child'] ?>" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="pay-body">
          <h3>Support Kakebe Tech Camp</h3>
          <p class="muted">Sponsor one or more children, or give any amount.</p>
          <div class="form-grid">
            <div class="field">
              <label for="s_children">I'd like to sponsor</label>
              <select id="s_children" name="children">
                <?php foreach ([1, 2, 3, 5, 10] as $k): ?><option value="<?= $k ?>"><?= $k ?> child<?= $k > 1 ? 'ren' : '' ?> — <?= e(format_ugx($k * $f['sponsor_child'])) ?></option><?php endforeach; ?>
                <option value="0">A custom amount</option>
              </select>
            </div>
            <div class="field js-custom-amount" hidden>
              <label for="s_amount">Amount (UGX)</label>
              <input id="s_amount" name="amount" type="text" inputmode="numeric" value="<?= number_format($f['sponsor_child']) ?>">
              <span class="err" data-err="amount"></span>
            </div>
            <div class="field"><label for="s_name">Your name <span class="req">*</span></label><input id="s_name" name="donor_name" type="text" maxlength="150" required><span class="err" data-err="donor_name"></span></div>
            <div class="field"><label for="s_org">Organisation <span class="opt">(optional)</span></label><input id="s_org" name="organization" type="text" maxlength="150"></div>
            <div class="field"><label for="s_email">Email <span class="req">*</span></label><input id="s_email" name="donor_email" type="email" maxlength="190" required><span class="err" data-err="donor_email"></span></div>
            <div class="field"><label for="s_phone">Phone <span class="req">*</span></label><input id="s_phone" name="donor_phone" type="tel" maxlength="40" required><span class="err" data-err="donor_phone"></span></div>
            <div class="field full"><label for="s_msg">Message <span class="opt">(optional)</span></label><textarea id="s_msg" name="message" rows="2" maxlength="1000" placeholder="A note of encouragement for the campers…"></textarea></div>
          </div>
          <label class="check"><input type="checkbox" name="anonymous" value="1"><span>Keep my sponsorship anonymous</span></label>
          <label class="lbl">Payment method</label>
          <div class="methods">
            <label class="method"><input type="radio" name="method" value="mobile_money" checked><span><i class="fa-solid fa-mobile-screen-button"></i><b>Mobile Money</b><small>MTN · Airtel</small></span></label>
            <label class="method"><input type="radio" name="method" value="card"><span><i class="fa-regular fa-credit-card"></i><b>Card</b><small>Visa · Mastercard</small></span></label>
          </div>
          <div class="field js-mm"><label for="s_payphone">Mobile Money number</label><input id="s_payphone" name="pay_phone" type="tel" value="<?= $sandbox ? '0111777771' : '' ?>" placeholder="e.g. 0772 123 456"><span class="err" data-err="pay_phone"></span></div>
          <p class="hint js-card" hidden><i class="fa-solid fa-lock"></i> You'll be taken to ioTec's secure card page.</p>
          <?php if ($sandbox): ?><p class="sandbox"><i class="fa-solid fa-flask"></i> <b>Test mode</b> — no real money. Use <code>0111777771</code> (success).</p><?php endif; ?>
          <div class="form-alert" hidden></div>
          <button class="btn btn-primary btn-block js-pay-btn" type="submit"><span class="btn-label"><i class="fa-solid fa-heart"></i> Give <span class="js-amt"><?= e(format_ugx($f['sponsor_child'])) ?></span></span><span class="btn-loading"><span class="spinner"></span> Starting payment…</span></button>
          <p class="secure"><i class="fa-solid fa-shield-halved"></i> Secure payments by ioTec Pay</p>
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
      <span class="chip"><i class="fa-regular fa-circle-question"></i> FAQ</span>
      <h2 class="title">Questions? We've got <span class="hl">answers</span></h2>
      <p>Everything you need to know about Kakebe Tech Camp 2026. Can't find your answer? Talk to our support line.</p>
      <div class="faq-cta">
        <a href="<?= e(tel_link($phone)) ?>" class="btn btn-navy"><i class="fa-solid fa-phone"></i> <?= e($phone) ?></a>
        <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener" class="btn btn-ghost"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
      </div>
      <div class="facts-card">
        <h4><i class="fa-solid fa-circle-info"></i> Quick facts</h4>
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
      <span class="chip"><i class="fa-solid fa-headset"></i> Contact</span>
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
          <i class="fa-solid fa-map-location-dot"></i>
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
        <p class="script light">Only 300 seats available</p>
        <h2>Ready to learn, build &amp; innovate this December?</h2>
        <p>Join young innovators from across Uganda in Kitgum, <?= e($c['dates']) ?>.</p>
        <div class="cta-actions">
          <a href="#register" class="btn btn-white btn-lg">Register Now <i class="fa-solid fa-arrow-right"></i></a>
          <a href="<?= e(tel_link($phone)) ?>" class="cta-phone"><i class="fa-solid fa-phone-volume"></i> <?= e($phone) ?></a>
        </div>
      </div>
      <div class="cta-media"><img src="assets/img/building-group-sm.jpg" alt="" loading="lazy" width="720" height="405"></div>
    </div>
  </div>
</section>

</main>

<footer class="footer">
  <div class="container footer-grid">
    <div class="f-brand">
      <img src="assets/img/techcamp-logo-email.png" alt="Kakebe Tech Camp 2026" class="f-logo" width="600" height="300">
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
        <li><a href="#sponsor">Sponsor a child</a></li>
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
      <div class="pm-output"><i class="fa-solid fa-bullseye"></i><div><b>Output</b><p id="pmOutput"></p></div></div>
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
</body>
</html>
