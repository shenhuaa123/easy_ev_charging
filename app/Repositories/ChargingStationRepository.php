<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ChargingStation;
use mysqli;
use RuntimeException;

class ChargingStationRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 查询某个站点下的全部充电桩。
     *
     * @return ChargingStation[]
     */
    public function findByLocationId(int $locationId): array 
    {
        $sql = '
            SELECT
                station_id,
                station_code,
                station_name,
                location_id,
                charger_type,
                power_kw,
                hourly_rate,
                status,
                created_at,
                updated_at
            FROM charging_stations
            WHERE location_id = ?
            ORDER BY station_id ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点充电桩查询SQL预处理失败。');
        }

        $statement->bind_param('i', $locationId);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('查询站点下的充电桩失败。');
        }

        $result = $statement->get_result();

        $stations = [];

        while($stationData = $result->fetch_assoc()){
            $stations[] = $this->mapToChargingStation($stationData);
        }

        $result->free();
        $statement->close();

        return $stations;
    }

    /**
     * 查询指定站点下的全部充电桩，并附带当前占用状态。
     *
     * @return array<int, array{
     *     station: ChargingStation,
     *     has_active_record: bool
     * }>
     */
    public function findAvailabilityItemsByLocationId(int $locationId, string $chargerType = ''): array
    {
        if($locationId <= 0){
            throw new RuntimeException('充电站点编号必须大于0。');
        }

        $chargerType = trim($chargerType);

        if(!in_array($chargerType, ['ac', 'dc'], true)){
            $chargerType = '';
        }

        $sql = '
            SELECT
                cs.station_id,
                cs.station_code,
                cs.station_name,
                cs.location_id,
                cs.charger_type,
                cs.power_kw,
                cs.hourly_rate,
                cs.status,
                cs.created_at,
                cs.updated_at,
                CASE
                    WHEN cr.charge_record_id IS NULL THEN 0
                    ELSE 1
                END AS has_active_record
            FROM charging_stations AS cs
            LEFT JOIN charge_records AS cr
                ON cr.active_station_id = cs.station_id
            WHERE cs.location_id = ?
            AND (? = "" OR cs.charger_type = ?)
            ORDER BY cs.station_id ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点充电桩占用状态查询SQL预处理失败。');
        }

        $statement->bind_param('iss', $locationId, $chargerType, $chargerType);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询站点充电桩及占用状态失败。');
        }

        $result = $statement->get_result();
        $stationItems = [];

        while($stationData = $result->fetch_assoc()){
            $stationItems[] = [
                'station' => $this->mapToChargingStation($stationData),
                'has_active_record' => (bool)(int)$stationData['has_active_record'],
            ];
        }

        $result->free();
        $statement->close();

        return $stationItems;
    }

    /**
     * 在当前事务中锁定指定充电桩记录。
     */
    public function lockByIdForUpdate(int $stationId): bool
    {
        $sql = '
            SELECT station_id
            FROM charging_stations
            WHERE station_id = ?
            FOR UPDATE
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩记录锁定SQL预处理失败。');
        }

        $statement->bind_param('i', $stationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('锁定充电桩记录失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 根据数据库主键查询充电桩。
     */
    public function findById(int $stationId): ?ChargingStation 
    {
        $sql = '
            SELECT
                station_id,
                station_code,
                station_name,
                location_id,
                charger_type,
                power_kw,
                hourly_rate,
                status,
                created_at,
                updated_at
            FROM charging_stations
            WHERE station_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩编号查询SQL预处理失败。');
        }

        $statement->bind_param('i', $stationId);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('根据编号查询充电桩失败。');
        }

        $result = $statement->get_result();

        $stationData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($stationData === null){
            return null;
        }

        return $this->mapToChargingStation($stationData);
    }

    /**
     * 根据业务编号查询充电桩。
     */
    public function findByCode(string $stationCode): ?ChargingStation 
    {
        $sql = '
            SELECT
                station_id,
                station_code,
                station_name,
                location_id,
                charger_type,
                power_kw,
                hourly_rate,
                status,
                created_at,
                updated_at
            FROM charging_stations
            WHERE station_code = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩业务编号查询SQL预处理失败。');
        }

        $statement->bind_param('s', $stationCode);

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('根据业务编号查询充电桩失败。');
        }

        $result = $statement->get_result();

        $stationData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($stationData === null){
            return null;
        }

        return $this->mapToChargingStation($stationData);
    }

    /**
     * 新增充电桩。
     *
     * 返回数据库生成的充电桩编号。
     */
    public function create(ChargingStation $station): int 
    {
        $sql = '
            INSERT INTO charging_stations (
                station_code,
                station_name,
                location_id,
                charger_type,
                power_kw,
                hourly_rate,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩新增SQL预处理失败。');
        }

        $stationCode = $station->getStationCode();

        $stationName = $station->getStationName();

        $locationId = $station->getLocationId();

        $chargerType = $station->getChargerType();

        $powerKw = $station->getPowerKw();

        $hourlyRate = $station->getHourlyRate();

        $status = $station->getStatus();

        $statement->bind_param(
            'ssissss',
            $stationCode,
            $stationName,
            $locationId,
            $chargerType,
            $powerKw,
            $hourlyRate,
            $status
        );

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('新增充电桩失败。');
        }

        $stationId = $statement->insert_id;

        $statement->close();

        return $stationId;
    }

    /**
     * 修改充电桩状态。
     */
    public function updateStatus(int $stationId, string $status): bool 
    {
        $sql = '
            UPDATE charging_stations
            SET status = ?
            WHERE station_id = ?
              AND status <> ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩状态更新SQL预处理失败。');
        }

        $statement->bind_param(
            'sis',
            $status,
            $stationId,
            $status
        );

        if(!$statement->execute()){
            $statement->close();

            throw new RuntimeException('更新充电桩状态失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 将某个站点下状态为可用的充电桩批量改为维护中。
     *
     * 返回实际被修改的充电桩数量。
     */
    public function updateActiveToMaintenanceByLocationId(int $locationId): int
    {
        $sql = '
            UPDATE charging_stations
            SET status = ?
            WHERE location_id = ?
            AND status = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点充电桩维护状态联动SQL预处理失败。');
        }

        $maintenanceStatus = 'maintenance';
        $activeStatus = 'active';

        $statement->bind_param('sis', $maintenanceStatus, $locationId, $activeStatus);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('联动更新站点充电桩为维护状态失败。');
        }

        $updatedCount = $statement->affected_rows;
        $statement->close();

        return $updatedCount;
    }

    /**
     * 将某个站点下可用或维护中的充电桩批量改为已停用。
     *
     * 返回实际被修改的充电桩数量。
     */
    public function updateActiveOrMaintenanceToInactiveByLocationId(int $locationId): int
    {
        $sql = '
            UPDATE charging_stations
            SET status = ?
            WHERE location_id = ?
            AND status IN (?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('站点充电桩停用状态联动SQL预处理失败。');
        }

        $inactiveStatus = 'inactive';
        $activeStatus = 'active';
        $maintenanceStatus = 'maintenance';

        $statement->bind_param(
            'siss',
            $inactiveStatus,
            $locationId,
            $activeStatus,
            $maintenanceStatus
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('联动更新站点充电桩为停用状态失败。');
        }

        $updatedCount = $statement->affected_rows;
        $statement->close();

        return $updatedCount;
    }

    /**
     * 检查某台充电桩是否存在正在进行的充电订单。
     */
    public function hasActiveChargeRecord(int $stationId): bool
    {
        if($stationId <= 0){
            return false;
        }

        $sql = '
            SELECT 1
            FROM charge_records
            WHERE active_station_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩活动订单检查SQL预处理失败。');
        }

        $statement->bind_param('i', $stationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查充电桩活动订单失败。');
        }

        $result = $statement->get_result();
        $hasActiveRecord = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $hasActiveRecord;
    }

    /**
     * 判断除指定充电桩外，是否存在相同的充电桩编号。
     */
    public function existsByCodeExceptId(string $stationCode, int $excludedStationId): bool 
    {
        $sql = '
            SELECT 1
            FROM charging_stations
            WHERE station_code = ?
            AND station_id <> ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩编号重复检查SQL预处理失败。');
        }

        $statement->bind_param('si', $stationCode, $excludedStationId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('检查充电桩编号是否重复失败。');
        }

        $result = $statement->get_result();
        $exists = $result->fetch_assoc() !== null;

        $result->free();
        $statement->close();

        return $exists;
    }

    /**
     * 更新充电桩的可编辑资料。
     */
    public function update(ChargingStation $station): bool
    {
        $stationId = $station->getStationId();

        if($stationId === null){
            throw new RuntimeException('缺少需要更新的充电桩编号。');
        }

        $sql = '
            UPDATE charging_stations
            SET
                station_code = ?,
                station_name = ?,
                location_id = ?,
                charger_type = ?,
                power_kw = ?,
                hourly_rate = ?
            WHERE station_id = ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩资料更新SQL预处理失败。');
        }

        $stationCode = $station->getStationCode();
        $stationName = $station->getStationName();
        $locationId = $station->getLocationId();
        $chargerType = $station->getChargerType();
        $powerKw = $station->getPowerKw();
        $hourlyRate = $station->getHourlyRate();

        $statement->bind_param(
            'ssisssi',
            $stationCode,
            $stationName,
            $locationId,
            $chargerType,
            $powerKw,
            $hourlyRate,
            $stationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('更新充电桩资料失败。');
        }

        $wasUpdated = $statement->affected_rows === 1;

        $statement->close();

        return $wasUpdated;
    }

    /**
     * 根据管理员筛选条件分页查询充电桩，并附带所属站点资料。
     *
     * @return array<int, array{
     *     station: ChargingStation,
     *     location_id: ?int,
     *     location_code: ?string,
     *     location_name: ?string,
     *     location_status: ?string
     * }>
     */
    public function searchAdminList(array $filters, int $limit, int $offset): array
    {
        if($limit <= 0){
            throw new RuntimeException('每页充电桩数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('充电桩分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeAdminListFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];

        $sql = '
            SELECT
                cs.station_id,
                cs.station_code,
                cs.station_name,
                cs.location_id,
                cs.charger_type,
                cs.power_kw,
                cs.hourly_rate,
                cs.status,
                cs.created_at,
                cs.updated_at,
                l.location_id AS related_location_id,
                l.location_code,
                l.location_name,
                l.status AS location_status
            FROM charging_stations AS cs
            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id
            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    cs.station_code,
                    cs.station_name,
                    l.location_code,
                    l.location_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR cs.status = ?
            )
            AND (
                ? = 0
                OR cs.location_id = ?
            )
            ORDER BY cs.station_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩管理列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'isisiiii',
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status,
            $hasLocation,
            $locationId,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询充电桩管理列表失败。');
        }

        $result = $statement->get_result();
        $stationItems = [];

        while($stationData = $result->fetch_assoc()){
            $stationItems[] = [
                'station' => $this->mapToChargingStation($stationData),
                'location_id' => $stationData['related_location_id'] === null
                    ? null
                    : (int)$stationData['related_location_id'],
                'location_code' => $stationData['location_code'],
                'location_name' => $stationData['location_name'],
                'location_status' => $stationData['location_status'],
            ];
        }

        $result->free();
        $statement->close();

        return $stationItems;
    }

    /**
     * 统计符合管理员筛选条件的充电桩数量。
     */
    public function countAdminList(array $filters): int
    {
        $filterValues = $this->normalizeAdminListFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasStatus = $filterValues['has_status'];
        $status = $filterValues['status'];
        $hasLocation = $filterValues['has_location'];
        $locationId = $filterValues['location_id'];

        $sql = '
            SELECT COUNT(*) AS total
            FROM charging_stations AS cs
            LEFT JOIN locations AS l
                ON l.location_id = cs.location_id
            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    cs.station_code,
                    cs.station_name,
                    l.location_code,
                    l.location_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR cs.status = ?
            )
            AND (
                ? = 0
                OR cs.location_id = ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电桩管理列表数量统计SQL预处理失败。');
        }

        $statement->bind_param(
            'isisii',
            $hasKeyword,
            $keywordPattern,
            $hasStatus,
            $status,
            $hasLocation,
            $locationId
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计筛选后的充电桩数量失败。');
        }

        $result = $statement->get_result();
        $countData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        return (int)($countData['total'] ?? 0);
    }

    /**
     * 整理充电桩管理列表筛选条件。
     */
    private function normalizeAdminListFilters(array $filters): array
    {
        $keyword = trim((string)($filters['keyword'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));

        $locationId = filter_var(
            $filters['location_id'] ?? null,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if($locationId === false || $locationId === null){
            $locationId = 0;
        }

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',
            'has_status' => $status === '' ? 0 : 1,
            'status' => $status,
            'has_location' => $locationId === 0 ? 0 : 1,
            'location_id' => $locationId,
        ];
    }

    /**
     * 将数据库查询结果转换成ChargingStation对象。
     */
    private function mapToChargingStation(array $stationData): ChargingStation 
    {
        return new ChargingStation(
            (int) $stationData['station_id'],
            $stationData['station_code'],
            $stationData['station_name'],
            (int) $stationData['location_id'],
            $stationData['charger_type'],
            $stationData['power_kw'],
            $stationData['hourly_rate'],
            $stationData['status'],
            $stationData['created_at'],
            $stationData['updated_at']
        );
    }
}