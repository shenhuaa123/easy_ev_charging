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

$chargeRecordId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($chargeRecordId === false || $chargeRecordId === null){
    $session->setFlash('error', '充电订单编号不合法。');
    header('Location: history.php');
    exit;
}

$record = $recordRepository->findById($chargeRecordId);

if(
    $record === null
    || $record->getUserId() !== $currentUserId
){
    $session->setFlash('error', '未找到指定的充电订单。');
    header('Location: history.php');
    exit;
}

$station = $stationRepository->findById(
    $record->getStationId()
);

$location = null;

if($station !== null){
    $location = $locationRepository->findById(
        $station->getLocationId()
    );
}

$remark = $record->getRemark();
$flashMessages = $session->getFlashMessages();

$statusDescription = match($record->getStatus()){
    'charging' => '该订单正在进行，计费时长和最终费用将在充电结束后确定。',
    'completed' => '该订单已经正常完成，以下信息为最终结算结果。',
    'abnormal' => '该订单已由管理员异常结束，请查看订单备注了解具体原因。',
    'cancelled' => '该订单已经取消，不会继续进行充电。',
    default => '当前订单状态无法识别，请联系系统管理员。',
};

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回用户中心',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回充电记录',
        'href' => 'history.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
    ],
];

function getRecordBannerClass(string $status): string
{
    return match($status){
        'charging' => 'banner-charging',
        'completed' => 'banner-completed',
        'abnormal' => 'banner-abnormal',
        'cancelled' => 'banner-cancelled',
        default => 'banner-unknown',
    };
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
    <title>充电订单详情｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1000px;
        }

        .status-banner {
            margin-bottom: 22px;
            padding: 17px 19px;
            border-radius: 9px;
        }

        .status-banner strong {
            display: block;
            margin-bottom: 5px;
        }

        .banner-charging {
            border: 1px solid #90caf9;
            background: #e3f2fd;
            color: #1565c0;
        }

        .banner-completed {
            border: 1px solid #81c784;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .banner-abnormal {
            border: 1px solid #ef9a9a;
            background: #ffebee;
            color: #c62828;
        }

        .banner-cancelled {
            border: 1px solid #cfd8dc;
            background: #eceff1;
            color: #546e7a;
        }

        .banner-unknown {
            border: 1px solid #ffcc80;
            background: #fff8e1;
            color: #e65100;
        }

        .card {
            margin-bottom: 22px;
            padding: 24px;
        }

        .detail-grid {
            gap: 20px 28px;
        }

        .status-badge {
            min-width: 82px;
        }

        .remark-box {
            padding: 16px;
            border-radius: 8px;
            background: #f5f8fb;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .notice {
            margin-top: 18px;
            padding: 14px 16px;
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
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">充电订单详情</h2>

                <p class="page-description">
                    订单编号：<?= View::escape($record->getOrderNumber()) ?>
                </p>
            </div>

            <div class="button-group">
                <?php if($record->isCharging()): ?>
                    <a class="primary-button" href="current.php">
                        查看当前充电
                    </a>
                <?php endif; ?>

                <?php if($location !== null): ?>
                    <a
                        class="secondary-button"
                        href="../locations/detail.php?id=<?= View::escape(
                            (string)$location->getLocationId()
                        ) ?>"
                    >
                        查看充电站点
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <section class="status-banner <?= View::escape(
            getRecordBannerClass($record->getStatus())
        ) ?>">
            <strong>
                当前订单状态：<?= View::escape($record->getStatusLabel()) ?>
            </strong>

            <?= View::escape($statusDescription) ?>
        </section>

        <section class="card">
            <h3 class="card-title">订单信息</h3>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">订单编号</div>

                    <p class="detail-value">
                        <?= View::escape($record->getOrderNumber()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">订单状态</div>

                    <p class="detail-value">
                        <span class="status-badge <?= View::escape(
                            View::statusClass($record->getStatus())
                        ) ?>">
                            <?= View::escape($record->getStatusLabel()) ?>
                        </span>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">开始充电时间</div>

                    <p class="detail-value">
                        <?= View::escape($record->getCheckInAt()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">结束充电时间</div>

                    <p class="detail-value">
                        <?= View::escape(
                            $record->getCheckOutAt() ?? '尚未结束'
                        ) ?>
                    </p>
                </div>
            </div>
        </section>

        <section class="card">
            <h3 class="card-title">计费信息</h3>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">订单费率</div>

                    <p class="detail-value">
                        <?= View::escape(
                            $record->getHourlyRateSnapshotLabel()
                        ) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">计费时长</div>

                    <p class="detail-value">
                        <?= View::escape(
                            $record->getBillableMinutesLabel()
                        ) ?>
                    </p>
                </div>

                <div class="detail-item full-width">
                    <div class="detail-label">最终费用</div>

                    <p class="detail-value">
                        <?= View::escape($record->getTotalCostLabel()) ?>
                    </p>
                </div>
            </div>

            <div class="notice">
                本订单按照开始充电时保存的费率快照结算，之后设备费率发生变化不会影响本订单。
            </div>
        </section>

        <section class="card">
            <h3 class="card-title">充电桩与站点</h3>

            <?php if($station === null): ?>
                <p class="missing-data">
                    订单关联的充电桩资料不存在，请联系系统管理员。
                </p>
            <?php else: ?>
                <div class="detail-grid">
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
                            <?= View::escape(
                                $station->getChargerTypeLabel()
                            ) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">充电功率</div>

                        <p class="detail-value">
                            <?= View::escape($station->getPowerKwLabel()) ?>
                        </p>
                    </div>
                </div>

                <?php if($location !== null): ?>
                    <hr>

                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">充电站点</div>

                            <p class="detail-value">
                                <?= View::escape(
                                    $location->getLocationName()
                                ) ?>
                            </p>
                        </div>

                        <div class="detail-item">
                            <div class="detail-label">站点编号</div>

                            <p class="detail-value">
                                <?= View::escape(
                                    $location->getLocationCode()
                                ) ?>
                            </p>
                        </div>

                        <div class="detail-item full-width">
                            <div class="detail-label">站点地址</div>

                            <p class="detail-value">
                                <?= View::escape(
                                    $location->getFullAddress()
                                ) ?>
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="missing-data">
                        充电桩所属站点资料不存在，请联系系统管理员。
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <h3 class="card-title">订单备注</h3>

            <div class="remark-box">
                <?= View::escape(
                    $remark === null || trim($remark) === ''
                        ? '无备注'
                        : $remark
                ) ?>
            </div>
        </section>
    </main>
</body>
</html>