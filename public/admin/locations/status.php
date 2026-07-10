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
use App\Services\LocationService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$locationRepository = new LocationRepository($connection);
$stationRepository = new ChargingStationRepository($connection);

$locationService = new LocationService(
    $connection,
    $locationRepository,
    $stationRepository,
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

$hasActiveRecords = $locationRepository->hasActiveChargeRecords($locationId);

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errorMessage = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $newStatus = trim((string)($_POST['status'] ?? ''));

        $updateResult = $locationService->updateStatus(
            $currentUserId,
            $locationId,
            $newStatus
        );

        $logService->recordCurrentRequest(
           $currentUserId,
           'location_status_update',
           'location',
           $locationId,
           $updateResult['success'] ? 'success' : 'failure',
           '目标状态：' . $newStatus . '；结果：' . $updateResult['message']
        );

        if($updateResult['success']){
            $session->setFlash('success', $updateResult['message']);
            header('Location: detail.php?id=' . $locationId);
            exit;
        }

        $errorMessage = $updateResult['message'];
        $location = $locationRepository->findById($locationId);

        if($location === null){
            $session->setFlash('error', '充电站点已不存在。');
            header('Location: index.php');
            exit;
        }

        $hasActiveRecords = $locationRepository->hasActiveChargeRecords($locationId);
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
        'label' => '返回站点详情',
        'href' => 'detail.php?id=' . $locationId,
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
        'active' => '站点恢复运营，但所属充电桩不会自动恢复状态，需要管理员逐台检查和调整。',
        'maintenance' => '站点进入维护后，所属可用充电桩会自动调整为维护中，已停用设备保持不变。',
        'inactive' => '站点停用后，所属可用或维护中的充电桩会自动调整为停用状态。',
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
    <title>修改站点状态｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 800px;
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
            <h2 class="page-title">修改充电站点状态</h2>
            <p class="page-description">
                状态修改会影响用户是否能够在该站点开始新的充电。
            </p>
        </section>

        <section class="form-card">
            <div class="station-info">
                <p>
                    <strong>站点名称：</strong>
                    <?= View::escape($location->getLocationName()) ?>
                </p>

                <p>
                    <strong>站点编号：</strong>
                    <?= View::escape($location->getLocationCode()) ?>
                </p>

                <p>
                    <strong>当前状态：</strong>
                    <?= View::escape($location->getStatusLabel()) ?>
                </p>

                <p>
                    <strong>完整地址：</strong>
                    <?= View::escape($location->getFullAddress()) ?>
                </p>
            </div>

            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if($hasActiveRecords): ?>
                <div class="warning-box">
                    当前站点仍有用户正在充电，暂时不能修改为“维护中”或“已停用”。
                </div>
            <?php else: ?>
                <div class="warning-box">
                    状态修改会自动联动所属充电桩。站点恢复运营时，充电桩不会自动恢复，需要管理员逐台检查后手动调整。
                </div>
            <?php endif; ?>

            <form method="post" action="" data-submit-lock>
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="status-list">
                    <label class="status-option">
                        <input
                            type="radio"
                            name="status"
                            value="active"
                            <?= $location->getStatus() === 'active' ? 'checked' : '' ?>
                            required
                        >

                        <span class="status-title">运营中</span>
                        <span class="status-description">
                            <?= View::escape(getStatusDescription('active')) ?>
                        </span>
                    </label>

                    <label class="status-option <?= $hasActiveRecords ? 'status-option-disabled' : '' ?>">
                        <input
                            type="radio"
                            name="status"
                            value="maintenance"
                            <?= $location->getStatus() === 'maintenance' ? 'checked' : '' ?>
                            <?= $hasActiveRecords ? 'disabled' : '' ?>
                        >

                        <span class="status-title">维护中</span>
                        <span class="status-description">
                            <?= View::escape(getStatusDescription('maintenance')) ?>
                        </span>
                    </label>

                    <label class="status-option <?= $hasActiveRecords ? 'status-option-disabled' : '' ?>">
                        <input
                            type="radio"
                            name="status"
                            value="inactive"
                            <?= $location->getStatus() === 'inactive' ? 'checked' : '' ?>
                            <?= $hasActiveRecords ? 'disabled' : '' ?>
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

                    <a
                        class="secondary-button"
                        href="detail.php?id=<?= View::escape((string) $locationId) ?>"
                    >
                        返回站点详情
                    </a>
                </div>
            </form>
        </section>
    </main>
    <script src="../../assets/js/form_submit_lock.js"></script>
</body>
</html>