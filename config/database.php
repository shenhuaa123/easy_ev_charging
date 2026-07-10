<?php

declare(strict_types=1);

$env = static function(string $key, string $default): string {
    $value = getenv($key);

    return $value !== false ? (string)$value : $default;
};

$portValue = getenv('DB_PORT');
$port = $portValue !== false ? (int)$portValue : 3306;

if($port <= 0){
    $port = 3306;
}

return [
    'host' => $env('DB_HOST', '127.0.0.1'),
    'port' => $port,
    'database' => $env('DB_DATABASE', 'easy_ev_charging_system'),
    'username' => $env('DB_USERNAME', 'root'),
    'password' => $env('DB_PASSWORD', ''),
    'charset' => $env('DB_CHARSET', 'utf8mb4'),
];