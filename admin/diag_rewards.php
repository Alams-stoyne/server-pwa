<?php
/**
 * Одноразовая диагностика наград. УДАЛИТЬ ПОСЛЕ ИСПОЛЬЗОВАНИЯ.
 *
 * GET-параметры:
 *   uid=<40hex>       — только один пользователь
 *   apply=Y           — реально досчитать пропущенное
 *   pageSize=<N>      — размер батча (по умолчанию 500)
 *   maxRows=<N>       — предохранитель от «всё за один раз» (по умолчанию 200000)
 *   kind=tasks|lectures|all — что проверять (по умолчанию all)
 *
 * Порядок обхода: ORDER BY ID ASC — детерминированно, от старых к новым.
 * При apply=Y досчитывается в порядке исходных действий — это соответствует
 * бизнес-логике «награда за первое действие дня, пока лимит не исчерпан».
 *
 * Параллельный запуск защищён lockfile.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\RewardsBuilder;
use X5\Pwa\Settings;
use X5\Pwa\Storage;

Loader::includeModule('x5.pwa');
Loader::includeModule('highloadblock');

$apply    = (string)($_GET['apply']    ?? '') === 'Y';
$onlyUid  = trim((string)($_GET['uid'] ?? ''));
$pageSize = max(50, min(5000, (int)($_GET['pageSize'] ?? 500)));
$maxRows  = max(100, min(2_000_000, (int)($_GET['maxRows'] ?? 200_000)));
$kind     = in_array($_GET['kind'] ?? '', ['tasks', 'lectures', 'all'], true)
    ? ($_GET['kind'] ?? 'all')
    : 'all';

// ── Lockfile: не даём двум процессам apply работать одновременно ──
$lockFile = sys_get_temp_dir() . '/x5.pwa.diag_rewards.lock';
$lockFp   = null;
if ($apply) {
    $lockFp = @fopen($lockFile, 'c+');
    if ($lockFp) {
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            die('Другой процесс apply=Y уже запущен. Остановитесь.');
        }
    }
}

echo '<pre style="font:12px monospace;background:#fff;padding:16px;line-height:1.4">';
echo "=== Диагностика наград x5.pwa ===\n";
echo "mode:     " . ($apply ? 'APPLY (реальный досчёт)' : 'DRY-RUN') . "\n";
echo "uid:      " . ($onlyUid !== '' ? $onlyUid : 'все') . "\n";
echo "kind:     " . $kind . "\n";
echo "pageSize: " . $pageSize . "\n";
echo "maxRows:  " . $maxRows . "\n\n";

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

/**
 * Разбор ID в числовой elementId.
 * Форматы:
 *   "5"                          — numeric
 *   "5-2026-10-25"               — numeric + date
 *   "2026-10-25:5-2026-10-25"    — prefix + numeric + date
 *   "stress-relief-1-2026-10-25" — CODE + date
 *   "2026-10-25:stress-relief-1-2026-10-25"
 *
 * Порядок: сначала снять -YYYY-MM-DD, потом prefix до :
 */
function diagResolveElementId(string $rawId): ?int
{
    if (method_exists(RewardsBuilder::class, 'resolveElementIdFromAny')) {
        return RewardsBuilder::resolveElementIdFromAny($rawId);
    }

    $rawId = trim($rawId);
    if ($rawId === '') return null;

    // 1) снять хвостовую -YYYY-MM-DD
    if (preg_match('/^(.+?)-\d{4}-\d{2}-\d{2}$/', $rawId, $m)) {
        $rawId = $m[1];
    }

    // 2) снять prefix до последнего ':' (после снятия даты — безопасно)
    if (($pos = strrpos($rawId, ':')) !== false) {
        $rawId = substr($rawId, $pos + 1);
    }

    if ($rawId === '') return null;
    if (ctype_digit($rawId)) return (int)$rawId;

    foreach (['general_lectures', 'charging', 'theme_lectures', 'tasks'] as $key) {
        $ibId = (int)Settings::iblockId($key);
        if ($ibId <= 0) continue;
        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, '=CODE' => $rawId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        if ($el = $rs->Fetch()) return (int)$el['ID'];
    }
    return null;
}

