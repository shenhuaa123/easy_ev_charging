<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;

require_once dirname(__DIR__) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);

$authService = new AuthService($userRepository);

$session = new Session();
$session->start();

if($session->isLoggedIn()){
    if($session->isAdmin()){
        header('Location: admin/dashboard.php');
        exit;
    }

    header('Location: user/dashboard.php');
    exit;
}

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'username' => '',
    'real_name' => '',
    'mobile' => '',
    'email' => '',
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
        $registerResult = $authService->register([
            'username' => $oldData['username'],
            'real_name' => $oldData['real_name'],
            'mobile' => $oldData['mobile'],
            'email' => $oldData['email'],
            'password' => (string)($_POST['password'] ?? ''),
            'password_confirmation' => (string)(
                $_POST['password_confirmation'] ?? ''
            ),
        ]);

        if($registerResult['success']){
            Logger::app('用户注册成功。', [
                'user_id' => $registerResult['user_id'],
                'username' => $oldData['username'],
            ]);
            $session->setFlash('success', '注册成功，请使用新账户登录。');
            header('Location: login.php');
            exit;
        }else{
            $errors = $registerResult['errors'];
        }
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

    <title>用户注册｜易充充电管理系统</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/forms.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-register">
    <main class="page">
        <section class="register-card">
            <h1 class="page-title">
                创建用户账户
            </h1>

            <p class="page-description">
                注册后可以查看充电站点、选择充电桩并查询充电记录。
            </p>

            <?php if($errorMessage !== ''): ?>
                <div class="message message-error">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php require dirname(__DIR__) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="username"
                    >
                        用户名
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'username'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="text"
                        id="username"
                        name="username"
                        value="<?= View::escape($oldData['username']) ?>"
                        minlength="3"
                        maxlength="30"
                        pattern="[A-Za-z0-9]+"
                        autocomplete="username"
                        required
                    >

                    <p class="field-help">
                        请输入3到30位英文字母或数字，不能包含中文、空格和符号。
                    </p>

                    <?php if(
                        View::firstError(
                            $errors,
                            'username'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'username'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="real_name"
                    >
                        真实姓名
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'real_name'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="text"
                        id="real_name"
                        name="real_name"
                        value="<?= View::escape(
                            $oldData['real_name']
                        ) ?>"
                        minlength="2"
                        maxlength="20"
                        autocomplete="name"
                        required
                    >

                    <p class="field-help">
                        请输入2到20个汉字。
                    </p>

                    <?php if(
                        View::firstError(
                            $errors,
                            'real_name'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'real_name'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="mobile"
                    >
                        手机号码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'mobile'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="tel"
                        id="mobile"
                        name="mobile"
                        value="<?= View::escape(
                            $oldData['mobile']
                        ) ?>"
                        maxlength="11"
                        inputmode="numeric"
                        pattern="1[3-9][0-9]{9}"
                        autocomplete="tel"
                        placeholder="请输入11位中国大陆手机号码"
                        required
                    >

                    <?php if(
                        View::firstError(
                            $errors,
                            'mobile'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'mobile'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="email"
                    >
                        电子邮箱
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'email'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="email"
                        id="email"
                        name="email"
                        value="<?= View::escape(
                            $oldData['email']
                        ) ?>"
                        maxlength="100"
                        autocomplete="email"
                        placeholder="选填"
                    >

                    <?php if(
                        View::firstError(
                            $errors,
                            'email'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'email'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="password"
                    >
                        密码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'password'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        maxlength="20"
                        autocomplete="new-password"
                        required
                    >

                    <p class="field-help">
                        密码长度为8到20个字符，必须包含大写英文字母、小写英文字母、数字和特殊字符，且不能包含空格、汉字或其他文字。
                    </p>

                    <?php if(
                        View::firstError(
                            $errors,
                            'password'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'password'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label
                        class="form-label"
                        for="password_confirmation"
                    >
                        确认密码
                        <span class="required-mark">*</span>
                    </label>

                    <input
                        class="form-control
                            <?= View::firstError(
                                $errors,
                                'password_confirmation'
                            ) !== null
                                ? 'input-error'
                                : '' ?>"
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        minlength="8"
                        maxlength="20"
                        autocomplete="new-password"
                        required
                    >

                    <?php if(
                        View::firstError(
                            $errors,
                            'password_confirmation'
                        ) !== null
                    ): ?>
                        <p class="field-error">
                            <?= View::escape(
                                View::firstError(
                                    $errors,
                                    'password_confirmation'
                                )
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <button
                    class="primary-button auth-submit"
                    type="submit"
                >
                    注册账户
                </button>
            </form>

            <p class="login-link">
                已经拥有账户？
                <a href="login.php">
                    前往登录
                </a>
            </p>
        </section>
    </main>
</body>
</html>