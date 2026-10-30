<?php
namespace X5\Pwa\Api;

use X5\Pwa\Cors;
use X5\Pwa\Push;
use X5\Pwa\Storage;

class Router
{
    private const ALLOWED_HOSTS = [
        'api.x5hd.ru',
        'api-pwa.x5hd.ru',
    ];

    public static function handle(): void
    {
        // 1. Сначала проверяем хост. Если это админка или сайт,
        // уходим, НЕ трогая error_reporting (иначе ломается вёрстка админки на PHP 8.5).
        if (!self::hostAllowed()) return;

        // 2. Только для API отключаем вывод ошибок на экран.
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        // error_reporting(E_ALL) убран: SetErrorHandler Битрикса смотрит на
        // handled_errors_types из .settings.php, а не на этот параметр.

        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = explode('?', $uri, 2)[0];

        Cors::apply();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'OPTIONS') {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(204);
            exit;
        }

        try {
            [$body, $status] = self::dispatch($path, $method);
            self::json($body, $status);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(),
                'Router::handle EXCEPTION',
                '/x5.pwa.log'
            );
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

        // ─── Глобальная проверка блокировки ───
        $uid = (string)($body['ID_USER'] ?? $body['UID'] ?? $body['uid'] ?? $_GET['ID_USER'] ?? $_GET['UID'] ?? $_GET['uid'] ?? '');
        if ($uid !== '' && preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            $publicPaths = [
                '/auth', '/consent/send', '/consent/status',
                '/support/ticket/create_guest', '/support/ticket/create_reset_uid', '/push/vapid',
                '/config/themes', '/config/quiz_theme', '/config/tutorial',
                '/config/reflection', '/config/habits', '/config/limits',
                '/config/period', '/analytics/event',
            ];
            $isPublic = in_array($path, $publicPaths, true);

            if ($method === 'GET') {
                $isPublic = true;
            }

            if (!$isPublic && Storage::isBlocked($uid)) {
                return [['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'], 403];
            }
        }

        // ─── Авторизация и синхронизация ───
        if ($path === '/auth'           && $method === 'POST') return [Auth::auth($body), 200];
        if ($path === '/reads'          && $method === 'POST') return [Reads::list($body), 200];
        if ($path === '/habits'         && $method === 'POST') return [Habits::list($body), 200];
        if ($path === '/state'          && $method === 'POST') return [State::save($body), 200];
        if ($path === '/profile'        && $method === 'POST') return [Profile::save($body), 200];

        // ─── Согласие на обработку ПД ───
        if ($path === '/consent/send'   && $method === 'POST') return [Consent::send($body), 200];
        if ($path === '/consent/status' && $method === 'POST') return [Consent::status($body), 200];

        // ─── Привычки ───
        if ($path === '/habits/selection' && $method === 'POST') return [Habits::selection($body), 200];
        if ($path === '/habits/select'    && $method === 'POST') return [Habits::select($body), 200];

        // ─── События дня ───
        if ($path === '/events/status' && $method === 'POST') return [Events::status($body), 200];
        if ($path === '/events/action' && $method === 'POST') return [Events::action($body), 200];

        // ─── Награды ───
        if ($path === '/rewards/balance' && $method === 'POST') return [Rewards::balance($body), 200];
        if ($path === '/rewards/history' && $method === 'POST') return [Rewards::history($body), 200];

        // ─── История яблок (новая) ───
        if ($path === '/apples/history'  && $method === 'POST') return [Apples::history($body), 200];

        // ─── Техподдержка ───
        if ($path === '/support/ticket/create'        && $method === 'POST') return [Support::create($body),      200];
        if ($path === '/support/ticket/create_guest'  && $method === 'POST') return [Support::createGuest($body), 200];
        if ($path === '/support/ticket/create_reset_uid' && $method === 'POST') return [Support::createResetUid($body), 200];
        if ($path === '/support/ticket/list'          && $method === 'POST') return [Support::tickets($body),    200];
        if ($path === '/support/ticket/get'           && $method === 'POST') return [Support::get($body),        200];
        if ($path === '/support/ticket/guest_get'     && $method === 'POST') return [Support::getGuest($body),   200];
        if ($path === '/support/ticket/message'       && $method === 'POST') return [Support::send($body),       200];
        if ($path === '/support/ticket/guest_message' && $method === 'POST') return [Support::sendGuest($body),  200];

        // ─── Завершение элементов ───
        if ($path === '/lecture/read'   && $method === 'POST') return [Reads::add($body), 200];
        if ($path === '/task/complete'  && $method === 'POST') return [Tasks::complete($body), 200];
        if ($path === '/habit/complete' && $method === 'POST') return [Habits::complete($body), 200];

        // ─── Списки практик ───
        if ($path === '/tasks'          && $method === 'POST') return [Tasks::list($body), 200];

        // ─── Статистика ───
        if ($path === '/stats/personal'    && $method === 'POST') return [Stats::personal($body), 200];
        if ($path === '/stats/sync'        && $method === 'POST') return [Stats::sync($body), 200];
        if ($path === '/stats/global.json' && $method === 'GET')  return [Stats::global(), 200];

        // ─── Аналитика ───
        if ($path === '/analytics/event'   && $method === 'POST') return [Analytics::event($body), 200];

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
        if ($path === '/config/limits'      && $method === 'POST') return [Config::limits($body), 200];

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
        if ($path === '/steps/history' && $method === 'POST') return [Steps::history($body), 200];

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
        if ($path === '/push/send'        && $method === 'POST') return [PushApi::send($body), 200];
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

        $json = json_encode($obj, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            // Для диагностики — что именно пытались отдать и какие ключи были на верхнем уровне.
            $keysDebug = is_array($obj)
                ? implode(',', array_keys($obj))
                : gettype($obj);

            \Bitrix\Main\Diag\Debug::writeToFile(
                'json_encode FAILED: ' . json_last_error_msg()
                . "\nobj type/keys: " . $keysDebug,
                'Router::json',
                '/x5.pwa.log'
            );

            // Параноидальный fallback: даже если оба encode вернут false,
            // отдаём заведомо валидную строку, чтобы клиент не получил пустоту.
            $json = (string)json_encode(
                [
                    'RESULT'  => 'ERROR',
                    'MESSAGE' => 'JSON encoding failed: ' . json_last_error_msg(),
                ],
                JSON_UNESCAPED_UNICODE
            );
            if ($json === '') {
                $json = '{"RESULT":"ERROR","MESSAGE":"json_encode failed"}';
            }
        }

        // ВАЖНО: Content-Length не ставим — при gzip на Nginx это рвёт соединение.
        echo $json;
        exit;
    }
}