<?php
namespace X5\Pwa;

class Cors
{
    public static function apply(): void
    {
        $allowed = Settings::allowedOrigins();
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
}