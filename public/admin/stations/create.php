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

$locations = $locationRepository->findAll();

$defaultLocationId = filter_input(
    INPUT_GET,
    'location_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($defaultLocationId === false){
    $defaultLocationId = null;
}

if($defaultLocationId !== null){
    $defaultLocation = $locationRepository->findById($defaultLocationId);

    if($defaultLocation === null){
        $session->setFlash('error', '默认选择的充电站点不存在。');
        header('Location: index.php');
        exit;
    }
}

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'station_code' => '',
    'station_name' => '',
    'location_id' => $defaultLocationId === null
        ? ''
        : (string) $defaultLocationId,
    'charger_type' => 'ac',
    'power_kw' => '',
    'hourly_rate' => '',
    'status' => 'active',
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'station_code' => trim((string)($_POST['station_code'] ?? '')),
        'station_name' => trim((string)($_POST['station_name'] ?? '')),
        'location_id' => trim((string)($_POST['location_id'] ?? '')),
        'charger_type' => trim((string)($_POST['charger_type'] ?? '')),
        'power_kw' => trim((string)($_POST['power_kw'] ?? '')),
        'hourly_rate' => trim((string)($_POST['hourly_rate'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'active')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $createResult = $stationService->create($currentUserId, $oldData);
        $createdStationId = isset($createResult['station_id'])
            ? (int)$createResult['station_id']
            : null;

        $logService->recordCurrentRequest(
            $currentUserId,
            'station_create',
            'station',
            $createdStationId,
            $createResult['success'] ? 'success' : 'failure',
            $createResult['success']
                ? '新增充电桩成功：' . $oldData['station_code'] . ' / ' . $oldData['station_name']
                : (string)($createResult['message'] ?? '充电桩新增验证失败。')
        );

        if($createResult['success']){
            $session->setFlash('success', '充电桩新增成功。');

            if($oldData['location_id'] !== ''){
                header('Location: index.php?location_id=' . urlencode($oldData['location_id']));
                exit;
            }

            header('Location: index.php');
            exit;
        }

        $errorMessage = (string)($createResult['message'] ?? '');
        $errors = $createResult['errors'];
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
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>新增充电桩｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 900px;
        }

        .page-header {
            display: block;
        }

        .empty-warning {
            padding: 18px;
            border: 1px solid #ffcc80;
            border-radius: 8px;
            background: #fff8e1;
            color: #e65100;
        }

        .empty-warning a {
            color: #bf360c;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <h2 class="page-title">新增充电桩</h2>
            <p class="page-description">
                请填写充电桩的编号、名称、所属站点、充电类型、功率和收费标准。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if($locations === []): ?>
                <div class="empty-warning">
                    当前系统中还没有充电站点，不能新增充电桩。
                    请先前往
                    <a href="../locations/create.php">新增充电站点</a>。
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="station_code">
                                充电桩编号
                                <span class="required-mark">*</span>
                            </label>

                            <input
                                class="form-control <?= View::hasError($errors, 'station_code') ? 'input-error' : '' ?>"
                                type="text"
                                id="station_code"
                                name="station_code"
                                value="<?= View::escape($oldData['station_code']) ?>"
                                maxlength="50"
                                placeholder="例如：ST-BJ-001"
                                required
                            >

                            <p class="field-help">
                                只允许大写英文字母、数字和连字符。
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
                                class="form-control <?= View::hasError($errors, 'station_name') ? 'input-error' : '' ?>"
                                type="text"
                                id="station_name"
                                name="station_name"
                                value="<?= View::escape($oldData['station_name']) ?>"
                                maxlength="100"
                                placeholder="例如：国贸中心直流充电桩1号"
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
                                class="form-control <?= View::hasError($errors, 'location_id') ? 'input-error' : '' ?>"
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
                                        value="<?= View::escape((string) $locationId) ?>"
                                        <?= $oldData['location_id'] === (string) $locationId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= View::escape(
                                            $location->getLocationName()
                                            . '（'
                                            . $location->getStatusLabel()
                                            . '）'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <p class="field-help">
                                已停用站点会由服务器拒绝新增充电桩。
                            </p>

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
                                class="form-control <?= View::hasError($errors, 'charger_type') ? 'input-error' : '' ?>"
                                id="charger_type"
                                name="charger_type"
                                required
                            >
                                <option
                                    value="ac"
                                    <?= $oldData['charger_type'] === 'ac' ? 'selected' : '' ?>
                                >
                                    交流充电桩
                                </option>

                                <option
                                    value="dc"
                                    <?= $oldData['charger_type'] === 'dc' ? 'selected' : '' ?>
                                >
                                    直流充电桩
                                </option>
                            </select>

                            <?php if(View::firstError($errors, 'charger_type') !== null): ?>
                                <p class="field-error">
                                    <?= View::escape(View::firstError($errors, 'charger_type')) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="status">
                                初始状态
                                <span class="required-mark">*</span>
                            </label>

                            <select
                                class="form-control <?= View::hasError($errors, 'status') ? 'input-error' : '' ?>"
                                id="status"
                                name="status"
                                required
                            >
                                <option
                                    value="active"
                                    <?= $oldData['status'] === 'active' ? 'selected' : '' ?>
                                >
                                    可用
                                </option>

                                <option
                                    value="maintenance"
                                    <?= $oldData['status'] === 'maintenance' ? 'selected' : '' ?>
                                >
                                    维护中
                                </option>

                                <option
                                    value="inactive"
                                    <?= $oldData['status'] === 'inactive' ? 'selected' : '' ?>
                                >
                                    已停用
                                </option>
                            </select>

                            <?php if(View::firstError($errors, 'status') !== null): ?>
                                <p class="field-error">
                                    <?= View::escape(View::firstError($errors, 'status')) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="power_kw">
                                充电功率
                                <span class="required-mark">*</span>
                            </label>

                            <input
                                class="form-control <?= View::hasError($errors, 'power_kw') ? 'input-error' : '' ?>"
                                type="number"
                                id="power_kw"
                                name="power_kw"
                                value="<?= View::escape($oldData['power_kw']) ?>"
                                min="0.01"
                                max="999999.99"
                                step="0.01"
                                placeholder="例如：60.00"
                                required
                            >

                            <p class="field-help">
                                单位为千瓦，必须大于0，最多保留两位小数。
                            </p>

                            <?php if(View::firstError($errors, 'power_kw') !== null): ?>
                                <p class="field-error">
                                    <?= View::escape(View::firstError($errors, 'power_kw')) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="hourly_rate">
                                每小时费用
                                <span class="required-mark">*</span>
                            </label>

                            <input
                                class="form-control <?= View::hasError($errors, 'hourly_rate') ? 'input-error' : '' ?>"
                                type="number"
                                id="hourly_rate"
                                name="hourly_rate"
                                value="<?= View::escape($oldData['hourly_rate']) ?>"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                placeholder="例如：5.00"
                                required
                            >

                            <p class="field-help">
                                单位为人民币元，可设置为0，最多保留两位小数。
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
                            保存充电桩
                        </button>

                        <a
                            class="secondary-button"
                            href="index.php<?= $oldData['location_id'] === ''
                                ? ''
                                : '?location_id=' . View::escape($oldData['location_id']) ?>"
                        >
                            返回充电桩列表
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>