<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use mysqli;
use RuntimeException;
use Throwable;

final class LoginAttemptRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function find(string $scope, string $attemptKey): ?array
    {
        return $this->findInternal($scope, $attemptKey, false);
    }

    public function recordFailure(
        string $scope,
        string $attemptKey,
        int $maxAttempts,
        int $windowSeconds,
        int $lockSeconds
    ): array {
        $this->purgeStaleRecords();

        $now = new DateTimeImmutable();

        if(!$this->connection->begin_transaction()){
            throw new RuntimeException('登录失败记录事务启动失败。');
        }

        try{
            $attempt = $this->findInternal($scope, $attemptKey, true);

            if($attempt === null){
                $failedAttempts = 1;
                $windowStartedAt = $now;
                $lockedUntil = $failedAttempts >= $maxAttempts
                    ? $now->modify('+' . $lockSeconds . ' seconds')
                    : null;

                $attemptId = $this->insert(
                    $scope,
                    $attemptKey,
                    $failedAttempts,
                    $windowStartedAt,
                    $now,
                    $lockedUntil
                );
            }else{
                $attemptId = (int)$attempt['login_attempt_id'];
                $existingLockedUntil = $attempt['locked_until'] === null
                    ? null
                    : new DateTimeImmutable($attempt['locked_until']);

                if($existingLockedUntil !== null && $existingLockedUntil > $now){
                    $this->connection->commit();

                    return $attempt;
                }

                $windowStartedAt = new DateTimeImmutable($attempt['window_started_at']);
                $windowExpired = $now->getTimestamp() - $windowStartedAt->getTimestamp()
                    >= $windowSeconds;

                if($windowExpired){
                    $failedAttempts = 1;
                    $windowStartedAt = $now;
                }else{
                    $failedAttempts = (int)$attempt['failed_attempts'] + 1;
                }

                $lockedUntil = $failedAttempts >= $maxAttempts
                    ? $now->modify('+' . $lockSeconds . ' seconds')
                    : null;

                $this->update(
                    $attemptId,
                    $failedAttempts,
                    $windowStartedAt,
                    $now,
                    $lockedUntil
                );
            }

            if(!$this->connection->commit()){
                throw new RuntimeException('登录失败记录事务提交失败。');
            }

            return [
                'login_attempt_id' => $attemptId,
                'attempt_scope' => $scope,
                'attempt_key' => $attemptKey,
                'failed_attempts' => $failedAttempts,
                'window_started_at' => $windowStartedAt->format('Y-m-d H:i:s'),
                'last_failed_at' => $now->format('Y-m-d H:i:s'),
                'locked_until' => $lockedUntil?->format('Y-m-d H:i:s'),
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            throw new RuntimeException(
                '记录登录失败次数失败。',
                0,
                $exception
            );
        }
    }

    public function clear(string $scope, string $attemptKey): void
    {
        $sql = '
            DELETE FROM login_attempts
            WHERE attempt_scope = ?
            AND attempt_key = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('登录失败记录删除SQL预处理失败。');
        }

        $statement->bind_param('ss', $scope, $attemptKey);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('清除登录失败记录失败。');
        }

        $statement->close();
    }

    private function findInternal(
        string $scope,
        string $attemptKey,
        bool $forUpdate
    ): ?array {
        $sql = '
            SELECT
                login_attempt_id,
                attempt_scope,
                attempt_key,
                failed_attempts,
                window_started_at,
                last_failed_at,
                locked_until
            FROM login_attempts
            WHERE attempt_scope = ?
            AND attempt_key = ?
            LIMIT 1
        ';

        if($forUpdate){
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('登录失败记录查询SQL预处理失败。');
        }

        $statement->bind_param('ss', $scope, $attemptKey);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询登录失败记录失败。');
        }

        $result = $statement->get_result();
        $attempt = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($attempt === null){
            return null;
        }

        return [
            'login_attempt_id' => (int)$attempt['login_attempt_id'],
            'attempt_scope' => (string)$attempt['attempt_scope'],
            'attempt_key' => (string)$attempt['attempt_key'],
            'failed_attempts' => (int)$attempt['failed_attempts'],
            'window_started_at' => (string)$attempt['window_started_at'],
            'last_failed_at' => (string)$attempt['last_failed_at'],
            'locked_until' => $attempt['locked_until'] === null
                ? null
                : (string)$attempt['locked_until'],
        ];
    }

    private function insert(
        string $scope,
        string $attemptKey,
        int $failedAttempts,
        DateTimeImmutable $windowStartedAt,
        DateTimeImmutable $lastFailedAt,
        ?DateTimeImmutable $lockedUntil
    ): int {
        $sql = '
            INSERT INTO login_attempts (
                attempt_scope,
                attempt_key,
                failed_attempts,
                window_started_at,
                last_failed_at,
                locked_until
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('登录失败记录新增SQL预处理失败。');
        }

        $windowStartedAtValue = $windowStartedAt->format('Y-m-d H:i:s');
        $lastFailedAtValue = $lastFailedAt->format('Y-m-d H:i:s');
        $lockedUntilValue = $lockedUntil?->format('Y-m-d H:i:s');

        $statement->bind_param(
            'ssisss',
            $scope,
            $attemptKey,
            $failedAttempts,
            $windowStartedAtValue,
            $lastFailedAtValue,
            $lockedUntilValue
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('新增登录失败记录失败。');
        }

        $attemptId = $statement->insert_id;

        $statement->close();

        return $attemptId;
    }

    private function update(
        int $attemptId,
        int $failedAttempts,
        DateTimeImmutable $windowStartedAt,
        DateTimeImmutable $lastFailedAt,
        ?DateTimeImmutable $lockedUntil
    ): void {
        $sql = '
            UPDATE login_attempts
            SET
                failed_attempts = ?,
                window_started_at = ?,
                last_failed_at = ?,
                locked_until = ?
            WHERE login_attempt_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('登录失败记录更新SQL预处理失败。');
        }

        $windowStartedAtValue = $windowStartedAt->format('Y-m-d H:i:s');
        $lastFailedAtValue = $lastFailedAt->format('Y-m-d H:i:s');
        $lockedUntilValue = $lockedUntil?->format('Y-m-d H:i:s');

        $statement->bind_param(
            'isssi',
            $failedAttempts,
            $windowStartedAtValue,
            $lastFailedAtValue,
            $lockedUntilValue,
            $attemptId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新登录失败记录失败。');
        }

        $statement->close();
    }

    private function purgeStaleRecords(): void
    {
        $sql = '
            DELETE FROM login_attempts
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ';

        if(!$this->connection->query($sql)){
            throw new RuntimeException('清理过期登录失败记录失败。');
        }
    }
}