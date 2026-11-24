<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
require_once __DIR__ . '/../core/credit.php';
check_login();

$client_id = (int) $_SESSION['client_id'];
$err = '';
$success = '';
$profile = null;
$history = array();
$tables_ready = true;
$fields = array(
    'monthly_income',
    'monthly_obligations',
    'credit_limit',
    'credit_used',
    'on_time_pct',
    'history_months',
    'recent_inquiries',
);
$form_values = array_fill_keys($fields, '');

if (isset($_SESSION['rb_credit_flash'])) {
    $success = (string) $_SESSION['rb_credit_flash'];
    unset($_SESSION['rb_credit_flash']);
}

try {
    $stmt = $mysqli->prepare('SELECT monthly_income, monthly_obligations, credit_limit, credit_used, on_time_pct, history_months, recent_inquiries FROM ib_credit_profiles WHERE client_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit profile query.');
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read credit profile.');
    }
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $mysqli->prepare('SELECT score, created_at FROM ib_credit_score_history WHERE client_id = ? ORDER BY created_at DESC, history_id DESC LIMIT 12');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare credit history query.');
    }
    $stmt->bind_param('i', $client_id);
    if (!$stmt->execute()) {
        throw new RuntimeException('Could not read credit score history.');
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
    $history = array_reverse($history);
} catch (Throwable $e) {
    $tables_ready = false;
    $profile = null;
    $history = array();
    $err = 'Credit Health is not ready. Import database/credit-simulation-migration.sql, then reload this page.';
    error_log('[RuralBanking] credit profile read failed: ' . $e->getMessage());
}

