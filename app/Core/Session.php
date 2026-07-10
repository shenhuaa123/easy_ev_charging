<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use RuntimeException;

class Session
{
    private const SESSION_NAME = 'EASYEVSESSION';
    private const USER_ID_KEY = 'user_id';
    private const ROLE_KEY = 'role';
    private const FLASH_KEY = 'flash_messages';
    private const CREATED_AT_KEY = 'session_created_at';
    private const LAST_ACTIVITY_KEY = 'session_last_activity';
    private const LAST_REGENERATED_KEY = 'session_last_regenerated_at';
    private const CREDENTIAL_FINGERPRINT_KEY = 'credential_fingerprint';

    private const IDLE_TIMEOUT_SECONDS = 1800;
    private const ABSOLUTE_TIMEOUT_SECONDS = 28800;
    private const REGENERATE_INTERVAL_SECONDS = 900;

    public function start(): void
    {
        if(session_status() === PHP_SESSION_ACTIVE){
            return;
        }

        if(session_status() === PHP_SESSION_DISABLED){
            throw new RuntimeException('服务器未启用Session支持。');
        }

        $this->configure();

        if(!session_start()){
            throw new RuntimeException('Session启动失败。');
        }
    }

    public function login(User $user): void
    {
        $this->start();

        $userId = $user->getUserId();

        if($userId === null){
            throw new RuntimeException('登录用户缺少用户编号。');
        }

        if(!session_regenerate_id(true)){
            throw new RuntimeException('登录Session编号更新失败。');
        }

        $now = time();

        $_SESSION[self::USER_ID_KEY] = $userId;
        $_SESSION[self::ROLE_KEY] = $user->getRole();
        $_SESSION[self::CREDENTIAL_FINGERPRINT_KEY] = 
            $this->buildCredentialFingerprint(
                $user->getPasswordHash()
            );
        $_SESSION[self::CREATED_AT_KEY] = $now;
        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
        $_SESSION[self::LAST_REGENERATED_KEY] = $now;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];

        if(ini_get('session.use_cookies')){
            $cookieParameters = session_get_cookie_params();

            $cookieOptions = [
                'expires' => time() - 42000,
                'path' => (string)($cookieParameters['path'] ?? '/'),
                'secure' => (bool)($cookieParameters['secure'] ?? false),
                'httponly' => (bool)($cookieParameters['httponly'] ?? true),
                'samesite' => (string)($cookieParameters['samesite'] ?? 'Lax'),
            ];

            $cookieDomain = (string)($cookieParameters['domain'] ?? '');

            if($cookieDomain !== ''){
                $cookieOptions['domain'] = $cookieDomain;
            }

            setcookie(session_name(), '', $cookieOptions);
            unset($_COOKIE[session_name()]);
        }

        session_destroy();
    }

    public function isLoggedIn(): bool
    {
        $this->start();

        return isset($_SESSION[self::USER_ID_KEY]);
    }

    public function isAuthenticatedSessionExpired(): bool
    {
        $this->start();

        if(!$this->isLoggedIn()){
            return false;
        }

        $createdAt = (int)($_SESSION[self::CREATED_AT_KEY] ?? 0);
        $lastActivityAt = (int)($_SESSION[self::LAST_ACTIVITY_KEY] ?? 0);

        if($createdAt <= 0 || $lastActivityAt <= 0){
            return true;
        }

        $now = time();
        $idleExpired = $now - $lastActivityAt > self::IDLE_TIMEOUT_SECONDS;
        $absoluteExpired = $now - $createdAt > self::ABSOLUTE_TIMEOUT_SECONDS;

        return $idleExpired || $absoluteExpired;
    }

    public function refreshAuthenticatedSession(): void
    {
        $this->start();

        if(!$this->isLoggedIn()){
            return;
        }

        $now = time();
        $lastRegeneratedAt = (int)($_SESSION[self::LAST_REGENERATED_KEY] ?? 0);

        if(
            $lastRegeneratedAt <= 0
            || $now - $lastRegeneratedAt >= self::REGENERATE_INTERVAL_SECONDS
        ){
            if(!session_regenerate_id(true)){
                throw new RuntimeException('Session编号定期更新失败。');
            }

            $_SESSION[self::LAST_REGENERATED_KEY] = $now;
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }

    public function getUserId(): ?int
    {
        $this->start();

        if(!isset($_SESSION[self::USER_ID_KEY])){
            return null;
        }

        return (int)$_SESSION[self::USER_ID_KEY];
    }

    public function getRole(): ?string
    {
        $this->start();

        return $_SESSION[self::ROLE_KEY] ?? null;
    }

    public function isAdmin(): bool
    {
        return $this->getRole() === 'admin';
    }

    public function matchesCredentialFingerprint(User $user): bool
    {
        $this->start();

        $storedFingerprint = $_SESSION[self::CREDENTIAL_FINGERPRINT_KEY] ?? null;

        if(
            !is_string($storedFingerprint)
            || preg_match('/\A[a-f0-9]{64}\z/D', $storedFingerprint) !== 1
        ){
            return false;
        }

        $currentFingerprint = $this->buildCredentialFingerprint(
            $user->getPasswordHash()
        );

        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    public function setFlash(string $type, string $message): void
    {
        $this->start();

        $_SESSION[self::FLASH_KEY][] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    public function getFlashMessages(): array
    {
        $this->start();

        $messages = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        return $messages;
    }

    private function buildCredentialFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    private function configure(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string)self::ABSOLUTE_TIMEOUT_SECONDS);

        session_name(self::SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isHttpsRequest(): bool
    {
        $httpsValue = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);

        return ($httpsValue !== '' && $httpsValue !== 'off') || $serverPort === 443;
    }
}