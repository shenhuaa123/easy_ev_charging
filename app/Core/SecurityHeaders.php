<?php

declare(strict_types=1);

namespace App\Core;

final class SecurityHeaders
{
    public static function send(): void
    {
        if(PHP_SAPI === 'cli' || headers_sent()){
            return;
        }

        header_remove('X-Powered-By');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');

        header(
            'Permissions-Policy: '
            . 'accelerometer=(), '
            . 'camera=(), '
            . 'geolocation=(), '
            . 'gyroscope=(), '
            . 'magnetometer=(), '
            . 'microphone=(), '
            . 'payment=(), '
            . 'usb=()'
        );

        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'; "
            . "frame-src 'none'; "
            . "object-src 'none'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "media-src 'self'; "
            . "worker-src 'none'"
        );

        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if(self::shouldSendHsts()){
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    private static function shouldSendHsts(): bool
    {
        if(!self::isHttpsRequest()){
            return false;
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+\z/', '', $host) ?? $host;

        return !in_array(
            $host,
            ['localhost', '127.0.0.1', '[::1]', '::1'],
            true
        );
    }

    private static function isHttpsRequest(): bool
    {
        $httpsValue = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        $serverPort = (int)($_SERVER['SERVER_PORT'] ?? 0);

        return($httpsValue !== '' && $httpsValue !== 'off')
            || $serverPort === 443;
    }
}