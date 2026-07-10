<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocationReview;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use RuntimeException;
use Tests\DatabaseTestCase;

final class LocationSearchRepositoryTest extends DatabaseTestCase
{
    public function testRatingRangeSupportsMinimumOnly(): void
    {
        $locationRepository = new LocationRepository($this->connection);

        $locationId1 = $this->createTestLocation(101);
        $locationId2 = $this->createTestLocation(102);
        $locationId3 = $this->createTestLocation(103);
        $locationId4 = $this->createTestLocation(104);

        $stationId1 = $this->createTestStation(101, $locationId1);
        $stationId2 = $this->createTestStation(102, $locationId2);
        $stationId3 = $this->createTestStation(103, $locationId3);

        $this->createVisibleReview(101, $locationId1, $stationId1, 2);
        $this->createVisibleReview(102, $locationId2, $stationId2, 3);
        $this->createVisibleReview(103, $locationId3, $stationId3, 5);

        $filters = [
            'rating_min' => 3,
            'rating_max' => null,
        ];

        $total = $locationRepository->countActiveList($filters);
        $items = $locationRepository->findActiveListItems(
            20,
            0,
            $filters
        );

        $this->assertSame(2, $total);
        $this->assertCount(2, $items);

        $locationIds = $this->extractLocationIds($items);

        $this->assertTrue(in_array($locationId2, $locationIds, true));
        $this->assertTrue(in_array($locationId3, $locationIds, true));
        $this->assertFalse(in_array($locationId1, $locationIds, true));
        $this->assertFalse(in_array($locationId4, $locationIds, true));
    }

    public function testRatingRangeSupportsMaximumOnly(): void
    {
        $locationRepository = new LocationRepository($this->connection);

        $locationId1 = $this->createTestLocation(111);
        $locationId2 = $this->createTestLocation(112);
        $locationId3 = $this->createTestLocation(113);

        $stationId1 = $this->createTestStation(111, $locationId1);
        $stationId2 = $this->createTestStation(112, $locationId2);
        $stationId3 = $this->createTestStation(113, $locationId3);

        $this->createVisibleReview(111, $locationId1, $stationId1, 2);
        $this->createVisibleReview(112, $locationId2, $stationId2, 3);
        $this->createVisibleReview(113, $locationId3, $stationId3, 5);

        $filters = [
            'rating_min' => null,
            'rating_max' => 3,
        ];

        $items = $locationRepository->findActiveListItems(
            20,
            0,
            $filters
        );

        $this->assertCount(2, $items);

        $locationIds = $this->extractLocationIds($items);

        $this->assertTrue(in_array($locationId1, $locationIds, true));
        $this->assertTrue(in_array($locationId2, $locationIds, true));
        $this->assertFalse(in_array($locationId3, $locationIds, true));
    }

    public function testRatingRangeFiltersBetweenTwoValues(): void
    {
        $locationRepository = new LocationRepository($this->connection);

        $locationId1 = $this->createTestLocation(121);
        $locationId2 = $this->createTestLocation(122);
        $locationId3 = $this->createTestLocation(123);

        $stationId1 = $this->createTestStation(121, $locationId1);
        $stationId2 = $this->createTestStation(122, $locationId2);
        $stationId3 = $this->createTestStation(123, $locationId3);

        $this->createVisibleReview(121, $locationId1, $stationId1, 1);
        $this->createVisibleReview(122, $locationId2, $stationId2, 3);
        $this->createVisibleReview(123, $locationId3, $stationId3, 5);

        $items = $locationRepository->findActiveListItems(
            20,
            0,
            [
                'rating_min' => 2,
                'rating_max' => 4,
            ]
        );

        $this->assertCount(1, $items);
        $this->assertSame(
            $locationId2,
            $items[0]['location']->getLocationId()
        );
    }

    public function testRatingRangeAutomaticallySwapsReversedValues(): void
    {
        $locationRepository = new LocationRepository($this->connection);

        $locationId1 = $this->createTestLocation(131);
        $locationId2 = $this->createTestLocation(132);
        $locationId3 = $this->createTestLocation(133);

        $stationId1 = $this->createTestStation(131, $locationId1);
        $stationId2 = $this->createTestStation(132, $locationId2);
        $stationId3 = $this->createTestStation(133, $locationId3);

        $this->createVisibleReview(131, $locationId1, $stationId1, 1);
        $this->createVisibleReview(132, $locationId2, $stationId2, 3);
        $this->createVisibleReview(133, $locationId3, $stationId3, 5);

        $items = $locationRepository->findActiveListItems(
            20,
            0,
            [
                'rating_min' => 4,
                'rating_max' => 2,
            ]
        );

        $this->assertCount(1, $items);
        $this->assertSame(
            $locationId2,
            $items[0]['location']->getLocationId()
        );
    }

