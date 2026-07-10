<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\AuthService;
use App\Services\LoginThrottleService;

require_once dirname(__DIR__) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$loginAttemptRepository = new LoginAttemptRepository($connection);

$authService = new AuthService($userRepository);
$loginThrottleService = new LoginThrottleService($loginAttemptRepository);
$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$session = new Session();

$session->start();

/*
 * 已经登录的用户再次访问登录页时，
 * 直接跳转到对应控制台，避免重复登录。
 */
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

$errorMessage = '';

$oldUsername = '';

$flashMessages = $session->getFlashMessages();

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldUsername = trim((string)($_POST['username'] ?? ''));

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $password = (string)($_POST['password'] ?? '');
        $clientIp = $loginThrottleService->resolveClientIp($_SERVER);
        $throttleState = $loginThrottleService->check($oldUsername, $clientIp);

        if($throttleState['blocked']){
            Logger::security('登录请求被限制：账号或IP仍处于锁定期。', [
                'username' => $oldUsername,
                'client_ip' => $clientIp,
                'retry_after' => (int)$throttleState['retry_after'],
            ]);
            http_response_code(429);
            header('Retry-After: ' . (string)$throttleState['retry_after']);
            $errorMessage = LoginThrottleService::BLOCKED_MESSAGE;
        }else{
            $loginResult = $authService->login($oldUsername, $password);

            if($loginResult['success']){
                $user = $loginResult['user'];

                $loginThrottleService->clearSuccessfulLogin($oldUsername);
                $session->login($user);
                $csrf->regenerate();

                Logger::app('用户登录成功。', [
                    'user_id' => $user->getUserId(),
                    'username' => $user->getUsername(),
                    'role' => $user->getRole(),
                ]);

                if($user->isAdmin()){
                    $adminUserId = $user->getUserId();

                    if($adminUserId !== null){
                        try{
                            $logService->recordCurrentRequest(
                                $adminUserId,
                                'admin_login_success',
                                'user',
                                $adminUserId,
                                'success',
                                '管理员登录成功。'
                            );
                        }catch(\Throwable $exception){
                            Logger::error('管理员登录审计日志写入失败。', [
                                'exception' => $exception,
                                'user_id' => $adminUserId,
                            ]);
                        }
                    }

                    header('Location: admin/dashboard.php');
                    exit;
                }

                header('Location: user/dashboard.php');
                exit;
            }

            $failureState = $loginThrottleService->recordFailure(
                $oldUsername,
                $clientIp
            );

            Logger::security('用户登录失败。', [
                'username' => $oldUsername,
                'client_ip' => $clientIp,
                'blocked_after_failure' => (bool)$failureState['blocked'],
                'retry_after' => (int)$failureState['retry_after'],
            ]);

            if($failureState['blocked']){
                Logger::security('登录失败后触发登录限制。', [
                    'username' => $oldUsername,
                    'client_ip' => $clientIp,
                    'retry_after' => (int)$failureState['retry_after'],
                ]);
                http_response_code(429);
                header('Retry-After: ' . (string)$failureState['retry_after']);
                $errorMessage = LoginThrottleService::BLOCKED_MESSAGE;
            }else{
                $errorMessage = $loginResult['message'];
            }
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

    <title>用户登录｜易充充电管理系统</title>
    <link rel="stylesheet" href="assets/css/common.css">
    <link rel="stylesheet" href="assets/css/forms.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-login">
    <main class="page">
        <section class="login-card">
            <h1 class="system-name">
                易充充电管理系统
            </h1>

            <p class="page-description">
                请输入用户名和密码登录系统。
            </p>

            <?php require dirname(__DIR__) . '/views/partials/flash_messages.php'; ?>

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
                        class="form-control"
                        type="text"
                        id="username"
                        name="username"
                        value="<?= View::escape($oldUsername) ?>"
                        maxlength="30"
                        autocomplete="username"
                        autofocus
                        required
                    >
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
                        class="form-control"
                        type="password"
                        id="password"
                        name="password"
                        maxlength="20"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button
                    class="primary-button auth-submit"
                    type="submit"
                >
                    登录系统
                </button>
            </form>

            <p class="register-link">
                还没有账户？
                <a href="register.php">
                    立即注册
                </a>
            </p>
        </section>
    </main>
</body>
</html>