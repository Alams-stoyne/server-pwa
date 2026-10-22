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
            self::maybeRegenerateDumps();
            self::maybeRegenerateStats();
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'Scheduler::lazyRun', '/x5.pwa.log'
            );
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function maybeRegenerateStats(): void
    {
        if (!Dumper::statsDue()) return;

        try {
            $r = Dumper::regenerateStats();
            if (!empty($r['ok'])) {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'updated, key=' . ($r['key'] ?? '?') . ' elapsed=' . ($r['elapsed'] ?? '?'),
                    'Scheduler stats',
                    '/x5.pwa.log'
                );
            } else {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'FAILED: ' . ($r['error'] ?? 'unknown'),
                    'Scheduler stats',
                    '/x5.pwa.log'
                );
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'Scheduler stats exception', '/x5.pwa.log'
            );
        }
    }

    private static function maybeRegenerateDumps(): void
    {
        $lastDump = Settings::lastDumpAt();
        if ($lastDump > 0 && (time() * 1000 - $lastDump) < self::DUMP_INTERVAL_MS) {
            return;
        }
        try {
            Dumper::regenerateAll();
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'Scheduler auto-dump', '/x5.pwa.log'
            );
        }
    }

    private static function logSkip(string $uid, string $habitId, string $reason, array $ctx = []): void
    {
        \Bitrix\Main\Diag\Debug::writeToFile(
            sprintf(
                'skip uid=%s habit=%s reason=%s ctx=%s',
                substr($uid, 0, 12) . '…',
                $habitId,
                $reason,
                json_encode($ctx, JSON_UNESCAPED_UNICODE)
            ),
            'Scheduler habit',
            '/x5.pwa.log'
        );
    }

    /**
     * Напоминания о привычках, ориентируясь на локальное время пользователя.
     *
     * Ключевое отличие от предыдущей версии:
     *   • напоминаем ТОЛЬКО по выбранной привычке (UF_HABIT_ID);
     *   • если привычка ещё не выбрана — только по тем, что пользователь
     *     хотя бы раз выполнял (а не по всему каталогу);
     *   • корректный парсинг UF_COMPLETED_AT через getTimestamp().
     */
    private static function runHabitReminders(): void
    {
        $allHabits = HabitsBuilder::getAll();
        if (!$allHabits) return;

        // Индекс: id привычки → её конфиг
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

            // Ночь — по локальному времени пользователя
            if ($userLocalHour >= self::NIGHT_START || $userLocalHour < self::NIGHT_END) {
                continue;
            }

            // Не раньше, чем через FIRST_DELAY_HOURS от локального начала дня
            $dayStartServerMs = (strtotime($userLocalDate . ' 00:00:00 UTC') * 1000) - $offsetMs;
            $firstAtServerMs  = $dayStartServerMs + self::FIRST_DELAY_HOURS * 3600 * 1000;
            if ($nowMs < $firstAtServerMs) continue;

            $userLogs = Storage::getHabits($uid);

            // ─── Кого напоминаем ───
            //   • если задан UF_HABIT_ID — только его;
            //   • если не задан — только привычки, которые пользователь
            //     когда-либо выполнял (есть в $userLogs).
            $selectedHabit    = Storage::getSelectedHabit($uid);
            $habitIdsToNotify = [];

            if ($selectedHabit !== null) {
                if (isset($habitsById[$selectedHabit])) {
                    $habitIdsToNotify[] = $selectedHabit;
                } else {
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        'selected habit not found in catalog uid='
                        . substr($uid, 0, 12) . '… habit=' . $selectedHabit,
                        'Scheduler habit',
                        '/x5.pwa.log'
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

                // 1. Норма дня выполнена
                if ($completionsToday >= (int)$cfg['timesPerDay']) {
                    self::logSkip($uid, $habitId, 'done_today', [
                        'completionsToday' => $completionsToday,
                        'timesPerDay'      => (int)$cfg['timesPerDay'],
                    ]);
                    continue;
                }

                // 2. Интервал между выполнениями ещё не прошёл
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

                // 3. Время старта напоминаний ещё не наступило
                $notifyFromTime = (string)($cfg['notifyFromTime'] ?? '');
                if ($notifyFromTime !== '' && $userLocalTime < $notifyFromTime) {
                    self::logSkip($uid, $habitId, 'notify_from_time', [
                        'local_time'  => $userLocalTime,
                        'notify_from' => $notifyFromTime,
                    ]);
                    continue;
                }

                // 4. Состояние напоминаний за сегодня
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

                // Всё прошло — отправляем
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
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        sprintf('sent uid=%s habit=%s doneToday=%d/%d',
                            substr($uid, 0, 12) . '…', $habitId,
                            $completionsToday, (int)$cfg['timesPerDay']
                        ),
                        'Scheduler habit',
                        '/x5.pwa.log'
                    );
                } elseif ($r['gone']) {
                    Storage::deleteSubscription($uid);
                    break;
                }
            }
        }
    }

    /**
     * Приводит дату выполнения к unix-ms по серверу (UTC).
     */
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