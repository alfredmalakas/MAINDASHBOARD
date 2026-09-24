<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();

$pageTitle = 'Leave Management';
try { $pdo->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE recipient_user_id=? AND related_entity='leave_requests'")->execute([$_SESSION['user_id']]); } catch(Throwable $e) {}
$balances = [];
$leaveTypes = ['Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Bereavement Leave'];
$settingsErrors = [];
$settingsMessage = '';

// Leave policy settings are intentionally kept on this page so HR can manage
// requests and policies from one Leave Management screen.
if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_leave_setting'])) {
    verify_csrf();
    $leaveType = $_POST['leave_type'] ?? '';
    $days = max(0, (int)($_POST['default_days'] ?? 0));
    $attachment = isset($_POST['requires_attachment']) ? 1 : 0;
    $allow = isset($_POST['allow_employee_apply']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');

    if (!in_array($leaveType, $leaveTypes, true)) {
        $settingsErrors[] = 'Invalid leave type.';
    }
    if (!$settingsErrors) {
        $stmt = $pdo->prepare("INSERT INTO leave_settings (leave_type,default_days,requires_attachment,allow_employee_apply,description,updated_by)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE default_days=VALUES(default_days),requires_attachment=VALUES(requires_attachment),
            allow_employee_apply=VALUES(allow_employee_apply),description=VALUES(description),updated_by=VALUES(updated_by)");
        $stmt->execute([$leaveType,$days,$attachment,$allow,$description,$_SESSION['user_id']]);
        flash('success', 'Leave setting updated.');
        redirect('/modules/leave/list.php?status=' . urlencode($_GET['status'] ?? 'Pending') . '#leave-settings');
    }
}

$leavePolicies = [];
if (isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_leave_credit'])) {
    verify_csrf();
    $employeeId=trim($_POST['employee_id']??''); $leaveType=$_POST['credit_leave_type']??''; $year=(int)($_POST['credit_year']??date('Y')); $allocated=max(0,(int)($_POST['allocated_days']??0));
    if ($employeeId && in_array($leaveType,$leaveTypes,true) && $year>=2000) {
        $u=$pdo->prepare("INSERT INTO leave_balances(employee_id,leave_type,year,allocated_days,used_days) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE allocated_days=VALUES(allocated_days)"); $u->execute([$employeeId,$leaveType,$year,$allocated]);
        audit($pdo,'LEAVE_CREDIT_UPDATED','leave_balances',null,"{$employeeId} {$leaveType} {$year}: {$allocated} days"); flash('success','Leave credit updated.'); redirect('/modules/leave/list.php');
    }
}

if (isAdmin()) {
    $policyStmt = $pdo->query("SELECT * FROM leave_settings ORDER BY FIELD(leave_type,'Vacation Leave','Sick Leave','Emergency Leave','Maternity Leave','Paternity Leave','Bereavement Leave')");
    $leavePolicies = $policyStmt->fetchAll();
}

$adminBalances=[]; $activeEmployees=[];
if (isAdmin()) {
    $activeEmployees=$pdo->query("SELECT employee_id,first_name,last_name FROM employees WHERE employment_status NOT IN ('Resigned','Terminated') ORDER BY first_name,last_name")->fetchAll();
    $adminBalances=$pdo->query("SELECT b.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name,
        (SELECT COUNT(*) FROM attendance a WHERE a.employee_id=b.employee_id AND YEAR(a.attendance_date)=b.year AND a.status IN ('Present','Late')) AS work_days
        FROM leave_balances b JOIN employees e ON b.employee_id=e.employee_id
        ORDER BY e.first_name,e.last_name,b.year DESC,b.leave_type")->fetchAll();
}

if (isAdmin()) {
    $statusFilter = $_GET['status'] ?? 'Pending';
    if ($statusFilter === 'All') {
        $stmt = $pdo->query("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code
                              FROM leave_requests lr JOIN employees e ON lr.employee_id = e.employee_id
                              ORDER BY lr.date_filed DESC");
        $requests = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT lr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code
                                FROM leave_requests lr JOIN employees e ON lr.employee_id = e.employee_id
                                WHERE lr.status = ? ORDER BY lr.date_filed DESC");
        $stmt->execute([$statusFilter]);
        $requests = $stmt->fetchAll();
    }
} else {
    $empId = currentEmployeeId();
    $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE employee_id = ? ORDER BY date_filed DESC");
    $stmt->execute([$empId]);
    $requests = $stmt->fetchAll();

    $year = date('Y');
    $balStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE employee_id = ? AND year = ?");
    $balStmt->execute([$empId, $year]);
    $balances = $balStmt->fetchAll();
    $workStmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND YEAR(attendance_date)=? AND status IN ('Present','Late')");
    $workStmt->execute([$empId,$year]);
    $myWorkDays = (int)$workStmt->fetchColumn();
}

include __DIR__ . '/../../includes/header.php';

function leaveBadge($status) {
    $cls = $status==='Approved'?'badge-success':($status==='Rejected'?'badge-error':'badge-warning');
    return "<span class=\"badge $cls\">" . htmlspecialchars($status) . "</span>";
}
?>

<?php if (!isAdmin()): ?>
<div class="card">
    <div class="card-header">
        <h3>My Leave Balances (<?= date('Y') ?>)</h3>
        <a href="<?= siteUrl('modules/leave/apply.php') ?>" class="btn btn-primary btn-sm">+ Apply for Leave</a>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon">🗓️</div><div><div class="stat-value"><?= $myWorkDays ?? 0 ?> days</div><div class="stat-label">Work Days (<?= date('Y') ?>)</div></div></div>
        <?php foreach ($balances as $b): ?>
        <div class="stat-card">
            <div class="stat-icon">📅</div>
            <div>
                <div class="stat-value"><?= (int)($b['allocated_days'] - $b['used_days']) ?> days</div>
                <div class="stat-label"><?= htmlspecialchars($b['leave_type']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>My Leave Requests</h3></div>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Reason</th><th>Medical Certificate</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$requests): ?>
            <tr><td colspan="7" class="empty-state">No leave requests filed yet.</td></tr>
        <?php endif; foreach ($requests as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['leave_type']) ?></td>
                <td><?= htmlspecialchars($r['start_date']) ?></td>
                <td><?= htmlspecialchars($r['end_date']) ?></td>
                <td><?= (int)$r['total_days'] ?></td>
                <td><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                <td><?php if (!empty($r['attachment_path'])): ?><a href="<?= siteUrl('modules/leave/download.php?id='.(int)$r['leave_id']) ?>" target="_blank">View</a><?php else: ?>—<?php endif; ?></td>
                <td><?= leaveBadge($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<div class="card" id="leave-balances">
<div class="card-header"><div><h2>Leave Balances / Credits</h2><p class="text-muted">Set the annual leave credit for each employee and leave type.</p></div></div>
<form method="POST" class="form-grid"><?= csrf_field() ?><input type="hidden" name="save_leave_credit" value="1">
<div class="form-group"><label>Employee</label><select name="employee_id" required><option value="">-- Select Employee --</option><?php foreach($activeEmployees as $e): ?><option value="<?= htmlspecialchars($e['employee_id']) ?>"><?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' — '.$e['employee_id']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Leave Type</label><select name="credit_leave_type" required><?php foreach($leaveTypes as $t): ?><option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Year</label><input type="number" name="credit_year" value="<?= date('Y') ?>" min="2000" max="2100" required></div>
<div class="form-group"><label>Leave Credit / Allocated Days</label><input type="number" name="allocated_days" min="0" required></div>
<div class="form-actions"><button class="btn btn-primary">Save Leave Credit</button></div></form>
<div class="table-wrap" style="margin-top:18px"><table><thead><tr><th>Employee</th><th>Leave Type</th><th>Year</th><th>Credit</th><th>Used</th><th>Leave Balance</th><th>Work Days</th></tr></thead><tbody><?php if(!$adminBalances): ?><tr><td colspan="7" class="empty-state">No leave balances yet.</td></tr><?php endif; ?><?php foreach($adminBalances as $b): ?><tr><td><?= htmlspecialchars($b['emp_name'].' ('.$b['employee_id'].')') ?></td><td><?= htmlspecialchars($b['leave_type']) ?></td><td><?= (int)$b['year'] ?></td><td><?= (int)$b['allocated_days'] ?> days</td><td><?= (int)$b['used_days'] ?> days</td><td><strong><?= max(0,(int)$b['allocated_days']-(int)$b['used_days']) ?> days</strong></td><td><?= (int)$b['work_days'] ?> days</td></tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card">
    <div class="card-header"><h2>Leave Requests</h2></div>
    <div class="pill-nav">
        <a href="?status=Pending" class="<?= $statusFilter==='Pending'?'active':'' ?>">Pending</a>
        <a href="?status=Approved" class="<?= $statusFilter==='Approved'?'active':'' ?>">Approved</a>
        <a href="?status=Rejected" class="<?= $statusFilter==='Rejected'?'active':'' ?>">Rejected</a>
        <a href="?status=All" class="<?= $statusFilter==='All'?'active':'' ?>">All</a>
    </div>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Employee</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Reason</th><th>Medical Certificate</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$requests): ?>
            <tr><td colspan="9" class="empty-state">No <?= strtolower($statusFilter) ?> leave requests.</td></tr>
        <?php endif; foreach ($requests as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['emp_name']) ?> <span class="text-muted">(<?= htmlspecialchars($r['emp_code']) ?>)</span></td>
                <td><?= htmlspecialchars($r['leave_type']) ?></td>
                <td><?= htmlspecialchars($r['start_date']) ?></td>
                <td><?= htmlspecialchars($r['end_date']) ?></td>
                <td><?= (int)$r['total_days'] ?></td>
                <td><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                <td><?php if (!empty($r['attachment_path'])): ?><a href="<?= siteUrl('modules/leave/download.php?id='.(int)$r['leave_id']) ?>" target="_blank">View Certificate</a><?php else: ?>—<?php endif; ?></td>
                <td><?= leaveBadge($r['status']) ?></td>
                <td>
                    <?php if ($r['status'] === 'Pending'): ?>
                        <form method="POST" action="<?= siteUrl('modules/leave/action.php') ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>">
                            <input type="hidden" name="decision" value="Approved">
                            <button type="submit" class="btn btn-success btn-sm">Approve</button>
                        </form>
                        <form method="POST" action="<?= siteUrl('modules/leave/action.php') ?>" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="leave_id" value="<?= $r['leave_id'] ?>">
                            <input type="hidden" name="decision" value="Rejected">
                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card" id="leave-settings">
    <div class="card-header">
        <div>
            <h2>Leave Settings</h2>
            <p class="text-muted">Configure leave policies without leaving Leave Management.</p>
        </div>
    </div>

    <?php if ($settingsErrors): ?>
        <div class="alert alert-error"><?= htmlspecialchars(implode(' ', $settingsErrors)) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="save_leave_setting" value="1">
        <div class="form-group">
            <label>Leave Type *</label>
            <select name="leave_type" id="leave_type_setting" required>
                <?php foreach ($leaveTypes as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Default Days *</label>
            <input type="number" min="0" name="default_days" id="default_days_setting" required>
        </div>
        <div class="form-group">
            <label>Description</label>
            <input name="description" id="description_setting" placeholder="Policy description">
        </div>
        <div class="form-group" style="display:flex;gap:20px;align-items:center;padding-top:25px;flex-wrap:wrap">
            <label><input type="checkbox" name="requires_attachment" id="requires_attachment_setting"> Requires attachment</label>
            <label><input type="checkbox" name="allow_employee_apply" id="allow_employee_apply_setting" checked> Employee may apply</label>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save Setting</button></div>
    </form>

    <div class="table-wrap" style="margin-top:22px">
        <table>
            <thead><tr><th>Leave Type</th><th>Default Days</th><th>Attachment</th><th>Employee Apply</th><th>Description</th></tr></thead>
            <tbody>
            <?php if (!$leavePolicies): ?>
                <tr><td colspan="5" class="empty-state">No leave policies configured yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($leavePolicies as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['leave_type']) ?></strong></td>
                    <td><?= (int)$s['default_days'] ?> days</td>
                    <td><?= $s['requires_attachment'] ? 'Yes' : 'No' ?></td>
                    <td><?= $s['allow_employee_apply'] ? 'Allowed' : 'Disabled' ?></td>
                    <td><?= htmlspecialchars($s['description'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
