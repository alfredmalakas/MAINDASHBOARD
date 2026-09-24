<?php
/**
 * Attendance & timekeeping controller.
 * Official schedule: 08:00 in / 17:00 out (mirrors modules/attendance/list.php).
 */
class ApiAttendanceController
{
    private const OFFICIAL_START = '08:00:00';
    private const OFFICIAL_END   = '17:00:00';
    private const STATUSES       = ['Present', 'Late', 'Absent', 'Leave', 'Half-Day', 'Overtime'];

    /**
     * GET /v1/attendance?date=YYYY-MM-DD&employee_id= — HR daily roster.
     */
    public static function index(array $params, array $user): void
    {
        global $pdo;

        $date = api_date_value($_GET['date'] ?? date('Y-m-d'), 'Date');

        $sql  = 'SELECT a.*, e.first_name, e.last_name, e.employee_id AS emp_id
                   FROM attendance a
                   JOIN employees e ON e.employee_id = a.employee_id
                  WHERE a.attendance_date = ?';
        $bind = [$date];

        if (!empty($_GET['employee_id'])) {
            $sql   .= ' AND a.employee_id = ?';
            $bind[] = (string)$_GET['employee_id'];
        }
        $sql .= ' ORDER BY a.time_in DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        api_respond($stmt->fetchAll());
    }

    /**
     * GET /v1/attendance/me — today's record + last 30 records for caller.
     */
    public static function me(array $params, array $user): void
    {
        global $pdo;

        $employeeId = api_own_employee_id($user);
        $today      = date('Y-m-d');

        $todayStmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?');
        $todayStmt->execute([$employeeId, $today]);
        $todayRecord = $todayStmt->fetch();

        $histStmt = $pdo->prepare(
            'SELECT * FROM attendance WHERE employee_id = ? ORDER BY attendance_date DESC, time_in DESC LIMIT 30'
        );
        $histStmt->execute([$employeeId]);

        api_respond([
            'today'   => $todayRecord ?: null,
            'history' => $histStmt->fetchAll(),
        ]);
    }

    /**
     * POST /v1/attendance/time-in — employee role only.
     */
    public static function timeIn(array $params, array $user): void
    {
        global $pdo;

        if (api_is_hr($user)) {
            throw new ApiException('HR accounts cannot punch their own time; use an employee token.', 403, 'forbidden');
        }
        $employeeId = api_own_employee_id($user);
        $date       = date('Y-m-d');

        $existing = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?');
        $existing->execute([$employeeId, $date]);
        if ($existing->fetch()) {
            throw new ApiException('You have already timed in today.', 409, 'already_timed_in');
        }

        $now = date('H:i:s');
        if ($now > self::OFFICIAL_START) {
            $lateMinutes = (int)round((strtotime($now) - strtotime(self::OFFICIAL_START)) / 60);
            $status      = 'Late';
        } else {
            $lateMinutes = 0;
            $status      = 'Present';
        }

        $ins = $pdo->prepare(
            'INSERT INTO attendance (employee_id, attendance_date, time_in, status, late_minutes) VALUES (?,?,?,?,?)'
        );
        $ins->execute([$employeeId, $date, $now, $status, $lateMinutes]);

        $get = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?');
        $get->execute([$employeeId, $date]);
        api_respond($get->fetch(), 201);
    }

    /**
     * POST /v1/attendance/time-out — employee role only.
     */
    public static function timeOut(array $params, array $user): void
    {
        global $pdo;

        if (api_is_hr($user)) {
            throw new ApiException('HR accounts cannot punch their own time; use an employee token.', 403, 'forbidden');
        }
        $employeeId = api_own_employee_id($user);
        $date       = date('Y-m-d');

        $existing = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?');
        $existing->execute([$employeeId, $date]);
        $rec = $existing->fetch();

        if (!$rec || !$rec['time_in']) {
            throw new ApiException('You need to time in first.', 409, 'no_time_in');
        }
        if ($rec['time_out']) {
            throw new ApiException('You have already timed out today.', 409, 'already_timed_out');
        }

        $now = date('H:i:s');
        $overtimeMinutes = ($now > self::OFFICIAL_END)
            ? (int)round((strtotime($now) - strtotime(self::OFFICIAL_END)) / 60)
            : 0;

        $status = $rec['status'];
        if ($overtimeMinutes > 0 && $status === 'Present') {
            $status = 'Overtime';
        }

        $upd = $pdo->prepare(
            'UPDATE attendance SET time_out = ?, overtime_minutes = ?, status = ? WHERE attendance_id = ?'
        );
        $upd->execute([$now, $overtimeMinutes, $status, $rec['attendance_id']]);

        $get = $pdo->prepare('SELECT * FROM attendance WHERE attendance_id = ?');
        $get->execute([$rec['attendance_id']]);
        api_respond($get->fetch());
    }

