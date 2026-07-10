<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Models\LocationReview;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\LocationReviewService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$reviewRepository = new LocationReviewRepository($connection);
$adminOperationLogRepository = new AdminOperationLogRepository($connection);
$adminOperationLogService = new AdminOperationLogService($adminOperationLogRepository);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);
$csrf = new Csrf($session);
$adminController = new AdminController($session, $csrf, $authGuard);

$currentUser = $adminController->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$currentUserId = $adminController->requireAdminId(
    $currentUser,
    '../../login.php'
);

$reviewId = $adminController->getPositiveIntFromInput(INPUT_GET, 'id');

if($reviewId === null){
    $reviewId = $adminController->getPositiveIntFromInput(
        INPUT_POST,
        'review_id'
    );
}

if($reviewId === null){
    $adminController->flashError('评价编号不合法。');
    $adminController->redirect('index.php');
}

$review = $reviewRepository->findById($reviewId);

if($review === null){
    $session->setFlash('error', '未找到指定的站点评价。');
    header('Location: index.php');
    exit;
}

$newStatus = $review->isVisible()
    ? LocationReview::STATUS_HIDDEN
    : LocationReview::STATUS_VISIBLE;

$actionLabel = $newStatus === LocationReview::STATUS_HIDDEN
    ? '隐藏评价'
    : '恢复公开';

$confirmButtonLabel = $newStatus === LocationReview::STATUS_HIDDEN
    ? '确认隐藏评价'
    : '确认恢复公开';

$reviewService = new LocationReviewService(
    $connection,
    $reviewRepository,
    $locationRepository,
    $userRepository,
    $adminOperationLogService
);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $adminController->validateCsrfOrRedirect(
        $_POST[Csrf::FIELD_NAME] ?? null,
        'status.php?id=' . urlencode((string)$reviewId)
    );

    $postedStatus = trim((string)($_POST['new_status'] ?? ''));

    if($postedStatus !== $newStatus){
        $adminController->flashError('评价状态提交值不一致，请返回确认页后重新操作。');
        $adminController->redirect('status.php?id=' . urlencode((string)$reviewId));
    }

    $result = $reviewService->updateStatusAsAdmin(
        $currentUserId,
        $reviewId,
        $newStatus
    );

    if($result['success']){
        $adminController->flashSuccess((string)$result['message']);
    }else{
        $adminController->flashError((string)($result['message'] ?? '评价状态更新失败。'));
    }

    $adminController->redirect('index.php');
}

$csrfToken = $adminController->csrfToken();
$flashMessages = $adminController->flashMessages();

$topbarContext = $adminController->buildTopbarContext(
    $currentUser,
    '../dashboard.php',
    '../../logout.php'
);

$topbarTheme = $topbarContext['topbarTheme'];
$topbarIdentityLabel = $topbarContext['topbarIdentityLabel'];
$topbarDisplayName = $topbarContext['topbarDisplayName'];
$topbarLinks = $topbarContext['topbarLinks'];

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title><?= View::escape($actionLabel) ?>｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">
    <link rel="stylesheet" href="../../assets/css/reviews.css">

    <style>
        .page {
            max-width: 900px;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <?php
        $pageTitle = $actionLabel;
        $pageDescription = '请确认是否要修改这条站点评价的公开显示状态。';
        $pageBackLink = [
            'label' => '返回评价管理',
            'href' => 'index.php',
            'class' => 'secondary-button',
        ];
        require dirname(__DIR__, 3) . '/views/layouts/page_header.php';
        ?>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <section class="confirm-card">
            <div class="warning-box">
                <?php if($newStatus === LocationReview::STATUS_HIDDEN): ?>
                    隐藏后，普通用户在站点详情页将看不到这条评价，该评价也不会计入站点公开评分。
                <?php else: ?>
                    恢复公开后，普通用户将在站点详情页看到这条评价，该评价也会重新计入站点公开评分。
                <?php endif; ?>
            </div>

            <div class="review-grid">
                <div class="review-item">
                    <div class="review-label">评价编号</div>
                    <p class="review-value">
                        <?= View::escape((string)$review->getLocationReviewId()) ?>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">当前状态</div>
                    <p class="review-value">
                        <span class="status-badge <?= View::escape(View::statusClass($review->getStatus())) ?>">
                            <?= View::escape($review->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">将修改为</div>
                    <p class="review-value">
                        <span class="status-badge <?= View::escape(View::statusClass($newStatus)) ?>">
                            <?= View::escape(LocationReview::getStatusOptions()[$newStatus]) ?>
                        </span>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">评分</div>
                    <p class="review-value rating-stars">
                        <?= View::escape($review->getRatingLabel()) ?>
                    </p>
                </div>

                <div class="review-item full-width">
                    <div class="review-label">用户评论</div>
                    <div class="text-box">
                        <p><?= View::escape($review->getContent()) ?></p>
                    </div>
                </div>
            </div>

            <form
                method="post"
                action="status.php?id=<?= View::escape((string)$reviewId) ?>"
                data-submit-lock
            >
                <input
                    type="hidden"
                    name="<?= View::escape(Csrf::FIELD_NAME) ?>"
                    value="<?= View::escape($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="review_id"
                    value="<?= View::escape((string)$reviewId) ?>"
                >

                <input
                    type="hidden"
                    name="new_status"
                    value="<?= View::escape($newStatus) ?>"
                >

                <div class="form-actions">
                    <button
                        class="<?= $newStatus === LocationReview::STATUS_HIDDEN ? 'danger-button' : 'primary-button' ?>"
                        type="submit"
                        data-loading-text="<?= $newStatus === LocationReview::STATUS_HIDDEN ? '正在隐藏...' : '正在恢复...' ?>"
                    >
                        <?= View::escape($confirmButtonLabel) ?>
                    </button>

                    <a class="secondary-button" href="index.php">
                        返回评价管理
                    </a>
                </div>
            </form>
        </section>
    </main>
    <script src="../../assets/js/form_submit_lock.js"></script>
</body>
</html>