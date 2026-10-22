<?php
// /local/diag_events.php  →  открыть в браузере: https://x5hd.ru/local/diag_events.php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Loader;
use X5\Pwa\Settings;
use X5\Pwa\IbReader;
use X5\Pwa\EventsBuilder;

Loader::includeModule('x5.pwa');
Loader::includeModule('iblock');
Loader::includeModule('highloadblock');

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";

// 1) ID из Settings
$ibId = (int)Settings::iblockId('events');
echo "1) Settings::iblockId('events') = {$ibId}\n";

if ($ibId <= 0) {
    echo "\n⛔ ИБ events не зарегистрирован в настройках модуля.\n";
    echo "Решение: зайти в админку модуля (или дернуть /bitrix/admin/x5_pwa_users.php) —\n";
    echo "include.php увидит ib_events=0 и вызовет IblockSetup::install().\n";
    echo "После этого проверить снова.\n";
    echo "</pre>";
    exit;
}

// 2) Сам ИБ
$ib = \CIBlock::GetList([], ['ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
echo "2) ИБ: ID={$ib['ID']}, CODE={$ib['CODE']}, NAME={$ib['NAME']}, ACTIVE={$ib['ACTIVE']}\n";

// 3) Все элементы этого ИБ (без фильтра ACTIVE)
$rs = \CIBlockElement::GetList(
    ['ID' => 'DESC'],
    ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    ['ID', 'CODE', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'DATE_ACTIVE_FROM']
);
$rawElements = [];
while ($el = $rs->Fetch()) {
    $rawElements[] = $el;
}
echo "3) Всего элементов в ИБ (без фильтра): " . count($rawElements) . "\n";
foreach ($rawElements as $el) {
    echo "   ─ ID={$el['ID']} CODE='{$el['CODE']}' ACTIVE='{$el['ACTIVE']}' ACTIVE_FROM='{$el['ACTIVE_FROM']}' DATE_ACTIVE_FROM='{$el['DATE_ACTIVE_FROM']}' NAME='{$el['NAME']}'\n";
}

// 4) Что возвращает IbReader::elements() (тот же фильтр, что использует EventsBuilder)
$elements = IbReader::elements($ibId);
echo "4) IbReader::elements() вернул: " . count($elements) . "\n";
if (!empty($elements)) {
    echo "   Первый элемент:\n";
    print_r($elements[0]);
}

// 5) Что возвращает EventsBuilder::getAll()
echo "5) EventsBuilder::getAll() = \n";
print_r(EventsBuilder::getAll());

echo "\n6) Проверка extractDateFromFields вручную:\n";
if (!empty($rawElements)) {
    $el = $rawElements[0];
    $raw = trim((string)($el['ACTIVE_FROM'] ?? ''));
    echo "   ACTIVE_FROM = '{$raw}'\n";
    echo "   MakeTimeStamp = " . \MakeTimeStamp($raw, \FORMAT_DATETIME) . "\n";
    echo "   strtotime     = " . strtotime($raw) . "\n";
    echo "   FORMAT_DATETIME (из настроек сайта) = '" . \FORMAT_DATETIME . "'\n";
}

// 7) Что возвращает getCdnDates() и getForCdn на сегодня
echo "\n7) getCdnDates() первых 5 = \n";
print_r(array_slice(EventsBuilder::getCdnDates(), 0, 5));

echo "\n8) getForCdn('" . date('Y-m-d') . "') = \n";
print_r(EventsBuilder::getForCdn(date('Y-m-d')));

echo "</pre>";