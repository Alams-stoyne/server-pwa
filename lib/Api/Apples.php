<?php
namespace X5\Pwa\Api;

use X5\Pwa\ApplesBuilder;
use X5\Pwa\Storage;

class Apples
{
    /**
     * POST /apples/history
     * Body: { ID_USER, PAGE?, PER_PAGE? }
     *
     * Ответ:
     *   {
     *     RESULT,
     *     ITEMS: [...],              ← записи за LOOKBACK_DAYS, пагинация
     *     DAILY_EFFICIENCY: [        ← массив дней [PLANNED_START .. min(PLANNED_END, today)], desc
     *       { DATE, EARNED, MAX, PERCENT, ITEMS_COUNT },
     *       ...
     *     ],                          ← [] если PERIOD_CONFIGURED === false
     *     TOTAL, PAGE, PER_PAGE, PAGES
     *   }
     *
     * PERCENT в DAILY_EFFICIENCY:
     *   • null  — нет активностей в этот день (MAX = 0);
     *   • 0     — были активности, но не выполнены (MAX > 0, EARNED = 0);
     *   • float — percent (MAX > 0, EARNED > 0).
     */
    public static function history(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? $b['UID'] ?? ''));
        if ($uid === '') {
            return [
                'RESULT'           => 'ERROR',
                'MESSAGE'          => 'ID_USER required',
                'ITEMS'            => [],
                'DAILY_EFFICIENCY' => [],
            ];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return [
                'RESULT'           => 'ERROR',
                'MESSAGE'          => 'Invalid ID_USER',
                'ITEMS'            => [],
                'DAILY_EFFICIENCY' => [],
            ];
        }

        if (Storage::isBlocked($uid)) {
            return [
                'RESULT'           => 'BLOCKED',
                'MESSAGE'          => 'Доступ приостановлен.',
                'ITEMS'            => [],
                'DAILY_EFFICIENCY' => [],
            ];
        }
        if (!Storage::getUidBinding($uid)) {
            return [
                'RESULT'           => 'UID_NOT_FOUND',
                'MESSAGE'          => 'Пользователь не найден.',
                'ITEMS'            => [],
                'DAILY_EFFICIENCY' => [],
            ];
        }

        $page    = max(1, (int)($b['PAGE']     ?? 1));
        $perPage = max(1, min(100, (int)($b['PER_PAGE'] ?? 50)));

        $res = ApplesBuilder::getHistory($uid, $page, $perPage);

        return [
            'RESULT'           => 'OK',
            'ITEMS'            => $res['items'],
            'DAILY_EFFICIENCY' => $res['daily_efficiency'] ?? [],
            'TOTAL'            => $res['total'],
            'PAGE'             => $res['page'],
            'PER_PAGE'         => $res['perPage'],
            'PAGES'            => $res['pages'],
        ];
    }
}