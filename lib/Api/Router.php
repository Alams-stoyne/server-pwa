<?php
namespace X5\Pwa\Api;

use X5\Pwa\Cors;
use X5\Pwa\Push;

class Router
{
    private const ALLOWED_HOSTS = [
        'api.x5hd.ru',
        'api-pwa.x5hd.ru',
    ];

    public static function handle(): void
    {
        if (!self::hostAllowed()) return;

        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri, 2)[0];

        Cors::apply();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'OPTIONS') {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(204);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            exit;
        }

        try {
            [$body, $status] = self::dispatch($path, $method);
            self::json($body, $status);
        } catch (\Throwable $e) {
            self::json(['RESULT' => 'ERROR', 'MESSAGE' => $e->getMessage()], 500);
        }
        exit;
    }

    private static function hostAllowed(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(preg_replace('#:\d+$#', '', $host));
        return in_array($host, self::ALLOWED_HOSTS, true);
    }

    private static function dispatch(string $path, string $method): array
    {
        $body = self::readBody();

        // ─── Авторизация и синхронизация ───
        if ($path === '/auth'           && $method === 'POST') return [Auth::auth($body), 200];
        if ($path === '/reads'          && $method === 'POST') return [Reads::list($body), 200];
        if ($path === '/habits'         && $method === 'POST') return [Habits::list($body), 200];
        if ($path === '/state'          && $method === 'POST') return [State::save($body), 200];
        if ($path === '/profile'        && $method === 'POST') return [Profile::save($body), 200];

        // ─── Завершение элементов ───
        if ($path === '/lecture/read'   && $method === 'POST') return [Reads::add($body), 200];
        if ($path === '/task/complete'  && $method === 'POST') return [Tasks::complete($body), 200];
        if ($path === '/habit/complete' && $method === 'POST') return [Habits::complete($body), 200];

        // ─── Списки практик ───
        if ($path === '/tasks'          && $method === 'POST') return [Tasks::list($body), 200];

        // ─── Статистика ───
        if ($path === '/stats/sync'        && $method === 'POST') return [Stats::sync($body), 200];
        if ($path === '/stats/global.json' && $method === 'GET')  return [Stats::global(), 200];

        // ─── Конфигурация ───
        if ($path === '/config/themes'      && $method === 'GET') return [Config::themes(), 200];
        if ($path === '/config/quiz_theme'  && $method === 'GET') return [Config::quizTheme(), 200];
        if ($path === '/config/tutorial'    && $method === 'GET') return [Config::tutorial(), 200];
        if ($path === '/config/reflection'  && ($method === 'GET' || $method === 'POST')) {
            $input = $method === 'POST' ? $body : $_GET;
            return [Reflection::config($input), 200];
        }
        if ($path === '/config/habits'      && ($method === 'GET' || $method === 'POST')) {
            $input = $method === 'POST' ? $body : $_GET;
            return [Config::habits($input), 200];
        }

        // ─── Виджет Today ───
        if (($path === '/today' || $path === '/home/today')
            && ($method === 'GET' || $method === 'POST')) {
            $input = $method === 'POST' ? $body : $_GET;
            return [Today::build($input), 200];
        }

        // ─── Рефлексия ───
        if ($path === '/reflection/send'   && $method === 'POST') return [Reflection::send($body), 200];
        if ($path === '/reflection/status' && $method === 'POST') return [Reflection::status($body), 200];

        // ─── Шагомер ───
        if ($path === '/steps/send'    && $method === 'POST') return [Steps::send($body), 200];
        if ($path === '/steps/summary' && $method === 'POST') return [Steps::summary($body), 200];

        // ─── Контент ───
        $m = [];
        if (preg_match('#^/lectures/general/(\d{4}-\d{2}-\d{2})\.json$#', $path, $m)) {
            return [Content::lecturesGeneral($m[1]), 200];
        }
        if (preg_match('#^/lectures/charging/(\d{4}-\d{2}-\d{2})\.json$#', $path, $m)) {
            return [Content::lecturesCharging($m[1]), 200];
        }
        if (preg_match('#^/lectures/([a-z0-9_-]+)/(\d{4}-\d{2}-\d{2})\.json$#', $path, $m)) {
            return [Content::lecturesTheme($m[1], $m[2]), 200];
        }
        if (preg_match('#^/tasks/([a-z0-9_-]+)/(\d{4}-\d{2}-\d{2})\.json$#', $path, $m)) {
            return [Content::tasks($m[1], $m[2]), 200];
        }

        // ─── Push ───
        if ($path === '/push/vapid'       && $method === 'GET')  return [PushApi::vapidInfo(), 200];
        if ($path === '/push/subscribe'   && $method === 'POST') return [PushApi::subscribe($body), 200];
        if ($path === '/push/unsubscribe' && $method === 'POST') return [PushApi::unsubscribe($body), 200];
        if ($path === '/push/send'        && $method === 'POST') return PushApi::send($body);
        if ($path === '/push/broadcast'   && $method === 'POST') return [PushApi::broadcast($body), 200];

        // ─── Admin ───
        if (strpos($path, '/admin/') === 0) return Admin::handle($path, $method, $body);

        // ─── Health ───
        if ($path === '/' || $path === '') {
            return [[
                'RESULT'  => 'OK',
                'SERVICE' => 'x5.pwa',
                'TIME'    => (new \Bitrix\Main\Type\DateTime())->toString(),
                'PUSH'    => Push::available() ? 'available' : 'no_library',
            ], 200];
        }

        return [['RESULT' => 'NOT_FOUND', 'PATH' => $path], 404];
    }

    public static function readBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    public static function json($obj, int $status = 200): void
    {
        while (ob_get_level() > 0) ob_end_clean();

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        $json = (string)json_encode($obj, JSON_UNESCAPED_UNICODE);
        header('Content-Length: ' . strlen($json));

        echo $json;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit;
    }
}