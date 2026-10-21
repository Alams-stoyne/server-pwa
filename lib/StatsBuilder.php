<?php
namespace X5\Pwa;

use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;

Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

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

        // ─── Резолвим настоящие направления и бакеты лекций ───
        $buckets    = self::resolveLectureBuckets($reads);
        $themeCodes = ThemesResolver::getAllCodes();
        if (!$themeCodes) $themeCodes = ['energy', 'antistress', 'body', 'balance'];

        $byTheme = array_fill_keys($themeCodes, 0);
        $byTheme['general']  = 0;
        $byTheme['charging'] = 0;
        $byTheme['unknown']  = 0;

        $lecturesGeneral = 0;
        $chargingsDone   = 0;
        $activeCounts    = [];

        foreach ($reads as $dbKey) {
            $dbKey  = (string)$dbKey;
            $bucket = $buckets[$dbKey] ?? 'unknown';

            if ($bucket === 'general') {
                $lecturesGeneral++;
                $byTheme['general']++;
            } elseif ($bucket === 'charging') {
                $chargingsDone++;
                $byTheme['charging']++;
            } elseif (strpos($bucket, 'theme:') === 0) {
                $code = substr($bucket, 6);
                if (!array_key_exists($code, $byTheme)) $byTheme[$code] = 0;
                $byTheme[$code]++;
            } else {
                $byTheme['unknown']++;
            }

            // Активность по датам — поддерживаем оба формата
            if (preg_match('/-(\d{4}-\d{2}-\d{2})$/', $dbKey, $m)) {
                $activeCounts[$m[1]] = ($activeCounts[$m[1]] ?? 0) + 1;
            } elseif (preg_match('#:(\d{4}-\d{2}-\d{2}):#', $dbKey, $m)) {
                $activeCounts[$m[1]] = ($activeCounts[$m[1]] ?? 0) + 1;
            }
        }

        foreach ($taskKeys as $k) {
            $d = explode(':', (string)$k, 2)[0];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $activeCounts[$d] = ($activeCounts[$d] ?? 0) + 1;
            }
        }

        // ─── Привычки: реальный список из IB ───
        $allHabits    = HabitsBuilder::getAll();
        $habitTitles  = [];
        $byHabit      = [];
        foreach ($allHabits as $h) {
            $habitTitles[(string)$h['id']] = (string)$h['title'];
            $byHabit[(string)$h['id']] = 0;
        }

        $habitsTotal = 0;
        foreach ($habitData as $habitId => $list) {
            if (!is_array($list)) continue;
            $habitId = (string)$habitId;
            $cnt     = count($list);
            $habitsTotal += $cnt;

            if (!array_key_exists($habitId, $byHabit)) {
                $byHabit[$habitId] = 0;
                if (!isset($habitTitles[$habitId])) $habitTitles[$habitId] = $habitId;
            }
            $byHabit[$habitId] += $cnt;

            foreach ($list as $iso) {
                $d = substr((string)$iso, 0, 10);
                $activeCounts[$d] = ($activeCounts[$d] ?? 0) + 1;
            }
        }

        // ─── Streak ───
        $streak = 0;
        $cursor = new \DateTime('now', new \DateTimeZone('UTC'));
        $guard  = 0;
        while (isset($activeCounts[$cursor->format('Y-m-d')]) && $guard++ < 3650) {
            $streak++;
            $cursor->modify('-1 day');
        }

        // ─── Week ───
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = new \DateTime('now', new \DateTimeZone('UTC'));
            $d->modify("-$i days");
            $date = $d->format('Y-m-d');
            $week[] = ['date' => $date, 'count' => $activeCounts[$date] ?? 0];
        }

        // ─── Названия направлений ───
        $themeTitles = [];
        foreach (ThemesBuilder::getAll() as $t) {
            $themeTitles[(string)$t['id']] = (string)$t['name'];
        }

        // ─── Шаги ───
        $stepsSummary = [];
        try {
            $stepsSummary = StepsBuilder::getSummary($uid);
        } catch (\Throwable $e) {
            $stepsSummary = [];
        }

        // ─── Согласие и выбранная привычка ───
        $consent       = Storage::getConsent($uid);
        $selectedHabit = Storage::getSelectedHabit($uid);

        return [
            'LECTURES_READ'    => count($reads),
            'LECTURES_THEME'   => max(0, count($reads) - $lecturesGeneral - $chargingsDone),
            'LECTURES_GENERAL' => $lecturesGeneral,
            'CHARGINGS_DONE'   => $chargingsDone,
            'TASKS_DONE'       => count($taskKeys),
            'HABITS_DONE'      => $habitsTotal,
            'DAYS_ACTIVE'      => count($activeCounts),
            'STREAK'           => $streak,
            'BY_THEME'         => $byTheme,
            'THEME_TITLES'     => $themeTitles,
            'BY_HABIT'         => $byHabit,
            'HABIT_TITLES'     => $habitTitles,
            'WEEK_ACTIVITY'    => $week,
            'READS'            => array_values($reads),
            'TASKS'            => array_values($taskKeys),
            'HABITS'           => $habitData,
            'STEPS'            => $stepsSummary,

            // Плоские поля для удобства
            'CONSENT'          => $consent['granted'],
            'CONSENT_AT'       => $consent['serverAt'],
            'CONSENT_LOCAL_DT' => $consent['localDt'],
            'HAS_CONSENT'      => $consent['hasConsent'],
            'SELECTED_HABIT'   => $selectedHabit,
        ];
    }

    /**
     * Преобразует массив UF_LECTURE_ID → карту {key → bucket}.
     *
     * bucket:
     *   'general'          — общая лекция
     *   'charging'         — зарядка
     *   'theme:{code}'     — лекция по направлению
     *   'unknown'          — не удалось определить
     *
     * Поддерживает два формата ключей:
     *   • новый:   {elementId}-{YYYY-MM-DD}
     *   • legacy:  lecture-{theme}:{YYYY-MM-DD}:{suffix}
     */
    public static function resolveLectureBuckets(array $reads): array
    {
        $result = [];

        // legacy — без запросов
        $lookupIds = [];
        foreach ($reads as $r) {
            $r = (string)$r;
            if (preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})$/', $r, $m)) {
                $lookupIds[(int)$m[1]] = true;
            } elseif (preg_match('#^lecture-([a-z0-9_-]+):#i', $r, $m)) {
                $legacy = strtolower($m[1]);
                if ($legacy === 'general') {
                    $result[$r] = 'general';
                } elseif ($legacy === 'charging') {
                    $result[$r] = 'charging';
                } else {
                    $result[$r] = 'theme:' . $legacy;
                }
            } else {
                $result[$r] = 'unknown';
            }
        }

        if (!$lookupIds) return $result;

        $found           = []; // elementId → 'general'|'charging'|'theme'
        $themeByElement  = []; // elementId → code
        $ids             = array_keys($lookupIds);

        $ibGeneral  = (int)Settings::iblockId('general_lectures');
        $ibCharging = (int)Settings::iblockId('charging');
        $ibTheme    = (int)Settings::iblockId('theme_lectures');

        foreach ([
            'general'  => $ibGeneral,
            'charging' => $ibCharging,
        ] as $bucket => $ibId) {
            if ($ibId <= 0) continue;
            $rs = \CIBlockElement::GetList(
                [],
                ['ID' => $ids, 'IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
                false, false,
                ['ID']
            );
            while ($el = $rs->Fetch()) {
                $found[(int)$el['ID']] = $bucket;
            }
        }

        if ($ibTheme > 0) {
            $themeElements = [];
            $rs = \CIBlockElement::GetList(
                [],
                ['ID' => $ids, 'IBLOCK_ID' => $ibTheme, 'CHECK_PERMISSIONS' => 'N'],
                false, false,
                ['ID']
            );
            while ($el = $rs->Fetch()) {
                $elId = (int)$el['ID'];
                $themeElements[$elId] = true;
                $found[$elId] = 'theme';
            }

            foreach (array_keys($themeElements) as $elId) {
                $val = '';
                $props = \CIBlockElement::GetProperty($ibTheme, $elId, [], ['CODE' => 'THEME']);
                if ($p = $props->Fetch()) {
                    $val = (string)($p['VALUE'] ?? '');
                }
                $code = ThemesResolver::resolve($val);
                $themeByElement[$elId] = $code ?: 'unknown';
            }
        }

        foreach ($reads as $r) {
            $r = (string)$r;
            if (!preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})$/', $r, $m)) continue;
            $elId   = (int)$m[1];
            $bucket = $found[$elId] ?? 'unknown';
            if ($bucket === 'theme') {
                $result[$r] = 'theme:' . ($themeByElement[$elId] ?? 'unknown');
            } else {
                $result[$r] = $bucket;
            }
        }

        return $result;
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
        $dc   = Storage::dataClass('X5PwaLectureReads');
        $rows = $dc::getList(['select' => ['UF_LECTURE_ID']])->fetchAll();

        $ids     = array_column($rows, 'UF_LECTURE_ID');
        $buckets = self::resolveLectureBuckets($ids);

        $byTheme = [];
        foreach ($buckets as $bucket) {
            if (strpos($bucket, 'theme:') !== 0) continue;
            $t = substr($bucket, 6);
            if ($t === 'unknown' || $t === '') continue;
            $byTheme[$t] = ($byTheme[$t] ?? 0) + 1;
        }

        if (!$byTheme) return 'energy';
        arsort($byTheme);
        return (string)array_key_first($byTheme);
    }
}