<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\UserService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$userService = new UserService(
    $connection,
    $userRepository,
    $recordRepository
);

$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前管理员信息异常，请重新登录。', '../../login.php');
}

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($userId === false || $userId === null){
    $session->setFlash('error', '用户编号不合法。');
    header('Location: index.php');
    exit;
}

$user = $userRepository->findById($userId);

if($user === null){
    $session->setFlash('error', '未找到指定用户。');
    header('Location: index.php');
    exit;
}

if($userId === $currentUserId){
    $session->setFlash('error', '管理员不能修改自己的账户状态。');
    header('Location: detail.php?id=' . $userId);
    exit;
}

if($user->isAdmin()){
    $session->setFlash('error', '当前功能不允许修改管理员账户状态。');
    header('Location: detail.php?id=' . $userId);
    exit;
}

$targetStatus = $user->isActive()
    ? 'disabled'
    : 'active';

$targetStatusLabel = $targetStatus === 'active'
    ? '启用'
    : '停用';

$activeUserRecord = $recordRepository->findActiveByUserId($userId);

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $confirmed = (string)($_POST['confirmed'] ?? '');

        if($confirmed !== '1'){
            $errorMessage = '请确认已经了解账户状态修改的影响。';
        }else{
            $updateResult = $userService->updateStatus($currentUserId, $userId, $targetStatus);

            $logService->recordCurrentRequest(
                $currentUserId,
                'user_status_update',
                'user',
                $userId,
                $updateResult['success'] ? 'success' : 'failure',
                '目标状态：' . $targetStatus . '；结果：' . $updateResult['message']
            );

            if($updateResult['success']){
                $session->setFlash('success', $updateResult['message']);
                header('Location: detail.php?id=' . $userId);
                exit;
            }

            $errorMessage = $updateResult['message'];
            $user = $userRepository->findById($userId);

            if($user === null){
                $session->setFlash('error', '目标用户已不存在。');
                header('Location: index.php');
                exit;
            }

            if($userId === $currentUserId){
                $session->setFlash('error', '管理员不能修改自己的账户状态。');
                header('Location: detail.php?id=' . $userId);
                exit;
            }

            if($user->isAdmin()){
                $session->setFlash('error', '当前功能不允许修改管理员账户状态。');
                header('Location: detail.php?id=' . $userId);
                exit;
            }

            $targetStatus = $user->isActive() ? 'disabled' : 'active';
            $targetStatusLabel = $targetStatus === 'active' ? '启用' : '停用';
            $activeUserRecord = $recordRepository->findActiveByUserId($userId);
        }
    }
}

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回用户详情',
        'href' => 'detail.php?id=' . $userId,
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
    <title>修改用户状态｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }

        .detail-grid {
            gap: 20px 28px;
            margin-bottom: 24px;
        }

        .active-order-box {
            margin-bottom: 22px;
            padding: 15px 17px;
            border: 1px solid #ffcc80;
            border-radius: 8px;
            background: #fff8e1;
            color: #e65100;
        }

        .danger-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">
                <?= View::escape($targetStatusLabel) ?>用户账户
            </h2>

            <p class="page-description">
                请确认目标用户和账户状态无误后再提交操作。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if($targetStatus === 'disabled'): ?>
                <div class="danger-box">
                    <p><strong>停用账户后：</strong></p>
                    <p>1. 用户将无法继续正常登录和使用用户端功能。</p>
                    <p>2. 用户的历史订单和个人资料不会被删除。</p>
                    <p>3. 账户之后仍可由管理员重新启用。</p>
                </div>
            <?php else: ?>
                <div class="success-box">
                    <p><strong>启用账户后：</strong></p>
                    <p>1. 用户可以重新登录系统。</p>
                    <p>2. 用户可以继续搜索站点、开始充电和查看历史订单。</p>
                    <p>3. 用户原有资料和历史订单不会发生变化。</p>
                </div>
            <?php endif; ?>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">用户编号</div>

                    <p class="detail-value">
                        <?= View::escape((string)$userId) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">用户名</div>

                    <p class="detail-value">
                        <?= View::escape($user->getUsername()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">真实姓名</div>

                    <p class="detail-value">
                        <?= View::escape($user->getRealName()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">手机号码</div>

                    <p class="detail-value">
                        <?= View::escape($user->getMobile()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前账户状态</div>

                    <p class="detail-value">
                        <span class="status-badge <?= View::escape(
                            View::statusClass($user->getStatus())
                        ) ?>">
                            <?= View::escape($user->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">操作后状态</div>

                    <p class="detail-value">
                        <?= View::escape(
                            $targetStatus === 'active'
                                ? '正常'
                                : '已停用'
                        ) ?>
                    </p>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">电子邮箱</div>

                    <p class="detail-value">
                        <?= View::escape($user->getEmail() ?? '未填写') ?>
                    </p>
                </div>
            </div>

            <?php if(
                $targetStatus === 'disabled'
                && $activeUserRecord !== null
            ): ?>
                <div class="active-order-box">
                    <strong>当前无法停用该账户。</strong>

                    该用户仍有一笔正在进行的充电订单：

                    <?= View::escape($activeUserRecord->getOrderNumber()) ?>，

                    开始时间：

                    <?= View::escape($activeUserRecord->getCheckInAt()) ?>。

                    请先等待用户正常结束订单，或由管理员异常结束订单。
                </div>
            <?php endif; ?>

            <form method="post" action="" data-submit-lock>
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="confirm-group">
                    <label>
                        <input
                            type="checkbox"
                            name="confirmed"
                            value="1"
                            required
                            <?= (
                                $targetStatus === 'disabled'
                                && $activeUserRecord !== null
                            ) ? 'disabled' : '' ?>
                        >

                        我已确认目标用户和状态修改影响，并同意
                        <?= View::escape($targetStatusLabel) ?>
                        该账户。
                    </label>
                </div>

                <div class="form-actions">
                    <?php if(
                        $targetStatus === 'disabled'
                        && $activeUserRecord !== null
                    ): ?>
                        <button
                            class="danger-button"
                            type="button"
                            disabled
                        >
                            当前不能停用
                        </button>
                    <?php elseif($targetStatus === 'disabled'): ?>
                        <button
                            class="danger-button"
                            type="submit"
                            data-loading-text="正在停用..."
                        >
                            确认停用账户
                        </button>
                    <?php else: ?>
                        <button
                            class="success-button"
                            type="submit"
                            data-loading-text="正在启用..."
                        >
                            确认启用账户
                        </button>
                    <?php endif; ?>

                    <a
                        class="secondary-button"
                        href="detail.php?id=<?= View::escape((string)$userId) ?>"
                    >
                        返回用户详情
                    </a>
                </div>
            </form>
        </section>
    </main>
    <script src="../../assets/js/form_submit_lock.js"></script>
</body>
</html>