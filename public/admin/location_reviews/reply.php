<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
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
    $adminController->flashError('未找到指定的站点评价。');
    $adminController->redirect('index.php');
}

$reviewService = new LocationReviewService(
    $connection,
    $reviewRepository,
    $locationRepository,
    $userRepository,
    $adminOperationLogService
);

$errors = [];
$formAdminReply = $review->getAdminReply() ?? '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $formAdminReply = trim((string)($_POST['admin_reply'] ?? ''));

    if(!$adminController->isValidCsrf($_POST[Csrf::FIELD_NAME] ?? null)){
        $errors['_form'][] = Csrf::INVALID_MESSAGE;
    }else{
        $result = $reviewService->replyAsAdmin(
            $currentUserId,
            $reviewId,
            [
                'admin_reply' => $formAdminReply,
            ]
        );

        if($result['success']){
            $adminController->flashSuccess((string)$result['message']);
            $adminController->redirect('index.php');
        }

        $errors = $result['errors'] ?? [];

        if(isset($result['message']) && $result['message'] !== ''){
            $errors['_form'][] = (string)$result['message'];
        }

        if($errors === []){
            $errors['_form'][] = '管理员回复保存失败，请检查输入后重试。';
        }
    }
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
    <title>回复站点评价｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">
    <link rel="stylesheet" href="../../assets/css/reviews.css">

    <style>
        .page {
            max-width: 980px;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <?php
        $pageTitle = $review->hasAdminReply() ? '修改管理员回复' : '回复站点评价';
        $pageDescription = '管理员可以对用户公开评价进行正式回复，回复内容会展示在站点详情页。';
        $pageBackLink = [
            'label' => '返回评价管理',
            'href' => 'index.php',
            'class' => 'secondary-button',
        ];
        
        require dirname(__DIR__, 3) . '/views/layouts/page_header.php';
        ?>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <section class="review-detail">
            <div class="review-grid">
                <div class="review-item">
                    <div class="review-label">评价编号</div>
                    <p class="review-value">
                        <?= View::escape((string)$review->getLocationReviewId()) ?>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">评价状态</div>
                    <p class="review-value">
                        <span class="status-badge <?= View::escape(View::statusClass($review->getStatus())) ?>">
                            <?= View::escape($review->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">评分</div>
                    <p class="review-value rating-stars">
                        <?= View::escape($review->getRatingLabel()) ?>
                    </p>
                </div>

                <div class="review-item">
                    <div class="review-label">评价时间</div>
                    <p class="review-value">
                        <?= View::escape($review->getCreatedAt()) ?>
                    </p>
                </div>

                <div class="review-item full-width">
                    <div class="review-label">用户评论</div>
                    <div class="text-box">
                        <p><?= View::escape($review->getContent()) ?></p>
                    </div>
                </div>
            </div>
        </section>

        <section class="reply-form">
            <?php if(isset($errors['_form'])): ?>
                <div class="error-message">
                    <?php foreach($errors['_form'] as $formError): ?>
                        <div><?= View::escape($formError) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="reply.php?id=<?= View::escape((string)$reviewId) ?>"
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

                <div class="form-group">
                    <label class="form-label" for="admin_reply">
                        管理员回复
                        <span class="required-mark">*</span>
                    </label>

                    <textarea
                        class="form-control reply-textarea <?= View::hasError($errors, 'admin_reply') ? 'input-error' : '' ?>"
                        id="admin_reply"
                        name="admin_reply"
                        maxlength="1000"
                        placeholder="请输入面向用户展示的管理员回复内容。"
                    ><?= View::escape($formAdminReply) ?></textarea>

                    <p class="reply-help">
                        回复内容长度为1到1000个字符。请使用正式、礼貌、清晰的表达。
                    </p>

                    <?php if(View::hasError($errors, 'admin_reply')): ?>
                        <div class="field-error">
                            <?= View::escape(View::firstError($errors, 'admin_reply')) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button
                        class="primary-button"
                        type="submit"
                        data-loading-text="正在保存..."
                    >
                        保存管理员回复
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