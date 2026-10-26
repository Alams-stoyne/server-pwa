<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class Outbox
{
    public const TABLE_TASKS    = 'x5_pwa_task_completions';
    public const TABLE_LECTURES = 'x5_pwa_lecture_reads';

    public const STATE_PENDING = 'pending';
    public const STATE_AWARDED = 'awarded';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_FAILED  = 'failed';

    /** @var resource|null */
    private static $lockFp = null;
    private static ?string $lockFile = null;

    /**
     * Точка входа агента. Возвращает своё имя — Bitrix перерегистрирует.
     */
    public static function run(): string
    {
        if (!Settings::outboxEnabled()) {
            return '\\X5\\Pwa\\Outbox::run();';
        }

        if (!self::acquireLock()) {
            return '\\X5\\Pwa\\Outbox::run();';
        }

        try {
            $batch    = Settings::outboxBatchSize();
            $tasks    = self::processTasks($batch);
            $lectures = self::processLectures($batch);

            if ($tasks + $lectures > 0) {
                Settings::log(
                    "Outbox run: tasks={$tasks} lectures={$lectures} batch={$batch}",
                    'Outbox::run'
                );
            }
            Settings::set('outbox_last_run', (string)time());
        } catch (\Throwable $e) {
            Settings::logError(
                'Outbox::run failed: ' . $e->getMessage(),
                'Outbox::run'
            );
        } finally {
            self::releaseLock();
        }

        return '\\X5\\Pwa\\Outbox::run();';
    }

    public static function processTasks(int $limit = 500): int
    {
        return self::process(self::TABLE_TASKS, 'task', $limit);
    }

    public static function processLectures(int $limit = 500): int
    {
        return self::process(self::TABLE_LECTURES, 'lecture', $limit);
    }

    /**
     * @return array{processed:int,awarded:int,skipped:int,failed:int}
     */
    public static function getStats(): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $out = [
            'pending' => 0, 'awarded' => 0,
            'skipped' => 0, 'failed'  => 0,
        ];

        foreach ([self::TABLE_TASKS, self::TABLE_LECTURES] as $t) {
            $tSafe = $sqlHelper->forSql($t);
            try {
                $rs = $conn->query(
                    "SELECT UF_REWARD_STATE, COUNT(*) AS C FROM `{$tSafe}` "
                    . "GROUP BY UF_REWARD_STATE"
                );
                while ($r = $rs->fetch()) {
                    $state = (string)$r['UF_REWARD_STATE'];
                    if (isset($out[$state])) $out[$state] += (int)$r['C'];
                }
            } catch (\Throwable $e) {
                // поле ещё не создано — миграция не применена
                Settings::logError(
                    "Outbox::getStats {$t}: " . $e->getMessage(),
                    'Outbox::getStats'
                );
            }
        }

        return $out;
    }

    public static function resetFailedToPending(): int
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $total = 0;

        foreach ([self::TABLE_TASKS, self::TABLE_LECTURES] as $t) {
            $tSafe = $sqlHelper->forSql($t);
            try {
                $conn->queryExecute(
                    "UPDATE `{$tSafe}` SET UF_REWARD_STATE='" . self::STATE_PENDING . "' "
                    . "WHERE UF_REWARD_STATE='" . self::STATE_FAILED . "'"
                );
                $total += (int)$conn->getAffectedRowsCount();
            } catch (\Throwable $e) {
                Settings::logError(
                    'resetFailedToPending: ' . $e->getMessage(),
                    'Outbox::resetFailedToPending'
                );
            }
        }

        return $total;
    }

    public static function resetSkippedToPending(): int
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $total = 0;

        foreach ([self::TABLE_TASKS, self::TABLE_LECTURES] as $t) {
            $tSafe = $sqlHelper->forSql($t);
            try {
                $conn->queryExecute(
                    "UPDATE `{$tSafe}` SET UF_REWARD_STATE='" . self::STATE_PENDING . "' "
                    . "WHERE UF_REWARD_STATE='" . self::STATE_SKIPPED . "'"
                );
                $total += (int)$conn->getAffectedRowsCount();
            } catch (\Throwable $e) {
                Settings::logError(
                    'resetSkippedToPending: ' . $e->getMessage(),
                    'Outbox::resetSkippedToPending'
                );
            }
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────

    private static function process(string $table, string $kind, int $limit): int
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
            Settings::logError(
                "Outbox select {$table} failed: " . $e->getMessage()
                . " (поле UF_REWARD_STATE не создано? запустите outbox_migrate.php)",
                'Outbox::process'
            );
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
                                if (in_array(
                                    $resReason,
                                    ['DAILY_LIMIT_REACHED', 'NO_POINTS', 'ELEMENT_DATE_MISMATCH'],
                                    true
                                )) {
                                    $state  = self::STATE_SKIPPED;
                                    $reason = $resReason;
                                } else {
                                    $state  = self::STATE_FAILED;
                                    $reason = $resReason;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $state  = self::STATE_FAILED;
                $reason = 'EXC:' . substr($e->getMessage(), 0, 120);
            }

            self::markState($table, $id, $state);

            if ($state === self::STATE_FAILED) {
                Settings::logError(
                    "Outbox failed {$kind} id={$id} uid=" . substr($uid, 0, 12)
                    . "… key={$rawKey} reason={$reason}",
                    'Outbox::process'
                );
            }

            $processed++;
        }

        return $processed;
    }

    private static function markState(string $table, int $id, string $state): void
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        try {
            $conn->queryExecute(
                "UPDATE `" . $sqlHelper->forSql($table) . "` "
                . "SET UF_REWARD_STATE = '" . $sqlHelper->forSql($state) . "' "
                . "WHERE ID = " . (int)$id
            );
        } catch (\Throwable $e) {
            Settings::logError(
                "markState {$table} id={$id}: " . $e->getMessage(),
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