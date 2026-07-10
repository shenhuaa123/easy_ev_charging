<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$stationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($stationId === false || $stationId === null){
    $session->setFlash('error', '充电桩编号不合法。');
    header('Location: index.php');
    exit;
}

$station = $stationRepository->findById($stationId);

if($station === null){
    $session->setFlash('error', '未找到指定的充电桩。');
    header('Location: index.php');
    exit;
}

$location = $locationRepository->findById($station->getLocationId());
$activeStationRecord = $recordRepository->findActiveByStationId($stationId);
$summary = $recordRepository->getStationHistorySummary($stationId);
$recentRecordItems = $recordRepository->findStationHistoryItems($stationId, 10, 0);
$flashMessages = $session->getFlashMessages();

$totalOrders = $summary['total_records'];
$settledOrders = $summary['settled_count'];
$totalBillableMinutes = $summary['total_billable_minutes'];
$totalRevenue = (float)$summary['total_revenue'];

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回充电桩列表',
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
    <title>充电桩详情｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1300px;
        }

        .card {
            margin-bottom: 22px;
            padding: 24px;
        }

        .detail-grid {
            gap: 18px 28px;
        }

        .occupied-box,
        .available-box,
        .unavailable-box {
            margin-bottom: 22px;
            padding: 18px;
            border-radius: 10px;
        }

        .occupied-box {
            border: 1px solid #ef9a9a;
            background: #ffebee;
            color: #c62828;
        }

        .available-box {
            border: 1px solid #81c784;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .unavailable-box {
            border: 1px solid #ffcc80;
            background: #fff8e1;
            color: #e65100;
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
            min-width: 1240px;
        }

        @media(max-width: 900px){
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title"><?= View::escape($station->getStationName()) ?></h2>
                <p class="page-description">查看设备资料、占用情况和历史经营数据。</p>
            </div>

            <div class="header-actions">
                <a
                    class="primary-button"
                    href="edit.php?id=<?= View::escape((string)$stationId) ?>"
                >
                    编辑充电桩资料
                </a>
                
                <a
                    class="primary-button"
                    href="status.php?id=<?= View::escape((string) $stationId) ?>"
                >
                    修改设备状态
                </a>

                <?php if($location !== null): ?>
                    <a
                        class="secondary-button"
                        href="../locations/detail.php?id=<?= View::escape(
                            (string) $location->getLocationId()
                        ) ?>"
                    >
                        查看所属站点
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if($activeStationRecord !== null): ?>
            <section class="occupied-box">
                <strong>当前设备正在使用。</strong>

                订单编号：
                <?= View::escape($activeStationRecord->getOrderNumber()) ?>，

                开始时间：
                <?= View::escape($activeStationRecord->getCheckInAt()) ?>。

                <a
                    href="../charge_records/detail.php?id=<?= View::escape(
                        (string)$activeStationRecord->getChargeRecordId()
                    ) ?>"
                >
                    查看当前订单
                </a>
            </section>
        <?php elseif($station->getStatus() !== 'active'): ?>
            <section class="unavailable-box">
                当前设备没有进行中的订单，但设备状态为“<?= View::escape(
                    $station->getStatusLabel()
                ) ?>”，暂时不能提供充电服务。
            </section>
        <?php elseif($location === null): ?>
            <section class="unavailable-box">
                当前设备没有进行中的订单，但所属站点资料不存在，暂时不能提供充电服务。
            </section>
        <?php elseif($location->getStatus() !== 'active'): ?>
            <section class="unavailable-box">
                当前设备没有进行中的订单，但所属站点状态为“<?= View::escape(
                    $location->getStatusLabel()
                ) ?>”，暂时不能提供充电服务。
            </section>
        <?php else: ?>
            <section class="available-box">
                当前没有进行中的充电订单，设备状态正常，可以提供充电服务。
            </section>
        <?php endif; ?>

        <section class="card">
            <h3 class="card-title">充电桩基础信息</h3>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">数据库编号</div>
                    <p class="detail-value"><?= View::escape((string) $stationId) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">设备业务编号</div>
                    <p class="detail-value"><?= View::escape($station->getStationCode()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">设备状态</div>

                    <p class="detail-value">
                        <span class="status-badge <?= View::escape(
                            View::statusClass($station->getStatus())
                        ) ?>">
                            <?= View::escape($station->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">充电类型</div>
                    <p class="detail-value">
                        <?= View::escape($station->getChargerTypeLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">充电功率</div>
                    <p class="detail-value">
                        <?= View::escape($station->getPowerKwLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前每小时费用</div>
                    <p class="detail-value">
                        <?= View::escape($station->getHourlyRateLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">创建时间</div>
                    <p class="detail-value"><?= View::escape($station->getCreatedAt()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">最后更新时间</div>
                    <p class="detail-value"><?= View::escape($station->getUpdatedAt()) ?></p>
                </div>
            </div>
        </section>

        <section class="card">
            <h3 class="card-title">所属站点</h3>

            <?php if($location === null): ?>
                <div class="empty-state">
                    所属站点资料不存在。
                </div>
            <?php else: ?>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">站点名称</div>
                        <p class="detail-value">
                            <?= View::escape($location->getLocationName()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">站点编号</div>
                        <p class="detail-value">
                            <?= View::escape($location->getLocationCode()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">站点状态</div>
                        <p class="detail-value">
                            <?= View::escape($location->getStatusLabel()) ?>
                        </p>
                    </div>

                    <div class="detail-item full-width">
                        <div class="detail-label">完整地址</div>
                        <p class="detail-value">
                            <?= View::escape($location->getFullAddress()) ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">全部历史订单</div>
                <p class="summary-value"><?= View::escape((string) $totalOrders) ?> 笔</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">已结算订单</div>
                <p class="summary-value"><?= View::escape((string) $settledOrders) ?> 笔</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计计费时长</div>
                <p class="summary-value">
                    <?= View::escape(View::formatMinutes($totalBillableMinutes)) ?>
                </p>
            </article>

            <article class="summary-card">
                <div class="summary-label">累计结算收入</div>
                <p class="summary-value">
                    ￥<?= View::escape(number_format($totalRevenue, 2, '.', '')) ?>
                </p>
            </article>
        </section>

        <section class="card">
            <h3 class="card-title">最近订单记录</h3>

            <?php if($recentRecordItems === []): ?>
                <div class="empty-state">
                    当前充电桩还没有订单记录。
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>订单编号</th>
                                <th>用户</th>
                                <th>开始时间</th>
                                <th>结束时间</th>
                                <th>计费时长</th>
                                <th>结算金额</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($recentRecordItems as $recordItem): ?>
                                <?php
                                $record = $recordItem['record'];
                                $recordUser = $recordItem['user'];
                                ?>

                                <tr>
                                    <td><?= View::escape($record->getOrderNumber()) ?></td>

                                    <td>
                                        <?php if($recordUser === null): ?>
                                            <span class="missing-data">用户资料不存在</span>
                                        <?php else: ?>
                                            <?= View::escape($recordUser['real_name']) ?>
                                            <br>
                                            <small><?= View::escape($recordUser['username']) ?></small>
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
                                            View::statusClass($record->getStatus())
                                        ) ?>">
                                            <?= View::escape($record->getStatusLabel()) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            class="action-link primary"
                                            href="../charge_records/detail.php?id=<?= View::escape(
                                                (string)$record->getChargeRecordId()
                                            ) ?>"
                                        >
                                            查看详情
                                        </a>
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