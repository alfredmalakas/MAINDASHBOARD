<?php
/**
 * HRIS REST API Microservice — Front Controller / Router
 * ======================================================
 * Every request to /api/... lands here (see api/.htaccess).
 *
 * URL styles accepted:
 *   /api/v1/employees                 (clean URL, requires mod_rewrite)
 *   /api/index.php?route=v1/employees (query-string fallback)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    api_cors();

    $route  = api_route();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $matched = api_dispatch($route, $method);

    if (!$matched) {
        api_error("No such endpoint: {$method} /{$route}", 404, 'not_found');
    }
} catch (ApiException $e) {
    api_error($e->getMessage(), $e->status, $e->codeKey);
} catch (Throwable $e) {
    error_log('[HRIS-API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_error('Internal server error. Please try again later.', 500, 'server_error');
}

// ---------------------------------------------------------------------------
// Resolve the route to dispatch.
// ---------------------------------------------------------------------------
function api_route(): string
{
    $route = trim((string)($_GET['route'] ?? ''), '/');
    if ($route !== '') {
        return $route;
    }

    // Fallback: derive from REQUEST_URI relative to this file (PATH_INFO style).
    $scriptDir = str_replace('\\', '/', rtrim((string)dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'));
    $uriPath   = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    if ($scriptDir !== '' && $scriptDir !== '/' && strpos($uriPath, $scriptDir) === 0) {
        $path = substr($uriPath, strlen($scriptDir));
    } else {
        $path = $uriPath;
    }

    $path = trim($path, '/');
    $path = preg_replace('#^index\.php/?#', '', $path) ?? '';
    return trim($path, '/');
}

// ---------------------------------------------------------------------------
// Route table. Each entry: [verb(s), regex, ControllerClass, action, guard]
// guards: public | auth (any authenticated user) | admin (HR only)
// Order matters — more specific patterns must come first.
// ---------------------------------------------------------------------------
function api_routes(): array
{
    return [
        // health & introspection
        ['GET',    '#^v1/health$#',                    'ApiHealthController',      'index',       'public'],

        // authentication
        ['POST',   '#^v1/auth/login$#',                'ApiAuthController',        'login',       'public'],
        ['POST',   '#^v1/auth/logout$#',               'ApiAuthController',        'logout',      'auth'],
        ['GET',    '#^v1/auth/tokens$#',               'ApiAuthController',        'tokens',      'auth'],
        ['DELETE', '#^v1/auth/tokens/(?P<tokenId>\d+)$#', 'ApiAuthController',     'revokeToken', 'auth'],

        // incoming integrations
        ['POST',   '#^v1/integration/files$#',          'ApiIntegrationController',  'receiveFile',  'admin'],

        // employees
        ['GET',    '#^v1/employees/me$#',              'ApiEmployeesController',   'me',          'auth'],
        ['GET',    '#^v1/employees$#',                 'ApiEmployeesController',   'index',       'admin'],
        ['POST',   '#^v1/employees$#',                 'ApiEmployeesController',   'create',      'admin'],
        ['GET',    '#^v1/employees/(?P<id>[^/]+)$#',   'ApiEmployeesController',   'show',        'auth'],
        ['PUT',    '#^v1/employees/(?P<id>[^/]+)$#',   'ApiEmployeesController',   'update',      'admin'],
        ['DELETE', '#^v1/employees/(?P<id>[^/]+)$#',   'ApiEmployeesController',   'delete',      'admin'],

        // attendance
        ['GET',    '#^v1/attendance/me$#',             'ApiAttendanceController',  'me',          'auth'],
        ['POST',   '#^v1/attendance/time-in$#',        'ApiAttendanceController',  'timeIn',      'auth'],
        ['POST',   '#^v1/attendance/time-out$#',       'ApiAttendanceController',  'timeOut',     'auth'],
        ['POST',   '#^v1/attendance/records$#',        'ApiAttendanceController',  'upsert',      'admin'],
        ['GET',    '#^v1/attendance/report$#',         'ApiAttendanceController',  'report',      'admin'],
        ['GET',    '#^v1/attendance$#',                'ApiAttendanceController',  'index',       'admin'],

        // leave
        ['GET',    '#^v1/leave/types$#',               'ApiLeaveController',       'types',       'auth'],
        ['GET',    '#^v1/leave/balances$#',            'ApiLeaveController',       'balances',    'auth'],
        ['GET',    '#^v1/leave$#',                     'ApiLeaveController',       'index',       'auth'],
        ['POST',   '#^v1/leave$#',                     'ApiLeaveController',       'create',      'auth'],
        ['POST',   '#^v1/leave/(?P<id>\d+)/approve$#', 'ApiLeaveController',       'approve',     'admin'],
        ['POST',   '#^v1/leave/(?P<id>\d+)/reject$#',  'ApiLeaveController',       'reject',      'admin'],
        ['GET',    '#^v1/leave/(?P<id>\d+)$#',         'ApiLeaveController',       'show',        'auth'],

        // payroll
        ['POST',   '#^v1/payroll/generate$#',          'ApiPayrollController',     'generate',    'admin'],
        ['GET',    '#^v1/payroll$#',                   'ApiPayrollController',     'index',       'auth'],
        ['GET',    '#^v1/payroll/(?P<id>\d+)/summary$#', 'ApiPayrollController',     'summary',     'auth'],
        ['GET',    '#^v1/payroll/(?P<id>\d+)$#',       'ApiPayrollController',     'show',        'auth'],

        // performance
        ['GET',    '#^v1/performance/rubric$#',        'ApiPerformanceController', 'rubric',      'admin'],
        ['GET',    '#^v1/performance$#',               'ApiPerformanceController', 'index',       'auth'],
        ['POST',   '#^v1/performance$#',               'ApiPerformanceController', 'create',      'admin'],
        ['GET',    '#^v1/performance/(?P<id>\d+)$#',   'ApiPerformanceController', 'show',        'auth'],
    ];
}

// ---------------------------------------------------------------------------
// Match the route, resolve guard, and hand off to the controller action.
// ---------------------------------------------------------------------------
function api_dispatch(string $route, string $method): bool
{
    $matchedPattern = false;

    foreach (api_routes() as [$verbs, $pattern, $controller, $action, $guard]) {
        if (!preg_match($pattern, $route, $matches)) {
            continue;
        }

        $matchedPattern = true;
        $verbs = is_array($verbs) ? $verbs : [$verbs];

        if (!in_array($method, $verbs, true)) {
            // The path matches a route with a different verb — keep checking;
            // a later entry may declare this verb for the same path.
            continue;
        }

        $file = __DIR__ . '/controllers/' . $controller . '.php';
        if (is_file($file)) {
            require_once $file;
        } else {
            throw new ApiException("Controller not found: {$controller}", 500, 'server_error');
        }

        $user = null;
        if ($guard === 'auth' || $guard === 'admin') {
            $user = api_current_user();
        }
        if ($guard === 'admin' && $user !== null) {
            api_require_role($user, 'HR Administrator');
        }

        // Named regex captures (e.g. (?P<id>...)) become the $params argument.
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

        call_user_func([$controller, $action], $params, $user);
        return true;
    }

    if ($matchedPattern) {
        api_error("Method {$method} is not allowed for this endpoint.", 405, 'method_not_allowed');
    }

    return false;
}