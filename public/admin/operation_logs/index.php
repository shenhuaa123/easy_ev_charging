<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$logRepository = new AdminOperationLogRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$actionOptions = [
    '' => '全部操作',
    'admin_login_success' => '管理员登录',
    'admin_logout' => '管理员退出',
    'admin_profile_update' => '管理员修改个人资料',
    'admin_password_change' => '管理员修改密码',
    'user_profile_update' => '修改用户资料',
    'user_password_reset' => '重置用户密码',
    'user_status_update' => '修改用户状态',
    'location_create' => '新增站点',
    'location_update' => '编辑站点',
    'location_status_update' => '修改站点状态',
    'station_create' => '新增充电桩',
    'station_update' => '编辑充电桩',
    'station_status_update' => '修改充电桩状态',
    'charge_record_abnormal_finish' => '异常结束订单',

    'location_review_reply' => '回复站点评价',
    'location_review_status_update' => '修改评价状态',

    'charge_record_export' => '导出订单数据',
    'admin_operation_log_export' => '导出操作日志',
    'dashboard_statistics_export' => '导出统计数据',
];

$targetTypeOptions = [
    '' => '全部对象',
    'user' => '用户',
    'location' => '充电站点',
    'station' => '充电桩',
    'charge_record' => '充电订单',
    'location_review' => '站点评价',
    'admin_operation_log' => '操作日志',
    'dashboard_statistics' => '统计数据',
];

$resultOptions = [
    '' => '全部结果',
    'success' => '成功',
    'failure' => '失败',
];

