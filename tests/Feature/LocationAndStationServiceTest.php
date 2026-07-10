<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use App\Services\ChargingStationService;
use App\Services\LocationService;
use Tests\DatabaseTestCase;

final class LocationAndStationServiceTest extends DatabaseTestCase
{
    public function testLocationMaintenanceSyncsActiveStationsToMaintenance(): void
    {
        $adminId = $this->createTestUser(1, 'admin', 'active');
        $locationId = $this->createTestLocation(1, 'active');
        $activeStationId = $this->createTestStation(1, $locationId, 'active');
        $inactiveStationId = $this->createTestStation(2, $locationId, 'inactive');

        $service = $this->createLocationService();
        $result = $service->updateStatus($adminId, $locationId, 'maintenance');

        $this->assertTrue($result['success']);
        $this->assertSame('充电站点已进入维护状态，1台可用充电桩已同步调整为维护中。', $result['message']);

        $locationRepository = new LocationRepository($this->connection);
        $stationRepository = new ChargingStationRepository($this->connection);

        $location = $locationRepository->findById($locationId);
        $activeStation = $stationRepository->findById($activeStationId);
        $inactiveStation = $stationRepository->findById($inactiveStationId);

        $this->assertNotNull($location);
        $this->assertNotNull($activeStation);
        $this->assertNotNull($inactiveStation);
        $this->assertSame('maintenance', $location->getStatus());
        $this->assertSame('maintenance', $activeStation->getStatus());
        $this->assertSame('inactive', $inactiveStation->getStatus());
    }

    public function testLocationInactiveSyncsActiveAndMaintenanceStationsToInactive(): void
    {
        $adminId = $this->createTestUser(2, 'admin', 'active');
        $locationId = $this->createTestLocation(2, 'active');
        $activeStationId = $this->createTestStation(3, $locationId, 'active');
        $maintenanceStationId = $this->createTestStation(4, $locationId, 'maintenance');
        $inactiveStationId = $this->createTestStation(5, $locationId, 'inactive');

        $service = $this->createLocationService();
        $result = $service->updateStatus($adminId, $locationId, 'inactive');

        $this->assertTrue($result['success']);
        $this->assertSame('充电站点已停用，2台充电桩已同步调整为停用状态。', $result['message']);

        $locationRepository = new LocationRepository($this->connection);
        $stationRepository = new ChargingStationRepository($this->connection);

        $location = $locationRepository->findById($locationId);
        $activeStation = $stationRepository->findById($activeStationId);
        $maintenanceStation = $stationRepository->findById($maintenanceStationId);
        $inactiveStation = $stationRepository->findById($inactiveStationId);

        $this->assertNotNull($location);
        $this->assertNotNull($activeStation);
        $this->assertNotNull($maintenanceStation);
        $this->assertNotNull($inactiveStation);
        $this->assertSame('inactive', $location->getStatus());
        $this->assertSame('inactive', $activeStation->getStatus());
        $this->assertSame('inactive', $maintenanceStation->getStatus());
        $this->assertSame('inactive', $inactiveStation->getStatus());
    }

    public function testLocationCannotEnterMaintenanceWhenActiveChargeExists(): void
    {
        $adminId = $this->createTestUser(3, 'admin', 'active');
        $userId = $this->createTestUser(4, 'user', 'active');
        $locationId = $this->createTestLocation(3, 'active');
        $stationId = $this->createTestStation(6, $locationId, 'active');

        $chargeService = $this->createChargeRecordService();
        $startResult = $chargeService->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $service = $this->createLocationService();
        $result = $service->updateStatus($adminId, $locationId, 'maintenance');

        $this->assertFalse($result['success']);
        $this->assertSame('该站点仍有用户正在充电，不能进入维护或停用状态。', $result['message']);

        $locationRepository = new LocationRepository($this->connection);
        $location = $locationRepository->findById($locationId);

        $this->assertNotNull($location);
        $this->assertSame('active', $location->getStatus());
    }

