<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

use App\Core\Database;
use App\Core\Logger;
use App\Core\SecurityHeaders;

spl_autoload_register(
    function(string $className): void {
        $namespacePrefix = 'App\\';
        $baseDirectory = __DIR__ . '/app/';

        if(!str_starts_with($className, $namespacePrefix)){
            return;
        }

        $relativeClassName = substr($className, strlen($namespacePrefix));
        $relativeFilePath = str_replace(
            '\\',
            DIRECTORY_SEPARATOR,
            $relativeClassName
        );

        $filePath = $baseDirectory . $relativeFilePath . '.php';

        if(!is_file($filePath)){
            throw new RuntimeException('无法自动加载类：' . $className);
        }

        require_once $filePath;
    }
);

SecurityHeaders::send();

$databaseConfig = require __DIR__ . '/config/database.php';

try{
    $database = new Database($databaseConfig);
}catch(Throwable $exception){
    Logger::exception($exception, '数据库初始化失败。');
    throw $exception;
}