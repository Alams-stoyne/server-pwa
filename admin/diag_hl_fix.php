<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Highloadblock\HighloadBlockTable;

if (!$USER->IsAdmin()) die('admin only');

Loader::includeModule('highloadblock');
Loader::includeModule('x5.pwa');

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "=== DIAG HL FIX (SQL) ===\n\n";

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

$hlName  = 'X5PwaEventActions';
$hlTable = 'x5_pwa_event_actions';

// ── 1. Состояние сейчас
$tableExists = (bool)$conn->query(
    "SHOW TABLES LIKE '" . $sqlHelper->forSql($hlTable) . "'"
)->fetch();

$hl = HighloadBlockTable::getList([
    'filter' => ['=NAME' => $hlName],
    'limit'  => 1,
])->fetch();
$hlId = $hl ? (int)$hl['ID'] : 0;

$ufCount = 0;
if ($hlId > 0) {
    $ufCount = (int)($conn->query(
        "SELECT COUNT(*) AS C FROM b_user_field
         WHERE ENTITY_ID = 'HLBLOCK_" . $hlId . "'"
    )->fetch()['C'] ?? 0);
}

echo "1) Таблица '{$hlTable}': " . ($tableExists ? 'есть' : 'НЕТ') . "\n";
echo "2) HL-запись '{$hlName}': " . ($hlId ? "ID={$hlId}" : 'нет') . "\n";
echo "3) UF-полей в b_user_field: {$ufCount}\n\n";

if ($tableExists && $hlId > 0) {
    echo "✅ Всё на месте — ничего делать не нужно.\n</pre>";
    exit;
}

// ── 2. Удаляем UF-поля напрямую SQL (обходим HL-обработчик)
if ($hlId > 0 && $ufCount > 0) {
    echo "Удаляю UF-поля из b_user_field напрямую SQL...\n";

    try {
        $conn->queryExecute(
            "DELETE FROM b_user_field WHERE ENTITY_ID = 'HLBLOCK_" . $hlId . "'"
        );
        echo "  ✔ b_user_field очищено ({(int)$ufCount} строк)\n";

        // Языковые подписи — если есть
        try {
            $conn->queryExecute(
                "DELETE FROM b_user_field_lang WHERE USER_FIELD_ID IN (
                    SELECT ID FROM b_user_field WHERE ENTITY_ID = 'HLBLOCK_" . $hlId . "'
                )"
            );
        } catch (\Throwable $e) {
            // Не критично — b_user_field_lang может отсутствовать или уже пуст
        }
    } catch (\Throwable $e) {
        echo "  ❌ Ошибка SQL: " . $e->getMessage() . "\n";
        echo "</pre>";
        exit;
    }
}

// ── 3. Удаляем запись из b_hlblock_entity напрямую SQL
if ($hlId > 0) {
    echo "Удаляю запись из b_hlblock_entity (ID={$hlId}) напрямую SQL...\n";
    try {
        $conn->queryExecute(
            "DELETE FROM b_hlblock_entity WHERE ID = " . $hlId
        );
        echo "  ✔ b_hlblock_entity очищено\n";
    } catch (\Throwable $e) {
        echo "  ❌ Ошибка SQL: " . $e->getMessage() . "\n";
        echo "</pre>";
        exit;
    }
}

// ── 4. Сбрасываем кэш
echo "Сбрасываю кеш Битрикса...\n";
try {
    BXClearCache(true, '/highloadblock/');
    Application::getInstance()->getManagedCache()->cleanDir('highloadblock');
    echo "  ✔ кеш очищен\n";
} catch (\Throwable $e) {
    echo "  ⚠️  кеш не очистился: " . $e->getMessage() . " (не критично)\n";
}

// ── 5. Сбрасываем версию миграции
\X5\Pwa\Settings::set('hl_setup_version', '0');
echo "\nhl_setup_version сброшено в 0\n\n";

// ── 6. Пересоздаём всё заново
echo "Запускаю HlSetup::install()...\n";
try {
    \X5\Pwa\HlSetup::install();
    echo "  ✔ HlSetup::install() — OK\n\n";

    // ── 7. Проверяем результат
    $nowTable = (bool)$conn->query(
        "SHOW TABLES LIKE '" . $sqlHelper->forSql($hlTable) . "'"
    )->fetch();

    $hlNew = HighloadBlockTable::getList([
        'filter' => ['=NAME' => $hlName],
        'limit'  => 1,
    ])->fetch();
    $hlIdNew = $hlNew ? (int)$hlNew['ID'] : 0;

    $ufNew = $hlIdNew > 0 ? (int)($conn->query(
        "SELECT COUNT(*) AS C FROM b_user_field
         WHERE ENTITY_ID = 'HLBLOCK_" . $hlIdNew . "'"
    )->fetch()['C'] ?? 0) : 0;

    echo "Итог:\n";
    echo "  Таблица '{$hlTable}': " . ($nowTable ? '✅ есть' : '❌ НЕТ') . "\n";
    echo "  HL-запись: " . ($hlIdNew ? "✅ ID={$hlIdNew}" : '❌ нет') . "\n";
    echo "  UF-полей: {$ufNew}\n";

    // Проверка индексов
    $indexes = $conn->query(
        "SHOW INDEX FROM `" . $sqlHelper->forSql($hlTable) . "`"
    )->fetchAll();
    $indexNames = [];
    foreach ($indexes as $idx) {
        $indexNames[$idx['Key_name']] = true;
    }
    echo "  Индексов: " . count($indexNames) . "\n";
    echo "  Индексы: " . implode(', ', array_keys($indexNames)) . "\n";

    if ($nowTable && $hlIdNew > 0 && $ufNew >= 9) {
        \X5\Pwa\Settings::set('hl_setup_version', (string)\X5\Pwa\HlSetup::currentVersion());
        echo "\n✅ Всё пересоздано успешно. hl_setup_version = "
            . \X5\Pwa\HlSetup::currentVersion() . "\n";
    } else {
        echo "\n⚠️ Пересоздание прошло, но что-то не сошлось — смотрите цифры выше.\n";
    }
} catch (\Throwable $e) {
    echo "  ❌ HlSetup::install() УПАЛ:\n";
    echo "     " . $e->getMessage() . "\n\n";
    echo "Смотрите /x5.pwa.log.\n";
    echo "Если ошибка 'Specified key was too long; max key length is 767 bytes' —\n";
    echo "пришлите лог, поправлю индексы на префиксные в HlSetup::ensureIndexes.\n";
}

echo "</pre>";