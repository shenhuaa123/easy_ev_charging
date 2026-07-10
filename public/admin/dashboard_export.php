<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\AdminDashboardRepository;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\CsvExportService;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$dashboardRepository = new AdminDashboardRepository($connection);

$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$csvExportService = new CsvExportService();

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../login.php',
    '../user/dashboard.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect(
        '当前管理员信息异常，请重新登录。',
        '../login.php'
    );
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    Logger::security('非法请求方法：统计数据导出只允许POST。', [
        'actual_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'admin_user_id' => $currentUserId,
    ]);
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('请求方法不允许。统计数据导出只能通过管理员控制台执行。');
}

$csrf = new Csrf($session);

if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(Csrf::INVALID_MESSAGE);
}

$currentYear = (int)date('Y');
$availableRevenueYears = $dashboardRepository->getAvailableRevenueYears();

$selectedRevenueYear = filter_input(
    INPUT_POST,
    'revenue_year',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 2000,
            'max_range' => 2100,
        ],
    ]
);

if($selectedRevenueYear === false || $selectedRevenueYear === null){
    $selectedRevenueYear = $currentYear;
}

if(!in_array($selectedRevenueYear, $availableRevenueYears, true)){
    $selectedRevenueYear = $currentYear;
}

$metrics = $dashboardRepository->getOverviewMetrics();
$recentRevenueTrend = $dashboardRepository->getRecentRevenueTrend(7);
$monthlyRevenueTrend = $dashboardRepository->getMonthlyRevenueTrend($selectedRevenueYear);
$yearlyRevenueTrend = $dashboardRepository->getYearlyRevenueTrend();
$topLocations = $dashboardRepository->getTopLocationsByRevenue(5);

$rows = [];

appendOverviewRows($rows, $metrics);
appendRecentRevenueRows($rows, $recentRevenueTrend);
appendMonthlyRevenueRows($rows, $monthlyRevenueTrend, $selectedRevenueYear);
appendYearlyRevenueRows($rows, $yearlyRevenueTrend);
appendTopLocationRows($rows, $topLocations);

try{
    try{
        $logService->recordCurrentRequest(
            $currentUserId,
            'dashboard_statistics_export',
            'dashboard_statistics',
            null,
            'success',
            '导出管理员控制台统计数据CSV；月收入统计年份：' . $selectedRevenueYear . '。'
        );
    }catch(\Throwable $exception){
        Logger::error('统计数据导出审计日志写入失败。', [
            'exception' => $exception,
            'admin_user_id' => $currentUserId,
            'selected_revenue_year' => $selectedRevenueYear,
        ]);
    }

    $filename = 'dashboard_statistics_' . date('Ymd_His') . '.csv';

    $csvExportService->download(
        $filename,
        [
            '统计分组',
            '统计项目',
            '统计值',
            '补充说明',
        ],
        $rows
    );

    Logger::app('管理员导出控制台统计数据成功。', [
        'admin_user_id' => $currentUserId,
        'filename' => $filename,
        'row_count' => count($rows),
        'selected_revenue_year' => $selectedRevenueYear,
    ]);
}catch(\Throwable $exception){
    Logger::exception($exception, '控制台统计数据CSV导出失败。', [
        'admin_user_id' => $currentUserId,
        'selected_revenue_year' => $selectedRevenueYear,
        'row_count' => count($rows),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('统计数据导出失败，请稍后重试。');
}

exit;

function appendOverviewRows(array &$rows, array $metrics): void
{
    $userMetrics = $metrics['users'];
    $locationMetrics = $metrics['locations'];
    $stationMetrics = $metrics['stations'];
    $orderMetrics = $metrics['orders'];
    $logMetrics = $metrics['logs'];

    $rows[] = ['核心数据', '总用户数', $userMetrics['total_users'], ''];
    $rows[] = ['核心数据', '正常用户数', $userMetrics['active_users'], ''];
    $rows[] = ['核心数据', '停用用户数', $userMetrics['disabled_users'], ''];

    $rows[] = ['核心数据', '充电站点总数', $locationMetrics['total_locations'], ''];
    $rows[] = ['核心数据', '运营站点数', $locationMetrics['active_locations'], ''];
    $rows[] = ['核心数据', '维护站点数', $locationMetrics['maintenance_locations'], ''];
    $rows[] = ['核心数据', '停用站点数', $locationMetrics['inactive_locations'], ''];

    $rows[] = ['核心数据', '充电桩总数', $stationMetrics['total_stations'], ''];
    $rows[] = ['核心数据', '可用充电桩数', $stationMetrics['active_stations'], ''];
    $rows[] = ['核心数据', '维护充电桩数', $stationMetrics['maintenance_stations'], ''];
    $rows[] = ['核心数据', '停用充电桩数', $stationMetrics['inactive_stations'], ''];

    $rows[] = ['订单统计', '订单总数', $orderMetrics['total_orders'], ''];
    $rows[] = ['订单统计', '进行中订单数', $orderMetrics['charging_orders'], ''];
    $rows[] = ['订单统计', '已完成订单数', $orderMetrics['completed_orders'], ''];
    $rows[] = ['订单统计', '异常结束订单数', $orderMetrics['abnormal_orders'], ''];
    $rows[] = ['订单统计', '已取消订单数', $orderMetrics['cancelled_orders'], ''];
    $rows[] = ['订单统计', '今日收入', $orderMetrics['today_revenue'], '元'];
    $rows[] = ['订单统计', '今日完成订单数', $orderMetrics['today_finished_orders'], ''];
    $rows[] = ['订单统计', '累计收入', $orderMetrics['total_revenue'], '元'];
    $rows[] = ['订单统计', '累计完成订单数', $orderMetrics['total_finished_orders'], ''];
    $rows[] = ['订单统计', '平均计费时长', $orderMetrics['average_billable_minutes'], '分钟'];

    $rows[] = ['操作日志', '日志总数', $logMetrics['total_logs'], ''];
    $rows[] = ['操作日志', '今日日志数', $logMetrics['today_logs'], ''];
    $rows[] = ['操作日志', '失败日志数', $logMetrics['failure_logs'], ''];
}

function appendRecentRevenueRows(array &$rows, array $recentRevenueTrend): void
{
    foreach($recentRevenueTrend as $trendItem){
        $rows[] = [
            '近7天收入趋势',
            $trendItem['date'],
            $trendItem['revenue'],
            $trendItem['order_count'] . '单',
        ];
    }
}

function appendMonthlyRevenueRows(array &$rows, array $monthlyRevenueTrend, int $selectedRevenueYear): void
{
    foreach($monthlyRevenueTrend as $monthItem){
        $rows[] = [
            $selectedRevenueYear . '年月收入统计',
            $monthItem['label'],
            $monthItem['revenue'],
            $monthItem['order_count'] . '单',
        ];
    }
}

function appendYearlyRevenueRows(array &$rows, array $yearlyRevenueTrend): void
{
    foreach($yearlyRevenueTrend as $yearItem){
        $rows[] = [
            '年收入统计',
            $yearItem['label'],
            $yearItem['revenue'],
            $yearItem['order_count'] . '单',
        ];
    }
}

function appendTopLocationRows(array &$rows, array $topLocations): void
{
    foreach($topLocations as $index => $locationItem){
        $rows[] = [
            '站点收入排行',
            '第' . ($index + 1) . '名',
            $locationItem['revenue'],
            $locationItem['location_name'] . ' / ' . $locationItem['location_code'] . ' / ' . $locationItem['order_count'] . '单',
        ];
    }
}