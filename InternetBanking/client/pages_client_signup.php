<?php
session_start();
include('conf/config.php');

$err = '';
$success = '';
$v = array('name' => '', 'national_id' => '', 'phone' => '', 'email' => '', 'address' => '');

if (isset($_POST['create_account'])) {
    if (!rb_spam_honeypot_is_clear($_POST)) {
        $err = 'We could not verify this registration request. Please try again.';
    } else {
        try {
            if (!rb_public_form_allowed($mysqli, 'signup', 5)) {
                $err = 'Too many registration attempts. Please wait and try again later.';
            } else {
                foreach ($v as $k => $_) {
                    $v[$k] = isset($_POST[$k]) && is_scalar($_POST[$k]) ? trim((string) $_POST[$k]) : '';
                }
                $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
                $confirm  = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                if (($problem = rb_client_details_problem($v['name'], $v['national_id'], $v['phone'], $v['email'], $v['address'])) !== null) {
                    $err = $problem;
                } elseif (($problem = rb_password_problem($password)) !== null) {
                    $err = $problem;
                } elseif ($password !== $confirm) {
                    $err = 'The two passwords do not match.';
                } else {
                    $stmt = $mysqli->prepare('SELECT COUNT(*) FROM ib_clients WHERE email = ?');
                    $stmt->bind_param('s', $v['email']);
                    $stmt->execute();
                    $stmt->bind_result($exists);
                    $stmt->fetch();
                    $stmt->close();

                    if ($exists > 0) {
                        $err = 'An account with this email already exists. Try signing in instead.';
                    } else {
                        $client_number = 'RB-CLIENT-' . random_int(100000, 999999);
                        $hash = rb_hash_password($password);
                        $stmt = $mysqli->prepare('INSERT INTO ib_clients (name, national_id, client_number, phone, email, password, address) VALUES (?,?,?,?,?,?,?)');
                        $stmt->bind_param('sssssss', $v['name'], $v['national_id'], $client_number, $v['phone'], $v['email'], $hash, $v['address']);
                        if ($stmt->execute()) {
                            try {
                                rb_record_user_activity($mysqli, 'client', (int) $mysqli->insert_id, 'account_registration', __FILE__, 'POST');
                            } catch (Throwable $activity_error) {
                                error_log('[RuralBanking] registration activity unavailable: ' . $activity_error->getMessage());
                            }
                            $success = 'Your account has been created. You can sign in now.';
                            $v = array('name' => '', 'national_id' => '', 'phone' => '', 'email' => '', 'address' => '');
                        } else {
                            $err = 'We could not create your account. Please try again.';
                        }
                        $stmt->close();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[RuralBanking] account registration protection failed: ' . $e->getMessage());
            $err = 'Registration is temporarily unavailable. Please try again later.';
        }
    }
}

rb_auth_open(array(
    'portal'   => 'client',
    'icon'     => 'fa-user-plus',
    'title'    => 'Open your account',
    'subtitle' => 'It takes a minute. Once you sign in you can open a bank account from your dashboard.',
    'error'    => $err,
    'success'  => $success,
));
?>
      <form method="post" class="rb-form" autocomplete="on">
        <div class="rb-honeypot" aria-hidden="true"><label>Leave this field empty<input type="text" name="_rb_contact" tabindex="-1" autocomplete="off"></label></div>
        <label class="rb-field">
          <span>Full name</span>
          <div class="rb-input"><i class="fas fa-user"></i>
            <input type="text" name="name" maxlength="100" value="<?php echo rb_e($v['name']); ?>" placeholder="As on your ID" required autocomplete="name"></div>
        </label>
        <div class="rb-row">
          <label class="rb-field">
            <span>Aadhaar number</span>
            <div class="rb-input"><i class="fas fa-id-card"></i>
              <input type="text" name="national_id" inputmode="numeric" minlength="12" maxlength="12" pattern="[2-9][0-9]{11}" value="<?php echo rb_e($v['national_id']); ?>" placeholder="12-digit Aadhaar number" title="Enter a valid 12-digit Aadhaar number" autocomplete="off" required></div>
          </label>
          <label class="rb-field">
            <span>Phone</span>
            <div class="rb-input"><i class="fas fa-phone"></i>
              <input type="tel" name="phone" maxlength="17" value="<?php echo rb_e($v['phone']); ?>" placeholder="Mobile number" required autocomplete="tel"></div>
          </label>
        </div>
        <label class="rb-field">
          <span>Email address</span>
          <div class="rb-input"><i class="fas fa-envelope"></i>
            <input type="email" name="email" maxlength="190" value="<?php echo rb_e($v['email']); ?>" placeholder="you@example.com" required autocomplete="email"></div>
        </label>
        <label class="rb-field">
          <span>Address</span>
          <div class="rb-input"><i class="fas fa-map-marker-alt"></i>
            <input type="text" name="address" maxlength="250" value="<?php echo rb_e($v['address']); ?>" placeholder="Village / town, district" required autocomplete="street-address"></div>
        </label>
        <div class="rb-row">
          <label class="rb-field">
            <span>Password</span>
            <div class="rb-input"><i class="fas fa-lock"></i>
              <input type="password" id="pw1" name="password" maxlength="4096" placeholder="Min 8 characters" required autocomplete="new-password">
              <button type="button" class="rb-eye" data-toggle-pass="pw1" aria-label="Show or hide password"><i class="fas fa-eye"></i></button></div>
          </label>
          <label class="rb-field">
            <span>Confirm password</span>
            <div class="rb-input"><i class="fas fa-lock"></i>
              <input type="password" id="pw2" name="confirm_password" maxlength="4096" placeholder="Repeat password" required autocomplete="new-password">
              <button type="button" class="rb-eye" data-toggle-pass="pw2" aria-label="Show or hide password"><i class="fas fa-eye"></i></button></div>
          </label>
        </div>
        <p class="rb-hint">Use at least 8 characters with a mix of letters and numbers.</p>
        <button type="submit" name="create_account" class="rb-btn rb-btn--primary rb-btn--block">Create account <i class="fas fa-arrow-right"></i></button>
      </form>
<?php
rb_auth_close(array(
    array('Already have an account? Sign in', 'pages_client_index.php'),
    array('Back to home', '../index.php'),
));
