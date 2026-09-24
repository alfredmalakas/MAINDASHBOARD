<?php
/**
 * Leave management controller.
 * Mirrors the business rules in modules/leave/apply.php and action.php.
 */
class ApiLeaveController
{
    // Leave types that draw down the employee's annual leave balance.
    private const BALANCE_TYPES = ['Vacation Leave', 'Sick Leave', 'Emergency Leave'];
    private const STATUSES      = ['Pending', 'Approved', 'Rejected'];

    /**
     * GET /v1/leave/types — enabled leave settings.
     */
    public static function types(array $params, array $user): void
    {
        global $pdo;

        $stmt = $pdo->query('SELECT * FROM leave_settings ORDER BY leave_type');
        api_respond($stmt->fetchAll());
    }

    /**
     * GET /v1/leave/balances?year=&employee_id= — balances (HR may target an employee).
     */
    public static function balances(array $params, array $user): void
    {
        global $pdo;

        if (api_is_hr($user) && !empty($_GET['employee_id'])) {
            $employeeId = (string)$_GET['employee_id'];
        } else {
            $employeeId = api_own_employee_id($user);
        }

        $year = (int)($_GET['year'] ?? date('Y'));

        $sql  = 'SELECT lb.*, e.first_name, e.last_name
                   FROM leave_balances lb
                   JOIN employees e ON e.employee_id = lb.employee_id
                  WHERE lb.year = ?';
        $bind = [$year];

        if ($employeeId !== '') {
            $sql   .= ' AND lb.employee_id = ?';
            $bind[] = $employeeId;
        }
        $sql .= ' ORDER BY lb.employee_id, lb.leave_type';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['remaining_days'] = (int)$row['allocated_days'] - (int)$row['used_days'];
        }
        unset($row);