    public function testLocationCannotEnterInactiveWhenActiveChargeExists(): void
    {
        $adminId = $this->createTestUser(5, 'admin', 'active');
        $userId = $this->createTestUser(6, 'user', 'active');
        $locationId = $this->createTestLocation(4, 'active');
        $stationId = $this->createTestStation(7, $locationId, 'active');

        $chargeService = $this->createChargeRecordService();
        $startResult = $chargeService->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $service = $this->createLocationService();
        $result = $service->updateStatus($adminId, $locationId, 'inactive');

        $this->assertFalse($result['success']);
        $this->assertSame('该站点仍有用户正在充电，不能进入维护或停用状态。', $result['message']);

        $locationRepository = new LocationRepository($this->connection);
        $location = $locationRepository->findById($locationId);

        $this->assertNotNull($location);
        $this->assertSame('active', $location->getStatus());
    }

    public function testMaintenanceLocationRejectsStationActiveStatus(): void
    {
        $adminId = $this->createTestUser(7, 'admin', 'active');
        $locationId = $this->createTestLocation(5, 'maintenance');
        $stationId = $this->createTestStation(8, $locationId, 'maintenance');

        $service = $this->createStationService();
        $result = $service->updateStatus($adminId, $stationId, 'active');

        $this->assertFalse($result['success']);
        $this->assertSame('所属站点正在维护，不能将充电桩调整为可用状态。', $result['message']);

        $stationRepository = new ChargingStationRepository($this->connection);
        $station = $stationRepository->findById($stationId);

        $this->assertNotNull($station);
        $this->assertSame('maintenance', $station->getStatus());
    }

    public function testInactiveLocationRejectsStationMaintenanceStatus(): void
    {
        $adminId = $this->createTestUser(8, 'admin', 'active');
        $locationId = $this->createTestLocation(6, 'inactive');
        $stationId = $this->createTestStation(9, $locationId, 'inactive');

        $service = $this->createStationService();
        $result = $service->updateStatus($adminId, $stationId, 'maintenance');

        $this->assertFalse($result['success']);
        $this->assertSame('所属站点已停用，充电桩只能保持停用状态。', $result['message']);

        $stationRepository = new ChargingStationRepository($this->connection);
        $station = $stationRepository->findById($stationId);

        $this->assertNotNull($station);
        $this->assertSame('inactive', $station->getStatus());
    }

    public function testActiveStationWithActiveChargeCannotEnterMaintenance(): void
    {
        $adminId = $this->createTestUser(9, 'admin', 'active');
        $userId = $this->createTestUser(10, 'user', 'active');
        $locationId = $this->createTestLocation(7, 'active');
        $stationId = $this->createTestStation(10, $locationId, 'active');

        $chargeService = $this->createChargeRecordService();
        $startResult = $chargeService->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $service = $this->createStationService();
        $result = $service->updateStatus($adminId, $stationId, 'maintenance');

        $this->assertFalse($result['success']);
        $this->assertSame('该充电桩当前正在使用，不能进入维护或停用状态。', $result['message']);

        $stationRepository = new ChargingStationRepository($this->connection);
        $station = $stationRepository->findById($stationId);

        $this->assertNotNull($station);
        $this->assertSame('active', $station->getStatus());
    }

    public function testActiveStationWithActiveChargeCannotEnterInactive(): void
    {
        $adminId = $this->createTestUser(11, 'admin', 'active');
        $userId = $this->createTestUser(12, 'user', 'active');
        $locationId = $this->createTestLocation(8, 'active');
        $stationId = $this->createTestStation(11, $locationId, 'active');

        $chargeService = $this->createChargeRecordService();
        $startResult = $chargeService->startCharging($userId, $stationId);

        $this->assertTrue($startResult['success']);

        $service = $this->createStationService();
        $result = $service->updateStatus($adminId, $stationId, 'inactive');

        $this->assertFalse($result['success']);
        $this->assertSame('该充电桩当前正在使用，不能进入维护或停用状态。', $result['message']);

        $stationRepository = new ChargingStationRepository($this->connection);
        $station = $stationRepository->findById($stationId);

        $this->assertNotNull($station);
        $this->assertSame('active', $station->getStatus());
    }

