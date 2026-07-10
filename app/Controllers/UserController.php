<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthGuard;
use App\Core\Csrf;
use App\Core\Session;
use App\Models\User;

class UserController extends BaseController
{
    private AuthGuard $authGuard;

    public function __construct(Session $session, Csrf $csrf, AuthGuard $authGuard)
    {
        parent::__construct($session, $csrf);
        $this->authGuard = $authGuard;
    }

    public function requireUser(
        string $loginPath = '../../login.php',
        string $adminDashboardPath = '../../admin/dashboard.php'
    ): User {
        return $this->authGuard->requireUser($loginPath, $adminDashboardPath);
    }

    public function requireUserId(User $user, string $loginPath = '../../login.php'): int
    {
        $userId = $user->getUserId();

        if($userId === null){
            $this->authGuard->logoutAndRedirect('当前用户信息异常，请重新登录。', $loginPath);
        }

        return $userId;
    }

    public function buildTopbarContext(
        User $user,
        string $dashboardPath = '../dashboard.php',
        string $logoutPath = '../../logout.php'
    ): array {
        return [
            'topbarTheme' => 'user',
            'topbarIdentityLabel' => '用户',
            'topbarDisplayName' => $user->getRealName(),
            'topbarLinks' => [
                [
                    'label' => '返回用户中心',
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