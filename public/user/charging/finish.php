<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use App\Services\ChargeBillingCalculator;
use App\Services\ChargeRecordService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$recordService = new ChargeRecordService(
    $connection,
    $recordRepository,
    $stationRepository,
    $locationRepository,
    $userRepository
);

$billingCalculator = new ChargeBillingCalculator();
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
    header('Location: current.php');
    exit;
}

$record = $recordRepository->findById($chargeRecordId);

if($record === null){
    $session->setFlash('error', '未找到指定的充电订单。');
    header('Location: current.php');
    exit;
}

if($record->getUserId() !== $currentUserId){
    $session->setFlash('error', '您无权查看或结束其他用户的充电订单。');
    header('Location: current.php');
    exit;
}

if(!$record->isCharging()){
    $session->setFlash('info', '该充电订单已经结束，无需重复操作。');
    header('Location: detail.php?id=' . $chargeRecordId);
    exit;
}

$station = $stationRepository->findById($record->getStationId());
$location = null;

if($station !== null){
    $location = $locationRepository->findById($station->getLocationId());
}

$estimatedSettlement = $billingCalculator->calculate(
    $record->getCheckInAt(),
    date('Y-m-d H:i:s'),
    $record->getHourlyRateSnapshot()
);

$estimatedBillableMinutes = $estimatedSettlement['billable_minutes'];
$estimatedTotalCost = $estimatedSettlement['total_cost'];

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }elseif((string)($_POST['confirmed'] ?? '') !== '1'){
        $errorMessage = '请确认车辆已经停止充电，并同意结束订单。';
    }else{
        $finishResult = $recordService->finishCharging(
            $currentUserId,
            $chargeRecordId
        );

        if($finishResult['success']){
            $session->setFlash(
                'success',
                '充电已结束。本次计费时长为'
                . (string) $finishResult['billable_minutes']
                . '分钟，结算金额为￥'
                . (string) $finishResult['total_cost']
                . '。'
            );

            header('Location: detail.php?id=' . $chargeRecordId);
            exit;
        }

        $errorMessage = $finishResult['message'];

        $record = $recordRepository->findById($chargeRecordId);

        if($record === null){
            $session->setFlash('error', '充电订单已不存在。');
            header('Location: current.php');
            exit;
        }

        if(!$record->isCharging()){
            $session->setFlash('info', '该充电订单已经结束，以下是最终订单详情。');
            header('Location: detail.php?id=' . $chargeRecordId);
            exit;
        }

        $estimatedSettlement = $billingCalculator->calculate(
            $record->getCheckInAt(),
            date('Y-m-d H:i:s'),
            $record->getHourlyRateSnapshot()
        );

        $estimatedBillableMinutes = $estimatedSettlement['billable_minutes'];
        $estimatedTotalCost = $estimatedSettlement['total_cost'];
    }
}

$topbarTheme = 'user';
$topbarIdentityLabel = '用户';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回用户中心',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回当前充电',
        'href' => 'current.php',
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
    <title>确认结束充电｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }

        .detail-grid {
            gap: 20px 28px;
            margin-bottom: 24px;
        }

        .estimated-value {
            color: #d84315;
            font-size: 19px;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">确认结束充电</h2>
            <p class="page-description">
                请确认车辆已经停止充电，再提交结束订单。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="warning-box">
                结束订单后，系统会立即计算最终计费分钟数和费用，并释放当前充电桩供其他用户使用。订单结束后不能恢复为充电中状态。
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">订单编号</div>
                    <p class="detail-value">
                        <?= View::escape($record->getOrderNumber()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前状态</div>
                    <p class="detail-value">
                        <?= View::escape($record->getStatusLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">开始时间</div>
                    <p class="detail-value">
                        <?= View::escape($record->getCheckInAt()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">费率快照</div>
                    <p class="detail-value">
                        <?= View::escape($record->getHourlyRateSnapshotLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前预计计费时长</div>
                    <p class="detail-value estimated-value">
                        <?= View::escape(View::formatMinutes($estimatedBillableMinutes)) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">当前预计费用</div>
                    <p class="detail-value estimated-value">
                        ￥<?= View::escape($estimatedTotalCost) ?>
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
                        <div class="detail-label">充电功率</div>
                        <p class="detail-value">
                            <?= View::escape($station->getPowerKwLabel()) ?>
                        </p>
                    </div>

                    <div class="detail-item">
                        <div class="detail-label">充电类型</div>
                        <p class="detail-value">
                            <?= View::escape($station->getChargerTypeLabel()) ?>
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

                <?php if($record->getRemark() !== null): ?>
                    <div class="detail-item full-width">
                        <div class="detail-label">订单备注</div>
                        <p class="detail-value">
                            <?= View::escape($record->getRemark()) ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if($station === null || $location === null): ?>
                <div class="danger-box">
                    订单关联的充电桩或站点资料异常，但订单仍可根据开始时间和费率快照完成结算。
                </div>
            <?php endif; ?>

            <div class="rule-box">
                <p><strong>结算说明：</strong></p>
                <p>1. 当前显示的是页面打开时的预计结果。</p>
                <p>2. 提交后服务器会使用最新时间重新计算。</p>
                <p>3. 不足1分钟按1分钟计费，超过整分钟的部分向上取整。</p>
                <p>4. 最终金额按照费率快照计算，不受充电桩当前费率变化影响。</p>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="confirm-group">
                    <label>
                        <input
                            type="checkbox"
                            name="confirmed"
                            value="1"
                            required
                        >
                        我已确认车辆停止充电，并同意结束订单和完成结算。
                    </label>
                </div>

                <div class="form-actions">
                    <button class="danger-button" type="submit">
                        确认结束并结算
                    </button>

                    <a class="secondary-button" href="current.php">
                        返回当前充电
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>