function diagLocalDateFromCompletedAt($completedAtDt, string $uid): string
{
    if (!$completedAtDt) return '';
    $tz = Storage::getUserTzOffsetMin($uid);
    $ts = method_exists($completedAtDt, 'getTimestamp')
        ? $completedAtDt->getTimestamp()
        : (int)strtotime((string)$completedAtDt);
    $localMs = ($ts * 1000) + $tz * 60 * 1000;
    return gmdate('Y-m-d', (int)($localMs / 1000));
}

function diagTypeFromSource(string $source): string
{
    return match ($source) {
        'general'  => 'lecture_general',
        'charging' => 'charging',
        default    => 'lecture_theme',
    };
}

function diagLimitForLectureType(string $type): int
{
    return match ($type) {
        'lecture_theme'   => Settings::dailyThemeLectureLimit(),
        'lecture_general' => Settings::dailyGeneralLectureLimit(),
        'charging'        => Settings::dailyChargingLimit(),
        default           => 0,
    };
}

/**
 * Универсальный обработчик батча.
 *
 * @param string $kind        'tasks' | 'lectures'
 * @param array  $rows        сырые строки из БД
 * @param bool   $apply
 * @param callable $resolver  fn(string $rawId): ?int
 * @param callable $infoFn    fn(int $elementId): ?array (resolveTaskInfo / resolveLectureInfo)
 * @param callable $awardFn   fn(...): array (awardTask / awardLecture)
 * @param string $actionTypesForCheck  SQL-литерал для IN(...) при проверке existing
 * @return array{stats: array, missing: array}
 */
