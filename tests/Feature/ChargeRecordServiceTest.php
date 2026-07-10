<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ChargeRecordRepository;
use Tests\DatabaseTestCase;

final class ChargeRecordServiceTest extends DatabaseTestCase
{
    public function testStartChargingCreatesActiveRecord(): void
    {
        $userId = $this->createTestUser(1);
        $locationId = $this->createTestLocation(1);
        $stationId = $this->createTestStation(1, $locationId);

        $service = $this->createChargeRecordService();
        $result = $service->startCharging($userId, $stationId, '测试开始充电');

        $this->assertTrue($result['success']);
        $this->assertSame('充电已开始。', $result['message']);
        $this->assertNotNull($result['charge_record_id']);
        $this->assertArrayHasKey('order_number', $result);

        $recordRepository = new ChargeRecordRepository($this->connection);
        $record = $recordRepository->findById((int)$result['charge_record_id']);

        $this->assertNotNull($record);
        $this->assertSame($userId, $record->getUserId());
        $this->assertSame($stationId, $record->getStationId());
        $this->assertSame('charging', $record->getStatus());
        $this->assertSame('测试开始充电', $record->getRemark());
    }

    public function testStartChargingRejectsDisabledUser(): void
    {
        $userId = $this->createTestUser(2, 'user', 'disabled');
        $locationId = $this->createTestLocation(2);
        $stationId = $this->createTestStation(2, $locationId);

        $service = $this->createChargeRecordService();
        $result = $service->startCharging($userId, $stationId);

        $this->assertFalse($result['success']);
        $this->assertSame('当前账户已被停用，不能开始充电。', $result['message']);
        $this->assertNull($result['charge_record_id']);
    }

    public function testStartChargingRejectsAdminUser(): void
    {
        $adminId = $this->createTestUser(3, 'admin', 'active');
        $locationId = $this->createTestLocation(3);
        $stationId = $this->createTestStation(3, $locationId);

        $service = $this->createChargeRecordService();
        $result = $service->startCharging($adminId, $stationId);

        $this->assertFalse($result['success']);
        $this->assertSame('管理员账户不能创建普通用户充电订单。', $result['message']);
        $this->assertNull($result['charge_record_id']);
    }

    public function testStartChargingRejectsUnavailableStation(): void
    {
        $userId = $this->createTestUser(4);
        $locationId = $this->createTestLocation(4);
        $stationId = $this->createTestStation(4, $locationId, 'maintenance');

        $service = $this->createChargeRecordService();
        $result = $service->startCharging($userId, $stationId);

        $this->assertFalse($result['success']);
        $this->assertSame('该充电桩当前不可用。', $result['message']);
        $this->assertNull($result['charge_record_id']);
    }

    public function testStartChargingRejectsInactiveLocation(): void
    {
        $userId = $this->createTestUser(5);
        $locationId = $this->createTestLocation(5, 'maintenance');
        $stationId = $this->createTestStation(5, $locationId);

        $service = $this->createChargeRecordService();
        $result = $service->startCharging($userId, $stationId);

        $this->assertFalse($result['success']);
        $this->assertSame('该充电桩所属站点当前未运营。', $result['message']);
        $this->assertNull($result['charge_record_id']);
    }

    public function testStartChargingRejectsDuplicateActiveUserOrder(): void
    {
        $userId = $this->createTestUser(6);
        $locationId = $this->createTestLocation(6);
        $firstStationId = $this->createTestStation(6, $locationId);
        $secondStationId = $this->createTestStation(7, $locationId);

        $service = $this->createChargeRecordService();
        $firstResult = $service->startCharging($userId, $firstStationId);

        $this->assertTrue($firstResult['success']);

        $secondResult = $service->startCharging($userId, $secondStationId);

        $this->assertFalse($secondResult['success']);
        $this->assertSame('您当前已有正在进行的充电订单，不能重复开始充电。', $secondResult['message']);
        $this->assertNull($secondResult['charge_record_id']);
    }

    public function testStartChargingRejectsOccupiedStation(): void
    {
        $firstUserId = $this->createTestUser(8);
        $secondUserId = $this->createTestUser(9);
        $locationId = $this->createTestLocation(8);
        $stationId = $this->createTestStation(8, $locationId);

        $service = $this->createChargeRecordService();
        $firstResult = $service->startCharging($firstUserId, $stationId);

        $this->assertTrue($firstResult['success']);

        $secondResult = $service->startCharging($secondUserId, $stationId);

        $this->assertFalse($secondResult['success']);
        $this->assertSame('该充电桩当前正在使用，请选择其他充电桩。', $secondResult['message']);
        $this->assertNull($secondResult['charge_record_id']);
    }

