<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\ChargingStationRepository;
use App\Repositories\LocationRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\ChargingStationService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);

$stationService = new ChargingStationService(
    $connection,
    $stationRepository,
    $locationRepository,
    $userRepository
);

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
$hasActiveRecord = $stationRepository->hasActiveChargeRecord($stationId);
$locationStatus = $location?->getStatus();

$canActivate = $locationStatus === 'active';
$canSetMaintenance = $locationStatus !== null
    && $locationStatus !== 'inactive'
    && !$hasActiveRecord;
$canSetInactive = !$hasActiveRecord;

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $newStatus = trim((string)($_POST['status'] ?? ''));

        $updateResult = $stationService->updateStatus(
            $currentUserId,
            $stationId,
            $newStatus
        );

        $logService->recordCurrentRequest(
           $currentUserId,
           'station_status_update',
           'station',
           $stationId,
           $updateResult['success'] ? 'success' : 'failure',
           '目标状态：' . $newStatus . '；结果：' . $updateResult['message']
        );

        if($updateResult['success']){
            $session->setFlash('success', $updateResult['message']);
            header('Location: detail.php?id=' . $stationId);
            exit;
        }

        $errorMessage = $updateResult['message'];
        $station = $stationRepository->findById($stationId);

        if($station === null){
            $session->setFlash('error', '充电桩已不存在。');
            header('Location: index.php');
            exit;
        }

        $location = $locationRepository->findById($station->getLocationId());
        $hasActiveRecord = $stationRepository->hasActiveChargeRecord($stationId);
        $locationStatus = $location?->getStatus();

        $canActivate = $locationStatus === 'active';
        $canSetMaintenance = $locationStatus !== null
            && $locationStatus !== 'inactive'
            && !$hasActiveRecord;
        $canSetInactive = !$hasActiveRecord;
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
        'label' => '返回充电桩详情',
        'href' => 'detail.php?id=' . $stationId,
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
    ],
];

function getStatusDescription(string $status): string
{
    return match($status){
        'active' => '设备状态正常，并且所属站点处于运营状态时，可以被用户选择。',
        'maintenance' => '设备正在检修，用户不能创建新的充电订单。',
        'inactive' => '设备已停止使用，但历史订单和设备资料仍会保留。',
        default => '未知状态。',
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
    <title>修改充电桩状态｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 820px;
        }

        .page-header {
            display: block;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">修改充电桩状态</h2>

            <p class="page-description">
                设备状态会影响用户是否可以选择该充电桩开始充电。
            </p>
        </section>

        <section class="form-card">
            <div class="station-info">
                <p>
                    <strong>充电桩名称：</strong>
                    <?= View::escape($station->getStationName()) ?>
                </p>

                <p>
                    <strong>充电桩编号：</strong>
                    <?= View::escape($station->getStationCode()) ?>
                </p>

                <p>
                    <strong>充电类型：</strong>
                    <?= View::escape($station->getChargerTypeLabel()) ?>
                </p>

                <p>
                    <strong>功率：</strong>
                    <?= View::escape($station->getPowerKwLabel()) ?>
                </p>

                <p>
                    <strong>每小时费用：</strong>
                    <?= View::escape($station->getHourlyRateLabel()) ?>
                </p>

                <p>
                    <strong>当前设备状态：</strong>
                    <?= View::escape($station->getStatusLabel()) ?>
                </p>

                <p>
                    <strong>所属站点：</strong>
                    <?= View::escape(
                        $location === null
                            ? '所属站点不存在'
                            : $location->getLocationName()
                    ) ?>
                </p>

                <p>
                    <strong>所属站点状态：</strong>
                    <?= View::escape(
                        $location === null
                            ? '未知'
                            : $location->getStatusLabel()
                    ) ?>
                </p>
            </div>

            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if($hasActiveRecord): ?>
                <div class="warning-box">
                    当前充电桩正在被用户使用，暂时不能修改为“维护中”或“已停用”。请先等待当前订单结束。
                </div>
            <?php endif; ?>

            <?php if($location === null): ?>
                <div class="warning-box">
                    所属站点资料不存在，当前充电桩只能调整为“已停用”。
                </div>
            <?php elseif($locationStatus === 'inactive'): ?>
                <div class="warning-box">
                    所属站点已经停用，当前充电桩不能调整为“可用”或“维护中”，只能保持或切换为“已停用”。
                </div>
            <?php elseif($locationStatus === 'maintenance'): ?>
                <div class="warning-box">
                    所属站点正在维护，当前充电桩不能调整为“可用”，但可以设置为“维护中”或“已停用”。
                </div>
            <?php elseif(!$hasActiveRecord): ?>
                <div class="warning-box">
                    所属站点正在运营，当前充电桩可以在“可用”“维护中”和“已停用”之间手动切换。
                </div>
            <?php endif; ?>

            <form method="post" action="" data-submit-lock>
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="status-list">
                    <label class="status-option <?= !$canActivate ? 'status-option-disabled' : '' ?>">
                        <input
                            type="radio"
                            name="status"
                            value="active"
                            <?= $station->getStatus() === 'active' ? 'checked' : '' ?>
                            <?= !$canActivate ? 'disabled' : '' ?>
                            required
                        >

                        <span class="status-title">可用</span>

                        <span class="status-description">
                            <?= View::escape(getStatusDescription('active')) ?>
                        </span>
                    </label>

                    <label class="status-option <?= !$canSetMaintenance ? 'status-option-disabled' : '' ?>">
                        <input
                            type="radio"
                            name="status"
                            value="maintenance"
                            <?= $station->getStatus() === 'maintenance' ? 'checked' : '' ?>
                            <?= !$canSetMaintenance ? 'disabled' : '' ?>
                            required
                        >

                        <span class="status-title">维护中</span>

                        <span class="status-description">
                            <?= View::escape(getStatusDescription('maintenance')) ?>
                        </span>
                    </label>

                    <label class="status-option <?= !$canSetInactive ? 'status-option-disabled' : '' ?>">
                        <input
                            type="radio"
                            name="status"
                            value="inactive"
                            <?= $station->getStatus() === 'inactive' ? 'checked' : '' ?>
                            <?= !$canSetInactive ? 'disabled' : '' ?>
                            required
                        >

                        <span class="status-title">已停用</span>

                        <span class="status-description">
                            <?= View::escape(getStatusDescription('inactive')) ?>
                        </span>
                    </label>
                </div>

                <div class="form-actions">
                    <button
                        class="primary-button"
                        type="submit"
                        data-loading-text="正在保存状态..."
                    >
                        保存状态
                    </button>

                    <a class="secondary-button" href="detail.php?id=<?= View::escape((string)$stationId) ?>">
                        返回充电桩详情
                    </a>
                </div>
            </form>
        </section>
    </main>
    <script src="../../assets/js/form_submit_lock.js"></script>
</body>
</html>