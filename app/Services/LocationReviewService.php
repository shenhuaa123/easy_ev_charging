<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Validator;
use App\Models\LocationReview;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;
use mysqli;
use RuntimeException;
use Throwable;

class LocationReviewService
{
    private mysqli $connection;
    private LocationReviewRepository $reviewRepository;
    private LocationRepository $locationRepository;
    private UserRepository $userRepository;
    private AdminAuthorizationService $adminAuthorizationService;
    private ?AdminOperationLogService $adminOperationLogService;

    public function __construct(
        mysqli $connection,
        LocationReviewRepository $reviewRepository,
        LocationRepository $locationRepository,
        UserRepository $userRepository,
        ?AdminOperationLogService $adminOperationLogService = null
    ){
        $this->connection = $connection;
        $this->reviewRepository = $reviewRepository;
        $this->locationRepository = $locationRepository;
        $this->userRepository = $userRepository;
        $this->adminAuthorizationService = new AdminAuthorizationService(
            $userRepository
        );
        $this->adminOperationLogService = $adminOperationLogService;
    }

    /**
     * 获取用户对某个站点的评价上下文。
     *
     * 后续用户端评价页面会用它判断：
     * - 站点是否存在；
     * - 用户是否已经评价过；
     * - 用户是否有可评价订单。
     */
    public function getUserReviewContext(int $userId, int $locationId): array
    {
        if($userId <= 0){
            return [
                'success' => false,
                'message' => '用户编号不合法。',
                'location' => null,
                'review' => null,
                'can_review' => false,
                'reviewable_record_id' => null,
            ];
        }

        if($locationId <= 0){
            return [
                'success' => false,
                'message' => '充电站点编号不合法。',
                'location' => null,
                'review' => null,
                'can_review' => false,
                'reviewable_record_id' => null,
            ];
        }

        $user = $this->userRepository->findById($userId);

        if($user === null){
            return [
                'success' => false,
                'message' => '当前用户不存在。',
                'location' => null,
                'review' => null,
                'can_review' => false,
                'reviewable_record_id' => null,
            ];
        }

        if(!$user->isActive()){
            return [
                'success' => false,
                'message' => '当前账户已被停用，不能评价站点。',
                'location' => null,
                'review' => null,
                'can_review' => false,
                'reviewable_record_id' => null,
            ];
        }

        $location = $this->locationRepository->findById($locationId);

        if($location === null){
            return [
                'success' => false,
                'message' => '未找到指定的充电站点。',
                'location' => null,
                'review' => null,
                'can_review' => false,
                'reviewable_record_id' => null,
            ];
        }

        $review = $this->reviewRepository->findByUserAndLocation(
            $userId,
            $locationId
        );

        $reviewableRecordId = $this->reviewRepository
            ->findLatestReviewableRecordId($userId, $locationId);

        return [
            'success' => true,
            'message' => $reviewableRecordId === null
                ? '您需要在该站点完成过充电后才能评价。'
                : '',
            'location' => $location,
            'review' => $review,
            'can_review' => $reviewableRecordId !== null,
            'reviewable_record_id' => $reviewableRecordId,
        ];
    }