    /**
     * POST /v1/attendance/records — HR creates/updates a daily attendance record.
     * Body: employee_id, attendance_date, time_in, time_out, break_start, break_end,
     *       status, late_minutes, overtime_minutes, remarks
     */
    public static function upsert(array $params, array $user): void
    {
        global $pdo;

        $body       = api_body();
        $employeeId = api_require($body, 'employee_id', 'Employee ID');
        $date       = api_date_value($body['attendance_date'] ?? null, 'Attendance date');

        $check = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_id = ?');
        $check->execute([$employeeId]);
        if ((int)$check->fetchColumn() === 0) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO attendance
                (employee_id, attendance_date, time_in, time_out, break_start, break_end,
                 status, late_minutes, overtime_minutes, remarks)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                 time_in          = COALESCE(VALUES(time_in), attendance.time_in),
                 time_out         = COALESCE(VALUES(time_out), attendance.time_out),
                 break_start      = COALESCE(VALUES(break_start), attendance.break_start),
                 break_end        = COALESCE(VALUES(break_end), attendance.break_end),
                 status           = COALESCE(VALUES(status), attendance.status),
                 late_minutes     = COALESCE(VALUES(late_minutes), attendance.late_minutes),
                 overtime_minutes = COALESCE(VALUES(overtime_minutes), attendance.overtime_minutes),
                 remarks          = COALESCE(VALUES(remarks), attendance.remarks)"
        );
        $stmt->execute([
            $employeeId,
            $date,
            self::nullableTime($body['time_in'] ?? null),
            self::nullableTime($body['time_out'] ?? null),
            self::nullableTime($body['break_start'] ?? null),
            self::nullableTime($body['break_end'] ?? null),
            self::normalizeStatus($body['status'] ?? 'Present'),
            (int)($body['late_minutes'] ?? 0),
            (int)($body['overtime_minutes'] ?? 0),
            trim((string)($body['remarks'] ?? '')),
        ]);

        $get = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?');
        $get->execute([$employeeId, $date]);
        api_respond($get->fetch());
    }

    /**
     * GET /v1/attendance/report?month=YYYY-MM&employee_id= — HR monthly summary.
     */
    public static function report(array $params, array $user): void
    {
        global $pdo;

        $month = trim((string)($_GET['month'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new ApiException('month must be in YYYY-MM format.', 422, 'validation_error');
        }

        $sql = "SELECT e.employee_id, e.first_name, e.last_name,
                       SUM(CASE WHEN a.status='Present' THEN 1 ELSE 0 END)   AS present_count,
                       SUM(CASE WHEN a.status='Late' THEN 1 ELSE 0 END)      AS late_count,
                       SUM(CASE WHEN a.status='Absent' THEN 1 ELSE 0 END)    AS absent_count,
                       SUM(CASE WHEN a.status='Overtime' THEN 1 ELSE 0 END)  AS overtime_count,
                       SUM(CASE WHEN a.status='Leave' THEN 1 ELSE 0 END)     AS leave_count,
                       SUM(a.late_minutes)      AS total_late_minutes,
                       SUM(a.overtime_minutes)  AS total_ot_minutes
                  FROM employees e
                  LEFT JOIN attendance a
                         ON e.employee_id = a.employee_id
                        AND DATE_FORMAT(a.attendance_date, '%Y-%m') = ?
                 WHERE e.employment_status NOT IN ('Resigned','Terminated')";
        $bind = [$month];

        if (!empty($_GET['employee_id'])) {
            $sql   .= ' AND e.employee_id = ?';
            $bind[] = (string)$_GET['employee_id'];
        }
        $sql .= ' GROUP BY e.employee_id, e.first_name, e.last_name ORDER BY e.first_name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);

        api_respond($stmt->fetchAll());
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------
    private static function nullableTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return date('H:i:s', strtotime((string)$value));
    }

    private static function normalizeStatus($value): string
    {
        $value = (string)($value ?? 'Present');
        if (!in_array($value, self::STATUSES, true)) {
            throw new ApiException('status must be one of: ' . implode(', ', self::STATUSES), 422, 'validation_error');
        }
        return $value;
    }
}