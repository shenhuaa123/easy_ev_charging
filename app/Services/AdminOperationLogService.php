<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminOperationLog;
use App\Repositories\AdminOperationLogRepository;
use RuntimeException;

class AdminOperationLogService
{
    private const ALLOWED_RESULTS = [
        'success',
        'failure',
    ];

    private AdminOperationLogRepository $logRepository;

    public function __construct(AdminOperationLogRepository $logRepository)
    {
        $this->logRepository = $logRepository;
    }

    public function record(
        int $operatorUserId,
        string $action,
        string $targetType,
        ?int $targetId,
        string $result,
        ?string $detail = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        if($operatorUserId <= 0){
            throw new RuntimeException('管理员操作日志的操作者编号不合法。');
        }

        $action = trim($action);
        $targetType = trim($targetType);
        $result = trim($result);

        if($action === ''){
            throw new RuntimeException('管理员操作日志的操作类型不能为空。');
        }

        if($targetType === ''){
            throw new RuntimeException('管理员操作日志的对象类型不能为空。');
        }

        if(!in_array($result, self::ALLOWED_RESULTS, true)){
            throw new RuntimeException('管理员操作日志的操作结果不合法。');
        }

        $detail = $this->normalizeNullableText($detail, 1000);
        $ipAddress = $this->normalizeNullableText($ipAddress, 45);
        $userAgent = $this->normalizeNullableText($userAgent, 255);

        $log = new AdminOperationLog(
            null,
            $operatorUserId,
            mb_substr($action, 0, 60, 'UTF-8'),
            mb_substr($targetType, 0, 60, 'UTF-8'),
            $targetId !== null && $targetId > 0 ? $targetId : null,
            $result,
            $detail,
            $ipAddress,
            $userAgent,
            date('Y-m-d H:i:s')
        );

        return $this->logRepository->create($log);
    }

    public function recordCurrentRequest(
        int $operatorUserId,
        string $action,
        string $targetType,
        ?int $targetId,
        string $result,
        ?string $detail = null
    ): int {
        return $this->record(
            $operatorUserId,
            $action,
            $targetType,
            $targetId,
            $result,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );
    }

    private function normalizeNullableText(?string $value, int $maxLength): ?string
    {
        if($value === null){
            return null;
        }

        $value = trim($value);

        if($value === ''){
            return null;
        }

        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
}