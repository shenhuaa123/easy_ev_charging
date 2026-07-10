<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use RuntimeException;
use Tests\DatabaseTestCase;

final class AdminOperationLogTest extends DatabaseTestCase
{
    public function testRecordCreatesAdminOperationLog(): void
    {
        $adminId = $this->createAdminUser(1);

        $service = $this->createLogService();

        $logId = $service->record(
            $adminId,
            'user_status_update',
            'user',
            2,
            'success',
            '目标状态：disabled；结果：用户账户已停用。',
            '127.0.0.1',
            'PHPUnit-Test-Agent'
        );

        $repository = new AdminOperationLogRepository($this->connection);
        $log = $repository->findById($logId);

        $this->assertNotNull($log);
        $this->assertSame($adminId, $log->getOperatorUserId());
        $this->assertSame('user_status_update', $log->getAction());
        $this->assertSame('修改用户状态', $log->getActionLabel());
        $this->assertSame('user', $log->getTargetType());
        $this->assertSame('用户', $log->getTargetTypeLabel());
        $this->assertSame(2, $log->getTargetId());
        $this->assertSame('success', $log->getResult());
        $this->assertSame('成功', $log->getResultLabel());
        $this->assertSame('目标状态：disabled；结果：用户账户已停用。', $log->getDetail());
        $this->assertSame('127.0.0.1', $log->getIpAddress());
        $this->assertSame('PHPUnit-Test-Agent', $log->getUserAgent());
    }

    public function testRecordRejectsInvalidOperatorUserId(): void
    {
        $service = $this->createLogService();

        $this->assertThrows(
            RuntimeException::class,
            function() use ($service): void {
                $service->record(
                    0,
                    'admin_login_success',
                    'user',
                    1,
                    'success',
                    '管理员登录成功。'
                );
            }
        );
    }

    public function testRecordRejectsBlankAction(): void
    {
        $adminId = $this->createAdminUser(2);
        $service = $this->createLogService();

        $this->assertThrows(
            RuntimeException::class,
            function() use ($service, $adminId): void {
                $service->record(
                    $adminId,
                    '   ',
                    'user',
                    $adminId,
                    'success',
                    '管理员登录成功。'
                );
            }
        );
    }

    public function testRecordRejectsInvalidResult(): void
    {
        $adminId = $this->createAdminUser(3);
        $service = $this->createLogService();

        $this->assertThrows(
            RuntimeException::class,
            function() use ($service, $adminId): void {
                $service->record(
                    $adminId,
                    'admin_login_success',
                    'user',
                    $adminId,
                    'unknown',
                    '管理员登录成功。'
                );
            }
        );
    }

    public function testSearchListItemsSupportsActionFilter(): void
    {
        $adminId = $this->createAdminUser(4);
        $service = $this->createLogService();

        $service->record($adminId, 'admin_login_success', 'user', $adminId, 'success', '管理员登录成功。');
        $service->record($adminId, 'admin_logout', 'user', $adminId, 'success', '管理员主动退出登录。');

        $repository = new AdminOperationLogRepository($this->connection);
        $items = $repository->searchListItems(
            [
                'action' => 'admin_logout',
            ],
            20,
            0
        );

        $this->assertCount(1, $items);
        $this->assertSame('admin_logout', $items[0]['log']->getAction());
        $this->assertSame('管理员退出', $items[0]['log']->getActionLabel());
    }

    public function testSearchListItemsSupportsResultFilter(): void
    {
        $adminId = $this->createAdminUser(5);
        $service = $this->createLogService();

        $service->record($adminId, 'location_create', 'location', 1, 'success', '新增充电站点成功。');
        $service->record($adminId, 'location_create', 'location', null, 'failure', '充电站点新增验证失败。');

        $repository = new AdminOperationLogRepository($this->connection);
        $items = $repository->searchListItems(
            [
                'result' => 'failure',
            ],
            20,
            0
        );

        $this->assertCount(1, $items);
        $this->assertSame('failure', $items[0]['log']->getResult());
        $this->assertSame('失败', $items[0]['log']->getResultLabel());
    }

    public function testSearchListItemsSupportsKeywordFilter(): void
    {
        $adminId = $this->createAdminUser(6);
        $service = $this->createLogService();

        $service->record($adminId, 'station_create', 'station', 1, 'success', '新增充电桩成功：ST-001 / 测试充电桩');
        $service->record($adminId, 'location_create', 'location', 2, 'success', '新增充电站点成功：LOC-001 / 测试站点');

        $repository = new AdminOperationLogRepository($this->connection);
        $items = $repository->searchListItems(
            [
                'keyword' => '测试充电桩',
            ],
            20,
            0
        );

        $this->assertCount(1, $items);
        $this->assertSame('station_create', $items[0]['log']->getAction());
    }

    public function testCountListItemsMatchesFilteredResult(): void
    {
        $adminId = $this->createAdminUser(7);
        $service = $this->createLogService();

        $service->record($adminId, 'user_status_update', 'user', 10, 'success', '用户账户已启用。');
        $service->record($adminId, 'user_status_update', 'user', 11, 'failure', '用户账户状态没有发生变化。');
        $service->record($adminId, 'station_status_update', 'station', 12, 'success', '充电桩状态修改成功。');

        $repository = new AdminOperationLogRepository($this->connection);

        $this->assertSame(
            2,
            $repository->countListItems([
                'target_type' => 'user',
            ])
        );

        $this->assertSame(
            1,
            $repository->countListItems([
                'target_type' => 'user',
                'result' => 'failure',
            ])
        );
    }

    public function testSearchListItemsUsesPagination(): void
    {
        $adminId = $this->createAdminUser(8);
        $service = $this->createLogService();

        for($i = 1; $i <= 25; $i++){
            $service->record(
                $adminId,
                'admin_login_success',
                'user',
                $adminId,
                'success',
                '管理员登录成功，第' . $i . '次。'
            );
        }

        $repository = new AdminOperationLogRepository($this->connection);

        $firstPageItems = $repository->searchListItems([], 10, 0);
        $secondPageItems = $repository->searchListItems([], 10, 10);
        $thirdPageItems = $repository->searchListItems([], 10, 20);

        $this->assertCount(10, $firstPageItems);
        $this->assertCount(10, $secondPageItems);
        $this->assertCount(5, $thirdPageItems);
    }

    private function createLogService(): AdminOperationLogService
    {
        return new AdminOperationLogService(
            new AdminOperationLogRepository($this->connection)
        );
    }

    private function createAdminUser(int $index): int
    {
        $repository = new UserRepository($this->connection);
        $now = date('Y-m-d H:i:s');

        $user = new User(
            null,
            sprintf('log%04d', $index),
            password_hash('Aa123456!', PASSWORD_DEFAULT),
            '日志管理员',
            sprintf('137%08d', $index),
            sprintf('log%04d@example.com', $index),
            'admin',
            'active',
            null,
            $now,
            $now
        );

        return $repository->create($user);
    }
}