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

    /**
     * Считает максимум яблок на дату в разрезе BASE + THEMES.
     *
     * BASE = charging + general_lectures + events + reflection + steps
     * THEMES[code] = theme_lectures(THEME=code) + tasks(THEME=code)
     */
    public static function calculateDailyApples(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['BASE' => 0, 'THEMES' => []];
        }

        $themes = ThemesResolver::getAllCodes();
        if (!$themes) $themes = ['energy', 'antistress', 'body', 'balance'];

        $themeApples = [];
        foreach ($themes as $code) {
            $themeApples[$code] = 0;
        }

        $base = 0;

        // charging
        $ibCharging = (int)Settings::iblockId('charging');
        if ($ibCharging > 0) {
            $base += self::sumRewardPointsForDate($ibCharging, $date);
        }

        // general_lectures
        $ibGeneral = (int)Settings::iblockId('general_lectures');
        if ($ibGeneral > 0) {
            $base += self::sumRewardPointsForDate($ibGeneral, $date);
        }

        // events
        $ibEvents = (int)Settings::iblockId('events');
        if ($ibEvents > 0) {
            $base += self::sumRewardPointsForDate($ibEvents, $date);
        }

        // reflection
        $reflPoints = 0;
        try {
            $setting = ReflectionBuilder::getForDate($date);
            $reflPoints = (int)($setting['rewardPoints'] ?? 0);
        } catch (\Throwable $e) {}
        if ($reflPoints <= 0) $reflPoints = Settings::rewardReflectionPoints();
        $base += $reflPoints;

        // steps
        $base += Settings::rewardStepsSentPoints();
        $base += Settings::rewardStepsApprovedPoints();

        // theme_lectures
        $ibTheme = (int)Settings::iblockId('theme_lectures');
        if ($ibTheme > 0) {
            self::sumRewardPointsForDateByTheme($ibTheme, $date, $themeApples);
        }

        // tasks
        $ibTasks = (int)Settings::iblockId('tasks');
        if ($ibTasks > 0) {
            self::sumRewardPointsForDateByTheme($ibTasks, $date, $themeApples);
        }

        return [
            'BASE'   => max(0, $base),
            'THEMES' => $themeApples,
        ];
    }

    /**
     * Сумма REWARD_POINTS всех активных элементов ИБ за указанную дату.
     *
     * ВАЖНО: фильтр по дате делается через DATE_ACTIVE_FROM (виртуальное поле
     * «дата начала активности без времени»), а НЕ через ACTIVE_FROM.
     * Формат значения — формат даты сайта (SHORT), его даёт ConvertTimeStamp.
     *
     * Проверено диагностикой: ACTIVE_FROM с ISO/сайт-форматом не фильтрует;
     * DATE_ACTIVE_FROM с ISO-форматом тоже не фильтрует; работает только
     * DATE_ACTIVE_FROM + формат сайта.
     */
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

    /**
     * Группирует REWARD_POINTS по THEME (для theme_lectures и tasks).
     * THEME — свойство типа E (привязка к элементу ИБ «Направления»).
     *
     * @param array<string,int> $themeApples — модифицируется на месте
     */
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

            // THEME — ID элемента ИБ «Направления»
            $themeValue = '';
            $props = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'THEME']);
            if ($p = $props->Fetch()) {
                $themeValue = (string)($p['VALUE'] ?? '');
            }
            if ($themeValue === '') continue;

            $themeCode = ThemesResolver::resolve($themeValue);
            if ($themeCode === null || $themeCode === '') continue;

            // REWARD_POINTS
            $points = 0;
            $props2 = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'REWARD_POINTS']);
            if ($p2 = $props2->Fetch()) {
                $points = max(0, (int)($p2['VALUE'] ?? 0));
            }

            if (!isset($themeApples[$themeCode])) $themeApples[$themeCode] = 0;
            $themeApples[$themeCode] += $points;
        }
    }

    /**
     * Максимум для конкретного пользователя на дату.
     */
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
                return (int)($h['rewardPoints'] ?? 0);
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

        $wpMap = WorkplacesBuilder::getMap();
        $wpAgg = [];
        foreach ($wpMap as $code => $_) {
            $wpAgg[$code] = ['users' => 0, 'apples' => 0, 'earned' => 0, 'max' => 0];
        }

        $uids     = Storage::getActiveUidsForStats();
        $bindings = Storage::getBindingsForStats($uids);

        $totalApples     = 0;
        $totalUsers      = 0;
        $lecturesRead    = 0;
        $tasksDone       = 0;
        $habitsDaysTotal = 0;
        $stepsConfirmed  = 0;

        $applesCache = [];

        $habitPointsCache = [];
        foreach (HabitsBuilder::getAll() as $h) {
            $habitPointsCache[(string)$h['id']] = (int)($h['rewardPoints'] ?? 0);
        }

        foreach ($bindings as $uid => $b) {
            if ($b['blocked']) continue;
            if (!$b['consent']) continue;

            $totalUsers++;
            $totalApples += (int)$b['reward_balance'];

            [$userFrom, $userTo] = self::getUserPeriod($b['created_ts'], $from, $to);

            $wp = $b['workplace'];

            if ($wp !== '' && isset($wpAgg[$wp])) {
                $wpAgg[$wp]['users']++;
                $wpAgg[$wp]['apples'] += (int)$b['reward_balance'];
            }

            $themeCode   = (string)($b['theme'] ?? '');
            $habitId     = (string)($b['habit_id'] ?? '');
            $habitPoints = $habitPointsCache[$habitId] ?? 0;

            $earned   = Storage::getEarnedBetween($uid, $userFrom, $userTo);
            $maxTotal = self::sumDailyMaxForUser($userFrom, $userTo, $themeCode, $habitPoints, $applesCache);

            if ($wp !== '' && isset($wpAgg[$wp])) {
                $wpAgg[$wp]['earned'] += $earned;
                $wpAgg[$wp]['max']    += $maxTotal;
            }

            try {
                $reads = Storage::getReads($uid);
                $tasks = Storage::getTasks($uid);
                $lecturesRead += count($reads);
                $tasksDone    += count($tasks);

                $habitData = Storage::getHabits($uid);
                foreach ($habitData as $list) {
                    if (is_array($list)) $habitsDaysTotal += count($list);
                }
            } catch (\Throwable $e) {}

            $stepsConfirmed += (int)$b['total_steps'];
        }

        $list = [];
        foreach ($wpAgg as $code => $agg) {
            if ($agg['users'] <= 0) continue;

            $percent = 0.0;
            if ($agg['max'] > 0) {
                $percent = round($agg['earned'] / $agg['max'] * 100, 2);
            }

            $wp = $wpMap[$code] ?? ['id' => $code, 'name' => $code, 'logo' => ''];

            $list[] = [
                'ID'           => $code,
                'NAME'         => (string)$wp['name'],
                'PERCENT'      => $percent,
                'LOGO'         => (string)$wp['logo'],
                'TOTAL_APPLES' => (int)$agg['apples'],
            ];
        }
        usort($list, fn($a, $b) => $b['PERCENT'] <=> $a['PERCENT']);

        return [
            'RESULT'          => 'OK',
            'GLOBAL_PROGRESS' => [
                'TOTAL_APPLES'    => $totalApples,
                'TOTAL_USERS'     => $totalUsers,
                'LECTURES_READ'   => $lecturesRead,
                'TASKS_DONE'      => $tasksDone,
                'HABITS_DAYS'     => $habitsDaysTotal,
                'STEPS_CONFIRMED' => $stepsConfirmed,
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
                   AND (UF_BLOCKED IS NULL OR UF_BLOCKED = 'N')"
            )->fetchAll();
        } catch (\Throwable $e) {
            return 0.0;
        }

        if (!$rows) return 0.0;

        $habitPointsCache = [];
        foreach (HabitsBuilder::getAll() as $h) {
            $habitPointsCache[(string)$h['id']] = (int)($h['rewardPoints'] ?? 0);
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
            $habitPointsCache[(string)$h['id']] = (int)($h['rewardPoints'] ?? 0);
        }

        $computed = [];

        foreach ($bindings as $uid => $b) {
            if ($b['blocked'] || !$b['consent']) continue;

            [$userFrom, $userTo] = self::getUserPeriod($b['created_ts'], $from, $to);

            $themeCode   = (string)($b['theme'] ?? '');
            $habitId     = (string)($b['habit_id'] ?? '');
            $habitPoints = $habitPointsCache[$habitId] ?? 0;

            $earnedAll   = Storage::getEarnedBetween($uid, $userFrom, $userTo);
            $earnedToday = Storage::getEarnedOnDate($uid, $today);

            $maxTotal = self::sumDailyMaxForUser($userFrom, $userTo, $themeCode, $habitPoints, $applesCache);

            $todayApples = $applesCache[$today] ?? self::getDailyApples($today, $applesCache);
            $todayMax    = (int)($todayApples['BASE'] ?? 0);
            if ($themeCode !== '' && isset($todayApples['THEMES'][$themeCode])) {
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
            ];
        }

        Storage::bulkUpdateStatsCache($updates);
        Settings::touchStatsRecalcTime();

        $elapsed = round(microtime(true) - $startTime, 2);

        \Bitrix\Main\Diag\Debug::writeToFile(
            "recalcAllStats: processed=" . count($updates)
            . " period={$from}..{$to} elapsed={$elapsed}s",
            'StatsBuilder::recalcAllStats',
            '/x5.pwa.log'
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

        $uids     = array_column($rows, 'UF_UID');
        $stats    = Storage::getStatsForUids($uids);
        $subs     = Storage::getSubscriptionsForUids($uids);
        $activity = Storage::getLastActivityForUids($uids);

        $out = [];
        foreach ($rows as $b) {
            $uid = (string)$b['UF_UID'];
            $s   = $stats[$uid] ?? ['lectures' => 0, 'tasks' => 0, 'habits' => 0];

            $lastSeen = $b['UF_LAST_SEEN_AT']
                ? $b['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0;

            $lastActive = $lastSeen;
            if (isset($activity[$uid]) && $activity[$uid] > $lastActive) {
                $lastActive = $activity[$uid];
            }

            $blocked   = ($b['UF_BLOCKED'] ?? 'N') === 'Y';
            $blockedAt = !empty($b['UF_BLOCKED_AT'])
                ? $b['UF_BLOCKED_AT']->getTimestamp() * 1000 : 0;

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

    // ───────── Старый buildUser (для user_detail.php) ─────────

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