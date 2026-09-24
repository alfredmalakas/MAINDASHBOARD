<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/security.php';
security_headers();
requireLogin();
$pageTitle = $pageTitle ?? 'HRIS';
audit($pdo, 'PAGE_VIEW', 'module', basename($_SERVER['PHP_SELF'] ?? 'unknown'), 'Viewed ' . $pageTitle);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | HRIS</title>
<link rel="stylesheet" href="<?= assetUrl('assets/css/style.css') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-content">
<header class="topbar">
    <button class="menu-toggle" id="menuToggle">&#9776;</button>
    <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
    <div class="topbar-user">
        <?php $unreadNotifications=0; try { $nq=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_user_id=? AND is_read=0"); $nq->execute([$_SESSION['user_id']]); $unreadNotifications=(int)$nq->fetchColumn(); } catch(Throwable $e) {} ?>
        <a href="<?= siteUrl('modules/data/exchange.php') ?>#notifications" class="btn btn-secondary btn-sm" title="Notifications">🔔 <?= $unreadNotifications ?></a>
        <span class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></span>
        <span class="user-role"><?= htmlspecialchars($_SESSION['role']) ?></span>
    </div>
</header>
<main class="page-content" id="main-content" tabindex="-1">
<?php
$err = flash('error');
$ok  = flash('success');
if ($err): ?>
    <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
<?php endif;
if ($ok): ?>
    <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>

<style>
.hris-back-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;margin:8px 0;border:1px solid #d1d5db;border-radius:7px;background:#fff;color:#374151;text-decoration:none;font-size:14px;cursor:pointer}
.hris-back-btn:hover{background:#f3f4f6}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
 if(document.querySelector('[data-hris-back]')) return;
 var a=document.createElement('a');
 a.href='#'; a.className='hris-back-btn'; a.setAttribute('data-hris-back','1');
 a.innerHTML='&#8592; Back';
 a.onclick=function(e){
   e.preventDefault();
   if(document.referrer && new URL(document.referrer,location.href).origin===location.origin) history.back();
   else location.href='/MainDashboard4/index.php';
 };
 var target=document.querySelector('main,.main-content,.content,.container')||document.body;
 target.insertBefore(a,target.firstChild);
});
</script>
