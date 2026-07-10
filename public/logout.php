<?php

declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\AdminOperationLogRepository;
use App\Services\AdminOperationLogService;

require_once dirname(__DIR__) . '/bootstrap.php';

$connection = $database->getConnection();

$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$session = new Session();
$session->start();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    Logger::security('非法请求方法：退出登录只允许POST。', [
        'actual_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    ]);
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('请求方法不允许。退出登录只能通过系统中的退出按钮执行。');
}

if(!$session->isLoggedIn()){
    $session->setFlash('info', '您当前尚未登录。');
    header('Location: login.php');
    exit;
}

$csrf = new Csrf($session);

if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(Csrf::INVALID_MESSAGE);
}

$currentUserId = $session->getUserId();
$isAdminLogout = $session->isAdmin();

if($isAdminLogout && $currentUserId !== null){
    try{
        $logService->recordCurrentRequest(
            $currentUserId,
            'admin_logout',
            'user',
            $currentUserId,
            'success',
            '管理员主动退出登录。'
        );
    }catch(\Throwable $exception){
        Logger::error('管理员退出登录审计日志写入失败。', [
            'exception' => $exception,
            'user_id' => $currentUserId,
        ]);
    }
}

Logger::app('用户主动退出登录。', [
    'user_id' => $currentUserId,
    'is_admin' => $isAdminLogout,
]);

$session->logout();
$session->start();
$session->setFlash('success', '您已安全退出系统。');

header('Location: login.php');
exit;