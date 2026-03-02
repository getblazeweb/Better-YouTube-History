<?php
declare(strict_types=1);

$basePath = dirname(__DIR__);

function load_env(string $path): void
{
    if (!file_exists($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

load_env($basePath . '/.env');
$config = require $basePath . '/config/app.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

if (!is_dir($basePath . '/data')) {
    mkdir($basePath . '/data', 0700, true);
}

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

session_name('better_youtube_history_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/env_helper.php';

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__);
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    $webBase = (string) config('web_base');
    $path = ltrim($path, '/');
    return ($webBase !== '' ? rtrim($webBase, '/') . '/' : '/') . $path;
}

function app_url(string $path): string
{
    $path = ltrim($path, '/');
    $query = '';
    if (str_contains($path, '?')) {
        [$path, $q] = explode('?', $path, 2);
        $query = '?' . $q;
    }
    return asset_url($path) . $query;
}

function config(string $key, mixed $default = null): mixed
{
    global $config;
    return $config[$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = base_path('data/history.db');
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    ensure_schema($pdo, base_path('migrations/001_init.sql'));
    run_migrations($pdo);

    return $pdo;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): void
{
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        echo 'Invalid CSRF token.';
        exit;
    }
}
