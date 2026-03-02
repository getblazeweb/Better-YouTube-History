<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return !empty($_SESSION['user_logged_in']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . app_url('login.php'));
        exit;
    }
}

function verify_credentials(string $username, string $password): bool
{
    $expectedUser = (string) config('admin_username');
    $expectedHash = (string) config('admin_password_hash');

    if ($expectedHash === '' || $expectedUser === '') {
        return false;
    }

    if (!hash_equals($expectedUser, $username)) {
        return false;
    }

    return password_verify($password, $expectedHash);
}

function login_user(string $username): void
{
    session_regenerate_id(true);
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_name'] = $username;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
