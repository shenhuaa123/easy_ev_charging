<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;
use App\Services\UserService;
use Tests\DatabaseTestCase;

final class UserServiceTest extends DatabaseTestCase
{
    public function testAdminCanDisableNormalUser(): void
    {
        $adminId = $this->createTestUser(1, 'admin', 'active');
        $targetUserId = $this->createTestUser(2, 'user', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'disabled');

        $this->assertTrue($result['success']);
        $this->assertSame('用户账户已停用。', $result['message']);

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('disabled', $targetUser->getStatus());
    }

    public function testAdminCanReactivateDisabledUser(): void
    {
        $adminId = $this->createTestUser(3, 'admin', 'active');
        $targetUserId = $this->createTestUser(4, 'user', 'disabled');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'active');

        $this->assertTrue($result['success']);
        $this->assertSame('用户账户已启用。', $result['message']);

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('active', $targetUser->getStatus());
    }

    public function testNonAdminCannotUpdateUserStatus(): void
    {
        $operatorUserId = $this->createTestUser(5, 'user', 'active');
        $targetUserId = $this->createTestUser(6, 'user', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($operatorUserId, $targetUserId, 'disabled');

        $this->assertFalse($result['success']);
        $this->assertSame('当前账户没有用户管理权限。', $result['message']);
    }

    public function testDisabledAdminCannotUpdateUserStatus(): void
    {
        $adminId = $this->createTestUser(7, 'admin', 'disabled');
        $targetUserId = $this->createTestUser(8, 'user', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'disabled');

        $this->assertFalse($result['success']);
        $this->assertSame('当前管理员账户已被停用。', $result['message']);
    }

    public function testAdminCannotModifyOwnStatus(): void
    {
        $adminId = $this->createTestUser(9, 'admin', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $adminId, 'disabled');

        $this->assertFalse($result['success']);
        $this->assertSame('管理员不能修改自己的账户状态。', $result['message']);
    }

    public function testAdminCannotModifyAnotherAdminStatus(): void
    {
        $operatorAdminId = $this->createTestUser(10, 'admin', 'active');
        $targetAdminId = $this->createTestUser(11, 'admin', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($operatorAdminId, $targetAdminId, 'disabled');

        $this->assertFalse($result['success']);
        $this->assertSame('当前功能不允许修改其他管理员的账户状态。', $result['message']);
    }

    public function testUpdateStatusRejectsSameStatus(): void
    {
        $adminId = $this->createTestUser(12, 'admin', 'active');
        $targetUserId = $this->createTestUser(13, 'user', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'active');

        $this->assertFalse($result['success']);
        $this->assertSame('用户账户状态没有发生变化。', $result['message']);
    }

    public function testUpdateStatusRejectsInvalidStatus(): void
    {
        $adminId = $this->createTestUser(14, 'admin', 'active');
        $targetUserId = $this->createTestUser(15, 'user', 'active');

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'locked');

        $this->assertFalse($result['success']);
        $this->assertSame('用户账户状态不合法。', $result['message']);
    }

    public function testAdminCannotDisableUserWithActiveChargeRecord(): void
    {
        $adminId = $this->createTestUser(16, 'admin', 'active');
        $targetUserId = $this->createTestUser(17, 'user', 'active');
        $locationId = $this->createTestLocation(1);
        $stationId = $this->createTestStation(1, $locationId);

        $chargeService = $this->createChargeRecordService();
        $startResult = $chargeService->startCharging($targetUserId, $stationId);

        $this->assertTrue($startResult['success']);

        $service = $this->createUserService();
        $result = $service->updateStatus($adminId, $targetUserId, 'disabled');

        $this->assertFalse($result['success']);
        $this->assertSame('该用户当前仍有进行中的充电订单，不能停用账户。', $result['message']);

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('active', $targetUser->getStatus());
    }

    public function testUserCanUpdateOwnProfile(): void
    {
        $userId = $this->createTestUser(20);
        $service = $this->createUserService();

        $result = $service->updateProfile($userId, [
            'real_name' => '更新用户',
            'mobile' => '13800000020',
            'email' => 'updated020@example.com',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('个人资料更新成功。', $result['message']);

        $repository = new UserRepository($this->connection);
        $user = $repository->findById($userId);

        $this->assertNotNull($user);
        $this->assertSame('更新用户', $user->getRealName());
        $this->assertSame('13800000020', $user->getMobile());
        $this->assertSame('updated020@example.com', $user->getEmail());
    }

    public function testUpdateProfileRejectsDuplicateMobileAndEmail(): void
    {
        $existingUserId = $this->createTestUser(21);
        $targetUserId = $this->createTestUser(22);
        $service = $this->createUserService();

        $duplicateMobileResult = $service->updateProfile($targetUserId, [
            'real_name' => '目标用户',
            'mobile' => '13500000021',
            'email' => 'target022@example.com',
        ]);

        $this->assertFalse($duplicateMobileResult['success']);
        $this->assertArrayHasKey('mobile', $duplicateMobileResult['errors']);

        $duplicateEmailResult = $service->updateProfile($targetUserId, [
            'real_name' => '目标用户',
            'mobile' => '13800000022',
            'email' => 'tu0021@example.com',
        ]);

        $this->assertFalse($duplicateEmailResult['success']);
        $this->assertArrayHasKey('email', $duplicateEmailResult['errors']);

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('13500000022', $targetUser->getMobile());
        $this->assertSame('tu0022@example.com', $targetUser->getEmail());
        $this->assertNotNull($repository->findById($existingUserId));
    }

    public function testChangePasswordRejectsWrongCurrentPassword(): void
    {
        $userId = $this->createTestUser(23);
        $service = $this->createUserService();

        $result = $service->changePassword($userId, [
            'current_password' => 'Wrong123!',
            'new_password' => 'Bb123456!',
            'new_password_confirmation' => 'Bb123456!',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('密码修改验证失败。', $result['message']);
        $this->assertArrayHasKey('current_password', $result['errors']);

        $repository = new UserRepository($this->connection);
        $user = $repository->findById($userId);

        $this->assertNotNull($user);
        $this->assertTrue(password_verify('Aa123456!', $user->getPasswordHash()));
        $this->assertFalse(password_verify('Bb123456!', $user->getPasswordHash()));
    }

    public function testChangePasswordRejectsSamePassword(): void
    {
        $userId = $this->createTestUser(24);
        $service = $this->createUserService();

        $result = $service->changePassword($userId, [
            'current_password' => 'Aa123456!',
            'new_password' => 'Aa123456!',
            'new_password_confirmation' => 'Aa123456!',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('密码修改验证失败。', $result['message']);
        $this->assertArrayHasKey('new_password', $result['errors']);

        $repository = new UserRepository($this->connection);
        $user = $repository->findById($userId);

        $this->assertNotNull($user);
        $this->assertTrue(password_verify('Aa123456!', $user->getPasswordHash()));
    }

    public function testUserCanChangePassword(): void
    {
        $userId = $this->createTestUser(25);
        $service = $this->createUserService();

        $result = $service->changePassword($userId, [
            'current_password' => 'Aa123456!',
            'new_password' => 'Bb123456!',
            'new_password_confirmation' => 'Bb123456!',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('登录密码修改成功。', $result['message']);

        $repository = new UserRepository($this->connection);
        $user = $repository->findById($userId);

        $this->assertNotNull($user);
        $this->assertFalse(password_verify('Aa123456!', $user->getPasswordHash()));
        $this->assertTrue(password_verify('Bb123456!', $user->getPasswordHash()));
    }

    public function testNonAdminCannotManageAnotherUserProfileOrPassword(): void
    {
        $operatorUserId = $this->createTestUser(26);
        $targetUserId = $this->createTestUser(27);
        $service = $this->createUserService();

        $profileResult = $service->updateManagedProfile(
            $operatorUserId,
            $targetUserId,
            [
                'username' => 'managed027',
                'real_name' => '受管用户',
                'mobile' => '13800000027',
                'email' => 'managed027@example.com',
            ]
        );

        $this->assertFalse($profileResult['success']);
        $this->assertSame(
            '当前账户没有用户资料管理权限。',
            $profileResult['message']
        );

        $passwordResult = $service->resetManagedPassword(
            $operatorUserId,
            $targetUserId,
            [
                'new_password' => 'Bb123456!',
                'new_password_confirmation' => 'Bb123456!',
                'confirmed' => '1',
            ]
        );

        $this->assertFalse($passwordResult['success']);
        $this->assertSame(
            '当前账户没有重置用户密码的权限。',
            $passwordResult['message']
        );

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('tu0027', $targetUser->getUsername());
        $this->assertTrue(
            password_verify('Aa123456!', $targetUser->getPasswordHash())
        );
    }

    public function testAdminCanUpdateManagedUserProfile(): void
    {
        $adminId = $this->createTestUser(28, 'admin', 'active');
        $targetUserId = $this->createTestUser(29);
        $service = $this->createUserService();

        $result = $service->updateManagedProfile(
            $adminId,
            $targetUserId,
            [
                'username' => 'managed029',
                'real_name' => '管理员更新用户',
                'mobile' => '13800000029',
                'email' => 'managed029@example.com',
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            '用户资料更新成功。 用户名已修改，请通知用户使用新用户名登录。',
            $result['message']
        );

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertSame('managed029', $targetUser->getUsername());
        $this->assertSame('管理员更新用户', $targetUser->getRealName());
        $this->assertSame('13800000029', $targetUser->getMobile());
        $this->assertSame('managed029@example.com', $targetUser->getEmail());
    }

    public function testAdminCanResetNormalUserPasswordButCannotResetAdminPassword(): void
    {
        $operatorAdminId = $this->createTestUser(30, 'admin', 'active');
        $targetUserId = $this->createTestUser(31);
        $targetAdminId = $this->createTestUser(32, 'admin', 'active');
        $service = $this->createUserService();

        $resetResult = $service->resetManagedPassword(
            $operatorAdminId,
            $targetUserId,
            [
                'new_password' => 'Bb123456!',
                'new_password_confirmation' => 'Bb123456!',
                'confirmed' => '1',
            ]
        );

        $this->assertTrue($resetResult['success']);
        $this->assertSame(
            '用户登录密码重置成功，请通过安全方式通知用户。',
            $resetResult['message']
        );

        $repository = new UserRepository($this->connection);
        $targetUser = $repository->findById($targetUserId);

        $this->assertNotNull($targetUser);
        $this->assertFalse(
            password_verify('Aa123456!', $targetUser->getPasswordHash())
        );
        $this->assertTrue(
            password_verify('Bb123456!', $targetUser->getPasswordHash())
        );

        $adminResetResult = $service->resetManagedPassword(
            $operatorAdminId,
            $targetAdminId,
            [
                'new_password' => 'Cc123456!',
                'new_password_confirmation' => 'Cc123456!',
                'confirmed' => '1',
            ]
        );

        $this->assertFalse($adminResetResult['success']);
        $this->assertSame(
            '当前功能不允许重置管理员账户密码。',
            $adminResetResult['message']
        );

        $targetAdmin = $repository->findById($targetAdminId);

        $this->assertNotNull($targetAdmin);
        $this->assertTrue(
            password_verify('Aa123456!', $targetAdmin->getPasswordHash())
        );
        $this->assertFalse(
            password_verify('Cc123456!', $targetAdmin->getPasswordHash())
        );
    }

    private function createUserService(): UserService
    {
        return new UserService(
            $this->connection,
            new UserRepository($this->connection),
            new ChargeRecordRepository($this->connection)
        );
    }
}