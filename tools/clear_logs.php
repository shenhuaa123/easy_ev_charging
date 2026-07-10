<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

$rootDirectory = dirname(__DIR__);
$runtimeLogDirectory = $rootDirectory . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'logs';
$testLogDirectory = $runtimeLogDirectory . DIRECTORY_SEPARATOR . 'test';

$logFiles = [
    'app.log',
    'error.log',
    'security.log',
];

$options = parseOptions($argv);
$keepDays = $options['keep_days'];
$scope = $options['scope'];

$targets = match($scope){
    'all' => [
        '运行日志' => $runtimeLogDirectory,
        '测试日志' => $testLogDirectory,
    ],
    'test' => [
        '测试日志' => $testLogDirectory,
    ],
    default => [
        '运行日志' => $runtimeLogDirectory,
    ],
};

$existingLogFiles = collectExistingLogFiles($targets, $logFiles);

fwrite(STDOUT, PHP_EOL . '=== 清理日志 ===' . PHP_EOL);
fwrite(STDOUT, '清理范围：' . implode('、', array_keys($targets)) . PHP_EOL);

if($keepDays === null){
    fwrite(STDOUT, '清理方式：清空所选范围内的日志内容。' . PHP_EOL);
}else{
    fwrite(STDOUT, '清理方式：仅保留最近 ' . $keepDays . ' 天日志。' . PHP_EOL);
}

if($existingLogFiles === []){
    fwrite(STDOUT, '没有可清理的日志文件。' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, '待处理文件数：' . count($existingLogFiles) . PHP_EOL);

if(!confirm('您确定要执行日志清理吗？(输入y或yes以确认，输入其他字符以取消)：')){
    fwrite(STDOUT, '已取消日志清理。' . PHP_EOL);
    exit(0);
}

$currentLabel = null;

foreach($existingLogFiles as $logFile){
    if($logFile['label'] !== $currentLabel){
        $currentLabel = $logFile['label'];
        fwrite(STDOUT, PHP_EOL . '[' . $currentLabel . ']' . PHP_EOL);
    }

    processLogFile(
        $logFile['path'],
        $logFile['file_name'],
        $keepDays
    );
}

fwrite(STDOUT, PHP_EOL . '日志清理完成。' . PHP_EOL);

function parseOptions(array $argv): array
{
    $keepDays = null;
    $scope = 'runtime';

    foreach(array_slice($argv, 1) as $argument){
        if($argument === '--help' || $argument === '-h'){
            printUsage();
            exit(0);
        }

        if($argument === '--all'){
            if($scope === 'test'){
                fwrite(STDERR, '--all 与 --test 不能同时使用。' . PHP_EOL);
                exit(1);
            }

            $scope = 'all';
            continue;
        }

        if($argument === '--test'){
            if($scope === 'all'){
                fwrite(STDERR, '--all 与 --test 不能同时使用。' . PHP_EOL);
                exit(1);
            }

            $scope = 'test';
            continue;
        }

        if(str_starts_with($argument, '--keep-days=')){
            $value = substr($argument, strlen('--keep-days='));

            if(!preg_match('/^[1-9]\d*$/', $value)){
                fwrite(STDERR, '--keep-days 必须是大于0的整数。' . PHP_EOL);
                exit(1);
            }

            $keepDays = (int)$value;
            continue;
        }

        fwrite(STDERR, '未知参数：' . $argument . PHP_EOL);
        printUsage();
        exit(1);
    }

    return [
        'keep_days' => $keepDays,
        'scope' => $scope,
    ];
}

function collectExistingLogFiles(array $directories, array $logFiles): array
{
    $existingLogFiles = [];

    foreach($directories as $label => $directory){
        if(!is_dir($directory)){
            continue;
        }

        foreach($logFiles as $logFile){
            $filePath = $directory . DIRECTORY_SEPARATOR . $logFile;

            if(!is_file($filePath)){
                continue;
            }

            $existingLogFiles[] = [
                'label' => $label,
                'file_name' => $logFile,
                'path' => $filePath,
            ];
        }
    }

    return $existingLogFiles;
}

function printUsage(): void
{
    fwrite(STDOUT, PHP_EOL . '用法：' . PHP_EOL);
    fwrite(STDOUT, '  php tools/clear_logs.php [--keep-days=N]' . PHP_EOL);
    fwrite(STDOUT, '  php tools/clear_logs.php --all [--keep-days=N]' . PHP_EOL);
    fwrite(STDOUT, '  php tools/clear_logs.php --test [--keep-days=N]' . PHP_EOL . PHP_EOL);

    fwrite(STDOUT, '说明：' . PHP_EOL);
    fwrite(STDOUT, '  默认仅处理运行日志。' . PHP_EOL);
    fwrite(STDOUT, '  --all  同时处理运行日志和测试日志。' . PHP_EOL);
    fwrite(STDOUT, '  --test  仅处理测试日志。' . PHP_EOL);
    fwrite(STDOUT, '  --keep-days=N  仅保留最近 N 天的日志行。' . PHP_EOL);
}

function confirm(string $message): bool
{
    fwrite(STDOUT, PHP_EOL . $message);

    $input = fgets(STDIN);

    if($input === false){
        return false;
    }

    $value = strtolower(trim($input));

    return $value === 'y' || $value === 'yes';
}

function processLogFile(string $filePath, string $logFile, ?int $keepDays): void
{
    if($keepDays === null){
        if(file_put_contents($filePath, '') === false){
            fwrite(STDERR, '日志文件清空失败：' . $filePath . PHP_EOL);
            exit(1);
        }

        fwrite(STDOUT, $logFile . '：已清空。' . PHP_EOL);
        return;
    }

    $result = keepRecentLines($filePath, $keepDays);

    fwrite(
        STDOUT,
        $logFile
        . '：已保留最近'
        . $keepDays
        . '天日志，原行数'
        . $result['original_count']
        . '，保留'
        . $result['kept_count']
        . '。'
        . PHP_EOL
    );
}

function keepRecentLines(string $filePath, int $keepDays): array
{
    $content = file_get_contents($filePath);

    if($content === false){
        fwrite(STDERR, '日志文件读取失败：' . $filePath . PHP_EOL);
        exit(1);
    }

    $lines = preg_split('/\R/', $content);
    $lines = is_array($lines) ? $lines : [];

    $originalLines = array_values(
        array_filter(
            $lines,
            static fn(string $line): bool => trim($line) !== ''
        )
    );

    $cutoff = time() - ($keepDays * 86400);
    $keptLines = [];

    foreach($originalLines as $line){
        $timestamp = parseLogTimestamp($line);

        if($timestamp === null || $timestamp >= $cutoff){
            $keptLines[] = $line;
        }
    }

    $newContent = $keptLines === []
        ? ''
        : implode(PHP_EOL, $keptLines) . PHP_EOL;

    if(file_put_contents($filePath, $newContent) === false){
        fwrite(STDERR, '日志文件写入失败：' . $filePath . PHP_EOL);
        exit(1);
    }

    return [
        'original_count' => count($originalLines),
        'kept_count' => count($keptLines),
    ];
}

function parseLogTimestamp(string $line): ?int
{
    if(!preg_match(
        '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/',
        $line,
        $matches
    )){
        return null;
    }

    $timestamp = strtotime($matches[1]);

    return $timestamp === false ? null : $timestamp;
}