function diagProcessBatch(
    string $kind,
    array $rows,
    bool $apply,
    callable $resolver,
    callable $infoFn,
    callable $awardFn,
    string $actionTypesForCheck
): array {
    $conn      = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $stats = [
        'checked'             => 0,
        'OK'                  => 0,
        'NOT_RESOLVED'        => 0,
        'ELEMENT_GONE'        => 0,
        'NO_POINTS'           => 0,
        'DATE_MISMATCH'       => 0,
        'DAILY_LIMIT_REACHED' => 0,
        'MISSING_NO_REASON'   => 0,
        'FIXED'               => 0,
        'ALREADY'             => 0,
        'LIMIT_AT_FIX'        => 0,
        'FIX_FAILED'          => 0,
    ];
    $missing = [];

    foreach ($rows as $r) {
        $stats['checked']++;

        $uid      = (string)$r['UF_UID'];
        $rawKey   = (string)($kind === 'tasks' ? $r['UF_TASK_ID'] : $r['UF_LECTURE_ID']);
        $whenDt   = $r['WHEN_AT'] ?? null;

        $elementId = $resolver($rawKey);
        if ($elementId === null) {
            $stats['NOT_RESOLVED']++;
            $missing[] = [
                'reason' => 'NOT_RESOLVED', 'uid' => $uid, 'id' => $rawKey,
                'elementId' => null,
                'localDate' => diagLocalDateFromCompletedAt($whenDt, $uid),
            ];
            continue;
        }

        $elementDate = '';
        if (preg_match('/-(\d{4}-\d{2}-\d{2})$/', $rawKey, $m)) $elementDate = $m[1];

        $expectedActionId = $elementId . ':' . $elementDate;

        $existing = $conn->query(
            "SELECT ID FROM x5_pwa_reward_log
             WHERE UF_UID = '" . $sqlHelper->forSql($uid) . "'
               AND UF_ACTION_TYPE IN ({$actionTypesForCheck})
               AND UF_ACTION_ID = '" . $sqlHelper->forSql($expectedActionId) . "'
             LIMIT 1"
        )->fetch();

        if ($existing) {
            $stats['OK']++;
            continue;
        }

        $info = $infoFn($elementId);
        if ($info === null) {
            $stats['ELEMENT_GONE']++;
            $missing[] = [
                'reason' => 'ELEMENT_GONE', 'uid' => $uid, 'id' => $rawKey,
                'elementId' => $elementId,
                'localDate' => diagLocalDateFromCompletedAt($whenDt, $uid),
            ];
            continue;
        }

        if ((int)$info['points'] <= 0) {
            $stats['NO_POINTS']++;
            continue;
        }

        $localDate = diagLocalDateFromCompletedAt($whenDt, $uid);

        if ($localDate !== '' && $info['date'] !== $localDate) {
            $stats['DATE_MISMATCH']++;
            $missing[] = [
                'reason' => 'DATE_MISMATCH', 'uid' => $uid, 'id' => $rawKey,
                'elementId' => $elementId,
                'localDate' => $localDate, 'elementDate' => $info['date'],
            ];
            continue;
        }

        // Проверка лимита — по типу действия
        if ($kind === 'tasks') {
            $type  = 'task';
            $limit = Settings::dailyTaskLimit();
        } else {
            $type  = diagTypeFromSource((string)($info['source'] ?? ''));
            $limit = diagLimitForLectureType($type);
        }

        $rewardsThatDay = (int)($conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_reward_log
             WHERE UF_UID = '" . $sqlHelper->forSql($uid) . "'
               AND UF_ACTION_TYPE = '" . $sqlHelper->forSql($type) . "'
               AND UF_LOCAL_DATE = '" . $sqlHelper->forSql($localDate) . "'"
        )->fetch()['C'] ?? 0);

        if ($limit > 0 && $rewardsThatDay >= $limit) {
            $stats['DAILY_LIMIT_REACHED']++;
            continue;
        }

        $stats['MISSING_NO_REASON']++;
        $missing[] = [
            'reason' => 'MISSING_NO_REASON', 'uid' => $uid, 'id' => $rawKey,
            'elementId' => $elementId,
            'localDate' => $localDate, 'elementDate' => $info['date'],
            'points' => (int)$info['points'],
            'title'  => (string)($info['title'] ?? ''),
            'type'   => $type,
            'rewardsThatDay' => $rewardsThatDay,
            'limit' => $limit,
        ];

        if (!$apply) continue;

        try {
            if ($kind === 'tasks') {
                $res = $awardFn(
                    $uid, $elementId,
                    (int)$info['points'], (string)$info['title'],
                    (string)$info['date'], $localDate
                );
            } else {
                $res = $awardFn(
                    $uid, $elementId,
                    (string)$info['type'], (int)$info['points'], (string)$info['title'],
                    (string)$info['date'], $localDate, (string)($info['source'] ?? '')
                );
            }

            if (!empty($res['added'])) {
                $stats['FIXED']++;
                echo sprintf(
                    "  ✔ %s досчитано: uid=%s id=%s points=+%d localDate=%s\n",
                    $kind, substr($uid, 0, 12) . '…', $rawKey,
                    (int)$info['points'], $localDate
                );
            } else {
                $reason = (string)($res['reason'] ?? '?');
                if ($reason === 'ALREADY_AWARDED') {
                    $stats['ALREADY']++;
                } elseif ($reason === 'DAILY_LIMIT_REACHED') {
                    $stats['LIMIT_AT_FIX']++;
                } else {
                    $stats['FIX_FAILED']++;
                    echo sprintf(
                        "  ✖ %s не удалось: uid=%s id=%s reason=%s\n",
                        $kind, substr($uid, 0, 12) . '…', $rawKey, $reason
                    );
                }
            }
        } catch (\Throwable $e) {
            $stats['FIX_FAILED']++;
            echo "  ✖ {$kind} exception uid=" . substr($uid, 0, 12) . "…: " . $e->getMessage() . "\n";
        }
    }

    return ['stats' => $stats, 'missing' => $missing];
}

/**
 * Пагинация по ID без LIMIT-обрезки.
 */
