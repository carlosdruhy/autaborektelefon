<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
header('Location: ' . (isLoggedIn() ? APP_URL . '/dashboard.php' : APP_URL . '/login.php'));
exit;
