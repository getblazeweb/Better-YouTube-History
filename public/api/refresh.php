<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_takeout') {
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', '300');
}

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/TakeoutParser.php';

require_login();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();
$error = '';
$message = '';
$maxUploadBytes = 100 * 1024 * 1024;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string) ($_POST['csrf_token'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload_takeout') {
        $fileError = (int) ($_FILES['takeout_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
            $error = 'File too large. Increase PHP upload_max_filesize and post_max_size to at least 100M in php.ini.';
        } elseif ($fileError !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_NO_FILE => 'No file selected.',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not save the file.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            ];
            $error = $errors[$fileError] ?? 'Upload failed (error ' . $fileError . ').';
        } elseif (empty($_FILES['takeout_file']['tmp_name']) || !is_uploaded_file($_FILES['takeout_file']['tmp_name'])) {
            $error = 'Please select a file to upload.';
        } elseif ($_FILES['takeout_file']['size'] > $maxUploadBytes) {
            $error = 'File too large. Maximum 100MB.';
        } elseif ($_FILES['takeout_file']['size'] < 100) {
            $error = 'File appears empty or truncated. Check PHP upload_max_filesize and post_max_size (need at least 100M for large Takeout files).';
        } else {
            $content = file_get_contents($_FILES['takeout_file']['tmp_name']);
            if ($content === false) {
                $error = 'Failed to read uploaded file.';
            } else {
                try {
                    $items = parse_takeout_watch_history($content);
                    if (empty($items)) {
                        $size = strlen($content);
                        $hint = $size < 1000
                            ? ' File may be truncated—increase PHP upload_max_filesize and post_max_size to 100M.'
                            : ' Ensure you uploaded watch-history.html (not search-history.html) from Takeout/YouTube and YouTube Music/history/';
                        $error = 'No valid watch history entries found.' . $hint;
                    } else {
                        $count = upsert_watch_history($pdo, $items);
                        $_SESSION['flash'] = ['type' => 'success', 'message' => "Imported {$count} video(s) from Takeout."];
                        header('Location: ' . app_url('index.php'));
                        exit;
                    }
                } catch (Throwable $e) {
                    $error = 'Failed to parse file: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <title>Refresh History - <?php echo e((string) config('app_name')); ?></title>
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
        <div class="page-header">
            <h1>Refresh History</h1>
            <a href="<?php echo e(app_url('index.php')); ?>" class="button">Back to Dashboard</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?php echo e($flash['type'] ?? 'success'); ?>">
                <?php echo e($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Import from Google Takeout</h2>
            <p class="muted">Export your YouTube data from <a href="https://takeout.google.com" target="_blank" rel="noopener">takeout.google.com</a>, then extract and upload <code>Takeout/YouTube and YouTube Music/history/watch-history.html</code> (or <code>watch-history.json</code> for older exports). Large files (60MB+) require <code>upload_max_filesize</code> and <code>post_max_size</code> of at least 100M in php.ini.</p>
            <form method="post" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="upload_takeout">
                <label>
                    watch-history.html or watch-history.json
                    <input type="file" name="takeout_file" accept=".json,.html,application/json,text/html" required>
                </label>
                <button type="submit" class="button primary">Upload & Import</button>
            </form>
        </div>
    </main>
</body>
</html>
