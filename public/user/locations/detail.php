<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Models\LocationReview;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;
use App\Services\LocationReviewService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);
$reviewRepository = new LocationReviewRepository($connection);

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

$locationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($locationId === false || $locationId === null){
    $session->setFlash('error', '充电站点编号不合法。');
    header('Location: index.php');
    exit;
}

$location = $locationRepository->findById($locationId);

if($location === null){
    $session->setFlash('error', '未找到指定的充电站点。');
    header('Location: index.php');
    exit;
}

if(!$location->isActive()){
    $session->setFlash('error', '该充电站点当前未运营，暂时不能使用。');
    header('Location: index.php');
    exit;
}

$allowedChargerTypes = [
    'ac',
    'dc',
];
$chargerType = trim((string)($_GET['charger_type'] ?? ''));
$stationFilterError = '';

if($chargerType !== '' && !in_array($chargerType, $allowedChargerTypes, true)){
    $stationFilterError = '充电类型筛选条件不合法。';
    $chargerType = '';
}

$stationItems = $stationRepository->findAvailabilityItemsByLocationId(
    $locationId,
    $chargerType
);
$hasActiveUserRecord = $recordRepository->hasActiveByUserId($currentUserId);

$reviewService = new LocationReviewService(
    $connection,
    $reviewRepository,
    $locationRepository,
    $userRepository
);

$reviewContext = $reviewService->getUserReviewContext(
    $currentUserId,
    $locationId
);

$userLocationReview = $reviewContext['review'] ?? null;
$canReviewLocation = (bool)($reviewContext['can_review'] ?? false);
$showReviewEntry = $canReviewLocation
    || $userLocationReview instanceof LocationReview;

