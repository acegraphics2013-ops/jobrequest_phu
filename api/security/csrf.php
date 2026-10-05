<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (empty($_SESSION['whe_csrf'])) {
    $_SESSION['whe_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['whe_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse(true, 'CSRF token retrieved', [
        'csrfToken' => $csrfToken
    ]);
}

/**
 * Validates CSRF token from X-CSRF-Token header or body for state-changing requests
 */
function verifyCsrfToken(): bool
{
    $stored = $_SESSION['whe_csrf'] ?? '';
    if (empty($stored)) {
        return false;
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($headerToken)) {
        $input = getJsonInput();
        $headerToken = $input['csrf_token'] ?? '';
    }

    return !empty($headerToken) && hash_equals((string)$stored, (string)$headerToken);
}