function diagIterateTable(
    string $table,
    string $idColumn,
    string $rawColumn,
    string $whenColumn,
    string $onlyUid,
    int $pageSize,
    int $maxRows,
    callable $handleBatch
): void {
    $conn      = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $tableSafe = $sqlHelper->forSql($table);
    $idSafe    = $sqlHelper->forSql($idColumn);
    $rawSafe   = $sqlHelper->forSql($rawColumn);
    $whenSafe  = $sqlHelper->forSql($whenColumn);

    $lastId = 0;
    $total  = 0;

    while ($total < $maxRows) {
        $where = ["`{$idSafe}` > {$lastId}"];
        if ($onlyUid !== '') {
            $where[] = "UF_UID = '" . $sqlHelper->forSql($onlyUid) . "'";
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $conn->query(
            "SELECT `{$idSafe}` AS ID, UF_UID,
                    `{$rawSafe}` AS RAW_KEY,
                    `{$whenSafe}` AS WHEN_AT
             FROM `{$tableSafe}`
             {$whereSql}
             ORDER BY `{$idSafe}` ASC
             LIMIT {$pageSize}"
        )->fetchAll();

        if (!$rows) break;

        // нормализуем ключи для единой обработки
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = [
                'ID'      => (int)$r['ID'],
                'UF_UID'  => (string)$r['UF_UID'],
                'RAW_KEY' => (string)$r['RAW_KEY'],
                'WHEN_AT' => $r['WHEN_AT'],
                // совместимость с diagProcessBatch:
                'UF_TASK_ID'    => (string)$r['RAW_KEY'],
                'UF_LECTURE_ID' => (string)$r['RAW_KEY'],
            ];
        }

        $lastId = (int)end($batch)['ID'];
        $total += count($batch);

        $res = $handleBatch($batch);

        echo "  обработано батчей: " . count($batch) . " (последний ID = {$lastId})\n";

        if (count($batch) < $pageSize) break;
    }

    if ($total >= $maxRows) {
        echo "\n⚠ Достигнут maxRows = {$maxRows}. Возможно, есть ещё записи.\n";
        echo "  Запустите с ?maxRows=<больше>&... либо фильтруйте ?uid=.\n";
    }
}

// ─────────────────────────────────────────────────────────────
// 1. TASKS
// ─────────────────────────────────────────────────────────────

$statsTasks = null;
$missingTasks = [];

