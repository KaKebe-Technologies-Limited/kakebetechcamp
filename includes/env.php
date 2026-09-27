<?php
/**
 * Minimal .env loader (KEY=value lines, # comments). Secrets live in the untracked .env file.
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key !== '') {
            $_ENV[$key] = trim($value, " \t\"'");
        }
    }
}

function env(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    $value = getenv($key);
    return $value !== false ? (string) $value : $default;
}

function is_local_host(): bool
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    return $host === '' ? false : (str_contains($host, 'localhost') || str_starts_with($host, '127.0.0.1') || str_ends_with(explode(':', $host)[0], '.test'));
}
