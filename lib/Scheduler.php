<?php
namespace X5\Pwa;

class Scheduler
{
    private const FIRST_DELAY_HOURS = 2;
    private const NIGHT_START       = 22;
    private const NIGHT_END         = 8;
    private const DUMP_INTERVAL_MS  = 86400000;

    public static function lazyRun(): void
    {
        $lockFile = sys_get_temp_dir() . '/x5.pwa.scheduler.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) return;
        if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return; }

        $last = Settings::getInt('scheduler_last_run', 0);
        if ((time() * 1000 - $last) < 60_000) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }
        Settings::set('scheduler_last_run', (string)(time() * 1000));

        try {
            self::runHabitReminders();
            self::maybeRecalcStats();
            self::maybeRegenerateDumps();
            self::maybeRegenerateStats();
        } catch (\Throwable $e) {
            Settings::logError($e->getMessage(), 'Scheduler::lazyRun');
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Пересчёт кешей статистики в UidBindings:
     *   • UF_RANK — композитный рейтинг;
     *   • UF_EFFICIENCY_ALL/DAY — эффективность;
     *   • UF_HABITS_DAYS — число полных дней привычки;
     *   • UF_EARNED_ALL / UF_MAX_ALL / UF_MISSING_ALL / UF_EARNED_DAY / UF_MAX_DAY.
     *
     * Запускается не чаще stats_recalc_interval_sec (дефолт 3600 сек).
     */
    private static function maybeRecalcStats(): void
    {
        if (!Settings::statsRecalcDue()) return;

        try {
            $r = StatsBuilder::recalcAllStats();
            Settings::log(
                'recalcAllStats: processed=' . ($r['processed'] ?? 0)
                . ' elapsed=' . ($r['elapsed'] ?? '?') . 's',
                'Scheduler recalc'
            );
        } catch (\Throwable $e) {
            Settings::logError(
                'recalcAllStats FAILED: ' . $e->getMessage(),
                'Scheduler recalc'
            );
        }
    }

    private static function maybeRegenerateStats(): void
    {
        if (!Dumper::statsDue()) return;

        try {
            $r = Dumper::regenerateStats();
            if (!empty($r['ok'])) {
                Settings::log(
                    'updated, key=' . ($r['key'] ?? '?') . ' elapsed=' . ($r['elapsed'] ?? '?'),
                    'Scheduler stats'
                );
            } else {
                Settings::logError(
                    'FAILED: ' . ($r['error'] ?? 'unknown'),
                    'Scheduler stats'
                );
            }
        } catch (\Throwable $e) {
            Settings::logError($e->getMessage(), 'Scheduler stats exception');
        }
    }

    /**
     * Пересборка полных дампов.
     *
     * Обычно — раз в 24 часа. Но если админ изменил настройки дневных
     * лимитов (daily_*_limit), settings-слой ставит флаг limits_rules_dirty=1 —
     * тогда пересобираем вне очереди, чтобы config/limits_rules.json
     * на CDN обновился как можно скорее (в течение тика scheduler, ~60 сек).
     */
    private static function maybeRegenerateDumps(): void
    {
        $dirty    = Settings::limitsRulesDirty();
        $lastDump = Settings::lastDumpAt();

        if (!$dirty && $lastDump > 0
            && (time() * 1000 - $lastDump) < self::DUMP_INTERVAL_MS) {
            return;
        }

        try {
            Dumper::regenerateAll();
            // флаг сбрасывается внутри Dumper::regenerateAll
        } catch (\Throwable $e) {
            Settings::logError($e->getMessage(), 'Scheduler auto-dump');
        }
    }

    private static function logSkip(string $uid, string $habitId, string $reason, array $ctx = []): void
    {
        Settings::log(
            sprintf(
                'skip uid=%s habit=%s reason=%s ctx=%s',
                substr($uid, 0, 12) . '…',
                $habitId,
                $reason,
                json_encode($ctx, JSON_UNESCAPED_UNICODE)
            ),
            'Scheduler habit'
        );
    }

    /**
     * Напоминания о привычках, ориентируясь на локальное время пользователя.
     */
    private static function runHabitReminders(): void
    {
        $allHabits = HabitsBuilder::getAll();
        if (!$allHabits) return;

        $habitsById = [];
        foreach ($allHabits as $cfg) {
            $habitsById[(string)$cfg['id']] = $cfg;
        }

        $nowMs = time() * 1000;

        foreach (Storage::listSubscriptions() as $row) {
            $uid = (string)$row['UF_UID'];
            if ($uid === '') continue;

            if (Storage::isBlocked($uid)) continue;

            $sub = Storage::getSubscription($uid);
            if (!$sub) continue;

            $tzOffsetMin   = Storage::getUserTzOffsetMin($uid);
            $offsetMs      = $tzOffsetMin * 60 * 1000;
            $userLocalMs   = $nowMs + $offsetMs;
            $userLocalDate = gmdate('Y-m-d', (int)($userLocalMs / 1000));
            $userLocalTime = gmdate('H:i', (int)($userLocalMs / 1000));
            $userLocalHour = (int)gmdate('G', (int)($userLocalMs / 1000));

            if ($userLocalHour >= self::NIGHT_START || $userLocalHour < self::NIGHT_END) {
                continue;
            }

            $dayStartServerMs = (strtotime($userLocalDate . ' 00:00:00 UTC') * 1000) - $offsetMs;
            $firstAtServerMs  = $dayStartServerMs + self::FIRST_DELAY_HOURS * 3600 * 1000;
            if ($nowMs < $firstAtServerMs) continue;

            $userLogs = Storage::getHabits($uid);

            $selectedHabit    = Storage::getSelectedHabit($uid);
            $habitIdsToNotify = [];

            if ($selectedHabit !== null) {
                if (isset($habitsById[$selectedHabit])) {
                    $habitIdsToNotify[] = $selectedHabit;
                } else {
                    Settings::log(
                        'selected habit not found in catalog uid='
                        . substr($uid, 0, 12) . '… habit=' . $selectedHabit,
                        'Scheduler habit'
                    );
                }
            } else {
                foreach (array_keys($userLogs) as $hid) {
                    $hid = (string)$hid;
                    if (isset($habitsById[$hid])) {
                        $habitIdsToNotify[] = $hid;
                    }
                }
            }

            if (!$habitIdsToNotify) continue;

            foreach ($habitIdsToNotify as $habitId) {
                $cfg = $habitsById[$habitId];
                $log = $userLogs[$habitId] ?? [];

                $completionsToday      = 0;
                $lastCompletedServerMs = 0;

                foreach ($log as $dt) {
                    $completedServerMs = self::toServerMs($dt);
                    if ($completedServerMs <= 0) continue;

                    if ($completedServerMs > $lastCompletedServerMs) {
                        $lastCompletedServerMs = $completedServerMs;
                    }

                    $completedLocalMs   = $completedServerMs + $offsetMs;
                    $completedLocalDate = gmdate('Y-m-d', (int)($completedLocalMs / 1000));
                    if ($completedLocalDate === $userLocalDate) {
                        $completionsToday++;
                    }
                }

                if ($completionsToday >= (int)$cfg['timesPerDay']) {
                    self::logSkip($uid, $habitId, 'done_today', [
                        'completionsToday' => $completionsToday,
                        'timesPerDay'      => (int)$cfg['timesPerDay'],
                    ]);
                    continue;
                }

                $baseline = $lastCompletedServerMs ?: $firstAtServerMs;
                $minMs    = ((int)$cfg['minIntervalMin']) * 60 * 1000;
                if ($minMs > 0 && ($nowMs - $baseline) < $minMs) {
                    self::logSkip($uid, $habitId, 'min_interval_min', [
                        'baseline' => gmdate('c', (int)($baseline / 1000)),
                        'elapsed'  => (int)(($nowMs - $baseline) / 60000) . 'min',
                        'required' => $minMs / 60000 . 'min',
                    ]);
                    continue;
                }

                $notifyFromTime = (string)($cfg['notifyFromTime'] ?? '');
                if ($notifyFromTime !== '' && $userLocalTime < $notifyFromTime) {
                    self::logSkip($uid, $habitId, 'notify_from_time', [
                        'local_time'  => $userLocalTime,
                        'notify_from' => $notifyFromTime,
                    ]);
                    continue;
                }

                $notif = Storage::getNotifState($uid, $habitId, $userLocalDate);

                if ($notif['todayCount'] >= (int)$cfg['timesPerDayHint']) {
                    self::logSkip($uid, $habitId, 'times_per_day_hint', [
                        'todayCount' => $notif['todayCount'],
                        'limit'      => (int)$cfg['timesPerDayHint'],
                    ]);
                    continue;
                }

                $hintMs = ((int)$cfg['minIntervalHint']) * 60 * 1000;
                if ($hintMs > 0 && $notif['lastAt'] > 0
                    && ($nowMs - $notif['lastAt']) < $hintMs) {
                    self::logSkip($uid, $habitId, 'min_interval_hint', [
                        'lastNotifiedAt' => gmdate('c', (int)($notif['lastAt'] / 1000)),
                        'elapsed'        => (int)(($nowMs - $notif['lastAt']) / 60000) . 'min',
                        'required'       => $hintMs / 60000 . 'min',
                    ]);
                    continue;
                }

                $payload = Push::buildHabitPayload($habitId, $cfg);
                $r = Push::send($uid, $sub, $payload);

                if ($r['ok']) {
                    Storage::setNotified($uid, $habitId, $userLocalDate);
                    Storage::addPushHistory([
                        'type'   => 'habit-auto',
                        'target' => $uid,
                        'title'  => $cfg['title'],
                        'body'   => $cfg['hint'],
                        'url'    => '/',
                        'status' => (string)$r['statusCode'],
                    ]);
                    Settings::log(
                        sprintf('sent uid=%s habit=%s doneToday=%d/%d',
                            substr($uid, 0, 12) . '…', $habitId,
                            $completionsToday, (int)$cfg['timesPerDay']
                        ),
                        'Scheduler habit'
                    );
                } elseif ($r['gone']) {
                    Storage::deleteSubscription($uid);
                    break;
                }
            }
        }
    }

    private static function toServerMs($dt): int
    {
        if ($dt instanceof \Bitrix\Main\Type\DateTime
            || $dt instanceof \DateTimeInterface) {
            return $dt->getTimestamp() * 1000;
        }
        $s = trim((string)$dt);
        if ($s === '') return 0;
        $ts = strtotime($s);
        return $ts > 0 ? $ts * 1000 : 0;
    }
}