    public function testStationStatusCanBeChangedWhenNoActiveChargeExists(): void
    {
        $adminId = $this->createTestUser(13, 'admin', 'active');
        $locationId = $this->createTestLocation(9, 'active');
        $stationId = $this->createTestStation(12, $locationId, 'active');

        $service = $this->createStationService();
        $result = $service->updateStatus($adminId, $stationId, 'maintenance');

        $this->assertTrue($result['success']);
        $this->assertSame('充电桩状态修改成功。', $result['message']);

        $stationRepository = new ChargingStationRepository($this->connection);
        $station = $stationRepository->findById($stationId);

        $this->assertNotNull($station);
        $this->assertSame('maintenance', $station->getStatus());
    }

    public function testLocationCanReturnToActiveWithoutChangingStationStatuses(): void
    {
        $adminId = $this->createTestUser(14, 'admin', 'active');
        $locationId = $this->createTestLocation(10, 'maintenance');
        $maintenanceStationId = $this->createTestStation(13, $locationId, 'maintenance');
        $inactiveStationId = $this->createTestStation(14, $locationId, 'inactive');

        $service = $this->createLocationService();
        $result = $service->updateStatus($adminId, $locationId, 'active');

        $this->assertTrue($result['success']);
        $this->assertSame('充电站点已恢复运营。所属充电桩状态保持不变，请根据设备实际情况逐台调整。', $result['message']);

        $locationRepository = new LocationRepository($this->connection);
        $stationRepository = new ChargingStationRepository($this->connection);

        $location = $locationRepository->findById($locationId);
        $maintenanceStation = $stationRepository->findById($maintenanceStationId);
        $inactiveStation = $stationRepository->findById($inactiveStationId);

        $this->assertNotNull($location);
        $this->assertNotNull($maintenanceStation);
        $this->assertNotNull($inactiveStation);
        $this->assertSame('active', $location->getStatus());
        $this->assertSame('maintenance', $maintenanceStation->getStatus());
        $this->assertSame('inactive', $inactiveStation->getStatus());
    }

    public function testAdminCanCreateAndUpdateLocation(): void
    {
        $adminId = $this->createTestUser(40, 'admin', 'active');
        $service = $this->createLocationService();

        $createResult = $service->create($adminId, [
            'location_code' => 'loc-new-001',
            'location_name' => '新建测试站点',
            'province' => '江苏省',
            'city' => '南京市',
            'district' => '建邺区',
            'detailed_address' => '测试大道100号',
            'description' => '初始站点说明',
            'longitude' => '118.796877',
            'latitude' => '32.060255',
            'status' => 'active',
        ]);

        $this->assertTrue($createResult['success']);
        $this->assertNotNull($createResult['location_id']);

        $locationId = (int)$createResult['location_id'];

        $repository = new LocationRepository($this->connection);
        $createdLocation = $repository->findById($locationId);

        $this->assertNotNull($createdLocation);
        $this->assertSame(
            'LOC-NEW-001',
            $createdLocation->getLocationCode()
        );
        $this->assertSame(
            '新建测试站点',
            $createdLocation->getLocationName()
        );
        $this->assertSame(
            'active',
            $createdLocation->getStatus()
        );

        $updateResult = $service->update(
            $adminId,
            $locationId,
            [
                'location_name' => '更新后的测试站点',
                'province' => '江苏省',
                'city' => '南京市',
                'district' => '鼓楼区',
                'detailed_address' => '更新大道200号',
                'description' => '更新后的站点说明',
                'longitude' => '118.778074',
                'latitude' => '32.057236',
            ]
        );

        $this->assertTrue($updateResult['success']);
        $this->assertSame(
            '充电站点资料更新成功。',
            $updateResult['message']
        );

        $updatedLocation = $repository->findById($locationId);

        $this->assertNotNull($updatedLocation);
        $this->assertSame(
            'LOC-NEW-001',
            $updatedLocation->getLocationCode()
        );
        $this->assertSame(
            '更新后的测试站点',
            $updatedLocation->getLocationName()
        );
        $this->assertSame(
            '鼓楼区',
            $updatedLocation->getDistrict()
        );
        $this->assertSame(
            '更新大道200号',
            $updatedLocation->getDetailedAddress()
        );
        $this->assertSame(
            '更新后的站点说明',
            $updatedLocation->getDescription()
        );
        $this->assertSame(
            'active',
            $updatedLocation->getStatus()
        );
    }

