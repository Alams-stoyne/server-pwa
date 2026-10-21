<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;
use X5\Pwa\Data;

class Stats
{
    public static function sync(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? 'unknown');
        if ($uid === 'unknown') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        // Обновляем «последний визит» — синхронизация тоже активность
        Storage::touchBindingByUid($uid);

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

    public static function global(): array
    {
        return Data::globalStats();
    }
}