<?php
session_start();
include('conf/config.php');

if (!empty($_SESSION['admin_id'])) {
    header('Location: pages_dashboard.php');
    exit;
}

$err = '';
if (isset($_POST['login'])) {
    list($ok, $msg) = rb_attempt_login(
        $mysqli,
        'admin',
        isset($_POST['email']) ? $_POST['email'] : '',
        isset($_POST['password']) ? $_POST['password'] : ''
    );
    if ($ok) {
        header('Location: pages_dashboard.php');
        exit;
    }
    $err = $msg;
}

rb_auth_open(array(
    'portal'   => 'admin',
    'icon'     => 'fa-user-shield',
    'title'    => 'Administrator sign in',
    'subtitle' => 'Manage staff, customers and bank-wide settings.',
    'error'    => $err,
));
?>
      <form method="post" class="rb-form" autocomplete="on">
        <label class="rb-field">
          <span>Email address</span>
          <div class="rb-input">
            <i class="fas fa-envelope"></i>
            <input type="email" name="email" maxlength="190" value="<?php echo rb_e(isset($_POST['email']) ? $_POST['email'] : ''); ?>" placeholder="you@example.com" required autofocus autocomplete="username">
          </div>
        </label>
        <label class="rb-field">
          <span>Password</span>
          <div class="rb-input">
            <i class="fas fa-lock"></i>
            <input type="password" id="pw" name="password" maxlength="4096" placeholder="Enter your password" required autocomplete="current-password">
            <button type="button" class="rb-eye" data-toggle-pass="pw" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
          </div>
        </label>
        <button type="submit" name="login" class="rb-btn rb-btn--primary rb-btn--block">Sign in <i class="fas fa-arrow-right"></i></button>
      </form>
<?php
rb_auth_close(array(
    array('Forgot password?', 'pages_reset_pwd.php'),
    array('Back to home', '../index.php'),
));
