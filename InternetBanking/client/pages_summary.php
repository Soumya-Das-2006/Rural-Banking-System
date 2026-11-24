<?php
/**
 * Monthly summary in plain language (figures from the database, wording from AI).
 * (c) 2026 Soumya Das
 */
session_start();
include('conf/config.php');
include('conf/checklogin.php');
include(__DIR__ . '/../core/summary.php');
check_login();
$client_id = (int) $_SESSION['client_id'];

$months = rb_month_options(12);
$ym = isset($_GET['month']) && isset($months[$_GET['month']]) ? $_GET['month'] : key($months);
$f  = rb_month_figures($mysqli, $client_id, $ym);
$story = rb_month_story_en($f);
?>
<!DOCTYPE html>
<html>
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include("dist/_partials/nav.php"); ?>
    <?php include("dist/_partials/sidebar.php"); ?>
    <div class="content-wrapper">
      <section class="content-header">
        <div class="container-fluid">
          <div class="row mb-2">
            <div class="col-sm-6"><h1 class="rb-static">Monthly Summary</h1></div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Monthly Summary</li>
              </ol>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <form method="get" class="form-inline mb-3">
            <label for="month" class="mr-2">Month</label>
            <select name="month" id="month" class="form-control mr-2" onchange="this.form.submit()">
              <?php foreach ($months as $k => $label) { ?>
                <option value="<?php echo rb_e($k); ?>" <?php echo $k === $ym ? 'selected' : ''; ?>><?php echo rb_e($label); ?></option>
              <?php } ?>
            </select>
          </form>

          <div class="card rb-summary">
            <div class="card-body">
              <p class="rb-summary__text" id="story" data-no-translate="true"><?php echo rb_e($story); ?></p>
              <div>
                <button type="button" id="btnExplain" class="btn btn-success"><i class="fas fa-language mr-1"></i> Explain in my language</button>
                <button type="button" id="btnListen" class="btn btn-outline-success ml-1"><i class="fas fa-volume-up mr-1"></i> Listen</button>
              </div>
              <p class="text-muted small mt-2 mb-0" id="note" data-no-translate="true">Figures are calculated from your records. The AI only rewrites them in simple words.</p>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span><div class="info-box-content"><span class="info-box-text">Deposited</span><span class="info-box-number"><?php echo rb_e(rb_money($f['deposits'])); ?></span></div></div></div>
            <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-download"></i></span><div class="info-box-content"><span class="info-box-text">Received</span><span class="info-box-number"><?php echo rb_e(rb_money($f['received'])); ?></span></div></div></div>
            <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-arrow-up"></i></span><div class="info-box-content"><span class="info-box-text">Withdrawn</span><span class="info-box-number"><?php echo rb_e(rb_money($f['withdrawals'])); ?></span></div></div></div>
            <div class="col-md-3 col-6"><div class="info-box"><span class="info-box-icon bg-danger"><i class="fas fa-paper-plane"></i></span><div class="info-box-content"><span class="info-box-text">Sent</span><span class="info-box-number"><?php echo rb_e(rb_money($f['sent'])); ?></span></div></div></div>
          </div>
        </div>
      </section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
  <script>
    (function () {
      var LOCALE = { en: 'en-IN', hi: 'hi-IN', gu: 'gu-IN', bn: 'bn-IN', ta: 'ta-IN', te: 'te-IN', mr: 'mr-IN', kn: 'kn-IN', ml: 'ml-IN', pa: 'pa-IN', or: 'or-IN', ur: 'ur-IN', as: 'as-IN' };
      function lang() { try { return localStorage.getItem('rb_language') || 'en'; } catch (e) { return 'en'; } }
      var story = document.getElementById('story'), note = document.getElementById('note');
      document.getElementById('btnExplain').addEventListener('click', function () {
        var btn = this, label = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Working\u2026';
        fetch('../api/summary.php', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ month: <?php echo json_encode($ym); ?>, lang: lang() }) })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.summary) story.textContent = d.summary;
            note.textContent = d.fallback && lang() !== 'en' ? 'Showing English: the AI is not available right now.' : 'Explained in your chosen language (use the \uD83C\uDF10 button to change it).';
          })
          .catch(function () { note.textContent = 'Network problem. Please try again.'; })
          .then(function () { btn.disabled = false; btn.innerHTML = label; });
      });
      document.getElementById('btnListen').addEventListener('click', function () {
        if (!('speechSynthesis' in window)) { note.textContent = 'Your browser cannot read aloud.'; return; }
        window.speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(story.textContent);
        u.lang = LOCALE[lang()] || 'en-IN'; u.rate = 0.92;
        window.speechSynthesis.speak(u);
      });
    })();
  </script>
</body>

</html>
