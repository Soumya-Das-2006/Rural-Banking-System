<?php
/* Rural Banking System - PDO connection (settings come from .env / environment) */
require_once __DIR__ . '/../../core/bootstrap.php';
$DB_con = rb_pdo();
