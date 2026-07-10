<?php

declare(strict_types=1);

use App\Core\AuthGuard;
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

$currentUser = $authGuard->requireUser(
    '../../login.php',
    '../../admin/dashboard.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', '../../login.php');
}

$allowedStatuses = [
    'charging',
    'completed',
    'abnormal',
    'cancelled',
];

$status = trim((string)($_GET['status'] ?? ''));

$locationId = filter_input(
    INPUT_GET,
    'location_id',
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

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$filterErrors = [];

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

$locationOptions = $recordRepository->getUserHistoryLocationOptions($currentUserId);
$availableLocationIds = [];

foreach($locationOptions as $locationOption){
    $availableLocationIds[] = (int)$locationOption['location_id'];
}

if($locationId > 0 && !in_array($locationId, $availableLocationIds, true)){
    $filterErrors[] = '充电站点筛选条件不合法。';
    $locationId = 0;
}

$filters = [
    'status' => $status,
    'location_id' => $locationId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$hasActiveFilters = $status !== ''
    || $locationId > 0
    || $dateFrom !== ''
    || $dateTo !== '';

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

$summary = $recordRepository->getUserHistorySummary(
    $currentUserId,
    $filters
);

$totalRecords = $summary['total_records'];
$totalCompletedOrders = $summary['settled_count'];
$totalBillableMinutes = $summary['total_billable_minutes'];
$totalCost = (float)$summary['total_cost'];
$hasActiveUserRecord = $summary['active_count'] > 0;

$totalPages = max(1, (int)ceil($totalRecords / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$recordItems = $recordRepository->findUserHistoryItems(
    $currentUserId,
    $pageSize,
    $offset,
    $filters
);

$queryParameters = [];

if($status !== ''){
    $queryParameters['status'] = $status;
}

if($locationId > 0){
    $queryParameters['location_id'] = (string)$locationId;
}

if($dateFrom !== ''){
    $queryParameters['date_from'] = $dateFrom;
}

if($dateTo !== ''){
    $queryParameters['date_to'] = $dateTo;
}

$paginationPath = 'history.php';
$paginationAriaLabel = '充电记录分页';
$paginationTotal = $totalRecords;
$paginationUnit = '笔记录';

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回用户中心',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
    ],
];

$flashMessages = $session->getFlashMessages();

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>充电记录｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1400px;
        }

        .filter-grid {
            grid-template-columns:
                minmax(170px, 1fr)
                minmax(220px, 1.5fr)
                minmax(160px, 1fr)
                minmax(160px, 1fr);
            align-items: end;
        }

        .filter-card {
            margin-bottom: 24px;
        }

        .summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }

        .summary-card {
            padding: 20px;
        }

        .summary-label {
            margin-bottom: 7px;
        }

        .summary-value {
            font-size: 23px;
        }

        .current-order-notice {
            margin-bottom: 22px;
            padding: 15px 17px;
            border: 1px solid #81c784;
            border-radius: 8px;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .current-order-notice a {
            color: #1b5e20;
            font-weight: bold;
        }

        table {
            min-width: 1540px;
        }

        .remark-cell {
            max-width: 260px;
            white-space: normal;
            word-break: break-word;
        }

        @media(max-width: 900px){
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width: 760px){
            .filter-grid,
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">我的充电记录</h2>
                <p class="page-description">
                    查看全部进行中、已完成、异常结束和已取消的充电订单。
                </p>
            </div>

            <div class="header-actions">
                <a class="primary-button" href="../locations/index.php">
                    查看充电站点
                </a>

                <a class="secondary-button" href="current.php">
                    查看当前充电
                </a>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if($filterErrors !== []): ?>
            <ul class="filter-error-list">
                <?php foreach($filterErrors as $filterError): ?>
                    <li><?= View::escape($filterError) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <section class="filter-card">
            <form id="history-filter-form" method="get" action="history.php">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="status">
                            订单状态
                        </label>

                        <select class="filter-control" id="status" name="status">
                            <option value="">全部状态</option>
                            <option value="charging" <?= $status === 'charging' ? 'selected' : '' ?>>充电中</option>
                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>已完成</option>
                            <option value="abnormal" <?= $status === 'abnormal' ? 'selected' : '' ?>>异常结束</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>已取消</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="location_id">
                            充电站点
                        </label>

                        <select class="filter-control" id="location_id" name="location_id">
                            <option value="">全部站点</option>

                            <?php foreach($locationOptions as $locationOption): ?>
                                <option
                                    value="<?= View::escape((string)$locationOption['location_id']) ?>"
                                    <?= $locationId === (int)$locationOption['location_id'] ? 'selected' : '' ?>
                                >
                                    <?= View::escape($locationOption['location_name']) ?>
                                </option>
                            <?php endforeach; ?>
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
                    form="history-filter-form"
                >
                    筛选记录
                </button>

                <?php if($hasActiveFilters): ?>
                    <a class="reset-button" href="history.php">
                        重置筛选
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">已结算订单数量</div>
                <p class="summary-value">
                    <?= View::escape((string) $totalCompletedOrders) ?> 笔
                </p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计计费时长</div>
                <p class="summary-value">
                    <?= View::escape(View::formatMinutes($totalBillableMinutes)) ?>
                </p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计结算金额</div>
                <p class="summary-value">
                    ￥<?= View::escape(number_format($totalCost, 2, '.', '')) ?>
                </p>
            </article>
        </section>

        <?php if($hasActiveUserRecord): ?>
            <div class="current-order-notice">
                您当前仍有一笔正在进行的充电订单。
                <a href="current.php">查看当前充电</a>
            </div>
        <?php endif; ?>

        <section class="table-card">
            <?php if($recordItems === []): ?>
                <div class="empty-state">
                    <?php if($hasActiveFilters): ?>
                        当前筛选条件下没有符合要求的充电记录。

                        <br><br>

                        <a class="reset-button" href="history.php">
                            重置筛选
                        </a>
                    <?php else: ?>
                        当前还没有充电记录。
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>订单编号</th>
                            <th>充电站点</th>
                            <th>充电桩</th>
                            <th>开始时间</th>
                            <th>结束时间</th>
                            <th>计费时长</th>
                            <th>费率快照</th>
                            <th>最终费用</th>
                            <th>订单状态</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($recordItems as $recordItem): ?>
                            <?php
                            $record = $recordItem['record'];
                            $station = $recordItem['station'];
                            $location = $recordItem['location'];
                            ?>

                            <tr>
                                <td>
                                    <?= View::escape($record->getOrderNumber()) ?>
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
                                        <?= View::escape($station['station_name']) ?>
                                        <br>
                                        <small>
                                            <?= View::escape($station['station_code']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="missing-data">充电桩资料不存在</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= View::escape($record->getCheckInAt()) ?>
                                </td>

                                <td>
                                    <?= View::escape(
                                        $record->getCheckOutAt() ?? '尚未结束'
                                    ) ?>
                                </td>

                                <td>
                                    <?= View::escape($record->getBillableMinutesLabel()) ?>
                                </td>

                                <td>
                                    <?= View::escape($record->getHourlyRateSnapshotLabel()) ?>
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
                                            class="action-link primary"
                                            href="detail.php?id=<?= View::escape(
                                                (string)$record->getChargeRecordId()
                                            ) ?>"
                                        >
                                            查看详情
                                        </a>

                                        <?php if(($record->isCompleted() || $record->isAbnormal()) && $location !== null): ?>
                                            <a
                                                class="action-link review"
                                                href="../locations/review.php?id=<?= View::escape(
                                                    (string)$location['location_id']
                                                ) ?>"
                                            >
                                                评价站点
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