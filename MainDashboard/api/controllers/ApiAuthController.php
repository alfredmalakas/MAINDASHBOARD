<?php
/**
 * Authentication controller.
 *
 * Issues and manages Bearer tokens. Tokens are stored only as SHA-256 hashes;
 * the plain token is returned exactly once at login.
 */
class ApiAuthController
{
    /**
     * POST /v1/auth/login  {"username","password","expires_in"?}
     */
    public static function login(array $params, ?array $user): void
    {
        global $pdo;

        $body     = api_body();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $expires  = (int)($body['expires_in'] ?? API_TOKEN_TTL);

        if ($username === '' || $password === '') {
            throw new ApiException('Username and password are required.', 422, 'validation_error');
        }
        if ($expires < 300) {
            $expires = 300;
        }
        if ($expires > 31536000) {
            $expires = 31536000; // cap token lifetime at 1 year
        }
        $ttl = (int)getenv('HRIS_API_TOKEN_TTL');
        if ($ttl < 1) {
            $ttl = $expires;
        }

        $stmt = $pdo->prepare('SELECT user_id, username, password, role, employee_id, status FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $account = $stmt->fetch();

        if (!$account || !password_verify($password, (string)$account['password'])) {
            throw new ApiException('Invalid username or password.', 401, 'invalid_credentials');
        }
        if ($account['status'] !== 'Active') {
            throw new ApiException('This account is inactive. Contact the HR administrator.', 403, 'account_inactive');
        }

        $raw = bin2hex(random_bytes(32));

        $ins = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, name, expires_at)
             VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $ins->execute([
            $account['user_id'],
            api_hash_token($raw),
            'Login ' . date('Y-m-d H:i:s'),
            $ttl,
        ]);

        $account['full_name'] = api_full_name($pdo, $account['employee_id']);

        api_respond([
            'access_token' => $raw,
            'token_type'   => 'Bearer',
            'expires_in'   => $ttl,
            'expires_at'   => date(DATE_ATOM, time() + $ttl),
            'user'         => api_public_user($account),
        ]);
    }

    /**
     * POST /v1/auth/logout — revokes the presented token.
     */
    public static function logout(array $params, array $user): void
    {
        global $pdo;

        $raw = api_bearer_token();
        if ($raw !== null) {
            $del = $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = ?');
            $del->execute([api_hash_token($raw)]);
        }

        api_respond(['ok' => true, 'message' => 'Token revoked.']);
    }

    /**
     * GET /v1/auth/tokens — list active tokens (own, or any user for HR).
     */
    public static function tokens(array $params, array $user): void
    {
        global $pdo;

        $userId = (int)$user['user_id'];
        if (api_is_hr($user) && !empty($_GET['user_id'])) {
            $userId = (int)$_GET['user_id'];
        }

        $stmt = $pdo->prepare(
            'SELECT token_id, name, expires_at, last_used_at, created_at
               FROM api_tokens
              WHERE user_id = ?
              ORDER BY token_id DESC'
        );
        $stmt->execute([$userId]);

        api_respond($stmt->fetchAll());
    }

    /**
     * DELETE /v1/auth/tokens/{tokenId} — revoke a token (own, or any for HR).
     */
    public static function revokeToken(array $params, array $user): void
    {
        global $pdo;

        $tokenId = (int)$params['tokenId'];

        $get = $pdo->prepare('SELECT * FROM api_tokens WHERE token_id = ?');
        $get->execute([$tokenId]);
        $token = $get->fetch();

        if (!$token) {
            throw new ApiException('Token not found.', 404, 'not_found');
        }
        if (!api_is_hr($user) && (int)$token['user_id'] !== (int)$user['user_id']) {
            throw new ApiException('You may only revoke your own tokens.', 403, 'forbidden');
        }

        $del = $pdo->prepare('DELETE FROM api_tokens WHERE token_id = ?');
        $del->execute([$tokenId]);

        api_respond(['ok' => true, 'message' => 'Token revoked.']);
    }
}