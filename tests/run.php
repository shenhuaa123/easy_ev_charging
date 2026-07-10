<?php

declare(strict_types=1);

use Tests\TestCase;

require_once __DIR__ . '/bootstrap.php';

$requiredExtensions = [
    'mbstring' => '多字节字符串处理',
    'mysqli' => 'MySQL数据库连接',
];

$missingExtensions = [];

foreach($requiredExtensions as $extension => $description){
    if(!extension_loaded($extension)){
        $missingExtensions[] = $extension . '（' . $description . '）';
    }
}

if($missingExtensions !== []){
    echo '[ENV FAIL] 测试运行环境缺少必要的PHP扩展：' . PHP_EOL;

    foreach($missingExtensions as $extension){
        echo '- ' . $extension . PHP_EOL;
    }

    echo PHP_EOL;
    echo '请确认当前命令行使用的是项目所需的PHP环境，并检查php.ini扩展配置。' . PHP_EOL;

    exit(1);
}

$databaseConfigPath = __DIR__ . '/database_test.php';

if(!is_file($databaseConfigPath)){
    echo '[ENV FAIL] 缺少测试数据库配置文件：tests/database_test.php' . PHP_EOL;

    exit(1);
}

$databaseConfig = require $databaseConfigPath;

try{
    $databaseConnection = new mysqli(
        (string)($databaseConfig['host'] ?? ''),
        (string)($databaseConfig['username'] ?? ''),
        (string)($databaseConfig['password'] ?? ''),
        '',
        (int)($databaseConfig['port'] ?? 3306)
    );

    if($databaseConnection->connect_error){
        throw new RuntimeException(
            $databaseConnection->connect_error
        );
    }

    $databaseConnection->close();
}catch(Throwable $exception){
    echo '[ENV FAIL] 无法连接测试数据库服务。' . PHP_EOL;
    echo '原因：' . $exception->getMessage() . PHP_EOL;
    echo PHP_EOL;
    echo '请确认MySQL或MariaDB已经启动，并检查tests/database_test.php中的连接配置。' . PHP_EOL;

    exit(1);
}

$testDirectories = [
    __DIR__ . '/Unit',
    __DIR__ . '/Feature',
];

$totalCount = 0;
$passedCount = 0;
$failedCount = 0;
$failures = [];

foreach($testDirectories as $testDirectory){
    if(!is_dir($testDirectory)){
        continue;
    }

    $testFiles = glob($testDirectory . '/*Test.php');

    if($testFiles === false){
        continue;
    }

    foreach($testFiles as $testFile){
        require_once $testFile;

        $normalizedBasePath = str_replace('\\', '/', __DIR__) . '/';
        $normalizedTestFile = str_replace('\\', '/', $testFile);
        $relativePath = str_replace(
            $normalizedBasePath,
            '',
            $normalizedTestFile
        );
        $classPath = str_replace(
            ['/', '.php'],
            ['\\', ''],
            $relativePath
        );
        $className = 'Tests\\' . $classPath;

        if(!class_exists($className)){
            $failedCount++;
            $failures[] = $relativePath
                . '：找不到测试类 '
                . $className;
            continue;
        }

        if(!is_subclass_of($className, TestCase::class)){
            $failedCount++;
            $failures[] = $className
                . '：测试类必须继承 Tests\\TestCase。';
            continue;
        }

        $methods = get_class_methods($className);

        foreach($methods as $method){
            if(!str_starts_with($method, 'test')){
                continue;
            }

            $totalCount++;
            $testCase = new $className();
            $failureMessage = null;

            try{
                $testCase->setUp();
                $testCase->$method();
            }catch(Throwable $exception){
                $failureMessage = $exception->getMessage();
            }

            try{
                $testCase->tearDown();
            }catch(Throwable $exception){
                $tearDownMessage = '测试清理失败：'
                    . $exception->getMessage();

                $failureMessage = $failureMessage === null
                    ? $tearDownMessage
                    : $failureMessage . '；' . $tearDownMessage;
            }

            if($failureMessage === null){
                $passedCount++;

                echo '[PASS] '
                    . $className
                    . '::'
                    . $method
                    . PHP_EOL;

                continue;
            }

            $failedCount++;

            $failures[] = $className
                . '::'
                . $method
                . '：'
                . $failureMessage;

            echo '[FAIL] '
                . $className
                . '::'
                . $method
                . PHP_EOL;
        }
    }
}

echo PHP_EOL;
echo '==============================' . PHP_EOL;
echo '测试总数：' . $totalCount . PHP_EOL;
echo '通过数量：' . $passedCount . PHP_EOL;
echo '失败数量：' . $failedCount . PHP_EOL;
echo '==============================' . PHP_EOL;

if($failures !== []){
    echo PHP_EOL;
    echo '失败详情：' . PHP_EOL;

    foreach($failures as $failure){
        echo '- ' . $failure . PHP_EOL;
    }

    exit(1);
}

exit(0);