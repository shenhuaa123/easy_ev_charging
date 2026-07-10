<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;
use App\Services\LocationReviewService;
use Tests\DatabaseTestCase;

final class LocationReviewServiceTest extends DatabaseTestCase
{
    public function testSaveUserReviewRejectsUserWithoutCompletedOrder(): void
    {
        $userId = $this->createTestUser(1);
        $locationId = $this->createTestLocation(1);
        $service = $this->createLocationReviewService();

        $result = $service->saveUserReview($userId, $locationId, [
            'rating' => 5,
            'content' => '测试评价内容',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(
            '您需要在该站点存在已完成或异常结束的充电订单后才能评价。',
            $result['message']
        );
        $this->assertNull($result['review_id']);
    }

    public function testUserReviewContextAllowsReviewAfterCompletedOrder(): void
    {
        $userId = $this->createTestUser(2);
        $locationId = $this->createTestLocation(2);
        $chargeRecordId = $this->createCompletedChargeRecord(
            2,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $context = $service->getUserReviewContext(
            $userId,
            $locationId
        );

        $this->assertTrue($context['success']);
        $this->assertTrue($context['can_review']);
        $this->assertSame(
            $chargeRecordId,
            $context['reviewable_record_id']
        );
        $this->assertNotNull($context['location']);
        $this->assertNull($context['review']);
    }

    public function testSaveUserReviewCreatesVisibleReview(): void
    {
        $userId = $this->createTestUser(3);
        $locationId = $this->createTestLocation(3);

        $chargeRecordId = $this->createCompletedChargeRecord(
            3,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $result = $service->saveUserReview($userId, $locationId, [
            'rating' => 5,
            'content' => '充电体验很好。',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_update']);
        $this->assertNotNull($result['review_id']);

        $repository = new LocationReviewRepository(
            $this->connection
        );

        $review = $repository->findById(
            (int)$result['review_id']
        );

        $this->assertNotNull($review);
        $this->assertSame($userId, $review->getUserId());
        $this->assertSame(
            $locationId,
            $review->getLocationId()
        );
        $this->assertSame(
            $chargeRecordId,
            $review->getChargeRecordId()
        );
        $this->assertSame(5, $review->getRating());
        $this->assertSame(
            '充电体验很好。',
            $review->getContent()
        );
        $this->assertSame(
            'visible',
            $review->getStatus()
        );
    }

    public function testSaveUserReviewUpdatesExistingReviewInsteadOfCreatingAnother(): void
    {
        $userId = $this->createTestUser(4);
        $locationId = $this->createTestLocation(4);

        $this->createCompletedChargeRecord(
            4,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $createResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 4,
                'content' => '第一次评价。',
            ]
        );

        $this->assertTrue($createResult['success']);

        $updateResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 2,
                'content' => '修改后的评价。',
            ]
        );

        $this->assertTrue($updateResult['success']);
        $this->assertTrue($updateResult['is_update']);
        $this->assertSame(
            $createResult['review_id'],
            $updateResult['review_id']
        );

        $repository = new LocationReviewRepository(
            $this->connection
        );

        $review = $repository->findByUserAndLocation(
            $userId,
            $locationId
        );

        $this->assertNotNull($review);
        $this->assertSame(2, $review->getRating());
        $this->assertSame(
            '修改后的评价。',
            $review->getContent()
        );
    }

    public function testHiddenReviewRemainsHiddenAfterUserUpdatesIt(): void
    {
        $userId = $this->createTestUser(5);
        $adminId = $this->createTestUser(
            6,
            'admin',
            'active'
        );

        $locationId = $this->createTestLocation(5);

        $this->createCompletedChargeRecord(
            5,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $createResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 3,
                'content' => '原评价内容。',
            ]
        );

        $reviewId = (int)$createResult['review_id'];

        $hideResult = $service->updateStatusAsAdmin(
            $adminId,
            $reviewId,
            'hidden'
        );

        $this->assertTrue($hideResult['success']);

        $updateResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 4,
                'content' => '隐藏后修改的内容。',
            ]
        );

        $this->assertTrue($updateResult['success']);
        $this->assertTrue($updateResult['is_update']);

        $this->assertSame(
            '站点评价修改成功。该评价当前仍处于隐藏状态。',
            $updateResult['message']
        );

        $repository = new LocationReviewRepository(
            $this->connection
        );

        $review = $repository->findById($reviewId);

        $this->assertNotNull($review);
        $this->assertSame(
            'hidden',
            $review->getStatus()
        );
        $this->assertSame(4, $review->getRating());
        $this->assertSame(
            '隐藏后修改的内容。',
            $review->getContent()
        );
    }

