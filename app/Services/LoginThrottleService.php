<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LoginAttemptRepository;
use DateTimeImmutable;

final class LoginThrottleService
{
    public const BLOCKED_MESSAGE = '登录尝试过于频繁，请稍后再试。';

    private const ACCOUNT_SCOPE = 'account';
    private const IP_SCOPE = 'ip';

    private const ACCOUNT_MAX_ATTEMPTS = 5;
    private const IP_MAX_ATTEMPTS = 20;
    private const WINDOW_SECONDS = 900;
    private const LOCK_SECONDS = 900;

    private LoginAttemptRepository $loginAttemptRepository;

    public function __construct(LoginAttemptRepository $loginAttemptRepository)
    {
        $this->loginAttemptRepository = $loginAttemptRepository;
    }

    public function check(string $username, string $clientIp): array
    {
        $accountAttempt = $this->loginAttemptRepository->find(
            self::ACCOUNT_SCOPE,
            $this->accountKey($username)
        );

        $ipAttempt = $this->loginAttemptRepository->find(
            self::IP_SCOPE,
            $this->ipKey($clientIp)
        );

        return $this->combineStates([
            $this->buildState($accountAttempt),
            $this->buildState($ipAttempt),
        ]);
    }

    public function recordFailure(string $username, string $clientIp): array
    {
        $accountAttempt = $this->loginAttemptRepository->recordFailure(
            self::ACCOUNT_SCOPE,
            $this->accountKey($username),
            self::ACCOUNT_MAX_ATTEMPTS,
            self::WINDOW_SECONDS,
            self::LOCK_SECONDS
        );

        $ipAttempt = $this->loginAttemptRepository->recordFailure(
            self::IP_SCOPE,
            $this->ipKey($clientIp),
            self::IP_MAX_ATTEMPTS,
            self::WINDOW_SECONDS,
            self::LOCK_SECONDS
        );

        return $this->combineStates([
            $this->buildState($accountAttempt),
            $this->buildState($ipAttempt),
        ]);
    }

    public function clearSuccessfulLogin(string $username): void
    {
        $this->loginAttemptRepository->clear(
            self::ACCOUNT_SCOPE,
            $this->accountKey($username)
        );
    }

    public function resolveClientIp(array $server): string
    {
        $clientIp = trim((string)($server['REMOTE_ADDR'] ?? ''));

        if(filter_var($clientIp, FILTER_VALIDATE_IP) === false){
            return 'unknown';
        }

        return $clientIp;
    }

    private function accountKey(string $username): string
    {
        $normalizedUsername = strtolower(trim($username));

        if($normalizedUsername === ''){
            $normalizedUsername = '<empty>';
        }

        $normalizedUsername = substr($normalizedUsername, 0, 100);

        return hash('sha256', $normalizedUsername);
    }

    private function ipKey(string $clientIp): string
    {
        $normalizedIp = trim($clientIp);

        if(filter_var($normalizedIp, FILTER_VALIDATE_IP) === false){
            $normalizedIp = 'unknown';
        }

        return hash('sha256', $normalizedIp);
    }

    private function buildState(?array $attempt): array
    {
        if($attempt === null || $attempt['locked_until'] === null){
            return [
                'blocked' => false,
                'retry_after' => 0,
            ];
        }

        $now = new DateTimeImmutable();
        $lockedUntil = new DateTimeImmutable($attempt['locked_until']);

        if($lockedUntil <= $now){
            return [
                'blocked' => false,
                'retry_after' => 0,
            ];
        }

        return [
            'blocked' => true,
            'retry_after' => max(
                1,
                $lockedUntil->getTimestamp() - $now->getTimestamp()
            ),
        ];
    }

    private function combineStates(array $states): array
    {
        $blocked = false;
        $retryAfter = 0;

        foreach($states as $state){
            if(!$state['blocked']){
                continue;
            }

            $blocked = true;
            $retryAfter = max($retryAfter, (int)$state['retry_after']);
        }

        return [
            'blocked' => $blocked,
            'retry_after' => $retryAfter,
        ];
    }
}