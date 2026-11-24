<aside class="main-sidebar sidebar-dark-primary rb-sidebar">
  <!-- Brand Logo 
    and load this 
    page with logged in user instance
    -->
  <?php
  $client_id = $_SESSION['client_id'];
  $ret = "SELECT * FROM  ib_clients  WHERE client_id = ? ";
  $stmt = $mysqli->prepare($ret);
  $stmt->bind_param('i', $client_id);
  $stmt->execute(); //ok
  $res = $stmt->get_result();
  while ($row = $res->fetch_object()) {
    //set automatically logged in user default image if they have not updated their pics
    if ($row->profile_pic == '') {
      $profile_picture = "<img src='../admin/dist/img/user_icon.png' class=' elevation-2' alt='User Image'>
                ";
    } else {
      $profile_picture = "<img src='../admin/dist/img/$row->profile_pic' class='elevation-2' alt='User Image'>
                ";
    }



    /* Persisit System Settings On Brand */
    $ret = "SELECT * FROM `ib_systemsettings` ";
    $stmt = $mysqli->prepare($ret);
    $stmt->execute(); //ok
    $res = $stmt->get_result();
    while ($sys = $res->fetch_object()) {
  ?>

      <?php $use_default_logo = in_array($sys->sys_logo, array('', 'rb-logo.svg', 'bank-of-soumya-logo.png', 'bank-of-soumya-logo-optimized.png'), true); ?>
      <a href="pages_dashboard.php" class="brand-link">
        <img src="<?php echo $use_default_logo ? '../assets/img/bank-of-soumya-logo-optimized.png' : '../admin/dist/img/' . rb_e($sys->sys_logo); ?>" alt="Bank Of Soumya" class="brand-image rb-brand-logo<?php echo $use_default_logo ? ' rb-brand-logo--wordmark' : ''; ?>" <?php if ($use_default_logo) { ?>width="1000" height="355"<?php } ?> decoding="async">
        <?php if (!$use_default_logo) { ?><span class="brand-text"><?php echo rb_e($sys->sys_name); ?></span><?php } ?>
      </a>

      <!-- Sidebar -->
      <div class="sidebar">
        <!-- Sidebar user panel (optional) -->
        <div class="user-panel mt-3 pb-3 mb-3 d-flex">
          <div class="image">
            <?php echo $profile_picture; ?>
          </div>
          <div class="info">
            <a href="#" class="d-block"><?php echo rb_e($row->name); ?></a>
          </div>
        </div>

        <!-- Sidebar Menu -->
        <nav class="mt-2">
          <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
            <!-- Add icons to the links using the .nav-icon class
               with font-awesome or any other icon font library -->

            <li class="nav-item has-treeview">
              <a href="pages_dashboard.php" class="nav-link">
                <i class="nav-icon fas fa-tachometer-alt"></i>
                <p>
                  Dashboard

                </p>
              </a>
            </li>
            <!-- ./DAshboard -->

            <!--Account -->
            <li class="nav-item">
              <a href="pages_account.php" class="nav-link">
                <i class="nav-icon fas fa-user-tie"></i>
                <p>
                  Account
                </p>
              </a>
            </li>
            <!-- ./Account-->

            <!--iBank Accounts-->
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link">
                <i class="nav-icon fas fa-briefcase"></i>
                <p>
                  Rural Bank Accounts
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">
                <li class="nav-item">
                  <a href="pages_open_acc.php" class="nav-link">
                    <i class="fas fa-lock-open nav-icon"></i>
                    <p>Open Rural Bank Acc</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_manage_acc_openings.php" class="nav-link">
                    <i class="fas fa-cog nav-icon"></i>
                    <p>My Rural Bank Accounts</p>
                  </a>
                </li>
              </ul>
            </li>
            <!--./ Rural Bank Accounts-->

            <!--Finances-->
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link">
                <i class="nav-icon fas fa-rupee-sign"></i>
                <p>
                  Finances
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">
                <li class="nav-item">
                  <a href="pages_send_money.php" class="nav-link">
                    <i class="fas fa-paper-plane nav-icon"></i>
                    <p>Send Money</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_deposits.php" class="nav-link">
                    <i class="fas fa-upload nav-icon"></i>
                    <p>Deposits</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_withdrawals.php" class="nav-link">
                    <i class="fas fa-download nav-icon"></i>
                    <p>Withdrawals</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_transfers.php" class="nav-link">
                    <i class="fas fa-random nav-icon"></i>
                    <p>Transfers</p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="pages_view_client_bank_acc.php" class="nav-link">
                    <i class="fas fa-money-bill-alt nav-icon"></i>
                    <p>Balance Enquiries</p>
                  </a>
                </li>
              </ul>
            </li>
            <!-- ./Finances -->

            <li class="nav-header">Advanced Modules</li>
            <li class="nav-item">
              <a href="pages_notifications.php" class="nav-link">
                <i class="nav-icon fas fa-bell"></i>
                <p>Notifications</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_financial_health.php" class="nav-link">
                <i class="nav-icon fas fa-chart-line"></i>
                <p>Budgets &amp; Goals</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_financial_products.php" class="nav-link">
                <i class="nav-icon fas fa-piggy-bank"></i>
                <p>Loans &amp; Deposits</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_account_services.php" class="nav-link">
                <i class="nav-icon fas fa-concierge-bell"></i>
                <p>Account Services</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_security.php" class="nav-link">
                <i class="nav-icon fas fa-shield-alt"></i>
                <p>Security Center</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_summary.php" class="nav-link">
                <i class="nav-icon fas fa-chart-pie"></i>
                <p>
                  Monthly Summary
                </p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_credit_health.php" class="nav-link">
                <i class="nav-icon fas fa-heartbeat"></i>
                <p>Credit Health</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_cards.php" class="nav-link">
                <i class="nav-icon fas fa-credit-card"></i>
                <p>ATM &amp; Debit Cards</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_qr_payments.php" class="nav-link">
                <i class="nav-icon fas fa-qrcode"></i>
                <p>QR Payments</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="pages_transactions_engine.php" class="nav-link">
                <i class="nav-icon fas fa-exchange-alt"></i>
                <p>
                  Transactions History
                </p>
              </a>
            </li>
            <!--./Transcactions Engine-->

            <!--Financial Reporting-->
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link">
                <i class="nav-icon fas fa-file-invoice-dollar"></i>
                <p>
                  Financial Reports
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview">
                <li class="nav-item">
                  <a href="pages_financial_reporting_deposits.php" class="nav-link">
                    <i class="fas fa-file-upload nav-icon"></i>
                    <p>Deposits</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_financial_reporting_withdrawals.php" class="nav-link">
                    <i class="fas fa-cart-arrow-down nav-icon"></i>
                    <p>Withdrawals</p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="pages_financial_reporting_transfers.php" class="nav-link">
                    <i class="fas fa-random nav-icon"></i>
                    <p>Transfers</p>
                  </a>
                </li>
              </ul>
            </li>
            <!-- ./ End financial Reporting-->

            <!-- Log Out -->
            <li class="nav-item">
              <a href="pages_logout.php" class="nav-link">
                <i class="nav-icon fas fa-power-off"></i>
                <p>
                  Log Out
                </p>
              </a>
            </li>
            <!-- ./Log Out -->
          </ul>
        </nav>
        <!-- /.sidebar-menu -->
      </div>
      <!-- /.sidebar -->
</aside>
<?php
    }
  } ?>