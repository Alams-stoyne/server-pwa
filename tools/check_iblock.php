<?php
/**
 * Диагностика свойств инфоблоков модуля x5.pwa.
 * Запуск: под админом в браузере или через CLI.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin()) { http_response_code(403); die('Access denied'); }
    header('Content-Type: text/plain; charset=utf-8');
}

Loader::includeModule('x5.pwa');
Loader::includeModule('iblock');

$ibKeys = [
    'common_articles'  => 'Общие статьи',
    'useful_articles'  => 'Полезные материалы',
    'theme_articles'   => 'Статьи по направлениям',
    'general_lectures' => 'Общие лекции',
    'charging'         => 'Зарядка',
    'theme_lectures'   => 'Лекции по направлениям',
];

foreach ($ibKeys as $key => $label) {
    $ibId = \X5\Pwa\Settings::iblockId($key);
    echo "=== {$label} ({$key}) IB_ID={$ibId} ===\n";

    if ($ibId <= 0) {
        echo "  !! IB_ID = 0 — инфоблок не задан\n\n";
        continue;
    }

    // Все свойства инфоблока
    $rs = \CIBlockProperty::GetList(
        ['SORT' => 'ASC'],
        ['IBLOCK_ID' => $ibId]
    );
    $found = 0;
    while ($p = $rs->Fetch()) {
        $found++;
        printf(
            "  CODE=%-15s NAME=%-25s TYPE=%-2s MULTIPLE=%s ACTIVE=%s\n",
            $p['CODE'], mb_substr((string)$p['NAME'], 0, 25),
            $p['PROPERTY_TYPE'], $p['MULTIPLE'], $p['ACTIVE']
        );
    }
    if (!$found) echo "  (нет свойств вообще)\n";

    // Элементы и их значения свойств
    $rs = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 5],
        ['ID', 'NAME']
    );
    while ($el = $rs->Fetch()) {
        echo "  element ID={$el['ID']} NAME=" . mb_substr((string)$el['NAME'], 0, 40) . "\n";
        $rsProp = \CIBlockElement::GetProperty($ibId, (int)$el['ID']);
        $hasValues = false;
        while ($pr = $rsProp->Fetch()) {
            $v = $pr['VALUE'];
            if (is_array($v)) $v = 'ARRAY(' . count($v) . ')';
            echo "     PROP CODE={$pr['CODE']} VALUE=" . json_encode($v, JSON_UNESCAPED_UNICODE) . "\n";
            $hasValues = true;
        }
        if (!$hasValues) echo "     (нет значений свойств)\n";
    }
    echo "\n";
}

echo "ib_setup_done = " . \Bitrix\Main\Config\Option::get('x5.pwa', 'ib_setup_done', 'N') . "\n";