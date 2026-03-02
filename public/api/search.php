<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

require_login();

header('Content-Type: application/json');

$pdo = db();
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));

$history = get_watch_history(
    $pdo,
    $search !== '' ? $search : null,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null,
    $limit
);

echo json_encode([
    'items' => $history,
    'count' => count($history),
]);
