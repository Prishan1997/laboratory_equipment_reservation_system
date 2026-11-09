<?php
/**
 * Authentication & Role-Based Access Control (RBAC) Module
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

// Initialize secure session if not already active
if (session_status() === PHP_SESSION_NONE) {
    // Enforce NFR-3: HttpOnly and SameSite=Strict cookies
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path'     => '/',
        'domain'   => $cookieParams['domain'],
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

/**
 * Generate or retrieve the existing CSRF token for the session.
 */
function getCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF token input field for forms.
 */
function csrfField(): string
{
    $token = getCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Validate an incoming CSRF token against the session token.
 */
function validateCSRFToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if a user is currently authenticated.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && !empty($_SESSION['user']['user_id']);
}

/**
 * Retrieve current logged-in user array or null.
 */
function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Check if the logged-in user possesses one of the allowed roles.
 *
 * @param string|array $roles Single role or array of permitted roles
 */
function hasRole($roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    $userRole = $_SESSION['user']['role'] ?? '';
    if (is_array($roles)) {
        return in_array($userRole, $roles, true);
    }
    return $userRole === $roles;
}

/**
 * Guard that enforces login, redirecting unauthenticated users to login.php.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Please sign in with your university credentials to access this portal.';
        header('Location: login.php');
        exit;
    }
}

/**
 * Guard enforcing role authorization.
 *
 * @param string|array $roles
 */
function requireRole($roles): void
{
    requireLogin();
    if (!hasRole($roles)) {
        http_response_code(403);
        $_SESSION['flash_error'] = 'Access Denied: You do not hold required administrative authorization for that view.';
        header('Location: catalog.php');
        exit;
    }
}

/**
 * Authenticate and log in a user record.
 * Regenerates session ID to prevent session fixation attacks (NFR-3).
 */
function loginUser(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id'    => (int)$user['user_id'],
        'id_number'  => $user['id_number'],
        'full_name'  => $user['full_name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'cert_tier'  => $user['cert_tier'] ?? 'Tier 1',
        'department' => $user['department'] ?? 'Faculty of Science'
    ];
}

/**
 * Terminate user session and purge all session credentials.
 */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Sanitize string output to prevent XSS (NFR-2).
 */
function sanitize(?string $string): string
{
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}
