<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
require_once __DIR__ . '/../core/testimonials.php';
check_login();

$admin_id = (int) $_SESSION['admin_id'];
$error = '';
$success = isset($_SESSION['rb_admin_testimonials_success']) ? (string) $_SESSION['rb_admin_testimonials_success'] : '';
unset($_SESSION['rb_admin_testimonials_success']);
$ready = true;
$rows = array();
$form_values = array(
    'testimonial_id' => '0',
    'quote_text' => '',
    'display_name' => '',
    'attribution' => '',
    'status' => 'draft',
    'sort_order' => '0',
);

try {
    $mysqli->query('SELECT testimonial_id FROM ib_testimonials LIMIT 0');
    $mysqli->query('SELECT audit_id FROM ib_audit_log LIMIT 0');
} catch (mysqli_sql_exception $e) {
    $ready = false;
    $error = 'Import database/update-all-migrations.sql to enable testimonial management.';
    error_log('[RuralBanking] admin testimonial tables are unavailable: ' . $e->getMessage());
}

$post_text = function ($key) {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
};

if ($ready && isset($_POST['hide_testimonial'])) {
    $testimonial_id = filter_var($post_text('hide_testimonial'), FILTER_VALIDATE_INT);
    if (!$testimonial_id || $testimonial_id < 1) {
        $error = 'Choose a valid testimonial to hide.';
    } else {
        try {
            $mysqli->begin_transaction();
            $check = $mysqli->prepare('SELECT status FROM ib_testimonials WHERE testimonial_id = ? FOR UPDATE');
            $check->bind_param('i', $testimonial_id);
            $check->execute();
            $check->bind_result($old_status);
            if (!$check->fetch()) {
                $check->close();
                throw new RuntimeException('The testimonial could not be found.');
            }
            $check->close();
            if ($old_status === 'hidden') {
                throw new RuntimeException('This testimonial is already hidden.');
            }

            $stmt = $mysqli->prepare("UPDATE ib_testimonials
                                      SET status = 'hidden', consent_confirmed = 0, consent_confirmed_by = NULL,
                                          consent_confirmed_at = NULL, updated_by = ?
                                      WHERE testimonial_id = ?");
            $stmt->bind_param('ii', $admin_id, $testimonial_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Could not hide the testimonial.');
            }
            $stmt->close();

            $actor_role = 'admin';
            $action = 'testimonial_hidden';
            $entity_type = 'testimonial';
            $entity_id = (string) $testimonial_id;
            $details = 'Customer testimonial hidden and publication consent cleared.';
            $audit = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details)
                                       VALUES (?, ?, ?, ?, ?, ?)');
            $audit->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $details);
            if (!$audit->execute()) {
                $audit->close();
                throw new RuntimeException('Could not record this testimonial change in the audit log.');
            }
            $audit->close();
            $mysqli->commit();
            $_SESSION['rb_admin_testimonials_success'] = 'Testimonial hidden and publication consent cleared.';
            header('Location: pages_testimonials.php');
            exit;
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollback_error) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not hide the testimonial.';
            error_log('[RuralBanking] admin testimonial hide failed: ' . $e->getMessage());
        }
    }
}