$keyword = trim((string)($_GET['keyword'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$targetType = trim((string)($_GET['target_type'] ?? ''));
$resultValue = trim((string)($_GET['result'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$filterErrors = [];

if($keyword !== '' && mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if(!array_key_exists($action, $actionOptions)){
    $filterErrors[] = '操作类型筛选条件不合法。';
    $action = '';
}

if(!array_key_exists($targetType, $targetTypeOptions)){
    $filterErrors[] = '对象类型筛选条件不合法。';
    $targetType = '';
}

if(!array_key_exists($resultValue, $resultOptions)){
    $filterErrors[] = '操作结果筛选条件不合法。';
    $resultValue = '';
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
    'action' => $action,
    'target_type' => $targetType,
    'result' => $resultValue,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$totalLogs = $logRepository->countListItems($filters);
$totalPages = max(1, (int)ceil($totalLogs / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$logItems = $logRepository->searchListItems($filters, $pageSize, $offset);

$hasActiveFilters = $keyword !== ''
    || $action !== ''
    || $targetType !== ''
    || $resultValue !== ''
    || $dateFrom !== ''
    || $dateTo !== '';

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($action !== ''){
    $queryParameters['action'] = $action;
}

if($targetType !== ''){
    $queryParameters['target_type'] = $targetType;
}

if($resultValue !== ''){
    $queryParameters['result'] = $resultValue;
}

if($dateFrom !== ''){
    $queryParameters['date_from'] = $dateFrom;
}

if($dateTo !== ''){
    $queryParameters['date_to'] = $dateTo;
}

$paginationPath = 'index.php';
$paginationAriaLabel = '管理员操作日志分页';
$paginationTotal = $totalLogs;
$paginationUnit = '条日志';

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
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>管理员操作日志｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1500px;
        }

        .operation-log-filter-grid {
            grid-template-columns: minmax(240px, 2fr) minmax(170px, 1fr) minmax(160px, 1fr) minmax(150px, 1fr) minmax(150px, 1fr) minmax(150px, 1fr);
        }

        table {
            min-width: 1300px;
        }

        .detail-cell {
            max-width: 380px;
            white-space: normal;
            word-break: break-word;
        }

        @media(max-width: 1100px){
            .operation-log-filter-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media(max-width: 700px){
            .operation-log-filter-grid {
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
                <h2 class="page-title">管理员操作日志</h2>
                <p class="page-description">
                    查看管理员登录、退出以及关键管理操作的审计记录。
                </p>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php require dirname(__DIR__, 3) . '/views/admin/filter_errors.php'; ?>

        <section class="filter-card">
            <form id="operation-log-filter-form" method="get" action="index.php">
                <div class="filter-grid operation-log-filter-grid">
                    <div class="filter-group">
                        <label class="filter-label" for="keyword">搜索关键字</label>

                        <input
                            class="filter-control"
                            type="text"
                            id="keyword"
                            name="keyword"
                            value="<?= View::escape($keyword) ?>"
                            maxlength="100"
                            placeholder="操作、详情、IP、用户名"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="action">操作类型</label>

                        <select class="filter-control" id="action" name="action">
                            <?php foreach($actionOptions as $optionValue => $optionLabel): ?>
                                <option value="<?= View::escape($optionValue) ?>" <?= $action === $optionValue ? 'selected' : '' ?>>
                                    <?= View::escape($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="target_type">对象类型</label>

                        <select class="filter-control" id="target_type" name="target_type">
                            <?php foreach($targetTypeOptions as $optionValue => $optionLabel): ?>
                                <option value="<?= View::escape($optionValue) ?>" <?= $targetType === $optionValue ? 'selected' : '' ?>>
                                    <?= View::escape($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="result">操作结果</label>

                        <select class="filter-control" id="result" name="result">
                            <?php foreach($resultOptions as $optionValue => $optionLabel): ?>
                                <option value="<?= View::escape($optionValue) ?>" <?= $resultValue === $optionValue ? 'selected' : '' ?>>
                                    <?= View::escape($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="date_from">开始日期</label>

                        <input
                            class="filter-control"
                            type="date"
                            id="date_from"
                            name="date_from"
                            value="<?= View::escape($dateFrom) ?>"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="date_to">结束日期</label>

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
                    form="operation-log-filter-form"
                >
                    搜索日志
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
                        name="action"
                        value="<?= View::escape($action) ?>"
                    >

                    <input
                        type="hidden"
                        name="target_type"
                        value="<?= View::escape($targetType) ?>"
                    >

                    <input
                        type="hidden"
                        name="result"
                        value="<?= View::escape($resultValue) ?>"
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
                        导出当前筛选日志
                    </button>
                </form>
            </div>
        </section>

        <p class="result-summary">
            共找到 <?= View::escape((string)$totalLogs) ?> 条管理员操作日志。
        </p>

        <section class="table-card">
            <?php if($logItems === []): ?>
                <?php
                $emptyMessage = '当前还没有管理员操作日志。';
                $filteredEmptyMessage = '当前筛选条件下没有符合要求的管理员操作日志。';
                $resetUrl = 'index.php';
                $resetLabel = '重置筛选';
                require dirname(__DIR__, 3) . '/views/admin/empty_state.php';
                ?>
            <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>管理员</th>
                                <th>操作</th>
                                <th>对象</th>
                                <th>对象编号</th>
                                <th>结果</th>
                                <th>详情</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($logItems as $logItem): ?>
                                <?php
                                $log = $logItem['log'];
                                $operator = $logItem['operator'];
                                ?>
                                <tr>
                                    <td><?= View::escape($log->getCreatedAt()) ?></td>

                                    <td>
                                        <?php if($operator === null): ?>
                                            用户 #<?= View::escape((string)$log->getOperatorUserId()) ?>
                                        <?php else: ?>
                                            <?= View::escape($operator['real_name']) ?>
                                            <br>
                                            <span class="muted-text">
                                                <?= View::escape($operator['username']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= View::escape($log->getActionLabel()) ?></td>

                                    <td><?= View::escape($log->getTargetTypeLabel()) ?></td>

                                    <td>
                                        <?= $log->getTargetId() === null
                                            ? '无'
                                            : View::escape((string)$log->getTargetId()) ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?= $log->getResult() === 'success' ? 'status-active' : 'status-disabled' ?>">
                                            <?= View::escape($log->getResultLabel()) ?>
                                        </span>
                                    </td>

                                    <td class="detail-cell">
                                        <?= View::escape($log->getDetail() ?? '无') ?>
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