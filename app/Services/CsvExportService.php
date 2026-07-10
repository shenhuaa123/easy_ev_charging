<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class CsvExportService
{
    /**
     * 下载CSV文件。
     *
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public function download(string $filename, array $headers, array $rows): void
    {
        $safeFilename = $this->buildSafeFilename($filename);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'wb');

        if($output === false){
            throw new RuntimeException('CSV输出流创建失败。');
        }

        fwrite($output, "\xEF\xBB\xBF");

        $this->writeRow($output, $headers);

        foreach($rows as $row){
            $this->writeRow($output, $row);
        }

        fclose($output);
    }

    /**
     * @param resource $output
     * @param array<int, mixed> $row
     */
    private function writeRow($output, array $row): void
    {
        $safeRow = [];

        foreach($row as $cell){
            $safeRow[] = $this->formatCell($cell);
        }

        fputcsv($output, $safeRow, ',', '"', '');
    }

    private function formatCell(mixed $value): string
    {
        if($value === null){
            return '';
        }

        if(is_bool($value)){
            return $value ? '是' : '否';
        }

        $text = trim((string)$value);

        if($text !== '' && preg_match('/\A[=+\-@]/u', $text) === 1){
            return "'" . $text;
        }

        return $text;
    }

    private function buildSafeFilename(string $filename): string
    {
        $filename = trim($filename);

        if($filename === ''){
            $filename = 'export.csv';
        }

        if(!str_ends_with(strtolower($filename), '.csv')){
            $filename .= '.csv';
        }

        return preg_replace('/[^\w.\-]/u', '_', $filename) ?? 'export.csv';
    }
}