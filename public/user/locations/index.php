<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$reviewRepository = new LocationReviewRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireUser(
    '../../login.php',
    '../../admin/dashboard.php'
);

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

$pageSize = 12;

$totalLocations = $locationRepository->countActiveList();
$totalPages = max(1, (int)ceil($totalLocations / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$locationItems = $locationRepository->findActiveListItems(
    $pageSize,
    $offset
);

$locationIds = [];

foreach($locationItems as $locationItem){
    $location = $locationItem['location'];
    $locationId = $location->getLocationId();

    if($locationId !== null){
        $locationIds[] = $locationId;
    }
}

$ratingSummaries = $reviewRepository->getRatingSummariesByLocationIds(
    $locationIds
);

$queryParameters = [];
$paginationPath = 'index.php';
$paginationAriaLabel = '站点分页';
$paginationTotal = $totalLocations;
$paginationUnit = '个站点';

$flashMessages = $session->getFlashMessages();

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
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>充电站点｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/location_cards.css">

    <style>
        .page {
            max-width: 1200px;
        }

        .page-header {
            align-items: center;
            margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">可用充电站点</h2>
                <p class="page-description">
                    请选择正在运营的充电站点，查看当前可用充电桩。
                </p>
            </div>

            <a class="primary-button" href="../charging/current.php">
                查看当前充电
            </a>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if($locationItems === []): ?>
            <section class="empty-state">
                当前没有正在运营的充电站点，请稍后再试。
            </section>
        <?php else: ?>
            <section class="location-grid">
                <?php foreach($locationItems as $locationItem): ?>
                    <?php
                    $location = $locationItem['location'];
                    $locationId = $location->getLocationId();

                    if($locationId === null){
                        continue;
                    }

                    $locationCardLocation = $location;
                    $locationCardId = $locationId;
                    $locationCardAvailableCount = $locationItem['available_station_count'];
                    $locationCardRatingSummary = $ratingSummaries[$locationId] ?? [
                        'review_count' => 0,
                        'average_rating' => 0.0,
                    ];
                    $locationCardButtonLabel = '查看充电桩';
                    ?>

                    <?php require dirname(__DIR__, 3) . '/views/partials/location_card.php'; ?>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php require dirname(__DIR__, 3) . '/views/partials/pagination.php'; ?>
    </main>
</body>
</html>