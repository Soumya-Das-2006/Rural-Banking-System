<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
$clients = array();
$r = $mysqli->query('SELECT client_id, name, client_number FROM ib_clients ORDER BY name LIMIT 500');
while ($r && ($row = $r->fetch_assoc())) { $clients[] = $row; }
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
            <div class="col-sm-6"><h1 class="rb-static">AI Desk</h1></div>
            <div class="col-sm-6">
              <ol class="breadcrumb float-sm-right">
                <li class="breadcrumb-item"><a href="pages_dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active">AI Desk</li>
              </ol>
            </div>
          </div>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <div class="alert alert-info"><i class="fas fa-robot mr-1"></i>
            AI text can contain mistakes. <b>Always review it before using it.</b> Only anonymised statistics are sent to the AI: never names, phone numbers, ID numbers or account numbers.</div>
          <div class="row">
            <div class="col-lg-6">
              <div class="card">
                <div class="card-header"><h3 class="card-title">Customer summary</h3></div>
                <div class="card-body">
                  <div class="form-group">
                    <label for="cust">Customer</label>
                    <select id="cust" class="form-control">
                      <option value="">Choose a customer...</option>
                      <?php foreach ($clients as $c) { ?>
                        <option value="<?php echo (int) $c['client_id']; ?>"><?php echo rb_e($c['name'] . ' (' . $c['client_number'] . ')'); ?></option>
                      <?php } ?>
                    </select>
                  </div>
                  <button type="button" id="btnSummary" class="btn btn-success"><i class="fas fa-magic mr-1"></i> Summarise last 90 days</button>
                  <pre id="outSummary" class="rb-ai-out mt-3" hidden></pre>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card">
                <div class="card-header"><h3 class="card-title">Notice drafter</h3></div>
                <div class="card-body">
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label for="kind">Notice type</label>
                      <select id="kind" class="form-control">
                        <option value="welcome">Welcome new customer</option>
                        <option value="low_balance">Low balance reminder</option>
                        <option value="activation">Account activated</option>
                        <option value="kyc">KYC documents reminder</option>
                        <option value="suspicious">Confirm a recent transaction</option>
                        <option value="general">General notice</option>
                      </select>
                    </div>
                    <div class="form-group col-md-6">
                      <label for="format">Format</label>
                      <select id="format" class="form-control"><option value="email">Email</option><option value="letter">Letter</option></select>
                    </div>
                  </div>
                  <div class="form-group">
                    <label for="note">Extra note (optional, no personal details)</label>
                    <input type="text" id="note" class="form-control" maxlength="400" placeholder="e.g. Please visit before 30th of this month">
                  </div>
                  <button type="button" id="btnDraft" class="btn btn-success"><i class="fas fa-pen mr-1"></i> Draft notice</button>
                  <button type="button" id="btnCopy" class="btn btn-outline-success ml-1" hidden><i class="fas fa-copy mr-1"></i> Copy</button>
                  <pre id="outDraft" class="rb-ai-out mt-3" hidden></pre>
                </div>
              </div>
            </div>
          </div>
          <p class="text-muted small">Output language follows the language chosen with the &#127760; button (bottom right).</p>
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
      function lang() { try { return localStorage.getItem('rb_language') || 'en'; } catch (e) { return 'en'; } }
      function call(payload, btn, out) {
        var label = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Working\u2026';
        payload.lang = lang();
        fetch('../api/staff_ai.php', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(payload) })
          .then(function (r) { return r.json(); })
          .then(function (d) { out.hidden = false; out.textContent = d.text || d.error || 'No response.'; })
          .catch(function () { out.hidden = false; out.textContent = 'Network problem. Please try again.'; })
          .then(function () { btn.disabled = false; btn.innerHTML = label; });
      }
      document.getElementById('btnSummary').addEventListener('click', function () {
        var id = document.getElementById('cust').value;
        if (!id) { alert('Choose a customer first.'); return; }
        call({ action: 'customer_summary', client_id: parseInt(id, 10) }, this, document.getElementById('outSummary'));
      });
      document.getElementById('btnDraft').addEventListener('click', function () {
        var copy = document.getElementById('btnCopy');
        call({ action: 'draft_notice', kind: document.getElementById('kind').value, format: document.getElementById('format').value,
               note: document.getElementById('note').value }, this, document.getElementById('outDraft'));
        copy.hidden = false;
      });
      document.getElementById('btnCopy').addEventListener('click', function () {
        var t = document.getElementById('outDraft').textContent;
        if (navigator.clipboard) navigator.clipboard.writeText(t);
      });
    })();
  </script>
</body>

</html>
