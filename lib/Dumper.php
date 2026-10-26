<?php
namespace X5\Pwa;

class Dumper
{
    private const STATS_INTERVAL_DEFAULT = 600;
    private const STATS_INTERVAL_MIN     = 60;

    private const REGEN_THROTTLE_SEC     = 30;

    private static bool $inProgress = false;

    private static function log(string $msg, string $label = 'Dumper debug'): void
    {
        Settings::log('[' . date('H:i:s') . '] ' . $msg, $label);
    }

    private static function logError(string $msg, string $label = 'Dumper error'): void
    {
        Settings::logError('[' . date('H:i:s') . '] ' . $msg, $label);
    }

    private static function ourIblockIds(): array
    {
        $keys = ['general_lectures', 'charging', 'theme_lectures', 'tasks',
                 'themes', 'quiz_questions', 'habits', 'today_widgets',
                 'tutorial', 'reflections', 'events', 'workplaces'];
        $out = [];
        foreach ($keys as $k) {
            $id = Settings::iblockId($k);
            if ($id) $out[$id] = $k;
        }
        return $out;
    }

    public static function isOurIblock(int $iblockId): bool
    {
        return isset(self::ourIblockIds()[$iblockId]);
    }

    public static function onElementSave(array $arFields): void
    {
        $iblockId = (int)($arFields['IBLOCK_ID'] ?? 0);
        self::log("onElementSave iblockId=$iblockId elementId=" . ($arFields['ID'] ?? '?'));

        if (!$iblockId || !self::isOurIblock($iblockId)) {
            self::log("  → not our iblock, skip");
            return;
        }

        if (self::$inProgress) {
            self::log("  → skipped: regenerateAll already in progress");
            return;
        }

        $lastRunAgo = microtime(true) - (float)Settings::get('last_regen_started_at', '0');
        if ($lastRunAgo < self::REGEN_THROTTLE_SEC) {
            self::log("  → throttled: last regen " . round($lastRunAgo, 1) . "s ago");
            Settings::set('regen_dirty', '1');
            return;
        }

        Settings::set('last_regen_started_at', (string)microtime(true));
        self::$inProgress = true;
        try {
            self::regenerateAll();
            Settings::set('regen_dirty', '0');
        } catch (\Throwable $e) {
            Settings::set('regen_dirty', '1');
            self::logError('  → REGEN FAILED: ' . $e->getMessage(), 'Dumper::onElementSave');
        } finally {
            self::$inProgress = false;
        }
    }

    public static function onElementDelete(array $arFields): void
    {
        self::onElementSave($arFields);
    }

    public static function statsInterval(): int
    {
        $v = Settings::getInt('stats_interval_sec', self::STATS_INTERVAL_DEFAULT);
        if ($v < self::STATS_INTERVAL_MIN) $v = self::STATS_INTERVAL_MIN;
        return $v;
    }

    public static function statsDue(): bool
    {
        $last = Settings::getInt('last_stats_at', 0);
        if ($last <= 0) return true;
        return (time() * 1000 - $last) >= (self::statsInterval() * 1000);
    }

