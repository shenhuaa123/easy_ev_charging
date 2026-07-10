<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminDashboardRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository(
    $connection
);

$dashboardRepository = new AdminDashboardRepository(
    $connection
);

$session = new Session();

$authGuard = new AuthGuard(
    $session,
    $userRepository
);

/*
 * 检查内容包括：
 * 1. 是否已经登录；
 * 2. Session中的用户是否仍然存在；
 * 3. 账户是否仍为正常状态；
 * 4. 当前用户是否具有管理员角色。
 */
$currentUser = $authGuard->requireAdmin(
    '../login.php',
    '../user/dashboard.php'
);

$realName = $currentUser->getRealName();

$currentYear = (int)date('Y');
$availableRevenueYears = $dashboardRepository->getAvailableRevenueYears();

$selectedRevenueYear = filter_input(
    INPUT_GET,
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
$revenueTrend = $dashboardRepository->getRecentRevenueTrend(7);
$monthlyRevenueTrend = $dashboardRepository->getMonthlyRevenueTrend($selectedRevenueYear);
$yearlyRevenueTrend = $dashboardRepository->getYearlyRevenueTrend();
$topLocations = $dashboardRepository->getTopLocationsByRevenue(5);

$userMetrics = $metrics['users'];
$locationMetrics = $metrics['locations'];
$stationMetrics = $metrics['stations'];
$orderMetrics = $metrics['orders'];
$logMetrics = $metrics['logs'];

$todayRevenueText = number_format((float)$orderMetrics['today_revenue'], 2);
$totalRevenueText = number_format((float)$orderMetrics['total_revenue'], 2);
$averageBillableMinutesText = number_format((float)$orderMetrics['average_billable_minutes'], 1);

$maxTrendRevenue = 0.0;

foreach($revenueTrend as $trendItem){
    $maxTrendRevenue = max($maxTrendRevenue, (float)$trendItem['revenue']);
}

$maxLocationRevenue = 0.0;

foreach($topLocations as $locationItem){
    $maxLocationRevenue = max($maxLocationRevenue, (float)$locationItem['revenue']);
}

$maxMonthlyRevenue = 0.0;

foreach($monthlyRevenueTrend as $monthItem){
    $maxMonthlyRevenue = max($maxMonthlyRevenue, (float)$monthItem['revenue']);
}

$maxYearlyRevenue = 0.0;

foreach($yearlyRevenueTrend as $yearItem){
    $maxYearlyRevenue = max($maxYearlyRevenue, (float)$yearItem['revenue']);
}

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $realName;
$topbarLinks = [
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../logout.php',
    ],
];

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$flashMessages = $session->getFlashMessages();

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>管理员控制台｜易充充电管理系统</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body class="dashboard-admin">
    <?php require dirname(__DIR__, 2) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <?php require dirname(__DIR__, 2) . '/views/partials/flash_messages.php'; ?>

        <section class="welcome-card">
            <h2>
                欢迎进入管理员控制台
            </h2>

            <p>
                您可以在这里管理用户、充电站点、具体充电桩以及充电订单。
            </p>
        </section>

        <div class="dash-heading">
            <div>
                <h3>系统核心统计</h3>
                <p>展示系统当前运行状态和收入概况。</p>
            </div>
        </div>

        <section class="metric-grid" aria-label="系统核心统计">
            <article class="metric-card">
                <span class="metric-label">总用户数</span>
                <strong class="metric-value"><?= View::escape((string)$userMetrics['total_users']) ?></strong>
                <span class="metric-note">
                    正常 <?= View::escape((string)$userMetrics['active_users']) ?>，
                    停用 <?= View::escape((string)$userMetrics['disabled_users']) ?>
                </span>
            </article>

            <article class="metric-card">
                <span class="metric-label">充电站点</span>
                <strong class="metric-value"><?= View::escape((string)$locationMetrics['total_locations']) ?></strong>
                <span class="metric-note">
                    运营 <?= View::escape((string)$locationMetrics['active_locations']) ?>，
                    维护 <?= View::escape((string)$locationMetrics['maintenance_locations']) ?>，
                    停用 <?= View::escape((string)$locationMetrics['inactive_locations']) ?>
                </span>
            </article>

            <article class="metric-card">
                <span class="metric-label">充电桩</span>
                <strong class="metric-value"><?= View::escape((string)$stationMetrics['total_stations']) ?></strong>
                <span class="metric-note">
                    可用 <?= View::escape((string)$stationMetrics['active_stations']) ?>，
                    维护 <?= View::escape((string)$stationMetrics['maintenance_stations']) ?>，
                    停用 <?= View::escape((string)$stationMetrics['inactive_stations']) ?>
                </span>
            </article>

            <article class="metric-card">
                <span class="metric-label">进行中订单</span>
                <strong class="metric-value"><?= View::escape((string)$orderMetrics['charging_orders']) ?></strong>
                <span class="metric-note">
                    今日完成 <?= View::escape((string)$orderMetrics['today_finished_orders']) ?> 单
                </span>
            </article>

            <article class="metric-card metric-strong">
                <span class="metric-label">今日收入</span>
                <strong class="metric-value">¥<?= View::escape($todayRevenueText) ?></strong>
                <span class="metric-note">
                    今日完成 <?= View::escape((string)$orderMetrics['today_finished_orders']) ?> 单
                </span>
            </article>

            <article class="metric-card metric-strong">
                <span class="metric-label">累计收入</span>
                <strong class="metric-value">¥<?= View::escape($totalRevenueText) ?></strong>
                <span class="metric-note">
                    累计完成 <?= View::escape((string)$orderMetrics['total_finished_orders']) ?> 单
                </span>
            </article>

            <article class="metric-card">
                <span class="metric-label">操作日志</span>
                <strong class="metric-value"><?= View::escape((string)$logMetrics['total_logs']) ?></strong>
                <span class="metric-note">
                    今日 <?= View::escape((string)$logMetrics['today_logs']) ?>，
                    失败 <?= View::escape((string)$logMetrics['failure_logs']) ?>
                </span>
            </article>
        </section>

        <div class="dash-heading">
            <div>
                <h3>操作面板</h3>
                <p>常用管理功能面板，处理用户、站点、充电桩和订单。</p>
            </div>
        </div>

        <section class="feature-grid">
            <article class="feature-card">
                <h3>用户管理</h3>

                <p>
                    查看用户资料、账户状态和充电记录，并执行启用或停用操作。
                </p>

                <a class="feature-link" href="users/index.php">
                    进入用户管理
                </a>
            </article>

            <article class="feature-card">
                <h3>充电站点管理</h3>

                <p>
                    新增、编辑、维护和停用充电站点。
                </p>

                <a class="feature-link" href="locations/index.php">
                    进入站点管理
                </a>
            </article>

            <article class="feature-card">
                <h3>充电桩管理</h3>

                <p>
                    管理每台充电桩的功率、费率和设备状态。
                </p>

                <a class="feature-link" href="stations/index.php">
                    进入充电桩管理
                </a>
            </article>

            <article class="feature-card">
                <h3>充电订单管理</h3>

                <p>
                    查看正在进行、已经完成以及异常状态的充电订单。
                </p>

                <a class="feature-link" href="charge_records/index.php">
                    进入订单管理
                </a>
            </article>

            <article class="feature-card">
                <h3>操作日志</h3>

                <p>
                    查看管理员登录、退出、以及管理操作记录。
                </p>

                <a class="feature-link" href="operation_logs/index.php">
                    查看操作日志
                </a>
            </article>

            <article class="feature-card">
                <h3>评价管理</h3>

                <p>
                    查看用户对充电站点的评分和评论，并处理管理员回复与公开显示状态。
                </p>

                <a class="feature-link" href="location_reviews/index.php">
                    进入评价管理
                </a>
            </article>

            <article class="feature-card">
                <h3>个人资料</h3>

                <p>
                    查看和修改姓名、手机号码、电子邮箱以及登录密码。
                </p>

                <a class="feature-link" href="../account/profile.php">
                    查看个人资料
                </a>
            </article>
        </section>

        <div class="dash-heading">
            <div>
                <h3>运营统计</h3>
                <p>查看订单状态、收入趋势和站点收入排行。</p>
            </div>

            <form class="stat-export" method="post" action="dashboard_export.php">
                <input
                    type="hidden"
                    name="<?= View::escape(Csrf::FIELD_NAME) ?>"
                    value="<?= View::escape($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="revenue_year"
                    value="<?= View::escape((string)$selectedRevenueYear) ?>"
                >

                <button type="submit">
                    导出统计数据
                </button>
            </form>
        </div>

        <section class="dash-summary">
            <article class="dash-panel">
                <div class="panel-header">
                    <h3>订单状态</h3>
                    <span>平均计费 <?= View::escape($averageBillableMinutesText) ?> 分钟</span>
                </div>

                <div class="status-list">
                    <div>
                        <span>正在充电</span>
                        <strong><?= View::escape((string)$orderMetrics['charging_orders']) ?></strong>
                    </div>

                    <div>
                        <span>正常完成</span>
                        <strong><?= View::escape((string)$orderMetrics['completed_orders']) ?></strong>
                    </div>

                    <div>
                        <span>异常结束</span>
                        <strong><?= View::escape((string)$orderMetrics['abnormal_orders']) ?></strong>
                    </div>

                    <div>
                        <span>已取消</span>
                        <strong><?= View::escape((string)$orderMetrics['cancelled_orders']) ?></strong>
                    </div>
                </div>
            </article>

            <article class="dash-panel">
                <div class="panel-header">
                    <h3>近 7 天收入趋势</h3>
                    <span>按订单完成时间统计</span>
                </div>

                <?php if($revenueTrend === []): ?>
                    <p class="empty-dash">暂无收入数据。</p>
                <?php else: ?>
                    <div class="trend-list">
                        <?php foreach($revenueTrend as $trendItem): ?>
                            <?php
                            $trendRevenue = (float)$trendItem['revenue'];
                            $trendPercent = $maxTrendRevenue <= 0 ? 0 : (int)round($trendRevenue / $maxTrendRevenue * 100);
                            ?>
                            <div class="trend-row">
                                <span class="trend-date"><?= View::escape(substr($trendItem['date'], 5)) ?></span>

                                <div class="trend-track">
                                    <div class="trend-bar" style="width: <?= View::escape((string)$trendPercent) ?>%;"></div>
                                </div>

                                <span class="trend-value">
                                    ¥<?= View::escape(number_format($trendRevenue, 2)) ?>
                                    / <?= View::escape((string)$trendItem['order_count']) ?> 单
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="dash-panel">
                <div class="panel-header">
                    <h3><?= View::escape((string)$selectedRevenueYear) ?> 年月收入统计</h3>
                    <span>1 月到 12 月，按订单完成时间统计</span>
                </div>

                <form class="year-filter" method="get" action="dashboard.php">
                    <label for="revenue_year">统计年份</label>

                    <select id="revenue_year" name="revenue_year">
                        <?php foreach($availableRevenueYears as $yearOption): ?>
                            <option value="<?= View::escape((string)$yearOption) ?>" <?= $selectedRevenueYear === $yearOption ? 'selected' : '' ?>>
                                <?= View::escape((string)$yearOption) ?> 年
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">切换</button>
                </form>

                <div class="trend-list">
                    <?php foreach($monthlyRevenueTrend as $monthItem): ?>
                        <?php
                        $monthRevenue = (float)$monthItem['revenue'];
                        $monthPercent = $maxMonthlyRevenue <= 0 ? 0 : (int)round($monthRevenue / $maxMonthlyRevenue * 100);
                        ?>
                        <div class="trend-row">
                            <span class="trend-date"><?= View::escape($monthItem['label']) ?></span>

                            <div class="trend-track">
                                <div class="trend-bar" style="width: <?= View::escape((string)$monthPercent) ?>%;"></div>
                            </div>

                            <span class="trend-value">
                                ¥<?= View::escape(number_format($monthRevenue, 2)) ?>
                                / <?= View::escape((string)$monthItem['order_count']) ?> 单
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="dash-panel">
                <div class="panel-header">
                    <h3>年收入统计</h3>
                    <span>从最早有收入订单的年份到最新年份</span>
                </div>

                <?php if($yearlyRevenueTrend === []): ?>
                    <p class="empty-dash">暂无已完成或异常结束的订单收入数据。</p>
                <?php else: ?>
                    <div class="trend-list">
                        <?php foreach($yearlyRevenueTrend as $yearItem): ?>
                            <?php
                            $yearRevenue = (float)$yearItem['revenue'];
                            $yearPercent = $maxYearlyRevenue <= 0 ? 0 : (int)round($yearRevenue / $maxYearlyRevenue * 100);
                            ?>
                            <div class="trend-row">
                                <span class="trend-date"><?= View::escape($yearItem['label']) ?></span>

                                <div class="trend-track">
                                    <div class="trend-bar" style="width: <?= View::escape((string)$yearPercent) ?>%;"></div>
                                </div>

                                <span class="trend-value">
                                    ¥<?= View::escape(number_format($yearRevenue, 2)) ?>
                                    / <?= View::escape((string)$yearItem['order_count']) ?> 单
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <section class="dash-panel">
            <div class="panel-header">
                <h3>站点收入排行</h3>
                <span>收入最高的前 5 个站点</span>
            </div>

            <?php if($topLocations === []): ?>
                <p class="empty-dash">暂无已完成或异常结束的订单收入数据。</p>
            <?php else: ?>
                <div class="ranking-list">
                    <?php foreach($topLocations as $index => $locationItem): ?>
                        <?php
                        $locationRevenue = (float)$locationItem['revenue'];
                        $locationPercent = $maxLocationRevenue <= 0 ? 0 : (int)round($locationRevenue / $maxLocationRevenue * 100);
                        ?>
                        <div class="ranking-row">
                            <span class="rank-no"><?= View::escape((string)($index + 1)) ?></span>

                            <div class="ranking-main">
                                <div class="rank-title">
                                    <?= View::escape($locationItem['location_name']) ?>
                                    <span><?= View::escape($locationItem['location_code']) ?></span>
                                </div>

                                <div class="ranking-track">
                                    <div class="ranking-bar" style="width: <?= View::escape((string)$locationPercent) ?>%;"></div>
                                </div>
                            </div>

                            <div class="ranking-value">
                                <strong>¥<?= View::escape(number_format($locationRevenue, 2)) ?></strong>
                                <span><?= View::escape((string)$locationItem['order_count']) ?> 单</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>