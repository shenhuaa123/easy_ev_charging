<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\User;

class AdminController extends BaseController
{
    private AuthGuard $authGuard;

    public function __construct(Session $session, Csrf $csrf, AuthGuard $authGuard)
    {
        parent::__construct($session, $csrf);
        $this->authGuard = $authGuard;
    }

    public function requireAdmin(
        string $loginPath = '../../login.php',
        string $userDashboardPath = '../../user/dashboard.php'
    ): User {
        return $this->authGuard->requireAdmin($loginPath, $userDashboardPath);
    }

    public function requireAdminId(User $adminUser, string $loginPath = '../../login.php'): int
    {
        $adminUserId = $adminUser->getUserId();

        if($adminUserId === null){
            $this->authGuard->logoutAndRedirect('当前管理员信息异常，请重新登录。', $loginPath);
        }

        return $adminUserId;
    }

    public function buildTopbarContext(
        User $adminUser,
        string $dashboardPath = '../dashboard.php',
        string $logoutPath = '../../logout.php'
    ): array {
        return [
            'topbarTheme' => 'admin',
            'topbarIdentityLabel' => '管理员',
            'topbarDisplayName' => $adminUser->getRealName(),
            'topbarLinks' => [
                [
                    'label' => '返回控制台',
                    'href' => $dashboardPath,
                ],
                [
                    'label' => '退出登录',
                    'method' => 'post',
                    'href' => $logoutPath,
                ],
            ],
        ];
    }
}