<?php

declare(strict_types=1);

if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

$rootDirectory = dirname(__DIR__);
$reportDirectory = $rootDirectory . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'reports';

$options = parseOptions($argv);

if($options['show_help']){
    printUsage();
    exit(0);
}

$reportFiles = collectReportFiles($reportDirectory);

fwrite(STDOUT, PHP_EOL . '=== 清理报告 ===' . PHP_EOL);
fwrite(STDOUT, '清理目录：' . $reportDirectory . PHP_EOL);
fwrite(STDOUT, '清理方式：清空报告文件内容并保留文件本身。' . PHP_EOL);

if($reportFiles === []){
    fwrite(STDOUT, '没有可清理的报告文件。' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, '待处理文件数：' . count($reportFiles) . PHP_EOL);

if(!confirm('您确定要执行报告清理吗？(输入y或yes以确认，输入其他字符以取消)：')){
    fwrite(STDOUT, '已取消报告清理。' . PHP_EOL);
    exit(0);
}

foreach($reportFiles as $reportFile){
    clearReportFile($reportFile);
}

fwrite(STDOUT, PHP_EOL . '报告清理完成。' . PHP_EOL);

function parseOptions(array $argv): array
{
    $showHelp = false;

    foreach(array_slice($argv, 1) as $argument){
        if($argument === '--help' || $argument === '-h'){
            $showHelp = true;
            continue;
        }

        fwrite(STDERR, '未知参数：' . $argument . PHP_EOL);
        printUsage();
        exit(1);
    }

    return [
        'show_help' => $showHelp,
    ];
}

function collectReportFiles(string $reportDirectory): array
{
    if(!is_dir($reportDirectory)){
        return [];
    }

    $paths = glob($reportDirectory . DIRECTORY_SEPARATOR . '*');

    if($paths === false){
        fwrite(STDERR, '报告目录读取失败：' . $reportDirectory . PHP_EOL);
        exit(1);
    }

    $reportFiles = array_values(
        array_filter(
            $paths,
            static fn(string $path): bool => is_file($path)
        )
    );

    sort($reportFiles, SORT_STRING);

    return $reportFiles;
}

function printUsage(): void
{
    fwrite(STDOUT, PHP_EOL . '用法：' . PHP_EOL);
    fwrite(STDOUT, '  php tools/clear_reports.php' . PHP_EOL);
    fwrite(STDOUT, '  php tools/clear_reports.php --help' . PHP_EOL . PHP_EOL);

    fwrite(STDOUT, '说明：' . PHP_EOL);
    fwrite(STDOUT, '  清空 storage/reports 目录中所有报告文件的内容。' . PHP_EOL);
    fwrite(STDOUT, '  报告文件本身会保留，用于保留项目目录和文件结构。' . PHP_EOL);
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

function clearReportFile(string $filePath): void
{
    if(file_put_contents($filePath, '') === false){
        fwrite(STDERR, '报告文件清空失败：' . $filePath . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, basename($filePath) . '：已清空并保留文件。' . PHP_EOL);
}