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

require_once dirname(__DIR__, 2) . '/bootstrap.php';

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

$currentUser = $authGuard->requireLogin(
    '../login.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', '../login.php');
}

$user = $userRepository->findById($currentUserId);

if($user === null){
    $authGuard->logoutAndRedirect('当前用户不存在，请重新登录。', '../login.php');
}

if(!$user->isActive()){
    $authGuard->logoutAndRedirect('当前账户已被停用。', '../login.php');
}

$dashboardPath = $user->isAdmin()
    ? '../admin/dashboard.php'
    : '../user/dashboard.php';

$dashboardLabel = $user->isAdmin()
    ? '返回控制台'
    : '返回用户中心';

$topbarTheme = $user->isAdmin() ? 'admin' : 'user';
$topbarIdentityLabel = $user->isAdmin() ? '管理员' : '用户';
$topbarDisplayName = $user->getRealName();
$topbarLinks = [
    [
        'label' => $dashboardLabel,
        'href' => $dashboardPath,
    ],
    [
        'label' => '返回个人资料',
        'href' => 'profile.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../logout.php',
    ],
];

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $changeResult = $userService->changePassword(
            $currentUserId,
            [
                'current_password' => (string)(
                    $_POST['current_password'] ?? ''
                ),
                'new_password' => (string)(
                    $_POST['new_password'] ?? ''
                ),
                'new_password_confirmation' => (string)(
                    $_POST['new_password_confirmation'] ?? ''
                ),
            ]
        );

        if($user->isAdmin()){
            $logService->recordCurrentRequest(
                $currentUserId,
                'admin_password_change',
                'user',
                $currentUserId,
                $changeResult['success'] ? 'success' : 'failure',
                $changeResult['success']
                    ? '管理员修改自己的登录密码。'
                    : $changeResult['message']
            );
        }

        if($changeResult['success']){
            $session->logout();
            $session->start();
            $session->setFlash(
                'success',
                '登录密码修改成功，请使用新密码重新登录。'
            );

            header('Location: ../login.php');
            exit;
        }

        $errorMessage = $changeResult['message'];
        $errors = $changeResult['errors'];
    }
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
    <title>修改登录密码｜易充充电管理系统</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/forms.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }

        .account-box {
            margin-bottom: 24px;
            padding: 18px;
            border-radius: 9px;
            background: #f5f8fb;
        }

        .account-label {
            margin-bottom: 5px;
            color: #78909c;
            font-size: 14px;
        }

        .account-value {
            margin: 0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 2) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">修改登录密码</h2>

            <p class="page-description">
                请验证当前密码，并设置符合安全要求的新密码。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="account-box">
                <div class="account-label">当前账户</div>

                <p class="account-value">
                    <?= View::escape($user->getUsername()) ?>
                </p>
            </div>

            <div class="rule-box">
                <p><strong>新密码要求：</strong></p>
                <p>1. 长度必须为8到20个字符。</p>
                <p>2. 至少包含一个大写英文字母。</p>
                <p>3. 至少包含一个小写英文字母。</p>
                <p>4. 至少包含一个数字。</p>
                <p>5. 至少包含一个特殊字符。</p>
                <p>6. 不能包含空格。</p>
                <p>7. 不能与当前密码相同。</p>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 2) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="current_password">
                        当前密码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'current_password'
                        ) ? 'input-error' : '' ?>"
                        type="password"
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >

                    <?php if(
                        View::firstError($errors, 'current_password') !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'current_password'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="new_password">
                        新密码
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
                        8到20个字符，必须同时包含大小写字母、数字和特殊字符。
                    </p>

                    <?php if(
                        View::firstError($errors, 'new_password') !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'new_password'
                                )
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

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        确认修改密码
                    </button>

                    <a class="secondary-button" href="profile.php">
                        返回个人资料
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>