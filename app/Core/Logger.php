<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    private const LOG_DIRECTORY = '/storage/logs';

    private const TEST_LOG_SUBDIRECTORY = 'test';

    private const MAX_STRING_LENGTH = 1000;

    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'password_confirmation',
        'token',
        '_csrf_token',
        'csrf_token',
        'cookie',
        'authorization',
    ];

    public static function app(string $message, array $context = []): void
    {
        self::write('app.log', 'INFO', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error.log', 'ERROR', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        self::write('security.log', 'SECURITY', $message, $context);
    }

    public static function exception(Throwable $exception, string $message = '系统异常。', array $context = []): void
    {
        $context['exception'] = $exception;

        self::error($message, $context);
    }

    private static function write(string $fileName, string $level, string $message, array $context): void
    {
        $logDirectory = self::logDirectory();

        if(!is_dir($logDirectory) && !@mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)){
            self::fallback($message, $context);
            return;
        }

        $filePath = $logDirectory . DIRECTORY_SEPARATOR . $fileName;
        $line = self::formatLine($level, $message, $context);

        $written = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);

        if($written === false){
            self::fallback($message, $context);
        }
    }

    private static function formatLine(string $level, string $message, array $context): string
    {
        $time = date('Y-m-d H:i:s');
        $safeMessage = self::sanitizeString($message);
        $mergedContext = array_merge(self::requestContext(), $context);
        $safeContext = self::sanitizeValue($mergedContext);

        $contextJson = json_encode(
            $safeContext,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if(!is_string($contextJson)){
            $contextJson = '{}';
        }

        return '[' . $time . '] [' . $level . '] ' . $safeMessage . ' | context=' . $contextJson . PHP_EOL;
    }

    private static function requestContext(): array
    {
        return [
            'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        ];
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if($value instanceof Throwable){
            return [
                'class' => $value::class,
                'message' => self::sanitizeString($value->getMessage()),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if(is_array($value)){
            $safeArray = [];

            foreach($value as $key => $item){
                $safeKey = is_string($key) ? $key : (string)$key;

                if(self::isSensitiveKey($safeKey)){
                    $safeArray[$safeKey] = '[已隐藏]';
                    continue;
                }

                $safeArray[$safeKey] = self::sanitizeValue($item);
            }

            return $safeArray;
        }

        if(is_string($value)){
            return self::sanitizeString($value);
        }

        if(is_object($value)){
            return '[object ' . $value::class . ']';
        }

        return $value;
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;

        if(strlen($value) > self::MAX_STRING_LENGTH){
            return substr($value, 0, self::MAX_STRING_LENGTH) . '...';
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower($key);

        return in_array($normalizedKey, self::SENSITIVE_KEYS, true);
    }

    private static function logDirectory(): string
    {
        $logDirectory = dirname(__DIR__, 2) . self::LOG_DIRECTORY;

        if(
            defined('APP_ENV')
            && constant('APP_ENV') === 'test'
        ){
            return $logDirectory
                . DIRECTORY_SEPARATOR
                . self::TEST_LOG_SUBDIRECTORY;
        }

        return $logDirectory;
    }

    private static function fallback(string $message, array $context): void
    {
        $safeMessage = self::sanitizeString($message);
        $safeContext = json_encode(
            self::sanitizeValue($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if(!is_string($safeContext)){
            $safeContext = '{}';
        }

        @error_log('[EasyEV Logger Fallback] ' . $safeMessage . ' | context=' . $safeContext);
    }
}