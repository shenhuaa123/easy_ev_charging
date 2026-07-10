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

$oldData = [
    'real_name' => $user->getRealName(),
    'mobile' => $user->getMobile(),
    'email' => $user->getEmail() ?? '',
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'real_name' => trim((string)($_POST['real_name'] ?? '')),
        'mobile' => trim((string)($_POST['mobile'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $changedFields = [];
        $normalizedEmail = $oldData['email'] === '' ? null : $oldData['email'];

        if($user->getRealName() !== $oldData['real_name']){
            $changedFields[] = '真实姓名';
        }

        if($user->getMobile() !== $oldData['mobile']){
            $changedFields[] = '手机号码';
        }

        if($user->getEmail() !== $normalizedEmail){
            $changedFields[] = '电子邮箱';
        }

        $updateResult = $userService->updateProfile(
            $currentUserId,
            $oldData
        );

        if($user->isAdmin()){
            $changedFieldText = $changedFields === []
                ? '无字段变化'
                : implode('、', $changedFields);

            $logService->recordCurrentRequest(
                $currentUserId,
                'admin_profile_update',
                'user',
                $currentUserId,
                $updateResult['success'] ? 'success' : 'failure',
                $updateResult['success']
                    ? '管理员修改自己的个人资料，变更字段：' . $changedFieldText . '。'
                    : $updateResult['message']
            );
        }

        if($updateResult['success']){
            $session->setFlash(
                'success',
                $updateResult['message']
            );

            header('Location: profile.php');
            exit;
        }

        $errorMessage = $updateResult['message'];
        $errors = $updateResult['errors'];
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
    <title>编辑个人资料｜易充充电管理系统</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/forms.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 2) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">编辑个人资料</h2>

            <p class="page-description">
                修改真实姓名、手机号码和电子邮箱。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="readonly-grid">
                <div>
                    <div class="readonly-label">用户名</div>

                    <p class="readonly-value">
                        <?= View::escape($user->getUsername()) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">账户状态</div>

                    <p class="readonly-value">
                        <?= View::escape($user->getStatusLabel()) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 2) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="real_name">
                        真实姓名
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'real_name'
                        ) ? 'input-error' : '' ?>"
                        type="text"
                        id="real_name"
                        name="real_name"
                        value="<?= View::escape($oldData['real_name']) ?>"
                        minlength="2"
                        maxlength="20"
                        required
                    >

                    <p class="field-help">
                        请输入2到20个汉字。
                    </p>

                    <?php if(View::firstError($errors, 'real_name') !== null): ?>
                        <p class="field-error">
                            <?= View::escape(View::firstError($errors, 'real_name')) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="mobile">
                        手机号码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'mobile'
                        ) ? 'input-error' : '' ?>"
                        type="tel"
                        id="mobile"
                        name="mobile"
                        value="<?= View::escape($oldData['mobile']) ?>"
                        maxlength="11"
                        inputmode="numeric"
                        pattern="1[3-9][0-9]{9}"
                        required
                    >

                    <p class="field-help">
                        请输入11位中国大陆手机号码。
                    </p>

                    <?php if(View::firstError($errors, 'mobile') !== null): ?>
                        <p class="field-error">
                            <?= View::escape(View::firstError($errors, 'mobile')) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">
                        电子邮箱
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'email'
                        ) ? 'input-error' : '' ?>"
                        type="email"
                        id="email"
                        name="email"
                        value="<?= View::escape($oldData['email']) ?>"
                        maxlength="100"
                        placeholder="选填，例如：user@example.com"
                    >

                    <p class="field-help">
                        选填，最多100个字符。
                    </p>

                    <?php if(View::firstError($errors, 'email') !== null): ?>
                        <p class="field-error">
                            <?= View::escape(View::firstError($errors, 'email')) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        保存个人资料
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