        api_respond($rows);
    }

    /**
     * GET /v1/leave?status=&employee_id=&page=&per_page=
     * Employees see their own requests; HR sees all (optionally filtered).
     */
    public static function index(array $params, array $user): void
    {
        global $pdo;

        [$page, $perPage, $offset] = api_page_info();

        $where = [];
        $bind  = [];

        if (api_is_hr($user)) {
            if (!empty($_GET['employee_id'])) {
                $where[] = 'lr.employee_id = ?';
                $bind[]  = (string)$_GET['employee_id'];
            }
        } else {
            $where[] = 'lr.employee_id = ?';
            $bind[]  = api_own_employee_id($user);
        }

        $status = trim((string)($_GET['status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ApiException('status must be one of: ' . implode(', ', self::STATUSES), 422, 'validation_error');
            }
            $where[] = 'lr.status = ?';
            $bind[]  = $status;
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests lr' . $sqlWhere);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT lr.*, e.first_name, e.last_name, u.username AS approved_by_username
               FROM leave_requests lr
               JOIN employees e ON e.employee_id = lr.employee_id
               LEFT JOIN users u ON u.user_id = lr.approved_by
               {$sqlWhere}
               ORDER BY lr.date_filed DESC
               LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);

        api_respond($stmt->fetchAll(), 200, ['meta' => api_meta($page, $perPage, $total)]);
    }

    /**
     * POST /v1/leave — file a leave application.
     * Body: leave_type, start_date, end_date, reason (HR may include employee_id).
     */
    public static function create(array $params, array $user): void
    {
        global $pdo;

        $body = api_body();

        // HR may file on behalf of an employee; employees always file for themselves.
        if (api_is_hr($user) && !empty($body['employee_id'])) {
            $employeeId = (string)$body['employee_id'];
        } else {
            $employeeId = api_own_employee_id($user);
        }

        $leaveType = api_require($body, 'leave_type', 'Leave type');
        $startDate = api_date_value($body['start_date'] ?? null, 'Start date');
        $endDate   = api_date_value($body['end_date'] ?? null, 'End date');
        $reason    = trim((string)($body['reason'] ?? ''));

        if ($endDate < $startDate) {
            throw new ApiException('End date cannot be before start date.', 422, 'validation_error');
        }

        // Only enabled leave types may be requested (parity with apply.php).
        $enabled  = $pdo->query('SELECT leave_type FROM leave_settings WHERE allow_employee_apply = 1')->fetchAll();
        $allowedTypes = array_column($enabled, 'leave_type');
        if (!in_array($leaveType, $allowedTypes, true)) {
            throw new ApiException('This leave type is currently disabled for employee requests.', 422, 'validation_error');
        }

        $totalDays = (int)((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;

        // Balance check for leave types that draw from the annual balance.
        if (in_array($leaveType, self::BALANCE_TYPES, true)) {
            $year = date('Y', strtotime($startDate));
            $balStmt = $pdo->prepare(
                'SELECT * FROM leave_balances WHERE employee_id = ? AND leave_type = ? AND year = ?'
            );
            $balStmt->execute([$employeeId, $leaveType, $year]);
            $bal = $balStmt->fetch();
            $remaining = $bal ? ((int)$bal['allocated_days'] - (int)$bal['used_days']) : 0;

            if ($totalDays > $remaining) {
                throw new ApiException(
                    "Insufficient leave balance. You only have {$remaining} day(s) of {$leaveType} remaining.",
                    422,
                    'insufficient_balance'
                );
            }
        }

        $ins = $pdo->prepare(
            "INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, total_days, reason, status)
             VALUES (?,?,?,?,?,?,'Pending')"
        );
        $ins->execute([$employeeId, $leaveType, $startDate, $endDate, $totalDays, $reason]);

        $id  = (int)$pdo->lastInsertId();
        $get = $pdo->prepare(
            'SELECT lr.*, e.first_name, e.last_name
               FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id
              WHERE lr.leave_id = ?'
        );
        $get->execute([$id]);

        api_respond($get->fetch(), 201);
    }

    /**
     * GET /v1/leave/{id}
     */
    public static function show(array $params, array $user): void
    {
        global $pdo;

        $leaveId = (int)$params['id'];

        $get = $pdo->prepare(
            'SELECT lr.*, e.first_name, e.last_name, u.username AS approved_by_username
               FROM leave_requests lr
               JOIN employees e ON e.employee_id = lr.employee_id
               LEFT JOIN users u ON u.user_id = lr.approved_by
              WHERE lr.leave_id = ?'
        );
        $get->execute([$leaveId]);
        $leave = $get->fetch();

        if (!$leave) {
            throw new ApiException('Leave request not found.', 404, 'not_found');
        }
        api_require_self_or_hr($user, $leave['employee_id']);

        api_respond($leave);
    }

    /**
     * POST /v1/leave/{id}/approve — HR only.
     */
    public static function approve(array $params, array $user): void
    {
        global $pdo;
        self::decide($pdo, (int)$params['id'], 'Approved', (int)$user['user_id']);
    }

    /**
     * POST /v1/leave/{id}/reject — HR only.
     */
    public static function reject(array $params, array $user): void
    {
        global $pdo;
        self::decide($pdo, (int)$params['id'], 'Rejected', (int)$user['user_id']);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------
    private static function decide(PDO $pdo, int $leaveId, string $decision, int $userId): void
    {
        $get = $pdo->prepare('SELECT * FROM leave_requests WHERE leave_id = ?');
        $get->execute([$leaveId]);
        $leave = $get->fetch();

        if (!$leave) {
            throw new ApiException('Leave request not found.', 404, 'not_found');
        }
        if ($leave['status'] !== 'Pending') {
            throw new ApiException('Leave request has already been processed.', 409, 'already_processed');
        }

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE leave_requests SET status = ?, approved_by = ?, date_actioned = NOW() WHERE leave_id = ?'
            );
            $upd->execute([$decision, $userId, $leaveId]);

            if ($decision === 'Approved' && in_array($leave['leave_type'], self::BALANCE_TYPES, true)) {
                $year = date('Y', strtotime((string)$leave['start_date']));

                $balUpd = $pdo->prepare(
                    'UPDATE leave_balances SET used_days = used_days + ?
                      WHERE employee_id = ? AND leave_type = ? AND year = ?'
                );
                $balUpd->execute([$leave['total_days'], $leave['employee_id'], $leave['leave_type'], $year]);

                // Mark each day in the range as Leave in attendance.
                $start = new DateTime((string)$leave['start_date']);
                $end   = new DateTime((string)$leave['end_date']);
                $end->modify('+1 day');
                $period = new DatePeriod($start, new DateInterval('P1D'), $end);

                $attStmt = $pdo->prepare(
                    "INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?,?,'Leave')
                     ON DUPLICATE KEY UPDATE status='Leave'"
                );
                foreach ($period as $day) {
                    $attStmt->execute([$leave['employee_id'], $day->format('Y-m-d')]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new ApiException('Failed to process leave request: ' . $e->getMessage(), 500, 'server_error');
        }

        $fresh = $pdo->prepare(
            'SELECT lr.*, e.first_name, e.last_name
               FROM leave_requests lr JOIN employees e ON e.employee_id = lr.employee_id
              WHERE lr.leave_id = ?'
        );
        $fresh->execute([$leaveId]);

        api_respond($fresh->fetch());
    }
}