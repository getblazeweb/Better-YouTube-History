<?php
declare(strict_types=1);

function ensure_schema(PDO $pdo, string $schemaPath): void
{
    if (!file_exists($schemaPath)) {
        throw new RuntimeException('Schema file missing.');
    }
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('Failed to read schema.');
    }
    $pdo->exec($schema);
}

function run_migrations(PDO $pdo): void
{
    $stmt = $pdo->query('PRAGMA table_info(watch_history)');
    $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('channel_url', $columns, true)) {
        $pdo->exec('ALTER TABLE watch_history ADD COLUMN channel_url TEXT');
    }
}

function get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? (string) $row['value'] : null;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO app_settings (key, value)
        VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function log_login_attempt(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare('
        INSERT INTO login_attempts (username, ip_address, user_agent, success, reason, created_at)
        VALUES (:username, :ip_address, :user_agent, :success, :reason, :created_at)
    ');
    $stmt->execute([
        ':username' => $data['username'],
        ':ip_address' => $data['ip_address'],
        ':user_agent' => $data['user_agent'],
        ':success' => $data['success'],
        ':reason' => $data['reason'],
        ':created_at' => $data['created_at'],
    ]);
}

function count_failed_attempts(PDO $pdo, string $username, string $ip, int $windowSeconds): int
{
    $since = date('c', time() - $windowSeconds);
    $stmt = $pdo->prepare('
        SELECT COUNT(*) as total
        FROM login_attempts
        WHERE success = 0
          AND username = :username
          AND ip_address = :ip_address
          AND created_at >= :since
    ');
    $stmt->execute([
        ':username' => $username,
        ':ip_address' => $ip,
        ':since' => $since,
    ]);
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}

function get_login_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM login_settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    return $row ? (string) $row['value'] : $default;
}

function set_login_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('
        INSERT INTO login_settings (key, value)
        VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ');
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function list_login_attempts(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare('
        SELECT *
        FROM login_attempts
        ORDER BY created_at DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_watch_history(PDO $pdo, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null, int $limit = 1000, int $offset = 0): array
{
    $sql = 'SELECT * FROM watch_history WHERE 1=1';
    $params = [];

    if ($search !== null && $search !== '') {
        $sql .= ' AND (title LIKE :search OR channel LIKE :search2)';
        $like = '%' . $search . '%';
        $params[':search'] = $like;
        $params[':search2'] = $like;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND watched_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND watched_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql .= ' ORDER BY watched_at DESC LIMIT :limit OFFSET :offset';
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, str_contains($k, 'limit') || str_contains($k, 'offset') ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function upsert_watch_history(PDO $pdo, array $items): int
{
    if (empty($items)) {
        return 0;
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        INSERT INTO watch_history (video_id, title, channel, channel_url, watched_at, url, source, created_at)
        VALUES (:video_id, :title, :channel, :channel_url, :watched_at, :url, :source, :created_at)
        ON CONFLICT(video_id, watched_at) DO UPDATE SET
            title = excluded.title,
            channel = excluded.channel,
            channel_url = excluded.channel_url,
            url = excluded.url,
            source = excluded.source
    ');
    $now = date('c');
    $count = 0;
    try {
        foreach ($items as $item) {
            $stmt->execute([
                ':video_id' => $item['video_id'],
                ':title' => $item['title'] ?? '',
                ':channel' => $item['channel'] ?? '',
                ':channel_url' => $item['channel_url'] ?? '',
                ':watched_at' => $item['watched_at'],
                ':url' => $item['url'] ?? 'https://www.youtube.com/watch?v=' . $item['video_id'],
                ':source' => $item['source'] ?? 'takeout',
                ':created_at' => $now,
            ]);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $count;
}

/**
 * Returns distinct dates for the timeline, ordered newest first.
 */
function get_timeline_dates(PDO $pdo, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $sql = 'SELECT DISTINCT date(watched_at) as d FROM watch_history WHERE 1=1';
    $params = [];

    if ($search !== null && $search !== '') {
        $sql .= ' AND (title LIKE :search OR channel LIKE :search2)';
        $like = '%' . $search . '%';
        $params[':search'] = $like;
        $params[':search2'] = $like;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND watched_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND watched_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql .= ' ORDER BY d DESC';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'd');
}

/**
 * Returns a map of date => page number for all dates in the filtered history.
 * Uses a single query with window functions instead of N separate queries.
 */
function get_date_to_page_map(PDO $pdo, int $perPage, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $where = '1=1';
    $params = [];

    if ($search !== null && $search !== '') {
        $where .= ' AND (title LIKE :search OR channel LIKE :search2)';
        $like = '%' . $search . '%';
        $params[':search'] = $like;
        $params[':search2'] = $like;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $where .= ' AND watched_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where .= ' AND watched_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $sql = "WITH ranked AS (
        SELECT date(watched_at) as d, ROW_NUMBER() OVER (ORDER BY watched_at DESC) as rn
        FROM watch_history WHERE {$where}
    ),
    first_per_date AS (
        SELECT d, MIN(rn) as first_row FROM ranked GROUP BY d
    )
    SELECT d, ((first_row - 1) / :per_page) + 1 as page FROM first_per_date ORDER BY d DESC";
    $params[':per_page'] = $perPage;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $k === ':per_page' ? $v : $v, $k === ':per_page' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $map[$row['d']] = (int) max(1, $row['page']);
    }
    return $map;
}

function count_watch_history(PDO $pdo, ?string $search = null, ?string $dateFrom = null, ?string $dateTo = null): int
{
    $sql = 'SELECT COUNT(*) as total FROM watch_history WHERE 1=1';
    $params = [];

    if ($search !== null && $search !== '') {
        $sql .= ' AND (title LIKE :search OR channel LIKE :search2)';
        $like = '%' . $search . '%';
        $params[':search'] = $like;
        $params[':search2'] = $like;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND watched_at >= :date_from';
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND watched_at <= :date_to';
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    return (int) ($row['total'] ?? 0);
}
