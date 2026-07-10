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

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'location_code' => '',
    'location_name' => '',
    'province' => '',
    'city' => '',
    'district' => '',
    'detailed_address' => '',
    'description' => '',
    'longitude' => '',
    'latitude' => '',
    'status' => 'active',
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'location_code' => trim((string)($_POST['location_code'] ?? '')),
        'location_name' => trim((string)($_POST['location_name'] ?? '')),
        'province' => trim((string)($_POST['province'] ?? '')),
        'city' => trim((string)($_POST['city'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'detailed_address' => trim((string)($_POST['detailed_address'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'longitude' => trim((string)($_POST['longitude'] ?? '')),
        'latitude' => trim((string)($_POST['latitude'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'active')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $createResult = $locationService->create($currentUserId, $oldData);
        $createdLocationId = isset($createResult['location_id'])
            ? (int)$createResult['location_id'] : null;

        $logService->recordCurrentRequest(
           $currentUserId,
           'location_create',
           'location',
           $createdLocationId,
           $createResult['success'] ? 'success' : 'failure',
           $createResult['success'] 
                ? '新增充电站点成功：' . $oldData['location_code'] . '/' . $oldData['location_name']
                : (string)($createResult['message'] ?? '充电站点新增验证失败。')
        );

        if($createResult['success']){
            $session->setFlash('success', '充电站点新增成功。');
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
        'label' => '返回站点列表',
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
    <title>新增充电站点｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/forms.css">

    <style>
        .page {
            max-width: 900px;
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
            <h2 class="page-title">新增充电站点</h2>
            <p class="page-description">
                请填写充电站点的基础信息、地址、坐标和初始状态。
            </p>
        </section>

        <section class="form-card">
            <?php if($errorMessage !== ''): ?>
                <div class="error-message">
                    <?= View::escape($errorMessage) ?>
                </div>
            <?php endif; ?>

            <?php if(View::firstError($errors, 'coordinates') !== null): ?>
                <div class="error-message">
                    <?= View::escape(View::firstError($errors, 'coordinates')) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="location_code">
                            站点编号
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'location_code') ? 'input-error' : '' ?>"
                            type="text"
                            id="location_code"
                            name="location_code"
                            value="<?= View::escape($oldData['location_code']) ?>"
                            maxlength="50"
                            placeholder="例如：LOC-BJ-001"
                            required
                        >

                        <p class="field-help">
                            只允许大写英文字母、数字和连字符。
                        </p>

                        <?php if(View::firstError($errors, 'location_code') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'location_code')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="location_name">
                            站点名称
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'location_name') ? 'input-error' : '' ?>"
                            type="text"
                            id="location_name"
                            name="location_name"
                            value="<?= View::escape($oldData['location_name']) ?>"
                            maxlength="100"
                            placeholder="例如：北京国贸中心充电站"
                            required
                        >

                        <?php if(View::firstError($errors, 'location_name') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'location_name')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="province">
                            省级行政区
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'province') ? 'input-error' : '' ?>"
                            type="text"
                            id="province"
                            name="province"
                            value="<?= View::escape($oldData['province']) ?>"
                            maxlength="50"
                            placeholder="例如：北京市、广东省"
                            required
                        >

                        <?php if(View::firstError($errors, 'province') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'province')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="city">
                            城市
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'city') ? 'input-error' : '' ?>"
                            type="text"
                            id="city"
                            name="city"
                            value="<?= View::escape($oldData['city']) ?>"
                            maxlength="50"
                            placeholder="例如：北京市、广州市"
                            required
                        >

                        <?php if(View::firstError($errors, 'city') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'city')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="district">
                            区县
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'district') ? 'input-error' : '' ?>"
                            type="text"
                            id="district"
                            name="district"
                            value="<?= View::escape($oldData['district']) ?>"
                            maxlength="50"
                            placeholder="例如：朝阳区"
                            required
                        >

                        <?php if(View::firstError($errors, 'district') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'district')) ?>
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
                                运营中
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

                    <div class="form-group full-width">
                        <label class="form-label" for="detailed_address">
                            详细地址
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError($errors, 'detailed_address') ? 'input-error' : '' ?>"
                            type="text"
                            id="detailed_address"
                            name="detailed_address"
                            value="<?= View::escape($oldData['detailed_address']) ?>"
                            maxlength="255"
                            placeholder="例如：建国门外大街1号地下停车场B2层"
                            required
                        >

                        <?php if(View::firstError($errors, 'detailed_address') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'detailed_address')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="longitude">经度</label>

                        <input
                            class="form-control <?= View::hasError($errors, 'longitude') ? 'input-error' : '' ?>"
                            type="text"
                            id="longitude"
                            name="longitude"
                            value="<?= View::escape($oldData['longitude']) ?>"
                            maxlength="15"
                            placeholder="例如：116.4581000"
                        >

                        <p class="field-help">选填，范围为-180到180。</p>

                        <?php if(View::firstError($errors, 'longitude') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'longitude')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="latitude">纬度</label>

                        <input
                            class="form-control <?= View::hasError($errors, 'latitude') ? 'input-error' : '' ?>"
                            type="text"
                            id="latitude"
                            name="latitude"
                            value="<?= View::escape($oldData['latitude']) ?>"
                            maxlength="15"
                            placeholder="例如：39.9142000"
                        >

                        <p class="field-help">选填，范围为-90到90。</p>

                        <?php if(View::firstError($errors, 'latitude') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'latitude')) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label" for="description">站点说明</label>

                        <textarea
                            class="form-control <?= View::hasError($errors, 'description') ? 'input-error' : '' ?>"
                            id="description"
                            name="description"
                            maxlength="500"
                            placeholder="选填，例如：位于商场地下停车场，支持24小时充电。"
                        ><?= View::escape($oldData['description']) ?></textarea>

                        <?php if(View::firstError($errors, 'description') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(View::firstError($errors, 'description')) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        保存站点
                    </button>

                    <a class="secondary-button" href="index.php">
                        返回站点列表
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>