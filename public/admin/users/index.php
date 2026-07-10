<?php

declare(strict_types=1);

use App\Core\AuthGuard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;

require_once dirname(__DIR__, 3) . '/bootstrap.php';

$connection = $database->getConnection();
$userRepository = new UserRepository($connection);

$session = new Session();
$authGuard = new AuthGuard($session, $userRepository);

$currentUser = $authGuard->requireAdmin(
    '../../login.php',
    '../../user/dashboard.php'
);

$allowedRoles = [
    'user',
    'admin',
];
$allowedStatuses = [
    'active',
    'disabled',
];

$keyword = trim((string)($_GET['keyword'] ?? ''));
$role = trim((string)($_GET['role'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$filterErrors = [];

if(mb_strlen($keyword, 'UTF-8') > 100){
    $filterErrors[] = '搜索关键字不能超过100个字符。';
    $keyword = '';
}

if($role !== '' && !in_array($role, $allowedRoles, true)){
    $filterErrors[] = '用户角色筛选条件不合法。';
    $role = '';
}

if($status !== '' && !in_array($status, $allowedStatuses, true)){
    $filterErrors[] = '账户状态筛选条件不合法。';
    $status = '';
}

$currentPage = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if($currentPage === false || $currentPage === null){
    $currentPage = 1;
}

$pageSize = 20;

$filters = [
    'keyword' => $keyword,
    'role' => $role,
    'status' => $status,
];

if($filterErrors === []){
    $summary = $userRepository->getAdminListSummary($filters);

    $totalUsers = $summary['total_users'];
    $activeUsers = $summary['active_users'];
    $disabledUsers = $summary['disabled_users'];
    $adminUsers = $summary['admin_users'];
    $normalUsers = $summary['normal_users'];

    $totalPages = max(1, (int)ceil($totalUsers / $pageSize));

    if($currentPage > $totalPages){
        $currentPage = $totalPages;
    }

    $offset = ($currentPage - 1) * $pageSize;

    $users = $userRepository->searchAdminList(
        $filters,
        $pageSize,
        $offset
    );
}else{
    $users = [];
    $totalUsers = 0;
    $activeUsers = 0;
    $disabledUsers = 0;
    $adminUsers = 0;
    $normalUsers = 0;
    $totalPages = 1;
    $currentPage = 1;
    $offset = 0;
}

$queryParameters = [];

if($keyword !== ''){
    $queryParameters['keyword'] = $keyword;
}

if($role !== ''){
    $queryParameters['role'] = $role;
}

if($status !== ''){
    $queryParameters['status'] = $status;
}

$hasActiveFilters = $keyword !== '' || $role !== '' || $status !== '';

$paginationPath = 'index.php';
$paginationAriaLabel = '用户分页';
$paginationTotal = $totalUsers;
$paginationUnit = '名用户';

$topbarTheme = 'admin';
$topbarIdentityLabel = '管理员';
$topbarDisplayName = $currentUser->getRealName();
$topbarLinks = [
    [
        'label' => '返回控制台',
        'href' => '../dashboard.php',
    ],
    [
        'label' => '退出登录',
        'method' => 'post',
        'href' => '../../logout.php',
    ],
];

$flashMessages = $session->getFlashMessages();

?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理｜易充充电管理系统</title>
    <link rel="stylesheet" href="../../assets/css/common.css">
    <link rel="stylesheet" href="../../assets/css/data_list.css">

    <style>
        .page {
            max-width: 1400px;
        }

        .summary-grid {
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .summary-card {
            padding: 18px;
        }

        .summary-label {
            margin-bottom: 6px;
        }

        .summary-value {
            font-size: 22px;
        }

        table {
            min-width: 1250px;
        }

        @media(max-width: 1000px){
            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 3) . '/views/partials/topbar.php'; ?>

    <main class="page">
        <section class="page-header">
            <div>
                <h2 class="page-title">用户管理</h2>
                <p class="page-description">
                    查看用户资料、账户状态和最近登录信息。
                </p>
            </div>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/flash_messages.php'; ?>

        <?php if($filterErrors !== []): ?>
            <ul class="filter-error-list">
                <?php foreach($filterErrors as $filterError): ?>
                    <li><?= View::escape($filterError) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <section class="filter-card">
            <form method="get" action="index.php">
                <div class="filter-grid filter-grid-3">
                    <div class="filter-group">
                        <label class="filter-label" for="keyword">搜索关键字</label>

                        <input
                            class="filter-control"
                            type="search"
                            id="keyword"
                            name="keyword"
                            value="<?= View::escape($keyword) ?>"
                            maxlength="100"
                            placeholder="用户名、姓名、手机号或电子邮箱"
                        >
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="role">用户角色</label>

                        <select class="filter-control" id="role" name="role">
                            <option value="">全部角色</option>
                            <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>普通用户</option>
                            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>管理员</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" for="status">账户状态</label>

                        <select class="filter-control" id="status" name="status">
                            <option value="">全部状态</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>正常</option>
                            <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions">
                    <button class="filter-button" type="submit">筛选用户</button>

                    <?php if($hasActiveFilters): ?>
                        <a class="reset-button" href="index.php">重置筛选</a>
                    <?php endif; ?>
                </div>
            </form>

            <p class="filter-help">
                支持按用户名、真实姓名、手机号码和电子邮箱搜索，也可以按角色、状态筛选；列表按普通用户优先、管理员置底、同角色内用户编号倒序显示。
            </p>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <div class="summary-label">
                    <?= $hasActiveFilters ? '当前结果' : '全部用户' ?>
                </div>

                <p class="summary-value">
                    <?= View::escape((string)$totalUsers) ?> 人
                </p>
            </article>

            <article class="summary-card">
                <div class="summary-label">正常账户</div>
                <p class="summary-value"><?= View::escape((string)$activeUsers) ?> 人</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">已停用账户</div>
                <p class="summary-value"><?= View::escape((string)$disabledUsers) ?> 人</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">管理员</div>
                <p class="summary-value"><?= View::escape((string)$adminUsers) ?> 人</p>
            </article>

            <article class="summary-card">
                <div class="summary-label">普通用户</div>
                <p class="summary-value"><?= View::escape((string)$normalUsers) ?> 人</p>
            </article>
        </section>

        <p class="result-summary">
            当前共找到
            <strong><?= View::escape((string)$totalUsers) ?></strong>
            名用户。
            <?php if($hasActiveFilters): ?>
                已应用筛选条件。
            <?php endif; ?>
        </p>

        <section class="table-card">
            <?php if($users === []): ?>
                <div class="empty-state">
                    <?= $hasActiveFilters
                        ? '当前筛选条件下没有符合要求的用户。'
                        : '当前还没有用户数据。' ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>用户编号</th>
                            <th>用户名</th>
                            <th>真实姓名</th>
                            <th>手机号</th>
                            <th>电子邮箱</th>
                            <th>角色</th>
                            <th>状态</th>
                            <th>最近登录</th>
                            <th>注册时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach($users as $user): ?>
                            <?php
                            $userId = $user->getUserId();

                            if($userId === null){
                                continue;
                            }
                            ?>

                            <tr>
                                <td><?= View::escape((string)$userId) ?></td>
                                <td><?= View::escape($user->getUsername()) ?></td>
                                <td><?= View::escape($user->getRealName()) ?></td>
                                <td><?= View::escape($user->getMobile()) ?></td>

                                <td>
                                    <?= View::escape($user->getEmail() ?? '未填写') ?>
                                </td>

                                <td>
                                    <span class="role-badge <?= View::escape(
                                        View::roleClass($user->getRole())
                                    ) ?>">
                                        <?= View::escape($user->getRoleLabel()) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge <?= View::escape(
                                        View::statusClass($user->getStatus())
                                    ) ?>">
                                        <?= View::escape($user->getStatusLabel()) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= View::escape(
                                        $user->getLastLoginAt() ?? '从未登录'
                                    ) ?>
                                </td>

                                <td><?= View::escape($user->getCreatedAt()) ?></td>

                                <td>
                                    <div class="action-group">
                                        <a
                                            class="action-link"
                                            href="detail.php?id=<?= View::escape(
                                                (string)$userId
                                            ) ?>"
                                        >
                                            查看详情
                                        </a>

                                        <?php if(
                                            !$user->isAdmin()
                                            && $userId !== $currentUser->getUserId()
                                        ): ?>
                                            <?php if($user->isActive()): ?>
                                                <a
                                                    class="action-link danger"
                                                    href="status.php?id=<?= View::escape(
                                                        (string)$userId
                                                    ) ?>"
                                                >
                                                    停用账户
                                                </a>
                                            <?php else: ?>
                                                <a
                                                    class="action-link success"
                                                    href="status.php?id=<?= View::escape(
                                                        (string)$userId
                                                    ) ?>"
                                                >
                                                    启用账户
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <?php require dirname(__DIR__, 3) . '/views/partials/pagination.php'; ?>
    </main>
</body>
</html>