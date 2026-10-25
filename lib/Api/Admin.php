<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;
use X5\Pwa\Settings;
use X5\Pwa\S3;
use X5\Pwa\Dumper;
use Bitrix\Main\Loader;

class Admin
{
    /**
     * Префикс, под которым смонтированы админ-эндпоинты.
     * Роутер ловит его через `strpos($path, '/admin/') === 0`.
     */
    public const PREFIX = '/admin/mock';

    /**
     * Имя HTTP-заголовка с секретом.
     * Секрет задаётся в настройках модуля как admin_api_secret.
     * Пока секрет пуст — все эндпоинты возвращают 403.
     */
    private const SECRET_HEADER = 'HTTP_X_ADMIN_SECRET';

    public static function handle(string $path, string $method, array $b): array
    {
        // ─── 1. Проверка, что запрос действительно к нашему префиксу ───
        // (на случай, если Router будет вызывать Admin и на другие /admin/*)
        if (strpos($path, self::PREFIX . '/') !== 0) {
            return [['RESULT' => 'NOT_FOUND', 'MESSAGE' => 'Unknown admin endpoint'], 404];
        }

        // ─── 2. Авторизация по секрету ───
        // Все админ-эндпоинты доступны только при заданном admin_api_secret.
        // Это защищает /admin/mock/reset-all и /admin/mock/delete-user
        // от случайного вызова любым клиентом api.x5hd.ru.
        if (!self::checkSecret()) {
            self::audit($path, $method, 'DENIED');
            return [[
                'RESULT'  => 'FORBIDDEN',
                'MESSAGE' => 'Admin API is not configured or secret is invalid',
            ], 403];
        }

        Loader::includeModule('highloadblock');

        // Дальше пути уже в формате /admin/mock/...
        // Legacy-пути /mock/admin/... больше не поддерживаются —
        // они и раньше не работали через Router (не начинались с /admin/).

        // ── RESET UID ──
        if ($path === self::PREFIX . '/reset-uid' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR'], 400];

            self::audit($path, $method, 'OK', ['uid' => substr($uid, 0, 12) . '…']);

            $dc = Storage::dataClass('X5PwaUidBindings');
            $rows = $dc::getList(['filter' => ['=UF_UID' => $uid]])->fetchAll();
            foreach ($rows as $r) $dc::delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        if ($path === self::PREFIX . '/reset-device' && $method === 'POST') {
            $dev = (string)($b['DEVICE_ID'] ?? '');
            if (!$dev) return [['RESULT' => 'ERROR'], 400];

            self::audit($path, $method, 'OK', ['device' => substr($dev, 0, 12) . '…']);

            $dc = Storage::dataClass('X5PwaUidBindings');
            $rows = $dc::getList(['filter' => ['=UF_DEVICE_ID' => $dev]])->fetchAll();
            foreach ($rows as $r) $dc::delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        if ($path === self::PREFIX . '/reset-all' && $method === 'POST') {
            self::audit($path, $method, 'OK');

            $dc = Storage::dataClass('X5PwaUidBindings');
            foreach ($dc::getList()->fetchAll() as $r) $dc->delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        // ── STATE ──
        if ($path === self::PREFIX . '/state' && $method === 'GET') {
            $dc = Storage::dataClass('X5PwaUidBindings');
            $all = $dc::getList()->fetchAll();
            return [[
                'RESULT'        => 'OK',
                'DEVICES'       => count($all),
                'UIDS'          => count($all),
                'SUBSCRIPTIONS' => count(Storage::listSubscriptions()),
                'BINDINGS'      => array_map(fn($r) => [
                    'uid'        => substr((string)$r['UF_UID'], 0, 12) . '…',
                    'fullUid'    => $r['UF_UID'],
                    'deviceId'   => substr((string)$r['UF_DEVICE_ID'], 0, 12) . '…',
                    'blocked'    => ($r['UF_BLOCKED'] ?? 'N') === 'Y',
                    'createdAt'  => $r['UF_CREATED_AT']
                        ? $r['UF_CREATED_AT']->getTimestamp() * 1000 : 0,
                    'lastSeenAt' => $r['UF_LAST_SEEN_AT']
                        ? $r['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0,
                ], $all),
            ], 200];
        }

        // ── SUBSCRIBERS ──
        if ($path === self::PREFIX . '/subscribers' && $method === 'GET') {
            $subs = Storage::listSubscriptions();
            $out = [];
            foreach ($subs as $row) {
                $uid = (string)$row['UF_UID'];
                $habits = Storage::getHabits($uid);
                $habitsCount = 0;
                foreach ($habits as $list) {
                    if (is_array($list)) $habitsCount += count($list);
                }
                $out[] = [
                    'uid'         => $uid,
                    'shortUid'    => substr($uid, 0, 12) . '…',
                    'endpoint'    => substr((string)$row['UF_ENDPOINT'], 0, 60) . '…',
                    'hasHabits'   => !empty($habits),
                    'habitsCount' => $habitsCount,
                ];
            }
            return [['RESULT' => 'OK', 'SUBSCRIBERS' => $out], 200];
        }

        // ── PUSH HISTORY ──
        if ($path === self::PREFIX . '/push-history' && $method === 'GET') {
            return [['RESULT' => 'OK', 'HISTORY' => Storage::getPushHistory()], 200];
        }
        if ($path === self::PREFIX . '/clear-history' && $method === 'POST') {
            self::audit($path, $method, 'OK');
            Storage::clearPushHistory();
            return [['RESULT' => 'OK'], 200];
        }

        // ── S3 ──
        if ($path === self::PREFIX . '/s3/rebuild' && $method === 'POST') {
            self::audit($path, $method, 'OK');
            $r = Dumper::regenerateAll();
            return [array_merge(['RESULT' => 'OK'], $r), 200];
        }
        if ($path === self::PREFIX . '/s3/test' && $method === 'POST') {
            $r = S3::testConnection();
            return [array_merge(['RESULT' => $r['ok'] ? 'OK' : 'ERROR'], $r), 200];
        }

        // ── VAPID ──
        if ($path === self::PREFIX . '/vapid' && $method === 'GET') {
            $v = Settings::vapid();
            return [[
                'RESULT'           => 'OK',
                'VAPID_PUBLIC_KEY' => $v['public'],
                'VAPID_SUBJECT'    => $v['subject'],
            ], 200];
        }

        // ── Блокировка / разблокировка / удаление ──
        if ($path === self::PREFIX . '/block-user' && $method === 'POST') {
            $uid    = (string)($b['UID']    ?? '');
            $reason = (string)($b['REASON'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            self::audit($path, $method, 'OK', ['uid' => substr($uid, 0, 12) . '…', 'reason' => $reason]);

            $ok = Storage::blockUser($uid, $reason);
            return [['RESULT' => $ok ? 'OK' : 'ERROR'], $ok ? 200 : 400];
        }

        if ($path === self::PREFIX . '/unblock-user' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            self::audit($path, $method, 'OK', ['uid' => substr($uid, 0, 12) . '…']);

            $ok = Storage::unblockUser($uid);
            return [['RESULT' => $ok ? 'OK' : 'ERROR'], $ok ? 200 : 400];
        }

        if ($path === self::PREFIX . '/delete-user' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            self::audit($path, $method, 'OK', ['uid' => substr($uid, 0, 12) . '…']);

            $deleted = Storage::deleteUser($uid);
            return [['RESULT' => 'OK', 'DELETED' => $deleted], 200];
        }

        return [['RESULT' => 'ERROR', 'MESSAGE' => 'Unknown admin endpoint', 'PATH' => $path], 404];
    }

    /**
     * Проверка секрета из заголовка X-Admin-Secret.
     * Секрет задаётся в опции модуля admin_api_secret.
     * Если опция пуста — эндпоинты отключены (403).
     *
     * Сравнение constant-time через hash_equals — чтобы не утекал
     * префикс секрета по таймингам.
     */
    private static function checkSecret(): bool
    {
        $expected = trim(Settings::get('admin_api_secret', ''));
        if ($expected === '') return false;

        $provided = '';
        if (!empty($_SERVER[self::SECRET_HEADER])) {
            $provided = trim((string)$_SERVER[self::SECRET_HEADER]);
        }
        if ($provided === '') return false;

        return hash_equals($expected, $provided);
    }

    /**
     * Аудит вызовов админ-эндпоинтов.
     * Пишет в /x5.pwa.log с пометкой admin-audit.
     */
    private static function audit(
        string $path,
        string $method,
        string $result,
        array $ctx = []
    ): void {
        try {
            \Bitrix\Main\Diag\Debug::writeToFile(
                sprintf(
                    '%s %s result=%s ip=%s ctx=%s',
                    $method,
                    $path,
                    $result,
                    (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
                    $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '{}'
                ),
                'admin-audit',
                '/x5.pwa.log'
            );
        } catch (\Throwable $e) {
            // логирование аудита не должно ломать ответ
        }
    }
}