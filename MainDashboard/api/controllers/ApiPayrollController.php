<?php
/**
 * Payroll controller.
 * Payroll API uses the same statutory and BIR calculation helpers as the web payroll module.
 */
class ApiPayrollController
{
    /**
     * GET /v1/payroll?employee_id=&pay_period_start=&pay_period_end=&page=&per_page=
     * Employees see their own records; HR sees all (optionally filtered).
     */
    public static function index(array $params, array $user): void
    {
        global $pdo;

        [$page, $perPage, $offset] = api_page_info();

        $where = [];
        $bind  = [];

        if (api_is_hr($user)) {
            if (!empty($_GET['employee_id'])) {
                $where[] = 'p.employee_id = ?';
                $bind[]  = (string)$_GET['employee_id'];
            }
        } else {
            $where[] = 'p.employee_id = ?';
            $bind[]  = api_own_employee_id($user);
        }

        if (!empty($_GET['pay_period_start']) && !empty($_GET['pay_period_end'])) {
            $start  = api_date_value($_GET['pay_period_start'], 'Pay period start');
            $end    = api_date_value($_GET['pay_period_end'], 'Pay period end');
            $where[] = 'p.pay_period_start = ? AND p.pay_period_end = ?';
            array_push($bind, $start, $end);
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM payroll p' . $sqlWhere);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*, e.first_name, e.last_name, u.username AS generated_by_username
               FROM payroll p
               JOIN employees e ON e.employee_id = p.employee_id
               LEFT JOIN users u ON u.user_id = p.generated_by
               {$sqlWhere}
               ORDER BY p.date_generated DESC
               LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);

        api_respond($stmt->fetchAll(), 200, ['meta' => api_meta($page, $perPage, $total)]);
    }

    /**
     * GET /v1/payroll/{id}
     */
    public static function show(array $params, array $user): void
    {
        global $pdo;

        $payrollId = (int)$params['id'];

        $get = $pdo->prepare(
            'SELECT p.*, e.first_name, e.last_name, u.username AS generated_by_username
               FROM payroll p
               JOIN employees e ON e.employee_id = p.employee_id
               LEFT JOIN users u ON u.user_id = p.generated_by
              WHERE p.payroll_id = ?'
        );
        $get->execute([$payrollId]);
        $row = $get->fetch();

        if (!$row) {
            throw new ApiException('Payroll record not found.', 404, 'not_found');
        }
        api_require_self_or_hr($user, $row['employee_id']);

        api_respond($row);
    }

