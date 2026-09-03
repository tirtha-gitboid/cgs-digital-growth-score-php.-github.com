<?php
/**
 * Config loader for CGS Digital Growth Score (PHP port).
 * Mirrors backend/main.py's use of python-dotenv: reads a .env file
 * (if present) into the environment without overwriting real env vars
 * already set by the hosting platform.
 */

define('BASE_DIR', __DIR__);
define('FRONTEND_DIR', BASE_DIR . '/frontend');
define('DATA_DIR', BASE_DIR . '/data');

function cgs_load_dotenv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Strip matching surrounding quotes.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

cgs_load_dotenv(BASE_DIR . '/.env');

function cgs_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return $v === false ? $default : $v;
}

const USER_AGENT = 'CGS-Digital-Growth-Score/1.0 (+https://www.cgsinfotech.com/)';
const TIMEOUT = 12;

// Fall back to byte-based equivalents if the mbstring extension isn't
// available (e.g. a minimal shared-hosting PHP build).
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, string $encoding = 'UTF-8'): int
    {
        return strlen($s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, string $encoding = 'UTF-8'): string
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }
}
