<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireLogin();

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("SELECT pr.*, e.first_name, e.last_name, e.employee_id AS emp_code, d.department_name, p.position_title
                        FROM payroll pr JOIN employees e ON pr.employee_id = e.employee_id
                        LEFT JOIN departments d ON e.department_id = d.department_id
                        LEFT JOIN positions p ON e.position_id = p.position_id
                        WHERE pr.payroll_id = ?");
$stmt->execute([$id]);
$pay = $stmt->fetch();

if (!$pay) {
    flash('error', 'Payslip not found.');
    redirect('/modules/payroll/list.php');
}

// Attendance summary for this exact payroll period.
$attStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN status IN ('Present','Late','Overtime') THEN 1 WHEN status='Half-Day' THEN 0.5 ELSE 0 END),0) AS attendance_days,
    COALESCE(SUM(CASE WHEN status='Absent' THEN 1 ELSE 0 END),0) AS absent_days,
    COALESCE(SUM(late_minutes),0) AS late_minutes,
    COALESCE(SUM(overtime_minutes),0) AS overtime_minutes,
    COALESCE(SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date,' ',time_in), CONCAT(attendance_date,' ',time_out)) - CASE WHEN break_start IS NOT NULL AND break_end IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, break_start, break_end) ELSE 0 END) ELSE 0 END),0) AS worked_minutes
    FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
$attStmt->execute([$pay['employee_id'], $pay['pay_period_start'], $pay['pay_period_end']]);
$attendanceSummary = $attStmt->fetch() ?: [];

