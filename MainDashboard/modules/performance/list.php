<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

$pageTitle = 'Performance Evaluation';

if (isAdmin()) {
    $stmt = $pdo->query("SELECT pf.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code
                          FROM performance pf JOIN employees e ON pf.employee_id = e.employee_id
                          ORDER BY pf.evaluation_date DESC");
    $evaluations = $stmt->fetchAll();
} else {
    $empId = currentEmployeeId();
    $stmt = $pdo->prepare("SELECT * FROM performance WHERE employee_id = ? ORDER BY evaluation_date DESC");
    $stmt->execute([$empId]);
    $evaluations = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';

function ratingBadge($rating) {
    $map = [
        'Excellent' => 'badge-success',
        'Very Good' => 'badge-info',
        'Good' => 'badge-gray',
        'Fair' => 'badge-warning',
        'Needs Improvement' => 'badge-error',
    ];
    $cls = $map[$rating] ?? 'badge-gray';
    return "<span class=\"badge $cls\">" . htmlspecialchars($rating) . "</span>";
}
?>

<div class="card">
    <div class="card-header">
        <h2><?= isAdmin() ? 'Employee Performance Evaluations' : 'My Performance Evaluations' ?></h2>
        <?php if (isSuperAdmin()): ?>
            <a href="<?= siteUrl('modules/performance/add.php') ?>" class="btn btn-primary">+ New Evaluation (Super Admin)</a>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if (isAdmin()): ?><th>Employee</th><?php endif; ?>
            <th>Period</th><th>KPI Score</th><th>Rating</th><th>Evaluation Date</th><th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$evaluations): ?>
            <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>" class="empty-state">No performance evaluations recorded yet.</td></tr>
        <?php endif; foreach ($evaluations as $ev): ?>
            <tr>
                <?php if (isAdmin()): ?>
                    <td><?= htmlspecialchars($ev['emp_name']) ?> <span class="text-muted">(<?= htmlspecialchars($ev['emp_code']) ?>)</span></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($ev['evaluation_period']) ?></td>
                <td><?= number_format($ev['kpi_score'], 2) ?>%</td>
                <td><?= ratingBadge($ev['rating']) ?></td>
                <td><?= htmlspecialchars($ev['evaluation_date']) ?></td>
                <td><a href="<?= siteUrl('modules/performance/view.php?id=' . $ev['performance_id']) ?>" class="btn btn-secondary btn-sm">View Details</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
