<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository(
    $connection
);

$session = new Session();

$authGuard = new AuthGuard(
    $session,
    $userRepository
);

/*
 * 检查内容包括：
 * 1. 是否已经登录；
 * 2. Session中的用户是否仍然存在；
 * 3. 账户是否仍为正常状态；
 * 4. 当前用户是否为普通用户。
 */
$currentUser = $authGuard->requireUser(
    '../login.php',
    '../admin/dashboard.php'
);

$realName = $currentUser->getRealName();

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $realName;
$topbarLinks = [
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

    <title>用户中心｜易充充电管理系统</title>
    <link rel="stylesheet" href="../assets/css/common.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
</head>
<body class="dashboard-user">
    <?php require dirname(__DIR__, 2) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <?php require dirname(__DIR__, 2) . '/views/partials/flash_messages.php'; ?>

        <section class="welcome-card">
            <h2>
                欢迎回来，
                <?= View::escape($realName) ?>
            </h2>

            <p>
                您可以在这里查找充电站点、选择可用充电桩、查看当前充电状态和历史订单。
            </p>
        </section>

        <section class="feature-grid">
            <article class="feature-card">
                <h3>查找充电站点</h3>

                <p>
                    通过站点名称、编号、地区或地址关键字查找特定充电站点。
                </p>

                <a class="feature-link" href="locations/search.php">
                    搜索充电站点
                </a>
            </article>

            <article class="feature-card">
                <h3>开始充电</h3>

                <p>
                    选择可用充电桩并创建新的充电订单。
                </p>

                <a class="feature-link" href="locations/index.php">
                    查看充电站点
                </a>
            </article>

            <article class="feature-card">
                <h3>当前充电状态</h3>

                <p>
                    查看当前使用的充电桩、开始时间、预计时长和预计费用。
                </p>

                <a class="feature-link" href="charging/current.php">
                    查看当前充电
                </a>
            </article>

            <article class="feature-card">
                <h3>历史充电记录</h3>

                <p>
                    查看已经完成的充电订单、实际时长、计费时长和费用。
                </p>

                <a class="feature-link" href="charging/history.php">
                    查看充电记录
                </a>
            </article>

            <article class="feature-card">
                <h3>个人资料</h3>

                <p>
                    查看和修改姓名、手机号码、电子邮箱以及登录密码。
                </p>

                <a class="feature-link" href="../account/profile.php">
                    查看个人资料
                </a>
            </article>
        </section>
    </main>
</body>
</html>