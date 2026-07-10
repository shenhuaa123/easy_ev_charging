<?php

declare(strict_types=1);

namespace App\Repositories;

use mysqli;
use RuntimeException;

class AdminDashboardRepository
{
    private mysqli $connection;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    public function getOverviewMetrics(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'locations' => $this->getLocationMetrics(),
            'stations' => $this->getStationMetrics(),
            'orders' => $this->getOrderMetrics(),
            'logs' => $this->getLogMetrics(),
        ];
    }

    public function getRecentRevenueTrend(int $days): array
    {
        if($days < 1 || $days > 31){
            throw new RuntimeException('收入趋势天数必须在1到31天之间。');
        }

        $sql = '
            SELECT
                DATE(check_out_at) AS revenue_date,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_cost), 0.00) AS revenue
            FROM charge_records
            WHERE status IN (?, ?)
                AND check_out_at IS NOT NULL
                AND total_cost IS NOT NULL
                AND check_out_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(check_out_at)
            ORDER BY revenue_date ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('近期收入趋势查询SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'ssi',
            $completedStatus,
            $abnormalStatus,
            $days
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询近期收入趋势失败。');
        }

        $result = $statement->get_result();
        $trendMap = [];

        while($trendData = $result->fetch_assoc()){
            $trendMap[$trendData['revenue_date']] = [
                'date' => $trendData['revenue_date'],
                'order_count' => (int)$trendData['order_count'],
                'revenue' => (string)$trendData['revenue'],
            ];
        }

        $result->free();
        $statement->close();

        $items = [];

        for($i = $days - 1; $i >= 0; $i--){
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));

            $items[] = $trendMap[$date] ?? [
                'date' => $date,
                'order_count' => 0,
                'revenue' => '0.00',
            ];
        }

        return $items;
    }

    public function getMonthlyRevenueTrend(int $year): array
    {
        if($year < 2000 || $year > 2100){
            throw new RuntimeException('收入统计年份不合法。');
        }

        $startDate = sprintf('%04d-01-01', $year);
        $endDate = sprintf('%04d-01-01', $year + 1);

        $sql = '
            SELECT
                MONTH(check_out_at) AS revenue_month,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_cost), 0.00) AS revenue
            FROM charge_records
            WHERE status IN (?, ?)
                AND check_out_at IS NOT NULL
                AND total_cost IS NOT NULL
                AND check_out_at >= ?
                AND check_out_at < ?
            GROUP BY MONTH(check_out_at)
            ORDER BY revenue_month ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('月收入统计查询SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'ssss',
            $completedStatus,
            $abnormalStatus,
            $startDate,
            $endDate
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询月收入统计失败。');
        }

        $result = $statement->get_result();
        $monthMap = [];

        while($monthData = $result->fetch_assoc()){
            $month = (int)$monthData['revenue_month'];

            $monthMap[$month] = [
                'month' => $month,
                'label' => $year . '年' . $month . '月',
                'order_count' => (int)$monthData['order_count'],
                'revenue' => (string)$monthData['revenue'],
            ];
        }

        $result->free();
        $statement->close();

        $items = [];

        for($month = 1; $month <= 12; $month++){
            $items[] = $monthMap[$month] ?? [
                'month' => $month,
                'label' => $year . '年' . $month . '月',
                'order_count' => 0,
                'revenue' => '0.00',
            ];
        }

        return $items;
    }

    public function getYearlyRevenueTrend(): array
    {
        $sql = '
            SELECT
                YEAR(check_out_at) AS revenue_year,
                COUNT(*) AS order_count,
                COALESCE(SUM(total_cost), 0.00) AS revenue
            FROM charge_records
            WHERE status IN (?, ?)
                AND check_out_at IS NOT NULL
                AND total_cost IS NOT NULL
            GROUP BY YEAR(check_out_at)
            ORDER BY revenue_year ASC
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('年收入统计查询SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'ss',
            $completedStatus,
            $abnormalStatus
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询年收入统计失败。');
        }

        $result = $statement->get_result();
        $yearMap = [];

        while($yearData = $result->fetch_assoc()){
            $year = (int)$yearData['revenue_year'];

            $yearMap[$year] = [
                'year' => $year,
                'label' => $year . '年',
                'order_count' => (int)$yearData['order_count'],
                'revenue' => (string)$yearData['revenue'],
            ];
        }

        $result->free();
        $statement->close();

        if($yearMap === []){
            return [];
        }

        $minYear = min(array_keys($yearMap));
        $maxYear = max(array_keys($yearMap));
        $items = [];

        for($year = $minYear; $year <= $maxYear; $year++){
            $items[] = $yearMap[$year] ?? [
                'year' => $year,
                'label' => $year . '年',
                'order_count' => 0,
                'revenue' => '0.00',
            ];
        }

        return $items;
    }

    public function getAvailableRevenueYears(): array
    {
        $yearlyTrend = $this->getYearlyRevenueTrend();
        $years = [];

        foreach($yearlyTrend as $yearItem){
            $years[] = (int)$yearItem['year'];
        }

        $currentYear = (int)date('Y');

        if(!in_array($currentYear, $years, true)){
            $years[] = $currentYear;
        }

        sort($years);

        return $years;
    }

    public function getTopLocationsByRevenue(int $limit): array
    {
        if($limit <= 0 || $limit > 20){
            throw new RuntimeException('充电站点排行数量必须在1到20之间。');
        }

        $sql = '
            SELECT
                l.location_id,
                l.location_code,
                l.location_name,
                COUNT(cr.charge_record_id) AS order_count,
                COALESCE(SUM(cr.total_cost), 0.00) AS revenue
            FROM charge_records AS cr
            INNER JOIN charging_stations AS cs
                ON cs.station_id = cr.station_id
            INNER JOIN locations AS l
                ON l.location_id = cs.location_id
            WHERE cr.status IN (?, ?)
                AND cr.check_out_at IS NOT NULL
                AND cr.total_cost IS NOT NULL
            GROUP BY
                l.location_id,
                l.location_code,
                l.location_name
            ORDER BY revenue DESC, order_count DESC, l.location_id ASC
            LIMIT ?
        ';

        $statement = $this->connection->prepare($sql);

        if($statement === false){
            throw new RuntimeException('充电站点收入排行查询SQL预处理失败。');
        }

        $completedStatus = 'completed';
        $abnormalStatus = 'abnormal';

        $statement->bind_param(
            'ssi',
            $completedStatus,
            $abnormalStatus,
            $limit
        );

        if(!$statement->execute()){
            $statement->close();
            throw new RuntimeException('查询充电站点收入排行失败。');
        }

        $result = $statement->get_result();
        $items = [];

        while($locationData = $result->fetch_assoc()){
            $items[] = [
                'location_id' => (int)$locationData['location_id'],
                'location_code' => $locationData['location_code'],
                'location_name' => $locationData['location_name'],
                'order_count' => (int)$locationData['order_count'],
                'revenue' => (string)$locationData['revenue'],
            ];
        }

        $result->free();
        $statement->close();

        return $items;
    }

    private function getUserMetrics(): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_users,
                COALESCE(SUM(CASE WHEN role = "admin" THEN 1 ELSE 0 END), 0) AS admin_users,
                COALESCE(SUM(CASE WHEN role = "user" THEN 1 ELSE 0 END), 0) AS normal_users,
                COALESCE(SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END), 0) AS active_users,
                COALESCE(SUM(CASE WHEN status = "disabled" THEN 1 ELSE 0 END), 0) AS disabled_users
            FROM users
        ';

        $data = $this->fetchSingleRow($sql, '用户统计查询失败。');

        return [
            'total_users' => (int)($data['total_users'] ?? 0),
            'admin_users' => (int)($data['admin_users'] ?? 0),
            'normal_users' => (int)($data['normal_users'] ?? 0),
            'active_users' => (int)($data['active_users'] ?? 0),
            'disabled_users' => (int)($data['disabled_users'] ?? 0),
        ];
    }

    private function getLocationMetrics(): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_locations,
                COALESCE(SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END), 0) AS active_locations,
                COALESCE(SUM(CASE WHEN status = "maintenance" THEN 1 ELSE 0 END), 0) AS maintenance_locations,
                COALESCE(SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END), 0) AS inactive_locations
            FROM locations
        ';

        $data = $this->fetchSingleRow($sql, '充电站点统计查询失败。');

        return [
            'total_locations' => (int)($data['total_locations'] ?? 0),
            'active_locations' => (int)($data['active_locations'] ?? 0),
            'maintenance_locations' => (int)($data['maintenance_locations'] ?? 0),
            'inactive_locations' => (int)($data['inactive_locations'] ?? 0),
        ];
    }

    private function getStationMetrics(): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_stations,
                COALESCE(SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END), 0) AS active_stations,
                COALESCE(SUM(CASE WHEN status = "maintenance" THEN 1 ELSE 0 END), 0) AS maintenance_stations,
                COALESCE(SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END), 0) AS inactive_stations
            FROM charging_stations
        ';

        $data = $this->fetchSingleRow($sql, '充电桩统计查询失败。');

        return [
            'total_stations' => (int)($data['total_stations'] ?? 0),
            'active_stations' => (int)($data['active_stations'] ?? 0),
            'maintenance_stations' => (int)($data['maintenance_stations'] ?? 0),
            'inactive_stations' => (int)($data['inactive_stations'] ?? 0),
        ];
    }

    private function getOrderMetrics(): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_orders,

                COALESCE(SUM(CASE WHEN status = "charging" THEN 1 ELSE 0 END), 0) AS charging_orders,
                COALESCE(SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END), 0) AS completed_orders,
                COALESCE(SUM(CASE WHEN status = "abnormal" THEN 1 ELSE 0 END), 0) AS abnormal_orders,
                COALESCE(SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END), 0) AS cancelled_orders,

                COALESCE(SUM(CASE WHEN status IN ("completed", "abnormal") AND total_cost IS NOT NULL THEN total_cost ELSE 0 END), 0.00) AS total_revenue,
                COALESCE(SUM(CASE WHEN status IN ("completed", "abnormal") AND total_cost IS NOT NULL THEN 1 ELSE 0 END), 0) AS total_finished_orders,

                COALESCE(SUM(CASE WHEN status IN ("completed", "abnormal") AND check_out_at IS NOT NULL AND total_cost IS NOT NULL AND check_out_at >= CURDATE() AND check_out_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN total_cost ELSE 0 END), 0.00) AS today_revenue,
                COALESCE(SUM(CASE WHEN status IN ("completed", "abnormal") AND check_out_at IS NOT NULL AND total_cost IS NOT NULL AND check_out_at >= CURDATE() AND check_out_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END), 0) AS today_finished_orders,

                COALESCE(AVG(CASE WHEN status IN ("completed", "abnormal") THEN billable_minutes ELSE NULL END), 0) AS average_billable_minutes
            FROM charge_records
        ';

        $data = $this->fetchSingleRow($sql, '充电订单统计查询失败。');

        return [
            'total_orders' => (int)($data['total_orders'] ?? 0),
            'charging_orders' => (int)($data['charging_orders'] ?? 0),
            'completed_orders' => (int)($data['completed_orders'] ?? 0),
            'abnormal_orders' => (int)($data['abnormal_orders'] ?? 0),
            'cancelled_orders' => (int)($data['cancelled_orders'] ?? 0),

            'total_revenue' => (string)($data['total_revenue'] ?? '0.00'),
            'total_finished_orders' => (int)($data['total_finished_orders'] ?? 0),

            'today_revenue' => (string)($data['today_revenue'] ?? '0.00'),
            'today_finished_orders' => (int)($data['today_finished_orders'] ?? 0),

            'average_billable_minutes' => (float)($data['average_billable_minutes'] ?? 0),
        ];
    }

    private function getLogMetrics(): array
    {
        $sql = '
            SELECT
                COUNT(*) AS total_logs,
                COALESCE(SUM(CASE WHEN result = "failure" THEN 1 ELSE 0 END), 0) AS failure_logs,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS today_logs
            FROM admin_operation_logs
        ';

        $data = $this->fetchSingleRow($sql, '操作日志统计查询失败。');

        return [
            'total_logs' => (int)($data['total_logs'] ?? 0),
            'failure_logs' => (int)($data['failure_logs'] ?? 0),
            'today_logs' => (int)($data['today_logs'] ?? 0),
        ];
    }

    private function fetchSingleRow(string $sql, string $errorMessage): array
    {
        $result = $this->connection->query($sql);

        if($result === false){
            throw new RuntimeException($errorMessage);
        }

        $data = $result->fetch_assoc();

        $result->free();

        return $data ?? [];
    }
}