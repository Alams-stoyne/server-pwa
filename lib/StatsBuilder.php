<?php
namespace X5\Pwa;

use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;

Loader::includeModule('highloadblock');

class StatsBuilder
{
    // ───────── Global ─────────

    public static function buildGlobal(): array
    {
        return [
            'USERS'         => self::countUidBindings(),
            'LECTURES_READ' => self::countLectureReads(),
            'TASKS_DONE'    => self::countTaskCompletions(),
            'HABITS_DONE'   => self::countHabitLogs(),
            'TOP_THEME'     => self::findTopTheme(),
            'UPDATED_AT'    => (new \Bitrix\Main\Type\DateTime())->toString(),
        ];
    }

    // ───────── User ─────────

    public static function buildUser(string $uid): array
    {
        $reads     = Storage::getReads($uid);
        $taskKeys  = Storage::getTasks($uid);
        $habitData = Storage::getHabits($uid);

        $themes = ['energy', 'antistress', 'body', 'balance', 'general', 'charging'];
        $byTheme = array_fill_keys($themes, 0);
        $activeCounts = [];

        foreach ($reads as $dbKey) {
            if (preg_match('#^lecture-([a-z0-9_-]+):(\d{4}-\d{2}-\d{2}):#', (string)$dbKey, $m)) {
                $theme = $m[1];
                if (array_key_exists($theme, $byTheme)) $byTheme[$theme]++;
                $activeCounts[$m[2]] = ($activeCounts[$m[2]] ?? 0) + 1;
            }
        }
        foreach ($taskKeys as $k) {
            $d = explode(':', (string)$k, 2)[0];
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
            'LECTURES_READ' => count($reads),
            'TASKS_DONE'    => count($taskKeys),
            'HABITS_DONE'   => $habitsTotal,
            'DAYS_ACTIVE'   => count($activeCounts),
            'STREAK'        => $streak,
            'BY_THEME'      => $byTheme,
            'BY_HABIT'      => $byHabit,
            'WEEK_ACTIVITY' => $week,
            'READS'         => array_values($reads),
            'TASKS'         => array_values($taskKeys),
            'HABITS'        => $habitData,
        ];
    }

    // ───────── Users list ─────────

    /**
     * Пагинированный список с фильтрами.
     *
     * @return array{rows:array,total:int,page:int,perPage:int,pages:int}
     */
    public static function listUsers(array $params = []): array
    {
        $paginated = Storage::listUidBindings($params);
        $rows = $paginated['rows'];

        if (!$rows) {
            return [
                'rows'    => [],
                'total'   => $paginated['total'],
                'page'    => $paginated['page'],
                'perPage' => $paginated['perPage'],
                'pages'   => $paginated['pages'],
            ];
        }

        $uids  = array_column($rows, 'UF_UID');
        $stats = Storage::getStatsForUids($uids);
        $subs  = Storage::getSubscriptionsForUids($uids);
        $activity = Storage::getLastActivityForUids($uids);

        $out = [];
        foreach ($rows as $b) {
            $uid = (string)$b['UF_UID'];
            $s = $stats[$uid] ?? ['lectures' => 0, 'tasks' => 0, 'habits' => 0];

            $lastSeen = $b['UF_LAST_SEEN_AT']
                ? $b['UF_LAST_SEEN_AT']->getTimestamp() * 1000
                : 0;

            // Активность = максимум из «последнего визита» и дат действий
            $lastActive = $lastSeen;
            if (isset($activity[$uid]) && $activity[$uid] > $lastActive) {
                $lastActive = $activity[$uid];
            }

            $blocked   = ($b['UF_BLOCKED'] ?? 'N') === 'Y';
            $blockedAt = !empty($b['UF_BLOCKED_AT'])
                ? $b['UF_BLOCKED_AT']->getTimestamp() * 1000
                : 0;

            $out[] = [
                'UID'              => $uid,
                'DEVICE_ID'        => (string)$b['UF_DEVICE_ID'],
                'FIO'              => (string)($b['UF_FIO']       ?? ''),
                'NICKNAME'         => (string)($b['UF_NICKNAME']  ?? ''),
                'WORKPLACE'        => (string)($b['UF_WORKPLACE'] ?? ''),
                'THEME'            => (string)($b['UF_THEME']     ?? ''),
                'CREATED_AT'       => $b['UF_CREATED_AT']
                    ? $b['UF_CREATED_AT']->getTimestamp() * 1000 : 0,
                'LAST_SEEN_AT'     => $lastSeen,
                'LAST_ACTIVITY_AT' => $lastActive,
                'HAS_SUBSCRIPTION' => !empty($subs[$uid]),
                'LECTURES_READ'    => $s['lectures'],
                'TASKS_DONE'       => $s['tasks'],
                'HABITS_DONE'      => $s['habits'],
                'BLOCKED'          => $blocked,
                'BLOCKED_AT'       => $blockedAt,
                'BLOCK_REASON'     => (string)($b['UF_BLOCK_REASON'] ?? ''),
            ];
        }

        return [
            'rows'    => $out,
            'total'   => $paginated['total'],
            'page'    => $paginated['page'],
            'perPage' => $paginated['perPage'],
            'pages'   => $paginated['pages'],
        ];
    }

    // ───────── Aggregates ─────────

    private static function countUidBindings(): int
    {
        $dc  = Storage::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select'  => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(DISTINCT UF_UID)')],
        ])->fetch();
        return (int)($row['CNT'] ?? 0);
    }

    private static function countLectureReads(): int
    {
        $dc  = Storage::dataClass('X5PwaLectureReads');
        $row = $dc::getList([
            'select'  => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        return (int)($row['CNT'] ?? 0);
    }

    private static function countTaskCompletions(): int
    {
        $dc  = Storage::dataClass('X5PwaTaskCompletions');
        $row = $dc::getList([
            'select'  => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        return (int)($row['CNT'] ?? 0);
    }

    private static function countHabitLogs(): int
    {
        $dc  = Storage::dataClass('X5PwaHabitLogs');
        $row = $dc::getList([
            'select'  => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        return (int)($row['CNT'] ?? 0);
    }

    private static function findTopTheme(): string
    {
        $dc = Storage::dataClass('X5PwaLectureReads');
        $rows = $dc::getList(['select' => ['UF_LECTURE_ID']])->fetchAll();

        $byTheme = [];
        foreach ($rows as $r) {
            if (preg_match('#^lecture-([a-z0-9_-]+):#i', (string)($r['UF_LECTURE_ID'] ?? ''), $m)) {
                $t = strtolower($m[1]);
                $byTheme[$t] = ($byTheme[$t] ?? 0) + 1;
            }
        }
        if (!$byTheme) return 'energy';
        arsort($byTheme);
        return (string)array_key_first($byTheme);
    }
}