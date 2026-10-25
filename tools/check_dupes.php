<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Loader;
Loader::includeModule('x5.pwa');
Loader::includeModule('iblock');

$ibKeys = [
    'ib_tasks'             => 'Задания',
    'ib_theme_lectures'    => 'Лекции по направлениям',
    'ib_general_lectures'  => 'Общие лекции',
    'ib_charging'          => 'Зарядка',
];

echo '<pre style="font:13px monospace;background:#fff;padding:16px">';
echo "Проверка дублей CODE в ИБ модуля x5.pwa\n\n";

foreach ($ibKeys as $optKey => $label) {
    $ibId = (int)\Bitrix\Main\Config\Option::get('x5.pwa', $optKey, 0);
    echo "=== {$label} (IBLOCK_ID = {$ibId}) ===\n";
    if ($ibId <= 0) { echo "  ИБ не настроен\n\n"; continue; }

    $rows = [];
    $rs = \CIBlockElement::GetList(
        ['CODE' => 'ASC'],
        ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
        false, false,
        ['ID', 'CODE', 'NAME']
    );
    while ($el = $rs->Fetch()) {
        $code = trim((string)($el['CODE'] ?? ''));
        if ($code === '') continue;
        $rows[$code][] = (int)$el['ID'];
    }

    $dupes = array_filter($rows, fn($ids) => count($ids) > 1);
    if (!$dupes) {
        echo "  ✔ дублей нет\n\n";
        continue;
    }
    echo "  ✖ найдены дубли:\n";
    foreach ($dupes as $code => $ids) {
        echo "    CODE='{$code}' → ID: " . implode(', ', $ids) . "\n";
    }
    echo "\n";
}

echo "</pre>";