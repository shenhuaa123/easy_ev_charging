<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class Csrf
{
    public const FIELD_NAME = '_csrf_token';

    public const INVALID_MESSAGE = '页面安全验证失败，请刷新页面后重新提交。';

    private const SESSION_KEY = 'csrf_token';

    private const TOKEN_BYTES = 32;

    private Session $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * 获取当前Session中的CSRF令牌。
     *
     * 当前Session还没有令牌时自动生成。
     */
    public function token(): string 
    {
        $this->session->start();

        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        if(
            is_string($storedToken)
            && preg_match('/\A[a-f0-9]{64}\z/D', $storedToken) === 1
        ){
            return $storedToken;
        }

        try{
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        }catch(Throwable $exception){
            throw new RuntimeException(
                'CSRF安全令牌生成失败。',
                0,
                $exception
            );
        }

        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * 验证用户提交的CSRF令牌。
     *
     * 使用mixed类型是为了安全拒绝恶意构造的数组参数。
     */
    public function validate(mixed $submittedToken): bool
    {
        $this->session->start();

        if(!is_string($submittedToken) || $submittedToken === ''){
            Logger::security('CSRF校验失败：提交令牌缺失或类型不正确。', [
                'submitted_token_type' => get_debug_type($submittedToken),
            ]);
            return false;
        }

        $storedToken = $_SESSION[self::SESSION_KEY] ?? null;

        if(!is_string($storedToken) || $storedToken === ''){
            Logger::security('CSRF校验失败：Session令牌不存在。');
            return false;
        }

        $isValid = hash_equals($storedToken, $submittedToken);

        if(!$isValid){
            Logger::security('CSRF校验失败：提交令牌与Session令牌不匹配。');
        }

        return $isValid;
    }

    /**
     * 重新生成CSRF令牌。
     *
     * 登录状态发生变化时可以主动调用。
     */
    public function regenerate(): string
    {
        $this->clear();

        return $this->token();
    }

    /**
     * 删除当前CSRF令牌。
     */
    public function clear(): void
    {
        $this->session->start();

        unset($_SESSION[self::SESSION_KEY]);
    }
}