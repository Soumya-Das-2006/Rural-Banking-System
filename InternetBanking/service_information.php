<?php
if (!defined('RB_NO_SESSION')) {
    define('RB_NO_SESSION', true);
}
require_once __DIR__ . '/core/bootstrap.php';
$base_url = rb_public_base_url();
$page_url = $base_url . '/InternetBanking/service_information.php';
$page_title = 'Privacy Policy, Terms & Service Information | Bank Of Soumya';
$page_description = 'Read Bank Of Soumya service scope, terms and conditions, privacy policy, security logging and optional analytics information.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo rb_e($page_title); ?></title>
  <meta name="description" content="<?php echo rb_e($page_description); ?>">
  <meta name="author" content="Soumya Das">
  <meta name="robots" content="index,follow">
  <link rel="canonical" href="<?php echo rb_e($page_url); ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Bank Of Soumya">
  <meta property="og:title" content="<?php echo rb_e($page_title); ?>">
  <meta property="og:description" content="<?php echo rb_e($page_description); ?>">
  <meta property="og:url" content="<?php echo rb_e($page_url); ?>">
  <meta property="og:image" content="<?php echo rb_e($base_url . '/InternetBanking/assets/img/bank-of-soumya-social.jpg'); ?>">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" type="image/png" sizes="96x96" href="assets/img/bank-of-soumya-favicon.png">
  <link rel="stylesheet" href="assets/css/rb-theme.css?v=20261124-2">
  <link rel="stylesheet" href="assets/css/rb-consent.css">
  <style>
    body{margin:0;background:#f6f2e8;color:#14281f;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
    .service-shell{max-width:980px;margin:0 auto;padding:24px}
    .service-nav{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:28px}
    .service-brand{display:block;width:220px;max-width:100%;text-decoration:none}
    .service-brand img{display:block;width:100%;height:auto;max-height:82px;object-fit:contain}
    .service-nav-links{display:flex;gap:16px;flex-wrap:wrap}
    .service-nav-links a,.service-card a{color:#1f6f4f;font-weight:700}
    .service-hero{padding:34px;border-radius:22px;background:linear-gradient(135deg,#0f4d38,#0a2e22);color:#fff;box-shadow:0 16px 38px rgba(10,46,34,.16)}
    .service-hero h1{margin:.35rem 0 .6rem;font-family:Georgia,serif;font-size:clamp(2rem,5vw,3rem)}
    .service-hero p{max-width:680px;margin:0;color:#e3f1ea;line-height:1.7}
    .service-eyebrow{color:#f2c25b;font-size:.78rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
    .service-card{margin-top:20px;padding:26px;border:1px solid #e6dfce;border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(20,40,31,.05)}
    .service-card h2{margin:0 0 14px;font-family:Georgia,serif;font-size:1.45rem}
    .service-card p,.service-card li{color:#43594e;line-height:1.7}
    .service-card ul{margin:0;padding-left:22px}
    .service-card li+li{margin-top:9px}
    .service-foot{padding:24px 4px;color:#617369;font-size:.9rem}
    a:focus-visible,.cookie-settings:focus-visible{outline:3px solid #14583e;outline-offset:3px;box-shadow:0 0 0 5px #f2c25b}
    @media(max-width:600px){.service-shell{padding:16px}.service-nav{align-items:flex-start;flex-direction:column}.service-hero,.service-card{padding:22px}}
  </style>
</head>
<body>
  <main class="service-shell">
    <header class="service-nav">
      <a class="service-brand" href="../index.php">
        <img src="assets/img/bank-of-soumya-logo-optimized.png" alt="Bank Of Soumya" width="1000" height="355" decoding="async">
      </a>
      <nav class="service-nav-links" aria-label="Main navigation">
        <a href="../index.php">Home</a>
        <a href="client/pages_client_index.php">Customer sign in</a>
        <a href="#terms">Terms &amp; Conditions</a>
        <a href="#privacy">Privacy Policy</a>
      </nav>
    </header>

    <section class="service-hero">
      <span class="service-eyebrow">Account and product details</span>
      <h1>Service Information</h1>
      <p>Product scope, service terms and privacy information for this application. This page describes the current software capabilities and configured integrations.</p>
    </section>

    <section class="service-card" id="services">
      <h2>Current service scope</h2>
      <p>The current build records monetary activity in its own application ledger. It is not connected to external payment settlement, UPI/NPCI/BHIM, credit bureaus or card authorization networks. A ledger entry does not confirm settlement by an external bank or provider.</p>
      <ul>
        <li><strong>QR and virtual IDs:</strong> These identify profiles inside this application. They are not UPI addresses and do not route payments through BHIM, NPCI, or an external bank.</li>
        <li><strong>Credit health:</strong> Scores use customer-reported information and the application’s calculation model (payment history 40%, utilization 30%, history length 20%, recent enquiries 10%). They are not verified CIBIL/TransUnion reports, lender assessments, or credit offers, and must not be used for automatic credit decisions.</li>
        <li><strong>Cards:</strong> Card screens manage virtual records, requests and authorization controls. They do not issue payment-network credentials or a physical card.</li>
        <li><strong>Loans and deposits:</strong> Loan applications and EMI schedules are application records. An approved loan action may post a credit to the internal ledger; it is not an external lender disbursement or lending contract. EMI payments recorded here post only to the application ledger and are not reported to an external lender. FD/RD requests and maturity values are records or estimates; they do not reserve funds or automatically pay maturity proceeds.</li>
        <li><strong>KYC and rewards:</strong> KYC statuses record a workflow and do not verify identity. Reward points are application records and have no cash value.</li>
        <li><strong>Financial insights:</strong> Budget insights are based on recorded activity and fixed rules. They are general information, not personalized financial advice.</li>
      </ul>
    </section>

    <section class="service-card" id="terms">
      <h2>Terms &amp; Conditions</h2>
      <p>Use account and transaction records as records within this application, not as official bank statements, proof of external settlement, credit-bureau reports, loan offers, or deposit confirmations. For regulated financial products, rely on the terms and statements provided directly by the authorized institution or service provider.</p>
      <p>The application software does not itself establish that its operator or any product is a licensed bank, lender, card issuer or payment service provider. Any future integration must be identified here with its authorized provider and applicable terms before it is represented as available.</p>
    </section>

    <section class="service-card" id="privacy">
      <h2>Privacy Policy</h2>
      <p>Account, support and transaction information is stored in the application’s configured database. For security, sign-ins and authenticated page requests record the account role, activity type, time, browser details and IP address. These records are available to administrators and are retained for up to 180 days. Approximate country is recorded only when a configured trusted proxy supplies it; no third-party IP geolocation lookup is performed. Failed sign-in, public-registration and translation rate limits may temporarily record IP addresses; failed sign-in records may also contain the submitted email. Rate-limit records are pruned during subsequent checks.</p>
      <p>Email notifications use the operator-configured SMTP provider. When enabled, AI features may send prompts, staff notes or financial aggregates to the configured AI provider; customer balances are included only when the operator enables that option. Translation may send eligible interface text to its configured provider. AI-generated content may be inaccurate and should be reviewed.</p>
      <p>Optional Google Analytics 4 is disabled unless the operator configures a valid measurement ID and a visitor accepts optional analytics. The analytics script is not loaded before consent. The stored preference is kept in the visitor’s browser. Necessary session cookies support sign-in security; they are HttpOnly, SameSite Lax and marked Secure over HTTPS.</p>
      <p>Do not enter passwords, one-time codes, full card numbers, security codes or sensitive identity numbers into support messages or AI prompts. Contact the service administrator to request access or correction of account information, or to ask about retention and provider configuration.</p>
    </section>

    <footer class="service-foot">
      &copy; <?php echo date('Y'); ?> Bank Of Soumya · <a href="../index.php">Return to home</a> ·
      <button class="cookie-settings" type="button" data-cookie-settings>Cookie settings</button>
    </footer>
  </main>
  <aside class="rb-cookie-banner" data-cookie-banner data-ga-id="<?php echo rb_e(rb_ga4_measurement_id()); ?>" role="dialog" aria-label="Cookie and analytics preferences" hidden>
    <div class="rb-cookie-banner__copy">
      <strong>Privacy choices</strong>
      <p>Necessary session storage supports secure sign-in. Optional analytics stay off unless you accept them. <a href="#privacy">Read the Privacy Policy</a>.</p>
    </div>
    <div class="rb-cookie-banner__actions">
      <button class="rb-cookie-banner__accept" type="button" data-cookie-accept>Accept optional analytics</button>
      <button class="rb-cookie-banner__reject" type="button" data-cookie-reject>Reject optional analytics</button>
    </div>
  </aside>
  <script src="assets/js/rb-consent.js" defer></script>
</body>
</html>
