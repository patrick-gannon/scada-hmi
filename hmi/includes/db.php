<?php
// Load .env file from parent directory (same location as Python script's .env)
// Or override by setting these as Apache/Nginx env vars in your vhost config
function hmi_getenv(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false) return $val;

    // Try loading from a local .env file next to this project
    static $env_loaded = false;
    static $env_vars   = [];
    if (!$env_loaded) {
        $env_file = __DIR__ . '/../.env';
        if (file_exists($env_file)) {
            foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $env_vars[trim($k)] = trim($v);
            }
        }
        $env_loaded = true;
    }
    return $env_vars[$key] ?? $default;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = hmi_getenv('DB_HOST', '127.0.0.1');
    $user = hmi_getenv('DB_USER');
    $pass = hmi_getenv('DB_PASSWORD');
    $name = hmi_getenv('DB_NAME', 'scada');

    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
