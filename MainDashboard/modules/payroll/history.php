<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
requireLogin();

$pageTitle = 'Payroll History';
$employeeFilter = $_GET['employee_id'] ?? '';
$monthFilter = $_GET['month'] ?? '';
$yearFilter = (int)($_GET['year'] ?? date('Y'));
if ($yearFilter < 2020 || $yearFilter > 2100) $yearFilter = (int)date('Y');

if (isAdmin()) {
    $employees = $pdo->query("SELECT employee_id, first_name, last_name FROM employees ORDER BY first_name, last_name")->fetchAll();
    $sql = "SELECT pr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code,
                   d.department_name, p.position_title
            FROM payroll pr
            JOIN employees e ON pr.employee_id=e.employee_id
            LEFT JOIN departments d ON e.department_id=d.department_id
            LEFT JOIN positions p ON e.position_id=p.position_id
            WHERE YEAR(pr.pay_period_end)=?";
    $params = [$yearFilter];
    if ($employeeFilter !== '') { $sql .= " AND pr.employee_id=?"; $params[]=$employeeFilter; }
    if ($monthFilter !== '' && ctype_digit($monthFilter) && (int)$monthFilter >= 1 && (int)$monthFilter <= 12) {
        $sql .= " AND MONTH(pr.pay_period_end)=?"; $params[]=(int)$monthFilter;
    }
    $sql .= " ORDER BY pr.pay_period_end DESC, e.first_name, e.last_name, pr.payroll_id DESC";
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $records=$stmt->fetchAll();
} else {
    $empId=currentEmployeeId();
    $sql="SELECT pr.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code,
                  d.department_name, p.position_title
           FROM payroll pr JOIN employees e ON pr.employee_id=e.employee_id
           LEFT JOIN departments d ON e.department_id=d.department_id
           LEFT JOIN positions p ON e.position_id=p.position_id
           WHERE pr.employee_id=? AND YEAR(pr.pay_period_end)=?";
    $params=[$empId,$yearFilter];
    if ($monthFilter !== '' && ctype_digit($monthFilter) && (int)$monthFilter >= 1 && (int)$monthFilter <= 12) { $sql .= " AND MONTH(pr.pay_period_end)=?"; $params[]=(int)$monthFilter; }
    $sql .= " ORDER BY pr.pay_period_end DESC, pr.payroll_id DESC";
    $stmt=$pdo->prepare($sql); $stmt->execute($params); $records=$stmt->fetchAll();
}

$years=[];
try {
    $ys=$pdo->query("SELECT DISTINCT YEAR(pay_period_end) y FROM payroll ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    foreach($ys as $y) $years[]=(int)$y;
} catch(Throwable $e) {}
if (!$years) $years=[$yearFilter];

include __DIR__ . '/../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div><h2>Payroll History</h2><p class="text-muted">Historical payroll records by employee, month, and pay period.</p></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="<?= siteUrl('modules/payroll/list.php') ?>" class="btn btn-secondary">Current Payroll</a>
            <?php if(isAdmin()): ?><a href="<?= siteUrl('modules/payroll/generate.php') ?>" class="btn btn-primary">+ Generate Payroll</a><?php endif; ?>
        </div>
    </div>
    <form method="get" class="form-grid" style="margin-bottom:18px">
        <?php if(isAdmin()): ?><div class="form-group"><label>Employee</label><select name="employee_id"><option value="">All Employees</option><?php foreach($employees as $e): ?><option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= $employeeFilter===$e['employee_id']?'selected':'' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' ('.$e['employee_id'].')') ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="form-group"><label>Year</label><select name="year"><?php foreach($years as $y): ?><option value="<?= $y ?>" <?= $yearFilter===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Month</label><select name="month"><option value="">All Months</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= (string)$m===$monthFilter?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
        <div class="form-actions" style="align-self:end"><button class="btn btn-secondary">Filter History</button><button type="button" onclick="window.print()" class="btn btn-secondary">Print</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><?php if(isAdmin()): ?><th>Employee</th><?php endif; ?><th>Pay Period</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Generated</th><th>Action</th></tr></thead><tbody>
    <?php foreach($records as $r): ?><tr>
        <?php if(isAdmin()): ?><td><?= htmlspecialchars($r['emp_name']) ?><div class="text-muted"><?= htmlspecialchars($r['emp_code']) ?></div></td><?php endif; ?>
        <td><?= htmlspecialchars(date('M d, Y',strtotime($r['pay_period_start']))) ?> – <?= htmlspecialchars(date('M d, Y',strtotime($r['pay_period_end']))) ?></td>
        <td>₱<?= number_format((float)$r['gross_pay'],2) ?></td>
        <td>₱<?= number_format((float)$r['total_deductions'],2) ?></td>
        <td><strong>₱<?= number_format((float)$r['net_pay'],2) ?></strong></td>
        <td><?= htmlspecialchars(date('M d, Y',strtotime($r['date_generated']))) ?></td>
        <td><a href="<?= siteUrl('modules/payroll/payslip.php?id='.(int)$r['payroll_id']) ?>" class="btn btn-secondary btn-sm">View Payslip</a></td>
    </tr><?php endforeach; if(!$records): ?><tr><td colspan="<?= isAdmin()?7:6 ?>" class="empty-state">No payroll history found for the selected period.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="print-footer">HRIS Payroll History · <?= htmlspecialchars((string)$yearFilter) ?> · Generated <?= htmlspecialchars(date('M d, Y h:i A')) ?></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
