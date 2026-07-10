<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$connection = $database->getConnection();
$userRepository = new UserRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireLogin(
    '../login.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', '../login.php');
}

/*
 * 重新查询数据库，避免继续显示登录时保存在会话中的旧资料。
 */
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

$identityLabel = $user->isAdmin()
    ? '管理员'
    : '用户';

$topbarTheme = $user->isAdmin() ? 'admin' : 'user';
$topbarIdentityLabel = $identityLabel;
$topbarDisplayName = $user->getRealName();
$topbarLinks = [
    [
        'label' => $dashboardLabel,
        'href' => $dashboardPath,
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../logout.php',
    ],
];

$flashMessages = $session->getFlashMessages();
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>个人资料｜易充充电管理系统</title>
    <link rel="stylesheet" href="../assets/css/common.css">

    <style>
        .page {
            max-width: 950px;
        }

        .profile-card {
            padding: 28px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
        }

        .profile-heading {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 26px;
            padding-bottom: 22px;
            border-bottom: 1px solid #eceff1;
        }

        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #e3f2fd;
            color: #1565c0;
            font-size: 28px;
            font-weight: bold;
        }

        .profile-name {
            margin: 0 0 5px;
            font-size: 22px;
        }

        .profile-username {
            margin: 0;
            color: #78909c;
        }

        .detail-grid {
            gap: 22px 30px;
        }

        .detail-label {
            margin-bottom: 6px;
        }

        .role-badge,
        .status-badge {
            display: inline-block;
            min-width: 76px;
            padding: 5px 10px;
            border-radius: 999px;
            text-align: center;
            font-size: 13px;
            font-weight: bold;
        }

        .role-admin {
            background: #ede7f6;
            color: #5e35b1;
        }

        .role-user {
            background: #e3f2fd;
            color: #1565c0;
        }

        .role-unknown {
            background: #fff3e0;
            color: #ef6c00;
        }

        .status-active {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .status-disabled {
            background: #ffebee;
            color: #c62828;
        }

        .status-unknown {
            background: #eceff1;
            color: #546e7a;
        }

        .notice {
            margin-top: 26px;
            padding: 15px 17px;
            border: 1px solid #90caf9;
            border-radius: 8px;
            background: #e3f2fd;
            color: #1565c0;
        }

        @media(max-width: 700px){
            .button-group {
                width: 100%;
                flex-direction: column;
            }

            .button-group a {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 2) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">个人资料</h2>

                <p class="page-description">
                    查看和维护您的账户资料与登录密码。
                </p>
            </div>

            <div class="button-group">
                <a class="primary-button" href="edit_profile.php">
                    编辑个人资料
                </a>

                <a class="secondary-button" href="change_password.php">
                    修改登录密码
                </a>
            </div>
        </section>

        <?php require dirname(__DIR__, 2) . '/views/partials/flash_messages.php'; ?>

        <section class="profile-card">
            <div class="profile-heading">
                <div class="avatar">
                    <?= View::escape(
                        mb_substr(
                            $user->getRealName(),
                            0,
                            1,
                            'UTF-8'
                        )
                    ) ?>
                </div>

                <div>
                    <h3 class="profile-name">
                        <?= View::escape($user->getRealName()) ?>
                    </h3>

                    <p class="profile-username">
                        用户名：<?= View::escape($user->getUsername()) ?>
                    </p>
                </div>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">用户编号</div>

                    <p class="detail-value">
                        <?= View::escape((string)$user->getUserId()) ?>
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

                <div class="detail-item full-width">
                    <div class="detail-label">电子邮箱</div>

                    <p class="detail-value">
                        <?= View::escape($user->getEmail() ?? '未填写') ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">账户角色</div>

                    <p class="detail-value">
                        <span class="role-badge <?= View::escape(
                            View::roleClass($user->getRole())
                        ) ?>">
                            <?= View::escape($user->getRoleLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">账户状态</div>

                    <p class="detail-value">
                        <span class="status-badge <?= View::escape(
                            View::statusClass($user->getStatus())
                        ) ?>">
                            <?= View::escape($user->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">最近登录时间</div>

                    <p class="detail-value">
                        <?= View::escape(
                            $user->getLastLoginAt() ?? '暂无登录记录'
                        ) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">注册时间</div>

                    <p class="detail-value">
                        <?= View::escape($user->getCreatedAt()) ?>
                    </p>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">资料最后更新时间</div>

                    <p class="detail-value">
                        <?= View::escape($user->getUpdatedAt()) ?>
                    </p>
                </div>
            </div>

            <div class="notice">
                用户名、账户角色和账户状态不能在个人资料页面修改。如发现账户信息异常，请联系系统管理员。
            </div>
        </section>
    </main>
</body>
</html>