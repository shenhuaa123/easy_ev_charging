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

if(
    $user->isAdmin()
    || $userId === $currentUserId
){
    $session->setFlash(
        'error',
        '当前功能不允许重置管理员账户密码。'
    );

    header('Location: detail.php?id=' . $userId);
    exit;
}

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $confirmed = (string)($_POST['confirmed'] ?? '');

        $resetResult = $userService->resetManagedPassword(
            $currentUserId,
            $userId,
            [
                'new_password' => (string)($_POST['new_password'] ?? ''),
                'new_password_confirmation' => (string)($_POST['new_password_confirmation'] ?? ''),
                'confirmed' => $confirmed,
            ]
        );

        $logService->recordCurrentRequest(
            $currentUserId,
            'user_password_reset',
            'user',
            $userId,
            $resetResult['success'] ? 'success' : 'failure',
            $resetResult['message']
        );

        if($resetResult['success']){
            $session->setFlash('success', $resetResult['message']);
            header('Location: detail.php?id=' . $userId);
            exit;
        }

        $errorMessage = $resetResult['message'];
        $errors = $resetResult['errors'];

        $user = $userRepository->findById($userId);

        if($user === null){
            $session->setFlash('error', '目标用户已不存在。');
            header('Location: index.php');
            exit;
        }

        if($user->isAdmin() || $userId === $currentUserId){
            $session->setFlash('error', '当前功能不允许重置管理员账户密码。');
            header('Location: detail.php?id=' . $userId);
            exit;
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
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>重置用户密码｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }

        .user-box {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 24px;
            padding: 18px;
            border-radius: 9px;
            background: #f5f8fb;
        }

        .user-label {
            margin-bottom: 5px;
            color: #78909c;
            font-size: 14px;
        }

        .user-value {
            margin: 0;
            font-weight: bold;
        }

        @media(max-width: 700px){
            .user-box {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">重置用户登录密码</h2>

            <p class="page-description">
                为普通用户设置新的登录密码。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                <p><strong>重要提示：</strong></p>
                <p>1. 系统无法查看或恢复用户原密码。</p>
                <p>2. 重置后，用户必须使用新密码登录。</p>
                <p>3. 请通过安全方式将新密码告知用户。</p>
                <p>4. 不要使用容易猜测的固定密码。</p>
            </div>

            <div class="user-box">
                <div>
                    <div class="user-label">用户编号</div>

                    <p class="user-value">
                        <?= View::escape((string)$userId) ?>
                    </p>
                </div>

                <div>
                    <div class="user-label">用户名</div>

                    <p class="user-value">
                        <?= View::escape($user->getUsername()) ?>
                    </p>
                </div>

                <div>
                    <div class="user-label">真实姓名</div>

                    <p class="user-value">
                        <?= View::escape($user->getRealName()) ?>
                    </p>
                </div>

                <div>
                    <div class="user-label">账户状态</div>

                    <p class="user-value">
                        <?= View::escape($user->getStatusLabel()) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="new_password">
                        新登录密码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'new_password'
                        ) ? 'input-error' : '' ?>"
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="8"
                        maxlength="20"
                        autocomplete="new-password"
                        required
                    >

                    <p class="field-help">
                        8到20个字符，必须同时包含大写字母、小写字母、数字和特殊字符，且不能包含空格。
                    </p>

                    <?php if(
                        View::firstError($errors, 'new_password') !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError($errors, 'new_password')
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="new_password_confirmation"
                    >
                        再次输入新密码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'new_password_confirmation'
                        ) ? 'input-error' : '' ?>"
                        type="password"
                        id="new_password_confirmation"
                        name="new_password_confirmation"
                        minlength="8"
                        maxlength="20"
                        autocomplete="new-password"
                        required
                    >

                    <?php if(
                        View::firstError(
                            $errors,
                            'new_password_confirmation'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'new_password_confirmation'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="confirm-group">
                    <label>
                        <input
                            type="checkbox"
                            name="confirmed"
                            value="1"
                            required
                        >

                        我已确认目标用户信息无误，并同意重置该用户的登录密码。
                    </label>

                    <?php if(View::firstError($errors, 'confirmed') !== null): ?>
                        <p class="field-error">
                            <?= View::escape(View::firstError($errors, 'confirmed')) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="danger-button" type="submit">
                        确认重置密码
                    </button>

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
</body>
</html>