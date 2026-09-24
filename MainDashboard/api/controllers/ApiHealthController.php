<?php
/**
 * Health / introspection endpoint.
 */
class ApiHealthController
{
    public static function index(array $params, ?array $user): void
    {
        api_respond([
            'service' => API_NAME,
            'version' => API_VERSION,
            'status'  => 'ok',
            'time'    => date(DATE_ATOM),
            'php'     => PHP_VERSION,
        ]);
    }
}