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

$currentUser = $authGuard->requireUser(
    '../../login.php',
    '../../admin/dashboard.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', '../../login.php');
}

$activeUserRecord = $recordRepository->findActiveByUserId($currentUserId);
$station = null;
$location = null;
$currentDurationLabel = '';

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回用户中心',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
    ],
];

$flashMessages = $session->getFlashMessages();

if($activeUserRecord !== null){
    $station = $stationRepository->findById($activeUserRecord->getStationId());

    if($station !== null){
        $location = $locationRepository->findById($station->getLocationId());
    }

    $currentDurationLabel = calculateCurrentDurationLabel(
        $activeUserRecord->getCheckInAt()
    );
}

function calculateCurrentDurationLabel(string $checkInAt): string
{
    $checkInDateTime = new \DateTimeImmutable($checkInAt);
    $currentDateTime = new \DateTimeImmutable();

    $durationSeconds = $currentDateTime->getTimestamp()
        - $checkInDateTime->getTimestamp();

    if($durationSeconds < 0){
        return '时间数据异常';
    }

    $durationMinutes = max(1, (int) ceil($durationSeconds / 60));

    if($durationMinutes < 60){
        return $durationMinutes . ' 分钟';
    }

    $hours = intdiv($durationMinutes, 60);
    $remainingMinutes = $durationMinutes % 60;

    if($remainingMinutes === 0){
        return $hours . ' 小时';
    }

    return $hours . ' 小时 ' . $remainingMinutes . ' 分钟';
}

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>当前充电｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">

    <style>
        .page {
            max-width: 900px;
        }

        .page-header {
            display: block;
        }

        .card {
            padding: 28px;
        }

        .status-panel {
            margin-bottom: 24px;
            padding: 18px;
            border: 1px solid #81c784;
            border-radius: 10px;
            background: #e8f5e9;
            text-align: center;
        }

        .status-title {
            margin: 0 0 6px;
            color: #2e7d32;
            font-size: 22px;
        }

        .status-description {
            margin: 0;
            color: #388e3c;
        }

        .detail-grid {
            gap: 20px 28px;
        }

        .duration-value {
            color: #1565c0;
            font-size: 20px;
        }

        .price-value {
            color: #d84315;
            font-size: 18px;
        }

        .notice {
            margin-top: 24px;
            padding: 14px 16px;
            border: 1px solid #90caf9;
            border-radius: 8px;
            background: #e3f2fd;
            color: #1565c0;
        }

        .warning {
            margin-top: 18px;
            padding: 14px 16px;
            border: 1px solid #ffcc80;
            border-radius: 8px;
            background: #fff8e1;
            color: #e65100;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 24px;
        }

        .empty-state {
            padding: 42px 24px;
            text-align: center;
        }

        .empty-title {
            margin: 0 0 10px;
            color: #546e7a;
        }

        .empty-description {
            margin: 0 0 24px;
            color: #78909c;
        }

        .current-danger {
            margin: 24px 0 0;
        }

        @media(max-width: 700px){
            .actions {
                flex-direction: column;
            }

            .actions a {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">当前充电</h2>
            <p class="page-description">
                查看正在进行的充电订单，并在完成后结束充电。
            </p>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <section class="card">
            <?php if($activeUserRecord === null): ?>
                <div class="empty-state">
                    <h3 class="empty-title">当前没有正在进行的充电订单</h3>

                    <p class="empty-description">
                        您可以前往充电站点列表，选择一台可用的充电桩。
                    </p>

                    <a class="primary-button" href="../locations/index.php">
                        查看充电站点
                    </a>

                    <a class="secondary-button" href="history.php">
                        查看充电记录
                    </a>
                </div>
            <?php else: ?>
                <div class="status-panel">
                    <h3 class="status-title">正在充电</h3>
                    <p class="status-description">
                        订单正在进行中，请在充电完成后及时结束订单。
                    </p>
                </div>

                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">订单编号</div>
                        <p class="detail-value">
                            <?= View::escape($activeUserRecord->getOrderNumber()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">订单状态</div>
                        <p class="detail-value">
                            <?= View::escape($activeUserRecord->getStatusLabel()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">开始时间</div>
                        <p class="detail-value">
                            <?= View::escape($activeUserRecord->getCheckInAt()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">当前计费时长</div>
                        <p class="detail-value duration-value">
                            <?= View::escape($currentDurationLabel) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">费率快照</div>
                        <p class="detail-value price-value">
                            <?= View::escape($activeUserRecord->getHourlyRateSnapshotLabel()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">预计最低费用</div>
                        <p class="detail-value">
                            ￥<?= View::escape(number_format(
                                (float) $activeUserRecord->getHourlyRateSnapshot() / 60,
                                2,
                                '.',
                                ''
                            )) ?>
                        </p>
                    </div>

                    <?php if($station !== null): ?>
                        <div class="detail-item">
                            <div class="detail-label">充电桩名称</div>
                            <p class="detail-value">
                                <?= View::escape($station->getStationName()) ?>
                            </p>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">充电桩编号</div>
                            <p class="detail-value">
                                <?= View::escape($station->getStationCode()) ?>
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
                    <?php endif; ?>

                    <?php if($location !== null): ?>
                        <div class="detail-item">
                            <div class="detail-label">充电站点</div>
                            <p class="detail-value">
                                <?= View::escape($location->getLocationName()) ?>
                            </p>
                        </div>

                        <div class="detail-item full-width">
                            <div class="detail-label">站点地址</div>
                            <p class="detail-value">
                                <?= View::escape($location->getFullAddress()) ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if($activeUserRecord->getRemark() !== null): ?>
                        <div class="detail-item full-width">
                            <div class="detail-label">订单备注</div>
                            <p class="detail-value">
                                <?= View::escape($activeUserRecord->getRemark()) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="notice">
                    当前显示的时长是根据开始时间实时估算的计费分钟数。最终计费时长和费用将在结束充电时由服务器重新计算并写入订单。
                </div>

                <div class="warning">
                    点击“结束充电”后，订单将完成结算，并释放当前充电桩供其他用户使用。请确认车辆已经停止充电。
                </div>

                <?php if($station === null || $location === null): ?>
                    <div class="danger-box current-danger">
                        订单关联的站点或充电桩资料异常。您仍可以结束订单，但建议同时联系管理员检查数据。
                    </div>
                <?php endif; ?>

                <div class="actions">
                    <a
                        class="danger-button"
                        href="finish.php?id=<?= View::escape(
                            (string) $activeUserRecord->getChargeRecordId()
                        ) ?>"
                    >
                        结束充电
                    </a>

                    <a
                        class="secondary-button"
                        href="detail.php?id=<?= View::escape(
                            (string)$activeUserRecord->getChargeRecordId()
                        ) ?>"
                    >
                        查看订单详情
                    </a>

                    <a class="secondary-button" href="history.php">
                        查看充电记录
                    </a>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>