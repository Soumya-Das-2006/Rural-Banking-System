<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
define('RB_CREDIT_PROFILES_PORTAL', 'staff');
include __DIR__ . '/../credit_profiles_panel.php';
