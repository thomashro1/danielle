<?php

function app_parse_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

function app_env_map(): array
{
    static $values = null;

    if ($values !== null) {
        return $values;
    }

    $values = [
        'APP_TIMEZONE' => 'Europe/Berlin',
        'APP_BASE_URL' => '',
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => 'danielle_local',
        'DB_USER' => 'root',
        'DB_PASS' => '',
        'DB_CHARSET' => 'utf8mb4',
    ];

    foreach ([__DIR__ . '/.env.example', __DIR__ . '/.env', __DIR__ . '/.env.local'] as $envFile) {
        $values = array_merge($values, app_parse_env_file($envFile));
    }

    return $values;
}

function app_env(string $key, ?string $default = null): ?string
{
    $runtimeValue = getenv($key);
    if ($runtimeValue !== false && $runtimeValue !== '') {
        return $runtimeValue;
    }

    $values = app_env_map();
    return array_key_exists($key, $values) ? $values[$key] : $default;
}

function app_connect_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) app_env('DB_HOST', 'localhost');
    $port = (int) app_env('DB_PORT', '3306');
    $name = (string) app_env('DB_NAME', 'danielle_local');
    $user = (string) app_env('DB_USER', 'root');
    $pass = (string) app_env('DB_PASS', '');
    $charset = (string) app_env('DB_CHARSET', 'utf8mb4');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $host,
        $port,
        $name,
        $charset
    );

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function app_password_matches(string $plainTextPassword, ?string $storedPassword): bool
{
    if ($storedPassword === null || $storedPassword === '') {
        return false;
    }

    $info = password_get_info($storedPassword);
    if (!empty($info['algo'])) {
        return password_verify($plainTextPassword, $storedPassword);
    }

    return hash_equals($storedPassword, $plainTextPassword);
}

function app_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!is_string($header) || $header === '') {
        return null;
    }

    if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function app_json_input(): array
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    return is_array($input) ? $input : [];
}

function app_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
