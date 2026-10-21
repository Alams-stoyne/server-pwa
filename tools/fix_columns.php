<?php
/**
 * Разовое исправление типов колонок UF_* и создание индексов
 * в HL-блоках модуля x5.pwa.
 *
 * Проблема: Bitrix создаёт поля типа 'string' с большим SIZE как TEXT.
 * По TEXT нельзя ни индексировать, ни делать LIKE через ORM.
 * Скрипт конвертирует короткие поля в VARCHAR и создаёт индексы.
 *
 * Запуск: под админом,
 *   https://api.x5hd.ru/local/modules/x5.pwa/tools/fix_columns.php
 * Или из CLI:
 *   php /path/to/tools/fix_columns.php
 *
 * После успешного выполнения — удалить файл.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin()) { http_response_code(403); die('Access denied'); }
    header('Content-Type: text/plain; charset=utf-8');
}

\Bitrix\Main\Loader::includeModule('x5.pwa');
\Bitrix\Main\Loader::includeModule('highloadblock');

use Bitrix\Main\Application;

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

// ─────────────────────────────────────────────────────────
// 1. Конвертация TEXT → VARCHAR
// ─────────────────────────────────────────────────────────

/**
 * Целевые типы колонок. Всё, что здесь указано, будет VARCHAR(n).
 * Поля, которых нет в этом списке, не трогаем (оставляем TEXT).
 */
$targetColumns = [
    'x5_pwa_uid_bindings' => [
        'UF_UID'       => 40,
        'UF_DEVICE_ID' => 64,
        'UF_FIO'       => 255,
        'UF_NICKNAME'  => 100,
        'UF_WORKPLACE' => 255,
        'UF_THEME'     => 32,
    ],
    'x5_pwa_user_states' => [
        'UF_UID' => 40,
    ],
    'x5_pwa_lecture_reads' => [
        'UF_UID'        => 40,
        'UF_LECTURE_ID' => 128,
    ],
    'x5_pwa_task_completions' => [
        'UF_UID'     => 40,
        'UF_TASK_ID' => 128,
    ],
    'x5_pwa_habit_logs' => [
        'UF_UID'      => 40,
        'UF_HABIT_ID' => 32,
    ],
    'x5_pwa_push_subs' => [
        'UF_UID'    => 40,
        'UF_P256DH' => 255,
        'UF_AUTH'   => 255,
    ],
    'x5_pwa_notif_log' => [
        'UF_UID'      => 40,
        'UF_HABIT_ID' => 32,
    ],
    'x5_pwa_push_history' => [
        'UF_TYPE'   => 32,
        'UF_TARGET' => 64,
        'UF_TITLE'  => 255,
        'UF_STATUS' => 32,
    ],
    'x5_pwa_rate_limits' => [
        'UF_KEY' => 128,
    ],
];

echo "=== 1. Конвертация колонок TEXT → VARCHAR ===\n";

foreach ($targetColumns as $table => $cols) {
    $tableSafe = $sqlHelper->forSql($table);

    $exists = $conn->query("SHOW TABLES LIKE '{$tableSafe}'")->fetch();
    if (!$exists) {
        echo "SKIP {$table} — таблицы нет\n";
        continue;
    }

    foreach ($cols as $col => $maxSize) {
        $colSafe = $sqlHelper->forSql($col);

        $colInfo = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$colSafe}'")->fetch();
        if (!$colInfo) {
            echo "SKIP {$table}.{$col} — колонки нет\n";
            continue;
        }

        $currentType = strtolower((string)$colInfo['Type']);
        if (strpos($currentType, 'varchar') === 0) {
            echo "SKIP {$table}.{$col} — уже {$currentType}\n";
            continue;
        }

        // Проверим максимальную длину данных
        $maxRow = $conn->query(
            "SELECT MAX(CHAR_LENGTH(`{$colSafe}`)) AS MAXLEN FROM `{$tableSafe}`"
        )->fetch();
        $maxLen = (int)($maxRow['MAXLEN'] ?? 0);

        if ($maxLen > $maxSize) {
            // Данные не влезут — обрезать нельзя, оставляем как есть
            echo "WARN {$table}.{$col} — max length {$maxLen} > {$maxSize}, пропуск\n";
            continue;
        }

        try {
            $conn->queryExecute(
                "ALTER TABLE `{$tableSafe}` MODIFY `{$colSafe}` VARCHAR({$maxSize})"
            );
            echo "OK   {$table}.{$col} — {$currentType} → VARCHAR({$maxSize})\n";
        } catch (\Throwable $e) {
            echo "FAIL {$table}.{$col} — " . $e->getMessage() . "\n";
        }
    }
}

