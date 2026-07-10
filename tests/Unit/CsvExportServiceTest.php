<?php

declare(strict_types=1);

namespace Tests\Unit;

use RuntimeException;
use Tests\TestCase;

final class CsvExportServiceTest extends TestCase
{
    public function testDownloadOutputsBomHeadersAndDataRows(): void
    {
        $output = $this->runDownload(
            'basic_export',
            ['编号', '名称', '启用'],
            [
                [1, ' 测试站点 ', true],
                [2, null, false],
            ]
        );

        $this->assertTrue(
            str_starts_with($output, "\xEF\xBB\xBF")
        );

        $rows = $this->parseCsvRows($output);

        $this->assertCount(3, $rows);

        $this->assertSame(
            ['编号', '名称', '启用'],
            $rows[0]
        );

        $this->assertSame(
            ['1', '测试站点', '是'],
            $rows[1]
        );

        $this->assertSame(
            ['2', '', '否'],
            $rows[2]
        );
    }

    public function testDownloadEscapesSpreadsheetFormulaCells(): void
    {
        $output = $this->runDownload(
            'formula_export.csv',
            ['值'],
            [
                ['=SUM(A1:A2)'],
                ['+CMD(...)'],
                ['-1+1'],
                ['@something'],
                ['normal'],
            ]
        );

        $rows = $this->parseCsvRows($output);

        $this->assertCount(6, $rows);

        $this->assertSame(['值'], $rows[0]);
        $this->assertSame(["'=SUM(A1:A2)"], $rows[1]);
        $this->assertSame(["'+CMD(...)"], $rows[2]);
        $this->assertSame(["'-1+1"], $rows[3]);
        $this->assertSame(["'@something"], $rows[4]);
        $this->assertSame(['normal'], $rows[5]);
    }

    private function runDownload(
        string $filename,
        array $headers,
        array $rows
    ): string {
        $payload = [
            'filename' => $filename,
            'headers' => $headers,
            'rows' => $rows,
        ];

        $childCode = <<<'PHP'
require $argv[1] . '/tests/bootstrap.php';

$payload = json_decode(
    base64_decode($argv[2]),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$service = new App\Services\CsvExportService();

$service->download(
    $payload['filename'],
    $payload['headers'],
    $payload['rows']
);
PHP;

        $encodedPayload = base64_encode(
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $childCode,
                TEST_PROJECT_ROOT,
                $encodedPayload,
            ],
            $descriptors,
            $pipes
        );

        if(!is_resource($process)){
            throw new RuntimeException(
                '无法启动CSV导出测试子进程。'
            );
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if($exitCode !== 0){
            throw new RuntimeException(
                'CSV导出测试子进程执行失败：'
                . trim($errorOutput)
            );
        }

        if($output === false){
            throw new RuntimeException(
                '读取CSV导出测试结果失败。'
            );
        }

        return $output;
    }

    private function parseCsvRows(string $output): array
    {
        $csvContent = str_starts_with(
            $output,
            "\xEF\xBB\xBF"
        )
            ? substr($output, 3)
            : $output;

        $lines = preg_split(
            '/\r\n|\n|\r/',
            rtrim($csvContent, "\r\n")
        );

        if($lines === false){
            throw new RuntimeException(
                '拆分CSV测试输出失败。'
            );
        }

        $rows = [];

        foreach($lines as $line){
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }
}