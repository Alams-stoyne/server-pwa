<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;

if (!$USER->IsAdmin()) die('admin only');

Loader::includeModule('x5.pwa');
Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "=== DIAG WORKPLACES ===\n\n";

// ─── 1. baseUrl: что вернёт каждый источник ───
echo "1) Источники для baseUrl:\n";

echo "   Option::get('main', 'server_name') = '"
    . htmlspecialcharsbx(Option::get('main', 'server_name', '')) . "'\n";

try {
    $rs = SiteTable::getList([
        'select' => ['LID', 'SERVER_NAME', 'DIR'],
        'order'  => ['DEF' => 'DESC', 'SORT' => 'ASC'],
        'limit'  => 5,
    ]);
    while ($s = $rs->fetch()) {
        echo "   SiteTable: LID={$s['LID']} SERVER_NAME='"
            . htmlspecialcharsbx((string)($s['SERVER_NAME'] ?? '')) . "'\n";
    }
} catch (\Throwable $e) {
    echo "   SiteTable FAIL: " . $e->getMessage() . "\n";
}

echo "   \$_SERVER['HTTP_HOST'] = '"
    . htmlspecialcharsbx((string)($_SERVER['HTTP_HOST'] ?? '')) . "'\n";

echo "   \$_SERVER['SERVER_NAME'] = '"
    . htmlspecialcharsbx((string)($_SERVER['SERVER_NAME'] ?? '')) . "'\n";

echo "   PHP_SAPI = '" . PHP_SAPI . "'\n";
echo "   Settings::cdnBase() = '" . htmlspecialcharsbx(\X5\Pwa\Settings::cdnBase()) . "'\n\n";

// ─── 2. ИБ workplaces ───
$ibId = (int)\X5\Pwa\Settings::iblockId('workplaces');
echo "2) ИБ workplaces: ID={$ibId}\n\n";

if ($ibId <= 0) {
    echo "❌ ИБ не зарегистрирован в настройках модуля.\n</pre>";
    exit;
}

// ─── 3. Свойство LOGO ───
$prop = \CIBlockProperty::GetList([], [
    'IBLOCK_ID' => $ibId,
    'CODE'      => 'LOGO',
])->Fetch();

if (!$prop) {
    echo "❌ Свойства LOGO нет в ИБ {$ibId}. Надо нажать «Перенастроить инфоблоки» в настройках модуля.\n</pre>";
    exit;
}

echo "3) Свойство LOGO:\n";
echo "   ID={$prop['ID']} TYPE={$prop['PROPERTY_TYPE']} MULTIPLE={$prop['MULTIPLE']} ACTIVE={$prop['ACTIVE']}\n\n";

// ─── 4. Все элементы workplaces ───
echo "4) Элементы ИБ:\n";

$rs = \CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
    false, false,
    ['ID', 'NAME', 'CODE', 'XML_ID', 'ACTIVE']
);

$elements = [];
while ($el = $rs->Fetch()) {
    $elements[] = $el;
    echo "   #{$el['ID']} CODE='{$el['CODE']}' NAME='{$el['NAME']}' ACTIVE='{$el['ACTIVE']}'\n";
}
echo "\n";

if (!$elements) {
    echo "❌ Элементов нет.\n</pre>";
    exit;
}

// ─── 5. Сырые значения LOGO через CIBlockElement::GetProperty ───
echo "5) LOGO через CIBlockElement::GetProperty:\n";

foreach ($elements as $el) {
    $elId = (int)$el['ID'];

    $propRs = \CIBlockElement::GetProperty($ibId, $elId, [], ['CODE' => 'LOGO']);
    $p = $propRs->Fetch();

    $rawValue = $p ? $p['VALUE'] : null;
    echo "   #{$elId} ({$el['CODE']}): VALUE=" . var_export($rawValue, true)
        . " (type=" . gettype($rawValue) . ")\n";
}
echo "\n";

// ─── 6. Значения через IbReader::props ───
echo "6) LOGO через IbReader::props:\n";

foreach ($elements as $el) {
    $elId  = (int)$el['ID'];
    $props = \X5\Pwa\IbReader::props($ibId, $elId);

    $logo = $props['LOGO'] ?? null;
    echo "   #{$elId} ({$el['CODE']}): " . json_encode($logo, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n";

// ─── 7. Файлы: существуют ли физически? ───
echo "7) Файлы LOGO:\n";

foreach ($elements as $el) {
    $elId  = (int)$el['ID'];
    $props = \X5\Pwa\IbReader::props($ibId, $elId);

    $v = $props['LOGO']['VALUE'] ?? null;
    if (is_array($v)) $v = reset($v);
    $fileId = (int)$v;

    if ($fileId <= 0) {
        echo "   #{$elId}: LOGO пуст в БД\n";
        continue;
    }

    $fileRow = \CFile::GetByID($fileId)->Fetch();
    if (!$fileRow) {
        echo "   #{$elId}: file #{$fileId} — записи b_file НЕТ\n";
        continue;
    }

    $fileName = (string)($fileRow['FILE_NAME'] ?? '');
    $subdir   = (string)($fileRow['SUBDIR'] ?? '');
    $absPath  = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $subdir . '/' . $fileName;

    $exists   = is_file($absPath) ? 'YES' : 'NO';
    $size     = is_file($absPath) ? filesize($absPath) : 0;

    $getPath = \CFile::GetPath($fileId);
    $getPathStr = is_string($getPath) && $getPath !== '' ? $getPath : '(пусто)';

    echo "   #{$elId}: file #{$fileId} name='{$fileName}' subdir='{$subdir}' "
        . "exists_on_disk={$exists} size={$size} CFile::GetPath='{$getPathStr}'\n";
}
echo "\n";

// ─── 8. Что вернёт WorkplacesBuilder::getAll() ───
echo "8) WorkplacesBuilder::getAll():\n";
\X5\Pwa\WorkplacesBuilder::resetCache();
$all = \X5\Pwa\WorkplacesBuilder::getAll();
foreach ($all as $wp) {
    echo "   " . json_encode($wp, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n</pre>";