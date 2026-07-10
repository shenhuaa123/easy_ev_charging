<?php

declare(strict_types=1);

const APP_ENV = 'test';
const TEST_PROJECT_ROOT = __DIR__ . '/..';

date_default_timezone_set('Asia/Shanghai');

spl_autoload_register(
    function(string $className): void {
        $namespacePrefix = 'App\\';
        $baseDirectory = TEST_PROJECT_ROOT . '/app/';

        if(!str_starts_with($className, $namespacePrefix)){
            return;
        }

        $relativeClassName = substr($className, strlen($namespacePrefix));
        $relativeFilePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClassName);
        $filePath = $baseDirectory . $relativeFilePath . '.php';

        if(!is_file($filePath)){
            throw new RuntimeException('测试自动加载失败，找不到类文件：' . $className);
        }

        require_once $filePath;
    }
);

require_once __DIR__ . '/TestCase.php';

if(is_file(__DIR__ . '/DatabaseTestCase.php')){
    require_once __DIR__ . '/DatabaseTestCase.php';
}