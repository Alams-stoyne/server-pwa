<?php
/**
 * Миграция outbox. Добавляет UF_REWARD_STATE в таблицы completions/reads.
 * Одноразово. После применения можно удалить.
 *
 * ?apply=Y — применить. Без параметра — dry-run.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('x5.pwa');

$apply  = (string)($_GET['apply'] ?? '') === 'Y';
$tables = ['x5_pwa_task_completions', 'x5_pwa_lecture_reads'];

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

echo '<pre style="font:12px monospace;background:#fff;padding:16px;line-height:1.5">';
echo "=== Outbox migration ===\n";
echo "mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

foreach ($tables as $t) {
    echo "── {$t} ──\n";
    $tSafe = $sqlHelper->forSql($t);

    $exists = $conn->query("SHOW TABLES LIKE '{$tSafe}'")->fetch();
    if (!$exists) {
        echo "  ⚠ таблицы нет, пропуск\n\n";
        continue;
    }

    $col = $conn->query("SHOW COLUMNS FROM `{$tSafe}` LIKE 'UF_REWARD_STATE'")->fetch();
    if ($col) {
        echo "  ✔ колонка UF_REWARD_STATE уже есть (Type={$col['Type']}, Default=" . ($col['Default'] ?? 'NULL') . ")\n";
    } else {
        echo "  → ADD COLUMN UF_REWARD_STATE VARCHAR(16) NOT NULL DEFAULT 'pending'\n";
        if ($apply) {
            try {
                $conn->queryExecute(
                    "ALTER TABLE `{$tSafe}` "
                    . "ADD COLUMN UF_REWARD_STATE VARCHAR(16) NOT NULL DEFAULT 'pending'"
                );
                echo "    ✔ добавлено (все существующие записи → pending)\n";
            } catch (\Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column') !== false) {
                    echo "    ✔ уже существует (race)\n";
                } else {
                    echo "    ✖ ошибка: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    $idx = $conn->query(
        "SHOW INDEX FROM `{$tSafe}` WHERE Key_name = 'idx_reward_state'"
    )->fetch();
    if ($idx) {
        echo "  ✔ индекс idx_reward_state есть\n";
    } else {
        echo "  → ADD INDEX idx_reward_state (UF_REWARD_STATE, ID)\n";
        if ($apply) {
            try {
                $conn->queryExecute(
                    "ALTER TABLE `{$tSafe}` "
                    . "ADD INDEX idx_reward_state (UF_REWARD_STATE, ID)"
                );
                echo "    ✔ добавлено\n";
            } catch (\Throwable $e) {
                echo "    ✖ ошибка: " . $e->getMessage() . "\n";
            }
        }
    }

    if ($apply) {
        try {
            $cnt = $conn->query(
                "SELECT COUNT(*) AS C FROM `{$tSafe}` WHERE UF_REWARD_STATE = 'pending'"
            )->fetch();
            echo "  pending записей: " . (int)($cnt['C'] ?? 0) . "\n";
        } catch (\Throwable $e) {
            echo "  pending: ошибка подсчёта — " . $e->getMessage() . "\n";
        }
    }

    echo "\n";
}

if (!$apply) {
    echo "Запустите с ?apply=Y чтобы применить.\n";
    echo "\nПосле миграции:\n";
    echo "  1) tools/register_outbox_agent.php       — регистрация агента (раз в 5 минут)\n";
    echo "  2) admin/outbox.php                       — монитор и ручной запуск\n";
    echo "  3) (опционально) пересохраните HL-блоки в админке,\n";
    echo "     если хотите видеть поле UF_REWARD_STATE в списках HL.\n";
}
echo "</pre>";