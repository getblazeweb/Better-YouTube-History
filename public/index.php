<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

require_login();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = db();
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 500;

$searchParam = $search !== '' ? $search : null;
$dateFromParam = $dateFrom !== '' ? $dateFrom : null;
$dateToParam = $dateTo !== '' ? $dateTo : null;

$totalCount = count_watch_history($pdo, $searchParam, $dateFromParam, $dateToParam);
$totalPages = $totalCount > 0 ? (int) ceil($totalCount / $perPage) : 1;
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$dateToPageMap = get_date_to_page_map($pdo, $perPage, $searchParam, $dateFromParam, $dateToParam);
$timelineDates = array_keys($dateToPageMap);

$history = get_watch_history(
    $pdo,
    $searchParam,
    $dateFromParam,
    $dateToParam,
    $perPage,
    $offset
);

$grouped = [];
foreach ($history as $item) {
    $date = substr((string) $item['watched_at'], 0, 10);
    if (!isset($grouped[$date])) {
        $grouped[$date] = [];
    }
    $grouped[$date][] = $item;
}
krsort($grouped);

$pageTitle = 'Watch History';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require base_path('views/dashboard.php');
