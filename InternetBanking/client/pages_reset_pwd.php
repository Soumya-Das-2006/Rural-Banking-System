<?php
session_start();
include('conf/config.php');

rb_auth_open(array(
    'portal'   => 'client',
    'icon'     => 'fa-life-ring',
    'title'    => 'Need help signing in?',
    'subtitle' => 'For your security, passwords cannot be reset on this page.',
));
?>
      <div class="rb-help">
        <p>Your branch staff can verify your identity and set a temporary password for your account.</p>
        <ol>
          <li>Contact the person named above and confirm your identity.</li>
          <li>Sign in with the temporary password they give you.</li>
          <li>Change it straight away from <strong>My Profile</strong>.</li>
        </ol>
      </div>
      <a class="rb-btn rb-btn--primary rb-btn--block" href="pages_client_index.php"><i class="fas fa-arrow-left"></i> Back to sign in</a>
<?php
rb_auth_close(array());
