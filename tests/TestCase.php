<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Throwable;

abstract class TestCase
{
    public function setUp(): void
    {

    }

    public function tearDown(): void
    {

    }

    protected function assertTrue(
        bool $condition, 
        string $message = '断言失败：结果不是true。'
    ): void{
        if(!$condition){
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(
        bool $condition, 
        string $message = '断言失败：结果不是false'
    ): void{
        if($condition){
            throw new RuntimeException($message);
        }
    }

    protected function assertSame(
        mixed $expected,
        mixed $actual, 
        string $message = ''
    ): void{
        if($expected !== $actual){
            $defaultMessage = '断言失败：预期值为 ' . var_export($expected, true) . '，实际值为 ' . var_export($actual, true) . '。';
            throw new RuntimeException($message === '' ? $defaultMessage : $message);
        }
    }

    protected function assertNull(
        mixed $actual, 
        string $message = '断言失败：结果不是null'
    ): void{
        if($actual !== null){
            throw new RuntimeException($message);
        }
    }

    protected function assertNotNull(
        mixed $actual, 
        string $message = '断言失败：结果不应为null。'
    ): void{
        if($actual === null){
            throw new RuntimeException($message);
        }
    }

    protected function assertArrayHasKey(
        string|int $key,
        array $array, 
        string $message = ''
    ): void{
        if(!array_key_exists($key, $array)){
            $defaultMessage = '断言失败：数组中不存在键 ' . var_export($key, true) . '。';
            throw new RuntimeException($message === '' ? $defaultMessage : $message);
        }
    }

    protected function assertStringContains(
        string $needle,
        string $haystack, 
        string $message = ''
    ): void{
        if(!str_contains($haystack, $needle)){
            $defaultMessage = '断言失败：字符串中不包含 ' . var_export($needle, true) . '。';
            throw new RuntimeException($message === '' ? $defaultMessage : $message);
        }
    }

    protected function assertCount(int $expectedCount, array $array, string $message = ''): void
    {
        $actualCount = count($array);

        if($expectedCount !== $actualCount){
            $defaultMessage = '断言失败：预期数组数量为 ' . $expectedCount . '，实际数量为 ' . $actualCount . '。';
            throw new RuntimeException($message === '' ? $defaultMessage : $message);
        }
    }

    protected function assertThrows(string $expectedExceptionClass, callable $callback, string $message = ''): void
    {
        try{
            $callback();
        }catch(Throwable $exception){
            if($exception instanceof $expectedExceptionClass){
                return;
            }

            $defaultMessage = '断言失败：预期异常为 ' . $expectedExceptionClass . '，实际异常为 ' . get_class($exception) . '。';
            throw new RuntimeException($message === '' ? $defaultMessage : $message);
        }

        $defaultMessage = '断言失败：预期抛出异常 ' . $expectedExceptionClass . '，但没有抛出任何异常。';
        throw new RuntimeException($message === '' ? $defaultMessage : $message);
    }
}