    public static function regenerateStats(): array
    {
        $startTime = microtime(true);
        self::log('----- regenerateStats START -----');

        try {
            $globalStats = StatsBuilder::buildGlobal();
        } catch (\Throwable $e) {
            self::logError('StatsBuilder FAILED: ' . $e->getMessage(), 'Dumper::regenerateStats');
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $json = (string)json_encode($globalStats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $r    = S3::upload('stats/global.json', $json);

        Settings::set('last_stats_at', (string)(time() * 1000));

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($r['ok']) {
            self::log("----- regenerateStats END ok elapsed={$elapsed}s -----");
            return ['ok' => true, 'key' => $r['key'], 'elapsed' => $elapsed];
        }

        self::logError("----- regenerateStats END FAIL: {$r['message']} -----");
        return ['ok' => false, 'error' => $r['message'], 'elapsed' => $elapsed];
    }

    public static function regenerateStepLeaderboards(bool $includeAllDaily = true): array
    {
        $startTime = microtime(true);
        $today = date('Y-m-d');
        $map = [];

        $map['steps/leaderboard.json'] = StepsBuilder::buildLeaderboardForDate(null);
        $map["steps/leaderboard/{$today}.json"] = StepsBuilder::buildLeaderboardForDate($today);

        if ($includeAllDaily) {
            for ($i = 1; $i <= 14; $i++) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $map["steps/leaderboard/{$d}.json"] = StepsBuilder::buildLeaderboardForDate($d);
            }
        }

        $map['steps/school.json'] = StepsBuilder::buildSchoolProgress();

        $sent = 0; $failed = 0; $errors = [];
        foreach ($map as $key => $data) {
            $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $r = S3::upload($key, $json);
            if ($r['ok']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $key . ': ' . ($r['message'] ?? '?');
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        self::log("regenerateStepLeaderboards sent=$sent failed=$failed elapsed={$elapsed}s");

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors, 'elapsed' => $elapsed];
    }

    public static function regenerateAll(): array
    {
        $startTime = microtime(true);
        self::log('===== regenerateAll START =====');

        ThemesResolver::resetCache();
        HabitsBuilder::resetCache();
        ReflectionBuilder::resetCache();
        WorkplacesBuilder::resetCache();

        $ibIds = [
            'general_lectures' => Settings::iblockId('general_lectures'),
            'charging'         => Settings::iblockId('charging'),
            'theme_lectures'   => Settings::iblockId('theme_lectures'),
            'tasks'            => Settings::iblockId('tasks'),
            'themes'           => Settings::iblockId('themes'),
            'quiz_questions'   => Settings::iblockId('quiz_questions'),
            'habits'           => Settings::iblockId('habits'),
            'today_widgets'    => Settings::iblockId('today_widgets'),
            'tutorial'         => Settings::iblockId('tutorial'),
            'reflections'      => Settings::iblockId('reflections'),
            'events'           => Settings::iblockId('events'),
            'workplaces'       => Settings::iblockId('workplaces'),
        ];
        self::log('IB IDs: ' . json_encode($ibIds));

        // ─── Период активности для дампов ───
        // Берём сырые настройки (без обрезки до today), чтобы:
        //   • файлы для будущих дат внутри периода тоже генерировались (пустые);
        //   • даты вне периода НЕ дампились (клиент не должен видеть контент
        //     вне активного периода).
        // Fallback: если одна из границ не задана — подставим эффективную.
        $rawFrom = Settings::statsPeriodStart();
        $rawTo   = Settings::statsPeriodEnd();
        $hasPeriod = ($rawFrom !== '' || $rawTo !== '');

        if ($hasPeriod) {
            [$effFrom, $effTo] = StatsBuilder::getEffectivePeriod();
            if ($rawFrom === '') $rawFrom = $effFrom;
            if ($rawTo   === '') $rawTo   = $effTo;
        }

        self::log(sprintf(
            'PERIOD for dumps: from=%s to=%s (%s)',
            $rawFrom !== '' ? $rawFrom : '—',
            $rawTo   !== '' ? $rawTo   : '—',
            $hasPeriod ? 'active' : 'none (dump all)'
        ));

        $isInPeriod = function (string $date) use ($rawFrom, $rawTo, $hasPeriod): bool {
            if (!$hasPeriod) return true;
            if ($rawFrom !== '' && $date < $rawFrom) return false;
            if ($rawTo   !== '' && $date > $rawTo)   return false;
            return true;
        };

        $today         = date('Y-m-d');
        $todayInPeriod = $isInPeriod($today);

        $raw = ContentBuilder::rawAll();

        foreach ($raw as $bucket => $items) {
            self::log(sprintf('RAW %s: count=%d', $bucket, count($items)));
        }

        $themes = ThemesResolver::getAllCodes();
        if (!$themes && !ThemesResolver::isConfigured()) {
            $themes = ['energy', 'antistress', 'body', 'balance'];
        }
        self::log("TODAY = $today, themes=" . implode(',', $themes)
            . ($hasPeriod ? ", today_in_period=" . ($todayInPeriod ? 'Y' : 'N') : ''));

        // Группировка лекций — с фильтром по периоду
        $groupedLectures = [];
        foreach ($raw['general_lectures'] as $it) {
            $d = $it['DATE_ACTIVE'] ?? null;
            if (!$d) continue;
            if (!$isInPeriod((string)$d)) continue;   // ← фильтр по периоду
            $groupedLectures['general'][$d][] = $it;
        }
        foreach ($raw['charging'] as $it) {
            $d = $it['DATE_ACTIVE'] ?? null;
            if (!$d) continue;
            if (!$isInPeriod((string)$d)) continue;   // ← фильтр по периоду
            $groupedLectures['charging'][$d][] = $it;
        }
        foreach ($raw['theme_lectures'] as $it) {
            $d = $it['DATE_ACTIVE'] ?? null;
            if (!$d) continue;
            if (empty($it['THEME'])) continue;
            if (!$isInPeriod((string)$d)) continue;   // ← фильтр по периоду
            $groupedLectures[$it['THEME']][$d][] = $it;
        }

        // Группировка заданий — с фильтром по периоду
        $groupedTasks = [];
        foreach ($raw['tasks'] as $it) {
            $d = $it['DATE_ACTIVE'] ?? null;
            if (!$d) continue;
            if (empty($it['THEME'])) continue;
            if (!$isInPeriod((string)$d)) continue;   // ← фильтр по периоду
            $groupedTasks[$it['THEME']][$d][] = $it;
        }

        $extraThemesLectures = [];
        foreach (array_keys($groupedLectures) as $themeKey) {
            if (in_array($themeKey, $themes, true)) continue;
            if ($themeKey === 'general' || $themeKey === 'charging') continue;
            $extraThemesLectures[] = $themeKey;
        }
        $extraThemesTasks = [];
        foreach (array_keys($groupedTasks) as $themeKey) {
            if (in_array($themeKey, $themes, true)) continue;
            $extraThemesTasks[] = $themeKey;
        }

        $map = [];
        $byCategory = [
            'lectures' => 0, 'tasks' => 0, 'stats' => 0,
            'config'   => 0, 'steps' => 0, 'events' => 0, 'empty' => 0,
        ];

        // general / charging: даты из ИБ уже отфильтрованы,
        // today добавляем только если он в периоде.
        foreach (['general', 'charging'] as $bucket) {
            $dates = array_keys($groupedLectures[$bucket] ?? []);
            if ($todayInPeriod && !in_array($today, $dates, true)) {
                $dates[] = $today;
            }
            foreach ($dates as $d) {
                $items = $groupedLectures[$bucket][$d] ?? [];
                $map["lectures/$bucket/$d.json"] = self::prepareLectures($items, $d);
                $byCategory['lectures']++;
                if (empty($items)) $byCategory['empty']++;
            }
        }

        // темы лекций: dates уже отфильтрованы по периоду
        $allThemesToDump = array_values(array_unique(array_merge($themes, $extraThemesLectures)));
        foreach ($allThemesToDump as $theme) {
            $dates = array_keys($groupedLectures[$theme] ?? []);
            if ($todayInPeriod && in_array($theme, $themes, true)
                && !in_array($today, $dates, true)) {
                $dates[] = $today;
            }
            foreach ($dates as $d) {
                $items = $groupedLectures[$theme][$d] ?? [];
                $map["lectures/$theme/$d.json"] = self::prepareLectures($items, $d);
                $byCategory['lectures']++;
            }
        }

        // темы заданий: dates уже отфильтрованы
        $allThemesTasks = array_values(array_unique(array_merge($themes, $extraThemesTasks)));
        foreach ($allThemesTasks as $theme) {
            $dates = array_keys($groupedTasks[$theme] ?? []);
            if ($todayInPeriod && in_array($theme, $themes, true)
                && !in_array($today, $dates, true)) {
                $dates[] = $today;
            }
            foreach ($dates as $d) {
                $items = $groupedTasks[$theme][$d] ?? [];
                $map["tasks/$theme/$d.json"] = self::prepareTasks($items, $d);
                $byCategory['tasks']++;
                if (empty($items)) $byCategory['empty']++;
            }
        }

        try {
            $map['config/themes.json']     = ThemesBuilder::getAll();
            $map['config/habits.json']     = HabitsBuilder::getAll();
            $map['config/reflection.json'] = ReflectionBuilder::getAllSettings();
            $map['config/quiz_theme.json'] = [
                'QUESTIONS' => QuizBuilder::getQuestions(),
            ];
            $map['config/tutorial.json'] = [
                'SLIDES' => TutorialBuilder::getSlides(),
            ];
            $map['config/workplaces.json'] = WorkplacesBuilder::getForCdn();

            // config/period.json — эффективный период (START..END) +
            // запланированный (PLANNED_START..PLANNED_END) + TODAY.
            [$periodFrom, $periodTo] = StatsBuilder::getEffectivePeriod();
            $plannedStart = Settings::statsPeriodStart();
            $plannedEnd   = Settings::statsPeriodEnd();

            $map['config/period.json'] = [
                'START'         => $periodFrom,
                'END'           => $periodTo,
                'PLANNED_START' => $plannedStart !== '' ? $plannedStart : $periodFrom,
                'PLANNED_END'   => $plannedEnd   !== '' ? $plannedEnd   : $periodTo,
                'TODAY'         => $today,
            ];

            // config/limits_rules.json — правила дневных лимитов
            $map['config/limits_rules.json'] = [
                'UPDATED_AT' => (new \Bitrix\Main\Type\DateTime())->toString(),
                'LIMITS'     => [
                    'lecture_theme'   => ['limit' => Settings::dailyThemeLectureLimit()],
                    'lecture_general' => ['limit' => Settings::dailyGeneralLectureLimit()],
                    'charging'        => ['limit' => Settings::dailyChargingLimit()],
                    'task'            => ['limit' => Settings::dailyTaskLimit()],
                ],
            ];
            $byCategory['config'] = 8;
        } catch (\Throwable $e) {
            self::logError('config/* FAILED: ' . $e->getMessage(), 'Dumper config');
        }

        // События — параметризуем периодом
        try {
            $eventDates = EventsBuilder::getCdnDates(
                $hasPeriod ? $rawFrom : null,
                $hasPeriod ? $rawTo   : null
            );
            foreach ($eventDates as $eventDate) {
                $key = "events/{$eventDate}.json";
                $map[$key] = EventsBuilder::getForCdn($eventDate);
                $byCategory['events']++;
                if (empty($map[$key])) $byCategory['empty']++;
            }
            self::log(sprintf(
                'events: dates=%d (from %s to %s)',
                count($eventDates),
                $eventDates ? $eventDates[0] : '-',
                $eventDates ? $eventDates[count($eventDates) - 1] : '-'
            ));
        } catch (\Throwable $e) {
            self::logError('events/* FAILED: ' . $e->getMessage(), 'Dumper events');
        }

        try {
            $map['stats/global.json'] = StatsBuilder::buildGlobal();
            $byCategory['stats'] = 1;
        } catch (\Throwable $e) {
            self::logError('stats/global.json FAILED: ' . $e->getMessage(), 'Dumper stats');
        }

        $sent = 0; $failed = 0; $errors = [];
        $manifest = [
            'updated_at'   => (new \Bitrix\Main\Type\DateTime())->toString(),
            'generated_at' => $today,
            'period'       => [
                'FROM'          => $hasPeriod ? $rawFrom : '',
                'TO'            => $hasPeriod ? $rawTo   : '',
                'PLANNED_FROM'  => Settings::statsPeriodStart(),
                'PLANNED_TO'    => Settings::statsPeriodEnd(),
            ],
            'categories'   => $byCategory,
            'files'        => [],
        ];

        $cfg    = Settings::s3();
        $prefix = trim((string)($cfg['prefix'] ?? ''), '/');

        foreach ($map as $key => $data) {
            $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $r    = S3::upload($key, $json);
            if ($r['ok']) {
                $sent++;
                $manifest['files'][$key] = strlen($json);
            } else {
                $failed++;
                $errors[] = $key . ': ' . $r['message'];
                self::logError("UPLOAD FAIL $key: {$r['message']}");

                $localPath = S3::localCachePath(($prefix ? $prefix . '/' : '') . $key);
                @mkdir(dirname($localPath), 0775, true);
                @file_put_contents($localPath, $json);
            }
        }

        S3::upload(
            'manifest.json',
            (string)json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        try {
            $stepRes = self::regenerateStepLeaderboards(true);
            $sent   += (int)($stepRes['sent']   ?? 0);
            $failed += (int)($stepRes['failed'] ?? 0);
            if (!empty($stepRes['errors'])) {
                $errors = array_merge($errors, $stepRes['errors']);
            }
            $byCategory['steps'] = (int)(($stepRes['sent'] ?? 0) + ($stepRes['failed'] ?? 0));
        } catch (\Throwable $e) {
            self::logError('step leaderboards FAILED: ' . $e->getMessage(), 'Dumper steps');
        }

        Settings::touchDumpTime();
        Settings::set('last_stats_at', (string)(time() * 1000));
        Settings::clearLimitsRulesDirty();

        $elapsed = round(microtime(true) - $startTime, 2);
        self::log("===== regenerateAll END sent=$sent failed=$failed elapsed={$elapsed}s =====");

        return [
            'sent'        => $sent,
            'failed'      => $failed,
            'errors'      => $errors,
            'total'       => count($map) + $byCategory['steps'],
            'by_category' => $byCategory,
            'dates'       => ['generated_at' => $today],
            'elapsed'     => $elapsed,
        ];
    }

    private static function prepareLectures(array $list, string $date): array
    {
        $out = [];
        foreach ($list as $l) {
            $out[] = [
                'ID_LECTURE'    => ($l['ID'] ?? 'x') . '-' . $date,
                'TITLE'         => ($l['NAME'] ?? ''),
                'DESCRIPTION'   => $l['DESCRIPTION'] ?? '',
                'COVER'         => $l['COVER'] ?? null,
                'TEXT'          => $l['TEXT'] ?? '',
                'VIDEO_LINK'    => $l['VIDEO_LINK'] ?? null,
                'PHOTOS'        => $l['PHOTOS'] ?? [],
                'REWARD_POINTS' => (int)($l['REWARD_POINTS'] ?? 0),
                'DATE'          => $date,
            ];
        }
        return $out;
    }

    private static function prepareTasks(array $list, string $date): array
    {
        $out = [];
        foreach ($list as $t) {
            $out[] = [
                'ID_TASK'       => ($t['ID'] ?? 'x') . '-' . $date,
                'DATE'          => $date,
                'TITLE'         => $t['NAME'] ?? '',
                'DESCRIPTION'   => $t['DESCRIPTION'] ?? '',
                'TEXT'          => $t['TEXT'] ?? '',
                'COVER'         => $t['COVER'] ?? null,
                'VIDEO_LINK'    => $t['VIDEO_LINK'] ?? null,
                'REWARD_POINTS' => (int)($t['REWARD_POINTS'] ?? 0),
            ];
        }
        return $out;
    }
}