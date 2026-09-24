<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/payroll.php';
requireRole('HR Administrator');

$pageTitle = 'Generate Payroll';
$errors = [];

$employees = $pdo->query("
    SELECT * FROM employees 
    WHERE employment_status NOT IN ('Resigned','Terminated') 
    ORDER BY first_name
")->fetchAll();

$selectedEmployeeId = $_POST['employee_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $employeeId  = $_POST['employee_id'] ?? '';
    $periodStart = $_POST['pay_period_start'] ?? '';
    $periodEnd   = $_POST['pay_period_end'] ?? '';
    $bonusPercent = (float)($_POST['bonuses_percent'] ?? 10);

    if (!$employeeId) {
        $errors[] = 'Please select an employee.';
    }

    if (!$periodStart || !$periodEnd) {
        $errors[] = 'Pay period dates are required.';
    }

    if ($periodStart && $periodEnd && $periodStart > $periodEnd) {
        $errors[] = 'Pay period start date cannot be later than the end date.';
    }

    if (!$errors) {

        $empStmt = $pdo->prepare("
            SELECT * FROM employees 
            WHERE employee_id = ?
        ");
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();

        if (!$emp) {

            $errors[] = 'Employee not found.';
        } else {

            $basicSalary = (float)$emp['basic_salary'];

            // Bonus
            $bonuses = round(
                $basicSalary * ($bonusPercent / 100),
                2
            );

            // Attendance
            $attStmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(overtime_minutes), 0) AS total_ot_minutes,
                    COALESCE(SUM(late_minutes), 0) AS total_late_minutes,
                    COALESCE(
                        SUM(
                            CASE 
                                WHEN status = 'Absent' THEN 1 
                                ELSE 0 
                            END
                        ), 
                        0
                    ) AS absent_days
                FROM attendance
                WHERE employee_id = ?
                AND attendance_date BETWEEN ? AND ?
            ");

            $attStmt->execute([
                $employeeId,
                $periodStart,
                $periodEnd
            ]);

            $att = $attStmt->fetch();

            // Rates
            $dailyRate = $basicSalary / 22;
            $hourlyRate = $dailyRate / 8;

            $otMinutes = (int)($att['total_ot_minutes'] ?? 0);
            $lateMinutes = (int)($att['total_late_minutes'] ?? 0);
            $absentDays = (int)($att['absent_days'] ?? 0);

            // Overtime
            $overtimePay = round(
                ($otMinutes / 60) * $hourlyRate * 1.25,
                2
            );

            // Deductions
            $lateDeduction = round(
                ($lateMinutes / 60) * $hourlyRate,
                2
            );

            $absenceDeduction = round(
                $absentDays * $dailyRate,
                2
            );

            // Gross Pay
            $grossPay = round(
                $basicSalary +
                    $overtimePay +
                    $bonuses,
                2
            );

            // Government statutory deductions and BIR withholding tax.
            $periodType = payroll_period_type($periodStart, $periodEnd);
            $semiMonthly = ($periodType === 'semi-monthly');
            $statutory = payroll_statutory_deductions($basicSalary, $grossPay, $semiMonthly);
            $sss = $statutory['sss'];
            $philhealth = $statutory['philhealth'];
            $pagibig = $statutory['pagibig'];

            // BIR taxable compensation is compensation less employee mandatory contributions.
            $taxableIncome = max(0, $grossPay - $sss - $philhealth - $pagibig);
            $tax = round(bir_withholding_tax($taxableIncome, $periodType), 2);

            // Total deductions
            $totalDeductions = round(
                $sss +
                    $philhealth +
                    $pagibig +
                    $tax +
                    $lateDeduction +
                    $absenceDeduction,
                2
            );

            // Net Pay
            $netPay = round(
                $grossPay - $totalDeductions,
                2
            );

            /*
             * IMPORTANT:
             * incentives and allowances are removed.
             * Therefore the INSERT contains 16 columns.
             */

            $stmt = $pdo->prepare("
                INSERT INTO payroll (
                    employee_id,
                    pay_period_start,
                    pay_period_end,
                    basic_salary,
                    overtime_pay,
                    bonuses,
                    gross_pay,
                    tax_deduction,
                    sss_deduction,
                    philhealth_deduction,
                    pagibig_deduction,
                    late_deduction,
                    absence_deduction,
                    total_deductions,
                    net_pay,
                    generated_by
                )
                VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $employeeId,
                $periodStart,
                $periodEnd,
                $basicSalary,
                $overtimePay,
                $bonuses,
                $grossPay,
                $tax,
                $sss,
                $philhealth,
                $pagibig,
                $lateDeduction,
                $absenceDeduction,
                $totalDeductions,
                $netPay,
                $_SESSION['user_id']
            ]);

            $newId = $pdo->lastInsertId();

            audit($pdo,'PAYROLL_GENERATED','payroll',$newId,"Payroll generated for {$employeeId} {$periodStart} to {$periodEnd}");
            flash(
                'success',
                'Payroll generated successfully.'
            );

            redirect(
                '/modules/payroll/payslip.php?id=' . $newId
            );
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="max-width:640px;">

    <div class="card-header">
        <h2>Generate Payroll</h2>
    </div>

    <?php if ($errors): ?>

        <div class="alert alert-error">

            <?php foreach ($errors as $e): ?>

                <?= htmlspecialchars($e) ?><br>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <div class="alert alert-info">

        <strong>Payroll Basis:</strong>
        Basic salary is pulled from the employee record.
        Attendance is used to calculate overtime, late deductions, and absence deductions. Government deductions use current statutory rates configured for the Philippines. Withholding tax follows the BIR graduated table effective January 1, 2023 onward.

    </div>

    <form method="POST">
        <?= csrf_field() ?>

        <div class="form-group">

            <label>Employee *</label>

            <select name="employee_id" required>

                <option value="">
                    -- Select Employee --
                </option>

                <?php foreach ($employees as $e): ?>

                    <option
                        value="<?= htmlspecialchars($e['employee_id']) ?>"
                        <?= $selectedEmployeeId == $e['employee_id'] ? 'selected' : '' ?>>

                        <?= htmlspecialchars(
                            $e['first_name'] .
                                ' ' .
                                $e['last_name'] .
                                ' (' .
                                $e['employee_id'] .
                                ') — ₱' .
                                number_format($e['basic_salary'], 2)
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="form-grid">

            <div class="form-group">

                <label>Pay Period Start *</label>

                <input
                    type="date"
                    name="pay_period_start"
                    value="<?= htmlspecialchars($_POST['pay_period_start'] ?? '') ?>"
                    required>

            </div>

            <div class="form-group">

                <label>Pay Period End *</label>

                <input
                    type="date"
                    name="pay_period_end"
                    value="<?= htmlspecialchars($_POST['pay_period_end'] ?? '') ?>"
                    required>

            </div>

        </div>

        <div class="form-group">

            <label>
                Bonuses (%) - percentage of basic salary
            </label>

            <input
                type="number"
                step="0.01"
                min="0"
                name="bonuses_percent"
                value="<?= htmlspecialchars($_POST['bonuses_percent'] ?? '10') ?>">

        </div>

        <div class="form-actions">

            <button
                type="submit"
                class="btn btn-primary">
                Generate Payroll
            </button>

            <a
                href="<?= siteUrl('modules/payroll/list.php') ?>"
                class="btn btn-secondary">
                Cancel
            </a>

        </div>

    </form>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
```