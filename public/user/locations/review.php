<?php

declare(strict_types=1);

use App\Controllers\UserController;
use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Models\LocationReview;
use App\Repositories\LocationRepository;
use App\Repositories\LocationReviewRepository;
use App\Repositories\UserRepository;
use App\Services\LocationReviewService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$reviewRepository = new LocationReviewRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);
$csrf = new Csrf($session);
$userController = new UserController($session, $csrf, $authGuard);

$currentUser = $userController->requireUser(
    '../../login.php',
    '../../admin/dashboard.php'
);

$currentUserId = $userController->requireUserId(
    $currentUser,
    '../../login.php'
);

$locationId = $userController->getPositiveIntFromInput(INPUT_GET, 'id');

if($locationId === null){
    $locationId = $userController->getPositiveIntFromInput(
        INPUT_POST,
        'location_id'
    );
}

if($locationId === null){
    $userController->flashError('充电站点编号不合法。');
    $userController->redirect('index.php');
}

$reviewService = new LocationReviewService(
    $connection,
    $reviewRepository,
    $locationRepository,
    $userRepository
);

$context = $reviewService->getUserReviewContext(
    $currentUserId,
    $locationId
);

if(!$context['success']){
    $userController->flashError((string)$context['message']);
    $userController->redirect('index.php');
}

$location = $context['location'];
$existingReview = $context['review'];
$canReview = (bool)$context['can_review'];
$reviewableRecordId = $context['reviewable_record_id'];

if($location === null){
    $userController->flashError('未找到指定的充电站点。');
    $userController->redirect('index.php');
}

$errors = [];
$formRating = $existingReview instanceof LocationReview
    ? $existingReview->getRating()
    : 5;
$formContent = $existingReview instanceof LocationReview
    ? $existingReview->getContent()
    : '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $formRating = (int)($_POST['rating'] ?? 0);
    $formContent = trim((string)($_POST['content'] ?? ''));

    if(!$userController->isValidCsrf($_POST[Csrf::FIELD_NAME] ?? null)){
        $errors['_form'][] = Csrf::INVALID_MESSAGE;
    }else{
        $saveResult = $reviewService->saveUserReview(
            $currentUserId,
            $locationId,
            [
                'rating' => $formRating,
                'content' => $formContent,
            ]
        );

        if($saveResult['success']){
            $userController->flashSuccess((string)$saveResult['message']);
            $userController->redirect('detail.php?id=' . urlencode((string)$locationId));
        }

        $errors = $saveResult['errors'] ?? [];

        if(isset($saveResult['message']) && $saveResult['message'] !== ''){
            $errors['_form'][] = (string)$saveResult['message'];
        }

        if($errors === []){
            $errors['_form'][] = '站点评价保存失败，请检查输入后重试。';
        }
    }
}

$csrfToken = $userController->csrfToken();
$flashMessages = $userController->flashMessages();

