<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\LoginAttemptRepository;
use App\Services\LoginThrottleService;
use Tests\DatabaseTestCase;

final class LoginThrottleServiceTest extends DatabaseTestCase
{
    public function testAccountIsBlockedAfterFifthFailure(): void
    {
        $service = $this->createThrottleService();

        for($i = 1; $i <= 4; $i++){
            $state = $service->recordFailure(
                'throttle_user',
                '192.0.2.10'
            );

            $this->assertFalse($state['blocked']);
            $this->assertSame(0, $state['retry_after']);
        }

        $blockedState = $service->recordFailure(
            'throttle_user',
            '192.0.2.10'
        );

        $this->assertTrue($blockedState['blocked']);
        $this->assertTrue($blockedState['retry_after'] > 0);

        $checkedState = $service->check(
            'throttle_user',
            '192.0.2.10'
        );

        $this->assertTrue($checkedState['blocked']);
        $this->assertTrue($checkedState['retry_after'] > 0);
    }

    public function testIpIsBlockedAcrossDifferentUsernamesAfterTwentiethFailure(): void
    {
        $service = $this->createThrottleService();
        $clientIp = '198.51.100.20';

        for($i = 1; $i <= 19; $i++){
            $state = $service->recordFailure(
                'ip_user_' . $i,
                $clientIp
            );

            $this->assertFalse($state['blocked']);
        }

        $blockedState = $service->recordFailure(
            'ip_user_20',
            $clientIp
        );

        $this->assertTrue($blockedState['blocked']);
        $this->assertTrue($blockedState['retry_after'] > 0);
    }

    public function testUsernameNormalizationUsesSameAccountLimit(): void
    {
        $service = $this->createThrottleService();

        $usernames = [
            ' TestUser ',
            'testuser',
            'TESTUSER',
            ' testuser',
            'testuser ',
        ];

        foreach($usernames as $index => $username){
            $state = $service->recordFailure(
                $username,
                '203.0.113.' . ($index + 1)
            );

            if($index < 4){
                $this->assertFalse($state['blocked']);
                continue;
            }

            $this->assertTrue($state['blocked']);
            $this->assertTrue($state['retry_after'] > 0);
        }
    }

    public function testSuccessfulLoginClearResetsAccountFailures(): void
    {
        $service = $this->createThrottleService();

        for($i = 1; $i <= 4; $i++){
            $state = $service->recordFailure(
                'clear_user',
                '192.0.2.' . $i
            );

            $this->assertFalse($state['blocked']);
        }

        $service->clearSuccessfulLogin(' CLEAR_USER ');

        for($i = 5; $i <= 8; $i++){
            $state = $service->recordFailure(
                'clear_user',
                '192.0.2.' . $i
            );

            $this->assertFalse($state['blocked']);
        }

        $checkedState = $service->check(
            'clear_user',
            '192.0.2.100'
        );

        $this->assertFalse($checkedState['blocked']);
        $this->assertSame(0, $checkedState['retry_after']);
    }

    public function testResolveClientIpAcceptsValidAddressesAndRejectsInvalidValues(): void
    {
        $service = $this->createThrottleService();

        $this->assertSame(
            '127.0.0.1',
            $service->resolveClientIp([
                'REMOTE_ADDR' => '127.0.0.1',
            ])
        );

        $this->assertSame(
            '::1',
            $service->resolveClientIp([
                'REMOTE_ADDR' => '::1',
            ])
        );

        $this->assertSame(
            'unknown',
            $service->resolveClientIp([])
        );

        $this->assertSame(
            'unknown',
            $service->resolveClientIp([
                'REMOTE_ADDR' => '   ',
            ])
        );

        $this->assertSame(
            'unknown',
            $service->resolveClientIp([
                'REMOTE_ADDR' => 'not-an-ip',
            ])
        );
    }

    private function createThrottleService(): LoginThrottleService
    {
        return new LoginThrottleService(
            new LoginAttemptRepository($this->connection)
        );
    }
}