    /**
     * GET /v1/payroll/{id}/summary — payroll + attendance + leave summary for the same period.
     */
    public static function summary(array $params, array $user): void
    {
        global $pdo;
        $payrollId = (int)$params['id'];
        $get = $pdo->prepare('SELECT p.*, e.first_name, e.last_name FROM payroll p JOIN employees e ON e.employee_id = p.employee_id WHERE p.payroll_id = ?');
        $get->execute([$payrollId]);
        $pay = $get->fetch();
        if (!$pay) throw new ApiException('Payroll record not found.', 404, 'not_found');
        api_require_self_or_hr($user, $pay['employee_id']);

        $att = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN status IN ('Present','Late','Overtime') THEN 1 WHEN status='Half-Day' THEN 0.5 ELSE 0 END),0) AS attendance_days,
            COALESCE(SUM(CASE WHEN status='Absent' THEN 1 ELSE 0 END),0) AS absent_days,
            COALESCE(SUM(late_minutes),0) AS late_minutes,
            COALESCE(SUM(overtime_minutes),0) AS overtime_minutes,
            COALESCE(SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, CONCAT(attendance_date,' ',time_in), CONCAT(attendance_date,' ',time_out)) - CASE WHEN break_start IS NOT NULL AND break_end IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, break_start, break_end) ELSE 0 END) ELSE 0 END),0) AS worked_minutes
            FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
        $att->execute([$pay['employee_id'],$pay['pay_period_start'],$pay['pay_period_end']]);
        $attendance = $att->fetch() ?: [];

        $lv = $pdo->prepare("SELECT leave_type, COALESCE(SUM(total_days),0) AS approved_days FROM leave_requests WHERE employee_id=? AND status='Approved' AND start_date <= ? AND end_date >= ? GROUP BY leave_type ORDER BY leave_type");
        $lv->execute([$pay['employee_id'],$pay['pay_period_end'],$pay['pay_period_start']]);
        $leaves = $lv->fetchAll();

        api_respond(['payroll'=>$pay,'attendance'=>$attendance,'approved_leaves'=>$leaves]);
    }

    /**
     * POST /v1/payroll/generate — HR only.
     * Body: employee_id, pay_period_start, pay_period_end, bonuses_percent (default 10)
     */
    public static function generate(array $params, array $user): void
    {
        global $pdo;

        $body = api_body();

        $employeeId   = api_require($body, 'employee_id', 'Employee ID');
        $start        = api_date_value($body['pay_period_start'] ?? null, 'Pay period start');
        $end          = api_date_value($body['pay_period_end'] ?? null, 'Pay period end');
        $bonusPercent = (float)($body['bonuses_percent'] ?? 10);

        if ($start > $end) {
            throw new ApiException('Pay period start cannot be later than the end date.', 422, 'validation_error');
        }

        $empStmt = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();
        if (!$emp) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        // Prevent duplicate generation for the same employee + period.
        $dup = $pdo->prepare(
            'SELECT COUNT(*) FROM payroll WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?'
        );
        $dup->execute([$employeeId, $start, $end]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new ApiException(
                'Payroll has already been generated for this employee and pay period.',
                409,
                'duplicate_payroll'
            );
        }

        $basicSalary = (float)$emp['basic_salary'];

        // Attendance-based amounts for the period.
        $attStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(overtime_minutes), 0) AS total_ot_minutes,
                    COALESCE(SUM(late_minutes), 0)     AS total_late_minutes,
                    COALESCE(SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END), 0) AS absent_days
               FROM attendance
              WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?"
        );
        $attStmt->execute([$employeeId, $start, $end]);
        $att = $attStmt->fetch();

        $dailyRate  = $basicSalary / 22;
        $hourlyRate = $dailyRate / 8;

        $otMinutes   = (int)$att['total_ot_minutes'];
        $lateMinutes = (int)$att['total_late_minutes'];
        $absentDays  = (int)$att['absent_days'];

        $bonuses           = round($basicSalary * ($bonusPercent / 100), 2);
        $overtimePay       = round(($otMinutes / 60) * $hourlyRate * 1.25, 2);
        $lateDeduction     = round(($lateMinutes / 60) * $hourlyRate, 2);
        $absenceDeduction  = round($absentDays * $dailyRate, 2);
        $grossPay          = round($basicSalary + $overtimePay + $bonuses, 2);

        // Same statutory deductions and BIR withholding tax used by the web module.
        $periodType = payroll_period_type($start, $end);
        $semiMonthly = ($periodType === 'semi-monthly');
        $statutory = payroll_statutory_deductions($basicSalary, $grossPay, $semiMonthly);
        $sss = $statutory['sss'];
        $philhealth = $statutory['philhealth'];
        $pagibig = $statutory['pagibig'];
        $taxableIncome = max(0, $grossPay - $sss - $philhealth - $pagibig);
        $tax = round(bir_withholding_tax($taxableIncome, $periodType), 2);

        $totalDeductions = round($sss + $philhealth + $pagibig + $tax + $lateDeduction + $absenceDeduction, 2);
        $netPay          = round($grossPay - $totalDeductions, 2);

        $ins = $pdo->prepare(
            'INSERT INTO payroll
                (employee_id, pay_period_start, pay_period_end, basic_salary, overtime_pay, allowances,
                 incentives, bonuses, gross_pay, tax_deduction, sss_deduction, philhealth_deduction,
                 pagibig_deduction, late_deduction, absence_deduction, total_deductions, net_pay, generated_by)
             VALUES (?,?,?,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $employeeId,
            $start,
            $end,
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
            (int)$user['user_id'],
        ]);

        $id  = (int)$pdo->lastInsertId();
        $get = $pdo->prepare(
            'SELECT p.*, e.first_name, e.last_name
               FROM payroll p JOIN employees e ON e.employee_id = p.employee_id
              WHERE p.payroll_id = ?'
        );
        $get->execute([$id]);

        api_respond($get->fetch(), 201);
    }
}