<?php
namespace X5\Pwa;

use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;

Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

class StatsBuilder
{
    // ───────── Period ─────────

    public static function getEffectivePeriod(): array
    {
        $start = Settings::statsPeriodStart();
        $end   = Settings::statsPeriodEnd();

        $today = date('Y-m-d');

        if ($start === '') {
            $start = self::resolveAutoStart();
        }
        if ($end === '') {
            $end = $today;
        }
        if ($end > $today) $end = $today;
        if ($start > $end) $start = $end;

        return [$start, $end];
    }

    /**
     * Единый payload «периода» — используется и в config/period.json
     * (Dumper), и в /steps/summary (Api\Steps). Гарантирует одинаковый
     * контракт в обоих эндпоинтах.
     *
     * Ключи (все присутствуют всегда, значения — string "YYYY-MM-DD",
     * кроме PERIOD_CONFIGURED — bool):
     *   • START / END                 — эффективный период (END зажат к today,
     *                                    используется для арки прогресса);
     *   • PLANNED_START / PLANNED_END — СТАБИЛЬНЫЕ границы, НЕ зависят от today.
     *                                    Если период не задан — '2000-01-01' /
     *                                    '2099-12-31' (чтобы клиент не «прыгал»
     *                                    и не прятал свежие материалы после полуночи);
     *   • PERIOD_CONFIGURED           — true, если админ задал оба поля;
     *   • TODAY                       — серверная сегодняшняя дата.
     */
    public static function buildPeriodPayload(): array
    {
        [$periodFrom, $periodTo] = self::getEffectivePeriod();

        $plannedStart = Settings::statsPeriodStart();
        $plannedEnd   = Settings::statsPeriodEnd();
        $configured   = ($plannedStart !== '' && $plannedEnd !== '');

        return [
            'START'             => $periodFrom,
            'END'               => $periodTo,
            'PLANNED_START'     => $plannedStart !== '' ? $plannedStart : '2000-01-01',
            'PLANNED_END'       => $plannedEnd   !== '' ? $plannedEnd   : '2099-12-31',
            'PERIOD_CONFIGURED' => $configured,
            'TODAY'             => date('Y-m-d'),
        ];
    }

    private static function resolveAutoStart(): string
    {
        $today = date('Y-m-d');
        $floor = date('Y-m-d', strtotime('-365 days'));

        try {
            $conn = Application::getConnection();
            $row  = $conn->query(
                "SELECT MIN(UF_CREATED_AT) AS M FROM x5_pwa_uid_bindings"
            )->fetch();
            $min = $row['M'] ?? null;
            if (!$min || $min === '0000-00-00 00:00:00') {
                return $today;
            }
            $minDate = substr((string)$min, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) return $today;

            return $minDate > $floor ? $minDate : $floor;
        } catch (\Throwable $e) {
            return $floor;
        }
    }

    public static function getUserPeriod(int $userCreatedTs, string $globalFrom, string $globalTo): array
    {
        $from = $globalFrom;
        if ($userCreatedTs > 0) {
            $userDate = date('Y-m-d', $userCreatedTs);
            if ($userDate > $from) $from = $userDate;
        }
        if ($from > $globalTo) {
            return [$globalTo, $globalTo];
        }
        return [$from, $globalTo];
    }

    // ───────── Daily Apples ─────────

    public static function calculateDailyApples(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['BASE' => 0, 'THEMES' => []];
        }

        $themes = ThemesResolver::getAllCodes();
        if (!$themes && !ThemesResolver::isConfigured()) {
            $themes = ['energy', 'antistress', 'body', 'balance'];
        }

        $themeApples = [];
        foreach ($themes as $code) {
            $themeApples[$code] = 0;
        }

        $base = 0;

        $ibCharging = (int)Settings::iblockId('charging');
        if ($ibCharging > 0) {
            $base += self::sumRewardPointsForDate($ibCharging, $date);
        }

        $ibGeneral = (int)Settings::iblockId('general_lectures');
        if ($ibGeneral > 0) {
            $base += self::sumRewardPointsForDate($ibGeneral, $date);
        }

        $ibEvents = (int)Settings::iblockId('events');
        if ($ibEvents > 0) {
            $base += self::sumRewardPointsForDate($ibEvents, $date);
        }

        $reflPoints = 0;
        try {
            $setting = ReflectionBuilder::getForDate($date);
            $reflPoints = (int)($setting['rewardPoints'] ?? 0);
        } catch (\Throwable $e) {}
        if ($reflPoints <= 0) $reflPoints = Settings::rewardReflectionPoints();
        $base += $reflPoints;

        $base += Settings::rewardStepsSentPoints();
        $base += Settings::rewardStepsApprovedPoints();

        $ibTheme = (int)Settings::iblockId('theme_lectures');
        if ($ibTheme > 0) {
            self::sumRewardPointsForDateByTheme($ibTheme, $date, $themeApples);
        }

        $ibTasks = (int)Settings::iblockId('tasks');
        if ($ibTasks > 0) {
            self::sumRewardPointsForDateByTheme($ibTasks, $date, $themeApples);
        }

