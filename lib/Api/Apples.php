<?php
namespace X5\Pwa\Api;

use X5\Pwa\ApplesBuilder;
use X5\Pwa\Storage;

class Apples
{
    /**
     * POST /apples/history
     * Body: { ID_USER, PAGE?, PER_PAGE? }
     */
    public static function history(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? $b['UID'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required', 'ITEMS' => []];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER', 'ITEMS' => []];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.', 'ITEMS' => []];
        }
        if (!Storage::getUidBinding($uid)) {
            return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.', 'ITEMS' => []];
        }

        $page    = max(1, (int)($b['PAGE']     ?? 1));
        $perPage = max(1, min(100, (int)($b['PER_PAGE'] ?? 50)));

        $res = ApplesBuilder::getHistory($uid, $page, $perPage);

        return [
            'RESULT'   => 'OK',
            'ITEMS'    => $res['items'],
            'TOTAL'    => $res['total'],
            'PAGE'     => $res['page'],
            'PER_PAGE' => $res['perPage'],
            'PAGES'    => $res['pages'],
        ];
    }
}