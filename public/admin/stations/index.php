<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);

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
$locationIdValue = trim((string)($_GET['location_id'] ?? ''));
$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($status !== '' && !in_array($status, $allowedStatuses, true)){
    $filterErrors[] = '充电桩状态筛选值不合法。';
    $status = '';
}

$allLocations = $locationRepository->findAll();
$locationId = null;
$selectedLocation = null;

if($locationIdValue !== ''){
    $validatedLocationId = filter_var(
        $locationIdValue,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if($validatedLocationId === false){
        $filterErrors[] = '所属站点筛选值不合法。';
    }else{
        $locationId = (int)$validatedLocationId;

        foreach($allLocations as $availableLocation){
            if($availableLocation->getLocationId() === $locationId){
                $selectedLocation = $availableLocation;
                break;
            }
        }

        if($selectedLocation === null){
            $filterErrors[] = '未找到指定的充电站点。';
            $locationId = null;
        }
    }
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
    'location_id' => $locationId,
];

$totalStations = $stationRepository->countAdminList($filters);
$totalPages = max(1, (int)ceil($totalStations / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$stationItems = $stationRepository->searchAdminList(
    $filters,
    $pageSize,
    $offset
);

$hasActiveFilters = $keyword !== '' || $status !== '' || $locationId !== null;

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($status !== ''){
    $queryParameters['status'] = $status;
}

if($locationId !== null){
    $queryParameters['location_id'] = $locationId;
}

$paginationPath = 'index.php';
$paginationAriaLabel = '充电桩分页';
$paginationTotal = $totalStations;
$paginationUnit = '台充电桩';

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

function getStatusLabel(?string $status): string
{
    return match($status){
        'active' => '可用',
        'maintenance' => '维护中',
        'inactive' => '已停用',
        default => '未知',
    };
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>充电桩管理｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1400px;
        }

        .filter-grid {
            grid-template-columns:
                minmax(240px, 2fr)
                minmax(160px, 1fr)
                minmax(200px, 1fr);
        }

        .filter-card p {
            margin: 4px 0;
        }

        table {
            min-width: 1300px;
        }

        @media(max-width: 760px){
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
                <h2 class="page-title">
                    <?= $selectedLocation === null
                        ? '全部充电桩'
                        : View::escape($selectedLocation->getLocationName() . '——充电桩列表') ?>
                </h2>

                <p class="page-description">
                    查看充电桩所属站点、充电类型、功率、收费标准和设备状态。
                </p>
            </div>

            <div class="header-actions">
                <a
                    class="primary-button"
                    href="create.php<?= $locationId === null
                        ? ''
                        : '?location_id=' . View::escape((string) $locationId) ?>"
                >
                    新增充电桩
                </a>

                <?php if($selectedLocation !== null): ?>
                    <a
                        class="secondary-button"
                        href="../locations/detail.php?id=<?= View::escape((string) $locationId) ?>"
                    >
                        返回站点详情
                    </a>

                    <a class="secondary-button" href="index.php">
                        查看全部充电桩
                    </a>
                <?php endif; ?>
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
                            placeholder="设备编号、名称、站点编号或站点名称"
                        >
                    </div>

                    <div>
                        <label class="filter-label" for="status">设备状态</label>

                        <select class="filter-control" id="status" name="status">
                            <option value="">全部状态</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>
                                可用
                            </option>
                            <option value="maintenance" <?= $status === 'maintenance' ? 'selected' : '' ?>>
                                维护中
                            </option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>
                                已停用
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="filter-label" for="location_id">所属站点</label>

                        <select class="filter-control" id="location_id" name="location_id">
                            <option value="">全部站点</option>

                            <?php foreach($allLocations as $availableLocation): ?>
                                <?php $availableLocationId = $availableLocation->getLocationId(); ?>

                                <?php if($availableLocationId !== null): ?>
                                    <option
                                        value="<?= View::escape((string)$availableLocationId) ?>"
                                        <?= $locationId === $availableLocationId ? 'selected' : '' ?>
                                    >
                                        <?= View::escape(
                                            $availableLocation->getLocationName()
                                            . '（'
                                            . $availableLocation->getStatusLabel()
                                            . '）'
                                        ) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="filter-button" type="submit">搜索充电桩</button>

                    <?php if($hasActiveFilters): ?>
                        <a class="reset-button" href="index.php">重置筛选</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <p class="result-summary">
            当前共找到 <?= View::escape((string)$totalStations) ?> 台充电桩。
        </p>

        <?php if($selectedLocation !== null): ?>
            <section class="filter-card">
                <p>
                    <strong>当前站点：</strong>
                    <?= View::escape($selectedLocation->getLocationName()) ?>
                </p>

                <p>
                    <strong>站点编号：</strong>
                    <?= View::escape($selectedLocation->getLocationCode()) ?>
                </p>

                <p>
                    <strong>站点状态：</strong>
                    <?= View::escape($selectedLocation->getStatusLabel()) ?>
                </p>
            </section>
        <?php endif; ?>

        <section class="table-card">
            <?php if($stationItems === []): ?>
                <div class="empty-state">
                    <?php if($hasActiveFilters): ?>
                        当前筛选条件下没有符合要求的充电桩。

                        <br><br>

                        <a class="reset-button" href="index.php">重置筛选</a>
                    <?php else: ?>
                        当前还没有充电桩。
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>数据库编号</th>
                            <th>充电桩编号</th>
                            <th>充电桩名称</th>
                            <th>所属站点</th>
                            <th>站点状态</th>
                            <th>充电类型</th>
                            <th>功率</th>
                            <th>每小时费用</th>
                            <th>设备状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($stationItems as $stationItem): ?>
                            <?php
                            $station = $stationItem['station'];
                            $stationId = $station->getStationId();
                            $stationLocationId = $stationItem['location_id'];
                            $stationLocationName = $stationItem['location_name'];
                            $stationLocationStatus = $stationItem['location_status'];
                            ?>

                            <tr>
                                <td><?= View::escape((string) ($stationId ?? '')) ?></td>
                                <td><?= View::escape($station->getStationCode()) ?></td>
                                <td><?= View::escape($station->getStationName()) ?></td>

                                <td>
                                    <?php if($stationLocationId !== null && $stationLocationName !== null): ?>
                                        <a href="../locations/detail.php?id=<?= View::escape((string)$stationLocationId) ?>">
                                            <?= View::escape($stationLocationName) ?>
                                        </a>
                                    <?php else: ?>
                                        <span>所属站点不存在</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($stationLocationStatus ?? '')
                                    ) ?>">
                                        <?= View::escape(getStatusLabel($stationLocationStatus)) ?>
                                    </span>
                                </td>

                                <td><?= View::escape($station->getChargerTypeLabel()) ?></td>
                                <td><?= View::escape($station->getPowerKwLabel()) ?></td>
                                <td><?= View::escape($station->getHourlyRateLabel()) ?></td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($station->getStatus())
                                    ) ?>">
                                        <?= View::escape($station->getStatusLabel()) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-group">
                                        <a
                                            class="action-link"
                                            href="detail.php?id=<?= View::escape((string) $stationId) ?>"
                                        >
                                            查看详情
                                        </a>

                                        <a class="action-link" 
                                            href="edit.php?id=<?= View::escape((string)$stationId) ?>">
                                            编辑资料
                                        </a>
                                        
                                        <a
                                            class="action-link"
                                            href="status.php?id=<?= View::escape((string) $stationId) ?>"
                                        >
                                            修改状态
                                        </a>

                                        <?php if($stationLocationId !== null): ?>
                                            <a
                                                class="action-link"
                                                href="../locations/detail.php?id=<?= View::escape((string)$stationLocationId) ?>"
                                            >
                                                查看站点
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