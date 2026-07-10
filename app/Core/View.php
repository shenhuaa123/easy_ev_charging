<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private function __construct()
    {
    }

    /**
     * 转义输出到HTML页面中的内容。
     */
    public static function escape(string|int|float|null $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 获取指定字段的第一条错误信息。
     */
    public static function firstError(array $errors, string $field): ?string
    {
        $error = $errors[$field][0] ?? null;

        return $error === null ? null : (string)$error;
    }

    /**
     * 判断指定字段是否存在错误。
     */
    public static function hasError(array $errors, string $field): bool
    {
        return self::firstError($errors, $field) !== null;
    }

    /**
     * 获取Flash消息对应的CSS类名。
     */
    public static function flashMessageClass(string $type): string
    {
        return match($type){
            'success' => 'message-success',
            'error' => 'message-error',
            default => 'message-info',
        };
    }

    /**
     * 获取常见业务状态对应的CSS类名。
     */
    public static function statusClass(string $status): string
    {
        return match($status){
            'active' => 'status-active',
            'visible' => 'status-visible',
            'maintenance' => 'status-maintenance',
            'inactive' => 'status-inactive',
            'hidden' => 'status-hidden',
            'disabled' => 'status-disabled',
            'charging' => 'status-charging',
            'completed' => 'status-completed',
            'abnormal' => 'status-abnormal',
            'cancelled' => 'status-cancelled',
            default => 'status-unknown',
        };
    }

    /**
     * 获取用户角色对应的CSS类名。
     */
    public static function roleClass(string $role): string
    {
        return match($role){
            'admin' => 'role-admin',
            'user' => 'role-user',
            default => 'role-unknown',
        };
    }

    /**
     * 将分钟数格式化为中文时长文本。
     */
    public static function formatMinutes(int $minutes): string
    {
        if($minutes < 60){
            return $minutes . ' 分钟';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if($remainingMinutes === 0){
            return $hours . ' 小时';
        }

        return $hours . ' 小时 ' . $remainingMinutes . ' 分钟';
    }

    /**
     * 构建保留筛选条件的分页地址。
     */
    public static function buildPageUrl(
        string $path,
        int $page,
        array $queryParameters = []
    ): string {
        $queryParameters['page'] = $page;

        return $path . '?' . http_build_query($queryParameters);
    }
}