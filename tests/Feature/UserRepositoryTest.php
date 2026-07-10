<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\UserRepository;
use RuntimeException;
use Tests\DatabaseTestCase;

final class UserRepositoryTest extends DatabaseTestCase
{
    public function testCreateAndFindById(): void
    {
        $repository = new UserRepository($this->connection);

        $userId = $this->createUser($repository, 1);

        $user = $repository->findById($userId);

        $this->assertNotNull($user);
        $this->assertSame($userId, $user->getUserId());
        $this->assertSame('user001', $user->getUsername());
        $this->assertSame('张三', $user->getRealName());
        $this->assertSame('13800000001', $user->getMobile());
        $this->assertSame('user001@example.com', $user->getEmail());
        $this->assertSame('user', $user->getRole());
        $this->assertSame('active', $user->getStatus());
    }

    public function testFindByUsername(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser($repository, 2);

        $user = $repository->findByUsername('user002');

        $this->assertNotNull($user);
        $this->assertSame('user002', $user->getUsername());
    }

    public function testUsernameMustBeUnique(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser($repository, 3, 'same001', '13800000003', 'same001@example.com');

        $this->assertThrows(
            RuntimeException::class,
            function() use ($repository): void {
                $this->createUser($repository, 4, 'same001', '13800000004', 'same002@example.com');
            }
        );
    }

    public function testDatabaseRejectsChineseUsername(): void
    {
        $repository = new UserRepository($this->connection);

        $this->assertThrows(
            RuntimeException::class,
            function() use ($repository): void {
                $this->createUser($repository, 5, '用户123', '13800000005', 'bad001@example.com');
            }
        );
    }

    public function testUpdateStatus(): void
    {
        $repository = new UserRepository($this->connection);

        $userId = $this->createUser($repository, 6);

        $updated = $repository->updateStatus($userId, 'disabled');
        $user = $repository->findById($userId);

        $this->assertTrue($updated);
        $this->assertNotNull($user);
        $this->assertSame('disabled', $user->getStatus());
    }

    public function testSearchAdminListUsesPagination(): void
    {
        $repository = new UserRepository($this->connection);

        for($i = 1; $i <= 25; $i++){
            $this->createUser($repository, $i);
        }

        $firstPageUsers = $repository->searchAdminList([], 10, 0);
        $secondPageUsers = $repository->searchAdminList([], 10, 10);
        $thirdPageUsers = $repository->searchAdminList([], 10, 20);

        $this->assertCount(10, $firstPageUsers);
        $this->assertCount(10, $secondPageUsers);
        $this->assertCount(5, $thirdPageUsers);
    }

    public function testAdminListSummaryCountsUsersByStatusAndRole(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser($repository, 7, 'user007', '13800000007', 'user007@example.com', 'user', 'active');
        $this->createUser($repository, 8, 'user008', '13800000008', 'user008@example.com', 'user', 'disabled');
        $this->createUser($repository, 9, 'admin009', '13800000009', 'admin009@example.com', 'admin', 'active');

        $summary = $repository->getAdminListSummary([]);

        $this->assertSame(3, $summary['total_users']);
        $this->assertSame(2, $summary['active_users']);
        $this->assertSame(1, $summary['disabled_users']);
        $this->assertSame(1, $summary['admin_users']);
        $this->assertSame(2, $summary['normal_users']);
    }

    public function testSearchAdminListFiltersByRole(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser(
            $repository,
            10,
            'user010',
            '13800000010',
            'user010@example.com',
            'user',
            'active'
        );

        $this->createUser(
            $repository,
            11,
            'admin011',
            '13800000011',
            'admin011@example.com',
            'admin',
            'active'
        );

        $users = $repository->searchAdminList(
            [
                'role' => 'admin',
            ],
            20,
            0
        );

        $this->assertCount(1, $users);
        $this->assertSame('admin', $users[0]->getRole());
        $this->assertSame('admin011', $users[0]->getUsername());
    }

    public function testSearchAdminListFiltersByStatus(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser(
            $repository,
            12,
            'user012',
            '13800000012',
            'user012@example.com',
            'user',
            'active'
        );

        $this->createUser(
            $repository,
            13,
            'user013',
            '13800000013',
            'user013@example.com',
            'user',
            'disabled'
        );

        $users = $repository->searchAdminList(
            [
                'status' => 'disabled',
            ],
            20,
            0
        );

        $this->assertCount(1, $users);
        $this->assertSame('disabled', $users[0]->getStatus());
        $this->assertSame('user013', $users[0]->getUsername());
    }

    public function testSearchAdminListCombinesRoleAndStatusFilters(): void
    {
        $repository = new UserRepository($this->connection);

        $this->createUser(
            $repository,
            14,
            'user014',
            '13800000014',
            'user014@example.com',
            'user',
            'disabled'
        );

        $this->createUser(
            $repository,
            15,
            'admin015',
            '13800000015',
            'admin015@example.com',
            'admin',
            'disabled'
        );

        $this->createUser(
            $repository,
            16,
            'admin016',
            '13800000016',
            'admin016@example.com',
            'admin',
            'active'
        );

        $users = $repository->searchAdminList(
            [
                'role' => 'admin',
                'status' => 'disabled',
            ],
            20,
            0
        );

        $this->assertCount(1, $users);
        $this->assertSame('admin015', $users[0]->getUsername());
        $this->assertSame('admin', $users[0]->getRole());
        $this->assertSame('disabled', $users[0]->getStatus());
    }

    public function testSearchAdminListPlacesAdminsAfterNormalUsers(): void
    {
        $repository = new UserRepository($this->connection);

        $adminId1 = $this->createUser(
            $repository,
            17,
            'admin017',
            '13800000017',
            'admin017@example.com',
            'admin',
            'active'
        );

        $userId1 = $this->createUser(
            $repository,
            18,
            'user018',
            '13800000018',
            'user018@example.com',
            'user',
            'active'
        );

        $adminId2 = $this->createUser(
            $repository,
            19,
            'admin019',
            '13800000019',
            'admin019@example.com',
            'admin',
            'active'
        );

        $userId2 = $this->createUser(
            $repository,
            20,
            'user020',
            '13800000020',
            'user020@example.com',
            'user',
            'active'
        );

        $users = $repository->searchAdminList([], 20, 0);

        $this->assertCount(4, $users);

        $this->assertSame($userId2, $users[0]->getUserId());
        $this->assertSame($userId1, $users[1]->getUserId());
        $this->assertSame($adminId2, $users[2]->getUserId());
        $this->assertSame($adminId1, $users[3]->getUserId());
    }

    private function createUser(
        UserRepository $repository,
        int $index,
        ?string $username = null,
        ?string $mobile = null,
        ?string $email = null,
        string $role = 'user',
        string $status = 'active'
    ): int {
        $now = date('Y-m-d H:i:s');

        $user = new User(
            null,
            $username ?? sprintf('user%03d', $index),
            password_hash('Aa123456!', PASSWORD_DEFAULT),
            '张三',
            $mobile ?? sprintf('138%08d', $index),
            $email ?? sprintf('user%03d@example.com', $index),
            $role,
            $status,
            null,
            $now,
            $now
        );

        return $repository->create($user);
    }
}