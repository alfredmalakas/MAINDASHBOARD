<?php
/**
 * Employee management controller.
 */
class ApiEmployeesController
{
    private const STATUSES = ['Regular', 'Probationary', 'Contractual', 'Part-Time', 'Resigned', 'Terminated'];
    private const GENDERS  = ['Male', 'Female', 'Other'];

    /**
     * GET /v1/employees?q=&department_id=&position_id=&employment_status=&page=&per_page=
     * HR only.
     */
    public static function index(array $params, array $user): void
    {
        global $pdo;

        [$page, $perPage, $offset] = api_page_info();

        $where = [];
        $bind  = [];

        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.employee_id LIKE ? OR e.first_name LIKE ? OR e.middle_name LIKE ? OR e.last_name LIKE ?
                        OR e.email LIKE ? OR d.department_name LIKE ? OR p.position_title LIKE ?)';
            $like = "%{$q}%";
            array_push($bind, $like, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($_GET['department_id'])) {
            $where[] = 'e.department_id = ?';
            $bind[]  = (int)$_GET['department_id'];
        }
        if (!empty($_GET['position_id'])) {
            $where[] = 'e.position_id = ?';
            $bind[]  = (int)$_GET['position_id'];
        }

        $status = trim((string)($_GET['employment_status'] ?? ''));
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ApiException('Invalid employment_status value.', 422, 'validation_error');
            }
            $where[] = 'e.employment_status = ?';
            $bind[]  = $status;
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM employees e
             LEFT JOIN departments d ON d.department_id = e.department_id
             LEFT JOIN positions p ON p.position_id = e.position_id' . $sqlWhere
        );
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT e.*, d.department_name, p.position_title, p.base_salary AS position_base_salary
               FROM employees e
               LEFT JOIN departments d ON d.department_id = e.department_id
               LEFT JOIN positions p ON p.position_id = e.position_id
               {$sqlWhere}
               ORDER BY e.created_at DESC
               LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);

        api_respond($stmt->fetchAll(), 200, ['meta' => api_meta($page, $perPage, $total)]);
    }

    /**
     * GET /v1/employees/me — current user's own profile.
     */
    public static function me(array $params, array $user): void
    {
        global $pdo;

        $employeeId = api_own_employee_id($user);
        $emp = self::findEmployee($pdo, $employeeId);

        if (!$emp) {
            throw new ApiException('No employee profile found for this account.', 404, 'not_found');
        }

        api_respond($emp);
    }

    /**
     * GET /v1/employees/{id}
     */
    public static function show(array $params, array $user): void
    {
        global $pdo;

        $employeeId = (string)$params['id'];
        api_require_self_or_hr($user, $employeeId);

        $emp = self::findEmployee($pdo, $employeeId);
        if (!$emp) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        api_respond($emp);
    }

    /**
     * POST /v1/employees — HR only.
     */
    public static function create(array $params, array $user): void
    {
        global $pdo;

        $body = api_body();

        $first  = api_require($body, 'first_name', 'First name');
        $last   = api_require($body, 'last_name', 'Last name');
        $gender = api_require($body, 'gender', 'Gender');
        $dob    = api_date_value($body['date_of_birth'] ?? null, 'Date of birth');
        $email  = strtolower(trim((string)($body['email'] ?? '')));
        $hired  = api_date_value($body['date_hired'] ?? null, 'Date hired');

        if (!in_array($gender, self::GENDERS, true)) {
            throw new ApiException('Gender must be one of: ' . implode(', ', self::GENDERS), 422, 'validation_error');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('A valid email address is required.', 422, 'validation_error');
        }

        $dup = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE email = ?');
        $dup->execute([$email]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new ApiException('An employee with this email already exists.', 409, 'duplicate_email');
        }

        $employeeId = api_unique_employee_id($pdo);

        $ins = $pdo->prepare(
            'INSERT INTO employees
                (employee_id, first_name, middle_name, last_name, gender, date_of_birth, address,
                 contact_number, email, department_id, position_id, employment_status, date_hired,
                 emergency_contact_name, emergency_contact_number, photo, basic_salary)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $employeeId,
            $first,
            trim((string)($body['middle_name'] ?? '')),
            $last,
            $gender,
            $dob,
            trim((string)($body['address'] ?? '')),
            trim((string)($body['contact_number'] ?? '')),
            $email,
            self::nullableId($body['department_id'] ?? null),
            self::nullableId($body['position_id'] ?? null),
            self::normalizeStatus($body['employment_status'] ?? 'Probationary'),
            $hired,
            trim((string)($body['emergency_contact_name'] ?? '')),
            trim((string)($body['emergency_contact_number'] ?? '')),
            trim((string)($body['photo'] ?? 'assets/uploads/default.png')),
            (float)($body['basic_salary'] ?? 0),
        ]);

        // Seed current-year leave balances from enabled leave settings
        // (mirrors modules/employees/add.php).
        self::seedLeaveBalances($pdo, $employeeId);

        api_respond(self::findEmployee($pdo, $employeeId), 201);
    }

    /**
     * PUT /v1/employees/{id} — HR only. Partial update (only provided fields).
     */
    public static function update(array $params, array $user): void
    {
        global $pdo;

        $employeeId = (string)$params['id'];
        $emp = self::findEmployee($pdo, $employeeId);
        if (!$emp) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        $body    = api_body();
        $allowed = [
            'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'address',
            'contact_number', 'email', 'department_id', 'position_id', 'employment_status',
            'date_hired', 'emergency_contact_name', 'emergency_contact_number', 'photo', 'basic_salary',
        ];

        $sets = [];
        $bind = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];

            switch ($field) {
                case 'email':
                    $value = strtolower(trim((string)$value));
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new ApiException('A valid email address is required.', 422, 'validation_error');
                    }
                    $dup = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE email = ? AND employee_id <> ?');
                    $dup->execute([$value, $employeeId]);
                    if ((int)$dup->fetchColumn() > 0) {
                        throw new ApiException('An employee with this email already exists.', 409, 'duplicate_email');
                    }
                    break;

                case 'gender':
                    if (!in_array($value, self::GENDERS, true)) {
                        throw new ApiException('Gender must be one of: ' . implode(', ', self::GENDERS), 422, 'validation_error');
                    }
                    break;

                case 'employment_status':
                    $value = self::normalizeStatus($value);
                    break;

                case 'date_of_birth':
                case 'date_hired':
                    $value = api_date_value($value, str_replace('_', ' ', (string)$field));
                    break;

                case 'department_id':
                case 'position_id':
                    $value = self::nullableId($value);
                    break;

                case 'basic_salary':
                    $value = (float)$value;
                    break;
            }

            $sets[] = "`{$field}` = ?";
            $bind[] = $value;
        }

        if ($sets === []) {
            throw new ApiException('Nothing to update — no valid fields provided.', 422, 'validation_error');
        }

        $bind[] = $employeeId;
        $upd = $pdo->prepare('UPDATE employees SET ' . implode(', ', $sets) . ' WHERE employee_id = ?');
        $upd->execute($bind);

        api_respond(self::findEmployee($pdo, $employeeId));
    }

    /**
     * DELETE /v1/employees/{id} — HR only.
     * Related records cascade (attendance, leave, payroll, performance, users, tokens).
     */
    public static function delete(array $params, array $user): void
    {
        global $pdo;

        $employeeId = (string)$params['id'];
        $emp = self::findEmployee($pdo, $employeeId);
        if (!$emp) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        $del = $pdo->prepare('DELETE FROM employees WHERE employee_id = ?');
        $del->execute([$employeeId]);

        api_respond(['ok' => true, 'message' => "Employee {$employeeId} deleted."]);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------
    private static function findEmployee(PDO $pdo, string $employeeId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT e.*, d.department_name, p.position_title, p.base_salary AS position_base_salary
               FROM employees e
               LEFT JOIN departments d ON d.department_id = e.department_id
               LEFT JOIN positions p ON p.position_id = e.position_id
              WHERE e.employee_id = ?'
        );
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function normalizeStatus($value): string
    {
        $value = (string)$value;
        if (!in_array($value, self::STATUSES, true)) {
            throw new ApiException('employment_status must be one of: ' . implode(', ', self::STATUSES), 422, 'validation_error');
        }
        return $value;
    }

    private static function nullableId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private static function seedLeaveBalances(PDO $pdo, string $employeeId): void
    {
        $year     = date('Y');
        $settings = $pdo->query('SELECT leave_type, default_days FROM leave_settings WHERE allow_employee_apply = 1')->fetchAll();
        $ins = $pdo->prepare(
            'INSERT INTO leave_balances (employee_id, leave_type, year, allocated_days, used_days) VALUES (?,?,?,?,0)'
        );
        foreach ($settings as $setting) {
            $ins->execute([$employeeId, $setting['leave_type'], $year, (int)$setting['default_days']]);
        }
    }
}