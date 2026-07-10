<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

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

$activeUserRecord = $recordRepository->findActiveByUserId($userId);
$summary = $recordRepository->getUserHistorySummary($userId);
$recentRecordItems = $recordRepository->findUserHistoryItems($userId, 10, 0);
$flashMessages = $session->getFlashMessages();

$totalOrders = $summary['total_records'];
$settledOrders = $summary['settled_count'];
$totalBillableMinutes = $summary['total_billable_minutes'];
$totalCost = (float)$summary['total_cost'];

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回用户列表',
        'href' => 'index.php',
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
    <title>用户详情｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1300px;
        }

        .active-order-box {
            margin-bottom: 22px;
            padding: 17px;
            border: 1px solid #90caf9;
            border-radius: 9px;
            background: #e3f2fd;
            color: #1565c0;
        }

        .no-active-order-box {
            margin-bottom: 22px;
            padding: 17px;
            border: 1px solid #cfd8dc;
            border-radius: 9px;
            background: #f5f8fb;
            color: #546e7a;
        }

        .card {
            margin-bottom: 22px;
            padding: 24px;
        }

        .detail-grid {
            gap: 20px 28px;
        }

        .summary-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 22px;
        }

        .summary-card {
            padding: 20px;
        }

        .summary-label {
            margin-bottom: 7px;
        }

        .summary-value {
            font-size: 22px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            min-width: 1250px;
        }

        @media(max-width: 900px){
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
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
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">
                    <?= View::escape($user->getRealName()) ?>
                </h2>

                <p class="page-description">
                    查看用户资料、账户状态和充电记录。
                </p>
            </div>

            <div class="button-group">
                <?php if(
                    !$user->isAdmin()
                    && $userId !== $currentUser->getUserId()
                ): ?>
                    <a
                        class="primary-button"
                        href="edit.php?id=<?= View::escape((string)$userId) ?>"
                    >
                        编辑用户资料
                    </a>

                    <a
                        class="secondary-button"
                        href="reset_password.php?id=<?= View::escape((string)$userId) ?>"
                    >
                        重置登录密码
                    </a>

                    <?php if($user->isActive()): ?>
                        <a
                            class="danger-button"
                            href="status.php?id=<?= View::escape((string)$userId) ?>"
                        >
                            停用账户
                        </a>
                    <?php else: ?>
                        <a
                            class="success-button"
                            href="status.php?id=<?= View::escape((string)$userId) ?>"
                        >
                            启用账户
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if($activeUserRecord !== null): ?>
            <section class="active-order-box">
                <strong>该用户当前有正在进行的充电订单。</strong>

                订单编号：
                <?= View::escape($activeUserRecord->getOrderNumber()) ?>，

                开始时间：
                <?= View::escape($activeUserRecord->getCheckInAt()) ?>。
            </section>
        <?php else: ?>
            <section class="no-active-order-box">
                该用户当前没有正在进行的充电订单。
            </section>
        <?php endif; ?>

        <section class="card">
            <h3 class="card-title">用户基础资料</h3>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">用户编号</div>
                    <p class="detail-value"><?= View::escape((string)$userId) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">用户名</div>
                    <p class="detail-value"><?= View::escape($user->getUsername()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">真实姓名</div>
                    <p class="detail-value"><?= View::escape($user->getRealName()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">手机号码</div>
                    <p class="detail-value"><?= View::escape($user->getMobile()) ?></p>
                </div>

                <div class="detail-item">
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
                        <?= View::escape($user->getLastLoginAt() ?? '从未登录') ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">注册时间</div>
                    <p class="detail-value"><?= View::escape($user->getCreatedAt()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">最后更新时间</div>
                    <p class="detail-value"><?= View::escape($user->getUpdatedAt()) ?></p>
                </div>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">全部充电订单</div>
                <p class="summary-value"><?= View::escape((string)$totalOrders) ?> 笔</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">已结算订单</div>
                <p class="summary-value"><?= View::escape((string)$settledOrders) ?> 笔</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计计费时长</div>
                <p class="summary-value">
                    <?= View::escape(View::formatMinutes($totalBillableMinutes)) ?>
                </p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计结算金额</div>
                <p class="summary-value">
                    ￥<?= View::escape(number_format($totalCost, 2, '.', '')) ?>
                </p>
            </article>
        </section>

        <section class="card">
            <h3 class="card-title">最近充电记录</h3>

            <?php if($recentRecordItems === []): ?>
                <div class="empty-state">
                    该用户当前还没有充电记录。
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>订单编号</th>
                                <th>充电站点</th>
                                <th>充电桩</th>
                                <th>开始时间</th>
                                <th>结束时间</th>
                                <th>计费时长</th>
                                <th>结算金额</th>
                                <th>状态</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($recentRecordItems as $recordItem): ?>
                                <?php
                                $record = $recordItem['record'];
                                $station = $recordItem['station'];
                                $location = $recordItem['location'];
                                ?>

                                <tr>
                                    <td><?= View::escape($record->getOrderNumber()) ?></td>

                                    <td>
                                        <?php if($location !== null): ?>
                                            <?= View::escape($location['location_name']) ?>
                                        <?php else: ?>
                                            <span class="missing-data">
                                                站点资料不存在
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if($station !== null): ?>
                                            <a
                                                href="../stations/detail.php?id=<?= View::escape(
                                                    (string)$station['station_id']
                                                ) ?>"
                                            >
                                                <?= View::escape($station['station_name']) ?>
                                            </a>

                                            <br>

                                            <small>
                                                <?= View::escape($station['station_code']) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="missing-data">
                                                充电桩资料不存在
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= View::escape($record->getCheckInAt()) ?></td>

                                    <td>
                                        <?= View::escape(
                                            $record->getCheckOutAt() ?? '尚未结束'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= View::escape(
                                            $record->getBillableMinutesLabel()
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= View::escape($record->getTotalCostLabel()) ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?= View::escape(
                                            View::statusClass(
                                                $record->getStatus()
                                            )
                                        ) ?>">
                                            <?= View::escape($record->getStatusLabel()) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>