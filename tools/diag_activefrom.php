<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (!$USER->IsAdmin()) die('admin only');

Loader::includeModule('x5.pwa');
Loader::includeModule('iblock');

$ibCharging = (int)\X5\Pwa\Settings::iblockId('charging');
$dateSite   = '24.10.2026';   // формат сайта SHORT

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "IB charging = {$ibCharging}\n";
echo "FORMAT_DATE (site) = '" . \CSite::GetDateFormat('SHORT') . "'\n\n";

function runFilter(int $ibId, array $filter, string $label): void
{
    $rs = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        array_merge(['IBLOCK_ID' => $ibId, 'ACTIVE' => 'Y'], $filter),
        false,
        false,
        ['ID', 'ACTIVE_FROM']
    );
    $ids = [];
    while ($el = $rs->Fetch()) {
        $ids[] = '#'.$el['ID'].' ('.$el['ACTIVE_FROM'].')';
    }
    printf("%-55s → %d  %s\n", $label, count($ids), implode(', ', $ids));
}

// 1. DATE_ACTIVE_FROM = дата в формате сайта
runFilter($ibCharging, [
    '>=DATE_ACTIVE_FROM' => $dateSite,
    '<=DATE_ACTIVE_FROM' => $dateSite,
], '1. DATE_ACTIVE_FROM (site format, диапазон)');

// 2. =DATE_ACTIVE_FROM — точное совпадение
runFilter($ibCharging, [
    '=DATE_ACTIVE_FROM' => $dateSite,
], '2. =DATE_ACTIVE_FROM (site format, точное)');

// 3. ACTIVE_DATE — встроенный фильтр активности
runFilter($ibCharging, [
    'ACTIVE_DATE' => 'Y',
], '3. ACTIVE_DATE = Y (все активные по датам)');

// 4. Для сравнения: ISO-формат с DATE_ACTIVE_FROM
runFilter($ibCharging, [
    '>=DATE_ACTIVE_FROM' => '2026-10-24',
    '<=DATE_ACTIVE_FROM' => '2026-10-24',
], '4. DATE_ACTIVE_FROM (ISO format)');

// 5. Контроль: без фильтра
runFilter($ibCharging, [], '5. Без фильтра');

echo "</pre>";