// Approved leave that falls within the payroll period.
$leaveStmt = $pdo->prepare("SELECT leave_type, COALESCE(SUM(total_days),0) AS approved_days
    FROM leave_requests
    WHERE employee_id=? AND status='Approved' AND start_date <= ? AND end_date >= ?
    GROUP BY leave_type ORDER BY leave_type");
$leaveStmt->execute([$pay['employee_id'], $pay['pay_period_end'], $pay['pay_period_start']]);
$approvedLeaves = $leaveStmt->fetchAll();

$balanceStmt = $pdo->prepare("SELECT leave_type, allocated_days, used_days, (allocated_days-used_days) AS remaining_days
    FROM leave_balances WHERE employee_id=? AND year=? ORDER BY leave_type");
$balanceStmt->execute([$pay['employee_id'], date('Y', strtotime($pay['pay_period_end']))]);
$leaveBalances = $balanceStmt->fetchAll();

$workedMinutes = (int)($attendanceSummary['worked_minutes'] ?? 0);
$workedHours = intdiv($workedMinutes, 60);
$workedRemainder = $workedMinutes % 60;
$workedHoursLabel = $workedHours . 'h ' . $workedRemainder . 'm';

// Password is required before printing/downloading a payslip. Unlock lasts 5 minutes for this payslip.
$printKey = 'payslip_print_' . (string)$id;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_print') {
    $pw = (string)($_POST['print_password'] ?? '');
    $u = $pdo->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
    $u->execute([$_SESSION['user_id']]);
    $hash = $u->fetchColumn();
    if ($hash && password_verify($pw, $hash)) {
        $_SESSION[$printKey] = time() + 300;
        if (function_exists('audit')) audit($pdo,'PAYSLIP_PRINT_UNLOCK','payroll',$id,'Password verified before payslip print');
        flash('success','Password verified. You may now print this payslip.');
    } else {
        flash('error','Incorrect password. Payslip printing remains locked.');
    }
    redirect('/modules/payroll/payslip.php?id=' . urlencode($id));
}
$printUnlocked = isset($_SESSION[$printKey]) && (int)$_SESSION[$printKey] >= time();
if (!$printUnlocked) unset($_SESSION[$printKey]);

// Employees can only view their own payslip
if (!isAdmin() && $pay['employee_id'] !== currentEmployeeId()) {
    flash('error', 'You are not authorized to view this payslip.');
    redirect('/modules/payroll/list.php');
}

$pageTitle = 'Payslip';
include __DIR__ . '/../../includes/header.php';
?>

<style>
@media print {
    .sidebar, .topbar, .no-print { display: none !important; }
    .main-content { margin: 0 !important; }
    .page-content { padding: 0 !important; }
    body { background: #fff !important; }
}
.payslip-box { max-width: 680px; margin: 0 auto; }
.payslip-head { text-align: center; border-bottom: 2px solid var(--primary); padding-bottom: 14px; margin-bottom: 18px; }
.payslip-head h2 { color: var(--primary-dark); font-size: 18px; }
.payslip-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dashed var(--border); font-size: 13.5px; }
.payslip-total { display: flex; justify-content: space-between; padding: 12px 0; font-weight: 700; font-size: 16px; border-top: 2px solid var(--primary); margin-top: 8px; }
.payslip-section-title { font-weight: 700; margin: 16px 0 6px; color: var(--primary-dark); font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; }
</style>

<div class="card payslip-box">
    <div class="payslip-head">
        <h2>GREAT SOLOMON MANPOWER SERVICES INC.</h2><div style="font-weight:700">HRIS Payroll Payslip</div>
        <p class="text-muted">Pay Period: <?= htmlspecialchars($pay['pay_period_start']) ?> to <?= htmlspecialchars($pay['pay_period_end']) ?></p>
    </div>

    <div class="info-grid mb-0">
        <div class="info-item"><div class="label">Employee</div><div class="value"><?= htmlspecialchars($pay['first_name'].' '.$pay['last_name']) ?></div></div>
        <div class="info-item"><div class="label">Employee ID</div><div class="value"><?= htmlspecialchars($pay['emp_code']) ?></div></div>
        <div class="info-item"><div class="label">Department</div><div class="value"><?= htmlspecialchars($pay['department_name'] ?? '—') ?></div></div>
        <div class="info-item"><div class="label">Position</div><div class="value"><?= htmlspecialchars($pay['position_title'] ?? '—') ?></div></div>
    </div>

    <div class="payslip-section-title">Attendance & Leave Summary</div>
    <div class="payslip-row"><span>Attendance / Worked Days</span><span><?= number_format((float)($attendanceSummary['attendance_days'] ?? 0),1) ?> day(s)</span></div>
    <div class="payslip-row"><span>Total Worked Hours</span><span><?= htmlspecialchars($workedHoursLabel) ?></span></div>
    <div class="payslip-row"><span>Absent</span><span><?= (int)($attendanceSummary['absent_days'] ?? 0) ?> day(s)</span></div>
    <div class="payslip-row"><span>Late</span><span><?= (int)($attendanceSummary['late_minutes'] ?? 0) ?> minute(s)</span></div>
    <div class="payslip-row"><span>Overtime</span><span><?= round(((int)($attendanceSummary['overtime_minutes'] ?? 0))/60,2) ?> hour(s)</span></div>
    <?php if ($approvedLeaves): ?>
        <?php foreach ($approvedLeaves as $lv): ?>
            <div class="payslip-row"><span>Approved <?= htmlspecialchars($lv['leave_type']) ?></span><span><?= (int)$lv['approved_days'] ?> day(s)</span></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="payslip-row"><span>Approved Leave</span><span>0 day(s)</span></div>
    <?php endif; ?>
    <div class="payslip-section-title">Leave Balance</div>
    <?php if ($leaveBalances): ?>
        <?php foreach ($leaveBalances as $lb): ?>
            <div class="payslip-row"><span><?= htmlspecialchars($lb['leave_type']) ?></span><span><?= (int)$lb['remaining_days'] ?> remaining / <?= (int)$lb['allocated_days'] ?> credit</span></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="payslip-row"><span>Leave Balance</span><span>No balance record</span></div>
    <?php endif; ?>

    <div class="payslip-section-title">Earnings</div>
    <div class="payslip-row"><span>Basic Salary</span><span>₱<?= number_format($pay['basic_salary'],2) ?></span></div>
    <div class="payslip-row"><span>Overtime Pay</span><span>₱<?= number_format($pay['overtime_pay'],2) ?></span></div>
    <div class="payslip-row"><span>Allowances</span><span>₱<?= number_format($pay['allowances'],2) ?></span></div>
    <div class="payslip-row"><span>Incentives</span><span>₱<?= number_format($pay['incentives'],2) ?></span></div>
    <div class="payslip-row"><span>Bonuses</span><span>₱<?= number_format($pay['bonuses'],2) ?></span></div>
    <div class="payslip-row" style="font-weight:700;"><span>Gross Pay</span><span>₱<?= number_format($pay['gross_pay'],2) ?></span></div>

    <div class="payslip-section-title">Deductions</div>
    <div class="payslip-row"><span>Withholding Tax (BIR)</span><span>₱<?= number_format($pay['tax_deduction'],2) ?></span></div>
    <div class="payslip-row"><span>SSS</span><span>₱<?= number_format($pay['sss_deduction'],2) ?></span></div>
    <div class="payslip-row"><span>PhilHealth</span><span>₱<?= number_format($pay['philhealth_deduction'],2) ?></span></div>
    <div class="payslip-row"><span>Pag-IBIG</span><span>₱<?= number_format($pay['pagibig_deduction'],2) ?></span></div>
    <div class="payslip-row"><span>Late Deduction</span><span>₱<?= number_format($pay['late_deduction'],2) ?></span></div>
    <div class="payslip-row"><span>Absence Deduction</span><span>₱<?= number_format($pay['absence_deduction'],2) ?></span></div>
    <div class="payslip-row" style="font-weight:700;"><span>Total Deductions</span><span>₱<?= number_format($pay['total_deductions'],2) ?></span></div>

    <div class="payslip-total">
        <span>NET PAY</span>
        <span>₱<?= number_format($pay['net_pay'],2) ?></span>
    </div>

    <div style="margin-top:22px;padding-top:10px;border-top:1px solid var(--border);font-size:11px;text-align:center;color:#6b7280">This is a system-generated payroll record. Generated by HRIS.</div>
    <div class="form-actions no-print" style="margin-top:20px;">
        <?php if ($printUnlocked): ?>
            <button onclick="window.print()" class="btn btn-primary">🖨️ Print / Save as PDF</button>
            <span class="text-muted">Print unlocked for 5 minutes.</span>
        <?php else: ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="verify_print">
                <input type="password" name="print_password" placeholder="Account password" required autocomplete="current-password">
                <button type="submit" class="btn btn-primary">🔐 Verify Password to Print</button>
            </form>
        <?php endif; ?>
        <a href="<?= siteUrl('modules/payroll/list.php') ?>" class="btn btn-secondary">Back</a>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
