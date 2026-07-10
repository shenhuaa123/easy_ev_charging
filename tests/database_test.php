<?php

declare(strict_types=1);

$projectDatabaseConfigPath = dirname(__DIR__) . '/config/database.php';

if(is_file($projectDatabaseConfigPath)){
    $config = require $projectDatabaseConfigPath;
}else{
    $config = [
        'host' => '127.0.0.1',
        'username' => 'root',
        'password' => '',
        'database' => 'easy_ev_charging_system',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ];
}

$config['database'] = 'easy_ev_charging_system_test';
$config['schema_file'] = dirname(__DIR__) . '/database/easy_ev_charging_system_schema.sql';

return $config;