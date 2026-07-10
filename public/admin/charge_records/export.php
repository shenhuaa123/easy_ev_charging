<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\ChargeRecordRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\CsvExportService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$recordRepository = new ChargeRecordRepository($connection);

$logService = new AdminOperationLogService(
    new AdminOperationLogRepository($connection)
);

$csvExportService = new CsvExportService();

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

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    Logger::security('非法请求方法：充电订单导出只允许POST。', [
        'actual_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'admin_user_id' => $currentUserId,
    ]);
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('请求方法不允许。充电订单导出只能通过订单列表页执行。');
}

$csrf = new Csrf($session);

if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(Csrf::INVALID_MESSAGE);
}

$allowedStatuses = [
    'charging',
    'completed',
    'abnormal',
    'cancelled',
];

$keyword = trim((string)($_POST['keyword'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$dateFrom = trim((string)($_POST['date_from'] ?? ''));
$dateTo = trim((string)($_POST['date_to'] ?? ''));

$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($status !== '' && !in_array($status, $allowedStatuses, true)){
    $filterErrors[] = '订单状态筛选值不合法。';
    $status = '';
}

if($dateFrom !== '' && !Validator::isDateInput($dateFrom)){
    $filterErrors[] = '开始日期格式不正确。';
    $dateFrom = '';
}

if($dateTo !== '' && !Validator::isDateInput($dateTo)){
    $filterErrors[] = '结束日期格式不正确。';
    $dateTo = '';
}

if($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo){
    $filterErrors[] = '开始日期不能晚于结束日期。';
    $dateFrom = '';
    $dateTo = '';
}

if($filterErrors !== []){
    $session->setFlash('error', implode(' ', $filterErrors));
    header('Location: index.php');
    exit;
}

$filters = [
    'keyword' => $keyword,
    'status' => $status,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$summary = $recordRepository->getSummaryWithFilters($filters);
$matchedCount = (int)$summary['total_count'];
$maxExportRows = 10000;

$recordItems = $recordRepository->exportListItemsWithFilters(
    $filters,
    $maxExportRows
);

$rows = [];

foreach($recordItems as $recordItem){
    $record = $recordItem['record'];
    $recordUser = $recordItem['user'];
    $station = $recordItem['station'];
    $location = $recordItem['location'];

    $rows[] = [
        $record->getChargeRecordId(),
        $record->getOrderNumber(),
        $recordUser['real_name'] ?? '用户资料不存在',
        $recordUser['username'] ?? '',
        $location['location_name'] ?? '充电站点资料不存在',
        $station['station_name'] ?? '充电桩资料不存在',
        $station['station_code'] ?? '',
        $record->getCheckInAt(),
        $record->getCheckOutAt() ?? '',
        $record->getBillableMinutes(),
        $record->getHourlyRateSnapshot(),
        $record->getTotalCost(),
        $record->getStatusLabel(),
        $record->getRemark() ?? '',
        $record->getCreatedAt(),
        $record->getUpdatedAt(),
    ];
}

$exportedCount = count($rows);

$filterDescription = buildFilterDescription(
    $keyword,
    $status,
    $dateFrom,
    $dateTo
);

$logDetail = '导出充电订单CSV；筛选条件：'
    . $filterDescription
    . '；匹配'
    . $matchedCount
    . '条，实际导出'
    . $exportedCount
    . '条。';

if($matchedCount > $exportedCount){
    $logDetail .= '单次最多导出' . $maxExportRows . '条。';
}

try{
    try{
        $logService->recordCurrentRequest(
            $currentUserId,
            'charge_record_export',
            'charge_record',
            null,
            'success',
            $logDetail
        );
    }catch(\Throwable $exception){
        Logger::error('充电订单导出审计日志写入失败。', [
            'exception' => $exception,
            'admin_user_id' => $currentUserId,
            'matched_count' => $matchedCount,
            'exported_count' => $exportedCount,
        ]);
    }

    $filename = 'charge_records_' . date('Ymd_His') . '.csv';

    $csvExportService->download(
        $filename,
        [
            '数据库主键',
            '订单编号',
            '用户姓名',
            '用户名',
            '充电站点',
            '充电桩',
            '充电桩编号',
            '开始时间',
            '结束时间',
            '计费时长（分钟）',
            '费率快照（元/小时）',
            '最终费用（元）',
            '状态',
            '备注',
            '创建时间',
            '更新时间',
        ],
        $rows
    );

    Logger::app('管理员导出充电订单数据成功。', [
        'admin_user_id' => $currentUserId,
        'filename' => $filename,
        'matched_count' => $matchedCount,
        'exported_count' => $exportedCount,
    ]);
}catch(\Throwable $exception){
    Logger::exception($exception, '充电订单CSV导出失败。', [
        'admin_user_id' => $currentUserId,
        'matched_count' => $matchedCount,
        'exported_count' => $exportedCount,
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('充电订单导出失败，请稍后重试。');
}

exit;

function buildFilterDescription(
    string $keyword,
    string $status,
    string $dateFrom,
    string $dateTo
): string {
    $parts = [];

    if($keyword !== ''){
        $parts[] = '关键字=' . $keyword;
    }

    if($status !== ''){
        $parts[] = '状态=' . translateStatus($status);
    }

    if($dateFrom !== ''){
        $parts[] = '开始日期=' . $dateFrom;
    }

    if($dateTo !== ''){
        $parts[] = '结束日期=' . $dateTo;
    }

    if($parts === []){
        return '无筛选条件';
    }

    return implode('，', $parts);
}

function translateStatus(string $status): string
{
    return match($status){
        'charging' => '充电中',
        'completed' => '已完成',
        'abnormal' => '异常结束',
        'cancelled' => '已取消',
        default => $status,
    };
}