<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/totp.php';

if (is_logged_in()) {
    header('Location: ' . app_url('index.php'));
    exit;
}

$error = '';
$pdo = db();
$twoFactorEnabled = get_setting($pdo, 'two_factor_enabled') === '1';
$twoFactorSecret = $twoFactorEnabled ? (get_setting($pdo, 'two_factor_secret') ?? '') : '';
$loginWindowMinutes = (int) (get_login_setting($pdo, 'login_window_minutes', '15') ?? 15);
$loginMaxAttempts = (int) (get_login_setting($pdo, 'login_max_attempts', '5') ?? 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string) ($_POST['csrf_token'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $totpCode = trim((string) ($_POST['totp_code'] ?? ''));
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

    $windowSeconds = $loginWindowMinutes * 60;
    if (count_failed_attempts($pdo, $username, $ip, $windowSeconds) >= $loginMaxAttempts) {
        log_login_attempt($pdo, [
            'username' => $username,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'success' => 0,
            'reason' => 'rate_limited',
            'created_at' => date('c'),
        ]);
        $error = 'Too many failed attempts. Please wait and try again.';
    } elseif (!verify_credentials($username, $password)) {
        log_login_attempt($pdo, [
            'username' => $username,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'success' => 0,
            'reason' => 'invalid_credentials',
            'created_at' => date('c'),
        ]);
        $error = 'Invalid username or password.';
    } elseif ($twoFactorEnabled && ($twoFactorSecret === '' || !totp_verify($twoFactorSecret, $totpCode))) {
        log_login_attempt($pdo, [
            'username' => $username,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'success' => 0,
            'reason' => 'invalid_2fa',
            'created_at' => date('c'),
        ]);
        $error = 'Invalid verification code.';
    } else {
        login_user($username);
        log_login_attempt($pdo, [
            'username' => $username,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'success' => 1,
            'reason' => 'success',
            'created_at' => date('c'),
        ]);
        header('Location: ' . app_url('index.php'));
        exit;
    }
}

$configReady = config('admin_password_hash') !== '' && config('app_key') !== '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - <?php echo htmlspecialchars((string) config('app_name'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(asset_url('assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-header">
            <div class="auth-icon" aria-hidden="true">BYH</div>
            <div>
                <h1 class="auth-title"><?php echo htmlspecialchars((string) config('app_name'), ENT_QUOTES, 'UTF-8'); ?></h1>
                <p class="muted auth-subtitle">Sign in to continue</p>
            </div>
        </div>

        <?php if (!$configReady): ?>
            <div class="alert alert-warn">
                Admin password hash or APP_KEY is missing. Set <code>ADMIN_PASSWORD_HASH</code> and <code>APP_KEY</code> in your .env file.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" class="form auth-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <label>
                Username
                <input type="text" name="username" required autocomplete="username">
            </label>
            <label>
                Password
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <?php if ($twoFactorEnabled): ?>
                <label>
                    Verification Code
                    <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                </label>
            <?php endif; ?>
            <button type="submit" class="button primary">Sign in</button>
        </form>
    </div>
</body>
</html>