    public function testFinishChargingCompletesOwnOrder(): void
    {
        $userId = $this->createTestUser(10);
        $locationId = $this->createTestLocation(10);
        $stationId = $this->createTestStation(10, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $chargeRecordId = (int)$startResult['charge_record_id'];
        $finishResult = $service->finishCharging($userId, $chargeRecordId);

        $this->assertTrue($finishResult['success']);
        $this->assertSame('充电已结束，订单结算完成。', $finishResult['message']);
        $this->assertSame($chargeRecordId, $finishResult['charge_record_id']);
        $this->assertNotNull($finishResult['check_out_at']);
        $this->assertNotNull($finishResult['billable_minutes']);
        $this->assertNotNull($finishResult['total_cost']);

        $recordRepository = new ChargeRecordRepository($this->connection);
        $record = $recordRepository->findById($chargeRecordId);

        $this->assertNotNull($record);
        $this->assertSame('completed', $record->getStatus());
        $this->assertNotNull($record->getCheckOutAt());
        $this->assertNotNull($record->getBillableMinutes());
        $this->assertNotNull($record->getTotalCost());
    }

    public function testFinishChargingRejectsOtherUserOrder(): void
    {
        $ownerUserId = $this->createTestUser(11);
        $otherUserId = $this->createTestUser(12);
        $locationId = $this->createTestLocation(11);
        $stationId = $this->createTestStation(11, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($ownerUserId, $stationId);

        $this->assertTrue($startResult['success']);

        $finishResult = $service->finishCharging(
            $otherUserId,
            (int)$startResult['charge_record_id']
        );

        $this->assertFalse($finishResult['success']);
        $this->assertSame('您无权结束其他用户的充电订单。', $finishResult['message']);
    }

    public function testFinishChargingRejectsRepeatedFinish(): void
    {
        $userId = $this->createTestUser(13);
        $locationId = $this->createTestLocation(13);
        $stationId = $this->createTestStation(13, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $chargeRecordId = (int)$startResult['charge_record_id'];
        $firstFinishResult = $service->finishCharging($userId, $chargeRecordId);

        $this->assertTrue($firstFinishResult['success']);

        $secondFinishResult = $service->finishCharging($userId, $chargeRecordId);

        $this->assertFalse($secondFinishResult['success']);
        $this->assertSame('该充电订单已经结束，不能重复操作。', $secondFinishResult['message']);
    }

    public function testFinishAbnormallyRequiresAdminOperator(): void
    {
        $ownerUserId = $this->createTestUser(14);
        $operatorUserId = $this->createTestUser(15);
        $locationId = $this->createTestLocation(14);
        $stationId = $this->createTestStation(14, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($ownerUserId, $stationId);

        $this->assertTrue($startResult['success']);

        $abnormalResult = $service->finishAbnormally(
            $operatorUserId,
            (int)$startResult['charge_record_id'],
            '测试异常结束'
        );

        $this->assertFalse($abnormalResult['success']);
        $this->assertSame('当前账户没有管理员操作权限。', $abnormalResult['message']);
    }

    public function testFinishAbnormallyRejectsBlankRemark(): void
    {
        $adminId = $this->createTestUser(16, 'admin', 'active');
        $ownerUserId = $this->createTestUser(17);
        $locationId = $this->createTestLocation(16);
        $stationId = $this->createTestStation(16, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($ownerUserId, $stationId);

        $this->assertTrue($startResult['success']);

        $abnormalResult = $service->finishAbnormally(
            $adminId,
            (int)$startResult['charge_record_id'],
            '   '
        );

        $this->assertFalse($abnormalResult['success']);
        $this->assertSame('异常结束订单时必须填写原因。', $abnormalResult['message']);
    }

    public function testFinishAbnormallyCompletesChargingOrder(): void
    {
        $adminId = $this->createTestUser(18, 'admin', 'active');
        $ownerUserId = $this->createTestUser(19);
        $locationId = $this->createTestLocation(18);
        $stationId = $this->createTestStation(18, $locationId);

        $service = $this->createChargeRecordService();
        $startResult = $service->startCharging($ownerUserId, $stationId);

        $this->assertTrue($startResult['success']);

        $chargeRecordId = (int)$startResult['charge_record_id'];
        $abnormalResult = $service->finishAbnormally(
            $adminId,
            $chargeRecordId,
            '设备异常，管理员结束订单'
        );

        $this->assertTrue($abnormalResult['success']);
        $this->assertSame('订单已异常结束并完成结算。', $abnormalResult['message']);
        $this->assertSame($chargeRecordId, $abnormalResult['charge_record_id']);

        $recordRepository = new ChargeRecordRepository($this->connection);
        $record = $recordRepository->findById($chargeRecordId);

        $this->assertNotNull($record);
        $this->assertSame('abnormal', $record->getStatus());
        $this->assertSame('设备异常，管理员结束订单', $record->getRemark());
        $this->assertNotNull($record->getCheckOutAt());
        $this->assertNotNull($record->getBillableMinutes());
        $this->assertNotNull($record->getTotalCost());
    }
}