    /**
     * 用户提交或修改站点评价。
     */
    public function saveUserReview(int $userId, int $locationId, array $data): array
    {
        if($userId <= 0){
            return [
                'success' => false,
                'message' => '用户编号不合法。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        if($locationId <= 0){
            return [
                'success' => false,
                'message' => '充电站点编号不合法。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        $user = $this->userRepository->findById($userId);

        if($user === null){
            return [
                'success' => false,
                'message' => '当前用户不存在。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        if(!$user->isActive()){
            return [
                'success' => false,
                'message' => '当前账户已被停用，不能评价站点。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        $location = $this->locationRepository->findById($locationId);

        if($location === null){
            return [
                'success' => false,
                'message' => '未找到指定的充电站点。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        $rating = (int)($data['rating'] ?? 0);
        $content = trim((string)($data['content'] ?? ''));

        $validator = new Validator();

        if(
            $rating < LocationReview::MIN_RATING
            || $rating > LocationReview::MAX_RATING
        ){
            $validator->addError(
                'rating',
                '请选择1到5星之间的星级评分。'
            );
        }

        $validator->lengthBetween(
            'content',
            $content,
            '评价内容',
            1,
            1000
        );

        if($validator->hasErrors()){
            return [
                'success' => false,
                'errors' => $validator->getErrors(),
                'review_id' => null,
            ];
        }

        $reviewableRecordId = $this->reviewRepository
            ->findLatestReviewableRecordId($userId, $locationId);

        if($reviewableRecordId === null){
            return [
                'success' => false,
                'message' => '您需要在该站点存在已完成或异常结束的充电订单后才能评价。',
                'errors' => [],
                'review_id' => null,
            ];
        }

        if(!$this->connection->begin_transaction()){
            throw new RuntimeException('站点评价保存事务启动失败。');
        }

        try{
            $existingReview = $this->reviewRepository->findByUserAndLocation(
                $userId,
                $locationId
            );

            if($existingReview === null){
                $now = date('Y-m-d H:i:s');

                $review = new LocationReview(
                    null,
                    $userId,
                    $locationId,
                    $reviewableRecordId,
                    $rating,
                    $content,
                    null,
                    null,
                    null,
                    LocationReview::STATUS_VISIBLE,
                    $now,
                    $now
                );

                $reviewId = $this->reviewRepository->create($review);

                if(!$this->connection->commit()){
                    throw new RuntimeException('站点评价新增事务提交失败。');
                }

                return [
                    'success' => true,
                    'message' => '站点评价提交成功。',
                    'errors' => [],
                    'review_id' => $reviewId,
                    'is_update' => false,
                ];
            }

            $reviewId = $existingReview->getLocationReviewId();

            if($reviewId === null){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '评价数据异常，无法修改。',
                    'errors' => [],
                    'review_id' => null,
                ];
            }

            $wasUpdated = $this->reviewRepository->updateUserReview(
                $reviewId,
                $userId,
                $locationId,
                $reviewableRecordId,
                $rating,
                $content
            );

            if(!$wasUpdated){
                $this->connection->rollback();

                return [
                    'success' => false,
                    'message' => '评价未能保存，请稍后重试。',
                    'errors' => [],
                    'review_id' => null,
                ];
            }

            if(!$this->connection->commit()){
                throw new RuntimeException('站点评价修改事务提交失败。');
            }

            return [
                'success' => true,
                'message' => $existingReview->isHidden()
                    ? '站点评价修改成功。该评价当前仍处于隐藏状态。'
                    : '站点评价修改成功。',
                'errors' => [],
                'review_id' => $reviewId,
                'is_update' => true,
            ];
        }catch(Throwable $exception){
            $this->connection->rollback();

            Logger::exception($exception, '用户站点评价保存流程异常。', [
                'user_id' => $userId,
                'location_id' => $locationId,
            ]);

            throw $exception;
        }
    }

    /**
     * 管理员回复站点评价。
     */
    public function replyAsAdmin(
        int $operatorUserId,
        int $locationReviewId,
        array $data
    ): array {
        $authorizationError = $this->adminAuthorizationService->validate(
            $operatorUserId
        );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
                'errors' => [],
            ];
        }

        if($locationReviewId <= 0){
            return [
                'success' => false,
                'message' => '评价编号不合法。',
                'errors' => [],
            ];
        }

        $review = $this->reviewRepository->findById($locationReviewId);

        if($review === null){
            return [
                'success' => false,
                'message' => '未找到指定的站点评价。',
                'errors' => [],
            ];
        }

        $adminReply = trim((string)($data['admin_reply'] ?? ''));

        $validator = new Validator();

        $validator->lengthBetween(
            'admin_reply',
            $adminReply,
            '管理员回复',
            1,
            1000
        );

        if($validator->hasErrors()){
            return [
                'success' => false,
                'errors' => $validator->getErrors(),
            ];
        }

        try{
            $wasUpdated = $this->reviewRepository->updateAdminReply(
                $locationReviewId,
                $adminReply,
                $operatorUserId
            );
        }catch(Throwable $exception){
            Logger::exception($exception, '管理员回复站点评价流程异常。', [
                'operator_user_id' => $operatorUserId,
                'location_review_id' => $locationReviewId,
            ]);

            throw new RuntimeException(
                '管理员回复站点评价失败。',
                0,
                $exception
            );
        }

        if(!$wasUpdated){
            Logger::error('管理员回复站点评价未影响任何记录。', [
                'operator_user_id' => $operatorUserId,
                'location_review_id' => $locationReviewId,
            ]);

            return [
                'success' => false,
                'message' => '管理员回复未能保存，请稍后重试。',
                'errors' => [],
            ];
        }

        $this->recordAdminLogSafely(
            $operatorUserId,
            'location_review_reply',
            $locationReviewId,
            '回复站点评价；评价编号：' . $locationReviewId . '。'
        );

        return [
            'success' => true,
            'message' => '管理员回复保存成功。',
            'errors' => [],
        ];
    }

    /**
     * 管理员修改评价状态。
     */
    public function updateStatusAsAdmin(
        int $operatorUserId,
        int $locationReviewId,
        string $newStatus
    ): array {
        $authorizationError = $this->adminAuthorizationService->validate(
            $operatorUserId
        );

        if($authorizationError !== null){
            return [
                'success' => false,
                'message' => $authorizationError,
            ];
        }

        if($locationReviewId <= 0){
            return [
                'success' => false,
                'message' => '评价编号不合法。',
            ];
        }

        $newStatus = trim($newStatus);

        if(!array_key_exists($newStatus, LocationReview::getStatusOptions())){
            return [
                'success' => false,
                'message' => '评价状态不合法。',
            ];
        }

        $review = $this->reviewRepository->findById($locationReviewId);

        if($review === null){
            return [
                'success' => false,
                'message' => '未找到指定的站点评价。',
            ];
        }

        if($review->getStatus() === $newStatus){
            return [
                'success' => false,
                'message' => '评价状态没有发生变化。',
            ];
        }

        try{
            $wasUpdated = $this->reviewRepository->updateStatus(
                $locationReviewId,
                $newStatus
            );
        }catch(Throwable $exception){
            Logger::exception($exception, '管理员修改站点评价状态流程异常。', [
                'operator_user_id' => $operatorUserId,
                'location_review_id' => $locationReviewId,
                'new_status' => $newStatus,
            ]);

            throw new RuntimeException(
                '修改站点评价状态失败。',
                0,
                $exception
            );
        }

        if(!$wasUpdated){
            Logger::error('管理员修改站点评价状态未影响任何记录。', [
                'operator_user_id' => $operatorUserId,
                'location_review_id' => $locationReviewId,
                'new_status' => $newStatus,
            ]);

            return [
                'success' => false,
                'message' => '评价状态未能更新，请稍后重试。',
            ];
        }

        $this->recordAdminLogSafely(
            $operatorUserId,
            'location_review_status_update',
            $locationReviewId,
            '修改站点评价状态；评价编号：'
            . $locationReviewId
            . '；新状态：'
            . LocationReview::getStatusOptions()[$newStatus]
            . '。'
        );

        return [
            'success' => true,
            'message' => $newStatus === LocationReview::STATUS_VISIBLE
                ? '站点评价已恢复公开显示。'
                : '站点评价已隐藏。',
        ];
    }

    /**
     * 管理员日志写入失败时，不阻断主业务。
     */
    private function recordAdminLogSafely(
        int $operatorUserId,
        string $action,
        int $targetId,
        string $detail
    ): void {
        if($this->adminOperationLogService === null){
            return;
        }

        try{
            $this->adminOperationLogService->recordCurrentRequest(
                $operatorUserId,
                $action,
                'location_review',
                $targetId,
                'success',
                $detail
            );
        }catch(Throwable $exception){
            Logger::error('站点评价审计日志写入失败。', [
                'exception' => $exception,
                'operator_user_id' => $operatorUserId,
                'action' => $action,
                'target_id' => $targetId,
            ]);
        }
    }
}