    public function testCreateLocationRejectsDuplicateCode(): void
    {
        $adminId = $this->createTestUser(
            41,
            'admin',
            'active'
        );

        $this->createTestLocation(41);

        $service = $this->createLocationService();

        $result = $service->create($adminId, [
            'location_code' => 'tloc041',
            'location_name' => '重复编号测试站点',
            'province' => '浙江省',
            'city' => '杭州市',
            'district' => '西湖区',
            'detailed_address' => '测试路41号',
            'description' => '',
            'longitude' => '120.155070',
            'latitude' => '30.274084',
            'status' => 'active',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull($result['location_id']);
        $this->assertArrayHasKey(
            'location_code',
            $result['errors']
        );
    }

    public function testCreateLocationRejectsInvalidCoordinates(): void
    {
        $adminId = $this->createTestUser(
            42,
            'admin',
            'active'
        );

        $service = $this->createLocationService();

        $result = $service->create($adminId, [
            'location_code' => 'LOC-COORD-001',
            'location_name' => '坐标测试站点',
            'province' => '广东省',
            'city' => '深圳市',
            'district' => '南山区',
            'detailed_address' => '坐标测试路1号',
            'description' => '',
            'longitude' => '181',
            'latitude' => '91',
            'status' => 'active',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull($result['location_id']);

        $this->assertArrayHasKey(
            'longitude',
            $result['errors']
        );

        $this->assertArrayHasKey(
            'latitude',
            $result['errors']
        );
    }

    public function testAdminCanCreateAndUpdateStation(): void
    {
        $adminId = $this->createTestUser(
            43,
            'admin',
            'active'
        );

        $locationId = $this->createTestLocation(
            43,
            'active'
        );

        $service = $this->createStationService();

        $createResult = $service->create($adminId, [
            'station_code' => 'station-new-001',
            'station_name' => '新建测试充电桩',
            'location_id' => $locationId,
            'charger_type' => 'DC',
            'power_kw' => '120.50',
            'hourly_rate' => '15.80',
            'status' => 'active',
        ]);

        $this->assertTrue($createResult['success']);
        $this->assertNotNull($createResult['station_id']);

        $stationId = (int)$createResult['station_id'];

        $repository = new ChargingStationRepository(
            $this->connection
        );

        $createdStation = $repository->findById(
            $stationId
        );

        $this->assertNotNull($createdStation);
        $this->assertSame(
            'STATION-NEW-001',
            $createdStation->getStationCode()
        );
        $this->assertSame(
            '新建测试充电桩',
            $createdStation->getStationName()
        );
        $this->assertSame(
            $locationId,
            $createdStation->getLocationId()
        );
        $this->assertSame(
            'dc',
            $createdStation->getChargerType()
        );
        $this->assertSame(
            'active',
            $createdStation->getStatus()
        );

        $updateResult = $service->update(
            $adminId,
            $stationId,
            [
                'station_code' => 'STATION-NEW-001',
                'station_name' => '更新后的测试充电桩',
                'location_id' => $locationId,
                'charger_type' => 'dc',
                'power_kw' => '150.00',
                'hourly_rate' => '18.50',
            ]
        );

        $this->assertTrue($updateResult['success']);
        $this->assertSame(
            '充电桩资料更新成功。',
            $updateResult['message']
        );

        $updatedStation = $repository->findById(
            $stationId
        );

        $this->assertNotNull($updatedStation);
        $this->assertSame(
            'STATION-NEW-001',
            $updatedStation->getStationCode()
        );
        $this->assertSame(
            '更新后的测试充电桩',
            $updatedStation->getStationName()
        );
        $this->assertSame(
            '150.00',
            $updatedStation->getPowerKw()
        );
        $this->assertSame(
            '18.50',
            $updatedStation->getHourlyRate()
        );
        $this->assertSame(
            'active',
            $updatedStation->getStatus()
        );
    }

    public function testMaintenanceLocationRejectsCreatingActiveStation(): void
    {
        $adminId = $this->createTestUser(
            44,
            'admin',
            'active'
        );

        $locationId = $this->createTestLocation(
            44,
            'maintenance'
        );

        $service = $this->createStationService();

        $result = $service->create($adminId, [
            'station_code' => 'ST-MAINT-001',
            'station_name' => '维护站点测试充电桩',
            'location_id' => $locationId,
            'charger_type' => 'dc',
            'power_kw' => '60.00',
            'hourly_rate' => '12.00',
            'status' => 'active',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNull($result['station_id']);

        $this->assertArrayHasKey(
            'status',
            $result['errors']
        );

        $this->assertSame(
            '所属站点未处于运营状态，不能将新充电桩设置为可用。',
            $result['errors']['status'][0]
        );
    }

    public function testActiveChargeRestrictsHardwareChangesButAllowsNameAndRateChanges(): void
    {
        $adminId = $this->createTestUser(
            45,
            'admin',
            'active'
        );

        $userId = $this->createTestUser(
            46,
            'user',
            'active'
        );

        $locationId = $this->createTestLocation(
            45,
            'active'
        );

        $stationId = $this->createTestStation(
            50,
            $locationId,
            'active',
            'dc'
        );

        $chargeService = $this->createChargeRecordService();

        $startResult = $chargeService->startCharging(
            $userId,
            $stationId
        );

        $this->assertTrue($startResult['success']);

        $service = $this->createStationService();

        $restrictedResult = $service->update(
            $adminId,
            $stationId,
            [
                'station_code' => 'TST050-NEW',
                'station_name' => '测试充电桩50',
                'location_id' => $locationId,
                'charger_type' => 'dc',
                'power_kw' => '60.00',
                'hourly_rate' => '12.00',
            ]
        );

        $this->assertFalse(
            $restrictedResult['success']
        );

        $this->assertSame(
            '该充电桩当前正在使用，不能修改设备编号、所属站点、充电类型或功率。',
            $restrictedResult['message']
        );

        $repository = new ChargingStationRepository(
            $this->connection
        );

        $stationAfterRejectedUpdate = $repository->findById(
            $stationId
        );

        $this->assertNotNull(
            $stationAfterRejectedUpdate
        );

        $this->assertSame(
            'TST050',
            $stationAfterRejectedUpdate->getStationCode()
        );

        $allowedResult = $service->update(
            $adminId,
            $stationId,
            [
                'station_code' => 'TST050',
                'station_name' => '运行中允许更新名称',
                'location_id' => $locationId,
                'charger_type' => 'dc',
                'power_kw' => '60.00',
                'hourly_rate' => '15.00',
            ]
        );

        $this->assertTrue($allowedResult['success']);
        $this->assertSame(
            '充电桩资料更新成功。',
            $allowedResult['message']
        );

        $stationAfterAllowedUpdate = $repository->findById(
            $stationId
        );

        $this->assertNotNull(
            $stationAfterAllowedUpdate
        );

        $this->assertSame(
            'TST050',
            $stationAfterAllowedUpdate->getStationCode()
        );

        $this->assertSame(
            '运行中允许更新名称',
            $stationAfterAllowedUpdate->getStationName()
        );

        $this->assertSame(
            '15.00',
            $stationAfterAllowedUpdate->getHourlyRate()
        );

        $this->assertSame(
            '60.00',
            $stationAfterAllowedUpdate->getPowerKw()
        );
    }

    private function createLocationService(): LocationService
    {
        return new LocationService(
            $this->connection,
            new LocationRepository($this->connection),
            new ChargingStationRepository($this->connection),
            new UserRepository($this->connection)
        );
    }

    private function createStationService(): ChargingStationService
    {
        return new ChargingStationService(
            $this->connection,
            new ChargingStationRepository($this->connection),
            new LocationRepository($this->connection),
            new UserRepository($this->connection)
        );
    }
}