<?php
/**
 * Rural Banking System - public landing page
 * (c) 2026 Soumya Das
 */
define('RB_NO_SESSION', true);
require_once __DIR__ . '/InternetBanking/core/bootstrap.php';
$mysqli = rb_mysqli();
$sys = rb_system_settings($mysqli);
$name = $sys['sys_name'];
$base_url = rb_public_base_url();
$testimonials = array();
$testimonials_available = true;
try {
  $result = $mysqli->query("SELECT quote_text, display_name, attribution
                            FROM ib_testimonials
                            WHERE status = 'published' AND consent_confirmed = 1
                            ORDER BY sort_order ASC, created_at DESC
                            LIMIT 6");
  while ($row = $result->fetch_assoc()) {
    $testimonials[] = $row;
  }
  $result->free();
} catch (mysqli_sql_exception $e) {
  $testimonials_available = false;
  error_log('[RuralBanking] public testimonials could not be loaded: ' . $e->getMessage());
}
$page_title = $name . ' · Banking that reaches every village';
$page_description = 'A secure customer account-management experience for rural and semi-urban communities. Manage account requests, review application records and contact support online.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo rb_e($page_title); ?></title>
  <meta name="description" content="<?php echo rb_e($page_description); ?>">
  <meta name="author" content="Soumya Das">
  <meta name="theme-color" content="#0b3b2c">
  <link rel="canonical" href="<?php echo rb_e($base_url . '/'); ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?php echo rb_e($name); ?>">
  <meta property="og:title" content="<?php echo rb_e($page_title); ?>">
  <meta property="og:description" content="<?php echo rb_e($page_description); ?>">
  <meta property="og:url" content="<?php echo rb_e($base_url . '/'); ?>">
  <meta property="og:image" content="<?php echo rb_e($base_url . '/InternetBanking/assets/img/bank-of-soumya-social.jpg'); ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?php echo rb_e($page_title); ?>">
  <meta name="twitter:description" content="<?php echo rb_e($page_description); ?>">
  <meta name="twitter:image" content="<?php echo rb_e($base_url . '/InternetBanking/assets/img/bank-of-soumya-social.jpg'); ?>">
  <link rel="icon" type="image/png" sizes="96x96" href="InternetBanking/assets/img/bank-of-soumya-favicon.png">
  <link rel="stylesheet" href="InternetBanking/admin/plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="InternetBanking/assets/css/rb-landing.css?v=20261124-4">
  <link rel="stylesheet" href="InternetBanking/assets/css/rb-consent.css">
</head>
<body data-i18n-scope="all">
<header class="nav" id="top">
  <div class="wrap nav__in">
    <a class="logo" href="#top"><img src="InternetBanking/assets/img/bank-of-soumya-logo-optimized.png" alt="Bank Of Soumya" width="1000" height="355" decoding="async"></a>
    <nav class="nav__links" id="menu" aria-label="Main">
      <a href="#features">Services</a><a href="#how">How it works</a><a href="#testimonials">Customer feedback</a><a href="#security">Security</a>
    </nav>
    <div class="nav__cta">
      <a class="btn btn--ghost" href="InternetBanking/client/pages_client_index.php">Customer sign in</a>
      <a class="btn btn--gold" href="InternetBanking/client/pages_client_signup.php">Create your account</a>
    </div>
    <button class="burger" aria-label="Menu" aria-controls="menu" aria-expanded="false"><i class="fas fa-bars"></i></button>
  </div>
</header>

<section class="hero">
  <svg class="hills" viewBox="0 0 600 160" preserveAspectRatio="none"><path d="M0 110C90 60 180 60 280 100S470 140 600 80V160H0Z" fill="rgba(255,255,255,.07)"/><path d="M0 135C110 95 200 100 310 128S500 150 600 118V160H0Z" fill="rgba(255,255,255,.1)"/></svg>
  <div class="wrap hero__in">
    <div class="hero__copy">
      <span class="pill"><i class="fas fa-seedling"></i> Built for villages &amp; cooperatives</span>
      <h1>Banking that reaches <em>every village</em>.</h1>
      <p class="lead"><?php echo rb_e($sys['sys_tagline']); ?> Create your customer profile, manage account requests and review activity recorded in your application account.</p>
      <div class="hero__btns">
        <a class="btn btn--gold btn--lg" href="InternetBanking/client/pages_client_signup.php">Create your account <i class="fas fa-arrow-right"></i></a>
        <a class="btn btn--line btn--lg" href="#how">How it works</a>
      </div>
      <ul class="ticks">
        <li><i class="fas fa-check"></i> Customer access on your phone</li>
        <li><i class="fas fa-check"></i> Protected sign-in</li>
        <li><i class="fas fa-check"></i> Clear in-app activity records</li>
      </ul>
    </div>
    <div class="hero__art" aria-hidden="true">
      <div class="card card--main">
        <div class="card__top"><span>Account overview</span><i class="fas fa-lock"></i></div>
        <div class="card__bal"><small>Account information</small><strong>Private &amp; secure</strong></div>
        <div class="card__row"><span>Customer portal</span><span>Sign in required</span></div>
      </div>
      <div class="card card--tx">
        <div class="tx"><i class="fas fa-receipt"></i><div><b>Account activity</b><small>View records after sign-in</small></div></div>
        <div class="tx"><i class="fas fa-shield-alt"></i><div><b>Private access</b><small>Your profile stays protected</small></div></div>
      </div>
    </div>
  </div>
</section>

<section class="sec" id="features">
  <div class="wrap">
    <div class="head"><span class="eyebrow">Customer services</span><h2>Your account services, in one place</h2><p>Request eligible services, review account activity and manage your customer profile online.</p></div>
    <div class="grid3">
      <article class="feat"><i class="fas fa-wallet"></i><h3>Account requests</h3><p>Request savings, recurring deposit, fixed deposit or current account services and follow their status.</p></article>
      <article class="feat"><i class="fas fa-exchange-alt"></i><h3>In-app transfers</h3><p>Transfers between eligible application accounts are recorded in the application ledger, not settled over an external network.</p></article>
      <article class="feat"><i class="fas fa-receipt"></i><h3>Activity records</h3><p>Review and print application records for deposits, withdrawals and transfers.</p></article>
      <article class="feat"><i class="fas fa-qrcode"></i><h3>QR and virtual IDs</h3><p>Use application-only QR profiles and virtual IDs to prepare an in-app payment for review.</p></article>
      <article class="feat"><i class="fas fa-life-ring"></i><h3>Customer support</h3><p>Submit service requests and support tickets from your account and follow their status.</p></article>
      <article class="feat"><i class="fas fa-user-shield"></i><h3>Account security</h3><p>Use protected sign-in and account controls to help keep your profile private.</p></article>
    </div>
  </div>
</section>

<section class="sec sec--tint" id="how">
  <div class="wrap">
    <div class="head"><span class="eyebrow">How it works</span><h2>Get started with your account</h2></div>
    <ol class="steps">
      <li><b>1</b><h3>Create your profile</h3><p>Register for secure customer access with your account details.</p></li>
      <li><b>2</b><h3>Request a service</h3><p>Choose an eligible account or service request from your customer dashboard.</p></li>
      <li><b>3</b><h3>Follow its status</h3><p>Review updates and notices in your account as the request is processed.</p></li>
      <li><b>4</b><h3>Review your records</h3><p>Check account information and activity recorded in your application profile.</p></li>
    </ol>
  </div>
</section>

<section class="sec sec--tint" id="testimonials">
  <div class="wrap">
    <div class="head"><span class="eyebrow">Customer feedback</span><h2>Experiences, in customers’ own words</h2><p>Only customer-approved feedback is published here.</p></div>
    <?php if (!$testimonials_available) { ?>
      <div class="feedback-empty"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><div><h3>Customer feedback is temporarily unavailable</h3><p>Please check back later.</p></div></div>
    <?php } elseif ($testimonials) { ?>
      <div class="testimonial-grid">
        <?php foreach ($testimonials as $testimonial) { ?>
          <figure class="testimonial">
            <i class="fas fa-quote-left" aria-hidden="true"></i>
            <blockquote><?php echo nl2br(rb_e($testimonial['quote_text']), false); ?></blockquote>
            <figcaption><strong><?php echo rb_e($testimonial['display_name']); ?></strong><?php if ($testimonial['attribution'] !== '') { ?><span><?php echo rb_e($testimonial['attribution']); ?></span><?php } ?></figcaption>
          </figure>
        <?php } ?>
      </div>
    <?php } else { ?>
      <div class="feedback-empty"><i class="fas fa-comments" aria-hidden="true"></i><div><h3>No customer testimonials yet</h3><p>Approved customer feedback will appear here when it is available.</p></div></div>
    <?php } ?>
  </div>
</section>

<section class="sec sec--dark" id="security">
  <div class="wrap sec__split">
    <div>
      <span class="eyebrow eyebrow--gold">Security</span>
      <h2>Careful with every rupee</h2>
      <p>Money movement shown here is recorded in the application ledger. Requests are validated before they are recorded; external payment settlement is not connected.</p>
    </div>
    <ul class="checks">
      <li><i class="fas fa-lock"></i><div><b>Protected passwords</b><span>Passwords are hashed and never stored in plain text.</span></div></li>
      <li><i class="fas fa-shield-alt"></i><div><b>Request verification</b><span>Forms and sensitive actions are protected against forged requests.</span></div></li>
      <li><i class="fas fa-balance-scale"></i><div><b>Atomic transactions</b><span>Transfers either complete fully or not at all, with balance checks on every debit.</span></div></li>
      <li><i class="fas fa-user-lock"></i><div><b>Sign-in protection</b><span>Repeated failed attempts are temporarily blocked.</span></div></li>
    </ul>
  </div>
</section>

<section class="cta">
  <div class="wrap cta__in">
    <h2>Ready to manage your account online?</h2>
    <a class="btn btn--gold btn--lg" href="InternetBanking/client/pages_client_signup.php">Create your account <i class="fas fa-arrow-right"></i></a>
  </div>
</section>

<footer class="foot">
  <div class="wrap foot__in">
    <a class="logo logo--sm" href="#top"><img src="InternetBanking/assets/img/bank-of-soumya-logo-optimized.png" alt="Bank Of Soumya" width="1000" height="355" loading="lazy" decoding="async"></a>
    <p>&copy; <?php echo date('Y'); ?> Bank Of Soumya &middot; Designed &amp; developed by <b>Soumya Das</b> &middot;
      <a href="InternetBanking/service_information.php">Service Information</a> ·
      <a href="InternetBanking/service_information.php#privacy">Privacy Policy</a> ·
      <a href="InternetBanking/service_information.php#terms">Terms &amp; Conditions</a> ·
      <button class="cookie-settings" type="button" data-cookie-settings>Cookie settings</button>
    </p>
  </div>
</footer>
<aside class="rb-cookie-banner" data-cookie-banner data-ga-id="<?php echo rb_e(rb_ga4_measurement_id()); ?>" role="dialog" aria-label="Cookie and analytics preferences" hidden>
  <div class="rb-cookie-banner__copy">
    <strong>Privacy choices</strong>
    <p>Necessary session storage supports secure sign-in. Optional analytics stay off unless you accept them. <a href="InternetBanking/service_information.php#privacy">Read the Privacy Policy</a>.</p>
  </div>
  <div class="rb-cookie-banner__actions">
    <button class="rb-cookie-banner__accept" type="button" data-cookie-accept>Accept optional analytics</button>
    <button class="rb-cookie-banner__reject" type="button" data-cookie-reject>Reject optional analytics</button>
  </div>
</aside>
<script src="InternetBanking/assets/js/rb-i18n.js" defer></script>
<script src="InternetBanking/assets/js/rb-assistant.js" defer></script>
<script src="InternetBanking/assets/js/rb-consent.js" defer></script>
<script>
  (function(){
    var b=document.querySelector('.burger'),m=document.getElementById('menu');
    b.addEventListener('click',function(){var o=m.classList.toggle('open');b.setAttribute('aria-expanded',o)});
    m.addEventListener('click',function(e){if(e.target.tagName==='A'){m.classList.remove('open')}});
    var h=document.querySelector('.nav');window.addEventListener('scroll',function(){h.classList.toggle('scrolled',window.scrollY>10)});
  })();
</script>
</body>
</html>
