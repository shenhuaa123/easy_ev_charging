<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ChargeRecord;
use mysqli;
use RuntimeException;

class ChargeRecordRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 根据数据库主键查询充电订单。
     */
    public function findById(int $chargeRecordId): ?ChargeRecord
    {
        $sql = '
            SELECT
                charge_record_id,
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
            FROM charge_records
            WHERE charge_record_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单主键查询SQL预处理失败。');
        }

        $statement->bind_param('i', $chargeRecordId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('根据主键查询充电订单失败。');
        }

        $result = $statement->get_result();
        $recordData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($recordData === null){
            return null;
        }

        return $this->mapToChargeRecord($recordData);
    }

    /**
     * 根据订单业务编号查询充电订单。
     */
    public function findByOrderNumber(string $orderNumber): ?ChargeRecord
    {
        $sql = '
            SELECT
                charge_record_id,
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
            FROM charge_records
            WHERE order_number = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单业务编号查询SQL预处理失败。');
        }

        $statement->bind_param('s', $orderNumber);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('根据业务编号查询充电订单失败。');
        }

        $result = $statement->get_result();
        $recordData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($recordData === null){
            return null;
        }

        return $this->mapToChargeRecord($recordData);
    }

    /**
     * 查询某个用户当前正在进行的充电订单。
     */
    public function findActiveByUserId(int $userId): ?ChargeRecord
    {
        if($userId <= 0){
            return null;
        }

        $sql = '
            SELECT
                charge_record_id,
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
            FROM charge_records
            WHERE active_user_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户进行中订单查询SQL预处理失败。');
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询用户当前充电订单失败。');
        }

        $result = $statement->get_result();
        $recordData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($recordData === null){
            return null;
        }

        return $this->mapToChargeRecord($recordData);
    }

    /**
     * 判断指定用户是否存在正在进行的充电订单。
     */
    public function hasActiveByUserId(int $userId): bool
    {
        if($userId <= 0){
            return false;
        }

        $sql = '
            SELECT 1
            FROM charge_records
            WHERE active_user_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户进行中订单检查SQL预处理失败。');
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查用户进行中订单失败。');
        }

        $result = $statement->get_result();
        $hasActiveRecord = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $hasActiveRecord;
    }

    /**
     * 查询某台充电桩当前正在进行的订单。
     */
    public function findActiveByStationId(int $stationId): ?ChargeRecord
    {
        if($stationId <= 0){
            return null;
        }

        $sql = '
            SELECT
                charge_record_id,
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
            FROM charge_records
            WHERE active_station_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩进行中订单查询SQL预处理失败。');
        }

        $statement->bind_param('i', $stationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询充电桩当前订单失败。');
        }

        $result = $statement->get_result();
        $recordData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($recordData === null){
            return null;
        }

        return $this->mapToChargeRecord($recordData);
    }

    /**
     * 分页查询指定用户的充电历史展示数据。
     *
     * @return array<int, array{
     *     record: ChargeRecord,
     *     station: ?array,
     *     location: ?array
     * }>
     */
    public function findUserHistoryItems(
        int $userId,
        int $limit,
        int $offset,
        array $filters = []
    ): array {
        if($userId <= 0){
            throw new RuntimeException('用户编号必须大于0。');
        }

        if($limit <= 0){
            throw new RuntimeException('每页充电记录数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('充电记录分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeUserHistoryFilters($filters);

        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $sql = '
            SELECT
                cr.charge_record_id,
                cr.order_number,
                cr.user_id,
                cr.station_id,
                cr.check_in_at,
                cr.check_out_at,
                cr.hourly_rate_snapshot,
                cr.billable_minutes,
                cr.total_cost,
                cr.status,
                cr.remark,
                cr.created_at,
                cr.updated_at,

                cs.station_id AS related_station_id,
                cs.station_code AS related_station_code,
                cs.station_name AS related_station_name,

                l.location_id AS related_location_id,
                l.location_name AS related_location_name

            FROM charge_records AS cr

            LEFT JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id

            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id

            WHERE cr.user_id = ?
            AND (
                ? = 0
                OR cr.status = ?
            )
            AND (
                ? = 0
                OR l.location_id = ?
            )
            AND (
                ? = 0
                OR cr.check_in_at >= ?
            )
            AND (
                ? = 0
                OR cr.check_in_at <= ?
            )

            ORDER BY cr.charge_record_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户充电历史分页查询SQL预处理失败。');
        }

        $statement->bind_param(
            'iisiiisisii',
            $userId,
            $hasStatus,
            $status,
            $hasLocation,
            $locationId,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('分页查询用户充电历史失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($recordData = $result->fetch_assoc()){
            $items[] = $this->mapToUserHistoryItem($recordData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 汇总指定用户的充电历史。
     */
    public function getUserHistorySummary(int $userId, array $filters = []): array
    {
        if($userId <= 0){
            throw new RuntimeException('用户编号必须大于0。');
        }

        $filterValues = $this->normalizeUserHistoryFilters($filters);

        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $sql = '
            SELECT
                COUNT(DISTINCT cr.charge_record_id) AS total_records,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status IN (?, ?) THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS settled_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status IN (?, ?)
                                THEN COALESCE(cr.billable_minutes, 0)
                            ELSE 0
                        END
                    ),
                    0
                ) AS total_billable_minutes,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status IN (?, ?)
                                THEN COALESCE(cr.total_cost, 0)
                            ELSE 0
                        END
                    ),
                    0.00
                ) AS total_cost,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status = ? THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS active_count

            FROM charge_records AS cr

            LEFT JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id

            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id

            WHERE cr.user_id = ?
            AND (
                ? = 0
                OR cr.status = ?
            )
            AND (
                ? = 0
                OR l.location_id = ?
            )
            AND (
                ? = 0
                OR cr.check_in_at >= ?
            )
            AND (
                ? = 0
                OR cr.check_in_at <= ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户充电历史汇总SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';
        $chargingStatus = 'charging';

        $statement->bind_param(
            'sssssssiisiiisis',
            $completedStatus,
            $abnormalStatus,
            $completedStatus,
            $abnormalStatus,
            $completedStatus,
            $abnormalStatus,
            $chargingStatus,
            $userId,
            $hasStatus,
            $status,
            $hasLocation,
            $locationId,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('汇总用户充电历史失败。');
        }

        $result = $statement->get_result();
        $summaryData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return [
            'total_records' => (int)($summaryData['total_records'] ?? 0),
            'settled_count' => (int)($summaryData['settled_count'] ?? 0),
            'total_billable_minutes' => (int)($summaryData['total_billable_minutes'] ?? 0),
            'total_cost' => (string)($summaryData['total_cost'] ?? '0.00'),
            'active_count' => (int)($summaryData['active_count'] ?? 0),
        ];
    }

    /**
     * 查询用户历史订单中出现过的充电站点，用于充电历史筛选。
     *
     * @return array<int, array{
     *     location_id: int,
     *     location_name: string
     * }>
     */
    public function getUserHistoryLocationOptions(int $userId): array
    {
        if($userId <= 0){
            throw new RuntimeException('用户编号必须大于0。');
        }

        $sql = '
            SELECT DISTINCT
                l.location_id,
                l.location_name
            FROM charge_records AS cr
            INNER JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id
            INNER JOIN locations AS l
                ON l.location_id = cs.location_id
            WHERE cr.user_id = ?
            ORDER BY l.location_name ASC, l.location_id ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('用户充电历史站点选项查询SQL预处理失败。');
        }

        $statement->bind_param('i', $userId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询用户充电历史站点选项失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($locationData = $result->fetch_assoc()){
            $items[] = [
                'location_id' => (int)$locationData['location_id'],
                'location_name' => (string)$locationData['location_name'],
            ];
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 分页查询指定充电桩的历史订单展示数据。
     *
     * @return array<int, array{
     *     record: ChargeRecord,
     *     user: ?array
     * }>
     */
    public function findStationHistoryItems(int $stationId, int $limit, int $offset): array
    {
        if($stationId <= 0){
            throw new RuntimeException('充电桩编号必须大于0。');
        }

        if($limit <= 0){
            throw new RuntimeException('每页订单数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('订单分页偏移量不能小于0。');
        }

        $sql = '
            SELECT
                cr.charge_record_id,
                cr.order_number,
                cr.user_id,
                cr.station_id,
                cr.check_in_at,
                cr.check_out_at,
                cr.hourly_rate_snapshot,
                cr.billable_minutes,
                cr.total_cost,
                cr.status,
                cr.remark,
                cr.created_at,
                cr.updated_at,

                u.user_id AS related_user_id,
                u.username AS related_username,
                u.real_name AS related_real_name

            FROM charge_records AS cr

            LEFT JOIN users AS u
                ON u.user_id = cr.user_id

            WHERE cr.station_id = ?
            ORDER BY cr.charge_record_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩历史订单分页查询SQL预处理失败。');
        }

        $statement->bind_param('iii', $stationId, $limit, $offset);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('分页查询充电桩历史订单失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($recordData = $result->fetch_assoc()){
            $items[] = $this->mapToStationHistoryItem($recordData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 汇总指定充电桩的历史订单。
     */
    public function getStationHistorySummary(int $stationId): array
    {
        if($stationId <= 0){
            throw new RuntimeException('充电桩编号必须大于0。');
        }

        $sql = '
            SELECT
                COUNT(*) AS total_records,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status IN (?, ?) THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS settled_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status IN (?, ?)
                                THEN COALESCE(billable_minutes, 0)
                            ELSE 0
                        END
                    ),
                    0
                ) AS total_billable_minutes,

                COALESCE(
                    SUM(
                        CASE
                            WHEN status IN (?, ?)
                                THEN COALESCE(total_cost, 0)
                            ELSE 0
                        END
                    ),
                    0.00
                ) AS total_revenue

            FROM charge_records
            WHERE station_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩历史订单汇总SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'ssssssi',
            $completedStatus,
            $abnormalStatus,
            $completedStatus,
            $abnormalStatus,
            $completedStatus,
            $abnormalStatus,
            $stationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('汇总充电桩历史订单失败。');
        }

        $result = $statement->get_result();
        $summaryData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return [
            'total_records' => (int)($summaryData['total_records'] ?? 0),
            'settled_count' => (int)($summaryData['settled_count'] ?? 0),
            'total_billable_minutes' => (int)(
                $summaryData['total_billable_minutes'] ?? 0
            ),
            'total_revenue' => (string)(
                $summaryData['total_revenue'] ?? '0.00'
            ),
        ];
    }

    /**
     * 根据管理员筛选条件导出订单列表展示数据。
     *
     * 为避免一次性导出过多数据导致浏览器或服务器卡死，单次最多导出10000条。
     *
     * @return array<int, array{
     *     record: ChargeRecord,
     *     user: ?array,
     *     station: ?array,
     *     location: ?array
     * }>
     */
    public function exportListItemsWithFilters(array $filters, int $maxRows = 10000): array
    {
        if($maxRows <= 0 || $maxRows > 10000){
            throw new RuntimeException('订单导出数量必须在1到10000之间。');
        }

        return $this->searchListItemsWithFilters($filters, $maxRows, 0);
    }

    /**
     * 根据管理员筛选条件分页查询订单列表展示数据。
     *
     * 每个元素包含：
     * - record：ChargeRecord对象；
     * - user：用户展示信息或null；
     * - station：充电桩展示信息或null；
     * - location：站点展示信息或null。
     */
    public function searchListItemsWithFilters(
        array $filters,
        int $limit,
        int $offset
    ): array {
        if($limit <= 0){
            throw new RuntimeException('每页订单数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('订单分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeSearchFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $sql = '
            SELECT
                cr.charge_record_id,
                cr.order_number,
                cr.user_id,
                cr.station_id,
                cr.check_in_at,
                cr.check_out_at,
                cr.hourly_rate_snapshot,
                cr.billable_minutes,
                cr.total_cost,
                cr.status,
                cr.remark,
                cr.created_at,
                cr.updated_at,

                u.user_id AS related_user_id,
                u.username AS related_username,
                u.real_name AS related_real_name,

                cs.station_id AS related_station_id,
                cs.station_code AS related_station_code,
                cs.station_name AS related_station_name,

                l.location_id AS related_location_id,
                l.location_name AS related_location_name

            FROM charge_records AS cr

            LEFT JOIN users AS u
                ON u.user_id = cr.user_id

            LEFT JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id

            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id

            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    cr.order_number,
                    u.username,
                    u.real_name,
                    u.mobile,
                    cs.station_code,
                    cs.station_name,
                    l.location_code,
                    l.location_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR cr.status = ?
            )
            AND (
                ? = 0
                OR cr.check_in_at >= ?
            )
            AND (
                ? = 0
                OR cr.check_in_at <= ?
            )

            ORDER BY cr.charge_record_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'isisisisii',
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询充电订单列表失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($recordData = $result->fetch_assoc()){
            $items[] = $this->mapToAdminListItem($recordData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 汇总符合管理员筛选条件的订单状态和结算收入。
     */
    public function getSummaryWithFilters(array $filters): array
    {
        $filterValues = $this->normalizeSearchFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $chargingStatus = 'charging';
        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';
        $cancelledStatus = 'cancelled';

        $sql = '
            SELECT
                COUNT(DISTINCT cr.charge_record_id) AS total_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status = ? THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS charging_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status = ? THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS completed_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status = ? THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS abnormal_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status = ? THEN 1
                            ELSE 0
                        END
                    ),
                    0
                ) AS cancelled_count,

                COALESCE(
                    SUM(
                        CASE
                            WHEN cr.status IN (?, ?)
                                THEN cr.total_cost
                            ELSE 0
                        END
                    ),
                    0.00
                ) AS total_revenue

            FROM charge_records AS cr

            LEFT JOIN users AS u
                ON u.user_id = cr.user_id

            LEFT JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id

            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id

            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    cr.order_number,
                    u.username,
                    u.real_name,
                    u.mobile,
                    cs.station_code,
                    cs.station_name,
                    l.location_code,
                    l.location_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR cr.status = ?
            )
            AND (
                ? = 0
                OR cr.check_in_at >= ?
            )
            AND (
                ? = 0
                OR cr.check_in_at <= ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单筛选汇总SQL预处理失败。');
        }

        $statement->bind_param(
            'ssssssisisisis',
            $chargingStatus,
            $completedStatus,
            $abnormalStatus,
            $cancelledStatus,
            $completedStatus,
            $abnormalStatus,
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('汇总筛选后的充电订单失败。');
        }

        $result = $statement->get_result();
        $summaryData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return [
            'total_count' => (int)(
                $summaryData['total_count'] ?? 0
            ),
            'charging_count' => (int)(
                $summaryData['charging_count'] ?? 0
            ),
            'completed_count' => (int)(
                $summaryData['completed_count'] ?? 0
            ),
            'abnormal_count' => (int)(
                $summaryData['abnormal_count'] ?? 0
            ),
            'cancelled_count' => (int)(
                $summaryData['cancelled_count'] ?? 0
            ),
            'total_revenue' => (string)(
                $summaryData['total_revenue'] ?? '0.00'
            ),
        ];
    }

    /**
     * 新增进行中的充电订单。
     *
     * 返回数据库生成的订单主键。
     */
    public function create(ChargeRecord $record): int
    {
        $sql = '
            INSERT INTO charge_records (
                order_number,
                user_id,
                station_id,
                check_in_at,
                hourly_rate_snapshot,
                status,
                remark
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单新增SQL预处理失败。');
        }

        $orderNumber = $record->getOrderNumber();
        $userId = $record->getUserId();
        $stationId = $record->getStationId();
        $checkInAt = $record->getCheckInAt();
        $hourlyRateSnapshot = $record->getHourlyRateSnapshot();
        $status = $record->getStatus();
        $remark = $record->getRemark();

        $statement->bind_param(
            'siissss',
            $orderNumber,
            $userId,
            $stationId,
            $checkInAt,
            $hourlyRateSnapshot,
            $status,
            $remark
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('新增充电订单失败。');
        }

        $chargeRecordId = $statement->insert_id;

        $statement->close();

        return $chargeRecordId;
    }

    /**
     * 正常结束订单。
     *
     * 只有当前状态仍为charging时才允许更新。
     */
    public function complete(
        int $chargeRecordId,
        int $userId,
        string $checkOutAt,
        int $billableMinutes,
        string $totalCost
    ): bool {
        $sql = '
            UPDATE charge_records
            SET
                check_out_at = ?,
                billable_minutes = ?,
                total_cost = ?,
                status = ?
            WHERE charge_record_id = ?
              AND user_id = ?
              AND status = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单正常结束SQL预处理失败。');
        }

        $newStatus = 'completed';
        $currentStatus = 'charging';

        $statement->bind_param(
            'sissiis',
            $checkOutAt,
            $billableMinutes,
            $totalCost,
            $newStatus,
            $chargeRecordId,
            $userId,
            $currentStatus
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('结束充电订单失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 将进行中的订单标记为异常结束。
     */
    public function markAsAbnormal(
        int $chargeRecordId,
        string $checkOutAt,
        int $billableMinutes,
        string $totalCost,
        string $remark
    ): bool {
        $sql = '
            UPDATE charge_records
            SET
                check_out_at = ?,
                billable_minutes = ?,
                total_cost = ?,
                status = ?,
                remark = ?
            WHERE charge_record_id = ?
              AND status = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电订单异常结束SQL预处理失败。');
        }

        $newStatus = 'abnormal';
        $currentStatus = 'charging';

        $statement->bind_param(
            'sisssis',
            $checkOutAt,
            $billableMinutes,
            $totalCost,
            $newStatus,
            $remark,
            $chargeRecordId,
            $currentStatus
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('异常结束充电订单失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 整理用户充电历史筛选条件。
     */
    private function normalizeUserHistoryFilters(array $filters): array
    {
        $allowedStatuses = [
            'charging',
            'completed',
            'abnormal',
            'cancelled',
        ];

        $status = trim((string)($filters['status'] ?? ''));
        $locationId = (int)($filters['location_id'] ?? 0);
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));

        if(!in_array($status, $allowedStatuses, true)){
            $status = '';
        }

        if($locationId <= 0){
            $locationId = 0;
        }

        if($dateFrom !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $dateFrom) !== 1){
            $dateFrom = '';
        }

        if($dateTo !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $dateTo) !== 1){
            $dateTo = '';
        }

        return [
            'has_status' => $status === '' ? 0 : 1,
            'status' => $status,
            'has_location' => $locationId <= 0 ? 0 : 1,
            'location_id' => $locationId,
            'has_date_from' => $dateFrom === '' ? 0 : 1,
            'date_from_start' => $dateFrom === '' ? '' : $dateFrom . ' 00:00:00',
            'has_date_to' => $dateTo === '' ? 0 : 1,
            'date_to_end' => $dateTo === '' ? '' : $dateTo . ' 23:59:59',
        ];
    }

    /**
     * 整理订单搜索条件，并转换为SQL查询需要的值。
     */
    private function normalizeSearchFilters(array $filters): array
    {
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',

            'has_status' => $status === '' ? 0 : 1,
            'status' => $status,

            'has_date_from' => $dateFrom === '' ? 0 : 1,
            'date_from_start' => $dateFrom === ''
                ? ''
                : $dateFrom . ' 00:00:00',

            'has_date_to' => $dateTo === '' ? 0 : 1,
            'date_to_end' => $dateTo === ''
                ? ''
                : $dateTo . ' 23:59:59',
        ];
    }

    /**
     * 将管理员列表查询结果转换为展示数据。
     */
    private function mapToAdminListItem(array $recordData): array
    {
        $user = $recordData['related_user_id'] === null
            ? null
            : [
                'user_id' => (int)$recordData['related_user_id'],
                'username' => (string)$recordData['related_username'],
                'real_name' => (string)$recordData['related_real_name'],
            ];

        $station = $recordData['related_station_id'] === null
            ? null
            : [
                'station_id' => (int)$recordData['related_station_id'],
                'station_code' => (string)$recordData['related_station_code'],
                'station_name' => (string)$recordData['related_station_name'],
            ];

        $location = $recordData['related_location_id'] === null
            ? null
            : [
                'location_id' => (int)$recordData['related_location_id'],
                'location_name' => (string)$recordData['related_location_name'],
            ];

        return [
            'record' => $this->mapToChargeRecord($recordData),
            'user' => $user,
            'station' => $station,
            'location' => $location,
        ];
    }

    /**
     * 将用户充电历史查询结果转换为展示数据。
     */
    private function mapToUserHistoryItem(array $recordData): array
    {
        $station = $recordData['related_station_id'] === null
            ? null
            : [
                'station_id' => (int)$recordData['related_station_id'],
                'station_code' => (string)$recordData['related_station_code'],
                'station_name' => (string)$recordData['related_station_name'],
            ];

        $location = $recordData['related_location_id'] === null
            ? null
            : [
                'location_id' => (int)$recordData['related_location_id'],
                'location_name' => (string)$recordData['related_location_name'],
            ];

        return [
            'record' => $this->mapToChargeRecord($recordData),
            'station' => $station,
            'location' => $location,
        ];
    }

    /**
     * 将充电桩历史订单查询结果转换为展示数据。
     */
    private function mapToStationHistoryItem(array $recordData): array
    {
        $user = $recordData['related_user_id'] === null
            ? null
            : [
                'user_id' => (int)$recordData['related_user_id'],
                'username' => (string)$recordData['related_username'],
                'real_name' => (string)$recordData['related_real_name'],
            ];

        return [
            'record' => $this->mapToChargeRecord($recordData),
            'user' => $user,
        ];
    }

    /**
     * 将数据库查询结果转换成ChargeRecord对象。
     */
    private function mapToChargeRecord(array $recordData): ChargeRecord
    {
        return new ChargeRecord(
            (int) $recordData['charge_record_id'],
            $recordData['order_number'],
            (int) $recordData['user_id'],
            (int) $recordData['station_id'],
            $recordData['check_in_at'],
            $recordData['check_out_at'],
            $recordData['hourly_rate_snapshot'],
            $recordData['billable_minutes'] === null
                ? null
                : (int) $recordData['billable_minutes'],
            $recordData['total_cost'],
            $recordData['status'],
            $recordData['remark'],
            $recordData['created_at'],
            $recordData['updated_at']
        );
    }
}