$topbarContext = $userController->buildTopbarContext(
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>站点评价｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">
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
        <section class="page-header">
            <div>
                <h2 class="page-title">
                    <?= $existingReview instanceof LocationReview ? '修改站点评价' : '提交站点评价' ?>
                </h2>

                <p class="page-description">
                    您可以对已完成或异常结束充电订单对应的站点进行1到5星评分，并填写使用体验。
                </p>
            </div>

            <a
                class="secondary-button"
                href="detail.php?id=<?= View::escape((string)$locationId) ?>"
            >
                返回站点详情
            </a>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <div class="review-page">
            <section class="review-info">
                <div class="review-grid">
                    <div class="review-item">
                        <div class="review-label">站点名称</div>
                        <p class="review-value">
                            <?= View::escape($location->getLocationName()) ?>
                        </p>
                    </div>

                    <div class="review-item">
                        <div class="review-label">站点编号</div>
                        <p class="review-value">
                            <?= View::escape($location->getLocationCode()) ?>
                        </p>
                    </div>

                    <div class="review-item full-width">
                        <div class="review-label">完整地址</div>
                        <p class="review-value">
                            <?= View::escape($location->getFullAddress()) ?>
                        </p>
                    </div>
                </div>
            </section>

            <?php if(!$canReview): ?>
                <section class="review-notice">
                    <?= View::escape((string)$context['message']) ?>

                    <br><br>

                    只有在该站点存在已完成或异常结束的充电订单后，才能提交站点评价。
                </section>
            <?php else: ?>
                <section class="review-form">
                    <?php if(isset($errors['_form'])): ?>
                        <div class="error-message">
                            <?php foreach($errors['_form'] as $formError): ?>
                                <div><?= View::escape($formError) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if($existingReview instanceof LocationReview && $existingReview->isHidden()): ?>
                        <div class="status-note">
                            您的这条评价当前已被管理员隐藏。修改内容后仍不会自动恢复公开，需要管理员重新设为公开显示。
                        </div>
                    <?php endif; ?>

                    <?php if($existingReview instanceof LocationReview && $existingReview->hasAdminReply()): ?>
                        <div class="reply-box">
                            <p class="reply-title">管理员回复</p>
                            <p class="reply-text">
                                <?= View::escape($existingReview->getAdminReply()) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <form
                        method="post"
                        action="review.php?id=<?= View::escape((string)$locationId) ?>"
                        data-submit-lock
                    >
                        <input
                            type="hidden"
                            name="<?= View::escape(Csrf::FIELD_NAME) ?>"
                            value="<?= View::escape($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="location_id"
                            value="<?= View::escape((string)$locationId) ?>"
                        >

                        <div class="form-group">
                            <label class="form-label">
                                星级评分
                                <span class="required-mark">*</span>
                            </label>

                            <div class="rating-options" data-rating-options>
                                <?php foreach(LocationReview::getRatingOptions() as $ratingValue => $ratingLabel): ?>
                                    <label class="rating-option <?= $formRating === $ratingValue ? 'selected' : '' ?>">
                                        <input
                                            type="radio"
                                            name="rating"
                                            value="<?= View::escape((string)$ratingValue) ?>"
                                            <?= $formRating === $ratingValue ? 'checked' : '' ?>
                                        >

                                        <span class="star-text">
                                            <?= View::escape(str_repeat('★', (int)$ratingValue)) ?>
                                        </span>

                                        <span class="rating-text">
                                            <?= View::escape($ratingLabel) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if(View::hasError($errors, 'rating')): ?>
                                <div class="field-error">
                                    <?= View::escape(View::firstError($errors, 'rating')) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="content">
                                评价内容
                                <span class="required-mark">*</span>
                            </label>

                            <textarea
                                class="form-control review-textarea <?= View::hasError($errors, 'content') ? 'input-error' : '' ?>"
                                id="content"
                                name="content"
                                maxlength="1000"
                                placeholder="请填写本次站点使用体验，例如位置是否好找、设备是否稳定、费用是否清晰等。"
                            ><?= View::escape($formContent) ?></textarea>

                            <p class="review-help">
                                评价内容长度为1到1000个字符。请不要填写手机号、邮箱等个人隐私信息。
                            </p>

                            <?php if(View::hasError($errors, 'content')): ?>
                                <div class="field-error">
                                    <?= View::escape(View::firstError($errors, 'content')) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-actions">
                            <button
                                class="primary-button"
                                type="submit"
                                data-loading-text="<?= $existingReview instanceof LocationReview ? '正在保存...' : '正在提交...' ?>"
                            >
                                <?= $existingReview instanceof LocationReview ? '保存站点评价修改' : '提交站点评价' ?>
                            </button>

                            <a
                                class="secondary-button"
                                href="detail.php?id=<?= View::escape((string)$locationId) ?>"
                            >
                                返回站点详情
                            </a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    </main>
    <script src="../../assets/js/rating_options.js"></script>
    <script src="../../assets/js/form_submit_lock.js"></script>
</body>
</html>