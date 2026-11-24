<nav class="main-header navbar navbar-expand navbar-white navbar-light rb-navbar">

<ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
    </ul>

  <!-- SEARCH FORM -->
  <!-- <form class="form-inline ml-3">
    <div class="input-group input-group-sm">
      <input class="form-control form-control-navbar" type="search" placeholder="Search" aria-label="Search">
      <div class="input-group-append">
        <button class="btn btn-navbar" type="submit">
          <i class="fas fa-search"></i>
        </button>
      </div>
    </div>
  </form> -->

  <!-- Right navbar links -->
  <ul class="navbar-nav ml-auto">
    <!-- Messages Dropdown Menu -->
    <li class="nav-item dropdown">
      <a class="nav-link" data-toggle="dropdown" href="#">
        <i class="far fa-bell"></i>
        <?php
        //code for summing up notifications
        $result = "SELECT count(*) FROM ib_notifications";
        $stmt = $mysqli->prepare($result);
        $stmt->execute();
        $stmt->bind_result($ntf);
        $stmt->fetch();
        $stmt->close();
        ?>
        <span class="badge badge-danger navbar-badge"><?php echo $ntf; ?></span>
      </a>

      <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
        <h6 class="dropdown-header">Recent system activity</h6>
        <?php
        $ret = 'SELECT notification_id, notification_details, created_at FROM ib_notifications ORDER BY notification_id DESC LIMIT 10';
        $stmt = $mysqli->prepare($ret);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_object()) {
        ?>
          <div class="dropdown-item-text text-wrap">
            <p class="text-sm mb-1"><?php echo rb_e($row->notification_details); ?></p>
            <p class="text-xs text-muted mb-1"><i class="far fa-clock mr-1"></i><?php echo rb_e(date('d M Y H:i', strtotime($row->created_at))); ?></p>
            <form method="post" action="pages_dashboard.php">
              <button class="btn btn-sm btn-outline-secondary" type="submit" name="Clear_Notifications" value="<?php echo (int) $row->notification_id; ?>">Dismiss</button>
            </form>
          </div>
          <div class="dropdown-divider"></div>
        <?php } $stmt->close(); ?>
        <?php if ($ntf === 0) { ?><span class="dropdown-item-text text-muted">No recent activity.</span><?php } ?>
      </div>

    </li>

  </ul>
</nav>