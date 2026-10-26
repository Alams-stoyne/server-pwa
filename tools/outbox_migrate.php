<?php
/**
 * Миграция outbox для всех источников наград.
 *
 * ?apply=Y — применить. Без параметра — dry-run.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('x5.pwa');

$apply = (string)($_GET['apply'] ?? '') === 'Y';

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

// Что мигрировать: table => список колонок
$targets = [
    'x5_pwa_task_completions' => ['UF_REWARD_STATE'],
    'x5_pwa_lecture_reads'    => ['UF_REWARD_STATE'],
    'x5_pwa_habit_logs'       => ['UF_REWARD_STATE'],
    'x5_pwa_reflections'      => ['UF_REWARD_STATE'],
    'x5_pwa_steps'            => ['UF_REWARD_STATE_SENT', 'UF_REWARD_STATE_APPROVED'],
    'x5_pwa_event_actions'    => ['UF_REWARD_STATE'],
];

echo '<pre style="font:12px monospace;background:#fff;padding:16px;line-height:1.5">';
echo "=== Outbox migration (full) ===\n";
echo "mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

foreach ($targets as $table => $columns) {
    echo "── {$table} ──\n";
    $tableSafe = $sqlHelper->forSql($table);

    $exists = $conn->query("SHOW TABLES LIKE '{$tableSafe}'")->fetch();
    if (!$exists) {
        echo "  ⚠ таблицы нет, пропуск\n\n";
        continue;
    }

    foreach ($columns as $col) {
        $colSafe = $sqlHelper->forSql($col);
        $c = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$colSafe}'")->fetch();

        if ($c) {
            echo "  ✔ {$col} уже есть (Type={$c['Type']}, Default=" . ($c['Default'] ?? 'NULL') . ")\n";
        } else {
            echo "  → ADD COLUMN {$col} VARCHAR(16) NOT NULL DEFAULT 'pending'\n";
            if ($apply) {
                try {
                    $conn->queryExecute(
                        "ALTER TABLE `{$tableSafe}` "
                        . "ADD COLUMN `{$colSafe}` VARCHAR(16) NOT NULL DEFAULT 'pending'"
                    );
                    echo "    ✔ добавлено\n";
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'Duplicate column') !== false) {
                        echo "    ✔ уже существует (race)\n";
                    } else {
                        echo "    ✖ ошибка: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
    }

    // Индекс для быстрого pending-выбора
    $idxName = 'idx_outbox_' . md5($table);
    $idxNameShort = substr($idxName, 0, 60);

    $idx = $conn->query(
        "SHOW INDEX FROM `{$tableSafe}` WHERE Key_name = '{$idxNameShort}'"
    )->fetch();
    if ($idx) {
        echo "  ✔ индекс {$idxNameShort} есть\n";
    } else {
        $primaryCol = $columns[0];
        $primarySafe = $sqlHelper->forSql($primaryCol);
        echo "  → ADD INDEX {$idxNameShort} ({$primaryCol}, ID)\n";
        if ($apply) {
            try {
                $conn->queryExecute(
                    "ALTER TABLE `{$tableSafe}` "
                    . "ADD INDEX `{$idxNameShort}` (`{$primarySafe}`, ID)"
                );
                echo "    ✔ добавлено\n";
            } catch (\Throwable $e) {
                echo "    ⚠ " . $e->getMessage() . "\n";
            }
        }
    }

    if ($apply) {
        try {
            $cnt = $conn->query(
                "SELECT COUNT(*) AS C FROM `{$tableSafe}` "
                . "WHERE `" . $sqlHelper->forSql($columns[0]) . "` = 'pending'"
            )->fetch();
            echo "  pending: " . (int)($cnt['C'] ?? 0) . "\n";
        } catch (\Throwable $e) {
            echo "  pending: ошибка подсчёта — " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
}

if (!$apply) {
    echo "Запустите с ?apply=Y чтобы применить.\n";
    echo "\nПосле миграции:\n";
    echo "  1) tools/register_outbox_agent.php (если ещё не зарегистрирован)\n";
    echo "  2) admin/outbox.php — увидите полную картину\n";
}
echo "</pre>";