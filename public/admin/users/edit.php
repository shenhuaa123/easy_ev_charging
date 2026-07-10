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

if($user->isAdmin()){
    $session->setFlash('error', '当前功能不允许修改管理员账户资料。');
    header('Location: detail.php?id=' . $userId);
    exit;
}

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'username' => $user->getUsername(),
    'real_name' => $user->getRealName(),
    'mobile' => $user->getMobile(),
    'email' => $user->getEmail() ?? '',
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'username' => trim((string)($_POST['username'] ?? '')),
        'real_name' => trim((string)($_POST['real_name'] ?? '')),
        'mobile' => trim((string)($_POST['mobile'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $updateResult = $userService->updateManagedProfile($currentUserId, $userId, $oldData);

        $logService->recordCurrentRequest(
            $currentUserId,
            'user_profile_update',
            'user',
            $userId,
            $updateResult['success'] ? 'success' : 'failure',
            $updateResult['success']
                ? '修改用户资料成功：' . $oldData['username']
                : $updateResult['message']
        );

        if($updateResult['success']){
            $session->setFlash('success', $updateResult['message']);
            header('Location: detail.php?id=' . $userId);
            exit;
        }

        $errorMessage = $updateResult['message'];
        $errors = $updateResult['errors'];

        $user = $userRepository->findById($userId);

        if($user === null){
            $session->setFlash('error', '目标用户已不存在。');
            header('Location: index.php');
            exit;
        }

        if($user->isAdmin()){
            $session->setFlash('error', '当前功能不允许修改管理员账户资料。');
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
    <title>编辑用户资料｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

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
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">编辑用户资料</h2>

            <p class="page-description">
                修改普通用户的登录用户名和基础联系资料。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                修改用户名后，用户下次登录必须使用新的用户名。请确认已经通知用户。
            </div>

            <div class="readonly-grid">
                <div>
                    <div class="readonly-label">用户编号</div>

                    <p class="readonly-value">
                        <?= View::escape((string)$userId) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">账户状态</div>

                    <p class="readonly-value">
                        <?= View::escape($user->getStatusLabel()) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">账户角色</div>

                    <p class="readonly-value">
                        <?= View::escape($user->getRoleLabel()) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">注册时间</div>

                    <p class="readonly-value">
                        <?= View::escape($user->getCreatedAt()) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>
                
                <div class="form-group">
                    <label class="form-label" for="username">
                        用户名
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control <?= View::hasError(
                            $errors,
                            'username'
                        ) ? 'input-error' : '' ?>"
                        type="text"
                        id="username"
                        name="username"
                        value="<?= View::escape($oldData['username']) ?>"
                        minlength="3"
                        maxlength="30"
                        pattern="[A-Za-z0-9]+"
                        required
                    >

                    <p class="field-help">
                        请输入3到30位英文字母或数字，不能包含中文、空格和符号。
                    </p>

                    <?php if(View::firstError($errors, 'username') !== null): ?>
                        <p class="field-error">
                            <?= View::escape(View::firstError($errors, 'username')) ?>
                        </p>
                    <?php endif; ?>
                </div>

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
                        保存用户资料
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