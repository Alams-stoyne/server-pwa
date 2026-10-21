<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;
use X5\Pwa\Settings;
use X5\Pwa\S3;
use X5\Pwa\Dumper;
use Bitrix\Main\Loader;

class Admin
{
    public static function handle(string $path, string $method, array $b): array
    {
        Loader::includeModule('highloadblock');

        // ── RESET UID ──
        if ($path === '/mock/admin/reset-uid' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR'], 400];
            $dc = Storage::dataClass('X5PwaUidBindings');
            $rows = $dc::getList(['filter' => ['=UF_UID' => $uid]])->fetchAll();
            foreach ($rows as $r) $dc::delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        if ($path === '/mock/admin/reset-device' && $method === 'POST') {
            $dev = (string)($b['DEVICE_ID'] ?? '');
            if (!$dev) return [['RESULT' => 'ERROR'], 400];
            $dc = Storage::dataClass('X5PwaUidBindings');
            $rows = $dc::getList(['filter' => ['=UF_DEVICE_ID' => $dev]])->fetchAll();
            foreach ($rows as $r) $dc::delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        if ($path === '/mock/admin/reset-all' && $method === 'POST') {
            $dc = Storage::dataClass('X5PwaUidBindings');
            foreach ($dc::getList()->fetchAll() as $r) $dc::delete($r['ID']);
            return [['RESULT' => 'OK'], 200];
        }

        // ── STATE ──
        if ($path === '/mock/admin/state' && $method === 'GET') {
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
        if ($path === '/mock/admin/subscribers' && $method === 'GET') {
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
        if ($path === '/mock/admin/push-history' && $method === 'GET') {
            return [['RESULT' => 'OK', 'HISTORY' => Storage::getPushHistory()], 200];
        }
        if ($path === '/mock/admin/clear-history' && $method === 'POST') {
            Storage::clearPushHistory();
            return [['RESULT' => 'OK'], 200];
        }

        // ── S3 ──
        if ($path === '/mock/admin/s3/rebuild' && $method === 'POST') {
            $r = Dumper::regenerateAll();
            return [array_merge(['RESULT' => 'OK'], $r), 200];
        }
        if ($path === '/mock/admin/s3/test' && $method === 'POST') {
            $r = S3::testConnection();
            return [array_merge(['RESULT' => $r['ok'] ? 'OK' : 'ERROR'], $r), 200];
        }

        // ── VAPID ──
        if ($path === '/mock/admin/vapid' && $method === 'GET') {
            $v = Settings::vapid();
            return [[
                'RESULT'           => 'OK',
                'VAPID_PUBLIC_KEY' => $v['public'],
                'VAPID_SUBJECT'    => $v['subject'],
            ], 200];
        }

        // ── Блокировка / разблокировка / удаление ──
        if ($path === '/mock/admin/block-user' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            $reason = (string)($b['REASON'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            $ok = Storage::blockUser($uid, $reason);
            return [['RESULT' => $ok ? 'OK' : 'ERROR'], $ok ? 200 : 400];
        }

        if ($path === '/mock/admin/unblock-user' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            $ok = Storage::unblockUser($uid);
            return [['RESULT' => $ok ? 'OK' : 'ERROR'], $ok ? 200 : 400];
        }

        if ($path === '/mock/admin/delete-user' && $method === 'POST') {
            $uid = (string)($b['UID'] ?? '');
            if (!$uid) return [['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'], 400];

            $deleted = Storage::deleteUser($uid);
            return [['RESULT' => 'OK', 'DELETED' => $deleted], 200];
        }

        return [['RESULT' => 'ERROR', 'MESSAGE' => 'Unknown admin endpoint'], 404];
    }
}