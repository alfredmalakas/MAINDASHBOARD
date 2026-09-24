<?php
require_once __DIR__ . '/config/session.php';
if (isLoggedIn()) {
    header("Location: " . siteUrl('modules/dashboard/index.php'));
} else {
    header("Location: " . siteUrl('auth/login.php'));
}
exit;
