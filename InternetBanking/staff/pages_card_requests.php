<?php
session_start();
include('conf/config.php');
include('conf/checklogin.php');
check_login();
require_once __DIR__ . '/../core/cards.php';
define('RB_CARD_REQUESTS_PORTAL', 'staff');
include __DIR__ . '/../card_requests_panel.php';