if ($profile !== null) {
    foreach ($fields as $field) {
        $form_values[$field] = (string) $profile[$field];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_credit_profile'])) {
    foreach ($fields as $field) {
        $form_values[$field] = isset($_POST[$field]) && is_scalar($_POST[$field])
            ? trim((string) $_POST[$field])
            : '';
    }

    if ($tables_ready) {
        $problem = null;
        $candidate = rb_credit_profile_parse($_POST, $problem);
        if ($candidate === null) {
            $err = $problem;
        } else {
            $transaction_started = false;
            try {
                if (!$mysqli->begin_transaction()) {
                    throw new RuntimeException('Could not start credit profile transaction.');
                }
                $transaction_started = true;

                $stmt = $mysqli->prepare('SELECT monthly_income, monthly_obligations, credit_limit, credit_used, on_time_pct, history_months, recent_inquiries FROM ib_credit_profiles WHERE client_id = ? FOR UPDATE');
                if (!$stmt) {
                    throw new RuntimeException('Could not prepare credit profile lock.');
                }
                $stmt->bind_param('i', $client_id);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not lock credit profile.');
                }
                $current = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $changed = $current === null || !rb_credit_profiles_equal($current, $candidate);

                $stmt = $mysqli->prepare(
                    'INSERT INTO ib_credit_profiles (client_id, monthly_income, monthly_obligations, credit_limit, credit_used, on_time_pct, history_months, recent_inquiries) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE monthly_income = VALUES(monthly_income), monthly_obligations = VALUES(monthly_obligations), '
                    . 'credit_limit = VALUES(credit_limit), credit_used = VALUES(credit_used), on_time_pct = VALUES(on_time_pct), '
                    . 'history_months = VALUES(history_months), recent_inquiries = VALUES(recent_inquiries)'
                );
                if (!$stmt) {
                    throw new RuntimeException('Could not prepare credit profile save.');
                }
                $stmt->bind_param(
                    'issssiii',
                    $client_id,
                    $candidate['monthly_income'],
                    $candidate['monthly_obligations'],
                    $candidate['credit_limit'],
                    $candidate['credit_used'],
                    $candidate['on_time_pct'],
                    $candidate['history_months'],
                    $candidate['recent_inquiries']
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not save credit profile.');
                }
                $stmt->close();

                if ($changed) {
                    $score = rb_credit_score($candidate);
                    $stmt = $mysqli->prepare('INSERT INTO ib_credit_score_history (client_id, score) VALUES (?, ?)');
                    if (!$stmt) {
                        throw new RuntimeException('Could not prepare credit history save.');
                    }
                    $stmt->bind_param('ii', $client_id, $score);
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Could not save credit score history.');
                    }
                    $stmt->close();
                }

                if (!$mysqli->commit()) {
                    throw new RuntimeException('Could not commit credit profile.');
                }
                $transaction_started = false;
                $_SESSION['rb_credit_flash'] = $changed
                    ? 'Your credit profile and score history have been saved.'
                    : 'Your credit profile is unchanged; no duplicate history point was added.';
                header('Location: pages_credit_health.php', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($transaction_started) {
                    $mysqli->rollback();
                }
                $err = 'Could not save your credit profile right now. Please try again later.';
                error_log('[RuralBanking] credit profile save failed: ' . $e->getMessage());
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_credit_profile']) && $tables_ready) {
    $transaction_started = false;
    try {
        if (!$mysqli->begin_transaction()) {
            throw new RuntimeException('Could not start credit profile deletion.');
        }
        $transaction_started = true;
        $stmt = $mysqli->prepare('DELETE FROM ib_credit_score_history WHERE client_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare credit history deletion.');
        }
        $stmt->bind_param('i', $client_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not delete credit history.');
        }
        $stmt->close();

        $stmt = $mysqli->prepare('DELETE FROM ib_credit_profiles WHERE client_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Could not prepare credit profile deletion.');
        }
        $stmt->bind_param('i', $client_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not delete credit profile.');
        }
        $stmt->close();

        if (!$mysqli->commit()) {
            throw new RuntimeException('Could not commit credit profile deletion.');
        }
        $transaction_started = false;
        $_SESSION['rb_credit_flash'] = 'Your self-reported credit profile and score history have been deleted.';
        header('Location: pages_credit_health.php', true, 303);
        exit;
    } catch (Throwable $e) {
        if ($transaction_started) {
            $mysqli->rollback();
        }
        $err = 'Could not delete your credit profile right now. Please try again later.';
        error_log('[RuralBanking] credit profile delete failed: ' . $e->getMessage());
    }
}

$score = $profile !== null ? rb_credit_score($profile) : null;
$utilization = $profile !== null ? rb_credit_utilization_percent($profile) : null;
$obligation_ratio = $profile !== null ? rb_credit_obligation_ratio($profile) : null;
$recommendations = $profile !== null ? rb_credit_recommendations($profile) : array();
$history_labels = array();
$history_scores = array();
foreach ($history as $point) {
    $history_labels[] = date('M j, H:i', strtotime($point['created_at']));
    $history_scores[] = (int) $point['score'];
}
$score_percent = $score === null ? 0 : (int) round(($score - 300) / 6);
$simulator_data = array(
    'hasProfile' => $profile !== null,
    'score' => $score,
    'onTimePct' => $profile !== null ? (int) $profile['on_time_pct'] : 0,
    'utilizationPct' => $utilization,
    'hasCreditLimit' => $profile !== null && (float) $profile['credit_limit'] > 0,
    'historyMonths' => $profile !== null ? (int) $profile['history_months'] : 0,
    'recentInquiries' => $profile !== null ? (int) $profile['recent_inquiries'] : 0,
);
?>
<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=utf-8" />
<?php include("dist/_partials/head.php"); ?>

<body class="hold-transition sidebar-mini layout-fixed layout-navbar-fixed">
  <div class="wrapper">
    <?php include("dist/_partials/nav.php"); ?>
    <?php include("dist/_partials/sidebar.php"); ?>

    <div class="content-wrapper">
      <section class="content-header rb-credit-noprint">
        <div class="container-fluid">
          <div class="row align-items-center mb-2">
            <div class="col-sm-8">
              <h1 class="rb-static">Credit Health</h1>
              <p class="text-muted mb-0">Review your reported profile and score trends. <a href="../service_information.php">Service Information</a></p>
            </div>
            <div class="col-sm-4 text-sm-right">
              <?php if ($profile !== null) { ?>
                <button type="button" class="btn btn-success" onclick="window.print()">
                  <i class="fas fa-file-pdf mr-1"></i> Save report as PDF
                </button>
                <small class="d-block text-muted mt-1">Choose “Save as PDF” in your browser’s print dialog.</small>
              <?php } ?>
            </div>
          </div>
        </div>
      </section>

      <section class="content">
        <div class="container-fluid">
          <?php if ($err !== '') { ?>
            <div class="alert alert-danger" role="alert"><?php echo rb_e($err); ?></div>
          <?php } elseif ($success !== '') { ?>
            <div class="alert alert-success rb-credit-noprint" role="status"><?php echo rb_e($success); ?></div>
          <?php } ?>

          <?php if ($profile !== null) { ?>
            <div class="rb-credit-report">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h4 mb-0">Credit profile summary</h2>
                <span class="text-muted small">Prepared <?php echo rb_e(date('d M Y')); ?></span>
              </div>
              <p class="rb-credit-report-note">The score estimate is based on the information you reported and the factors below.</p>

              <div class="row">
                <div class="col-lg-4">
                  <div class="card rb-credit-score-card">
                    <div class="card-body">
                      <p class="text-muted mb-1">Score estimate (range 300–900)</p>
                      <div class="rb-credit-score"><?php echo rb_e($score); ?></div>
                      <div class="progress rb-credit-score-progress" role="progressbar" aria-label="Estimated score position" aria-valuenow="<?php echo rb_e($score_percent); ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?php echo rb_e($score_percent); ?>%"></div>
                      </div>
                      <p class="small text-muted mt-3 mb-0">Calculated from your reported profile using the factors shown.</p>
                    </div>
                  </div>
                </div>
                <div class="col-lg-8">
                  <div class="row">
                    <div class="col-md-4 col-6">
                      <div class="info-box">
                        <span class="info-box-icon bg-success"><i class="fas fa-calendar-check"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Reported on-time payments</span><span class="info-box-number"><?php echo rb_e((int) $profile['on_time_pct']); ?>%</span></div>
                      </div>
                    </div>
                    <div class="col-md-4 col-6">
                      <div class="info-box">
                        <span class="info-box-icon bg-info"><i class="fas fa-chart-pie"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Credit utilization</span><span class="info-box-number"><?php echo $utilization === null ? 'Not reported' : rb_e(number_format($utilization, 1) . '%'); ?></span></div>
                      </div>
                    </div>
                    <div class="col-md-4 col-12">
                      <div class="info-box">
                        <span class="info-box-icon bg-warning"><i class="fas fa-balance-scale"></i></span>
                        <div class="info-box-content"><span class="info-box-text">Monthly obligations / income</span><span class="info-box-number"><?php echo $obligation_ratio === null ? 'Not reported' : rb_e(number_format($obligation_ratio, 1) . '%'); ?></span></div>
                      </div>
                    </div>
                  </div>
                  <div class="card">
                    <div class="card-header"><h3 class="card-title">Factors used in this estimate</h3></div>
                    <div class="card-body p-0">
                      <div class="table-responsive">
                        <table class="table table-sm mb-0">
                          <tbody>
                            <tr><th scope="row">Monthly income (self-reported)</th><td class="text-right"><?php echo rb_e(rb_money($profile['monthly_income'])); ?></td></tr>
                            <tr><th scope="row">Monthly debt payments (self-reported)</th><td class="text-right"><?php echo rb_e(rb_money($profile['monthly_obligations'])); ?></td></tr>
                            <tr><th scope="row">Credit limit / balance (self-reported)</th><td class="text-right"><?php echo rb_e(rb_money($profile['credit_limit'])); ?> / <?php echo rb_e(rb_money($profile['credit_used'])); ?></td></tr>
                            <tr><th scope="row">Credit history</th><td class="text-right"><?php echo rb_e((int) $profile['history_months']); ?> months</td></tr>
                            <tr><th scope="row">Recent credit enquiries</th><td class="text-right"><?php echo rb_e((int) $profile['recent_inquiries']); ?></td></tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row">
                <div class="col-lg-7">
                  <div class="card">
                    <div class="card-header"><h3 class="card-title">Score history</h3></div>
                    <div class="card-body">
                      <?php if ($history) { ?>
                        <div class="rb-credit-history-chart"><canvas id="rbCreditHistory" role="img" aria-label="Graph of estimated credit scores over time"></canvas></div>
                        <p class="small text-muted mb-0">Each point reflects a saved score calculation.</p>
                      <?php } else { ?>
                        <p class="rb-chart-empty mb-0" role="status">No saved score history yet.</p>
                      <?php } ?>
                    </div>
                  </div>
                </div>
                <div class="col-lg-5">
                  <div class="card">
                    <div class="card-header"><h3 class="card-title">Credit health recommendations</h3></div>
                    <div class="card-body">
                      <ul class="rb-credit-recommendations mb-0">
                        <?php foreach ($recommendations as $recommendation) { ?>
                          <li><?php echo rb_e($recommendation); ?></li>
                        <?php } ?>
                      </ul>
                      <p class="small text-muted mt-3 mb-0">General education only. Your actual lender or bureau may assess factors differently.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php } else { ?>
            <div class="card rb-credit-noprint">
              <div class="card-body text-center py-4">
                <i class="fas fa-chart-line rb-credit-intro-icon" aria-hidden="true"></i>
                <h2 class="h4 mt-2">Start with your own reported figures</h2>
                <p class="text-muted mb-0">Your score estimate and history appear here after you save a profile.</p>
              </div>
            </div>
          <?php } ?>

          <div class="card rb-credit-controls">
            <div class="card-header"><h3 class="card-title">Your self-reported credit profile</h3></div>
            <div class="card-body">
              <p class="text-muted">Enter figures from your own records. Use 0 if a field does not apply. Your profile is saved to your customer account for future reviews.</p>
              <form method="post" action="pages_credit_health.php">
                <div class="row">
                  <div class="form-group col-md-6">
                    <label for="monthly_income">Monthly income (₹)</label>
                    <input class="form-control" id="monthly_income" name="monthly_income" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo rb_e($form_values['monthly_income']); ?>" required>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="monthly_obligations">Monthly debt payments / EMIs (₹)</label>
                    <input class="form-control" id="monthly_obligations" name="monthly_obligations" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo rb_e($form_values['monthly_obligations']); ?>" required>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="credit_limit">Total revolving credit limit (₹)</label>
                    <input class="form-control" id="credit_limit" name="credit_limit" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo rb_e($form_values['credit_limit']); ?>" required>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="credit_used">Current balance on that credit (₹)</label>
                    <input class="form-control" id="credit_used" name="credit_used" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo rb_e($form_values['credit_used']); ?>" required>
                  </div>
                  <div class="form-group col-md-4">
                    <label for="on_time_pct">Payments made on time (%)</label>
                    <input class="form-control" id="on_time_pct" name="on_time_pct" type="number" min="0" max="100" step="1" value="<?php echo rb_e($form_values['on_time_pct']); ?>" required>
                  </div>
                  <div class="form-group col-md-4">
                    <label for="history_months">Credit history (months)</label>
                    <input class="form-control" id="history_months" name="history_months" type="number" min="0" max="600" step="1" value="<?php echo rb_e($form_values['history_months']); ?>" required>
                  </div>
                  <div class="form-group col-md-4">
                    <label for="recent_inquiries">Credit enquiries in last 6 months</label>
                    <input class="form-control" id="recent_inquiries" name="recent_inquiries" type="number" min="0" max="20" step="1" value="<?php echo rb_e($form_values['recent_inquiries']); ?>" required>
                  </div>
                </div>
                <button class="btn btn-primary" type="submit" name="save_credit_profile" value="1">
                  <i class="fas fa-save mr-1"></i> Save and update estimate
                </button>
              </form>
              <?php if ($profile !== null) { ?>
                <form method="post" action="pages_credit_health.php" class="mt-3">
                  <button class="btn btn-outline-danger btn-sm" type="submit" name="delete_credit_profile" value="1" onclick="return confirm('Delete your saved credit profile and score history?')">
                    Delete my saved credit data
                  </button>
                </form>
              <?php } ?>
            </div>
          </div>

          <div class="row rb-credit-controls">
            <div class="col-lg-6">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Loan affordability calculator</h3></div>
                <div class="card-body">
                  <p class="text-muted">Estimate monthly payments and an affordability range from the values entered.</p>
                  <div class="row">
                    <div class="form-group col-sm-6">
                      <label for="loan_income">Monthly income (₹)</label>
                      <input class="form-control" id="loan_income" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo $profile !== null ? rb_e($profile['monthly_income']) : ''; ?>">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="loan_obligations">Existing monthly EMIs (₹)</label>
                      <input class="form-control" id="loan_obligations" type="number" min="0" max="999999999999.99" step="0.01" value="<?php echo $profile !== null ? rb_e($profile['monthly_obligations']) : ''; ?>">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="loan_amount">Loan amount (₹)</label>
                      <input class="form-control" id="loan_amount" type="number" min="1" max="999999999999.99" step="1000" value="100000">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="loan_rate">Annual interest rate (%)</label>
                      <input class="form-control" id="loan_rate" type="number" min="0" max="60" step="0.1" value="12">
                    </div>
                    <div class="form-group col-sm-6">
                      <label for="loan_term">Loan term (years)</label>
                      <input class="form-control" id="loan_term" type="number" min="0.5" max="30" step="0.5" value="3">
                    </div>
                  </div>
                  <div id="loanEstimate" class="rb-credit-estimate" aria-live="polite"></div>
                  <p id="loanAffordabilityNote" class="small mb-0" aria-live="polite"></p>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="card h-100">
                <div class="card-header"><h3 class="card-title">Score scenario planner</h3></div>
                <div class="card-body">
                  <?php if ($profile !== null) { ?>
                    <p class="text-muted">Explore a hypothetical change to the reported factors. This does not change your saved profile.</p>
                    <div class="form-group">
                      <label for="simPayment">Target on-time payments: <span id="simPaymentValue"></span></label>
                      <input class="custom-range" id="simPayment" type="range" min="0" max="100" step="1" value="<?php echo rb_e((int) $profile['on_time_pct']); ?>">
                    </div>
                    <div class="form-group">
                      <label for="simUtilization">Target credit utilization: <span id="simUtilizationValue"></span></label>
                      <input class="custom-range" id="simUtilization" type="range" min="0" max="100" step="1" value="<?php echo $utilization === null ? '0' : rb_e((int) min(100, round($utilization))); ?>" <?php echo $utilization === null ? 'disabled' : ''; ?>>
                      <?php if ($utilization === null) { ?><small class="form-text text-muted">Enter a credit limit to estimate utilization changes.</small><?php } ?>
                    </div>
                    <div class="rb-credit-projection" aria-live="polite">
                      <span class="text-muted">Projected model score</span>
                      <strong id="simScore"></strong>
                      <span id="simDelta"></span>
                    </div>
                  <?php } else { ?>
                    <p class="text-muted mb-0">Save a self-reported profile to explore how different payment and utilization assumptions change the model estimate.</p>
                  <?php } ?>
                </div>
              </div>
            </div>
          </div>

          <p class="small text-muted rb-credit-noprint mt-3">For score methodology and service scope, see <a href="../service_information.php#services">Service Information</a>.</p>
        </div>
      </section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>

  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
  <script src="plugins/chart.js/Chart.min.js"></script>
  <script>
    (function () {
      var credit = <?php echo json_encode($simulator_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
      var historyLabels = <?php echo json_encode($history_labels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
      var historyScores = <?php echo json_encode($history_scores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
      var historyCanvas = document.getElementById('rbCreditHistory');

      if (historyCanvas && window.Chart) {
        new Chart(historyCanvas.getContext('2d'), {
          type: 'line',
          data: {
            labels: historyLabels,
            datasets: [{
              label: 'Estimated score',
              data: historyScores,
              borderColor: '#1f6f4f',
              backgroundColor: 'rgba(31,111,79,.12)',
              pointBackgroundColor: '#d99a22',
              pointBorderColor: '#ffffff',
              pointRadius: 4,
              borderWidth: 3,
              fill: true,
              lineTension: 0.25
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            legend: { display: false },
            scales: {
              yAxes: [{ ticks: { min: 300, max: 900, stepSize: 100 }, gridLines: { color: '#e6dfce' } }],
              xAxes: [{ gridLines: { display: false } }]
            }
          }
        });
      }

      function readNumber(id) {
        var element = document.getElementById(id);
        var value = element ? Number(element.value) : NaN;
        return isFinite(value) && value >= 0 ? value : NaN;
      }
      function money(value) {
        return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);
      }

      var loanInputs = ['loan_income', 'loan_obligations', 'loan_amount', 'loan_rate', 'loan_term'];
      var loanEstimate = document.getElementById('loanEstimate');
      var loanNote = document.getElementById('loanAffordabilityNote');
      function updateLoanEstimate() {
        var income = readNumber('loan_income');
        var obligations = readNumber('loan_obligations');
        var principal = readNumber('loan_amount');
        var annualRate = readNumber('loan_rate');
        var years = readNumber('loan_term');
        if ([income, obligations, principal, annualRate, years].some(function(value) { return !isFinite(value); })
          || principal <= 0 || principal > 999999999999.99
          || income > 999999999999.99 || obligations > 999999999999.99
          || years < 0.5 || years > 30 || annualRate > 60) {
          loanEstimate.textContent = 'Enter valid non-negative values to calculate an estimate.';
          loanNote.textContent = '';
          return;
        }

        var months = Math.max(1, Math.round(years * 12));
        var monthlyRate = annualRate / 1200;
        var growth = Math.pow(1 + monthlyRate, months);
        var emi = monthlyRate === 0 ? principal / months : principal * monthlyRate * growth / (growth - 1);
        var maxEmi = isFinite(income) && income > 0 ? Math.max(0, income * 0.40 - obligations) : 0;
        var eligiblePrincipal = monthlyRate === 0
          ? maxEmi * months
          : maxEmi * (growth - 1) / (monthlyRate * growth);
        var totalInterest = Math.max(0, emi * months - principal);

        loanEstimate.innerHTML = '<div><span>Estimated monthly payment</span><strong>' + money(emi) + '</strong></div>' +
          '<div><span>Estimated total interest</span><strong>' + money(totalInterest) + '</strong></div>' +
          '<div><span>Illustrative maximum amount for this term</span><strong>' + money(eligiblePrincipal) + '</strong></div>';
        if (income <= 0) {
          loanNote.textContent = 'Enter monthly income to estimate affordability using the 40% planning threshold.';
        } else {
          var ratio = ((obligations + emi) / income) * 100;
          loanNote.textContent = ratio > 40
            ? 'The requested payment exceeds the 40% planning threshold.'
            : 'The estimated payment is within the 40% planning threshold.';
        }
      }
      loanInputs.forEach(function (id) {
        var element = document.getElementById(id);
        if (element) element.addEventListener('input', updateLoanEstimate);
      });
      updateLoanEstimate();

      var paymentSlider = document.getElementById('simPayment');
      var utilizationSlider = document.getElementById('simUtilization');
      function estimateScore(paymentPct, utilizationPct) {
        var utilizationFactor = credit.hasCreditLimit
          ? 1 - Math.min(100, Math.max(0, utilizationPct)) / 100
          : 0.5;
        var paymentFactor = Math.min(100, Math.max(0, paymentPct)) / 100;
        var historyFactor = Math.min(1, Math.max(0, credit.historyMonths) / 120);
        var inquiryFactor = 1 - Math.min(10, Math.max(0, credit.recentInquiries)) / 10;
        return Math.max(300, Math.min(900, Math.round(300 + 600 * (
          0.40 * paymentFactor + 0.30 * utilizationFactor + 0.20 * historyFactor + 0.10 * inquiryFactor
        ))));
      }
      function updateSimulator() {
        if (!credit.hasProfile || !paymentSlider || !utilizationSlider) return;
        var payment = Number(paymentSlider.value);
        var utilization = credit.utilizationPct === null ? 0 : Number(utilizationSlider.value);
        var projected = estimateScore(payment, utilization);
        document.getElementById('simPaymentValue').textContent = payment + '%';
        document.getElementById('simUtilizationValue').textContent = credit.hasCreditLimit
          ? utilization + '%'
          : 'Not reported';
        document.getElementById('simScore').textContent = projected;
        var delta = projected - credit.score;
        var deltaElement = document.getElementById('simDelta');
        deltaElement.textContent = (delta > 0 ? '+' : '') + delta + ' points versus your saved estimate';
        deltaElement.className = delta > 0 ? 'text-success' : (delta < 0 ? 'text-danger' : 'text-muted');
      }
      if (paymentSlider) paymentSlider.addEventListener('input', updateSimulator);
      if (utilizationSlider) utilizationSlider.addEventListener('input', updateSimulator);
      updateSimulator();
    })();
  </script>
</body>
</html>
