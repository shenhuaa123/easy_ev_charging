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

$csrf = new Csrf($session);
$csrfToken = $csrf->token();

$errors = [];
$errorMessage = '';

$oldData = [
    'location_name' => $location->getLocationName(),
    'province' => $location->getProvince(),
    'city' => $location->getCity(),
    'district' => $location->getDistrict(),
    'detailed_address' => $location->getDetailedAddress(),
    'description' => $location->getDescription() ?? '',
    'longitude' => $location->getLongitude() ?? '',
    'latitude' => $location->getLatitude() ?? '',
];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $oldData = [
        'location_name' => trim((string)($_POST['location_name'] ?? '')),
        'province' => trim((string)($_POST['province'] ?? '')),
        'city' => trim((string)($_POST['city'] ?? '')),
        'district' => trim((string)($_POST['district'] ?? '')),
        'detailed_address' => trim((string)($_POST['detailed_address'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'longitude' => trim((string)($_POST['longitude'] ?? '')),
        'latitude' => trim((string)($_POST['latitude'] ?? '')),
    ];

    if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
        http_response_code(403);
        $errorMessage = Csrf::INVALID_MESSAGE;
    }else{
        $updateResult = $locationService->update(
            $currentUserId,
            $locationId,
            $oldData
        );

        $logService->recordCurrentRequest(
           $currentUserId,
           'location_update',
           'location',
           $locationId,
           $updateResult['success'] ? 'success' : 'failure',
           $updateResult['success'] 
                ? '编辑充电站点成功：' . $oldData['location_code'] . '/' . $oldData['location_name']
                : $updateResult['message']
        );

        if($updateResult['success']){
            $session->setFlash('success', $updateResult['message']);
            header('Location: detail.php?id=' . $locationId);
            exit;
        }

        $errorMessage = $updateResult['message'];
        $errors = $updateResult['errors'];

        $location = $locationRepository->findById($locationId);

        if($location === null){
            $session->setFlash('error', '充电站点已不存在。');
            header('Location: index.php');
            exit;
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
        'label' => '返回站点详情',
        'href' => 'detail.php?id=' . $locationId,
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
    <title>编辑充电站点｜易充充电管理系统</title>
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
            <h2 class="page-title">编辑充电站点</h2>

            <p class="page-description">
                修改站点名称、地址、说明和经纬度。站点编号与状态需通过其他功能维护。
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
                    <div class="readonly-label">站点业务编号</div>

                    <p class="readonly-value">
                        <?= View::escape($location->getLocationCode()) ?>
                    </p>
                </div>

                <div>
                    <div class="readonly-label">当前站点状态</div>

                    <p class="readonly-value">
                        <?= View::escape($location->getStatusLabel()) ?>
                    </p>
                </div>
            </div>

            <form method="post" action="">
                <?php require dirname(__DIR__, 3) . '/views/partials/csrf_field.php'; ?>

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label" for="location_name">
                            充电站点名称
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'location_name'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="location_name"
                            name="location_name"
                            value="<?= View::escape($oldData['location_name']) ?>"
                            minlength="2"
                            maxlength="100"
                            required
                        >

                        <?php if(
                            View::firstError($errors, 'location_name') !== null
                        ): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError(
                                        $errors,
                                        'location_name'
                                    )
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="province">
                            省级行政区
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'province'
                            ) ? 'input-error' : '' ?>"
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
                                <?= View::escape(
                                    View::firstError($errors, 'province')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="city">
                            城市
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'city'
                            ) ? 'input-error' : '' ?>"
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
                                <?= View::escape(
                                    View::firstError($errors, 'city')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="district">
                            区县
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'district'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="district"
                            name="district"
                            value="<?= View::escape($oldData['district']) ?>"
                            maxlength="50"
                            placeholder="例如：朝阳区、天河区"
                            required
                        >

                        <?php if(View::firstError($errors, 'district') !== null): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError($errors, 'district')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="detailed_address">
                            详细地址
                            <span class="required-mark">*</span>
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'detailed_address'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="detailed_address"
                            name="detailed_address"
                            value="<?= View::escape(
                                $oldData['detailed_address']
                            ) ?>"
                            minlength="2"
                            maxlength="200"
                            placeholder="例如：建国路88号地下停车场B2层"
                            required
                        >

                        <?php if(
                            View::firstError(
                                $errors,
                                'detailed_address'
                            ) !== null
                        ): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError(
                                        $errors,
                                        'detailed_address'
                                    )
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="longitude">
                            经度
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'longitude'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="longitude"
                            name="longitude"
                            value="<?= View::escape($oldData['longitude']) ?>"
                            maxlength="12"
                            placeholder="例如：116.397128"
                        >

                        <p class="field-help">
                            选填，范围为-180到180，最多保留7位小数。
                        </p>

                        <?php if(
                            View::firstError($errors, 'longitude') !== null
                        ): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError($errors, 'longitude')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="latitude">
                            纬度
                        </label>

                        <input
                            class="form-control <?= View::hasError(
                                $errors,
                                'latitude'
                            ) ? 'input-error' : '' ?>"
                            type="text"
                            id="latitude"
                            name="latitude"
                            value="<?= View::escape($oldData['latitude']) ?>"
                            maxlength="11"
                            placeholder="例如：39.908722"
                        >

                        <p class="field-help">
                            选填，范围为-90到90，最多保留7位小数。
                        </p>

                        <?php if(
                            View::firstError($errors, 'latitude') !== null
                        ): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError($errors, 'latitude')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label" for="description">
                            站点说明
                        </label>

                        <textarea
                            class="form-control <?= View::hasError(
                                $errors,
                                'description'
                            ) ? 'input-error' : '' ?>"
                            id="description"
                            name="description"
                            maxlength="500"
                            placeholder="选填，例如：停车场入口位置、开放时间或其他说明。"
                        ><?= View::escape($oldData['description']) ?></textarea>

                        <p class="field-help">
                            选填，最多500个字符。
                        </p>

                        <?php if(
                            View::firstError($errors, 'description') !== null
                        ): ?>
                            <p class="field-error">
                                <?= View::escape(
                                    View::firstError($errors, 'description')
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="primary-button" type="submit">
                        保存站点资料
                    </button>

                    <a
                        class="secondary-button"
                        href="detail.php?id=<?= View::escape(
                            (string) $locationId
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