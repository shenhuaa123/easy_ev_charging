<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
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

$allowedRatings = [
    '1',
    '2',
    '3',
    '4',
    '5',
];

$keyword = trim((string)($_GET['keyword'] ?? ''));
$ratingMinRaw = trim((string)($_GET['rating_min'] ?? ''));
$ratingMaxRaw = trim((string)($_GET['rating_max'] ?? ''));
$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($ratingMinRaw !== '' && !in_array($ratingMinRaw, $allowedRatings, true)){
    $filterErrors[] = '最低评分必须选择1到5之间的整数。';
    $ratingMinRaw = '';
}

if($ratingMaxRaw !== '' && !in_array($ratingMaxRaw, $allowedRatings, true)){
    $filterErrors[] = '最高评分必须选择1到5之间的整数。';
    $ratingMaxRaw = '';
}

$ratingMin = $ratingMinRaw === '' ? null : (int)$ratingMinRaw;
$ratingMax = $ratingMaxRaw === '' ? null : (int)$ratingMaxRaw;

if($ratingMin !== null && $ratingMax !== null && $ratingMin > $ratingMax){
    [$ratingMin, $ratingMax] = [$ratingMax, $ratingMin];
    $ratingMinRaw = (string)$ratingMin;
    $ratingMaxRaw = (string)$ratingMax;
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

$filterKeys = [
    'keyword',
    'rating_min',
    'rating_max',
];
$hasSubmitted = false;

foreach($filterKeys as $filterKey){
    if(array_key_exists($filterKey, $_GET)){
        $hasSubmitted = true;
        break;
    }
}

$hasActiveFilters = $keyword !== ''
    || $ratingMinRaw !== ''
    || $ratingMaxRaw !== '';

$pageSize = 12;
$totalLocations = 0;
$totalPages = 1;
$locationItems = [];
$ratingSummaries = [];

$filters = [
    'keyword' => $keyword,
    'rating_min' => $ratingMin,
    'rating_max' => $ratingMax,
];

if($hasSubmitted && $filterErrors === []){
    $totalLocations = $locationRepository->countActiveList($filters);
    $totalPages = max(1, (int)ceil($totalLocations / $pageSize));

    if($currentPage > $totalPages){
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $pageSize;

    $locationItems = $locationRepository->findActiveListItems(
        $pageSize,
        $offset,
        $filters
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
}

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($ratingMinRaw !== ''){
    $queryParameters['rating_min'] = $ratingMinRaw;
}

if($ratingMaxRaw !== ''){
    $queryParameters['rating_max'] = $ratingMaxRaw;
}

$paginationPath = 'search.php';
$paginationAriaLabel = '站点筛选结果分页';
$paginationTotal = $totalLocations;
$paginationUnit = '个站点';

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
    <title>查找充电站点｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">
    <link rel="stylesheet" href="../../assets/css/location_cards.css">

    <style>
        .page {
            max-width: 1200px;
        }

        .page-header {
            display: block;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">查找充电站点</h2>

            <p class="page-description">
                通过关键字和站点评分查找正在运营的充电站点。
            </p>
        </section>

        <?php if($filterErrors !== []): ?>
            <ul class="filter-error-list">
                <?php foreach($filterErrors as $filterError): ?>
                    <li><?= View::escape($filterError) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <section class="filter-card">
            <form method="get" action="search.php">
                <div class="filter-grid filter-grid-2">
                    <div class="filter-group">
                        <label class="filter-label" for="keyword">搜索关键字</label>

                        <input
                            class="filter-control"
                            type="search"
                            id="keyword"
                            name="keyword"
                            value="<?= View::escape($keyword) ?>"
                            maxlength="100"
                            placeholder="例如：朝阳、北京市、国贸、LOC-BJ"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">评分范围</label>

                        <div class="rating-range-control">
                            <select class="filter-control" name="rating_min" aria-label="最低评分">
                                <option value="">最低分</option>
                                <?php for($rating = 1; $rating <= 5; $rating++): ?>
                                    <option value="<?= $rating ?>" <?= $ratingMinRaw === (string)$rating ? 'selected' : '' ?>>
                                        <?= $rating ?>
                                    </option>
                                <?php endfor; ?>
                            </select>

                            <span class="range-separator">-</span>

                            <select class="filter-control" name="rating_max" aria-label="最高评分">
                                <option value="">最高分</option>
                                <?php for($rating = 1; $rating <= 5; $rating++): ?>
                                    <option value="<?= $rating ?>" <?= $ratingMaxRaw === (string)$rating ? 'selected' : '' ?>>
                                        <?= $rating ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <p class="filter-help">
                    评分范围说明：只选最低分表示筛选该分数到5分；只选最高分表示筛选1分到该分数；两侧都选时按区间筛选；最低分大于最高分时系统会自动交换。
                </p>

                <div class="filter-actions">
                    <button class="filter-button" type="submit">筛选站点</button>

                    <?php if($hasSubmitted || $hasActiveFilters): ?>
                        <a class="reset-button" href="search.php">重置筛选</a>
                    <?php endif; ?>

                    <a class="reset-button" href="index.php">浏览全部站点</a>
                </div>
            </form>
        </section>

        <?php if(!$hasSubmitted): ?>
            <section class="initial-state">
                <h3>请输入搜索或筛选条件</h3>

                <p>
                    可以按站点关键字或评分范围筛选运营中站点。
                </p>

                <a class="secondary-link" href="index.php">
                    浏览全部运营站点
                </a>
            </section>
        <?php elseif($filterErrors !== []): ?>
            <section class="empty-state">
                请先修正筛选条件后再查询。
            </section>
        <?php elseif($locationItems === []): ?>
            <section class="empty-state">
                <h3>没有找到符合条件的充电站点</h3>

                <p>
                    当前筛选条件下没有正在运营且符合要求的站点，请调整评分或关键字后重试。
                </p>

                <a class="secondary-link" href="index.php">
                    浏览全部运营站点
                </a>
            </section>
        <?php else: ?>
            <p class="result-summary">
                当前共找到
                <strong><?= View::escape((string)$totalLocations) ?></strong>
                个符合条件的充电站点。
            </p>

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
                    $locationCardButtonLabel = '查看站点与充电桩';
                    ?>

                    <?php require dirname(__DIR__, 3) . '/views/partials/location_card.php'; ?>
                <?php endforeach; ?>
            </section>

            <?php require dirname(__DIR__, 3) . '/views/partials/pagination.php'; ?>
        <?php endif; ?>
    </main>
</body>
</html>