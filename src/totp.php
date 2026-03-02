<?php
declare(strict_types=1);

function totp_generate_secret(int $length = 20): string
{
    $bytes = random_bytes($length);
    return totp_base32_encode($bytes);
}

function totp_verify(string $secret, string $code, int $window = 1, int $period = 30): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if ($code === null || $code === '') {
        return false;
    }
    $code = str_pad($code, 6, '0', STR_PAD_LEFT);

    $timeStep = (int) floor(time() / $period);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_at($secret, $timeStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_at(string $secret, int $timeStep): string
{
    $key = totp_base32_decode($secret);
    $binTime = pack('N*', 0) . pack('N*', $timeStep);
    $hash = hash_hmac('sha1', $binTime, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $chunk = substr($hash, $offset, 4);
    $value = unpack('N', $chunk)[1] & 0x7FFFFFFF;
    $code = $value % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

function totp_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $output = '';
    $chunks = str_split($binary, 5);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $index = bindec($chunk);
        $output .= $alphabet[$index];
    }

    return $output;
}

function totp_base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper($input);
    $input = preg_replace('/[^A-Z2-7]/', '', $input) ?? '';

    $binary = '';
    foreach (str_split($input) as $char) {
        $index = strpos($alphabet, $char);
        if ($index === false) {
            continue;
        }
        $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $output = '';
    $bytes = str_split($binary, 8);
    foreach ($bytes as $byte) {
        if (strlen($byte) !== 8) {
            continue;
        }
        $output .= chr(bindec($byte));
    }

    return $output;
}
