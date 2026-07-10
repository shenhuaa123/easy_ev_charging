<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$allowedStatuses = [
    'active',
    'maintenance',
    'inactive',
];

$keyword = trim((string)($_GET['keyword'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($status !== '' && !in_array($status, $allowedStatuses, true)){
    $filterErrors[] = '站点状态筛选值不合法。';
    $status = '';
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
];

$totalLocations = $locationRepository->countAdminList($filters);
$totalPages = max(1, (int)ceil($totalLocations / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$locationItems = $locationRepository->searchAdminList(
    $filters,
    $pageSize,
    $offset
);

$hasActiveFilters = $keyword !== '' || $status !== '';

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($status !== ''){
    $queryParameters['status'] = $status;
}

$paginationPath = 'index.php';
$paginationAriaLabel = '站点分页';
$paginationTotal = $totalLocations;
$paginationUnit = '个站点';

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
    <title>充电站点管理｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1400px;
        }

        .filter-grid {
            grid-template-columns: minmax(260px, 2fr) minmax(180px, 1fr);
        }

        table {
            min-width: 1100px;
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
            <div>
                <h2 class="page-title">充电站点管理</h2>
                <p class="page-description">
                    查看和维护系统中的全部充电站点。
                </p>
            </div>

            <a class="primary-button" href="create.php">新增充电站点</a>
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
            <form method="get" action="index.php">
                <div class="filter-grid">
                    <div>
                        <label class="filter-label" for="keyword">搜索关键字</label>

                        <input
                            class="filter-control"
                            type="search"
                            id="keyword"
                            name="keyword"
                            value="<?= View::escape($keyword) ?>"
                            maxlength="100"
                            placeholder="站点编号、名称、省市区或详细地址"
                        >
                    </div>

                    <div>
                        <label class="filter-label" for="status">站点状态</label>

                        <select class="filter-control" id="status" name="status">
                            <option value="">全部状态</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>
                                运营中
                            </option>
                            <option value="maintenance" <?= $status === 'maintenance' ? 'selected' : '' ?>>
                                维护中
                            </option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>
                                已停用
                            </option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="filter-button" type="submit">搜索站点</button>

                    <?php if($hasActiveFilters): ?>
                        <a class="reset-button" href="index.php">重置筛选</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <p class="result-summary">
            当前共找到 <?= View::escape((string)$totalLocations) ?> 个充电站点。
        </p>

        <section class="table-card">
            <?php if($locationItems === []): ?>
                <div class="empty-state">
                    <?php if($hasActiveFilters): ?>
                        当前筛选条件下没有符合要求的充电站点。

                        <br><br>

                        <a class="reset-button" href="index.php">重置筛选</a>
                    <?php else: ?>
                        当前还没有充电站点，请先新增站点。
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>数据库编号</th>
                            <th>站点编号</th>
                            <th>站点名称</th>
                            <th>完整地址</th>
                            <th>状态</th>
                            <th>充电桩数量</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($locationItems as $locationItem): ?>
                            <?php
                            $location = $locationItem['location'];
                            $locationId = $location->getLocationId();
                            $stationCount = $locationItem['station_count'];
                            ?>

                            <tr>
                                <td>
                                    <?= View::escape((string) ($locationId ?? '')) ?>
                                </td>

                                <td>
                                    <?= View::escape($location->getLocationCode()) ?>
                                </td>

                                <td>
                                    <?= View::escape($location->getLocationName()) ?>
                                </td>

                                <td>
                                    <?= View::escape($location->getFullAddress()) ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($location->getStatus())
                                    ) ?>">
                                        <?= View::escape($location->getStatusLabel()) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= View::escape((string) $stationCount) ?>
                                </td>

                                <td>
                                    <?= View::escape($location->getCreatedAt()) ?>
                                </td>

                                <td>
                                    <div class="action-group">
                                        <a
                                            class="action-link"
                                            href="detail.php?id=<?= View::escape((string) $locationId) ?>"
                                        >
                                            查看详情
                                        </a>

                                        <a
                                            class="action-link"
                                            href="edit.php?id=<?= View::escape((string)$locationId) ?>"
                                        >
                                            编辑资料
                                        </a>

                                        <a
                                            class="action-link"
                                            href="status.php?id=<?= View::escape((string) $locationId) ?>"
                                        >
                                            修改状态
                                        </a>

                                        <a
                                            class="action-link"
                                            href="../stations/index.php?location_id=<?= View::escape((string) $locationId) ?>"
                                        >
                                            查看充电桩
                                        </a>
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