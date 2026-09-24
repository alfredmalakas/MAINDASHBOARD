<?php
/**
 * HRIS REST API Microservice — Bootstrap
 * =======================================
 * Shared runtime helpers for the headless HRIS API:
 *   - PDO connection (reuses config/db.php)
 *   - auto-provisioning of the api_tokens table
 *   - JSON request/response helpers
 *   - Bearer-token authentication + role guards
 *   - pagination, date & general validation helpers
 *
 * The microservice is stateless: it never starts a PHP session and never
 * touches $_SESSION. Every authenticated request uses its own Bearer token.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payroll.php';

const API_NAME      = 'HRIS REST API';
const API_VERSION   = 'v1';
// Default token lifetime in seconds (7 days). Override via HRIS_API_TOKEN_TTL.
const API_TOKEN_TTL = 604800;

// ---------------------------------------------------------------------------
// Auto-provision API token storage (idempotent; also shipped in hris_db.sql).
// ---------------------------------------------------------------------------
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS api_tokens (
        token_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL DEFAULT 'API Token',
        expires_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB"
);

// ---------------------------------------------------------------------------
// Domain exception thrown by controllers; rendered by the front controller.
// ---------------------------------------------------------------------------
class ApiException extends Exception
{
    public int $status;
    public string $codeKey;

    public function __construct(string $message, int $status = 400, string $codeKey = 'bad_request')
    {
        parent::__construct($message);
        $this->status  = $status;
        $this->codeKey = $codeKey;
    }
}

// ---------------------------------------------------------------------------
// JSON responses
// ---------------------------------------------------------------------------
function api_respond($data, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['data' => $data];
    foreach ($extra as $k => $v) {
        $body[$k] = $v;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(string $message, int $status, ?string $code = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code'    => $code ?? api_status_code($status),
            'message' => $message,
            'status'  => $status,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_status_code(int $status): string
{
    $map = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        422 => 'validation_error',
        500 => 'server_error',
    ];
    return $map[$status] ?? 'error';
}

// ---------------------------------------------------------------------------
// CORS
// ---------------------------------------------------------------------------
function api_cors(): void
{
    $origin = getenv('HRIS_API_CORS_ORIGIN') ?: '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Request body parsing (JSON or form-encoded)
// ---------------------------------------------------------------------------
function api_body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    $parsed = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $json = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Invalid JSON payload: ' . json_last_error_msg(), 400, 'invalid_json');
            }
            $parsed = is_array($json) ? $json : [];
        }
    } else {
        $parsed = $_POST ?? [];
    }
    return $parsed;
}

function api_val(array $data, string $key, $default = null)
{
    if (isset($data[$key]) && $data[$key] !== '') {
        return $data[$key];
    }
    return $default;
}

function api_require(array $data, string $key, string $label): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '') {
        throw new ApiException(ucfirst($label) . ' is required.', 422, 'validation_error');
    }
    return $value;
}

// ---------------------------------------------------------------------------
// Token authentication
// ---------------------------------------------------------------------------
function api_hash_token(string $raw): string
{
    return hash('sha256', $raw);
}

function api_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string)$value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }
    return null;
}

function api_current_user(): array
{
    global $pdo;

    $raw = api_bearer_token();
    if ($raw === null) {
        throw new ApiException('Authentication required. Send the token as: Authorization: Bearer <token>.', 401, 'missing_token');
    }

    $stmt = $pdo->prepare(
        'SELECT t.token_id, t.user_id, t.expires_at,
                u.username, u.role, u.employee_id, u.status
           FROM api_tokens t
           JOIN users u ON u.user_id = t.user_id
          WHERE t.token_hash = ?'
    );
    $stmt->execute([api_hash_token($raw)]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ApiException('Invalid or revoked token.', 401, 'invalid_token');
    }
    if ($row['status'] !== 'Active') {
        throw new ApiException('User account is inactive.', 403, 'account_inactive');
    }
    if ($row['expires_at'] !== null && strtotime((string)$row['expires_at']) < time()) {
        throw new ApiException('Token has expired. Log in again to get a new token.', 401, 'token_expired');
    }

    $tick = $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_id = ?');
    $tick->execute([$row['token_id']]);

    return $row;
}

function api_is_hr(array $user): bool
{
    return ($user['role'] ?? '') === 'HR Administrator';
}

function api_require_role(array $user, string $role): void
{
    if (($user['role'] ?? '') !== $role) {
        throw new ApiException("Access denied. The '{$role}' role is required.", 403, 'forbidden');
    }
}

function api_require_self_or_hr(array $user, string $employeeId): void
{
    if (api_is_hr($user)) {
        return;
    }
    if (($user['employee_id'] ?? null) !== $employeeId) {
        throw new ApiException('Access denied. You may only access your own records.', 403, 'forbidden');
    }
}

function api_own_employee_id(array $user): string
{
    $employeeId = $user['employee_id'] ?? '';
    if ($employeeId === '' || $employeeId === null) {
        throw new ApiException('Your account is not linked to an employee profile.', 404, 'no_employee_profile');
    }
    return (string)$employeeId;
}

function api_public_user(array $user): array
{
    unset($user['password']);
    return $user;
}

function api_full_name(PDO $pdo, ?string $employeeId): ?string
{
    if ($employeeId === null || $employeeId === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) AS full_name FROM employees WHERE employee_id = ?');
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch();
    return $row['full_name'] ?? null;
}

// ---------------------------------------------------------------------------
// Pagination helpers
// ---------------------------------------------------------------------------
function api_page_info(): array
{
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 20);
    if ($perPage < 1)  $perPage = 20;
    if ($perPage > 100) $perPage = 100;
    return [$page, $perPage, ($page - 1) * $perPage];
}

function api_meta(int $page, int $perPage, int $total): array
{
    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / max(1, $perPage)),
    ];
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------
function api_date_value($value, string $label, bool $allowEmpty = false): ?string
{
    if ($value === null || $value === '') {
        if ($allowEmpty) {
            return null;
        }
        throw new ApiException(ucfirst($label) . ' is required.', 422, 'validation_error');
    }
    $value = (string)$value;
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new ApiException(ucfirst($label) . ' must be a valid date (YYYY-MM-DD).', 422, 'validation_error');
    }
    return $value;
}

// ---------------------------------------------------------------------------
// Employee ID generator — matches modules/employees/add.php (EMP-YYYY-NNNN)
// ---------------------------------------------------------------------------
function api_unique_employee_id(PDO $pdo): string
{
    $count = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE employee_id LIKE ?');
    $count->execute(['EMP-' . date('Y') . '-%']);
    return sprintf('EMP-%s-%04d', date('Y'), ((int)$count->fetchColumn()) + 1);
}