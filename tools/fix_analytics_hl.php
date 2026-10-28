<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;

Loader::includeModule('highloadblock');

$apply = (string)($_GET['apply'] ?? '') === 'Y';

echo '<pre style="font:12px monospace;background:#fff;padding:16px;line-height:1.5">';
echo "=== Fix Analytics HL Duplicates (SAFE MODE) ===\n";
echo "mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n\n";

$rs = HighloadBlockTable::getList([
    'filter' => ['=NAME' => 'X5PwaAnalyticsEvents'],
    'order'  => ['ID' => 'ASC']
]);

$blocks = [];
while ($hl = $rs->fetch()) {
    $hlId = (int)$hl['ID'];
    $table = $hl['TABLE_NAME'];
    
    $conn = \Bitrix\Main\Application::getConnection();
    $tableExists = $conn->query("SHOW TABLES LIKE '{$table}'")->fetch() ? true : false;
    
    $entityId = 'HLBLOCK_' . $hlId;
    $ufFields = [];
    $ufRs = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
    while ($uf = $ufRs->Fetch()) {
        $ufFields[] = $uf['FIELD_NAME'];
    }
    
    $blocks[] = [
        'ID' => $hlId,
        'TABLE_NAME' => $table,
        'TABLE_EXISTS' => $tableExists,
        'UF_FIELDS' => $ufFields,
    ];
}

if (empty($blocks)) {
    echo "❌ HL-блоки X5PwaAnalyticsEvents не найдены.\n</pre>";
    die();
}

echo "Найдено блоков: " . count($blocks) . "\n\n";
foreach ($blocks as $b) {
    echo "ID: {$b['ID']} | Table: {$b['TABLE_NAME']} | Exists: " . ($b['TABLE_EXISTS'] ? 'YES' : 'NO') . "\n";
    echo "  Fields (" . count($b['UF_FIELDS']) . "): " . implode(', ', $b['UF_FIELDS']) . "\n";
}

// Оставляем тот, у кого 10 полей
$toKeep = null;
$toDelete = [];
foreach ($blocks as $b) {
    if ($b['TABLE_EXISTS'] && count($b['UF_FIELDS']) >= 8) {
        if ($toKeep === null || $b['ID'] < $toKeep['ID']) {
            if ($toKeep !== null) $toDelete[] = $toKeep;
            $toKeep = $b;
        } else {
            $toDelete[] = $b;
        }
    } else {
        $toDelete[] = $b;
    }
}

if ($toKeep === null) {
    echo "\n❌ Не найдено рабочего блока (с 8+ UF-полей).\n</pre>";
    die();
}

echo "\n✅ Оставляем блок ID: {$toKeep['ID']}\n";

if (empty($toDelete)) {
    echo "Лишних блоков нет.\n</pre>";
    die();
}

echo "🗑️ Удаляем только записи HL-блоков (UF-поля НЕ трогаем):\n";
foreach ($toDelete as $b) {
    echo "  - ID: {$b['ID']} ({$b['TABLE_NAME']}, UF: " . count($b['UF_FIELDS']) . ")\n";
}

if ($apply) {
    echo "\n⚠️ SAFE MODE: удаление только записей b_hlblock_entity. Колонки в таблице остаются.\n\n";
    foreach ($toDelete as $b) {
        $hlId = $b['ID'];
        
        try {
            $res = HighloadBlockTable::delete($hlId);
            if ($res->isSuccess()) {
                echo "  ✔ Блок {$hlId} удален (UF-поля orphaned, но колонки в таблице целы).\n";
            } else {
                echo "  ✖ Ошибка {$hlId}: " . implode('; ', $res->getErrorMessages()) . "\n";
            }
        } catch (\Throwable $e) {
            echo "  ✖ Исключение {$hlId}: " . $e->getMessage() . "\n";
        }
    }
    echo "\nГотово! Дальше:\n";
    echo "1. Открой любую страницу сайта — миграция HlSetup увидит только блок {$toKeep['ID']} и не будет создавать дубли.\n";
    echo "2. Отправь POST на /sync/events — должен вернуться RESULT:OK.\n";
    echo "3. Открой PWA -> Аналитика воронки — данные должны появиться.\n";
} else {
    echo "\nЗапусти с ?apply=Y для удаления.\n";
}

echo "</pre>";