$reviewPage = filter_input(
    INPUT_GET,
    'review_page',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($reviewPage === false || $reviewPage === null){
    $reviewPage = 1;
}

$reviewPageSize = 5;

$reviewSummary = $reviewRepository->getLocationRatingSummary($locationId);
$totalVisibleReviewCount = $reviewRepository->countVisibleByLocation($locationId);
$totalReviewPages = max(1, (int)ceil($totalVisibleReviewCount / $reviewPageSize));

if($reviewPage > $totalReviewPages){
    $reviewPage = $totalReviewPages;
}

$reviewOffset = ($reviewPage - 1) * $reviewPageSize;

$visibleReviewItems = $reviewRepository->findVisibleListByLocation(
    $locationId,
    $reviewPageSize,
    $reviewOffset
);

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $currentUser->getRealName();

$flashMessages = $session->getFlashMessages();

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

function getStationAvailability(
    string $stationStatus,
    bool $hasActiveRecord
): array {
    if($stationStatus === 'maintenance'){
        return [
            'available' => false,
            'label' => '维护中',
            'class' => 'status-maintenance',
        ];
    }

    if($stationStatus === 'inactive'){
        return [
            'available' => false,
            'label' => '已停用',
            'class' => 'status-inactive',
        ];
    }

    if($stationStatus !== 'active'){
        return [
            'available' => false,
            'label' => '状态异常',
            'class' => 'status-unknown',
        ];
    }

    if($hasActiveRecord){
        return [
            'available' => false,
            'label' => '使用中',
            'class' => 'status-busy',
        ];
    }

    return [
        'available' => true,
        'label' => '可使用',
        'class' => 'status-available',
    ];
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>站点详情｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">
    <link rel="stylesheet" href="../../assets/css/reviews.css">

    <style>
        .page {
            max-width: 1200px;
        }

        .page-header {
            align-items: center;
        }

        .location-card {
            margin-bottom: 24px;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        .location-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 28px;
        }

        .location-item.full-width {
            grid-column: 1 / -1;
        }

        .label {
            margin-bottom: 5px;
            color: #78909c;
            font-size: 14px;
        }

        .value {
            margin: 0;
            font-weight: bold;
            word-break: break-word;
        }

        .notice {
            margin-bottom: 22px;
            padding: 14px 16px;
            border-radius: 8px;
            background: #e3f2fd;
            color: #1565c0;
        }

        .notice-warning {
            border: 1px solid #ffcc80;
            background: #fff8e1;
            color: #e65100;
        }

        .station-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .station-card {
            display: flex;
            flex-direction: column;
            padding: 22px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        .station-name {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .station-code {
            margin: 0 0 15px;
            color: #78909c;
            font-size: 14px;
        }

        .station-info {
            flex: 1;
        }

        .station-info p {
            margin: 9px 0;
        }

        .status-available {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-busy,
        .status-unknown {
            background: #ffebee;
            color: #c62828;
        }

        .status-maintenance {
            background: #fff3e0;
            color: #ef6c00;
        }

        .status-inactive {
            background: #eceff1;
            color: #546e7a;
        }

        .start-button,
        .disabled-button {
            display: block;
            margin-top: 18px;
            padding: 10px 15px;
            border-radius: 7px;
            text-align: center;
            text-decoration: none;
            font-weight: bold;
        }

        .disabled-button {
            background: #eceff1;
            color: #90a4ae;
            cursor: not-allowed;
        }

        .empty-state {
            padding: 45px 20px;
            border-radius: 12px;
            background: #fff;
            color: #78909c;
            text-align: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        @media(max-width: 760px){
            .location-grid,
            .station-grid {
                grid-template-columns: 1fr;
            }

            .location-item.full-width {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title"><?= View::escape($location->getLocationName()) ?></h2>
                <p class="page-description">请选择当前可用的充电桩开始充电。</p>
            </div>

            <div class="header-actions">
                <?php if($showReviewEntry): ?>
                    <a
                        class="primary-button"
                        href="review.php?id=<?= View::escape((string)$locationId) ?>"
                    >
                        <?= $userLocationReview instanceof LocationReview ? '修改站点评价' : '评价该站点' ?>
                    </a>
                <?php endif; ?>

                <a class="secondary-button" href="index.php">
                    返回站点列表
                </a>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if(!$showReviewEntry): ?>
            <div class="review-note">
                完成该站点的充电订单后，可以对该站点进行星级评分和评论。
            </div>
        <?php endif; ?>

        <section class="location-card">
            <div class="location-grid">
                <div class="location-item">
                    <div class="label">站点编号</div>
                    <p class="value"><?= View::escape($location->getLocationCode()) ?></p>
                </div>

                <div class="location-item">
                    <div class="label">站点状态</div>
                    <p class="value"><?= View::escape($location->getStatusLabel()) ?></p>
                </div>

                <div class="location-item full-width">
                    <div class="label">完整地址</div>
                    <p class="value"><?= View::escape($location->getFullAddress()) ?></p>
                </div>

                <?php if($location->getDescription() !== null): ?>
                    <div class="location-item full-width">
                        <div class="label">站点说明</div>
                        <p class="value"><?= View::escape($location->getDescription()) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if($stationFilterError !== ''): ?>
            <div class="notice notice-warning">
                <?= View::escape($stationFilterError) ?>
            </div>
        <?php endif; ?>

        <section class="filter-card">
            <form method="get" action="detail.php">
                <input type="hidden" name="id" value="<?= View::escape((string)$locationId) ?>">

                <div class="filter-grid filter-grid-2">
                    <div class="filter-group">
                        <label class="filter-label" for="charger_type">充电类型</label>

                        <select class="filter-control" id="charger_type" name="charger_type">
                            <option value="">全部充电桩</option>
                            <option value="ac" <?= $chargerType === 'ac' ? 'selected' : '' ?>>交流充电桩</option>
                            <option value="dc" <?= $chargerType === 'dc' ? 'selected' : '' ?>>直流充电桩</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="filter-button" type="submit">
                        筛选充电桩
                    </button>

                    <?php if($chargerType !== ''): ?>
                        <a class="reset-button" href="detail.php?id=<?= View::escape((string)$locationId) ?>">
                            重置筛选
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <p class="filter-help">
                充电类型筛选只影响当前站点下方的充电桩列表，不影响站点本身。
            </p>
        </section>

        <?php if($hasActiveUserRecord): ?>
            <div class="notice notice-warning">
                您当前已有一笔正在进行的充电订单，不能再次开始充电。
                请先前往“当前充电”页面结束现有订单。
            </div>
        <?php else: ?>
            <div class="notice">
                计费规则：按实际使用秒数向上取整到分钟，最低计费1分钟。
            </div>
        <?php endif; ?>

        <?php if($stationItems === []): ?>
            <section class="empty-state">
                <?= $chargerType !== ''
                    ? '该站点当前没有符合充电类型筛选条件的充电桩。'
                    : '该站点目前还没有充电桩。' ?>
            </section>
        <?php else: ?>
            <section class="station-grid">
                <?php foreach($stationItems as $stationItem): ?>
                    <?php
                    $station = $stationItem['station'];
                    $stationId = $station->getStationId();

                    if($stationId === null){
                        continue;
                    }

                    $availability = getStationAvailability(
                        $station->getStatus(),
                        $stationItem['has_active_record']
                    );

                    $canStartCharging = $availability['available'] && !$hasActiveUserRecord;
                    ?>

                    <article class="station-card">
                        <h3 class="station-name">
                            <?= View::escape($station->getStationName()) ?>
                        </h3>

                        <p class="station-code">
                            设备编号：<?= View::escape($station->getStationCode()) ?>
                        </p>

                        <div class="station-info">
                            <p>
                                <strong>充电类型：</strong>
                                <?= View::escape($station->getChargerTypeLabel()) ?>
                            </p>

                            <p>
                                <strong>充电功率：</strong>
                                <?= View::escape($station->getPowerKwLabel()) ?>
                            </p>

                            <p>
                                <strong>每小时费用：</strong>
                                <?= View::escape($station->getHourlyRateLabel()) ?>
                            </p>

                            <p>
                                <strong>当前状态：</strong>

                                <span class="status-badge <?= View::escape(
                                    $availability['class']
                                ) ?>">
                                    <?= View::escape($availability['label']) ?>
                                </span>
                            </p>
                        </div>

                        <?php if($canStartCharging): ?>
                            <a
                                class="primary-button start-button"
                                href="../charging/start.php?station_id=<?= View::escape((string)$stationId) ?>"
                            >
                                选择并开始充电
                            </a>
                        <?php elseif($hasActiveUserRecord): ?>
                            <span class="disabled-button">
                                您已有进行中的订单
                            </span>
                        <?php else: ?>
                            <span class="disabled-button">
                                当前不可使用
                            </span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php require dirname(__DIR__, 3) . '/views/user/location_reviews.php'; ?>
    </main>
</body>
</html>