if ($kind === 'tasks' || $kind === 'all') {
    echo "──── 1. TASKS ────\n";

    $handleTasks = function (array $batch) use ($apply, &$statsTasks, &$missingTasks): array {
        $res = diagProcessBatch(
            'tasks', $batch, $apply,
            'diagResolveElementId',
            fn(int $id) => RewardsBuilder::resolveTaskInfo($id),
            fn(...$args) => RewardsBuilder::awardTask(...$args),
            "'task'"
        );

        if ($statsTasks === null) {
            $statsTasks = $res['stats'];
        } else {
            foreach ($res['stats'] as $k => $v) $statsTasks[$k] += $v;
        }
        if ($res['missing']) $missingTasks = array_merge($missingTasks, $res['missing']);
        return $res;
    };

    diagIterateTable(
        'x5_pwa_task_completions', 'ID', 'UF_TASK_ID', 'UF_COMPLETED_AT',
        $onlyUid, $pageSize, $maxRows, $handleTasks
    );

    echo "\nИтоги TASKS:\n";
    foreach ($statsTasks as $k => $v) {
        if ($v > 0) echo sprintf("  %-22s %d\n", $k, $v);
    }
    echo "\n";

    if ($missingTasks) {
        echo "Проблемные TASKS (первые 20 из " . count($missingTasks) . "):\n";
        foreach (array_slice($missingTasks, 0, 20) as $mt) {
            echo sprintf(
                "  [%s] uid=%s id=%s elementId=%s localDate=%s elementDate=%s\n",
                $mt['reason'],
                substr($mt['uid'], 0, 12) . '…',
                $mt['id'], $mt['elementId'] ?? '-',
                $mt['localDate'] ?? '-', $mt['elementDate'] ?? '-'
            );
        }
        if (count($missingTasks) > 20) {
            echo "  ... и ещё " . (count($missingTasks) - 20) . "\n";
        }
    } else {
        echo "✔ проблемных TASKS нет\n";
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────
// 2. LECTURES
// ─────────────────────────────────────────────────────────────

$statsLect = null;
$missingLect = [];

if ($kind === 'lectures' || $kind === 'all') {
    echo "──── 2. LECTURES (чтение) ────\n";

    $handleLectures = function (array $batch) use ($apply, &$statsLect, &$missingLect): array {
        $res = diagProcessBatch(
            'lectures', $batch, $apply,
            'diagResolveElementId',
            fn(int $id) => RewardsBuilder::resolveLectureInfo($id),
            fn(...$args) => RewardsBuilder::awardLecture(...$args),
            "'lecture','lecture_general','lecture_theme','charging'"
        );

        if ($statsLect === null) {
            $statsLect = $res['stats'];
        } else {
            foreach ($res['stats'] as $k => $v) $statsLect[$k] += $v;
        }
        if ($res['missing']) $missingLect = array_merge($missingLect, $res['missing']);
        return $res;
    };

    diagIterateTable(
        'x5_pwa_lecture_reads', 'ID', 'UF_LECTURE_ID', 'UF_READ_AT',
        $onlyUid, $pageSize, $maxRows, $handleLectures
    );

    echo "\nИтоги LECTURES:\n";
    foreach ($statsLect as $k => $v) {
        if ($v > 0) echo sprintf("  %-22s %d\n", $k, $v);
    }
    echo "\n";

    if ($missingLect) {
        echo "Проблемные LECTURES (первые 20 из " . count($missingLect) . "):\n";
        foreach (array_slice($missingLect, 0, 20) as $mt) {
            echo sprintf(
                "  [%s] uid=%s id=%s elementId=%s localDate=%s elementDate=%s\n",
                $mt['reason'],
                substr($mt['uid'], 0, 12) . '…',
                $mt['id'], $mt['elementId'] ?? '-',
                $mt['localDate'] ?? '-', $mt['elementDate'] ?? '-'
            );
        }
        if (count($missingLect) > 20) {
            echo "  ... и ещё " . (count($missingLect) - 20) . "\n";
        }
    } else {
        echo "✔ проблемных LECTURES нет\n";
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────
// 3. UF_REWARD_BALANCE vs SUM(UF_POINTS)
// ─────────────────────────────────────────────────────────────

echo "──── 3. UF_REWARD_BALANCE vs SUM(UF_POINTS) ────\n";

$sql = "SELECT b.UF_UID, COALESCE(b.UF_REWARD_BALANCE, 0) AS bal,
               COALESCE(SUM(r.UF_POINTS), 0) AS calc
        FROM x5_pwa_uid_bindings b
        LEFT JOIN x5_pwa_reward_log r ON r.UF_UID = b.UF_UID
        " . ($onlyUid !== '' ? "WHERE b.UF_UID = '" . $sqlHelper->forSql($onlyUid) . "'" : "") . "
        GROUP BY b.UF_UID, b.UF_REWARD_BALANCE
        HAVING ABS(bal - calc) > 0";

$mismatch = $conn->query($sql)->fetchAll();

echo count($mismatch) . " пользователей с расхождением\n";
foreach ($mismatch as $m) {
    echo sprintf(
        "  uid=%s balance=%d calc=%d (diff=%+d)\n",
        substr($m['UF_UID'], 0, 12) . '…',
        (int)$m['bal'], (int)$m['calc'], (int)$m['calc'] - (int)$m['bal']
    );
    if ($apply) {
        $recalc = RewardsBuilder::recalcBalance((string)$m['UF_UID']);
        echo "    → пересчитано: " . $recalc . "\n";
    }
}

if ($mismatch && !$apply) {
    echo "\nЗапустите с ?apply=Y чтобы пересчитать баланс.\n";
}

echo "\n=== Готово ===\n";
echo "</pre>";

// Отпускаем lock
if ($lockFp) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}