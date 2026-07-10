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

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireUser(
    '../../login.php',
    '../../admin/dashboard.php'
);

$stationId = filter_input(
    INPUT_GET,
    'station_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($stationId === false || $stationId === null){
    $session->setFlash('error', '充电桩编号不合法。');
    header('Location: ../locations/index.php');
    exit;
}

$station = $stationRepository->findById($stationId);

if($station === null){
    $session->setFlash('error', '未找到指定的充电桩。');
    header('Location: ../locations/index.php');
    exit;
}

$location = $locationRepository->findById($station->getLocationId());

if($location === null){
    $session->setFlash('error', '该充电桩所属站点不存在。');
    header('Location: ../locations/index.php');
    exit;
}

if(!$location->isActive()){
    $session->setFlash('error', '该充电站点当前未运营，不能开始充电。');
    header('Location: ../locations/index.php');
    exit;
}

if(!$station->isActive()){
    $session->setFlash('error', '该充电桩当前不可使用。');
    header('Location: ../locations/detail.php?id=' . $location->getLocationId());
    exit;
}

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', '../../login.php');
}

$activeUserRecord = $recordRepository->findActiveByUserId($currentUserId);

if($activeUserRecord !== null){
    $session->setFlash('error', '您当前已有正在进行的充电订单。');
    header('Location: current.php');
    exit;
}

$activeStationRecord = $recordRepository->findActiveByStationId($stationId);

if($activeStationRecord !== null){
    $session->setFlash('error', '该充电桩当前正在使用，请选择其他充电桩。');
    header('Location: ../locations/detail.php?id=' . $location->getLocationId());
    exit;
}

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errorMessage = '';
$remark = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $remark = trim((string)($_POST['remark'] ?? ''));

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }elseif((string)($_POST['confirmed'] ?? '') !== '1'){
        $errorMessage = '请确认站点、充电桩编号、充电功率和收费规则。';
    }else{
        $startResult = $recordService->startCharging(
            $currentUserId,
            $stationId,
            $remark === '' ? null : $remark
        );

        if($startResult['success']){
            $session->setFlash(
                'success',
                '充电已开始，订单编号：' . (string)$startResult['order_number']
            );

            header('Location: current.php');
            exit;
        }

        $errorMessage = $startResult['message'];
        $station = $stationRepository->findById($stationId);

        if($station === null){
            $session->setFlash('error', '该充电桩已不存在。');
            header('Location: ../locations/index.php');
            exit;
        }

        $location = $locationRepository->findById($station->getLocationId());

        if($location === null){
            $session->setFlash('error', '该充电桩所属站点已不存在。');
            header('Location: ../locations/index.php');
            exit;
        }
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
    <title>确认开始充电｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 850px;
        }

        .page-header {
            display: block;
        }

        .device-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px 28px;
            margin-bottom: 24px;
        }

        .device-item.full-width {
            grid-column: 1 / -1;
        }

        .item-label {
            margin-bottom: 5px;
            color: #78909c;
            font-size: 14px;
        }

        .item-value {
            margin: 0;
            font-weight: bold;
            word-break: break-word;
        }

        .price {
            color: #d84315;
            font-size: 20px;
        }

        textarea.form-control {
            min-height: 100px;
        }

        @media(max-width: 700px){
            .device-grid {
                grid-template-columns: 1fr;
            }

            .device-item.full-width {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">确认开始充电</h2>
            <p class="page-description">
                请确认站点、设备和收费信息无误后再开始充电。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="device-grid">
                <div class="device-item">
                    <div class="item-label">充电站点</div>
                    <p class="item-value">
                        <?= View::escape($location->getLocationName()) ?>
                    </p>
                </div>

                <div class="device-item">
                    <div class="item-label">站点编号</div>
                    <p class="item-value">
                        <?= View::escape($location->getLocationCode()) ?>
                    </p>
                </div>

                <div class="device-item full-width">
                    <div class="item-label">站点地址</div>
                    <p class="item-value">
                        <?= View::escape($location->getFullAddress()) ?>
                    </p>
                </div>

                <div class="device-item">
                    <div class="item-label">充电桩名称</div>
                    <p class="item-value">
                        <?= View::escape($station->getStationName()) ?>
                    </p>
                </div>

                <div class="device-item">
                    <div class="item-label">充电桩编号</div>
                    <p class="item-value">
                        <?= View::escape($station->getStationCode()) ?>
                    </p>
                </div>

                <div class="device-item">
                    <div class="item-label">充电类型</div>
                    <p class="item-value">
                        <?= View::escape($station->getChargerTypeLabel()) ?>
                    </p>
                </div>

                <div class="device-item">
                    <div class="item-label">充电功率</div>
                    <p class="item-value">
                        <?= View::escape($station->getPowerKwLabel()) ?>
                    </p>
                </div>

                <div class="device-item full-width">
                    <div class="item-label">当前每小时费用</div>
                    <p class="item-value price">
                        <?= View::escape($station->getHourlyRateLabel()) ?>
                    </p>
                </div>
            </div>

            <div class="rule-box">
                <p><strong>计费规则：</strong></p>
                <p>1. 开始充电时，系统会保存当前每小时费率作为订单费率快照。</p>
                <p>2. 充电费用按计费分钟数计算，不足1分钟按1分钟计费。</p>
                <p>3. 超过整分钟的部分向上计入下一分钟。</p>
                <p>4. 最终费用按照“每小时费率 × 计费分钟数 ÷ 60”计算。</p>
            </div>

            <div class="warning-box">
                开始充电后，在当前订单结束前，您不能同时使用其他充电桩。请确认已经到达对应设备，并核对设备编号。
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="remark">订单备注</label>

                    <textarea
                        class="form-control"
                        id="remark"
                        name="remark"
                        maxlength="500"
                        placeholder="选填，例如：车辆停放位置或其他需要记录的信息。"
                    ><?= View::escape($remark) ?></textarea>

                    <p class="field-help">
                        选填，最多500个字符。
                    </p>
                </div>

                <div class="confirm-group">
                    <label>
                        <input
                            type="checkbox"
                            name="confirmed"
                            value="1"
                            required
                        >
                        我已确认站点、充电桩编号、充电功率和收费规则。
                    </label>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        确认开始充电
                    </button>

                    <a
                        class="secondary-button"
                        href="../locations/detail.php?id=<?= View::escape(
                            (string) $location->getLocationId()
                        ) ?>"
                    >
                        返回站点详情
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>