        return [
            'BASE'   => max(0, $base),
            'THEMES' => $themeApples,
        ];
    }

    private static function sumRewardPointsForDate(int $iblockId, string $date): int
    {
        if ($iblockId <= 0) return 0;

        $dateTs = strtotime($date);
        if ($dateTs === false) return 0;

        $dateSite = \ConvertTimeStamp($dateTs, 'SHORT');
        if ($dateSite === false || $dateSite === '') return 0;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID'          => $iblockId,
                'ACTIVE'             => 'Y',
                'CHECK_PERMISSIONS'  => 'N',
                '>=DATE_ACTIVE_FROM' => $dateSite,
                '<=DATE_ACTIVE_FROM' => $dateSite,
            ],
            false,
            false,
            ['ID']
        );

        $total = 0;
        while ($el = $rs->Fetch()) {
            $props = \CIBlockElement::GetProperty($iblockId, (int)$el['ID'], [], ['CODE' => 'REWARD_POINTS']);
            if ($p = $props->Fetch()) {
                $total += max(0, (int)($p['VALUE'] ?? 0));
            }
        }
        return $total;
    }

    private static function sumRewardPointsForDateByTheme(int $iblockId, string $date, array &$themeApples): void
    {
        if ($iblockId <= 0) return;

        $dateTs = strtotime($date);
        if ($dateTs === false) return;

        $dateSite = \ConvertTimeStamp($dateTs, 'SHORT');
        if ($dateSite === false || $dateSite === '') return;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID'          => $iblockId,
                'ACTIVE'             => 'Y',
                'CHECK_PERMISSIONS'  => 'N',
                '>=DATE_ACTIVE_FROM' => $dateSite,
                '<=DATE_ACTIVE_FROM' => $dateSite,
            ],
            false,
            false,
            ['ID']
        );

        while ($el = $rs->Fetch()) {
            $elId = (int)$el['ID'];

            $themeValue = '';
            $props = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'THEME']);
            if ($p = $props->Fetch()) {
                $themeValue = (string)($p['VALUE'] ?? '');
            }
            if ($themeValue === '') continue;

            $themeCode = ThemesResolver::resolve($themeValue);
            if ($themeCode === null || $themeCode === '') continue;

            if (!isset($themeApples[$themeCode])) continue;

            $points = 0;
            $props2 = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'REWARD_POINTS']);
            if ($p2 = $props2->Fetch()) {
                $points = max(0, (int)($p2['VALUE'] ?? 0));
            }

            $themeApples[$themeCode] += $points;
        }
    }

    public static function calculateDailyMaxForUser(string $uid, string $date): int
    {
        $binding = Storage::getUidBinding($uid);
        if (!$binding) return 0;

        $themeCode   = trim((string)($binding['UF_THEME'] ?? ''));
        $habitPoints = self::resolveHabitPoints((string)($binding['UF_HABIT_ID'] ?? ''));

        $apples = self::getDailyApples($date);

        $max = (int)($apples['BASE'] ?? 0);
        if ($themeCode !== '' && isset($apples['THEMES'][$themeCode])) {
            $max += (int)$apples['THEMES'][$themeCode];
        }
        $max += $habitPoints;

        return max(0, $max);
    }

    /**
     * Batch-версия calculateDailyMaxForUser: возвращает [date => max] за набор дат.
     *
     * Ключевое отличие от N×calculateDailyMaxForUser:
     *   • binding ($uid → theme/habit) читается 1 раз;
     *   • кэш X5PwaDailyApples общий ($applesCache), поэтому после прогретого
     *     кэша (recalcAllStats раз в час) новых SQL-запросов нет;
     *   • для 8-дневного марафона — 1-2 SQL вместо 8-16.
     *
     * Используется в ApplesBuilder::buildDailyEfficiency() (экран «Эффективность по дням»).
     */
    public static function calculateDailyMaxForUserBatch(string $uid, array $dates): array
    {
        $out = [];
        if (!$dates) return $out;

        foreach ($dates as $d) $out[$d] = 0;

        $binding = Storage::getUidBinding($uid);
        if (!$binding) return $out;

        $themeCode   = trim((string)($binding['UF_THEME'] ?? ''));
        $habitPoints = self::resolveHabitPoints((string)($binding['UF_HABIT_ID'] ?? ''));

        $cache = [];
        foreach ($dates as $d) {
            $apples = self::getDailyApples($d, $cache);

            $max = (int)($apples['BASE'] ?? 0);
            if ($themeCode !== '' && isset($apples['THEMES'][$themeCode])) {
                $max += (int)$apples['THEMES'][$themeCode];
            }
            $max += $habitPoints;

            $out[$d] = max(0, $max);
        }
        return $out;
    }

    public static function getDailyApples(string $date, array &$cache = []): array
    {
        if (isset($cache[$date])) return $cache[$date];

        $cached = Storage::getDailyApplesFromCache($date);
        if ($cached !== null) {
            $cache[$date] = $cached;
            return $cached;
        }

        $calculated = self::calculateDailyApples($date);
        Storage::saveDailyApples($date, $calculated);
        $cache[$date] = $calculated;
        return $calculated;
    }

    public static function sumDailyMaxForUser(
        string $from,
        string $to,
        string $themeCode,
        int $habitPoints,
        array &$applesCache = []
    ): int {
        $cursor = strtotime($from);
        $endTs  = strtotime($to);
        if ($cursor === false || $endTs === false || $endTs < $cursor) return 0;

        $sum = 0;
        while ($cursor <= $endTs) {
            $d      = date('Y-m-d', $cursor);
            $apples = self::getDailyApples($d, $applesCache);

            $dayMax = (int)($apples['BASE'] ?? 0);
            if ($themeCode !== '' && isset($apples['THEMES'][$themeCode])) {
                $dayMax += (int)$apples['THEMES'][$themeCode];
            }
            $dayMax += $habitPoints;

            $sum += $dayMax;
            $cursor = strtotime('+1 day', $cursor);
        }
        return $sum;
    }

    private static function resolveHabitPoints(string $habitId): int
    {
        $habitId = trim($habitId);
        if ($habitId === '') return 0;

        foreach (HabitsBuilder::getAll() as $h) {
            if ((string)$h['id'] === $habitId) {
                $points = (int)($h['rewardPoints'] ?? 0);
                $times  = max(1, (int)($h['timesPerDay'] ?? 1));
                return $points * $times;
            }
        }
        return 0;
    }

    /**
     * @deprecated Глобальный максимум (BASE + все THEMES). Только для диагностики.
     */
    public static function calculateDailyMax(string $date): int
    {
        $apples = self::calculateDailyApples($date);
        return (int)($apples['BASE'] ?? 0) + array_sum($apples['THEMES'] ?? []);
    }

    // ───────── Global ─────────

    public static function buildGlobal(): array
    {
        [$from, $to] = self::getEffectivePeriod();
        $today = date('Y-m-d');

        // ── Общие счётчики по всей базе ──
        $totalRegistered   = 0;
        $totalParticipants = 0;
        try {
            $totalsRow = Application::getConnection()->query(
                "SELECT
                    COUNT(*) AS REGISTERED,
                    SUM(CASE WHEN UF_CONSENT_GRANTED = 'Y'
                              AND UF_THEME IS NOT NULL AND UF_THEME != ''
                              AND (UF_BLOCKED IS NULL OR UF_BLOCKED = 'N')
                              AND (UF_IS_TEST IS NULL OR UF_IS_TEST != 'Y')
                         THEN 1 ELSE 0 END) AS PARTICIPANTS
                 FROM x5_pwa_uid_bindings"
            )->fetch();
            $totalRegistered   = (int)($totalsRow['REGISTERED']   ?? 0);
            $totalParticipants = (int)($totalsRow['PARTICIPANTS'] ?? 0);
        } catch (\Throwable $e) {
            Settings::logError(
                'buildGlobal totals failed: ' . $e->getMessage(),
                'StatsBuilder::buildGlobal'
            );
        }

        // ── ЕДИНАЯ БАЗА: все участники марафона с активностью в [from, to] ──
        $participatingUids  = Storage::getParticipatingUidsInPeriod($from, $to);
        $totalUsersInPeriod = count($participatingUids);

        // ── TOTAL_DAY_ADD_USERS: новые пользователи за сегодня ──
        $totalDayAddUsers = Storage::countRegisteredOnDate($today);

        // ── Статистика по единой базе ──
        // periodStats — за весь период [from, to]  → *_TOTAL
        // dayStats    — за сегодня [today, today]  → *_DAY
        $periodStats = Storage::getPeriodStatsForUids($participatingUids, $from, $to);
        $dayStats    = Storage::getPeriodStatsForUids($participatingUids, $today, $today);

        // Агрегатор по компаниям (period + day)
        $wpMap = WorkplacesBuilder::getMap();
        $wpAgg = [];
        foreach ($wpMap as $code => $_) {
            $wpAgg[$code] = [
                'users' => 0, 'apples' => 0,
                'earned' => 0, 'max' => 0, 'earned_day' => 0, 'max_day' => 0,
                'lectures' => 0, 'tasks' => 0, 'habits' => 0, 'steps' => 0,
                'events_v' => 0, 'events_c' => 0,
                'lectures_day' => 0, 'tasks_day' => 0, 'habits_day' => 0, 'steps_day' => 0,
                'events_v_day' => 0, 'events_c_day' => 0,
            ];
        }

        $totalApples = 0;
        $totalUsers  = 0;

        // ── TOTAL (за весь период) ──
        $lecturesPeriod        = 0;
        $tasksPeriod           = 0;
        $habitsPeriod          = 0;
        $stepsPeriod           = 0;
        $eventsVisitedPeriod   = 0;
        $eventsConfirmedPeriod = 0;

        // ── DAY (за сегодня) ──
        $lecturesDay        = 0;
        $tasksDay           = 0;
        $habitsDay          = 0;
        $stepsDay           = 0;
        $eventsVisitedDay   = 0;
        $eventsConfirmedDay = 0;

        // ── Эффективность ──
        $totalEarned    = 0;
        $totalMax       = 0;
        $totalEarnedDay = 0;
        $totalMaxDay    = 0;

        $applesCache = [];

        $habitPointsCache = [];
        foreach (HabitsBuilder::getAll() as $h) {
            $points = (int)($h['rewardPoints'] ?? 0);
            $times  = max(1, (int)($h['timesPerDay'] ?? 1));
            $habitPointsCache[(string)$h['id']] = $points * $times;
        }

        $todayApples = self::getDailyApples($today, $applesCache);

        $bindings = Storage::getBindingsForStats($participatingUids);

        foreach ($bindings as $uid => $b) {
            if ($b['blocked']) continue;
            if (!$b['consent']) continue;

            $themeCode = (string)($b['theme'] ?? '');
            if ($themeCode === '') continue;

            $totalUsers++;
            $totalApples += (int)$b['reward_balance'];

            [$userFrom, $userTo] = self::getUserPeriod($b['created_ts'], $from, $to);

            $wp = $b['workplace'];

            if ($wp !== '' && isset($wpAgg[$wp])) {
                $wpAgg[$wp]['users']++;
                $wpAgg[$wp]['apples'] += (int)$b['reward_balance'];
            }

            $habitId     = (string)($b['habit_id'] ?? '');
            $habitPoints = $habitPointsCache[$habitId] ?? 0;

            // ── Награды: за период / за сегодня ──
            $earned      = Storage::getEarnedBetween($uid, $userFrom, $userTo);
            $earnedToday = Storage::getEarnedOnDate($uid, $today);

            // ── Потенциал: за период / за сегодня ──
            $maxTotal = self::sumDailyMaxForUser($userFrom, $userTo, $themeCode, $habitPoints, $applesCache);

            $dayMax = (int)($todayApples['BASE'] ?? 0);
            if (isset($todayApples['THEMES'][$themeCode])) {
                $dayMax += (int)$todayApples['THEMES'][$themeCode];
            }
            $dayMax += $habitPoints;

            $totalEarned    += $earned;
            $totalMax       += $maxTotal;
            $totalEarnedDay += $earnedToday;
            $totalMaxDay    += $dayMax;

            // ── Активности за период ──
            $ps = $periodStats[$uid] ?? [
                'lectures' => 0, 'tasks' => 0, 'habits' => 0, 'steps' => 0,
                'events_visited' => 0, 'events_confirmed' => 0,
            ];

            $lecturesPeriod        += (int)$ps['lectures'];
            $tasksPeriod           += (int)$ps['tasks'];
            $habitsPeriod          += (int)$ps['habits'];
            $stepsPeriod           += (int)$ps['steps'];
            $eventsVisitedPeriod   += (int)$ps['events_visited'];
            $eventsConfirmedPeriod += (int)$ps['events_confirmed'];

            // ── Активности за сегодня ──
            $ds = $dayStats[$uid] ?? [
                'lectures' => 0, 'tasks' => 0, 'habits' => 0, 'steps' => 0,
                'events_visited' => 0, 'events_confirmed' => 0,
            ];

            $lecturesDay        += (int)$ds['lectures'];
            $tasksDay           += (int)$ds['tasks'];
            $habitsDay          += (int)$ds['habits'];
            $stepsDay           += (int)$ds['steps'];
            $eventsVisitedDay   += (int)$ds['events_visited'];
            $eventsConfirmedDay += (int)$ds['events_confirmed'];

            // ── Агрегация по компаниям ──
            if ($wp !== '' && isset($wpAgg[$wp])) {
                $wpAgg[$wp]['earned']     += $earned;
                $wpAgg[$wp]['max']        += $maxTotal;
                $wpAgg[$wp]['earned_day'] += $earnedToday;
                $wpAgg[$wp]['max_day']    += $dayMax;

                $wpAgg[$wp]['lectures']    += (int)$ps['lectures'];
                $wpAgg[$wp]['tasks']       += (int)$ps['tasks'];
                $wpAgg[$wp]['habits']      += (int)$ps['habits'];
                $wpAgg[$wp]['steps']       += (int)$ps['steps'];
                $wpAgg[$wp]['events_v']    += (int)$ps['events_visited'];
                $wpAgg[$wp]['events_c']    += (int)$ps['events_confirmed'];

                $wpAgg[$wp]['lectures_day']    += (int)$ds['lectures'];
                $wpAgg[$wp]['tasks_day']       += (int)$ds['tasks'];
                $wpAgg[$wp]['habits_day']      += (int)$ds['habits'];
                $wpAgg[$wp]['steps_day']       += (int)$ds['steps'];
                $wpAgg[$wp]['events_v_day']    += (int)$ds['events_visited'];
                $wpAgg[$wp]['events_c_day']    += (int)$ds['events_confirmed'];
            }
        }

        // Sanity: getBindingsForStats() должен вернуть запись на каждый UID.
        if ($totalUsers !== $totalUsersInPeriod) {
            Settings::logError(
                "buildGlobal: count mismatch uids={$totalUsersInPeriod} bindings={$totalUsers}",
                'StatsBuilder::buildGlobal'
            );
        }

        // Список компаний
        $list = [];
        foreach ($wpAgg as $code => $agg) {
            if ($agg['users'] <= 0) continue;

            $percent = 0.0;
            if ($agg['max'] > 0) {
                $percent = round($agg['earned'] / $agg['max'] * 100, 2);
            }

            $percentDay = 0.0;
            if ($agg['max_day'] > 0) {
                $percentDay = round($agg['earned_day'] / $agg['max_day'] * 100, 2);
            }

            $wp = $wpMap[$code] ?? ['id' => $code, 'name' => $code, 'logo' => ''];

            $list[] = [
                'ID'           => $code,
                'NAME'         => (string)$wp['name'],
                'PERCENT'      => $percent,
                'LOGO'         => (string)$wp['logo'],
                'TOTAL_APPLES' => (int)$agg['apples'],
                'USERS'        => (int)$agg['users'],
                'EARNED'       => (int)$agg['earned'],
                'MAX'          => (int)$agg['max'],
                'MISSING'      => max(0, (int)$agg['max'] - (int)$agg['earned']),
                'PERCENT_DAY'  => $percentDay,
                'EARNED_DAY'   => (int)$agg['earned_day'],
                'MAX_DAY'      => (int)$agg['max_day'],
                'MISSING_DAY'  => max(0, (int)$agg['max_day'] - (int)$agg['earned_day']),

                // TOTAL (за период)
                'LECTURES'         => (int)$agg['lectures'],
                'TASKS'            => (int)$agg['tasks'],
                'HABITS'           => (int)$agg['habits'],
                'STEPS'            => (int)$agg['steps'],
                'EVENTS_VISITED'   => (int)$agg['events_v'],
                'EVENTS_CONFIRMED' => (int)$agg['events_c'],

                // DAY (за сегодня)
                'LECTURES_DAY'         => (int)$agg['lectures_day'],
                'TASKS_DAY'            => (int)$agg['tasks_day'],
                'HABITS_DAY'           => (int)$agg['habits_day'],
                'STEPS_DAY'            => (int)$agg['steps_day'],
                'EVENTS_VISITED_DAY'   => (int)$agg['events_v_day'],
                'EVENTS_CONFIRMED_DAY' => (int)$agg['events_c_day'],
            ];
        }
        usort($list, fn($a, $b) => $b['PERCENT'] <=> $a['PERCENT']);

        $totalMissing = max(0, $totalMax - $totalEarned);
        $avgEfficiency = $totalMax > 0
            ? round($totalEarned / $totalMax * 100, 2)
            : 0.0;

        $totalMissingDay = max(0, $totalMaxDay - $totalEarnedDay);
        $avgEfficiencyDay = $totalMaxDay > 0
            ? round($totalEarnedDay / $totalMaxDay * 100, 2)
            : 0.0;

        return [
            'RESULT'          => 'OK',
            'GLOBAL_PROGRESS' => [
                // ── Метрики шапки ──
                'TOTAL_REGISTERED'      => $totalRegistered,
                'TOTAL_PARTICIPANTS'    => $totalParticipants,
                'TOTAL_USERS'           => $totalUsersInPeriod,
                'TOTAL_DAY_ADD_USERS'   => $totalDayAddUsers,

                // ── TOTAL: за весь период [from, to] (база = TOTAL_USERS) ──
                'LECTURES_READ_TOTAL'      => $lecturesPeriod,
                'TASKS_DONE_TOTAL'         => $tasksPeriod,
                'HABITS_DAYS_TOTAL'        => $habitsPeriod,
                'STEPS_CONFIRMED_TOTAL'    => $stepsPeriod,
                'EVENTS_VISITED_TOTAL'     => $eventsVisitedPeriod,
                'EVENTS_CONFIRMED_TOTAL'   => $eventsConfirmedPeriod,

                // ── DAY: за сегодня (база = TOTAL_USERS) ──
                'LECTURES_READ_DAY'        => $lecturesDay,
                'TASKS_DONE_DAY'           => $tasksDay,
                'HABITS_DAYS_DAY'          => $habitsDay,
                'STEPS_CONFIRMED_DAY'      => $stepsDay,
                'EVENTS_VISITED_DAY'       => $eventsVisitedDay,
                'EVENTS_CONFIRMED_DAY'     => $eventsConfirmedDay,

                // ── Alias для обратной совместимости (= *_TOTAL) ──
                'LECTURES_READ'    => $lecturesPeriod,
                'TASKS_DONE'       => $tasksPeriod,
                'HABITS_DAYS'      => $habitsPeriod,
                'STEPS_CONFIRMED'  => $stepsPeriod,
                'EVENTS_VISITED'   => $eventsVisitedPeriod,
                'EVENTS_CONFIRMED' => $eventsConfirmedPeriod,

                // ── Яблоки ──
                // TOTAL_APPLES — снимок баланса (за всё время, включая pre-period)
                // EARNED_TOTAL — заработано за период
                // EARNED_DAY_TOTAL — заработано за сегодня
                'TOTAL_APPLES'      => $totalApples,
                'APPLES_TOTAL'      => $totalApples,      // alias
                'EARNED_TOTAL'      => $totalEarned,
                'EARNED_DAY_TOTAL'  => $totalEarnedDay,
                'APPLES_DAY'        => $totalEarnedDay,   // alias

                // ── Эффективность ──
                'MAX_TOTAL'           => $totalMax,
                'MISSING_TOTAL'       => $totalMissing,
                'AVG_EFFICIENCY'      => $avgEfficiency,
                'MAX_DAY_TOTAL'       => $totalMaxDay,
                'MISSING_DAY_TOTAL'   => $totalMissingDay,
                'AVG_EFFICIENCY_DAY'  => $avgEfficiencyDay,

                'TODAY' => $today,
            ],
            'COMPANIES'       => ['LIST' => $list],
            'PERIOD'          => ['FROM' => $from, 'TO' => $to],
            'UPDATED_AT'      => (new \Bitrix\Main\Type\DateTime())->toString(),
        ];
    }

    // ───────── Personal ─────────

    public static function buildPersonal(string $uid): ?array
    {
        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) return null;

        $binding = Storage::getBindingForPersonal($uid);
        if ($binding === null) return null;
        if ($binding['blocked']) return null;
        if (!$binding['consent']) return null;

        $rank           = (int)$binding['rank'];
        $effAll         = (float)$binding['eff_all'];
        $effDay         = (float)$binding['eff_day'];
        $habitsDays     = (int)$binding['habits_days'];
        $apples         = (int)$binding['reward_balance'];
        $stepsConfirmed = (int)$binding['total_steps'];

        $reads = Storage::getReads($uid);
        $tasks = Storage::getTasks($uid);
        $lecturesRead = count($reads);
        $tasksDone    = count($tasks);

        if ($apples <= 0
            && $lecturesRead <= 0
            && $tasksDone <= 0
            && $habitsDays <= 0
            && $stepsConfirmed <= 0) {
            return null;
        }

        $company = [];
        if ($binding['workplace'] !== '') {
            $wp = WorkplacesBuilder::getByCode($binding['workplace']);
            if ($wp !== null) {
                [$from, $to] = self::getEffectivePeriod();
                $company = [
                    'COMPANY_CODE'    => (string)$wp['id'],
                    'COMPANY_NAME'    => (string)$wp['name'],
                    'COMPANY_PERCENT' => self::getCompanyPercent($binding['workplace'], $from, $to),
                ];
            }
        }

        return [
            'APPLES'          => $apples,
            'RANK'            => $rank,
            'LECTURES_READ'   => $lecturesRead,
            'TASKS_DONE'      => $tasksDone,
            'HABITS_DAYS'     => $habitsDays,
            'STEPS_CONFIRMED' => $stepsConfirmed,
            'EFFICIENCY_ALL'  => $effAll,
            'EFFICIENCY_DAY'  => $effDay,
            'EARNED_ALL'      => (int)($binding['earned_all'] ?? 0),
            'MAX_ALL'         => (int)($binding['max_all'] ?? 0),
            'MISSING_ALL'     => (int)($binding['missing_all'] ?? 0),
            'EARNED_DAY'      => (int)($binding['earned_day'] ?? 0),
            'MAX_DAY'         => (int)($binding['max_day'] ?? 0),
        ] + $company;
    }

    private static function getCompanyPercent(string $workplace, string $from, string $to): float
    {
        if ($workplace === '') return 0.0;

        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeWp    = $sqlHelper->forSql($workplace);

        try {
            $rows = $conn->query(
                "SELECT UF_UID, UF_CREATED_AT, UF_THEME, UF_HABIT_ID
                 FROM x5_pwa_uid_bindings
                 WHERE UF_WORKPLACE = '{$safeWp}'
                   AND UF_CONSENT_GRANTED = 'Y'
                   AND (UF_BLOCKED IS NULL OR UF_BLOCKED = 'N')
                   AND (UF_THEME IS NOT NULL AND UF_THEME != '')"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0.0;
        }

        if (!$rows) return 0.0;

        $habitPointsCache = [];
        foreach (HabitsBuilder::getAll() as $h) {
            $points = (int)($h['rewardPoints'] ?? 0);
            $times  = max(1, (int)($h['timesPerDay'] ?? 1));
            $habitPointsCache[(string)$h['id']] = $points * $times;
        }

        $applesCache = [];
        $earned = 0;
        $max    = 0;

        foreach ($rows as $r) {
            $u = (string)$r['UF_UID'];
            $createdTs = !empty($r['UF_CREATED_AT'])
                ? strtotime((string)$r['UF_CREATED_AT']) : 0;

            $themeCode   = trim((string)($r['UF_THEME'] ?? ''));
            $habitId     = trim((string)($r['UF_HABIT_ID'] ?? ''));
            $habitPoints = $habitPointsCache[$habitId] ?? 0;

            [$userFrom, $userTo] = self::getUserPeriod((int)$createdTs, $from, $to);

            $earned += Storage::getEarnedBetween($u, $userFrom, $userTo);
            $max    += self::sumDailyMaxForUser($userFrom, $userTo, $themeCode, $habitPoints, $applesCache);
        }

        if ($max <= 0) return 0.0;
        return round($earned / $max * 100, 2);
    }

    // ───────── Пересчёт кешей ─────────

    public static function recalcAllStats(): array
    {
        $startTime = microtime(true);

        [$from, $to] = self::getEffectivePeriod();
        $today       = date('Y-m-d');

        $uids = Storage::getActiveUidsForStats();
        if (!$uids) {
            Settings::touchStatsRecalcTime();
            return ['processed' => 0, 'elapsed' => 0];
        }

        $bindings = Storage::getBindingsForStats($uids);

        $applesCache = [];
        $cursor = strtotime($from);
        $endTs  = strtotime($to);
        while ($cursor <= $endTs) {
            $d = date('Y-m-d', $cursor);
            self::getDailyApples($d, $applesCache);
            $cursor = strtotime('+1 day', $cursor);
        }

        $habitPointsCache = [];
        foreach (HabitsBuilder::getAll() as $h) {
            $points = (int)($h['rewardPoints'] ?? 0);
            $times  = max(1, (int)($h['timesPerDay'] ?? 1));
            $habitPointsCache[(string)$h['id']] = $points * $times;
        }

        $computed = [];

        foreach ($bindings as $uid => $b) {
            if ($b['blocked'] || !$b['consent']) continue;

            $themeCode = (string)($b['theme'] ?? '');
            if ($themeCode === '') continue;

            [$userFrom, $userTo] = self::getUserPeriod($b['created_ts'], $from, $to);

            $habitId     = (string)($b['habit_id'] ?? '');
            $habitPoints = $habitPointsCache[$habitId] ?? 0;

            $earnedAll   = Storage::getEarnedBetween($uid, $userFrom, $userTo);
            $earnedToday = Storage::getEarnedOnDate($uid, $today);

            $maxTotal = self::sumDailyMaxForUser($userFrom, $userTo, $themeCode, $habitPoints, $applesCache);

            $todayApples = $applesCache[$today] ?? self::getDailyApples($today, $applesCache);
            $todayMax    = (int)($todayApples['BASE'] ?? 0);
            if (isset($todayApples['THEMES'][$themeCode])) {
                $todayMax += (int)$todayApples['THEMES'][$themeCode];
            }
            $todayMax += $habitPoints;

            $effAll = $maxTotal > 0 ? round($earnedAll / $maxTotal * 100, 2) : 0.0;
            $effDay = $todayMax > 0 ? round($earnedToday / $todayMax * 100, 2) : 0.0;

            $habitsDays = 0;
            $selectedHabit = $b['habit_id'];
            if ($selectedHabit !== '') {
                $times = 1;
                foreach (HabitsBuilder::getAll() as $h) {
                    if ((string)$h['id'] === $selectedHabit) {
                        $times = max(1, (int)($h['timesPerDay'] ?? 1));
                        break;
                    }
                }
                $habitsDays = Storage::getHabitFullDaysCount($uid, $selectedHabit, $times, $userFrom, $userTo);
            }

            $computed[$uid] = [
                'eff_all'     => $effAll,
                'eff_day'     => $effDay,
                'habits_days' => $habitsDays,
                'created_ts'  => $b['created_ts'],
                'steps'       => $b['total_steps'],
                'rank'        => 0,
                'earned_all'  => $earnedAll,
                'max_all'     => $maxTotal,
                'missing_all' => max(0, $maxTotal - $earnedAll),
                'earned_day'  => $earnedToday,
                'max_day'     => $todayMax,
            ];
        }

        $sorted = $computed;
        uasort($sorted, function ($a, $b) {
            if ($a['eff_all'] != $b['eff_all']) return $b['eff_all'] <=> $a['eff_all'];
            if ($a['created_ts'] != $b['created_ts']) return $a['created_ts'] <=> $b['created_ts'];
            return $b['steps'] <=> $a['steps'];
        });

        $rank = 1;
        $updates = [];
        foreach ($sorted as $uid => $v) {
            $computed[$uid]['rank'] = $rank++;
            $updates[$uid] = [
                'rank'        => $computed[$uid]['rank'],
                'eff_all'     => $computed[$uid]['eff_all'],
                'eff_day'     => $computed[$uid]['eff_day'],
                'habits_days' => $computed[$uid]['habits_days'],
                'earned_all'  => $computed[$uid]['earned_all'],
                'max_all'     => $computed[$uid]['max_all'],
                'missing_all' => $computed[$uid]['missing_all'],
                'earned_day'  => $computed[$uid]['earned_day'],
                'max_day'     => $computed[$uid]['max_day'],
            ];
        }

        Storage::bulkUpdateStatsCache($updates);
        Settings::touchStatsRecalcTime();

        $elapsed = round(microtime(true) - $startTime, 2);

        Settings::log(
            "recalcAllStats: processed=" . count($updates)
            . " period={$from}..{$to} elapsed={$elapsed}s",
            'StatsBuilder::recalcAllStats'
        );

        return ['processed' => count($updates), 'elapsed' => $elapsed];
    }

    // ───────── Users list (админка) ─────────

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

        $uids = array_column($rows, 'UF_UID');

        [$periodFrom, $periodTo] = self::getEffectivePeriod();
        $periodStats = Storage::getPeriodStatsForUids($uids, $periodFrom, $periodTo);
        $totalStats  = Storage::getStatsForUids($uids);

        $subs     = Storage::getSubscriptionsForUids($uids);
        $activity = Storage::getLastActivityForUids($uids);

        $out = [];
        foreach ($rows as $b) {
            $uid = (string)$b['UF_UID'];
            $ps  = $periodStats[$uid] ?? [
                'lectures' => 0, 'tasks' => 0, 'habits' => 0, 'steps' => 0,
                'events_visited' => 0, 'events_confirmed' => 0,
            ];
            $ts  = $totalStats[$uid] ?? ['lectures' => 0, 'tasks' => 0, 'habits' => 0];

            $lastSeen = $b['UF_LAST_SEEN_AT']
                ? $b['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0;

            $lastActive = $lastSeen;
            if (isset($activity[$uid]) && $activity[$uid] > $lastActive) {
                $lastActive = $activity[$uid];
            }

            $blocked   = ($b['UF_BLOCKED'] ?? 'N') === 'Y';
            $blockedAt = !empty($b['UF_BLOCKED_AT'])
                ? $b['UF_BLOCKED_AT']->getTimestamp() * 1000 : 0;

            $consentAt = !empty($b['UF_CONSENT_DATETIME'])
                ? $b['UF_CONSENT_DATETIME']->getTimestamp() * 1000 : 0;

            $out[] = [
                'UID'              => $uid,
                'DEVICE_ID'        => (string)$b['UF_DEVICE_ID'],
                'FIO'              => (string)($b['UF_FIO']       ?? ''),
                'NICKNAME'         => (string)($b['UF_NICKNAME']  ?? ''),
                'WORKPLACE'        => (string)($b['UF_WORKPLACE'] ?? ''),
                'THEME'            => (string)($b['UF_THEME']     ?? ''),
                'CONSENT'          => (string)($b['UF_CONSENT_GRANTED'] ?? ''),   // 'Y' | 'N' | ''
                'CONSENT_AT'       => $consentAt,
                'CONSENT_LOCAL_DT' => (string)($b['UF_CONSENT_LOCAL_DT'] ?? ''),
                'CREATED_AT'       => $b['UF_CREATED_AT']
                    ? $b['UF_CREATED_AT']->getTimestamp() * 1000 : 0,
                'LAST_SEEN_AT'     => $lastSeen,
                'LAST_ACTIVITY_AT' => $lastActive,
                'HAS_SUBSCRIPTION' => !empty($subs[$uid]),

                'LECTURES_READ'    => (int)$ps['lectures'],
                'TASKS_DONE'       => (int)$ps['tasks'],
                'HABITS_DONE'      => (int)$ps['habits'],
                'STEPS_CONFIRMED'  => (int)$ps['steps'],
                'EVENTS_VISITED'   => (int)$ps['events_visited'],
                'EVENTS_CONFIRMED' => (int)$ps['events_confirmed'],

                'LECTURES_READ_TOTAL' => (int)$ts['lectures'],
                'TASKS_DONE_TOTAL'    => (int)$ts['tasks'],
                'HABITS_DONE_TOTAL'   => (int)$ts['habits'],

                'BLOCKED'          => $blocked,
                'BLOCKED_AT'       => $blockedAt,
                'BLOCK_REASON'     => (string)($b['UF_BLOCK_REASON'] ?? ''),

                'EFFICIENCY_ALL'   => (float)($b['UF_EFFICIENCY_ALL'] ?? 0),
                'EFFICIENCY_DAY'   => (float)($b['UF_EFFICIENCY_DAY'] ?? 0),
                'EARNED_ALL'       => (int)($b['UF_EARNED_ALL'] ?? 0),
                'MAX_ALL'          => (int)($b['UF_MAX_ALL'] ?? 0),
                'MISSING_ALL'      => (int)($b['UF_MISSING_ALL'] ?? 0),
                'EARNED_DAY'       => (int)($b['UF_EARNED_DAY'] ?? 0),
                'MAX_DAY'          => (int)($b['UF_MAX_DAY'] ?? 0),
                'REWARD_BALANCE'   => (int)($b['UF_REWARD_BALANCE'] ?? 0),

                'PERIOD_FROM'      => $periodFrom,
                'PERIOD_TO'        => $periodTo,
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

    /**
     * Агрегат по фильтру для шапки users.php.
     *
     * Содержит метрики:
     *   — за период: earned_sum, max_sum, missing_sum, avg_eff;
     *   — за сегодня: earned_day_sum, max_day_sum, missing_day_sum, avg_eff_day.
     *
     * Учитываются только пользователи с выбранной темой (по БМ).
     */
    public static function getUsersAggregate(array $params = []): array
    {
        $empty = [
            'total' => 0,
            'earned_sum' => 0, 'max_sum' => 0, 'missing_sum' => 0,
            'avg_eff' => 0.0, 'balance_sum' => 0,
            'earned_day_sum' => 0, 'max_day_sum' => 0,
            'missing_day_sum' => 0, 'avg_eff_day' => 0.0,
        ];

        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $where = ['1=1'];

        $where[] = "(UF_THEME IS NOT NULL AND UF_THEME != '')";

        // Согласуем агрегат с фильтром по consent.
        // - пусто / 'Y' — только давшие согласие (как buildGlobal);
        // - 'N' — только отказавшиеся;
        // - 'NOT_Y' — все без consent (N + NULL + '');
        // - 'ALL' — без фильтра (для аудита).
        $consent = (string)($params['consent'] ?? '');
        if ($consent === '' || $consent === 'Y') {
            $where[] = "UF_CONSENT_GRANTED = 'Y'";
        } elseif ($consent === 'N') {
            $where[] = "UF_CONSENT_GRANTED = 'N'";
        } elseif ($consent === 'NOT_Y') {
            $where[] = "(UF_CONSENT_GRANTED IS NULL OR UF_CONSENT_GRANTED != 'Y')";
        }
        // 'ALL' — ничего не добавляем (аудит)

        if (!empty($params['search'])) {
            $s = $sqlHelper->forSql('%' . $params['search'] . '%');
            $where[] = "(UF_UID LIKE '{$s}' OR UF_FIO LIKE '{$s}' OR UF_NICKNAME LIKE '{$s}' OR UF_WORKPLACE LIKE '{$s}')";
        }
        if (!empty($params['theme'])) {
            $safe = $sqlHelper->forSql((string)$params['theme']);
            $where[] = "UF_THEME = '{$safe}'";
        }
        $blocked = (string)($params['blocked'] ?? '');
        if ($blocked === 'Y') {
            $where[] = "UF_BLOCKED = 'Y'";
        } elseif ($blocked === 'N') {
            $where[] = "(UF_BLOCKED IS NULL OR UF_BLOCKED != 'Y')";
        }

        if (!empty($params['active_from'])) {
            $safe = $sqlHelper->forSql((string)$params['active_from']);
            $where[] = "UF_LAST_SEEN_AT >= '{$safe}'";
        }
        if (!empty($params['active_to'])) {
            $safe = $sqlHelper->forSql((string)$params['active_to']);
            $where[] = "UF_LAST_SEEN_AT <= '{$safe}'";
        }
        if (empty($params['active_from']) && empty($params['active_to'])
            && !empty($params['activeDays'])) {
            $days = max(1, (int)$params['activeDays']);
            $where[] = "UF_LAST_SEEN_AT >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT
                    COUNT(*) AS C,
                    COALESCE(SUM(UF_EARNED_ALL), 0) AS E,
                    COALESCE(SUM(UF_MAX_ALL), 0) AS M,
                    COALESCE(SUM(UF_MISSING_ALL), 0) AS MI,
                    COALESCE(AVG(NULLIF(UF_EFFICIENCY_ALL, 0)), 0) AS A,
                    COALESCE(SUM(UF_REWARD_BALANCE), 0) AS B,
                    COALESCE(SUM(UF_EARNED_DAY), 0) AS ED,
                    COALESCE(SUM(UF_MAX_DAY), 0) AS MD,
                    COALESCE(AVG(NULLIF(UF_EFFICIENCY_DAY, 0)), 0) AS AD
                FROM x5_pwa_uid_bindings
                WHERE {$whereSql}";

        try {
            $row = $conn->query($sql)->fetch();

            $earnedDay = (int)($row['ED'] ?? 0);
            $maxDay    = (int)($row['MD'] ?? 0);

            return [
                'total'       => (int)($row['C'] ?? 0),
                'earned_sum'  => (int)($row['E'] ?? 0),
                'max_sum'     => (int)($row['M'] ?? 0),
                'missing_sum' => (int)($row['MI'] ?? 0),
                'avg_eff'     => round((float)($row['A'] ?? 0), 2),
                'balance_sum' => (int)($row['B'] ?? 0),

                'earned_day_sum'  => $earnedDay,
                'max_day_sum'     => $maxDay,
                'missing_day_sum' => max(0, $maxDay - $earnedDay),
                'avg_eff_day'     => round((float)($row['AD'] ?? 0), 2),
            ];
        } catch (\Throwable $e) {
            Settings::logError(
                'getUsersAggregate failed: ' . $e->getMessage(),
                'StatsBuilder::getUsersAggregate'
            );
            return $empty;
        }
    }
    /**
     * Сводка по согласиям на ПД — для предупреждения в admin/users.php и admin/stats.php.
     *
     * Возвращает:
     *   [
     *     'granted'   => ['users' => N, 'balance' => N, 'users_with_balance' => N],
     *     'refused'   => ['users' => N, 'balance' => N, 'users_with_balance' => N],
     *     'not_asked' => ['users' => N, 'balance' => N, 'users_with_balance' => N],
     *     'other'     => ['users' => N, 'balance' => N, 'users_with_balance' => N],
     *     'total'     => ['users' => N, 'balance' => N, 'users_with_balance' => N],
     *     'dirty_balance' => N,  // refused + not_asked + other — сумма баланса
     *     'dirty_users'   => N,  // refused + not_asked + other — число пользователей
     *     'dirty_with_balance' => N,
     *   ]
     *
     * Использование в админке: показать предупреждение, если dirty_balance > 0
     * или dirty_with_balance > 0.
     */
    public static function getConsentStats(): array
    {
        $conn      = Application::getConnection();

        $sql = "SELECT
                    SUM(CASE WHEN UF_CONSENT_GRANTED = 'Y' THEN 1 ELSE 0 END)                                     AS granted_users,
                    SUM(CASE WHEN UF_CONSENT_GRANTED = 'N' THEN 1 ELSE 0 END)                                     AS refused_users,
                    SUM(CASE WHEN UF_CONSENT_GRANTED = '' OR UF_CONSENT_GRANTED IS NULL THEN 1 ELSE 0 END)        AS not_asked_users,
                    SUM(CASE WHEN UF_CONSENT_GRANTED NOT IN ('Y','N') AND UF_CONSENT_GRANTED != '' AND UF_CONSENT_GRANTED IS NOT NULL THEN 1 ELSE 0 END) AS other_users,

                    COALESCE(SUM(CASE WHEN UF_CONSENT_GRANTED = 'Y' THEN UF_REWARD_BALANCE ELSE 0 END), 0)        AS granted_balance,
                    COALESCE(SUM(CASE WHEN UF_CONSENT_GRANTED = 'N' THEN UF_REWARD_BALANCE ELSE 0 END), 0)        AS refused_balance,
                    COALESCE(SUM(CASE WHEN UF_CONSENT_GRANTED = '' OR UF_CONSENT_GRANTED IS NULL THEN UF_REWARD_BALANCE ELSE 0 END), 0) AS not_asked_balance,
                    COALESCE(SUM(CASE WHEN UF_CONSENT_GRANTED NOT IN ('Y','N') AND UF_CONSENT_GRANTED != '' AND UF_CONSENT_GRANTED IS NOT NULL THEN UF_REWARD_BALANCE ELSE 0 END), 0) AS other_balance,

                    SUM(CASE WHEN UF_CONSENT_GRANTED = 'Y' AND UF_REWARD_BALANCE > 0 THEN 1 ELSE 0 END)           AS granted_with_balance,
                    SUM(CASE WHEN UF_CONSENT_GRANTED = 'N' AND UF_REWARD_BALANCE > 0 THEN 1 ELSE 0 END)           AS refused_with_balance,
                    SUM(CASE WHEN (UF_CONSENT_GRANTED = '' OR UF_CONSENT_GRANTED IS NULL) AND UF_REWARD_BALANCE > 0 THEN 1 ELSE 0 END) AS not_asked_with_balance,

                    COUNT(*)                                                                                      AS total_users,
                    COALESCE(SUM(UF_REWARD_BALANCE), 0)                                                           AS total_balance,
                    SUM(CASE WHEN UF_REWARD_BALANCE > 0 THEN 1 ELSE 0 END)                                        AS total_with_balance
                FROM x5_pwa_uid_bindings";

        try {
            $row = $conn->query($sql)->fetch();
        } catch (\Throwable $e) {
            Settings::logError('getConsentStats failed: ' . $e->getMessage(), 'StatsBuilder::getConsentStats');
            return [
                'granted'   => ['users' => 0, 'balance' => 0, 'users_with_balance' => 0],
                'refused'   => ['users' => 0, 'balance' => 0, 'users_with_balance' => 0],
                'not_asked' => ['users' => 0, 'balance' => 0, 'users_with_balance' => 0],
                'other'     => ['users' => 0, 'balance' => 0, 'users_with_balance' => 0],
                'total'     => ['users' => 0, 'balance' => 0, 'users_with_balance' => 0],
                'dirty_balance' => 0, 'dirty_users' => 0, 'dirty_with_balance' => 0,
            ];
        }

        $granted = [
            'users'             => (int)($row['granted_users']   ?? 0),
            'balance'           => (int)($row['granted_balance'] ?? 0),
            'users_with_balance'=> (int)($row['granted_with_balance'] ?? 0),
        ];
        $refused = [
            'users'             => (int)($row['refused_users']   ?? 0),
            'balance'           => (int)($row['refused_balance'] ?? 0),
            'users_with_balance'=> (int)($row['refused_with_balance'] ?? 0),
        ];
        $notAsked = [
            'users'             => (int)($row['not_asked_users']   ?? 0),
            'balance'           => (int)($row['not_asked_balance'] ?? 0),
            'users_with_balance'=> (int)($row['not_asked_with_balance'] ?? 0),
        ];
        $other = [
            'users'             => (int)($row['other_users']   ?? 0),
            'balance'           => (int)($row['other_balance'] ?? 0),
            'users_with_balance'=> 0,
        ];
        $total = [
            'users'             => (int)($row['total_users']   ?? 0),
            'balance'           => (int)($row['total_balance'] ?? 0),
            'users_with_balance'=> (int)($row['total_with_balance'] ?? 0),
        ];

        return [
            'granted'   => $granted,
            'refused'   => $refused,
            'not_asked' => $notAsked,
            'other'     => $other,
            'total'     => $total,
            'dirty_balance'      => $refused['balance'] + $notAsked['balance'] + $other['balance'],
            'dirty_users'        => $refused['users']   + $notAsked['users']   + $other['users'],
            'dirty_with_balance' => $refused['users_with_balance'] + $notAsked['users_with_balance'],
        ];
    }
    // ───────── Старый buildUser ─────────

    public static function buildUser(string $uid): array
    {
        $reads     = Storage::getReads($uid);
        $taskKeys  = Storage::getTasks($uid);
        $habitData = Storage::getHabits($uid);

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

        $allHabits   = HabitsBuilder::getAll();
        $habitTitles = [];
        $byHabit     = [];
        foreach ($allHabits as $h) {
            $habitTitles[(string)$h['id']] = (string)$h['title'];
            $byHabit[(string)$h['id']]     = 0;
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
            $d->modify("-$i days");
            $date = $d->format('Y-m-d');
            $week[] = ['date' => $date, 'count' => $activeCounts[$date] ?? 0];
        }

        $themeTitles = [];
        foreach (ThemesBuilder::getAll() as $t) {
            $themeTitles[(string)$t['id']] = (string)$t['name'];
        }

        $stepsSummary = [];
        try { $stepsSummary = StepsBuilder::getSummary($uid); } catch (\Throwable $e) {}

        $consent       = Storage::getConsent($uid);
        $selectedHabit = Storage::getSelectedHabit($uid);
        $eventStats    = Storage::getEventStatsForUid($uid);

        $personal = Storage::getBindingForPersonal($uid);

        [$periodFrom, $periodTo] = self::getEffectivePeriod();
        $ps = Storage::getPeriodStatsForUid($uid, $periodFrom, $periodTo);

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

            'EVENTS_VISITED'   => (int)$eventStats['visited'],
            'EVENTS_CONFIRMED' => (int)$eventStats['confirmed'],

            'REWARDS_BALANCE'  => RewardsBuilder::getBalance($uid),

            'CONSENT'          => $consent['granted'],
            'CONSENT_AT'       => $consent['serverAt'],
            'CONSENT_LOCAL_DT' => $consent['localDt'],
            'HAS_CONSENT'      => $consent['hasConsent'],
            'SELECTED_HABIT'   => $selectedHabit,

            'RANK'             => (int)($personal['rank'] ?? 0),
            'EFFICIENCY_ALL'   => (float)($personal['eff_all'] ?? 0),
            'EFFICIENCY_DAY'   => (float)($personal['eff_day'] ?? 0),
            'EARNED_ALL'       => (int)($personal['earned_all'] ?? 0),
            'MAX_ALL'          => (int)($personal['max_all'] ?? 0),
            'MISSING_ALL'      => (int)($personal['missing_all'] ?? 0),
            'EARNED_DAY'       => (int)($personal['earned_day'] ?? 0),
            'MAX_DAY'          => (int)($personal['max_day'] ?? 0),
            'HABITS_DAYS'      => (int)($personal['habits_days'] ?? 0),

            'PERIOD_FROM'               => $periodFrom,
            'PERIOD_TO'                 => $periodTo,
            'LECTURES_READ_PERIOD'      => (int)$ps['lectures'],
            'TASKS_DONE_PERIOD'         => (int)$ps['tasks'],
            'HABITS_DONE_PERIOD'        => (int)$ps['habits'],
            'STEPS_CONFIRMED_PERIOD'    => (int)$ps['steps'],
            'EVENTS_VISITED_PERIOD'     => (int)$ps['events_visited'],
            'EVENTS_CONFIRMED_PERIOD'   => (int)$ps['events_confirmed'],
        ];
    }

    public static function resolveLectureBuckets(array $reads): array
    {
        $result = [];

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

        $found           = [];
        $themeByElement  = [];
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
}