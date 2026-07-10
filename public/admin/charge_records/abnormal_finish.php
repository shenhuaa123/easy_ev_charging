<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
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

$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$currentUserId = $currentUser->getUserId();

if($currentUserId === null){
    $authGuard->logoutAndRedirect(
        '当前管理员信息异常，请重新登录。',
        '../../login.php'
    );
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
    header('Location: index.php');
    exit;
}

$record = $recordRepository->findById($chargeRecordId);

if($record === null){
    $session->setFlash('error', '未找到指定的充电订单。');
    header('Location: index.php');
    exit;
}

if(!$record->isCharging()){
    $session->setFlash('info', '该订单已经结束，不能再次执行异常结束。');
    header('Location: detail.php?id=' . $chargeRecordId);
    exit;
}

$recordUser = $userRepository->findById($record->getUserId());
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

$remark = '';
$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $remark = trim((string)($_POST['remark'] ?? ''));

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $confirmed = (string)($_POST['confirmed'] ?? '');

        if($confirmed !== '1'){
            $errorMessage = '请确认已经核实现场情况，并同意异常结束订单。';
        }elseif($remark === ''){
            $errorMessage = '异常结束订单时必须填写处理原因。';
        }elseif(mb_strlen($remark, 'UTF-8') > 500){
            $errorMessage = '异常处理原因不能超过500个字符。';
        }else{
            $finishResult = $recordService->finishAbnormally(
                $currentUserId,
                $chargeRecordId,
                $remark
            );

            $logService->recordCurrentRequest(
                $currentUserId,
                'charge_record_abnormal_finish',
                'charge_record',
                $chargeRecordId,
                $finishResult['success'] ? 'success' : 'failure',
                $finishResult['success']
                    ? '异常结束订单成功，原因：' . $remark
                    : $finishResult['message']
            );

            if($finishResult['success']){
                $session->setFlash(
                    'success',
                    '订单已异常结束。本次计费时长为'
                    . (string)$finishResult['billable_minutes']
                    . '分钟，结算金额为￥'
                    . (string)$finishResult['total_cost']
                    . '。'
                );

                header('Location: detail.php?id=' . $chargeRecordId);
                exit;
            }

            $errorMessage = $finishResult['message'];
            $record = $recordRepository->findById($chargeRecordId);

            if($record === null){
                $session->setFlash('error', '充电订单已不存在。');
                header('Location: index.php');
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
}

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '返回订单列表',
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
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>异常结束充电订单｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 900px;
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

        textarea.form-control {
            min-height: 130px;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">异常结束充电订单</h2>

            <p class="page-description">
                仅用于设备故障、通信异常或用户无法正常结束订单等特殊情况。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="danger-box">
                <p><strong>重要提示：</strong></p>
                <p>异常结束会立即完成订单结算，并释放当前用户和充电桩的占用状态。</p>
                <p>操作完成后，订单状态不能恢复为“充电中”。</p>
                <p>请确认现场情况，并填写清晰、真实的处理原因。</p>
            </div>

            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">订单数据库主键</div>

                    <p class="detail-value">
                        <?= View::escape((string) $record->getChargeRecordId()) ?>
                    </p>
                </div>

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
                    <div class="detail-label">预计计费时长</div>

                    <p class="detail-value estimated-value">
                        <?= View::escape(View::formatMinutes($estimatedBillableMinutes)) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">预计结算金额</div>

                    <p class="detail-value estimated-value">
                        ￥<?= View::escape($estimatedTotalCost) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">费率快照</div>

                    <p class="detail-value">
                        <?= View::escape($record->getHourlyRateSnapshotLabel()) ?>
                    </p>
                </div>

                <div class="detail-item">
                    <div class="detail-label">订单用户</div>

                    <p class="detail-value">
                        <?php if($recordUser !== null): ?>
                            <?= View::escape($recordUser->getRealName()) ?>
                            （<?= View::escape($recordUser->getUsername()) ?>）
                        <?php else: ?>
                            用户资料不存在
                        <?php endif; ?>
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
                        <div class="detail-label">原订单备注</div>

                        <p class="detail-value">
                            <?= View::escape($record->getRemark()) ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if(
                $recordUser === null
                || $station === null
                || $location === null
            ): ?>
                <div class="warning-box">
                    订单关联的用户、充电桩或站点资料存在异常，但订单仍可根据开始时间和费率快照完成结算。
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-group">
                    <label class="form-label" for="remark">
                        异常处理原因
                        <span class="required-mark">*</span>
                    </label>

                    <textarea
                        class="form-control"
                        id="remark"
                        name="remark"
                        maxlength="500"
                        placeholder="例如：充电桩通信中断，用户无法自行结束订单，管理员已核实现场情况并执行异常结束。"
                        required
                    ><?= View::escape($remark) ?></textarea>

                    <p class="field-help">
                        必填，最多500个字符。请说明异常原因和处理情况。
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
                        我已核实现场情况，并确认异常结束该订单和完成结算。
                    </label>
                </div>

                <div class="form-actions">
                    <button class="danger-button" type="submit">
                        确认异常结束
                    </button>

                    <a class="secondary-button" href="index.php">
                        返回订单列表
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>