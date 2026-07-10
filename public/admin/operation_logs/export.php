<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Session;
use App\Core\Validator;
use App\Repositories\AdminOperationLogRepository;
use App\Repositories\UserRepository;
use App\Services\AdminOperationLogService;
use App\Services\CsvExportService;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();

$userRepository = new UserRepository($connection);
$logRepository = new AdminOperationLogRepository($connection);
$logService = new AdminOperationLogService($logRepository);
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
    Logger::security('非法请求方法：管理员操作日志导出只允许POST。', [
        'actual_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'admin_user_id' => $currentUserId,
    ]);
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    exit('请求方法不允许。操作日志导出只能通过操作日志列表页执行。');
}

$csrf = new Csrf($session);

if(!$csrf->validate($_POST[Csrf::FIELD_NAME] ?? null)){
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(Csrf::INVALID_MESSAGE);
}

$actionOptions = [
    '',
    'admin_login_success',
    'admin_logout',
    'admin_profile_update',
    'admin_password_change',
    'user_profile_update',
    'user_password_reset',
    'user_status_update',
    'location_create',
    'location_update',
    'location_status_update',
    'station_create',
    'station_update',
    'station_status_update',
    'charge_record_abnormal_finish',
    'charge_record_export',
    'admin_operation_log_export',
    'dashboard_statistics_export',
];

$targetTypeOptions = [
    '',
    'user',
    'location',
    'station',
    'charge_record',
    'admin_operation_log',
    'dashboard_statistics',
];

$resultOptions = [
    '',
    'success',
    'failure',
];

$keyword = trim((string)($_POST['keyword'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
$targetType = trim((string)($_POST['target_type'] ?? ''));
$resultValue = trim((string)($_POST['result'] ?? ''));
$dateFrom = trim((string)($_POST['date_from'] ?? ''));
$dateTo = trim((string)($_POST['date_to'] ?? ''));

$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if(!in_array($action, $actionOptions, true)){
    $filterErrors[] = '操作类型筛选条件不合法。';
    $action = '';
}

if(!in_array($targetType, $targetTypeOptions, true)){
    $filterErrors[] = '对象类型筛选条件不合法。';
    $targetType = '';
}

if(!in_array($resultValue, $resultOptions, true)){
    $filterErrors[] = '操作结果筛选条件不合法。';
    $resultValue = '';
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
    'action' => $action,
    'target_type' => $targetType,
    'result' => $resultValue,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

$matchedCount = $logRepository->countListItems($filters);
$maxExportRows = 10000;
$logItems = $logRepository->exportListItems($filters, $maxExportRows);

$rows = [];

foreach($logItems as $logItem){
    $log = $logItem['log'];
    $operator = $logItem['operator'];

    $rows[] = [
        $log->getAdminOperationLogId(),
        $log->getCreatedAt(),
        $log->getOperatorUserId(),
        $operator['real_name'] ?? '管理员资料不存在',
        $operator['username'] ?? '',
        $log->getActionLabel(),
        $log->getAction(),
        $log->getTargetTypeLabel(),
        $log->getTargetType(),
        $log->getTargetId(),
        $log->getResultLabel(),
        $log->getResult(),
        $log->getDetail(),
        $log->getIpAddress(),
        $log->getUserAgent(),
    ];
}

$exportedCount = count($rows);

$filterDescription = buildFilterDescription(
    $keyword,
    $action,
    $targetType,
    $resultValue,
    $dateFrom,
    $dateTo
);

$logDetail = '导出管理员操作日志CSV；筛选条件：'
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
            'admin_operation_log_export',
            'admin_operation_log',
            null,
            'success',
            $logDetail
        );
    }catch(\Throwable $exception){
        Logger::error('管理员操作日志导出审计日志写入失败。', [
            'exception' => $exception,
            'admin_user_id' => $currentUserId,
            'matched_count' => $matchedCount,
            'exported_count' => $exportedCount,
        ]);
    }

    $filename = 'admin_operation_logs_' . date('Ymd_His') . '.csv';

    $csvExportService->download(
        $filename,
        [
            '日志编号',
            '操作时间',
            '管理员编号',
            '管理员姓名',
            '管理员用户名',
            '操作名称',
            '操作代码',
            '对象类型',
            '对象代码',
            '对象编号',
            '结果',
            '结果代码',
            '详情',
            'IP地址',
            '客户端',
        ],
        $rows
    );

    Logger::app('管理员导出操作日志数据成功。', [
        'admin_user_id' => $currentUserId,
        'filename' => $filename,
        'matched_count' => $matchedCount,
        'exported_count' => $exportedCount,
    ]);
}catch(\Throwable $exception){
    Logger::exception($exception, '管理员操作日志CSV导出失败。', [
        'admin_user_id' => $currentUserId,
        'matched_count' => $matchedCount,
        'exported_count' => $exportedCount,
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('管理员操作日志导出失败，请稍后重试。');
}

exit;

function buildFilterDescription(
    string $keyword,
    string $action,
    string $targetType,
    string $resultValue,
    string $dateFrom,
    string $dateTo
): string {
    $parts = [];

    if($keyword !== ''){
        $parts[] = '关键字=' . $keyword;
    }

    if($action !== ''){
        $parts[] = '操作=' . $action;
    }

    if($targetType !== ''){
        $parts[] = '对象=' . $targetType;
    }

    if($resultValue !== ''){
        $parts[] = '结果=' . $resultValue;
    }

    if($dateFrom !== ''){
        $parts[] = '开始日期=' . $dateFrom;
    }

    if($dateTo !== ''){
        $parts[] = '结束日期=' . $dateTo;
    }

    return $parts === [] ? '无筛选条件' : implode('，', $parts);
}