<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Models\LocationReview;
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

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$statusOptions = [
    '' => '全部状态',
] + LocationReview::getStatusOptions();

$ratingOptions = [
    0 => '全部评分',
] + LocationReview::getRatingOptions();

$keyword = trim((string)($_GET['keyword'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$rating = filter_input(
    INPUT_GET,
    'rating',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 5,
        ],
    ]
);
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

if($rating === false || $rating === null){
    $rating = 0;
}

if($locationId === false || $locationId === null){
    $locationId = 0;
}

$filterErrors = [];

if($keyword !== '' && mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if(!array_key_exists($status, $statusOptions)){
    $filterErrors[] = '评价状态筛选条件不合法。';
    $status = '';
}

if($rating < 0 || $rating > 5){
    $filterErrors[] = '评分筛选条件不合法。';
    $rating = 0;
}

$locationOptions = $locationRepository->findAll();
$availableLocationIds = [];

foreach($locationOptions as $locationOption){
    $optionId = $locationOption->getLocationId();

    if($optionId !== null){
        $availableLocationIds[] = $optionId;
    }
}

if($locationId > 0 && !in_array($locationId, $availableLocationIds, true)){
    $filterErrors[] = '站点筛选条件不合法。';
    $locationId = 0;
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
    'rating' => $rating,
    'location_id' => $locationId,
];

$totalReviews = $reviewRepository->countListItems($filters);
$totalPages = max(1, (int)ceil($totalReviews / $pageSize));

if($currentPage > $totalPages){
    $currentPage = $totalPages;
}

$offset = ($currentPage - 1) * $pageSize;

$reviewItems = $reviewRepository->searchListItems(
    $filters,
    $pageSize,
    $offset
);

$hasActiveFilters = $keyword !== ''
    || $status !== ''
    || $rating > 0
    || $locationId > 0;

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($status !== ''){
    $queryParameters['status'] = $status;
}

if($rating > 0){
    $queryParameters['rating'] = (string)$rating;
}

if($locationId > 0){
    $queryParameters['location_id'] = (string)$locationId;
}

$paginationPath = 'index.php';
$paginationAriaLabel = '站点评价分页';
$paginationTotal = $totalReviews;
$paginationUnit = '条评价';

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
    <title>站点评价管理｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">
    <link rel="stylesheet" href="../../assets/css/reviews.css">

    <style>
        .page {
            max-width: 1500px;
        }
        
        .filter-grid {
            grid-template-columns:
                minmax(260px, 2fr)
                minmax(150px, 1fr)
                minmax(170px, 1fr)
                minmax(220px, 1.5fr);
            align-items: end;
        }

        table {
            min-width: 1450px;
        }

        @media(max-width: 1050px){
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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
        <?php
        $pageTitle = '站点评价管理';
        $pageDescription = '查看用户对充电站点的评分和评论，并进行回复、隐藏或恢复公开显示。';
        $pageBackLink = null;
        require dirname(__DIR__, 3) . '/views/layouts/page_header.php';
        ?>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php require dirname(__DIR__, 3) . '/views/admin/filter_errors.php'; ?>

        <section class="filter-card">
            <form id="location-review-filter-form" method="get" action="index.php">
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
                            placeholder="评论、回复、用户名、姓名或站点名称"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="status">
                            评价状态
                        </label>

                        <select class="filter-control" id="status" name="status">
                            <?php foreach($statusOptions as $optionValue => $optionLabel): ?>
                                <option value="<?= View::escape($optionValue) ?>" <?= $status === $optionValue ? 'selected' : '' ?>>
                                    <?= View::escape($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="rating">
                            星级评分
                        </label>

                        <select class="filter-control" id="rating" name="rating">
                            <?php foreach($ratingOptions as $optionValue => $optionLabel): ?>
                                <option value="<?= View::escape((string)$optionValue) ?>" <?= $rating === (int)$optionValue ? 'selected' : '' ?>>
                                    <?= View::escape($optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="location_id">
                            充电站点
                        </label>

                        <select class="filter-control" id="location_id" name="location_id">
                            <option value="">全部站点</option>

                            <?php foreach($locationOptions as $locationOption): ?>
                                <?php $optionId = $locationOption->getLocationId(); ?>

                                <?php if($optionId !== null): ?>
                                    <option
                                        value="<?= View::escape((string)$optionId) ?>"
                                        <?= $locationId === $optionId ? 'selected' : '' ?>
                                    >
                                        <?= View::escape($locationOption->getLocationName()) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>

            <div class="filter-actions">
                <button
                    class="filter-button"
                    type="submit"
                    form="location-review-filter-form"
                >
                    搜索评价
                </button>

                <?php if($hasActiveFilters): ?>
                    <a class="reset-button" href="index.php">
                        重置筛选
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <p class="result-summary">
            共找到 <?= View::escape((string)$totalReviews) ?> 条站点评价。
        </p>

        <section class="table-card">
            <?php if($reviewItems === []): ?>
                <?php
                $emptyMessage = '当前还没有站点评价。';
                $filteredEmptyMessage = '当前筛选条件下没有符合要求的站点评价。';
                $resetUrl = 'index.php';
                $resetLabel = '重置筛选';
                require dirname(__DIR__, 3) . '/views/admin/empty_state.php';
                ?>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>评价编号</th>
                            <th>评价时间</th>
                            <th>用户</th>
                            <th>充电站点</th>
                            <th>评分</th>
                            <th>评论内容</th>
                            <th>管理员回复</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($reviewItems as $reviewItem): ?>
                            <?php
                            $review = $reviewItem['review'];
                            $reviewUser = $reviewItem['user'];
                            $reviewLocation = $reviewItem['location'];
                            $replyAdmin = $reviewItem['reply_admin'];
                            $reviewId = $review->getLocationReviewId();
                            ?>

                            <tr>
                                <td>
                                    <?= View::escape((string)($reviewId ?? '')) ?>
                                </td>

                                <td>
                                    <?= View::escape($review->getCreatedAt()) ?>

                                    <?php if($review->getUpdatedAt() !== $review->getCreatedAt()): ?>
                                        <br>
                                        <span class="muted-text">
                                            更新：<?= View::escape($review->getUpdatedAt()) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($reviewUser === null): ?>
                                        <span class="missing-data">用户资料不存在</span>
                                    <?php else: ?>
                                        <?= View::escape($reviewUser['real_name']) ?>
                                        <br>
                                        <span class="muted-text">
                                            <?= View::escape($reviewUser['username']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if($reviewLocation === null): ?>
                                        <span class="missing-data">站点资料不存在</span>
                                    <?php else: ?>
                                        <a
                                            class="action-link"
                                            href="../locations/detail.php?id=<?= View::escape((string)$review->getLocationId()) ?>"
                                        >
                                            <?= View::escape($reviewLocation['location_name']) ?>
                                        </a>
                                        <br>
                                        <span class="muted-text">
                                            <?= View::escape($reviewLocation['location_code']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="rating-stars">
                                        <?= View::escape($review->getRatingLabel()) ?>
                                    </div>
                                    <span class="muted-text">
                                        <?= View::escape($review->getRatingText()) ?>
                                    </span>
                                </td>

                                <td class="review-cell">
                                    <?= View::escape($review->getContent()) ?>
                                </td>

                                <td class="reply-cell">
                                    <?php if($review->hasAdminReply()): ?>
                                        <?= View::escape($review->getAdminReply()) ?>

                                        <?php if($replyAdmin !== null): ?>
                                            <br>
                                            <span class="muted-text">
                                                回复人：<?= View::escape($replyAdmin['real_name'] ?? $replyAdmin['username']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if($review->getRepliedAt() !== null): ?>
                                            <br>
                                            <span class="muted-text">
                                                回复时间：<?= View::escape($review->getRepliedAt()) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted-text">暂未回复</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($review->getStatus())
                                    ) ?>">
                                        <?= View::escape($review->getStatusLabel()) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-group">
                                        <a
                                            class="action-link"
                                            href="reply.php?id=<?= View::escape((string)($reviewId ?? 0)) ?>"
                                        >
                                            <?= $review->hasAdminReply() ? '修改回复' : '回复评价' ?>
                                        </a>

                                        <a
                                            class="action-link <?= $review->isVisible() ? 'danger' : '' ?>"
                                            href="status.php?id=<?= View::escape((string)($reviewId ?? 0)) ?>"
                                        >
                                            <?= $review->isVisible() ? '隐藏评价' : '恢复公开' ?>
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