    public function testChargerTypeFilterReturnsMatchingStations(): void
    {
        $stationRepository = new ChargingStationRepository(
            $this->connection
        );

        $locationId = $this->createTestLocation(141);

        $this->createTestStation(
            141,
            $locationId,
            'active',
            'ac'
        );

        $this->createTestStation(
            142,
            $locationId,
            'active',
            'dc'
        );

        $this->createTestStation(
            143,
            $locationId,
            'maintenance',
            'ac'
        );

        $acItems = $stationRepository
            ->findAvailabilityItemsByLocationId($locationId, 'ac');

        $dcItems = $stationRepository
            ->findAvailabilityItemsByLocationId($locationId, 'dc');

        $allItems = $stationRepository
            ->findAvailabilityItemsByLocationId($locationId);

        $this->assertCount(2, $acItems);
        $this->assertCount(1, $dcItems);
        $this->assertCount(3, $allItems);

        foreach($acItems as $item){
            $this->assertSame(
                'ac',
                $item['station']->getChargerType()
            );
        }

        $this->assertSame(
            'dc',
            $dcItems[0]['station']->getChargerType()
        );
    }

    public function testInvalidChargerTypeFallsBackToAllStations(): void
    {
        $stationRepository = new ChargingStationRepository(
            $this->connection
        );

        $locationId = $this->createTestLocation(151);

        $this->createTestStation(
            151,
            $locationId,
            'active',
            'ac'
        );

        $this->createTestStation(
            152,
            $locationId,
            'active',
            'dc'
        );

        $items = $stationRepository
            ->findAvailabilityItemsByLocationId(
                $locationId,
                'invalid'
            );

        $this->assertCount(2, $items);
    }

    private function createVisibleReview(
        int $index,
        int $locationId,
        int $stationId,
        int $rating
    ): void {
        $userId = $this->createTestUser(500 + $index);
        $now = date('Y-m-d H:i:s');

        $statement = $this->connection->prepare(
            '
                INSERT INTO charge_records (
                    order_number,
                    user_id,
                    station_id,
                    check_in_at,
                    check_out_at,
                    hourly_rate_snapshot,
                    billable_minutes,
                    total_cost,
                    status,
                    remark,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            '
        );

        if($statement === false){
            throw new RuntimeException(
                '测试订单新增SQL预处理失败。'
            );
        }

        $orderNumber = sprintf('TEST-REVIEW-%04d', $index);
        $hourlyRate = '10.00';
        $billableMinutes = 60;
        $totalCost = '10.00';
        $status = 'completed';
        $remark = null;

        $statement->bind_param(
            'siisssidssss',
            $orderNumber,
            $userId,
            $stationId,
            $now,
            $now,
            $hourlyRate,
            $billableMinutes,
            $totalCost,
            $status,
            $remark,
            $now,
            $now
        );

        if(!$statement->execute()){
            $error = $statement->error;
            $statement->close();

            throw new RuntimeException(
                '测试订单新增失败：' . $error
            );
        }

        $chargeRecordId = (int)$statement->insert_id;
        $statement->close();

        $review = new LocationReview(
            null,
            $userId,
            $locationId,
            $chargeRecordId,
            $rating,
            '评分范围筛选测试评价',
            null,
            null,
            null,
            LocationReview::STATUS_VISIBLE,
            $now,
            $now
        );

        $reviewRepository = new LocationReviewRepository(
            $this->connection
        );

        $reviewRepository->create($review);
    }

    /**
     * @param array<int, array{
     *     location: \App\Models\Location,
     *     available_station_count: int
     * }> $items
     *
     * @return int[]
     */
    private function extractLocationIds(array $items): array
    {
        $locationIds = [];

        foreach($items as $item){
            $locationId = $item['location']->getLocationId();

            if($locationId !== null){
                $locationIds[] = $locationId;
            }
        }

        return $locationIds;
    }
}