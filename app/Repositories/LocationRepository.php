<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Location;
use mysqli;
use RuntimeException;

class LocationRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 查询全部充电站点。
     * 
     * @return Location[]
     */
    public function findAll(): array
    {
        $sql = '
            SELECT
                location_id,
                location_code,
                location_name,
                province,
                city,
                district,
                detailed_address,
                description,
                longitude,
                latitude,
                status,
                created_at,
                updated_at
            FROM locations
            ORDER BY location_id DESC
        ';

        $result = $this->connection->query($sql);

        if($result === false){
            throw new RuntimeException('查询充电站点列表失败。');
        }

        $locations = [];

        while($locationData = $result->fetch_assoc()){
            $locations[] = $this->mapToLocation($locationData);
        }

        $result->free();

        return $locations;
    }

    /**
     * 统计正在运营的充电站点数量。
     */
    public function countActiveList(array $filters = []): int
    {
        $filterValues = $this->normalizeActiveListFilters($filters);
        $visibleReviewStatus = 'visible';
        $activeLocationStatus = 'active';

        $sql = '
            SELECT COUNT(*) AS total
            FROM locations AS l
            LEFT JOIN (
                SELECT
                    location_id,
                    COUNT(*) AS review_count,
                    AVG(rating) AS average_rating
                FROM location_reviews
                WHERE status = ?
                GROUP BY location_id
            ) AS review_summary
                ON review_summary.location_id = l.location_id
            WHERE l.status = ?
            AND (
                ? = 0
                OR l.location_code LIKE ?
                OR l.location_name LIKE ?
                OR l.province LIKE ?
                OR l.city LIKE ?
                OR l.district LIKE ?
                OR l.detailed_address LIKE ?
            )
            AND (
                ? = 0
                OR (
                    COALESCE(review_summary.review_count, 0) > 0
                    AND review_summary.average_rating BETWEEN ? AND ?
                )
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('运营中站点数量统计SQL预处理失败。');
        }

        $statement->bind_param(
            'ssissssssidd',
            $visibleReviewStatus,
            $activeLocationStatus,
            $filterValues['has_keyword'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['has_rating_range'],
            $filterValues['rating_min'],
            $filterValues['rating_max']
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计运营中站点数量失败。');
        }

        $result = $statement->get_result();
        $countData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return (int)($countData['total'] ?? 0);
    }

    /**
     * 分页查询用户端可见站点，并附带可用充电桩数量。
     *
     * @return array<int, array{
     *     location: Location,
     *     available_station_count: int
     * }>
     */
    public function findActiveListItems(int $limit, int $offset, array $filters = []): array
    {
        if($limit <= 0){
            throw new RuntimeException('每页站点数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('站点分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeActiveListFilters($filters);
        $activeStationStatus = 'active';
        $visibleReviewStatus = 'visible';
        $activeLocationStatus = 'active';

        $sql = '
            SELECT
                l.location_id,
                l.location_code,
                l.location_name,
                l.province,
                l.city,
                l.district,
                l.detailed_address,
                l.description,
                l.longitude,
                l.latitude,
                l.status,
                l.created_at,
                l.updated_at,
                COALESCE(available_counts.available_station_count, 0)
                    AS available_station_count
            FROM locations AS l
            LEFT JOIN (
                SELECT
                    cs.location_id,
                    COUNT(*) AS available_station_count
                FROM charging_stations AS cs
                LEFT JOIN charge_records AS cr
                    ON cr.active_station_id = cs.station_id
                WHERE cs.status = ?
                AND cr.charge_record_id IS NULL
                GROUP BY cs.location_id
            ) AS available_counts
                ON available_counts.location_id = l.location_id
            LEFT JOIN (
                SELECT
                    location_id,
                    COUNT(*) AS review_count,
                    AVG(rating) AS average_rating
                FROM location_reviews
                WHERE status = ?
                GROUP BY location_id
            ) AS review_summary
                ON review_summary.location_id = l.location_id
            WHERE l.status = ?
            AND (
                ? = 0
                OR l.location_code LIKE ?
                OR l.location_name LIKE ?
                OR l.province LIKE ?
                OR l.city LIKE ?
                OR l.district LIKE ?
                OR l.detailed_address LIKE ?
            )
            AND (
                ? = 0
                OR (
                    COALESCE(review_summary.review_count, 0) > 0
                    AND review_summary.average_rating BETWEEN ? AND ?
                )
            )
            ORDER BY
                l.location_name ASC,
                l.location_id ASC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户端站点列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'sssissssssiddii',
            $activeStationStatus,
            $visibleReviewStatus,
            $activeLocationStatus,
            $filterValues['has_keyword'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['keyword_pattern'],
            $filterValues['has_rating_range'],
            $filterValues['rating_min'],
            $filterValues['rating_max'],
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询用户端站点列表失败。');
        }

        $result = $statement->get_result();
        $locationItems = [];

        while($locationData = $result->fetch_assoc()){
            $locationItems[] = [
                'location' => $this->mapToLocation($locationData),
                'available_station_count' => (int)$locationData['available_station_count'],
            ];
        }

        $result->free();
        $statement->close();

        return $locationItems;
    }

    /**
     * 在当前事务中锁定指定充电站点记录。
     */
    public function lockByIdForUpdate(int $locationId): bool
    {
        $sql = '
            SELECT location_id
            FROM locations
            WHERE location_id = ?
            FOR UPDATE
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电站点记录锁定SQL预处理失败。');
        }

        $statement->bind_param('i', $locationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('锁定充电站点记录失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 根据数据库主键查询站点。
     */
    public function findById(int $locationId): ?Location
    {
        $sql = '
            SELECT
                location_id,
                location_code,
                location_name,
                province,
                city,
                district,
                detailed_address,
                description,
                longitude,
                latitude,
                status,
                created_at,
                updated_at
            FROM locations
            WHERE location_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点编号查询SQL预处理失败。');
        }

        $statement->bind_param('i', $locationId);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('根据站点编号查询充电站点失败。');
        }

        $result = $statement->get_result();

        $locationData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($locationData === null){
            return null;
        }

        return $this->mapToLocation($locationData);
    }

    /**
     * 根据业务编号查询站点。
     */
    public function findByCode(string $locationCode): ?Location
    {
        $sql = '
            SELECT
                location_id,
                location_code,
                location_name,
                province,
                city,
                district,
                detailed_address,
                description,
                longitude,
                latitude,
                status,
                created_at,
                updated_at
            FROM locations
            WHERE location_code = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点业务编号查询SQL预处理失败。');
        }

        $statement->bind_param('s', $locationCode);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('根据站点业务编号查询充电站点失败。');
        }

        $result = $statement->get_result();

        $locationData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($locationData === null){
            return null;
        }

        return $this->mapToLocation($locationData);
    }

    /**
     * 新增充电站点。
     * 
     * 返回数据库生成的站点编号。
     */
    public function create(Location $location): int
    {
        $sql = '
            INSERT INTO locations(
                location_code,
                location_name,
                province,
                city,
                district,
                detailed_address,
                description,
                longitude,
                latitude,
                status
            )
            VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false) {
            throw new RuntimeException('充电站点新增SQL预处理失败。');
        }

        $locationCode = $location->getLocationCode();

        $locationName = $location->getLocationName();

        $province = $location->getProvince();

        $city = $location->getCity();

        $district = $location->getDistrict();

        $detailedAddress = $location->getDetailedAddress();

        $description = $location->getDescription();

        $longitude = $location->getLongitude();

        $latitude = $location->getLatitude();

        $status = $location->getStatus();

        $statement->bind_param(
            'ssssssssss',
            $locationCode,
            $locationName,
            $province,
            $city,
            $district,
            $detailedAddress,
            $description,
            $longitude,
            $latitude,
            $status
        );

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('新增充电站点失败。');
        }

        $locationId = $statement->insert_id;

        $statement->close();

        return $locationId;
    }

    /**
     * 修改站点状态。
     * 
     * 当新状态与旧状态相同，或站点不存在时，返回false。
     */
    public function updateStatus(int $locationId, string $status): bool
    {
        $sql = '
            UPDATE locations
            SET status = ?
            WHERE location_id = ?
              AND status <> ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false) {
            throw new RuntimeException('站点状态更新SQL预处理失败。');
        }

        $statement->bind_param(
            'sis',
            $status,
            $locationId,
            $status
        );

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('更新充电站点状态失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 检查某个站点内是否存在正在进行的充电订单。
     */
    public function hasActiveChargeRecords(int $locationId): bool
    {
        if($locationId <= 0){
            return false;
        }

        $sql = '
            SELECT 1
            FROM charging_stations AS cs
            INNER JOIN charge_records AS cr
                ON cr.active_station_id = cs.station_id
            WHERE cs.location_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('活动充电订单检查SQL预处理失败。');
        }

        $statement->bind_param('i', $locationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查站点活动充电订单失败。');
        }

        $result = $statement->get_result();
        $hasActiveRecord = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $hasActiveRecord;
    }

    /**
     * 判断除指定站点外，是否存在相同名称和完整地址的站点。
     */
    public function existsByNameAndAddressExceptId(
        string $locationName,
        string $province,
        string $city,
        string $district,
        string $detailedAddress,
        int $excludedLocationId
    ): bool {
        $sql = '
            SELECT 1
            FROM locations
            WHERE location_name = ?
            AND province = ?
            AND city = ?
            AND district = ?
            AND detailed_address = ?
            AND location_id <> ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点名称和地址重复检查SQL预处理失败。');
        }

        $statement->bind_param(
            'sssssi',
            $locationName,
            $province,
            $city,
            $district,
            $detailedAddress,
            $excludedLocationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查站点名称和地址是否重复失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 更新充电站点的可编辑资料。
     */
    public function update(Location $location): bool
    {
        $locationId = $location->getLocationId();

        if($locationId === null){
            throw new RuntimeException('缺少需要更新的充电站点编号。');
        }

        $sql = '
            UPDATE locations
            SET
                location_name = ?,
                province = ?,
                city = ?,
                district = ?,
                detailed_address = ?,
                description = ?,
                longitude = ?,
                latitude = ?
            WHERE location_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电站点资料更新SQL预处理失败。');
        }

        $locationName = $location->getLocationName();
        $province = $location->getProvince();
        $city = $location->getCity();
        $district = $location->getDistrict();
        $detailedAddress = $location->getDetailedAddress();
        $description = $location->getDescription();
        $longitude = $location->getLongitude();
        $latitude = $location->getLatitude();

        $statement->bind_param(
            'ssssssssi',
            $locationName,
            $province,
            $city,
            $district,
            $detailedAddress,
            $description,
            $longitude,
            $latitude,
            $locationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新充电站点资料失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 根据管理员筛选条件分页查询站点，并附带充电桩数量。
     *
     * @return array<int, array{
     *     location: Location,
     *     station_count: int
     * }>
     */
    public function searchAdminList(array $filters, int $limit, int $offset): array
    {
        if($limit <= 0){
            throw new RuntimeException('每页站点数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('站点分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeAdminListFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];

        $sql = '
            SELECT
                l.location_id,
                l.location_code,
                l.location_name,
                l.province,
                l.city,
                l.district,
                l.detailed_address,
                l.description,
                l.longitude,
                l.latitude,
                l.status,
                l.created_at,
                l.updated_at,
                COALESCE(sc.station_count, 0) AS station_count
            FROM locations AS l
            LEFT JOIN (
                SELECT
                    location_id,
                    COUNT(*) AS station_count
                FROM charging_stations
                GROUP BY location_id
            ) AS sc
                ON sc.location_id = l.location_id
            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    l.location_code,
                    l.location_name,
                    l.province,
                    l.city,
                    l.district,
                    l.detailed_address
                ) LIKE ?
            )
            AND (
                ? = 0
                OR l.status = ?
            )
            ORDER BY l.location_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点管理列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'isisii',
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询站点管理列表失败。');
        }

        $result = $statement->get_result();
        $locationItems = [];

        while($locationData = $result->fetch_assoc()){
            $locationItems[] = [
                'location' => $this->mapToLocation($locationData),
                'station_count' => (int)$locationData['station_count'],
            ];
        }

        $result->free();
        $statement->close();

        return $locationItems;
    }

    /**
     * 统计符合管理员筛选条件的站点数量。
     */
    public function countAdminList(array $filters): int
    {
        $filterValues = $this->normalizeAdminListFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];

        $sql = '
            SELECT COUNT(*) AS total
            FROM locations AS l
            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    l.location_code,
                    l.location_name,
                    l.province,
                    l.city,
                    l.district,
                    l.detailed_address
                ) LIKE ?
            )
            AND (
                ? = 0
                OR l.status = ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点管理列表数量统计SQL预处理失败。');
        }

        $statement->bind_param(
            'isis',
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计筛选后的站点数量失败。');
        }

        $result = $statement->get_result();
        $countData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return (int)($countData['total'] ?? 0);
    }

    private function normalizeActiveListFilters(array $filters): array
    {
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $ratingMin = $filters['rating_min'] ?? null;
        $ratingMax = $filters['rating_max'] ?? null;
        $hasRatingRange = $ratingMin !== null || $ratingMax !== null;

        if($hasRatingRange){
            $ratingMinValue = $ratingMin === null ? 1.0 : (float)$ratingMin;
            $ratingMaxValue = $ratingMax === null ? 5.0 : (float)$ratingMax;

            if($ratingMinValue > $ratingMaxValue){
                [$ratingMinValue, $ratingMaxValue] = [$ratingMaxValue, $ratingMinValue];
            }

            return [
                'has_keyword' => $keyword === '' ? 0 : 1,
                'keyword_pattern' => '%' . $keyword . '%',
                'has_rating_range' => 1,
                'rating_min' => $ratingMinValue,
                'rating_max' => $ratingMaxValue,
            ];
        }

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',
            'has_rating_range' => 0,
            'rating_min' => 1.0,
            'rating_max' => 5.0,
        ];
    }

    /**
     * 整理站点管理列表筛选条件。
     */
    private function normalizeAdminListFilters(array $filters): array
    {
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',
            'has_status' => $status === '' ? 0 : 1,
            'status' => $status,
        ];
    }

    /**
     * 将数据库查询结果转换成Location对象。
     */
    private function mapToLocation(array $locationData): Location
    {
        return new Location(
            (int) $locationData['location_id'],
            $locationData['location_code'],
            $locationData['location_name'],
            $locationData['province'],
            $locationData['city'],
            $locationData['district'],
            $locationData['detailed_address'],
            $locationData['description'],
            $locationData['longitude'],
            $locationData['latitude'],
            $locationData['status'],
            $locationData['created_at'],
            $locationData['updated_at']
        );
    }
}