<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireRole('HR Administrator');
$pageTitle='Employee Archive';
$rows=$pdo->query("SELECT a.*,u.username AS archived_by_username FROM employee_archive a LEFT JOIN users u ON a.archived_by=u.user_id ORDER BY a.archived_at DESC")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="card"><div class="card-header"><div><h2>Employee Archive</h2><p class="text-muted">Employees removed from the active directory are retained here for administrative history.</p></div><a href="<?= siteUrl('modules/employees/list.php') ?>" class="btn btn-secondary">Back to Employees</a></div>
<div class="table-wrap"><table><thead><tr><th>Employee</th><th>Original ID</th><th>Employment Status</th><th>Archived By</th><th>Archived At</th><th>Reason</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="empty-state">No archived employees.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr><td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong><br><span class="text-muted"><?= htmlspecialchars($r['email']??'') ?></span></td><td><?= htmlspecialchars($r['original_employee_id']) ?></td><td><?= htmlspecialchars($r['employment_status']??'—') ?></td><td><?= htmlspecialchars($r['archived_by_username']??'—') ?></td><td><?= htmlspecialchars(date('M d, Y h:i A',strtotime($r['archived_at']))) ?></td><td><?= htmlspecialchars($r['reason']??'—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>