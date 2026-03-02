<?php
declare(strict_types=1);

/**
 * Updates or appends key=value pairs in the .env file.
 * Preserves other lines, comments, and order.
 *
 * @param array<string, string> $vars Key => value pairs to set
 * @return bool True on success, false on failure
 */
function update_env_vars(array $vars): bool
{
    $envPath = dirname(__DIR__) . '/.env';

    if (!file_exists($envPath)) {
        $content = '';
        $lines = [];
    } else {
        $content = file_get_contents($envPath);
        if ($content === false) {
            return false;
        }
        $lines = explode("\n", $content);
    }

    $updated = [];
    foreach ($vars as $key => $value) {
        $updated[$key] = false;
        $vars[$key] = str_replace(["\r", "\n"], '', (string) $value);
    }

    $result = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $result[] = $line;
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            $result[] = $line;
            continue;
        }
        $existingKey = trim($parts[0]);
        if (isset($vars[$existingKey])) {
            $value = $vars[$existingKey];
            $result[] = $existingKey . '=' . $value;
            $updated[$existingKey] = true;
        } else {
            $result[] = $line;
        }
    }

    foreach ($vars as $key => $value) {
        if (!$updated[$key]) {
            $result[] = $key . '=' . $value;
        }
    }

    $newContent = implode("\n", $result);
    if (!empty($lines) && !str_ends_with($newContent, "\n")) {
        $newContent .= "\n";
    }

    return file_put_contents($envPath, $newContent, LOCK_EX) !== false;
}
