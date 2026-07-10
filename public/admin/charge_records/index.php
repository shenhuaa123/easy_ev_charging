<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$allowedStatuses = [
    'charging',
    'completed',
    'abnormal',
    'cancelled',
];

$keyword = trim((string)($_GET['keyword'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($status !== '' && !in_array($status, $allowedStatuses, true)){
    $filterErrors[] = '订单状态筛选值不合法。';
    $status = '';
}

if($dateFrom !== '' && !Validator::isDateInput($dateFrom)){
    $filterErrors[] = '开始日期格式不正确。';
    $dateFrom = '';
}

if($dateTo !== '' && !Validator::isDateInput($dateTo)){
    $filterErrors[] = '结束日期格式不正确。';
    $dateTo = '';
}

if($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo){
    $filterErrors[] = '开始日期不能晚于结束日期。';
    $dateFrom = '';
    $dateTo = '';
}

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($currentPage === false || $currentPage === null){
    $currentPage = 1;
}

$pageSize = 20;

$filters = [
    'keyword' => $keyword,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$summary = $recordRepository->getSummaryWithFilters($filters);

$totalRecords = $summary['total_count'];
$chargingCount = $summary['charging_count'];
$completedCount = $summary['completed_count'];
$abnormalCount = $summary['abnormal_count'];
$cancelledCount = $summary['cancelled_count'];
$totalRevenue = (float)$summary['total_revenue'];

$totalPages = max(1, (int)ceil($totalRecords / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$recordItems = $recordRepository->searchListItemsWithFilters(
    $filters,
    $pageSize,
    $offset
);

$hasActiveFilters = $keyword !== ''
    || $status !== ''
    || $dateFrom !== ''
    || $dateTo !== '';

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($status !== ''){
    $queryParameters['status'] = $status;
}

if($dateFrom !== ''){
    $queryParameters['date_from'] = $dateFrom;
}

if($dateTo !== ''){
    $queryParameters['date_to'] = $dateTo;
}

$paginationPath = 'index.php';
$paginationAriaLabel = '订单分页';
$paginationTotal = $totalRecords;
$paginationUnit = '笔订单';

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>充电订单管理｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1500px;
        }

        .filter-grid {
            grid-template-columns:
                minmax(260px, 2fr)
                minmax(150px, 1fr)
                minmax(160px, 1fr)
                minmax(160px, 1fr);
            align-items: end;
        }

        .summary-grid {
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .summary-card {
            padding: 18px;
        }

        .summary-label {
            margin-bottom: 6px;
        }

        .summary-value {
            font-size: 22px;
        }

        table {
            min-width: 1650px;
        }

        .remark-cell {
            max-width: 260px;
            white-space: normal;
            word-break: break-word;
        }

        @media(max-width: 1050px){
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media(max-width: 700px){
            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">充电订单管理</h2>
            <p class="page-description">
                查看系统中全部用户的充电订单、设备、站点和结算信息。
            </p>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php require dirname(__DIR__, 3) . '/views/admin/filter_errors.php'; ?>

        <section class="filter-card">
            <form id="order-filter-form" method="get" action="index.php">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="keyword">
                            搜索关键字
                        </label>

                        <input
                            class="filter-control"
                            type="search"
                            id="keyword"
                            name="keyword"
                            value="<?= View::escape($keyword) ?>"
                            maxlength="100"
                            placeholder="订单编号、用户、手机号、站点或充电桩"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="status">
                            订单状态
                        </label>

                        <select
                            class="filter-control"
                            id="status"
                            name="status"
                        >
                            <option value="">全部状态</option>

                            <option
                                value="charging"
                                <?= $status === 'charging'
                                    ? 'selected'
                                    : '' ?>
                            >
                                充电中
                            </option>

                            <option
                                value="completed"
                                <?= $status === 'completed'
                                    ? 'selected'
                                    : '' ?>
                            >
                                已完成
                            </option>

                            <option
                                value="abnormal"
                                <?= $status === 'abnormal'
                                    ? 'selected'
                                    : '' ?>
                            >
                                异常结束
                            </option>

                            <option
                                value="cancelled"
                                <?= $status === 'cancelled'
                                    ? 'selected'
                                    : '' ?>
                            >
                                已取消
                            </option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="date_from">
                            开始日期
                        </label>

                        <input
                            class="filter-control"
                            type="date"
                            id="date_from"
                            name="date_from"
                            value="<?= View::escape($dateFrom) ?>"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="date_to">
                            结束日期
                        </label>

                        <input
                            class="filter-control"
                            type="date"
                            id="date_to"
                            name="date_to"
                            value="<?= View::escape($dateTo) ?>"
                        >
                    </div>
                </div>

            </form>

            <div class="filter-actions">
                <button
                    class="filter-button"
                    type="submit"
                    form="order-filter-form"
                >
                    搜索订单
                </button>

                <?php if($hasActiveFilters): ?>
                    <a class="reset-button" href="index.php">
                        重置筛选
                    </a>
                <?php endif; ?>

                <form class="export-form" method="post" action="export.php">
                    <input
                        type="hidden"
                        name="<?= View::escape(Csrf::FIELD_NAME) ?>"
                        value="<?= View::escape($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="keyword"
                        value="<?= View::escape($keyword) ?>"
                    >

                    <input
                        type="hidden"
                        name="status"
                        value="<?= View::escape($status) ?>"
                    >

                    <input
                        type="hidden"
                        name="date_from"
                        value="<?= View::escape($dateFrom) ?>"
                    >

                    <input
                        type="hidden"
                        name="date_to"
                        value="<?= View::escape($dateTo) ?>"
                    >

                    <button class="export-button" type="submit">
                        导出当前筛选结果
                    </button>
                </form>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">全部订单</div>
                <p class="summary-value"><?= View::escape((string) $totalRecords) ?></p>
            </article>

            <article class="summary-card">
                <div class="summary-label">充电中</div>
                <p class="summary-value"><?= View::escape((string) $chargingCount) ?></p>
            </article>

            <article class="summary-card">
                <div class="summary-label">已完成</div>
                <p class="summary-value"><?= View::escape((string) $completedCount) ?></p>
            </article>

            <article class="summary-card">
                <div class="summary-label">异常结束</div>
                <p class="summary-value"><?= View::escape((string) $abnormalCount) ?></p>
            </article>

            <article class="summary-card">
                <div class="summary-label">已取消</div>
                <p class="summary-value"><?= View::escape((string) $cancelledCount) ?></p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计结算收入</div>
                <p class="summary-value">
                    ￥<?= View::escape(number_format($totalRevenue, 2, '.', '')) ?>
                </p>
            </article>
        </section>

        <section class="table-card">
            <?php if($recordItems === []): ?>
                <?php
                $emptyMessage = '当前还没有充电订单。';
                $filteredEmptyMessage = '当前筛选条件下没有符合要求的充电订单。';
                $resetUrl = 'index.php';
                $resetLabel = '重置筛选';
                require dirname(__DIR__, 3) . '/views/admin/empty_state.php';
                ?>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>数据库主键</th>
                            <th>订单编号</th>
                            <th>用户</th>
                            <th>充电站点</th>
                            <th>充电桩</th>
                            <th>开始时间</th>
                            <th>结束时间</th>
                            <th>计费时长</th>
                            <th>费率快照</th>
                            <th>最终费用</th>
                            <th>状态</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($recordItems as $recordItem): ?>
                            <?php
                            $record = $recordItem['record'];
                            $recordUser = $recordItem['user'];
                            $station = $recordItem['station'];
                            $location = $recordItem['location'];
                            ?>

                            <tr>
                                <td>
                                    <?= View::escape((string) (
                                        $record->getChargeRecordId() ?? ''
                                    )) ?>
                                </td>

                                <td><?= View::escape($record->getOrderNumber()) ?></td>

                                <td>
                                    <?php if($recordUser !== null): ?>
                                        <?= View::escape($recordUser['real_name']) ?>
                                        <br>
                                        <small>
                                            <?= View::escape($recordUser['username']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="missing-data">用户资料不存在</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($location !== null): ?>
                                        <?= View::escape($location['location_name']) ?>
                                    <?php else: ?>
                                        <span class="missing-data">站点资料不存在</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($station !== null): ?>
                                        <a
                                            href="../stations/detail.php?id=<?= View::escape(
                                                (string)$station['station_id']
                                            ) ?>"
                                        >
                                            <?= View::escape($station['station_name']) ?>
                                        </a>
                                        <br>
                                        <small>
                                            <?= View::escape($station['station_code']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="missing-data">充电桩资料不存在</span>
                                    <?php endif; ?>
                                </td>

                                <td><?= View::escape($record->getCheckInAt()) ?></td>

                                <td>
                                    <?= View::escape(
                                        $record->getCheckOutAt() ?? '尚未结束'
                                    ) ?>
                                </td>

                                <td>
                                    <?= View::escape($record->getBillableMinutesLabel()) ?>
                                </td>

                                <td>
                                    <?= View::escape(
                                        $record->getHourlyRateSnapshotLabel()
                                    ) ?>
                                </td>

                                <td>
                                    <?= View::escape($record->getTotalCostLabel()) ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($record->getStatus())
                                    ) ?>">
                                        <?= View::escape($record->getStatusLabel()) ?>
                                    </span>
                                </td>

                                <td class="remark-cell">
                                    <?= View::escape($record->getRemark() ?? '无') ?>
                                </td>

                                <td>
                                    <div class="action-group">
                                        <a
                                            class="action-link"
                                            href="detail.php?id=<?= View::escape(
                                                (string)$record->getChargeRecordId()
                                            ) ?>"
                                        >
                                            查看详情
                                        </a>

                                        <?php if($record->isCharging()): ?>
                                            <a
                                                class="action-link danger"
                                                href="abnormal_finish.php?id=<?= View::escape(
                                                    (string)$record->getChargeRecordId()
                                                ) ?>"
                                            >
                                                异常结束
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/pagination.php'; ?>
    </main>
</body>
</html>