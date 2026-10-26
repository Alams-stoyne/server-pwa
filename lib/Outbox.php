<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class Outbox
{
    public const TABLE_TASKS       = 'x5_pwa_task_completions';
    public const TABLE_LECTURES    = 'x5_pwa_lecture_reads';
    public const TABLE_HABITS      = 'x5_pwa_habit_logs';
    public const TABLE_REFLECTIONS = 'x5_pwa_reflections';
    public const TABLE_STEPS       = 'x5_pwa_steps';
    public const TABLE_EVENTS      = 'x5_pwa_event_actions';

    public const STATE_PENDING = 'pending';
    public const STATE_AWARDED = 'awarded';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_FAILED  = 'failed';

    /** @var resource|null */
    private static $lockFp = null;
    private static ?string $lockFile = null;

    public static function run(): string
    {
        if (!Settings::outboxEnabled()) {
            return '\\X5\\Pwa\\Outbox::run();';
        }
        if (!self::acquireLock()) {
            return '\\X5\\Pwa\\Outbox::run();';
        }

        try {
            $batch = Settings::outboxBatchSize();

            $t = self::processTasks($batch);
            $l = self::processLectures($batch);
            $h = self::processHabits(min(200, $batch));
            $r = self::processReflections($batch);
            $s = self::processSteps($batch);
            $e = self::processEvents($batch);

            $total = $t + $l + $h + $r + $s + $e;
            if ($total > 0) {
                Settings::log(
                    "Outbox run: tasks={$t} lectures={$l} habits={$h} refl={$r} steps={$s} events={$e}",
                    'Outbox::run'
                );
            }
            Settings::set('outbox_last_run', (string)time());
        } catch (\Throwable $e) {
            Settings::logError('Outbox::run failed: ' . $e->getMessage(), 'Outbox::run');
        } finally {
            self::releaseLock();
        }

        return '\\X5\\Pwa\\Outbox::run();';
    }

    public static function processTasks(int $limit = 500): int
    {
        return self::processTaskLike(self::TABLE_TASKS, 'task', $limit);
    }

    public static function processLectures(int $limit = 500): int
    {
        return self::processTaskLike(self::TABLE_LECTURES, 'lecture', $limit);
    }

    public static function processHabits(int $limit = 200): int
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, UF_HABIT_ID, UF_COMPLETED_AT "
                . "FROM `" . self::TABLE_HABITS . "` "
                . "WHERE UF_REWARD_STATE = '" . self::STATE_PENDING . "' "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select habits failed: " . $e->getMessage(), 'Outbox::processHabits');
            return 0;
        }

        if (!$rows) return 0;

        $habitsCache = [];
        try {
            foreach (HabitsBuilder::getAll() as $h) {
                $habitsCache[(string)$h['id']] = $h;
            }
        } catch (\Throwable $e) {
            Settings::logError("Outbox habits cache failed: " . $e->getMessage(), 'Outbox::processHabits');
            return 0;
        }

        $groups = [];
        foreach ($rows as $r) {
            $uid      = (string)$r['UF_UID'];
            $habitId  = (string)$r['UF_HABIT_ID'];
            $localDate = self::localDateFromCompletedAt($r['UF_COMPLETED_AT'], $uid);
            $key = $uid . '|' . $habitId . '|' . $localDate;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'uid' => $uid, 'habitId' => $habitId,
                    'localDate' => $localDate, 'ids' => [],
                ];
            }
            $groups[$key]['ids'][] = (int)$r['ID'];
        }

        $processed = 0;
        foreach ($groups as $g) {
            $uid       = $g['uid'];
            $habitId   = $g['habitId'];
            $localDate = $g['localDate'];
            $rowIds    = $g['ids'];

            $state  = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } else {
                    $cfg = $habitsCache[$habitId] ?? null;
                    if ($cfg === null) {
                        $state  = self::STATE_SKIPPED;
                        $reason = 'HABIT_NOT_FOUND';
                    } elseif ($localDate === '') {
                        $reason = 'BAD_DATE';
                    } else {
                        $timesPerDay = max(1, (int)($cfg['timesPerDay'] ?? 1));

                        $tz = Storage::getUserTzOffsetMin($uid);
                        $startMs  = strtotime($localDate . ' 00:00:00 UTC') * 1000 - $tz * 60 * 1000;
                        $endMs    = $startMs + 86400 * 1000;
                        $startStr = gmdate('Y-m-d H:i:s', (int)($startMs / 1000));
                        $endStr   = gmdate('Y-m-d H:i:s', (int)($endMs / 1000));

                        $cntRow = $conn->query(
                            "SELECT COUNT(*) AS C FROM `" . self::TABLE_HABITS . "` "
                            . "WHERE UF_UID = '" . $sqlHelper->forSql($uid) . "' "
                            . "AND UF_HABIT_ID = '" . $sqlHelper->forSql($habitId) . "' "
                            . "AND UF_COMPLETED_AT >= '" . $sqlHelper->forSql($startStr) . "' "
                            . "AND UF_COMPLETED_AT < '" . $sqlHelper->forSql($endStr) . "'"
                        )->fetch();
                        $doneToday = (int)($cntRow['C'] ?? 0);

                        if ($doneToday < $timesPerDay) {
                            $state  = self::STATE_SKIPPED;
                            $reason = 'NOT_ENOUGH';
                        } else {
                            $res = RewardsBuilder::awardHabit($uid, $cfg, $doneToday, $localDate);
                            if (!empty($res['added']) || !empty($res['already'])) {
                                $state  = self::STATE_AWARDED;
                                $reason = 'OK';
                            } else {
                                $rr = (string)($res['reason'] ?? '');
                                if (in_array($rr, ['NO_POINTS', 'NOT_DONE_YET', 'TEST_ACCOUNT'], true)) {
                                    $state  = self::STATE_SKIPPED;
                                    $reason = $rr;
                                } else {
                                    $reason = $rr !== '' ? $rr : 'UNKNOWN';
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            foreach ($rowIds as $rid) {
                self::markState(self::TABLE_HABITS, $rid, 'UF_REWARD_STATE', $state);
            }
            $processed += count($rowIds);

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed habit uid=" . substr($uid, 0, 12) . "… habit={$habitId} date={$localDate} reason={$reason}",
                    'Outbox::processHabits'
                );
            }
        }

        return $processed;
    }

    public static function processReflections(int $limit = 500): int
    {
        $conn = Application::getConnection();

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, UF_LOCAL_DATE, UF_SENT_AT "
                . "FROM `" . self::TABLE_REFLECTIONS . "` "
                . "WHERE UF_REWARD_STATE = '" . self::STATE_PENDING . "' "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select reflections failed: " . $e->getMessage(), 'Outbox::processReflections');
            return 0;
        }

        if (!$rows) return 0;

        $processed = 0;
        foreach ($rows as $r) {
            $id    = (int)$r['ID'];
            $uid   = (string)$r['UF_UID'];
            $ld    = trim((string)($r['UF_LOCAL_DATE'] ?? ''));
            if ($ld === '') {
                $ld = self::localDateFromCompletedAt($r['UF_SENT_AT'], $uid);
            }

            $state  = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } elseif ($ld === '') {
                    $reason = 'BAD_DATE';
                } else {
                    $res = RewardsBuilder::awardReflection($uid, $ld);
                    if (!empty($res['added']) || !empty($res['already'])) {
                        $state  = self::STATE_AWARDED;
                        $reason = 'OK';
                    } else {
                        $rr = (string)($res['reason'] ?? '');
                        if (in_array($rr, ['NO_POINTS', 'TEST_ACCOUNT'], true)) {
                            $state  = self::STATE_SKIPPED;
                            $reason = $rr;
                        } else {
                            $reason = $rr !== '' ? $rr : 'UNKNOWN';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            self::markState(self::TABLE_REFLECTIONS, $id, 'UF_REWARD_STATE', $state);
            $processed++;

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed reflection id={$id} uid=" . substr($uid, 0, 12) . "… date={$ld} reason={$reason}",
                    'Outbox::processReflections'
                );
            }
        }

        return $processed;
    }

    public static function processSteps(int $limit = 500): int
    {
        return self::processStepsSent($limit) + self::processStepsApproved($limit);
    }

    public static function processEvents(int $limit = 500): int
    {
        $conn = Application::getConnection();

        try {
            $conn->queryExecute(
                "UPDATE `" . self::TABLE_EVENTS . "` SET UF_REWARD_STATE = 'skipped' "
                . "WHERE UF_REWARD_STATE = 'pending' AND UF_CONFIRMED_AT IS NULL"
            );
        } catch (\Throwable $e) {
            Settings::logError("Outbox event cleanup failed: " . $e->getMessage(), 'Outbox::processEvents');
        }

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, UF_EVENT_ID, UF_CONFIRMED_AT, UF_REWARD_POINTS, UF_REWARD_TEXT, UF_EVENT_TITLE "
                . "FROM `" . self::TABLE_EVENTS . "` "
                . "WHERE UF_REWARD_STATE = '" . self::STATE_PENDING . "' "
                . "AND UF_CONFIRMED_AT IS NOT NULL "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select events failed: " . $e->getMessage(), 'Outbox::processEvents');
            return 0;
        }

        if (!$rows) return 0;

        $processed = 0;
        foreach ($rows as $r) {
            $id       = (int)$r['ID'];
            $uid      = (string)$r['UF_UID'];
            $eventId  = (string)$r['UF_EVENT_ID'];
            $points   = (int)($r['UF_REWARD_POINTS'] ?? 0);
            $title    = trim((string)($r['UF_REWARD_TEXT'] ?? ''));
            if ($title === '') $title = (string)($r['UF_EVENT_TITLE'] ?? 'Мероприятие');
            $localDate = self::localDateFromCompletedAt($r['UF_CONFIRMED_AT'], $uid);

            $state  = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } elseif ($eventId === '' || $localDate === '') {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'BAD_DATA';
                } elseif ($points <= 0) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'NO_POINTS';
                } else {
                    $res = RewardsBuilder::awardEvent($uid, $eventId, $points, $title, $localDate);
                    if (!empty($res['added']) || !empty($res['already'])) {
                        $state  = self::STATE_AWARDED;
                        $reason = 'OK';
                    } else {
                        $rr = (string)($res['reason'] ?? '');
                        if (in_array($rr, ['NO_POINTS', 'TEST_ACCOUNT'], true)) {
                            $state  = self::STATE_SKIPPED;
                            $reason = $rr;
                        } else {
                            $reason = $rr !== '' ? $rr : 'UNKNOWN';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            self::markState(self::TABLE_EVENTS, $id, 'UF_REWARD_STATE', $state);
            $processed++;

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed event id={$id} uid=" . substr($uid, 0, 12) . "… event={$eventId} reason={$reason}",
                    'Outbox::processEvents'
                );
            }
        }

        return $processed;
    }

    /**
     * @return array<string, array{pending:int,awarded:int,skipped:int,failed:int}>
     */
    public static function getStats(): array
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $sources = [
            'tasks'           => [self::TABLE_TASKS,       'UF_REWARD_STATE'],
            'lectures'        => [self::TABLE_LECTURES,    'UF_REWARD_STATE'],
            'habits'          => [self::TABLE_HABITS,      'UF_REWARD_STATE'],
            'reflections'     => [self::TABLE_REFLECTIONS, 'UF_REWARD_STATE'],
            'steps_sent'      => [self::TABLE_STEPS,       'UF_REWARD_STATE_SENT'],
            'steps_approved'  => [self::TABLE_STEPS,       'UF_REWARD_STATE_APPROVED'],
            'events'          => [self::TABLE_EVENTS,      'UF_REWARD_STATE'],
        ];

        $out = [];
        foreach ($sources as $label => $cfg) {
            [$table, $col] = $cfg;
            $tSafe = $sqlHelper->forSql($table);
            $cSafe = $sqlHelper->forSql($col);

            $out[$label] = ['pending' => 0, 'awarded' => 0, 'skipped' => 0, 'failed' => 0];
            try {
                $rs = $conn->query(
                    "SELECT `{$cSafe}` AS ST, COUNT(*) AS C "
                    . "FROM `{$tSafe}` GROUP BY `{$cSafe}`"
                );
                while ($r = $rs->fetch()) {
                    $st = (string)$r['ST'];
                    if (isset($out[$label][$st])) {
                        $out[$label][$st] += (int)$r['C'];
                    }
                }
            } catch (\Throwable $e) {
                Settings::logError("Outbox::getStats {$table}.{$col}: " . $e->getMessage(), 'Outbox::getStats');
            }
        }

        return $out;
    }

    public static function resetFailedToPending(): int
    {
        return self::resetState(self::STATE_FAILED, self::STATE_PENDING);
    }

    public static function resetSkippedToPending(): int
    {
        return self::resetState(self::STATE_SKIPPED, self::STATE_PENDING);
    }

    // ─────────────────────────────────────────────────────────

    private static function resetState(string $fromState, string $toState): int
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $sources = [
            [self::TABLE_TASKS,       'UF_REWARD_STATE'],
            [self::TABLE_LECTURES,    'UF_REWARD_STATE'],
            [self::TABLE_HABITS,      'UF_REWARD_STATE'],
            [self::TABLE_REFLECTIONS, 'UF_REWARD_STATE'],
            [self::TABLE_STEPS,       'UF_REWARD_STATE_SENT'],
            [self::TABLE_STEPS,       'UF_REWARD_STATE_APPROVED'],
            [self::TABLE_EVENTS,      'UF_REWARD_STATE'],
        ];

        $total = 0;
        foreach ($sources as [$table, $col]) {
            $tSafe = $sqlHelper->forSql($table);
            $cSafe = $sqlHelper->forSql($col);
            try {
                $conn->queryExecute(
                    "UPDATE `{$tSafe}` SET `{$cSafe}` = '" . $sqlHelper->forSql($toState) . "' "
                    . "WHERE `{$cSafe}` = '" . $sqlHelper->forSql($fromState) . "'"
                );
                $total += (int)$conn->getAffectedRowsCount();
            } catch (\Throwable $e) {
                Settings::logError("resetState {$table}.{$col}: " . $e->getMessage(), 'Outbox::resetState');
            }
        }
        return $total;
    }

    private static function processTaskLike(string $table, string $kind, int $limit): int
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $tableSafe = $sqlHelper->forSql($table);
        $rawCol    = $kind === 'task' ? 'UF_TASK_ID'      : 'UF_LECTURE_ID';
        $whenCol   = $kind === 'task' ? 'UF_COMPLETED_AT' : 'UF_READ_AT';

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, `{$rawCol}` AS RAW_KEY, `{$whenCol}` AS WHEN_AT "
                . "FROM `{$tableSafe}` "
                . "WHERE UF_REWARD_STATE = '" . self::STATE_PENDING . "' "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select {$table} failed: " . $e->getMessage(), 'Outbox::processTaskLike');
            return 0;
        }

        if (!$rows) return 0;

        $processed = 0;
        foreach ($rows as $r) {
            $id     = (int)$r['ID'];
            $uid    = (string)$r['UF_UID'];
            $rawKey = (string)$r['RAW_KEY'];
            $whenAt = $r['WHEN_AT'];

            $state  = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } else {
                    $candidateKeys = $kind === 'task'
                        ? ['tasks']
                        : ['general_lectures', 'charging', 'theme_lectures'];

                    $elementId = RewardsBuilder::resolveElementIdFromAny($rawKey, $candidateKeys);

                    if ($elementId === null) {
                        $reason = 'NOT_RESOLVED';
                    } else {
                        $info = $kind === 'task'
                            ? RewardsBuilder::resolveTaskInfo($elementId)
                            : RewardsBuilder::resolveLectureInfo($elementId);

                        if ($info === null) {
                            $reason = 'ELEMENT_GONE';
                        } elseif ((int)$info['points'] <= 0) {
                            $state  = self::STATE_SKIPPED;
                            $reason = 'NO_POINTS';
                        } else {
                            $localDate = self::localDateFromCompletedAt($whenAt, $uid);

                            if ($localDate === '' || $info['date'] !== $localDate) {
                                $state  = self::STATE_SKIPPED;
                                $reason = 'DATE_MISMATCH';
                            } else {
                                $actionId    = $elementId . ':' . $localDate;
                                $actionTypes = $kind === 'task'
                                    ? [RewardsBuilder::TYPE_TASK]
                                    : [
                                        RewardsBuilder::TYPE_LECTURE,
                                        RewardsBuilder::TYPE_LECTURE_GENERAL,
                                        RewardsBuilder::TYPE_LECTURE_THEME,
                                        RewardsBuilder::TYPE_CHARGING,
                                    ];

                                if (self::hasExistingRewardLog($uid, $actionId, $actionTypes)) {
                                    $state  = self::STATE_AWARDED;
                                    $reason = 'ALREADY';
                                } else {
                                    if ($kind === 'task') {
                                        $res = RewardsBuilder::awardTask(
                                            $uid, $elementId, (int)$info['points'],
                                            (string)$info['title'], (string)$info['date'], $localDate
                                        );
                                    } else {
                                        $res = RewardsBuilder::awardLecture(
                                            $uid, $elementId,
                                            (string)($info['type'] ?? 'lecture'),
                                            (int)$info['points'],
                                            (string)$info['title'],
                                            (string)$info['date'],
                                            $localDate,
                                            (string)($info['source'] ?? '')
                                        );
                                    }

                                    if (!empty($res['added'])) {
                                        $state  = self::STATE_AWARDED;
                                        $reason = 'OK';
                                    } elseif (!empty($res['already'])) {
                                        $state  = self::STATE_AWARDED;
                                        $reason = 'ALREADY';
                                    } else {
                                        $resReason = (string)($res['reason'] ?? 'UNKNOWN');
                                        if (in_array($resReason, ['DAILY_LIMIT_REACHED', 'NO_POINTS', 'ELEMENT_DATE_MISMATCH', 'TEST_ACCOUNT'], true)) {
                                            $state  = self::STATE_SKIPPED;
                                            $reason = $resReason;
                                        } else {
                                            $reason = $resReason;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            self::markState($table, $id, 'UF_REWARD_STATE', $state);
            $processed++;

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed {$kind} id={$id} uid=" . substr($uid, 0, 12)
                    . "… key={$rawKey} reason={$reason}",
                    'Outbox::processTaskLike'
                );
            }
        }

        return $processed;
    }

    private static function processStepsSent(int $limit): int
    {
        $conn = Application::getConnection();

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, UF_LOCAL_DATE FROM `" . self::TABLE_STEPS . "` "
                . "WHERE UF_REWARD_STATE_SENT = '" . self::STATE_PENDING . "' "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select steps sent failed: " . $e->getMessage(), 'Outbox::processStepsSent');
            return 0;
        }

        if (!$rows) return 0;

        $groups = [];
        foreach ($rows as $r) {
            $uid = (string)$r['UF_UID'];
            $ld  = trim((string)$r['UF_LOCAL_DATE']);

            if ($ld === '') {
                self::markState(self::TABLE_STEPS, (int)$r['ID'], 'UF_REWARD_STATE_SENT', self::STATE_SKIPPED);
                continue;
            }
            $key = $uid . '|' . $ld;
            if (!isset($groups[$key])) $groups[$key] = ['uid' => $uid, 'ld' => $ld, 'ids' => []];
            $groups[$key]['ids'][] = (int)$r['ID'];
        }

        $processed = 0;
        foreach ($groups as $g) {
            $uid   = $g['uid'];
            $ld    = $g['ld'];
            $state = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } else {
                    $res = RewardsBuilder::awardStepsSent($uid, $ld);
                    if (!empty($res['added']) || !empty($res['already'])) {
                        $state  = self::STATE_AWARDED;
                        $reason = 'OK';
                    } else {
                        $rr = (string)($res['reason'] ?? '');
                        if (in_array($rr, ['NO_POINTS', 'TEST_ACCOUNT'], true)) {
                            $state  = self::STATE_SKIPPED;
                            $reason = $rr;
                        } else {
                            $reason = $rr !== '' ? $rr : 'UNKNOWN';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            foreach ($g['ids'] as $rid) {
                self::markState(self::TABLE_STEPS, $rid, 'UF_REWARD_STATE_SENT', $state);
            }
            $processed += count($g['ids']);

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed step sent uid=" . substr($uid, 0, 12) . "… date={$ld} reason={$reason}",
                    'Outbox::processStepsSent'
                );
            }
        }

        return $processed;
    }

    private static function processStepsApproved(int $limit): int
    {
        $conn = Application::getConnection();

        try {
            $conn->queryExecute(
                "UPDATE `" . self::TABLE_STEPS . "` SET UF_REWARD_STATE_APPROVED = 'skipped' "
                . "WHERE UF_REWARD_STATE_APPROVED = 'pending' AND UF_STATUS != 'Y'"
            );
        } catch (\Throwable $e) {
            // ok
        }

        try {
            $rows = $conn->query(
                "SELECT ID, UF_UID, UF_LOCAL_DATE FROM `" . self::TABLE_STEPS . "` "
                . "WHERE UF_REWARD_STATE_APPROVED = '" . self::STATE_PENDING . "' "
                . "AND UF_STATUS = 'Y' "
                . "ORDER BY ID ASC LIMIT " . (int)$limit
            )->fetchAll();
        } catch (\Throwable $e) {
            Settings::logError("Outbox select steps approved failed: " . $e->getMessage(), 'Outbox::processStepsApproved');
            return 0;
        }

        if (!$rows) return 0;

        $groups = [];
        foreach ($rows as $r) {
            $uid = (string)$r['UF_UID'];
            $ld  = trim((string)$r['UF_LOCAL_DATE']);
            if ($ld === '') {
                self::markState(self::TABLE_STEPS, (int)$r['ID'], 'UF_REWARD_STATE_APPROVED', self::STATE_SKIPPED);
                continue;
            }
            $key = $uid . '|' . $ld;
            if (!isset($groups[$key])) $groups[$key] = ['uid' => $uid, 'ld' => $ld, 'ids' => []];
            $groups[$key]['ids'][] = (int)$r['ID'];
        }

        $processed = 0;
        foreach ($groups as $g) {
            $uid   = $g['uid'];
            $ld    = $g['ld'];
            $state = self::STATE_FAILED;
            $reason = '';

            try {
                if (Storage::isTestAccount($uid)) {
                    $state  = self::STATE_SKIPPED;
                    $reason = 'TEST_ACCOUNT';
                } else {
                    $res = RewardsBuilder::awardStepsApproved($uid, $ld);
                    if (!empty($res['added']) || !empty($res['already'])) {
                        $state  = self::STATE_AWARDED;
                        $reason = 'OK';
                    } else {
                        $rr = (string)($res['reason'] ?? '');
                        if (in_array($rr, ['NO_POINTS', 'TEST_ACCOUNT'], true)) {
                            $state  = self::STATE_SKIPPED;
                            $reason = $rr;
                        } else {
                            $reason = $rr !== '' ? $rr : 'UNKNOWN';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            foreach ($g['ids'] as $rid) {
                self::markState(self::TABLE_STEPS, $rid, 'UF_REWARD_STATE_APPROVED', $state);
            }
            $processed += count($g['ids']);

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed step approved uid=" . substr($uid, 0, 12) . "… date={$ld} reason={$reason}",
                    'Outbox::processStepsApproved'
                );
            }
        }

        return $processed;
    }

    private static function hasExistingRewardLog(string $uid, string $actionId, array $actionTypes): bool
    {
        if ($uid === '' || $actionId === '' || !$actionTypes) return false;

        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $safeUid = $sqlHelper->forSql($uid);
        $safeId  = $sqlHelper->forSql($actionId);

        $safeTypes = [];
        foreach ($actionTypes as $t) {
            $safeTypes[] = "'" . $sqlHelper->forSql((string)$t) . "'";
        }
        $inList = implode(',', $safeTypes);

        try {
            $row = $conn->query(
                "SELECT ID FROM x5_pwa_reward_log "
                . "WHERE UF_UID = '{$safeUid}' "
                . "AND UF_ACTION_TYPE IN ({$inList}) "
                . "AND UF_ACTION_ID = '{$safeId}' "
                . "LIMIT 1"
            )->fetch();
            return !empty($row);
        } catch (\Throwable $e) {
            Settings::logError(
                'hasExistingRewardLog failed uid=' . substr($uid, 0, 12) . '… id=' . $actionId . ': ' . $e->getMessage(),
                'Outbox::hasExistingRewardLog'
            );
            return false;
        }
    }

    private static function markState(string $table, int $id, string $column, string $state): void
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        try {
            $conn->queryExecute(
                "UPDATE `" . $sqlHelper->forSql($table) . "` "
                . "SET `" . $sqlHelper->forSql($column) . "` = '" . $sqlHelper->forSql($state) . "' "
                . "WHERE ID = " . (int)$id
            );
        } catch (\Throwable $e) {
            Settings::logError(
                "markState {$table}.{$column} id={$id}: " . $e->getMessage(),
                'Outbox::markState'
            );
        }
    }

    private static function localDateFromCompletedAt($whenAt, string $uid): string
    {
        if (!$whenAt) return '';
        $tz = Storage::getUserTzOffsetMin($uid);

        if ($whenAt instanceof \Bitrix\Main\Type\DateTime
            || $whenAt instanceof \DateTimeInterface) {
            $ts = $whenAt->getTimestamp();
        } elseif (is_string($whenAt)) {
            $ts = (int)strtotime($whenAt);
        } else {
            $ts = 0;
        }
        if ($ts <= 0) return '';
        $localMs = ($ts * 1000) + $tz * 60 * 1000;
        return gmdate('Y-m-d', (int)($localMs / 1000));
    }

    private static function acquireLock(): bool
    {
        self::$lockFile = sys_get_temp_dir() . '/x5.pwa.outbox.lock';
        self::$lockFp = @fopen(self::$lockFile, 'c+');
        if (!self::$lockFp) return false;
        if (!flock(self::$lockFp, LOCK_EX | LOCK_NB)) {
            fclose(self::$lockFp);
            self::$lockFp = null;
            return false;
        }
        return true;
    }

    private static function releaseLock(): void
    {
        if (self::$lockFp) {
            flock(self::$lockFp, LOCK_UN);
            fclose(self::$lockFp);
            self::$lockFp = null;
        }
        if (self::$lockFile && is_file(self::$lockFile)) {
            @unlink(self::$lockFile);
        }
    }
}