<?php
/**
 * Performance evaluation controller.
 * KPI computation mirrors modules/performance/add.php: average of the 1-5
 * rubric scores is converted to a /100 KPI and a preset rating band.
 */
require_once __DIR__ . '/../../modules/performance/rubric.php';

class ApiPerformanceController
{
    /**
     * GET /v1/performance/rubric — the A-I evaluation rubric (HR only).
     */
    public static function rubric(array $params, array $user): void
    {
        global $performanceRubric;
        api_respond($performanceRubric);
    }

    /**
     * GET /v1/performance?employee_id=&evaluation_period=&page=&per_page=
     * Employees see their own; HR sees all (optionally filtered).
     */
    public static function index(array $params, array $user): void
    {
        global $pdo;

        [$page, $perPage, $offset] = api_page_info();

        $where = [];
        $bind  = [];

        if (api_is_hr($user)) {
            if (!empty($_GET['employee_id'])) {
                $where[] = 'pf.employee_id = ?';
                $bind[]  = (string)$_GET['employee_id'];
            }
        } else {
            $where[] = 'pf.employee_id = ?';
            $bind[]  = api_own_employee_id($user);
        }

        if (!empty($_GET['evaluation_period'])) {
            $where[] = 'pf.evaluation_period = ?';
            $bind[]  = (string)$_GET['evaluation_period'];
        }

        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM performance pf' . $sqlWhere);
        $countStmt->execute($bind);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT pf.*, e.first_name, e.last_name, u.username AS evaluator_username
               FROM performance pf
               JOIN employees e ON e.employee_id = pf.employee_id
               LEFT JOIN users u ON u.user_id = pf.evaluator_id
               {$sqlWhere}
               ORDER BY pf.evaluation_date DESC
               LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['rubric_scores'] = self::decodeRubric($row['rubric_scores'] ?? null);
        }
        unset($row);

        api_respond($rows, 200, ['meta' => api_meta($page, $perPage, $total)]);
    }

    /**
     * POST /v1/performance — HR creates an evaluation.
     * Body: employee_id, evaluation_period, evaluation_date, strengths,
     *       areas_for_improvement, supervisor_comments, scores{ "CATEGORY|index": 1-5 }
     */
    public static function create(array $params, array $user): void
    {
        global $pdo, $performanceRubric;

        $body = api_body();

        $employeeId = api_require($body, 'employee_id', 'Employee ID');
        $period     = api_require($body, 'evaluation_period', 'Evaluation period');
        $evalDate   = api_date_value($body['evaluation_date'] ?? null, 'Evaluation date');
        $strengths  = trim((string)($body['strengths'] ?? ''));
        $improvement = trim((string)($body['areas_for_improvement'] ?? ''));
        $comments   = trim((string)($body['supervisor_comments'] ?? ''));
        $scores     = (is_array($body['scores'] ?? null)) ? $body['scores'] : [];

        // Confirm the employee exists.
        $check = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_id = ?');
        $check->execute([$employeeId]);
        if ((int)$check->fetchColumn() === 0) {
            throw new ApiException('Employee not found.', 404, 'not_found');
        }

        $flatScores = [];
        foreach ($performanceRubric as $category => $criteria) {
            foreach ($criteria as $i => $criterion) {
                $key   = $category . '|' . $i;
                $value = $scores[$key] ?? null;
                if (!is_numeric($value) || (int)$value < 1 || (int)$value > 5) {
                    throw new ApiException(
                        "Please rate every performance criterion from 1 to 5 (missing or invalid score for \"{$key}\").",
                        422,
                        'validation_error'
                    );
                }
                $flatScores[$key] = (int)$value;
            }
        }

        $average  = array_sum($flatScores) / count($flatScores);
        $kpiScore = round(($average / 5) * 100, 2);
        $rating   = performanceRatingFromScore($average);

        $ins = $pdo->prepare(
            'INSERT INTO performance
                (employee_id, evaluator_id, evaluation_period, kpi_score, rating, strengths,
                 areas_for_improvement, supervisor_comments, evaluation_date, rubric_scores)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $employeeId,
            (int)$user['user_id'],
            $period,
            $kpiScore,
            $rating,
            $strengths,
            $improvement,
            $comments,
            $evalDate,
            json_encode($flatScores),
        ]);

        $id  = (int)$pdo->lastInsertId();
        $get = $pdo->prepare('SELECT * FROM performance WHERE performance_id = ?');
        $get->execute([$id]);
        $row = $get->fetch();
        $row['rubric_scores'] = self::decodeRubric($row['rubric_scores'] ?? null);

        api_respond($row, 201);
    }

    /**
     * GET /v1/performance/{id}
     */
    public static function show(array $params, array $user): void
    {
        global $pdo;

        $performanceId = (int)$params['id'];

        $get = $pdo->prepare(
            'SELECT pf.*, e.first_name, e.last_name, u.username AS evaluator_username
               FROM performance pf
               JOIN employees e ON e.employee_id = pf.employee_id
               LEFT JOIN users u ON u.user_id = pf.evaluator_id
              WHERE pf.performance_id = ?'
        );
        $get->execute([$performanceId]);
        $row = $get->fetch();

        if (!$row) {
            throw new ApiException('Performance evaluation not found.', 404, 'not_found');
        }
        api_require_self_or_hr($user, $row['employee_id']);

        $row['rubric_scores'] = self::decodeRubric($row['rubric_scores'] ?? null);
        api_respond($row);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------
    private static function decodeRubric(?string $json)
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : null;
    }
}