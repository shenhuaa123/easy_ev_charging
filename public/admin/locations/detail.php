<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$locationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($locationId === false || $locationId === null){
    $session->setFlash('error', '充电站点编号不合法。');
    header('Location: index.php');
    exit;
}

$location = $locationRepository->findById($locationId);

if($location === null){
    $session->setFlash('error', '未找到指定的充电站点。');
    header('Location: index.php');
    exit;
}

$stations = $stationRepository->findByLocationId($locationId);

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回站点列表',
        'href' => 'index.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
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
    <title>充电站点详情｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1200px;
        }

        .page-header {
            align-items: center;
        }

        .card {
            margin-bottom: 22px;
            padding: 24px;
        }

        .detail-grid {
            gap: 18px 28px;
        }

        .detail-item {
            min-width: 0;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            min-width: 940px;
        }

        @media(max-width: 760px){
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .detail-item.full-width {
                grid-column: auto;
            }

            .button-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title"><?= View::escape($location->getLocationName()) ?></h2>
                <p class="page-description">查看站点资料及所属充电桩。</p>
            </div>

            <div class="button-group">
                <a
                    class="primary-button"
                    href="edit.php?id=<?= View::escape((string) $locationId) ?>"
                >
                    编辑站点资料
                </a>

                <a
                    class="secondary-button"
                    href="status.php?id=<?= View::escape((string) $locationId) ?>"
                >
                    修改站点状态
                </a>

                <a
                    class="secondary-button"
                    href="../stations/create.php?location_id=<?= View::escape((string) $locationId) ?>"
                >
                    新增充电桩
                </a>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <section class="card">
            <h3 class="card-title">站点基础信息</h3>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">数据库编号</div>
                    <p class="detail-value"><?= View::escape((string) $locationId) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">站点业务编号</div>
                    <p class="detail-value"><?= View::escape($location->getLocationCode()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前状态</div>
                    <p class="detail-value">
                        <span class="status-badge <?= View::escape(
                            View::statusClass($location->getStatus())
                        ) ?>">
                            <?= View::escape($location->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">充电桩数量</div>
                    <p class="detail-value"><?= View::escape((string) count($stations)) ?> 台</p>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">完整地址</div>
                    <p class="detail-value"><?= View::escape($location->getFullAddress()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">经度</div>
                    <p class="detail-value">
                        <?= View::escape($location->getLongitude() ?? '未填写') ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">纬度</div>
                    <p class="detail-value">
                        <?= View::escape($location->getLatitude() ?? '未填写') ?>
                    </p>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">站点说明</div>
                    <p class="detail-value">
                        <?= View::escape($location->getDescription() ?? '暂无说明') ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">创建时间</div>
                    <p class="detail-value"><?= View::escape($location->getCreatedAt()) ?></p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">最后更新时间</div>
                    <p class="detail-value"><?= View::escape($location->getUpdatedAt()) ?></p>
                </div>
            </div>
        </section>

        <section class="card">
            <h3 class="card-title">所属充电桩</h3>

            <?php if($stations === []): ?>
                <div class="empty-state">
                    该站点目前还没有充电桩。
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>充电桩编号</th>
                                <th>充电桩名称</th>
                                <th>充电类型</th>
                                <th>功率</th>
                                <th>每小时费用</th>
                                <th>设备状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($stations as $station): ?>
                                <?php $stationId = $station->getStationId(); ?>
                                <tr>
                                    <td><?= View::escape($station->getStationCode()) ?></td>
                                    <td><?= View::escape($station->getStationName()) ?></td>
                                    <td><?= View::escape($station->getChargerTypeLabel()) ?></td>
                                    <td><?= View::escape($station->getPowerKwLabel()) ?></td>
                                    <td><?= View::escape($station->getHourlyRateLabel()) ?></td>

                                    <td>
                                        <span class="status-badge <?= View::escape(
                                            View::statusClass($station->getStatus())
                                        ) ?>">
                                            <?= View::escape($station->getStatusLabel()) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if($stationId !== null): ?>
                                            <a
                                                class="action-link primary"
                                                href="../stations/detail.php?id=<?= View::escape((string)$stationId) ?>"
                                            >
                                                查看详情
                                            </a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
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