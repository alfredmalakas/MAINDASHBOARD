<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

$pageTitle = 'Payroll';

if (isAdmin()) {
    $employeeFilter=$_GET['employee_id']??''; $startFilter=$_GET['start']??''; $endFilter=$_GET['end']??'';
    $sql="SELECT pr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code FROM payroll pr JOIN employees e ON pr.employee_id=e.employee_id WHERE 1=1"; $params=[];
    if($employeeFilter!==''){ $sql.=' AND pr.employee_id=?'; $params[]=$employeeFilter; } if($startFilter!==''){ $sql.=' AND pr.pay_period_end>=?'; $params[]=$startFilter; } if($endFilter!==''){ $sql.=' AND pr.pay_period_start<=?'; $params[]=$endFilter; } $sql.=' ORDER BY pr.date_generated DESC LIMIT 200';
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $records=$stmt->fetchAll();
    $employees=$pdo->query("SELECT employee_id,first_name,last_name FROM employees WHERE employment_status NOT IN ('Resigned','Terminated') ORDER BY first_name,last_name")->fetchAll();
} else {
    $empId = currentEmployeeId();
    $stmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id = ? ORDER BY pay_period_end DESC");
    $stmt->execute([$empId]);
    $records = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2><?= isAdmin() ? 'Payroll Records' : 'My Payslips' ?></h2>
        <?php if (isAdmin()): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="<?= siteUrl('modules/payroll/history.php') ?>" class="btn btn-secondary">Payroll History</a><a href="<?= siteUrl('modules/payroll/timesheet.php') ?>" class="btn btn-secondary">Timesheet / Reports</a><a href="<?= siteUrl('modules/payroll/generate.php') ?>" class="btn btn-primary">+ Generate Payroll</a></div>
        <?php endif; ?>
    </div>
    <?php if(isAdmin()): ?><form method="get" class="form-grid" style="margin-bottom:18px"><div class="form-group"><label>Employee</label><select name="employee_id"><option value="">All Employees</option><?php foreach($employees as $e): ?><option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= ($employeeFilter??'')===$e['employee_id']?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>From</label><input type="date" name="start" value="<?= htmlspecialchars($startFilter??'') ?>"></div><div class="form-group"><label>To</label><input type="date" name="end" value="<?= htmlspecialchars($endFilter??'') ?>"></div><div class="form-actions" style="align-self:end"><button class="btn btn-secondary">Filter History</button><button type="button" onclick="window.print()" class="btn btn-secondary">Print</button></div></form><?php endif; ?>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <?php if (isAdmin()): ?><th>Employee</th><?php endif; ?>
            <th>Pay Period</th><th>Gross Pay</th><th>Deductions</th><th>Net Pay</th><th>Generated</th><th>Action</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$records): ?>
            <tr><td colspan="<?= isAdmin() ? 7 : 6 ?>" class="empty-state">No payroll records yet.</td></tr>
        <?php endif; foreach ($records as $r): ?>
            <tr>
                <?php if (isAdmin()): ?>
                    <td><?= htmlspecialchars($r['emp_name']) ?> <span class="text-muted">(<?= htmlspecialchars($r['emp_code']) ?>)</span></td>
                <?php endif; ?>
                <td><?= htmlspecialchars($r['pay_period_start']) ?> to <?= htmlspecialchars($r['pay_period_end']) ?></td>
                <td>₱<?= number_format($r['gross_pay'], 2) ?></td>
                <td>₱<?= number_format($r['total_deductions'], 2) ?></td>
                <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                <td><?= htmlspecialchars(date('M d, Y', strtotime($r['date_generated']))) ?></td>
                <td><a href="<?= siteUrl('modules/payroll/payslip.php?id=' . $r['payroll_id']) ?>" class="btn btn-secondary btn-sm">View Payslip</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if(isAdmin()): ?><div class="print-footer">HRIS Payroll History · Generated <?= htmlspecialchars(date('M d, Y h:i A')) ?></div><?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
