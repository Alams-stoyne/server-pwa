<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
use X5\Pwa\Storage;

class Rewards
{
    /**
     * POST /rewards/balance
     * Body: { ID_USER }
     * → { RESULT: 'OK', BALANCE: int }
     */
    public static function balance(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required', 'BALANCE' => 0];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER', 'BALANCE' => 0];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.', 'BALANCE' => 0];
        }
        if (!Storage::getUidBinding($uid)) {
            return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.', 'BALANCE' => 0];
        }

        return [
            'RESULT'  => 'OK',
            'BALANCE' => RewardsBuilder::getBalance($uid),
        ];
    }

    /**
     * POST /rewards/history
     * Body: { ID_USER, PAGE?, PER_PAGE?, TYPE?, DATE_FROM?, DATE_TO? }
     * → { RESULT: 'OK', ITEMS: [...], TOTAL, PAGE, PER_PAGE, PAGES }
     */
    public static function history(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
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

        $params = [
            'page'      => max(1, (int)($b['PAGE']     ?? 1)),
            'perPage'   => max(1, min(100, (int)($b['PER_PAGE'] ?? 50))),
            'type'      => (string)($b['TYPE']       ?? ''),
            'date_from' => (string)($b['DATE_FROM']  ?? ''),
            'date_to'   => (string)($b['DATE_TO']    ?? ''),
        ];

        $res = RewardsBuilder::history($uid, $params);

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