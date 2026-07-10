<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

use Tools\Report\FinalCheckRunner;
use Tools\Report\FinalReportWriter;

if(PHP_SAPI !== 'cli'){
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/report/FinalCheckRunner.php';
require_once __DIR__ . '/report/FinalReportWriter.php';

$rootDirectory = dirname(__DIR__);
$reportPath = $rootDirectory
    . DIRECTORY_SEPARATOR
    . 'storage'
    . DIRECTORY_SEPARATOR
    . 'reports'
    . DIRECTORY_SEPARATOR
    . 'final_check_report.md';

fwrite(STDOUT, PHP_EOL . '=== 生成最终检查报告 ===' . PHP_EOL);
fwrite(STDOUT, '正在执行项目检查，请稍候……' . PHP_EOL . PHP_EOL);

try{
    $runner = new FinalCheckRunner($rootDirectory);
    $results = $runner->run();

    $writer = new FinalReportWriter();
    $writer->write($reportPath, $results);
}catch(Throwable $exception){
    fwrite(
        STDERR,
        '最终检查报告生成失败：'
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}

fwrite(STDOUT, PHP_EOL . '报告已生成：' . $reportPath . PHP_EOL);

if($results['overall']['passed']){
    fwrite(STDOUT, '最终检查结果：通过。' . PHP_EOL);
    exit(0);
}

fwrite(STDOUT, '最终检查结果：未通过，请查看报告中的失败项目。' . PHP_EOL);

exit(1);