    public function testSaveUserReviewRejectsInvalidRatingAndBlankContent(): void
    {
        $userId = $this->createTestUser(7);
        $locationId = $this->createTestLocation(7);
        $service = $this->createLocationReviewService();

        $result = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 0,
                'content' => '   ',
            ]
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey(
            'rating',
            $result['errors']
        );
        $this->assertArrayHasKey(
            'content',
            $result['errors']
        );
        $this->assertNull($result['review_id']);
    }

    public function testAdminCanReplyToReview(): void
    {
        $userId = $this->createTestUser(8);
        $adminId = $this->createTestUser(
            9,
            'admin',
            'active'
        );

        $locationId = $this->createTestLocation(8);

        $this->createCompletedChargeRecord(
            8,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $createResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 5,
                'content' => '希望以后继续保持。',
            ]
        );

        $reviewId = (int)$createResult['review_id'];

        $replyResult = $service->replyAsAdmin(
            $adminId,
            $reviewId,
            [
                'admin_reply' => '感谢您的评价。',
            ]
        );

        $this->assertTrue($replyResult['success']);

        $repository = new LocationReviewRepository(
            $this->connection
        );

        $review = $repository->findById($reviewId);

        $this->assertNotNull($review);
        $this->assertSame(
            '感谢您的评价。',
            $review->getAdminReply()
        );
        $this->assertSame(
            $adminId,
            $review->getReplyAdminUserId()
        );
        $this->assertNotNull(
            $review->getRepliedAt()
        );
    }

    public function testAdminCanHideAndRestoreReview(): void
    {
        $userId = $this->createTestUser(10);
        $adminId = $this->createTestUser(
            11,
            'admin',
            'active'
        );

        $locationId = $this->createTestLocation(10);

        $this->createCompletedChargeRecord(
            10,
            $userId,
            $locationId
        );

        $service = $this->createLocationReviewService();

        $createResult = $service->saveUserReview(
            $userId,
            $locationId,
            [
                'rating' => 1,
                'content' => '测试状态修改。',
            ]
        );

        $reviewId = (int)$createResult['review_id'];

        $repository = new LocationReviewRepository(
            $this->connection
        );

        $hideResult = $service->updateStatusAsAdmin(
            $adminId,
            $reviewId,
            'hidden'
        );

        $this->assertTrue($hideResult['success']);

        $hiddenReview = $repository->findById(
            $reviewId
        );

        $this->assertNotNull($hiddenReview);
        $this->assertSame(
            'hidden',
            $hiddenReview->getStatus()
        );

        $restoreResult = $service->updateStatusAsAdmin(
            $adminId,
            $reviewId,
            'visible'
        );

        $this->assertTrue($restoreResult['success']);

        $visibleReview = $repository->findById(
            $reviewId
        );

        $this->assertNotNull($visibleReview);
        $this->assertSame(
            'visible',
            $visibleReview->getStatus()
        );
    }

    private function createLocationReviewService(): LocationReviewService
    {
        return new LocationReviewService(
            $this->connection,
            new LocationReviewRepository($this->connection),
            new LocationRepository($this->connection),
            new UserRepository($this->connection)
        );
    }

    private function createCompletedChargeRecord(
        int $index,
        int $userId,
        int $locationId
    ): int {
        $stationId = $this->createTestStation(
            $index,
            $locationId
        );

        $service = $this->createChargeRecordService();

        $startResult = $service->startCharging(
            $userId,
            $stationId
        );

        $this->assertTrue($startResult['success']);

        $chargeRecordId = (int)$startResult[
            'charge_record_id'
        ];

        $finishResult = $service->finishCharging(
            $userId,
            $chargeRecordId
        );

        $this->assertTrue($finishResult['success']);

        return $chargeRecordId;
    }
}