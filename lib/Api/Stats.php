<?php
namespace X5\Pwa\Api;

use X5\Pwa\S3;
use X5\Pwa\StatsBuilder;
use X5\Pwa\Storage;

class Stats
{
    /**
     * POST /stats/personal
     * Body: { ID_USER, LOCAL_DATETIME?, TZ_OFFSET_MIN? }
     *
     * → { RESULT: 'OK', USER_PROGRESS: { ... } | null }
     */
    public static function personal(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? $b['UID'] ?? $b['uid'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.', 'USER_PROGRESS' => null];
        }

        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        Storage::touchBindingByUid($uid);

        $progress = StatsBuilder::buildPersonal($uid);

        return [
            'RESULT'        => 'OK',
            'USER_PROGRESS' => $progress,
        ];
    }

    /**
     * POST /stats/sync
     *
     * @deprecated с версии новой статистики — используйте /stats/personal.
     * Оставлен для обратной совместимости со старым клиентом.
     */
    public static function sync(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? 'unknown');
        if ($uid === 'unknown' || $uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        Storage::touchBindingByUid($uid);

        // Старый формат: считаем на лету (для совместимости)
        $reads     = Storage::getReads($uid);
        $taskKeys  = Storage::getTasks($uid);
        $habitData = Storage::getHabits($uid);

        $byTheme = [
            'energy' => 0, 'antistress' => 0, 'body' => 0,
            'balance' => 0, 'general' => 0, 'charging' => 0,
        ];
        $activeCounts = [];

        foreach ($reads as $dbKey) {
            if (!preg_match('/^lecture-([a-z0-9_-]+):(\d{4}-\d{2}-\d{2}):/', $dbKey, $m)) continue;
            if (array_key_exists($m[1], $byTheme)) $byTheme[$m[1]]++;
            $activeCounts[$m[2]] = ($activeCounts[$m[2]] ?? 0) + 1;
        }
        foreach ($taskKeys as $k) {
            $d = explode(':', $k, 2)[0];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $activeCounts[$d] = ($activeCounts[$d] ?? 0) + 1;
            }
        }

        $habitsTotal = 0;
        $byHabit = ['water' => 0, 'workout' => 0, 'eyes' => 0];
        foreach ($habitData as $habitId => $list) {
            if (!is_array($list)) continue;
            $habitsTotal += count($list);
            if (array_key_exists($habitId, $byHabit)) $byHabit[$habitId] += count($list);
            foreach ($list as $iso) {
                $d = substr((string)$iso, 0, 10);
                $activeCounts[$d] = ($activeCounts[$d] ?? 0) + 1;
            }
        }

        $streak = 0;
        $cursor = new \DateTime('now', new \DateTimeZone('UTC'));
        $guard  = 0;
        while (isset($activeCounts[$cursor->format('Y-m-d')]) && $guard++ < 3650) {
            $streak++;
            $cursor->modify('-1 day');
        }

        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = new \DateTime('now', new \DateTimeZone('UTC'));
            $d->modify("-$i day");
            $date = $d->format('Y-m-d');
            $week[] = ['date' => $date, 'count' => $activeCounts[$date] ?? 0];
        }

        return [
            'RESULT'        => 'OK',
            'LECTURES_READ' => count($reads),
            'TASKS_DONE'    => count($taskKeys),
            'HABITS_DONE'   => $habitsTotal,
            'DAYS_ACTIVE'   => count($activeCounts),
            'STREAK'        => $streak,
            'BY_THEME'      => $byTheme,
            'BY_HABIT'      => $byHabit,
            'WEEK_ACTIVITY' => $week,
        ];
    }

    /**
     * POST/GET /stats/global
     *
     * @deprecated с версии новой статистики — используйте CDN ${CDN}/stats/global.json.
     * Оставлен как алиас: читает локальный кеш S3-файла.
     */
    public static function global(): array
    {
        $raw = S3::readLocal('stats/global.json');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }

        // Fallback — сгенерировать на лету (медленнее)
        return StatsBuilder::buildGlobal();
    }
}