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

$locations = $locationRepository->findAll();

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'station_code' => $station->getStationCode(),
    'station_name' => $station->getStationName(),
    'location_id' => (string)$station->getLocationId(),
    'charger_type' => $station->getChargerType(),
    'power_kw' => $station->getPowerKw(),
    'hourly_rate' => $station->getHourlyRate(),
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'station_code' => trim((string)($_POST['station_code'] ?? '')),
        'station_name' => trim((string)($_POST['station_name'] ?? '')),
        'location_id' => trim((string)($_POST['location_id'] ?? '')),
        'charger_type' => trim((string)($_POST['charger_type'] ?? '')),
        'power_kw' => trim((string)($_POST['power_kw'] ?? '')),
        'hourly_rate' => trim((string)($_POST['hourly_rate'] ?? '')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $updateResult = $stationService->update(
            $currentUserId,
            $stationId,
            $oldData
        );

        $logService->recordCurrentRequest(
            $currentUserId,
            'station_update',
            'station',
            $stationId,
            $updateResult['success'] ? 'success' : 'failure',
            $updateResult['success']
                ? '编辑充电桩成功：' . $oldData['station_code'] . ' / ' . $oldData['station_name']
                : $updateResult['message']
        );

        if($updateResult['success']){
            $session->setFlash('success', $updateResult['message']);
            header('Location: detail.php?id=' . $stationId);
            exit;
        }

        $errorMessage = $updateResult['message'];
        $errors = $updateResult['errors'];
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

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑充电桩｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 950px;
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
            <h2 class="page-title">编辑充电桩</h2>

            <p class="page-description">
                修改设备编号、名称、所属站点、充电类型、功率和收费信息。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <div class="readonly-grid">
                <div>
                    <div class="readonly-label">数据库编号</div>

                    <p class="readonly-value">
                        <?= View::escape((string)$stationId) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">当前设备状态</div>

                    <p class="readonly-value">
                        <?= View::escape($station->getStatusLabel()) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="station_code">
                            充电桩编号
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'station_code'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="station_code"
                            name="station_code"
                            value="<?= View::escape($oldData['station_code']) ?>"
                            minlength="3"
                            maxlength="50"
                            pattern="[A-Za-z0-9_-]+"
                            required
                        >

                        <p class="field-help">
                            只能包含英文字母、数字、下划线和连字符。
                        </p>

                        <?php if(View::firstError($errors, 'station_code') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'station_code')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="station_name">
                            充电桩名称
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'station_name'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="station_name"
                            name="station_name"
                            value="<?= View::escape($oldData['station_name']) ?>"
                            minlength="2"
                            maxlength="100"
                            required
                        >

                        <?php if(View::firstError($errors, 'station_name') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'station_name')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label" for="location_id">
                            所属充电站点
                            <span class="required-mark">*</span>
                        </label>

                        <select
                            class="form-control <?= View::hasError(
                                $errors,
                                'location_id'
                            ) ? 'input-error' : '' ?>"
                            id="location_id"
                            name="location_id"
                            required
                        >
                            <option value="">请选择所属充电站点</option>

                            <?php foreach($locations as $location): ?>
                                <?php
                                $locationId = $location->getLocationId();

                                if($locationId === null){
                                    continue;
                                }
                                ?>

                                <option
                                    value="<?= View::escape((string)$locationId) ?>"
                                    <?= (string)$locationId === $oldData['location_id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= View::escape($location->getLocationName()) ?>
                                    （<?= View::escape($location->getLocationCode()) ?>）
                                    - <?= View::escape($location->getStatusLabel()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if(View::firstError($errors, 'location_id') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'location_id')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="charger_type">
                            充电类型
                            <span class="required-mark">*</span>
                        </label>

                        <select
                            class="form-control <?= View::hasError(
                                $errors,
                                'charger_type'
                            ) ? 'input-error' : '' ?>"
                            id="charger_type"
                            name="charger_type"
                            required
                        >
                            <option value="">请选择充电类型</option>

                            <option
                                value="ac"
                                <?= $oldData['charger_type'] === 'ac'
                                    ? 'selected'
                                    : '' ?>
                            >
                                交流充电
                            </option>

                            <option
                                value="dc"
                                <?= $oldData['charger_type'] === 'dc'
                                    ? 'selected'
                                    : '' ?>
                            >
                                直流充电
                            </option>
                        </select>

                        <?php if(View::firstError($errors, 'charger_type') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'charger_type')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="power_kw">
                            充电功率（千瓦）
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'power_kw'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="power_kw"
                            name="power_kw"
                            value="<?= View::escape($oldData['power_kw']) ?>"
                            maxlength="7"
                            placeholder="例如：60.00"
                            required
                        >

                        <p class="field-help">
                            必须大于0，最多保留两位小数，最大1000千瓦。
                        </p>

                        <?php if(View::firstError($errors, 'power_kw') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'power_kw')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="hourly_rate">
                            每小时费用（元）
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'hourly_rate'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="hourly_rate"
                            name="hourly_rate"
                            value="<?= View::escape($oldData['hourly_rate']) ?>"
                            maxlength="11"
                            placeholder="例如：6.00"
                            required
                        >

                        <p class="field-help">
                            可以为0，最多保留两位小数。
                        </p>

                        <?php if(View::firstError($errors, 'hourly_rate') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'hourly_rate')) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        保存充电桩资料
                    </button>

                    <a
                        class="secondary-button"
                        href="detail.php?id=<?= View::escape((string)$stationId) ?>"
                    >
                        返回充电桩详情
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>