if ($ready && isset($_POST['save_testimonial'])) {
    $form_values = array(
        'testimonial_id' => $post_text('testimonial_id'),
        'quote_text' => $post_text('quote_text'),
        'display_name' => $post_text('display_name'),
        'attribution' => $post_text('attribution'),
        'status' => $post_text('status'),
        'sort_order' => $post_text('sort_order'),
    );
    $testimonial_id = filter_var($form_values['testimonial_id'], FILTER_VALIDATE_INT);
    $sort_order = filter_var($form_values['sort_order'], FILTER_VALIDATE_INT);
    $status = $form_values['status'];
    $quote_text = $form_values['quote_text'];
    $display_name = $form_values['display_name'];
    $attribution = $form_values['attribution'];
    $consent_confirmed = isset($_POST['consent_confirmed'])
        && is_string($_POST['consent_confirmed'])
        && $_POST['consent_confirmed'] === '1';

    if ($testimonial_id === false || $testimonial_id < 0) {
        $error = 'Choose a valid testimonial, status, and display order.';
    } elseif (($validation_error = rb_testimonial_input_error(
        $quote_text,
        $display_name,
        $attribution,
        $status,
        $sort_order,
        $consent_confirmed
    )) !== '') {
        $error = $validation_error;
    } else {
        try {
            $mysqli->begin_transaction();
            if ($testimonial_id > 0) {
                $check = $mysqli->prepare('SELECT testimonial_id FROM ib_testimonials WHERE testimonial_id = ? FOR UPDATE');
                $check->bind_param('i', $testimonial_id);
                $check->execute();
                $check->bind_result($existing_id);
                $exists = $check->fetch();
                $check->close();
                if (!$exists) {
                    throw new RuntimeException('The testimonial could not be found.');
                }

                $stmt = $mysqli->prepare("UPDATE ib_testimonials
                                          SET quote_text = ?, display_name = ?, attribution = ?, status = ?, sort_order = ?,
                                              consent_confirmed = 1, consent_confirmed_by = ?, consent_confirmed_at = CURRENT_TIMESTAMP(6),
                                              updated_by = ?
                                          WHERE testimonial_id = ?");
                $stmt->bind_param(
                    'ssssiiii',
                    $quote_text,
                    $display_name,
                    $attribution,
                    $status,
                    $sort_order,
                    $admin_id,
                    $admin_id,
                    $testimonial_id
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Could not update the testimonial.');
                }
                $stmt->close();
                $action = 'testimonial_updated';
            } else {
                $stmt = $mysqli->prepare("INSERT INTO ib_testimonials
                                          (quote_text, display_name, attribution, status, sort_order,
                                           consent_confirmed, consent_confirmed_by, consent_confirmed_at, created_by, updated_by)
                                          VALUES (?, ?, ?, ?, ?, 1, ?, CURRENT_TIMESTAMP(6), ?, ?)");
                $stmt->bind_param(
                    'ssssiiii',
                    $quote_text,
                    $display_name,
                    $attribution,
                    $status,
                    $sort_order,
                    $admin_id,
                    $admin_id,
                    $admin_id
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Could not save the testimonial.');
                }
                $testimonial_id = (int) $mysqli->insert_id;
                $stmt->close();
                $action = 'testimonial_created';
            }

            $actor_role = 'admin';
            $entity_type = 'testimonial';
            $entity_id = (string) $testimonial_id;
            $details = 'Customer testimonial saved with status ' . $status . '; publication consent confirmed.';
            $audit = $mysqli->prepare('INSERT INTO ib_audit_log (actor_role, actor_id, action, entity_type, entity_id, details)
                                       VALUES (?, ?, ?, ?, ?, ?)');
            $audit->bind_param('sissss', $actor_role, $admin_id, $action, $entity_type, $entity_id, $details);
            if (!$audit->execute()) {
                $audit->close();
                throw new RuntimeException('Could not record this testimonial change in the audit log.');
            }
            $audit->close();
            $mysqli->commit();
            $_SESSION['rb_admin_testimonials_success'] = 'Testimonial saved and recorded in the admin audit log.';
            header('Location: pages_testimonials.php');
            exit;
        } catch (Throwable $e) {
            try {
                $mysqli->rollback();
            } catch (Throwable $rollback_error) {
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not save the testimonial.';
            error_log('[RuralBanking] admin testimonial save failed: ' . $e->getMessage());
        }
    }
}

if ($ready) {
    try {
        $result = $mysqli->query("SELECT testimonial_id, quote_text, display_name, attribution, status, sort_order,
                                         consent_confirmed, created_at, updated_at
                                  FROM ib_testimonials
                                  ORDER BY FIELD(status, 'published', 'draft', 'hidden'), sort_order ASC, updated_at DESC
                                  LIMIT 100");
        while ($row = $result->fetch_assoc()) {
            if ((string) $row['testimonial_id'] === $form_values['testimonial_id']) {
                foreach (array('quote_text', 'display_name', 'attribution', 'status', 'sort_order') as $field) {
                    $row[$field] = $form_values[$field];
                }
            }
            $rows[] = $row;
        }
        $result->free();
    } catch (mysqli_sql_exception $e) {
        $error = 'Could not load testimonials. Please try again.';
        error_log('[RuralBanking] admin testimonial list failed: ' . $e->getMessage());
    }
}

$new_form_values = $form_values['testimonial_id'] === '0'
    ? $form_values
    : array('testimonial_id' => '0', 'quote_text' => '', 'display_name' => '', 'attribution' => '', 'status' => 'draft', 'sort_order' => '0');
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
      <section class="content-header">
        <div class="container-fluid">
          <h1>Customer Testimonials</h1>
          <p class="text-muted mb-0">Manage customer-approved stories for the public homepage. Only published testimonials with recorded permission are shown.</p>
        </div>
      </section>
      <section class="content">
        <div class="container-fluid">
          <?php if ($error !== '') { ?><div class="alert alert-danger" role="alert"><?php echo rb_e($error); ?></div><?php } ?>
          <?php if ($success !== '') { ?><div class="alert alert-success" role="status"><?php echo rb_e($success); ?></div><?php } ?>
          <?php if (!$ready) { ?>
            <div class="alert alert-warning" role="alert">Import <code>database/update-all-migrations.sql</code> before managing testimonials.</div>
          <?php } else { ?>
            <div class="alert alert-info" role="note">Do not enter account numbers or other financial details. Use only a customer-approved quote and attribution; set the status to Published when it is ready for the public homepage.</div>
            <div class="card">
              <div class="card-header"><h2 class="card-title">Add a testimonial</h2></div>
              <div class="card-body">
                <form method="post">
                  <input type="hidden" name="testimonial_id" value="0">
                  <div class="form-row">
                    <div class="form-group col-lg-8">
                      <label for="new-quote">Customer-approved quote</label>
                      <textarea class="form-control" id="new-quote" name="quote_text" rows="4" maxlength="1200" required><?php echo rb_e($new_form_values['quote_text']); ?></textarea>
                    </div>
                    <div class="form-group col-lg-4">
                      <label for="new-display-name">Public attribution</label>
                      <input class="form-control" id="new-display-name" name="display_name" maxlength="80" value="<?php echo rb_e($new_form_values['display_name']); ?>" placeholder="Customer name or approved initials" required>
                      <label for="new-attribution" class="mt-2">Optional location or description</label>
                      <input class="form-control" id="new-attribution" name="attribution" maxlength="120" value="<?php echo rb_e($new_form_values['attribution']); ?>" placeholder="Only if approved for publication">
                    </div>
                  </div>
                  <div class="form-row align-items-end">
                    <div class="form-group col-md-3">
                      <label for="new-status">Publication status</label>
                      <select class="form-control" id="new-status" name="status">
                        <option value="draft" <?php echo $new_form_values['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="published" <?php echo $new_form_values['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                      </select>
                    </div>
                    <div class="form-group col-md-3">
                      <label for="new-order">Display order</label>
                      <input class="form-control" id="new-order" type="number" name="sort_order" min="0" max="9999" value="<?php echo rb_e($new_form_values['sort_order']); ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                      <div class="form-check">
                        <input class="form-check-input" id="new-consent" type="checkbox" name="consent_confirmed" value="1" required>
                        <label class="form-check-label" for="new-consent">I confirm the customer explicitly approved public use of this quote and attribution.</label>
                      </div>
                    </div>
                  </div>
                  <button class="btn btn-primary" type="submit" name="save_testimonial" value="1">Save testimonial</button>
                </form>
              </div>
            </div>

            <div class="card">
              <div class="card-header"><h2 class="card-title">Saved testimonials (<?php echo count($rows); ?>)</h2></div>
              <div class="card-body">
                <?php foreach ($rows as $row) {
                    $badge = $row['status'] === 'published' ? 'success' : ($row['status'] === 'hidden' ? 'secondary' : 'warning');
                ?>
                  <div class="border rounded p-3 mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                      <strong><?php echo rb_e($row['display_name']); ?></strong>
                      <span class="badge badge-<?php echo $badge; ?>"><?php echo rb_e(ucfirst($row['status'])); ?></span>
                    </div>
                    <form method="post">
                      <input type="hidden" name="testimonial_id" value="<?php echo (int) $row['testimonial_id']; ?>">
                      <div class="form-row">
                        <div class="form-group col-lg-8">
                          <label for="quote-<?php echo (int) $row['testimonial_id']; ?>">Approved quote</label>
                          <textarea class="form-control" id="quote-<?php echo (int) $row['testimonial_id']; ?>" name="quote_text" rows="4" maxlength="1200" required><?php echo rb_e($row['quote_text']); ?></textarea>
                        </div>
                        <div class="form-group col-lg-4">
                          <label for="name-<?php echo (int) $row['testimonial_id']; ?>">Public attribution</label>
                          <input class="form-control" id="name-<?php echo (int) $row['testimonial_id']; ?>" name="display_name" maxlength="80" value="<?php echo rb_e($row['display_name']); ?>" required>
                          <label for="attribution-<?php echo (int) $row['testimonial_id']; ?>" class="mt-2">Optional location or description</label>
                          <input class="form-control" id="attribution-<?php echo (int) $row['testimonial_id']; ?>" name="attribution" maxlength="120" value="<?php echo rb_e($row['attribution']); ?>">
                        </div>
                      </div>
                      <div class="form-row align-items-end">
                        <div class="form-group col-md-3">
                          <label for="status-<?php echo (int) $row['testimonial_id']; ?>">Publication status</label>
                          <select class="form-control" id="status-<?php echo (int) $row['testimonial_id']; ?>" name="status">
                            <option value="draft" <?php echo in_array($row['status'], array('draft', 'hidden'), true) ? 'selected' : ''; ?>>Draft</option>
                            <option value="published" <?php echo $row['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                          </select>
                        </div>
                        <div class="form-group col-md-3">
                          <label for="order-<?php echo (int) $row['testimonial_id']; ?>">Display order</label>
                          <input class="form-control" id="order-<?php echo (int) $row['testimonial_id']; ?>" type="number" name="sort_order" min="0" max="9999" value="<?php echo (int) $row['sort_order']; ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                          <div class="form-check">
                            <input class="form-check-input" id="consent-<?php echo (int) $row['testimonial_id']; ?>" type="checkbox" name="consent_confirmed" value="1" required>
                            <label class="form-check-label" for="consent-<?php echo (int) $row['testimonial_id']; ?>">Confirm customer permission before saving or publishing.</label>
                          </div>
                        </div>
                      </div>
                      <button class="btn btn-primary" type="submit" name="save_testimonial" value="1">Save changes</button>
                      <small class="text-muted ml-2">Updated <?php echo rb_e($row['updated_at']); ?></small>
                    </form>
                    <?php if ($row['status'] !== 'hidden') { ?>
                      <form method="post" class="mt-2">
                        <button class="btn btn-sm btn-outline-danger" type="submit" name="hide_testimonial" value="<?php echo (int) $row['testimonial_id']; ?>">Hide and clear publication consent</button>
                      </form>
                    <?php } ?>
                  </div>
                <?php } ?>
                <?php if (!$rows) { ?><p class="text-muted mb-0">No testimonials have been entered. Add a customer-approved quote above when one is available.</p><?php } ?>
              </div>
            </div>
          <?php } ?>
        </div>
      </section>
    </div>
    <?php include("dist/_partials/footer.php"); ?>
  </div>
  <script src="plugins/jquery/jquery.min.js"></script>
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="dist/js/adminlte.min.js"></script>
</body>
</html>
