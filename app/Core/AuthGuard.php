<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Repositories\UserRepository;

class AuthGuard
{
    private Session $session;

    private UserRepository $userRepository;

    public function __construct(Session $session, UserRepository $userRepository)
    {
        $this->session = $session;
        $this->userRepository = $userRepository;
    }

    public function requireLogin(string $loginPath = '../login.php'): User
    {
        $this->session->start();

        $userId = $this->session->getUserId();

        if($userId === null){
            Logger::security('未登录访问受保护页面。', [
                'login_path' => $loginPath,
            ]);
            $this->session->setFlash('error', '请先登录后再访问该页面。');
            header('Location: ' . $loginPath);
            exit;
        }

        if($this->session->isAuthenticatedSessionExpired()){
            return $this->logoutAndRedirect('登录状态已过期，请重新登录。', $loginPath, [
                'reason' => 'authenticated_session_expired',
                'user_id' => $userId,
            ]);
        }

        $user = $this->userRepository->findById($userId);

        if($user === null){
            return $this->logoutAndRedirect('当前登录账户不存在，请重新登录。', $loginPath, [
                'reason' => 'session_user_not_found',
                'user_id' => $userId,
            ]);
        }

        if(!$user->isActive()){
            return $this->logoutAndRedirect('当前账户已被停用，请联系管理员。', $loginPath, [
                'reason' => 'user_disabled',
                'user_id' => $user->getUserId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
                'status' => $user->getStatus(),
            ]);
        }

        if(!$this->session->matchesCredentialFingerprint($user)){
            return $this->logoutAndRedirect('登录凭据已更新，请重新登录。', $loginPath, [
                'reason' => 'credential_fingerprint_changed',
                'user_id' => $user->getUserId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
            ]);
        }

        $this->session->refreshAuthenticatedSession();

        return $user;
    }

    public function requireAdmin(
        string $loginPath = '../login.php',
        string $userDashboardPath = '../user/dashboard.php'
    ): User
    {
        $user = $this->requireLogin($loginPath);

        if(!$user->isAdmin()){
            Logger::security('权限拒绝：普通用户访问管理员页面。', [
                'user_id' => $user->getUserId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
                'redirect_to' => $userDashboardPath,
            ]);
            $this->session->setFlash('error', '您没有权限访问管理员控制台。');
            header('Location: ' . $userDashboardPath);
            exit;
        }

        return $user;
    }

    public function requireUser(
        string $loginPath = '../login.php',
        string $adminDashboardPath = '../admin/dashboard.php'
    ): User
    {
        $user = $this->requireLogin($loginPath);

        if($user->isAdmin()){
            Logger::security('权限拒绝：管理员账户访问普通用户页面。', [
                'user_id' => $user->getUserId(),
                'username' => $user->getUsername(),
                'role' => $user->getRole(),
                'redirect_to' => $adminDashboardPath,
            ]);
            $this->session->setFlash('error', '管理员账户不能访问普通用户页面。');
            header('Location: ' . $adminDashboardPath);
            exit;
        }

        return $user;
    }

    public function logoutAndRedirect(string $message, string $loginPath, array $context = []): never
    {
        $context = array_merge([
            'message' => $message,
            'login_path' => $loginPath,
            'session_user_id' => $this->session->getUserId(),
        ], $context);

        Logger::security('登录态失效并跳转登录页。', $context);

        $this->session->logout();
        $this->session->start();
        $this->session->setFlash('error', $message);
        header('Location: ' . $loginPath);
        exit;
    }
}