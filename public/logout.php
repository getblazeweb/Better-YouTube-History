<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

logout();
header('Location: ' . app_url('login.php'));
exit;
