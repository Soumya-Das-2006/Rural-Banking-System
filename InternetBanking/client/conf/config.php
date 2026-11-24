<?php
/* Rural Banking System - database connection (settings come from .env / environment) */
require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../core/money.php';
$mysqli = rb_mysqli();
