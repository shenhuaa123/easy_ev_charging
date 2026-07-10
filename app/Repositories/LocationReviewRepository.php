<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LocationReview;
use mysqli;
use RuntimeException;

class LocationReviewRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 根据评价编号查询评价。
     */
    public function findById(int $locationReviewId): ?LocationReview
    {
        if($locationReviewId <= 0){
            return null;
        }

        $sql = '
            SELECT
                location_review_id,
                user_id,
                location_id,
                charge_record_id,
                rating,
                content,
                admin_reply,
                reply_admin_user_id,
                replied_at,
                status,
                created_at,
                updated_at
            FROM location_reviews
            WHERE location_review_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点评价主键查询SQL预处理失败。');
        }

        $statement->bind_param('i', $locationReviewId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('根据主键查询站点评价失败。');
        }

        $result = $statement->get_result();
        $reviewData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($reviewData === null){
            return null;
        }

        return $this->mapToLocationReview($reviewData);
    }

    /**
     * 查询指定用户对指定站点的评价。
     */
    public function findByUserAndLocation(int $userId, int $locationId): ?LocationReview
    {
        if($userId <= 0 || $locationId <= 0){
            return null;
        }

        $sql = '
            SELECT
                location_review_id,
                user_id,
                location_id,
                charge_record_id,
                rating,
                content,
                admin_reply,
                reply_admin_user_id,
                replied_at,
                status,
                created_at,
                updated_at
            FROM location_reviews
            WHERE user_id = ?
            AND location_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户站点评价查询SQL预处理失败。');
        }

        $statement->bind_param('ii', $userId, $locationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询用户站点评价失败。');
        }

        $result = $statement->get_result();
        $reviewData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($reviewData === null){
            return null;
        }

        return $this->mapToLocationReview($reviewData);
    }

    /**
     * 查询用户在指定站点最近一笔可用于评价的已结束订单。
     */
    public function findLatestReviewableRecordId(int $userId, int $locationId): ?int
    {
        if($userId <= 0 || $locationId <= 0){
            return null;
        }

        $sql = '
            SELECT
                cr.charge_record_id
            FROM charge_records AS cr

            INNER JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id

            WHERE cr.user_id = ?
            AND cs.location_id = ?
            AND cr.status IN (?, ?)
            AND cr.check_out_at IS NOT NULL

            ORDER BY
                cr.check_out_at DESC,
                cr.charge_record_id DESC

            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('可评价订单查询SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'iiss',
            $userId,
            $locationId,
            $completedStatus,
            $abnormalStatus
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询可评价订单失败。');
        }

        $result = $statement->get_result();
        $recordData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($recordData === null){
            return null;
        }

        return (int)$recordData['charge_record_id'];
    }

    /**
     * 新增站点评价。
     */
    public function create(LocationReview $review): int
    {
        $sql = '
            INSERT INTO location_reviews (
                user_id,
                location_id,
                charge_record_id,
                rating,
                content,
                status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点评价新增SQL预处理失败。');
        }

        $userId = $review->getUserId();
        $locationId = $review->getLocationId();
        $chargeRecordId = $review->getChargeRecordId();
        $rating = $review->getRating();
        $content = $review->getContent();
        $status = $review->getStatus();
        $createdAt = $review->getCreatedAt();
        $updatedAt = $review->getUpdatedAt();

        $statement->bind_param(
            'iiiissss',
            $userId,
            $locationId,
            $chargeRecordId,
            $rating,
            $content,
            $status,
            $createdAt,
            $updatedAt
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('新增站点评价失败。');
        }

        $newId = (int)$statement->insert_id;
        $statement->close();

        return $newId;
    }

    /**
     * 用户修改自己的站点评价。
     */
    public function updateUserReview(
        int $locationReviewId,
        int $userId,
        int $locationId,
        int $chargeRecordId,
        int $rating,
        string $content
    ): bool {
        if($locationReviewId <= 0){
            throw new RuntimeException('评价编号不合法。');
        }

        if($userId <= 0 || $locationId <= 0){
            throw new RuntimeException('评价归属参数不合法。');
        }

        $sql = '
            UPDATE location_reviews
            SET
                charge_record_id = ?,
                rating = ?,
                content = ?,
                updated_at = ?
            WHERE location_review_id = ?
              AND user_id = ?
              AND location_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户评价修改SQL预处理失败。');
        }

        $updatedAt = date('Y-m-d H:i:s');

        $statement->bind_param(
            'iissiii',
            $chargeRecordId,
            $rating,
            $content,
            $updatedAt,
            $locationReviewId,
            $userId,
            $locationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('修改用户站点评价失败。');
        }

        $affectedRows = $statement->affected_rows;
        $statement->close();

        return $affectedRows >= 0;
    }

    /**
     * 管理员回复站点评价。
     */
    public function updateAdminReply(
        int $locationReviewId,
        string $adminReply,
        int $replyAdminUserId
    ): bool {
        if($locationReviewId <= 0){
            throw new RuntimeException('评价编号不合法。');
        }

        if($replyAdminUserId <= 0){
            throw new RuntimeException('回复管理员编号不合法。');
        }

        $sql = '
            UPDATE location_reviews
            SET
                admin_reply = ?,
                reply_admin_user_id = ?,
                replied_at = ?,
                updated_at = ?
            WHERE location_review_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员评价回复SQL预处理失败。');
        }

        $now = date('Y-m-d H:i:s');

        $statement->bind_param(
            'sissi',
            $adminReply,
            $replyAdminUserId,
            $now,
            $now,
            $locationReviewId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('管理员回复站点评价失败。');
        }

        $affectedRows = $statement->affected_rows;
        $statement->close();

        return $affectedRows >= 0;
    }

    /**
     * 管理员修改评价公开状态。
     */
    public function updateStatus(int $locationReviewId, string $status): bool
    {
        if($locationReviewId <= 0){
            throw new RuntimeException('评价编号不合法。');
        }

        $sql = '
            UPDATE location_reviews
            SET
                status = ?,
                updated_at = ?
            WHERE location_review_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('评价状态修改SQL预处理失败。');
        }

        $updatedAt = date('Y-m-d H:i:s');

        $statement->bind_param(
            'ssi',
            $status,
            $updatedAt,
            $locationReviewId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('修改站点评价状态失败。');
        }

        $affectedRows = $statement->affected_rows;
        $statement->close();

        return $affectedRows >= 0;
    }

    /**
     * 查询指定站点公开评价数量。
     */
    public function countVisibleByLocation(int $locationId): int
    {
        if($locationId <= 0){
            return 0;
        }

        $sql = '
            SELECT COUNT(*) AS total_count
            FROM location_reviews
            WHERE location_id = ?
            AND status = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点公开评价数量统计SQL预处理失败。');
        }

        $visibleStatus = LocationReview::STATUS_VISIBLE;

        $statement->bind_param('is', $locationId, $visibleStatus);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计站点公开评价数量失败。');
        }

        $result = $statement->get_result();
        $countData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return (int)($countData['total_count'] ?? 0);
    }

    /**
     * 查询指定站点公开评价列表。
     *
     * @return array<int, array{
     *     review: LocationReview,
     *     user: ?array,
     *     reply_admin: ?array
     * }>
     */
    public function findVisibleListByLocation(
        int $locationId,
        int $limit,
        int $offset
    ): array {
        if($locationId <= 0){
            throw new RuntimeException('站点编号必须大于0。');
        }

        if($limit <= 0){
            throw new RuntimeException('每页评价数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('评价分页偏移量不能小于0。');
        }

        $sql = '
            SELECT
                lr.location_review_id,
                lr.user_id,
                lr.location_id,
                lr.charge_record_id,
                lr.rating,
                lr.content,
                lr.admin_reply,
                lr.reply_admin_user_id,
                lr.replied_at,
                lr.status,
                lr.created_at,
                lr.updated_at,

                u.username AS related_username,
                u.real_name AS related_real_name,

                au.username AS related_admin_username,
                au.real_name AS related_admin_real_name

            FROM location_reviews AS lr

            LEFT JOIN users AS u
                ON u.user_id = lr.user_id

            LEFT JOIN users AS au
                ON au.user_id = lr.reply_admin_user_id

            WHERE lr.location_id = ?
            AND lr.status = ?

            ORDER BY
                lr.created_at DESC,
                lr.location_review_id DESC

            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点公开评价列表查询SQL预处理失败。');
        }

        $visibleStatus = LocationReview::STATUS_VISIBLE;

        $statement->bind_param(
            'isii',
            $locationId,
            $visibleStatus,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询站点公开评价列表失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($reviewData = $result->fetch_assoc()){
            $items[] = $this->mapToPublicItem($reviewData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 批量查询多个站点的公开评分摘要。
     *
     * @param array<int, int> $locationIds
     * @return array<int, array{
     *     review_count: int,
     *     average_rating: float
     * }>
     */
    public function getRatingSummariesByLocationIds(array $locationIds): array
    {
        $normalizedLocationIds = [];

        foreach($locationIds as $locationId){
            $locationId = (int)$locationId;

            if($locationId > 0){
                $normalizedLocationIds[$locationId] = $locationId;
            }
        }

        $normalizedLocationIds = array_values($normalizedLocationIds);

        if($normalizedLocationIds === []){
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($normalizedLocationIds), '?')
        );

        $sql = '
            SELECT
                location_id,
                COUNT(*) AS review_count,
                COALESCE(AVG(rating), 0) AS average_rating
            FROM location_reviews
            WHERE status = ?
            AND location_id IN (' . $placeholders . ')
            GROUP BY location_id
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点评分批量摘要查询SQL预处理失败。');
        }

        $visibleStatus = LocationReview::STATUS_VISIBLE;
        $types = 's' . str_repeat('i', count($normalizedLocationIds));
        $bindValues = array_merge(
            [$types, $visibleStatus],
            $normalizedLocationIds
        );

        $bindReferences = [];

        foreach($bindValues as $index => $bindValue){
            $bindReferences[$index] = &$bindValues[$index];
        }

        call_user_func_array(
            [$statement, 'bind_param'],
            $bindReferences
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('批量查询站点评分摘要失败。');
        }

        $result = $statement->get_result();
        $summaries = [];

        while($summaryData = $result->fetch_assoc()){
            $locationId = (int)$summaryData['location_id'];

            $summaries[$locationId] = [
                'review_count' => (int)($summaryData['review_count'] ?? 0),
                'average_rating' => round(
                    (float)($summaryData['average_rating'] ?? 0),
                    2
                ),
            ];
        }

        $result->free();
        $statement->close();

        return $summaries;
    }

    /**
     * 查询指定站点评分汇总。
     */
    public function getLocationRatingSummary(int $locationId): array
    {
        if($locationId <= 0){
            return [
                'review_count' => 0,
                'average_rating' => 0.0,
                'rating_5_count' => 0,
                'rating_4_count' => 0,
                'rating_3_count' => 0,
                'rating_2_count' => 0,
                'rating_1_count' => 0,
            ];
        }

        $sql = '
            SELECT
                COUNT(*) AS review_count,
                COALESCE(AVG(rating), 0) AS average_rating,
                COALESCE(SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END), 0) AS rating_5_count,
                COALESCE(SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END), 0) AS rating_4_count,
                COALESCE(SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END), 0) AS rating_3_count,
                COALESCE(SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END), 0) AS rating_2_count,
                COALESCE(SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END), 0) AS rating_1_count
            FROM location_reviews
            WHERE location_id = ?
            AND status = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点评分汇总查询SQL预处理失败。');
        }

        $visibleStatus = LocationReview::STATUS_VISIBLE;

        $statement->bind_param('is', $locationId, $visibleStatus);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询站点评分汇总失败。');
        }

        $result = $statement->get_result();
        $summaryData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return [
            'review_count' => (int)($summaryData['review_count'] ?? 0),
            'average_rating' => round((float)($summaryData['average_rating'] ?? 0), 2),
            'rating_5_count' => (int)($summaryData['rating_5_count'] ?? 0),
            'rating_4_count' => (int)($summaryData['rating_4_count'] ?? 0),
            'rating_3_count' => (int)($summaryData['rating_3_count'] ?? 0),
            'rating_2_count' => (int)($summaryData['rating_2_count'] ?? 0),
            'rating_1_count' => (int)($summaryData['rating_1_count'] ?? 0),
        ];
    }

    /**
     * 管理员端统计评价列表数量。
     */
    public function countListItems(array $filters = []): int
    {
        $filterValues = $this->normalizeListFilters($filters);

        $sql = '
            SELECT COUNT(*) AS total_count
            FROM location_reviews AS lr

            LEFT JOIN users AS u
                ON u.user_id = lr.user_id

            LEFT JOIN locations AS l
                ON l.location_id = lr.location_id

            WHERE (
                ? = 0
                OR lr.status = ?
            )
            AND (
                ? = 0
                OR lr.rating = ?
            )
            AND (
                ? = 0
                OR lr.location_id = ?
            )
            AND (
                ? = 0
                OR lr.content LIKE ?
                OR lr.admin_reply LIKE ?
                OR u.username LIKE ?
                OR u.real_name LIKE ?
                OR l.location_name LIKE ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('评价列表数量统计SQL预处理失败。');
        }

        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasRating = $filterValues['has_rating'];
        $rating = $filterValues['rating'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];
        $hasKeyword = $filterValues['has_keyword'];
        $keywordLike = $filterValues['keyword_like'];

        $statement->bind_param(
            'isiiiiisssss',
            $hasStatus,
            $status,
            $hasRating,
            $rating,
            $hasLocation,
            $locationId,
            $hasKeyword,
            $keywordLike,
            $keywordLike,
            $keywordLike,
            $keywordLike,
            $keywordLike
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计评价列表数量失败。');
        }

        $result = $statement->get_result();
        $countData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return (int)($countData['total_count'] ?? 0);
    }

    /**
     * 管理员端分页查询评价列表。
     *
     * @return array<int, array{
     *     review: LocationReview,
     *     user: ?array,
     *     location: ?array,
     *     reply_admin: ?array
     * }>
     */
    public function searchListItems(
        array $filters,
        int $limit,
        int $offset
    ): array {
        if($limit <= 0){
            throw new RuntimeException('每页评价数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('评价分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeListFilters($filters);

        $sql = '
            SELECT
                lr.location_review_id,
                lr.user_id,
                lr.location_id,
                lr.charge_record_id,
                lr.rating,
                lr.content,
                lr.admin_reply,
                lr.reply_admin_user_id,
                lr.replied_at,
                lr.status,
                lr.created_at,
                lr.updated_at,

                u.username AS related_username,
                u.real_name AS related_real_name,

                l.location_code AS related_location_code,
                l.location_name AS related_location_name,

                au.username AS related_admin_username,
                au.real_name AS related_admin_real_name

            FROM location_reviews AS lr

            LEFT JOIN users AS u
                ON u.user_id = lr.user_id

            LEFT JOIN locations AS l
                ON l.location_id = lr.location_id

            LEFT JOIN users AS au
                ON au.user_id = lr.reply_admin_user_id

            WHERE (
                ? = 0
                OR lr.status = ?
            )
            AND (
                ? = 0
                OR lr.rating = ?
            )
            AND (
                ? = 0
                OR lr.location_id = ?
            )
            AND (
                ? = 0
                OR lr.content LIKE ?
                OR lr.admin_reply LIKE ?
                OR u.username LIKE ?
                OR u.real_name LIKE ?
                OR l.location_name LIKE ?
            )

            ORDER BY
                lr.created_at DESC,
                lr.location_review_id DESC

            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('评价列表分页查询SQL预处理失败。');
        }

        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasRating = $filterValues['has_rating'];
        $rating = $filterValues['rating'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];
        $hasKeyword = $filterValues['has_keyword'];
        $keywordLike = $filterValues['keyword_like'];

        $statement->bind_param(
            'isiiiiisssssii',
            $hasStatus,
            $status,
            $hasRating,
            $rating,
            $hasLocation,
            $locationId,
            $hasKeyword,
            $keywordLike,
            $keywordLike,
            $keywordLike,
            $keywordLike,
            $keywordLike,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('分页查询评价列表失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($reviewData = $result->fetch_assoc()){
            $items[] = $this->mapToAdminListItem($reviewData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 整理管理员端评价筛选条件。
     */
    private function normalizeListFilters(array $filters): array
    {
        $status = trim((string)($filters['status'] ?? ''));
        $rating = (int)($filters['rating'] ?? 0);
        $locationId = (int)($filters['location_id'] ?? 0);
        $keyword = trim((string)($filters['keyword'] ?? ''));

        if(!array_key_exists($status, LocationReview::getStatusOptions())){
            $status = '';
        }

        if($rating < LocationReview::MIN_RATING || $rating > LocationReview::MAX_RATING){
            $rating = 0;
        }

        if($locationId <= 0){
            $locationId = 0;
        }

        if(mb_strlen($keyword, 'UTF-8') > 100){
            $keyword = mb_substr($keyword, 0, 100, 'UTF-8');
        }

        return [
            'has_status' => $status === '' ? 0 : 1,
            'status' => $status,
            'has_rating' => $rating <= 0 ? 0 : 1,
            'rating' => $rating,
            'has_location' => $locationId <= 0 ? 0 : 1,
            'location_id' => $locationId,
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_like' => '%' . $keyword . '%',
        ];
    }

    private function mapToPublicItem(array $reviewData): array
    {
        return [
            'review' => $this->mapToLocationReview($reviewData),
            'user' => $reviewData['related_username'] === null
                ? null
                : [
                    'username' => $reviewData['related_username'],
                    'real_name' => $reviewData['related_real_name'],
                ],
            'reply_admin' => $reviewData['related_admin_username'] === null
                ? null
                : [
                    'username' => $reviewData['related_admin_username'],
                    'real_name' => $reviewData['related_admin_real_name'],
                ],
        ];
    }

    private function mapToAdminListItem(array $reviewData): array
    {
        return [
            'review' => $this->mapToLocationReview($reviewData),
            'user' => $reviewData['related_username'] === null
                ? null
                : [
                    'username' => $reviewData['related_username'],
                    'real_name' => $reviewData['related_real_name'],
                ],
            'location' => $reviewData['related_location_name'] === null
                ? null
                : [
                    'location_code' => $reviewData['related_location_code'],
                    'location_name' => $reviewData['related_location_name'],
                ],
            'reply_admin' => $reviewData['related_admin_username'] === null
                ? null
                : [
                    'username' => $reviewData['related_admin_username'],
                    'real_name' => $reviewData['related_admin_real_name'],
                ],
        ];
    }

    private function mapToLocationReview(array $reviewData): LocationReview
    {
        return new LocationReview(
            (int)$reviewData['location_review_id'],
            (int)$reviewData['user_id'],
            (int)$reviewData['location_id'],
            (int)$reviewData['charge_record_id'],
            (int)$reviewData['rating'],
            (string)$reviewData['content'],
            $reviewData['admin_reply'] === null
                ? null
                : (string)$reviewData['admin_reply'],
            $reviewData['reply_admin_user_id'] === null
                ? null
                : (int)$reviewData['reply_admin_user_id'],
            $reviewData['replied_at'] === null
                ? null
                : (string)$reviewData['replied_at'],
            (string)$reviewData['status'],
            (string)$reviewData['created_at'],
            (string)$reviewData['updated_at']
        );
    }
}