<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\UserRepository;
use App\Services\AuthService;
use Tests\DatabaseTestCase;

final class AuthServiceTest extends DatabaseTestCase
{
    public function testRegisterCreatesActiveNormalUser(): void
    {
        $service = $this->createAuthService();

        $result = $service->register(
            $this->validRegistrationData(1)
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['user_id']);

        $repository = new UserRepository($this->connection);
        $user = $repository->findById((int)$result['user_id']);

        $this->assertNotNull($user);
        $this->assertSame('auth001', $user->getUsername());
        $this->assertSame('user', $user->getRole());
        $this->assertSame('active', $user->getStatus());
        $this->assertTrue(
            password_verify(
                'Aa123456!',
                $user->getPasswordHash()
            )
        );
    }

    public function testRegisterRejectsPasswordConfirmationMismatch(): void
    {
        $service = $this->createAuthService();

        $data = $this->validRegistrationData(2);
        $data['password_confirmation'] = 'Bb123456!';

        $result = $service->register($data);

        $this->assertFalse($result['success']);
        $this->assertNull($result['user_id']);
        $this->assertArrayHasKey(
            'password_confirmation',
            $result['errors']
        );
    }

    public function testRegisterRejectsDuplicateIdentityFields(): void
    {
        $service = $this->createAuthService();

        $firstResult = $service->register(
            $this->validRegistrationData(3)
        );

        $this->assertTrue($firstResult['success']);

        $duplicateData = $this->validRegistrationData(4);
        $duplicateData['username'] = 'auth003';
        $duplicateData['mobile'] = '13600000003';
        $duplicateData['email'] = 'auth003@example.com';

        $result = $service->register($duplicateData);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('username', $result['errors']);
        $this->assertArrayHasKey('mobile', $result['errors']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function testLoginSucceedsAndUpdatesLastLoginAt(): void
    {
        $userId = $this->createTestUser(40);
        $repository = new UserRepository($this->connection);

        $beforeLoginUser = $repository->findById($userId);

        $this->assertNotNull($beforeLoginUser);
        $this->assertNull($beforeLoginUser->getLastLoginAt());

        $service = $this->createAuthService();

        $result = $service->login(
            'tu0040',
            'Aa123456!'
        );

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['user']);
        $this->assertSame(
            $userId,
            $result['user']->getUserId()
        );

        $afterLoginUser = $repository->findById($userId);

        $this->assertNotNull($afterLoginUser);
        $this->assertNotNull($afterLoginUser->getLastLoginAt());
    }

    public function testLoginUsesSameGenericErrorForWrongPasswordAndUnknownUser(): void
    {
        $this->createTestUser(50);

        $service = $this->createAuthService();

        $wrongPasswordResult = $service->login(
            'tu0050',
            'Wrong123!'
        );

        $unknownUserResult = $service->login(
            'unknownuser',
            'Wrong123!'
        );

        $this->assertFalse($wrongPasswordResult['success']);
        $this->assertFalse($unknownUserResult['success']);

        $this->assertSame(
            $wrongPasswordResult['message'],
            $unknownUserResult['message']
        );

        $this->assertSame(
            '用户名或密码错误。',
            $wrongPasswordResult['message']
        );
    }

    public function testDisabledUserCannotLogin(): void
    {
        $this->createTestUser(
            60,
            'user',
            'disabled'
        );

        $service = $this->createAuthService();

        $result = $service->login(
            'tu0060',
            'Aa123456!'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            '用户名或密码错误。',
            $result['message']
        );
        $this->assertNull($result['user']);
    }

    private function createAuthService(): AuthService
    {
        return new AuthService(
            new UserRepository($this->connection)
        );
    }

    private function validRegistrationData(int $index): array
    {
        return [
            'username' => sprintf('auth%03d', $index),
            'real_name' => '测试用户',
            'mobile' => sprintf('136%08d', $index),
            'email' => sprintf(
                'auth%03d@example.com',
                $index
            ),
            'password' => 'Aa123456!',
            'password_confirmation' => 'Aa123456!',
        ];
    }
}