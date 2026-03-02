<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/totp.php';

require_login();

$pdo = db();
$error = '';
$message = '';
$twoFactorEnabled = get_setting($pdo, 'two_factor_enabled') === '1';
$twoFactorSecret = $twoFactorEnabled ? (get_setting($pdo, 'two_factor_secret') ?? '') : '';
$username = (string) config('admin_username');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
$loginWindowMinutes = (int) (get_login_setting($pdo, 'login_window_minutes', '15') ?? 15);
$loginMaxAttempts = (int) (get_login_setting($pdo, 'login_max_attempts', '5') ?? 5);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string) ($_POST['csrf_token'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'enable') {
        $pendingSecret = (string) ($_SESSION['pending_2fa_secret'] ?? '');
        $code = trim((string) ($_POST['totp_code'] ?? ''));
        if ($pendingSecret === '') {
            $error = 'No pending secret found. Refresh and try again.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 0,
                'reason' => '2fa_enable_no_secret',
                'created_at' => date('c'),
            ]);
        } elseif (!totp_verify($pendingSecret, $code)) {
            $error = 'Invalid verification code.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 0,
                'reason' => '2fa_enable_invalid_code',
                'created_at' => date('c'),
            ]);
        } else {
            set_setting($pdo, 'two_factor_secret', $pendingSecret);
            set_setting($pdo, 'two_factor_enabled', '1');
            unset($_SESSION['pending_2fa_secret']);
            $twoFactorEnabled = true;
            $twoFactorSecret = $pendingSecret;
            $message = 'Two-factor authentication enabled.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 1,
                'reason' => '2fa_enabled',
                'created_at' => date('c'),
            ]);
        }
    }

    if ($action === 'disable') {
        $password = (string) ($_POST['password'] ?? '');
        $code = trim((string) ($_POST['totp_code'] ?? ''));

        if (!verify_credentials($username, $password)) {
            $error = 'Password is incorrect.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 0,
                'reason' => '2fa_disable_bad_password',
                'created_at' => date('c'),
            ]);
        } elseif ($twoFactorSecret === '' || !totp_verify($twoFactorSecret, $code)) {
            $error = 'Invalid verification code.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 0,
                'reason' => '2fa_disable_invalid_code',
                'created_at' => date('c'),
            ]);
        } else {
            set_setting($pdo, 'two_factor_enabled', '0');
            set_setting($pdo, 'two_factor_secret', '');
            $twoFactorEnabled = false;
            $twoFactorSecret = '';
            $message = 'Two-factor authentication disabled.';
            log_login_attempt($pdo, [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'success' => 1,
                'reason' => '2fa_disabled',
                'created_at' => date('c'),
            ]);
        }
    }

    if ($action === 'login_limits') {
        $loginWindowMinutes = max(1, (int) ($_POST['login_window_minutes'] ?? 15));
        $loginMaxAttempts = max(1, (int) ($_POST['login_max_attempts'] ?? 5));
        set_login_setting($pdo, 'login_window_minutes', (string) $loginWindowMinutes);
        set_login_setting($pdo, 'login_max_attempts', (string) $loginMaxAttempts);
        $message = 'Login limits updated.';
    }
}

if (!$twoFactorEnabled && empty($_SESSION['pending_2fa_secret'])) {
    $_SESSION['pending_2fa_secret'] = totp_generate_secret();
}
$pendingSecret = (string) ($_SESSION['pending_2fa_secret'] ?? '');

$issuer = rawurlencode((string) config('app_name'));
$account = rawurlencode((string) config('admin_username'));
$otpauth = $pendingSecret !== ''
    ? "otpauth://totp/{$issuer}:{$account}?secret={$pendingSecret}&issuer={$issuer}"
    : '';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security - <?php echo e((string) config('app_name')); ?></title>
    <link rel="stylesheet" href="<?php echo e(asset_url('assets/style.css')); ?>">
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <div class="brand"><?php echo e((string) config('app_name')); ?></div>
            <nav class="topbar-actions">
                <a href="<?php echo e(app_url('index.php')); ?>" class="link">Dashboard</a>
                <a href="<?php echo e(app_url('security.php')); ?>" class="link">Security</a>
                <a href="<?php echo e(app_url('logout.php')); ?>" class="link link-logout">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="card">
            <h1>Security</h1>
            <p class="muted">Manage two-factor authentication and login limits.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo e($message); ?></div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <div class="card-section">
                <h2>Login Limits</h2>
                <form method="post" class="form">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="login_limits">
                    <label>
                        Attempt window (minutes)
                        <input type="number" name="login_window_minutes" min="1" value="<?php echo (int) $loginWindowMinutes; ?>">
                    </label>
                    <label>
                        Max failed attempts
                        <input type="number" name="login_max_attempts" min="1" value="<?php echo (int) $loginMaxAttempts; ?>">
                    </label>
                    <button type="submit" class="button">Save Limits</button>
                </form>
            </div>

            <?php if (!$twoFactorEnabled): ?>
                <div class="alert alert-warn">Two-factor authentication is currently disabled.</div>
                <div class="card-section">
                    <div class="form">
                        <label>
                            Secret
                            <input type="text" value="<?php echo e($pendingSecret); ?>" readonly>
                        </label>
                        <?php if ($otpauth !== ''): ?>
                            <label>
                                OTP URI
                                <div class="qr-wrap">
                                    <div class="qr-box">
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=<?php echo urlencode($otpauth); ?>" alt="2FA QR Code">
                                    </div>
                                    <div class="qr-meta">
                                        <input type="text" value="<?php echo e($otpauth); ?>" readonly>
                                        <span class="helper-text">Scan the QR code with Google Authenticator, Authy, or similar.</span>
                                    </div>
                                </div>
                            </label>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="form" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="enable">
                        <label>
                            Verification Code
                            <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                        </label>
                        <button type="submit" class="button primary">Enable 2FA</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="alert alert-success">Two-factor authentication is enabled.</div>
                <div class="card-section">
                    <form method="post" class="form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="disable">
                        <label>
                            Password
                            <input type="password" name="password" required autocomplete="current-password">
                        </label>
                        <label>
                            Verification Code
                            <input type="text" name="totp_code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                        </label>
                        <button type="submit" class="button">Disable 2FA</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Recent Login Attempts</h2>
            <div class="table">
                <div class="table-row table-head">
                    <div>Time</div>
                    <div>Username</div>
                    <div>IP</div>
                    <div>Result</div>
                    <div>Reason</div>
                </div>
                <?php foreach (list_login_attempts($pdo, 50) as $attempt): ?>
                    <div class="table-row">
                        <div><?php echo e((string) $attempt['created_at']); ?></div>
                        <div><?php echo e((string) $attempt['username']); ?></div>
                        <div><?php echo e((string) $attempt['ip_address']); ?></div>
                        <div><?php echo (int) $attempt['success'] === 1 ? 'Success' : 'Fail'; ?></div>
                        <div><?php echo e((string) $attempt['reason']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</body>
</html>
