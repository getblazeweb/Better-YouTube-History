<?php
declare(strict_types=1);

/**
 * Parses watch history from Google Takeout (YouTube export).
 * Supports both JSON (legacy) and HTML (current) formats.
 */
function parse_takeout_watch_history(string $content): array
{
    $content = trim($content);
    if ($content === '') {
        return [];
    }

    if (str_starts_with($content, '[') || str_starts_with($content, '{')) {
        return parse_takeout_watch_history_json($content);
    }
    if (stripos($content, '<html') !== false || stripos($content, 'youtube.com/watch') !== false) {
        return parse_takeout_watch_history_html($content);
    }

    throw new RuntimeException('Unrecognized format. Expected watch-history.json or watch-history.html from Google Takeout.');
}

/**
 * Parses watch-history.json (legacy Takeout format).
 */
function parse_takeout_watch_history_json(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON or empty watch history.');
    }

    $items = [];
    foreach ($data as $entry) {
        $url = (string) ($entry['titleUrl'] ?? '');
        if ($url === '') {
            continue;
        }

        $videoId = extract_video_id($url);
        if ($videoId === '') {
            continue;
        }

        $title = extract_video_title((string) ($entry['title'] ?? ''));
        $channel = '';
        if (!empty($entry['subtitles'][0]['name'])) {
            $channel = (string) $entry['subtitles'][0]['name'];
        }

        $time = (string) ($entry['time'] ?? '');
        if ($time === '') {
            continue;
        }

        $items[] = [
            'video_id' => $videoId,
            'title' => $title,
            'channel' => $channel,
            'watched_at' => normalize_takeout_time($time),
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'source' => 'takeout',
        ];
    }

    return $items;
}

function extract_video_id(string $url): string
{
    if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#youtu\.be/([a-zA-Z0-9_-]{11})#', $url, $m)) {
        return $m[1];
    }
    return '';
}

function extract_video_title(string $title): string
{
    $title = trim($title);
    if (str_starts_with($title, 'Watched ')) {
        return trim(substr($title, 8));
    }
    return $title;
}

/**
 * Normalize Takeout date string to Y-m-d H:i:s.
 * Uses DateTime to preserve the date in the original timezone (e.g. EST),
 * avoiding off-by-one when entries are near midnight.
 */
function normalize_takeout_time(string $time): string
{
    $time = preg_replace('/[\x{FFFD}\x{202F}\x{00A0}?]/u', ' ', $time);
    $time = trim($time);
    try {
        $dt = new DateTime($time);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $ts = strtotime($time);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : $time;
    }
}

/**
 * Parses watch-history.html (current Takeout format).
 * Structure: Watched <a href="...watch?v=ID">Title</a><br><a href="...channel/...">Channel</a><br>Date<br>
 */
function parse_takeout_watch_history_html(string $html): array
{
    $items = [];
    $pattern = '/Watched\s*<a\s+href="(https:\/\/www\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11}))">([^<]*)<\/a>(?:<[^>]*>\s*)(?:<a\s+href="(https:\/\/www\.youtube\.com\/channel\/[^"]+)">([^<]*)<\/a>)?(?:<[^>]*>\s*)([^<]+?)(?:<|$)/si';

    $limit = ini_get('pcre.backtrack_limit');
    if ($limit !== false && is_numeric($limit) && (int) $limit < 10000000) {
        @ini_set('pcre.backtrack_limit', '10000000');
    }

    if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false || empty($matches)) {
        $items = parse_takeout_watch_history_html_fallback($html);
        if (!empty($items)) {
            return $items;
        }
        return [];
    }

    foreach ($matches as $m) {
        $videoId = $m[2];
        $title = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $channelUrl = isset($m[4]) && $m[4] !== '' ? trim($m[4]) : '';
        $channel = isset($m[5]) && $m[5] !== '' ? html_entity_decode(trim($m[5]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $dateStr = html_entity_decode(trim($m[6] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $dateStr = preg_replace('/[\x{FFFD}\x{202F}\x{00A0}?]/u', ' ', $dateStr);
        $dateStr = trim($dateStr);

        if (strtotime($dateStr) === false) {
            continue;
        }
        $watchedAt = normalize_takeout_time($dateStr);

        $items[] = [
            'video_id' => $videoId,
            'title' => $title ?: 'Video',
            'channel' => $channel,
            'channel_url' => $channelUrl,
            'watched_at' => $watchedAt,
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'source' => 'takeout',
        ];
    }

    return $items;
}

/**
 * Fallback: extract video links and dates when main pattern fails.
 * Searches for date pattern (e.g. "Mar 1, 2026, 3:20:09 PM EST") in context after each video link.
 */
function parse_takeout_watch_history_html_fallback(string $html): array
{
    $pattern = '/<a\s+href="https:\/\/www\.youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})">([^<]*)<\/a>/';
    if (preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
        return [];
    }

    $datePatterns = [
        '/([A-Za-z]{3,9}\s+\d{1,2},\s+\d{4},\s+\d{1,2}:\d{2}:\d{2}\s*(?:AM|PM)\s*[A-Z]{2,6})/',
        '/([A-Za-z]{3,9}\s+\d{1,2},\s+\d{4},\s+\d{1,2}:\d{2}:\d{2}\s*(?:AM|PM))/',
        '/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/',
    ];
    $items = [];
    $seen = [];

    foreach ($matches[0] as $i => $fullMatch) {
        $videoId = $matches[1][$i][0];
        $title = html_entity_decode(trim($matches[2][$i][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $key = $videoId . '_' . substr(md5($title), 0, 8);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $endPos = $fullMatch[1] + strlen($fullMatch[0]);
        $context = substr($html, $endPos, 500);
        $context = preg_replace('/[\x{FFFD}\x{202F}\x{00A0}?]/u', ' ', $context);
        $watchedAt = date('Y-m-d H:i:s', time());
        foreach ($datePatterns as $dp) {
            if (preg_match($dp, $context, $dm)) {
                $dateStr = trim($dm[1]);
                if (strtotime($dateStr) !== false) {
                    $watchedAt = normalize_takeout_time($dateStr);
                    break;
                }
            }
        }

        $channel = '';
        $channelUrl = '';
        if (preg_match('/<a\s+href="(https:\/\/www\.youtube\.com\/channel\/[^"]+)">([^<]*)<\/a>/', $context, $cm)) {
            $channelUrl = trim($cm[1]);
            $channel = html_entity_decode(trim($cm[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $items[] = [
            'video_id' => $videoId,
            'title' => $title ?: 'Video',
            'channel' => $channel,
            'channel_url' => $channelUrl,
            'watched_at' => $watchedAt,
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'source' => 'takeout',
        ];
    }

    return $items;
}
