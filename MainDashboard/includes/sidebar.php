<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function navActive($dir, $match) {
    return $dir === $match ? 'active' : '';
}

function sidebarInitials() {
    $name = trim((string)($_SESSION['username'] ?? 'HR'));
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

$admin = isAdmin();
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img class="brand-logo-image"
             src="<?= assetUrl('assets/images/gsm-logo-mark.png') ?>"
             alt="Great Solomon Manpower Services Inc. logo">
        <div class="brand-copy">
            <div class="brand-name">Great Solomon</div>
            <div class="brand-subtitle">Manpower services Inc.</div>
        </div>
    </div>

    <div class="sidebar-scroll">
        <div class="nav-caption">Core2 (HRIS) Operations</div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="<?= siteUrl('modules/dashboard/index.php') ?>"
               class="nav-item <?= navActive($currentDir, 'dashboard') ?>">
                <span class="nav-icon icon-dashboard">▦</span>
                <span class="nav-label">Dashboard</span>
            </a>

            <a href="<?= siteUrl('modules/ai/assistant.php') ?>"
               class="nav-item <?= navActive($currentDir, 'ai') ?>">
                <span class="nav-icon">✦</span>
                <span class="nav-label">AI HRIS Assistant</span>
            </a>

            <?php if ($admin): ?>
                <a href="<?= siteUrl('modules/employees/list.php') ?>" class="nav-item <?= navActive($currentDir, 'employees') ?>"><span class="nav-icon">♙</span><span class="nav-label">Employees</span></a>
                <a href="<?= siteUrl('modules/users/access.php') ?>" class="nav-item <?= navActive($currentDir, 'users') ?>"><span class="nav-icon">♙</span><span class="nav-label">Login Access</span></a>
                <a href="<?= siteUrl('modules/employees/archive.php') ?>" class="nav-item <?= navActive($currentDir, 'employees') && $currentPage === 'archive.php' ? 'active' : '' ?>"><span class="nav-icon">▣</span><span class="nav-label">Employee Archive</span></a>
                <a href="<?= siteUrl('modules/employees/positions.php') ?>" class="nav-item <?= navActive($currentDir, 'employees') && $currentPage === 'positions.php' ? 'active' : '' ?>"><span class="nav-icon">▤</span><span class="nav-label">Positions</span></a>
                <div class="nav-caption">System & Compliance</div>
                <a href="<?= siteUrl('modules/compliance/index.php') ?>" class="nav-item <?= navActive($currentDir, 'compliance') ?>"><span class="nav-icon">✓</span><span class="nav-label">Compliance</span></a>
                <a href="<?= siteUrl('modules/reports/export.php') ?>" class="nav-item <?= navActive($currentDir, 'reports') ?>"><span class="nav-icon">▤</span><span class="nav-label">Reports & Export</span></a>
                <a href="<?= siteUrl('modules/data/import.php') ?>" class="nav-item <?= navActive($currentDir, 'data') ?>"><span class="nav-icon">⇧</span><span class="nav-label">Data Import</span></a>
                <a href="<?= siteUrl('modules/system/backup.php') ?>" class="nav-item <?= navActive($currentDir, 'system') ?>"><span class="nav-icon">◫</span><span class="nav-label">Backup & Restore</span></a>
            <?php else: ?>
                <a href="<?= siteUrl('modules/employees/view.php') ?>" class="nav-item <?= navActive($currentDir, 'employees') ?>"><span class="nav-icon">♙</span><span class="nav-label">My Profile</span></a>
            <?php endif; ?>

            <a href="<?= siteUrl('modules/attendance/list.php') ?>"
               class="nav-item <?= navActive($currentDir, 'attendance') ?>">
                <span class="nav-icon">◷</span>
                <span class="nav-label">Attendance</span>
               
            </a>

            <a href="<?= siteUrl('modules/leave/list.php') ?>"
               class="nav-item <?= navActive($currentDir, 'leave') && $currentPage !== 'settings.php' ? 'active' : '' ?>">
                <span class="nav-icon">▣</span>
                <span class="nav-label">Leave Management</span>
               
            </a>


            <a href="<?= siteUrl('modules/payroll/list.php') ?>"
               class="nav-item <?= navActive($currentDir, 'payroll') ?>">
                <span class="nav-icon">₱</span>
                <span class="nav-label">Payroll</span>
               
            </a>

            <a href="<?= siteUrl('modules/payroll/history.php') ?>"
               class="nav-item <?= navActive($currentDir, 'payroll') && $currentPage === 'history.php' ? 'active' : '' ?>">
                <span class="nav-icon">◫</span>
                <span class="nav-label">Payroll History</span>
            </a>

            <a href="<?= siteUrl('modules/employees/contracts.php') ?>"
               class="nav-item <?= navActive($currentDir, 'employees') && $currentPage === 'contracts.php' ? 'active' : '' ?>">
                <span class="nav-icon">▤</span>
                <span class="nav-label">Employee Contracts</span>
            </a>

            <a href="<?= siteUrl('modules/performance/list.php') ?>"
               class="nav-item <?= navActive($currentDir, 'performance') ?>">
                <span class="nav-icon">↗</span>
                <span class="nav-label">Performance</span>
            </a>

        </nav>

        <div class="nav-caption nav-caption-secondary">Account</div>
        <nav class="sidebar-nav sidebar-nav-secondary">
            <a href="<?= siteUrl('auth/logout.php') ?>" class="nav-item nav-logout">
                <span class="nav-icon">↪</span>
                <span class="nav-label">Sign Out</span>
            </a>
        </nav>
    </div>

    <div class="sidebar-account">
        <div class="account-avatar"><?= htmlspecialchars(sidebarInitials()) ?></div>
        <div class="account-info">
            <div class="account-name"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></div>
            <div class="account-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Employee') ?></div>
        </div>
        <span class="account-status" title="Online"></span>
    </div>
</aside>
