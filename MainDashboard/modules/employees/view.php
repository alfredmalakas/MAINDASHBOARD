<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

// Admin can view any employee via ?id=; Employee always views own profile
if (isAdmin() && isset($_GET['id'])) {
    $id = $_GET['id'];
} else {
    $id = currentEmployeeId();
}

$pageTitle = 'Employee Profile';

$stmt = $pdo->prepare("SELECT e.*, d.department_name, p.position_title FROM employees e
                        LEFT JOIN departments d ON e.department_id = d.department_id
                        LEFT JOIN positions p ON e.position_id = p.position_id
                        WHERE e.employee_id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) {
    flash('error', 'Employee not found.');
    redirect(isAdmin() ? '/modules/employees/list.php' : '/modules/dashboard/index.php');
}

// Leave balances
$year = date('Y');
$balStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND year = ?");
$balStmt->execute([$id, $year]);
$balances = $balStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="profile-header">
        <img src="<?= assetUrl(htmlspecialchars($emp['photo'])) ?>" class="avatar avatar-lg" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($emp['first_name'].' '.$emp['last_name']) ?>&size=96&background=e8f1f8&color=2c5f8a'">
        <div>
            <h2><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></h2>
            <p><?= htmlspecialchars($emp['position_title'] ?? 'No Position Assigned') ?> · <?= htmlspecialchars($emp['department_name'] ?? 'No Department') ?></p>
            <p class="text-muted"><?= htmlspecialchars($emp['employee_id']) ?></p>
        </div>
        <?php if (isAdmin()): ?>
        <div style="margin-left:auto;">
            <?php if (isAdmin()): ?>
                <a href="<?= siteUrl('modules/employees/edit.php?id=' . urlencode($emp['employee_id'])) ?>" class="btn btn-primary">Edit Profile</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="info-grid mt-10">
        <div class="info-item"><div class="label">Gender</div><div class="value"><?= htmlspecialchars($emp['gender']) ?></div></div>
        <div class="info-item"><div class="label">Date of Birth</div><div class="value"><?= htmlspecialchars($emp['date_of_birth']) ?></div></div>
        <div class="info-item"><div class="label">Contact Number</div><div class="value"><?= htmlspecialchars($emp['contact_number'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Email Address</div><div class="value"><?= htmlspecialchars($emp['email']) ?></div></div>
        <div class="info-item"><div class="label">Address</div><div class="value"><?= htmlspecialchars($emp['address'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Employment Status</div><div class="value"><span class="badge badge-info"><?= htmlspecialchars($emp['employment_status']) ?></span></div></div>
        <div class="info-item"><div class="label">Date Hired</div><div class="value"><?= htmlspecialchars($emp['date_hired']) ?></div></div>
        <div class="info-item"><div class="label">Basic Salary</div><div class="value">₱<?= number_format($emp['basic_salary'], 2) ?></div></div>
        <div class="info-item"><div class="label">Emergency Contact</div><div class="value"><?= htmlspecialchars($emp['emergency_contact_name'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Emergency Number</div><div class="value"><?= htmlspecialchars($emp['emergency_contact_number'] ?: '—') ?></div></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Leave Balances (<?= $year ?>)</h3></div>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Leave Type</th><th>Allocated</th><th>Used</th><th>Remaining</th></tr></thead>
        <tbody>
        <?php if (!$balances): ?>
            <tr><td colspan="4" class="empty-state">No leave balance records yet.</td></tr>
        <?php endif; foreach ($balances as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['leave_type']) ?></td>
                <td><?= (int)$b['allocated_days'] ?></td>
                <td><?= (int)$b['used_days'] ?></td>
                <td><strong><?= (int)($b['allocated_days'] - $b['used_days']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
