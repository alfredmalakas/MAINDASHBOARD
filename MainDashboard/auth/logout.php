<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
if (isLoggedIn()) { audit($pdo,'LOGOUT','users',$_SESSION['user_id'],'User signed out'); }
require_once __DIR__ . '/../config/session.php';
session_unset(); session_destroy(); header("Location: " . siteUrl('auth/login.php')); exit;