// ─────────────────────────────────────────────────────────
// 2. Индексы
// ─────────────────────────────────────────────────────────

echo "\n=== 2. Индексы ===\n";

function x5_pwa_ensureIndex(
    \Bitrix\Main\DB\Connection $conn,
    \Bitrix\Main\DB\SqlHelper $sqlHelper,
    string $table,
    string $indexName,
    string $cols
): string {
    $safeTable = $sqlHelper->forSql($table);
    $safeIndex = $sqlHelper->forSql($indexName);

    $exists = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'")->fetch();
    if ($exists) return "SKIP {$table}.{$indexName} — уже есть";

    try {
        $conn->queryExecute("CREATE INDEX `{$safeIndex}` ON `{$safeTable}` ({$cols})");
        return "OK   {$table}.{$indexName} ({$cols})";
    } catch (\Throwable $e) {
        return "FAIL {$table}.{$indexName} — " . $e->getMessage();
    }
}

$indexes = [
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_uid',      '`UF_UID`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_device',   '`UF_DEVICE_ID`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_seen',     '`UF_LAST_SEEN_AT`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_created',  '`UF_CREATED_AT`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_theme',    '`UF_THEME`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_nick',     '`UF_NICKNAME`'],
    ['x5_pwa_uid_bindings', 'ix_x5pwa_bind_fio',      '`UF_FIO`'],

    ['x5_pwa_lecture_reads',    'ix_x5pwa_reads_uid',      '`UF_UID`'],
    ['x5_pwa_lecture_reads',    'ix_x5pwa_reads_lect',     '`UF_LECTURE_ID`'],
    ['x5_pwa_lecture_reads',    'ix_x5pwa_reads_uid_lect', '`UF_UID`, `UF_LECTURE_ID`'],

    ['x5_pwa_task_completions', 'ix_x5pwa_tasks_uid',  '`UF_UID`'],
    ['x5_pwa_task_completions', 'ix_x5pwa_tasks_task', '`UF_TASK_ID`'],

    ['x5_pwa_habit_logs', 'ix_x5pwa_habits_uid',   '`UF_UID`'],
    ['x5_pwa_habit_logs', 'ix_x5pwa_habits_habit', '`UF_HABIT_ID`'],
    ['x5_pwa_habit_logs', 'ix_x5pwa_habits_date',  '`UF_COMPLETED_AT`'],

    ['x5_pwa_push_subs', 'ix_x5pwa_subs_uid', '`UF_UID`'],

    ['x5_pwa_notif_log', 'ix_x5pwa_notif_uid', '`UF_UID`, `UF_HABIT_ID`'],

    ['x5_pwa_rate_limits', 'ix_x5pwa_rate_key', '`UF_KEY`'],
];

foreach ($indexes as [$table, $name, $cols]) {
    echo x5_pwa_ensureIndex($conn, $sqlHelper, $table, $name, $cols) . "\n";
}

// ─────────────────────────────────────────────────────────
// 3. Итог
// ─────────────────────────────────────────────────────────

echo "\n=== 3. Итог ===\n";

foreach (array_keys($targetColumns) as $t) {
    $tSafe = $sqlHelper->forSql($t);
    $exists = $conn->query("SHOW TABLES LIKE '{$tSafe}'")->fetch();
    if (!$exists) { echo "{$t}: НЕТ\n"; continue; }

    $n = (int)$conn->query("SELECT COUNT(*) AS C FROM `{$tSafe}`")->fetch()['C'];
    $idx = $conn->query("SHOW INDEX FROM `{$tSafe}`")->fetchAll();
    $names = [];
    foreach ($idx as $row) $names[$row['Key_name']] = true;

    echo "{$t}: rows={$n}, indexes=" . count($names) . "\n";
}

echo "\nГотово.\n";
echo "После проверки удалите файл:\n";
echo "  rm " . __FILE__ . "\n";