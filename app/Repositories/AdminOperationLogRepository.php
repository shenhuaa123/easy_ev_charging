<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AdminOperationLog;
use mysqli;
use RuntimeException;

class AdminOperationLogRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * 新增管理员操作日志。
     */
    public function create(AdminOperationLog $log): int
    {
        $sql = '
            INSERT INTO admin_operation_logs (
                operator_user_id,
                action,
                target_type,
                target_id,
                result,
                detail,
                ip_address,
                user_agent,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员操作日志新增SQL预处理失败。');
        }

        $operatorUserId = $log->getOperatorUserId();
        $action = $log->getAction();
        $targetType = $log->getTargetType();
        $targetId = $log->getTargetId();
        $result = $log->getResult();
        $detail = $log->getDetail();
        $ipAddress = $log->getIpAddress();
        $userAgent = $log->getUserAgent();
        $createdAt = $log->getCreatedAt();

        $statement->bind_param(
            'ississsss',
            $operatorUserId,
            $action,
            $targetType,
            $targetId,
            $result,
            $detail,
            $ipAddress,
            $userAgent,
            $createdAt
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('新增管理员操作日志失败。');
        }

        $logId = $statement->insert_id;
        $statement->close();

        return $logId;
    }

    /**
     * 根据主键查询管理员操作日志。
     */
    public function findById(int $logId): ?AdminOperationLog
    {
        $sql = '
            SELECT
                admin_operation_log_id,
                operator_user_id,
                action,
                target_type,
                target_id,
                result,
                detail,
                ip_address,
                user_agent,
                created_at
            FROM admin_operation_logs
            WHERE admin_operation_log_id = ?
            LIMIT 1
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员操作日志查询SQL预处理失败。');
        }

        $statement->bind_param('i', $logId);

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询管理员操作日志失败。');
        }

        $result = $statement->get_result();
        $logData = $result->fetch_assoc();

        $result->free();
        $statement->close();

        if($logData === null){
            return null;
        }

        return $this->mapToAdminOperationLog($logData);
    }

    /**
     * 根据筛选条件导出管理员操作日志。
     *
     * @return array<int, array{
     *     log: AdminOperationLog,
     *     operator: ?array
     * }>
     */
    public function exportListItems(array $filters, int $maxRows = 10000): array
    {
        if($maxRows <= 0 || $maxRows > 10000){
            throw new RuntimeException('操作日志导出数量必须在1到10000之间。');
        }

        return $this->searchListItems($filters, $maxRows, 0);
    }

    /**
     * 根据筛选条件分页查询管理员操作日志。
     *
     * @return array<int, array{
     *     log: AdminOperationLog,
     *     operator: ?array
     * }>
     */
    public function searchListItems(array $filters, int $limit, int $offset): array
    {
        if($limit <= 0){
            throw new RuntimeException('每页日志数量必须大于0。');
        }

        if($offset < 0){
            throw new RuntimeException('日志分页偏移量不能小于0。');
        }

        $filterValues = $this->normalizeSearchFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasAction = $filterValues['has_action'];
        $action = $filterValues['action'];
        $hasTargetType = $filterValues['has_target_type'];
        $targetType = $filterValues['target_type'];
        $hasResult = $filterValues['has_result'];
        $resultValue = $filterValues['result'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $sql = '
            SELECT
                aol.admin_operation_log_id,
                aol.operator_user_id,
                aol.action,
                aol.target_type,
                aol.target_id,
                aol.result,
                aol.detail,
                aol.ip_address,
                aol.user_agent,
                aol.created_at,

                u.user_id AS operator_related_user_id,
                u.username AS operator_username,
                u.real_name AS operator_real_name

            FROM admin_operation_logs AS aol

            LEFT JOIN users AS u
                ON u.user_id = aol.operator_user_id

            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    aol.action,
                    aol.target_type,
                    aol.detail,
                    aol.ip_address,
                    u.username,
                    u.real_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR aol.action = ?
            )
            AND (
                ? = 0
                OR aol.target_type = ?
            )
            AND (
                ? = 0
                OR aol.result = ?
            )
            AND (
                ? = 0
                OR aol.created_at >= ?
            )
            AND (
                ? = 0
                OR aol.created_at <= ?
            )

            ORDER BY aol.admin_operation_log_id DESC
            LIMIT ?
            OFFSET ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员操作日志列表查询SQL预处理失败。');
        }

        $statement->bind_param(
            'isisisisisisii',
            $hasKeyword,
            $keywordPattern,
            $hasAction,
            $action,
            $hasTargetType,
            $targetType,
            $hasResult,
            $resultValue,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd,
            $limit,
            $offset
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询管理员操作日志列表失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($logData = $result->fetch_assoc()){
            $items[] = $this->mapToListItem($logData);
        }

        $result->free();
        $statement->close();

        return $items;
    }

    /**
     * 统计符合筛选条件的管理员操作日志数量。
     */
    public function countListItems(array $filters): int
    {
        $filterValues = $this->normalizeSearchFilters($filters);

        $hasKeyword = $filterValues['has_keyword'];
        $keywordPattern = $filterValues['keyword_pattern'];
        $hasAction = $filterValues['has_action'];
        $action = $filterValues['action'];
        $hasTargetType = $filterValues['has_target_type'];
        $targetType = $filterValues['target_type'];
        $hasResult = $filterValues['has_result'];
        $resultValue = $filterValues['result'];
        $hasDateFrom = $filterValues['has_date_from'];
        $dateFromStart = $filterValues['date_from_start'];
        $hasDateTo = $filterValues['has_date_to'];
        $dateToEnd = $filterValues['date_to_end'];

        $sql = '
            SELECT COUNT(*) AS total
            FROM admin_operation_logs AS aol
            LEFT JOIN users AS u
                ON u.user_id = aol.operator_user_id
            WHERE (
                ? = 0
                OR CONCAT_WS(
                    CHAR(32),
                    aol.action,
                    aol.target_type,
                    aol.detail,
                    aol.ip_address,
                    u.username,
                    u.real_name
                ) LIKE ?
            )
            AND (
                ? = 0
                OR aol.action = ?
            )
            AND (
                ? = 0
                OR aol.target_type = ?
            )
            AND (
                ? = 0
                OR aol.result = ?
            )
            AND (
                ? = 0
                OR aol.created_at >= ?
            )
            AND (
                ? = 0
                OR aol.created_at <= ?
            )
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('管理员操作日志数量统计SQL预处理失败。');
        }

        $statement->bind_param(
            'isisisisisis',
            $hasKeyword,
            $keywordPattern,
            $hasAction,
            $action,
            $hasTargetType,
            $targetType,
            $hasResult,
            $resultValue,
            $hasDateFrom,
            $dateFromStart,
            $hasDateTo,
            $dateToEnd
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('统计管理员操作日志数量失败。');
        }

        $queryResult = $statement->get_result();
        $countData = $queryResult->fetch_assoc();

        $queryResult->free();
        $statement->close();

        return (int)($countData['total'] ?? 0);
    }

    private function normalizeSearchFilters(array $filters): array
    {
        $allowedActions = [
            'admin_login_success',
            'admin_logout',
            'admin_profile_update',
            'admin_password_change',

            'user_profile_update',
            'user_password_reset',
            'user_status_update',

            'location_create',
            'location_update',
            'location_status_update',

            'station_create',
            'station_update',
            'station_status_update',

            'charge_record_abnormal_finish',

            'location_review_reply',
            'location_review_status_update',

            'charge_record_export',
            'admin_operation_log_export',
            'dashboard_statistics_export',
        ];

        $allowedTargetTypes = [
            'user',
            'location',
            'station',
            'charge_record',
            'location_review',
            'admin_operation_log',
            'dashboard_statistics',
        ];

        $allowedResults = [
            'success',
            'failure',
        ];

        $keyword = trim((string)($filters['keyword'] ?? ''));
        $action = trim((string)($filters['action'] ?? ''));
        $targetType = trim((string)($filters['target_type'] ?? ''));
        $result = trim((string)($filters['result'] ?? ''));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));

        if(!in_array($action, $allowedActions, true)){
            $action = '';
        }

        if(!in_array($targetType, $allowedTargetTypes, true)){
            $targetType = '';
        }

        if(!in_array($result, $allowedResults, true)){
            $result = '';
        }

        if($dateFrom !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $dateFrom) !== 1){
            $dateFrom = '';
        }

        if($dateTo !== '' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $dateTo) !== 1){
            $dateTo = '';
        }

        return [
            'has_keyword' => $keyword === '' ? 0 : 1,
            'keyword_pattern' => '%' . $keyword . '%',
            'has_action' => $action === '' ? 0 : 1,
            'action' => $action,
            'has_target_type' => $targetType === '' ? 0 : 1,
            'target_type' => $targetType,
            'has_result' => $result === '' ? 0 : 1,
            'result' => $result,
            'has_date_from' => $dateFrom === '' ? 0 : 1,
            'date_from_start' => $dateFrom === '' ? '' : $dateFrom . ' 00:00:00',
            'has_date_to' => $dateTo === '' ? 0 : 1,
            'date_to_end' => $dateTo === '' ? '' : $dateTo . ' 23:59:59',
        ];
    }

    /**
     * @return array{
     *     log: AdminOperationLog,
     *     operator: ?array
     * }
     */
    private function mapToListItem(array $logData): array
    {
        $operator = null;

        if($logData['operator_related_user_id'] !== null){
            $operator = [
                'user_id' => (int)$logData['operator_related_user_id'],
                'username' => $logData['operator_username'],
                'real_name' => $logData['operator_real_name'],
            ];
        }

        return [
            'log' => $this->mapToAdminOperationLog($logData),
            'operator' => $operator,
        ];
    }

    private function mapToAdminOperationLog(array $logData): AdminOperationLog
    {
        return new AdminOperationLog(
            (int)$logData['admin_operation_log_id'],
            (int)$logData['operator_user_id'],
            $logData['action'],
            $logData['target_type'],
            $logData['target_id'] === null ? null : (int)$logData['target_id'],
            $logData['result'],
            $logData['detail'],
            $logData['ip_address'],
            $logData['user_agent'],
            $logData['created_at']
        );
    }
}