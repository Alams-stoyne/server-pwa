<?php
/**
 * Диагностика лекций по направлениям.
 *
 * Запуск:
 *   https://api.x5hd.ru/local/modules/x5.pwa/tools/diag_lectures.php
 *
 * После проверки удалить.
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
\Bitrix\Main\Loader::includeModule('iblock');

echo "=== 1. IB «Направления» ===\n";
$themesIb = \X5\Pwa\Settings::iblockId('themes');
echo "IB_ID = {$themesIb}\n";

$themesInfo = [];
if ($themesIb) {
    $rs = \CIBlockElement::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $themesIb, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'NAME', 'CODE', 'XML_ID', 'ACTIVE']
    );
    while ($el = $rs->Fetch()) {
        printf("  ID=%s  CODE=%s  XML_ID=%s  ACTIVE=%s  NAME=%s\n",
            $el['ID'],
            $el['CODE'] ?: '(пусто)',
            $el['XML_ID'] ?: '(пусто)',
            $el['ACTIVE'],
            $el['NAME']
        );
        $themesInfo[(string)$el['ID']] = $el;
    }
}
echo "\n";

echo "=== 2. ThemesResolver::getAllCodes() ===\n";
$codes = \X5\Pwa\ThemesResolver::getAllCodes();
echo "  " . json_encode($codes, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== 3. Свойство THEME в IB «Лекции по направлениям» ===\n";
$lecturesIb = \X5\Pwa\Settings::iblockId('theme_lectures');
echo "IB_ID = {$lecturesIb}\n";

$prop = \CIBlockProperty::GetList([], [
    'IBLOCK_ID' => $lecturesIb,
    'CODE'      => 'THEME',
])->Fetch();
if ($prop) {
    printf("  PROPERTY_TYPE=%s  USER_TYPE=%s  LINK_IBLOCK_ID=%s  MULTIPLE=%s\n",
        $prop['PROPERTY_TYPE'],
        $prop['USER_TYPE'] ?: '(нет)',
        $prop['LINK_IBLOCK_ID'] ?? '0',
        $prop['MULTIPLE']
    );
    if ($prop['PROPERTY_TYPE'] === 'E' && (int)$prop['LINK_IBLOCK_ID'] !== (int)$themesIb) {
        echo "  ВНИМАНИЕ: LINK_IBLOCK_ID не указывает на IB тем!\n";
    }
    if ($prop['PROPERTY_TYPE'] === 'L') {
        echo "  ВНИМАНИЕ: свойство всё ещё L-тип. Нужно E.\n";
    }
} else {
    echo "  Свойство THEME НЕ НАЙДЕНО\n";
}
echo "\n";

echo "=== 4. Лекции и их THEME ===\n";
if ($lecturesIb) {
    $rs = \CIBlockElement::GetList(
        ['SORT' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $lecturesIb, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'NAME', 'CODE', 'ACTIVE_FROM']
    );
    while ($el = $rs->Fetch()) {
        $props = \X5\Pwa\IbReader::props($lecturesIb, (int)$el['ID']);
        $themeProp = $props['THEME'] ?? null;

        $raw = null;
        $xmlId = null;
        $enum = null;
        if (is_array($themeProp)) {
            $raw = $themeProp['VALUE'] ?? null;
            $xmlId = $themeProp['VALUE_XML_ID'] ?? null;
            $enum = $themeProp['VALUE_ENUM'] ?? null;
        }

        $resolved = \X5\Pwa\ContentBuilder::_debugExtractTheme($lecturesIb, (int)$el['ID']);

        printf("  ID=%s  NAME=%s\n", $el['ID'], mb_substr($el['NAME'], 0, 40));
        printf("    ACTIVE_FROM=%s\n", $el['ACTIVE_FROM'] ?: '(пусто)');
        printf("    THEME.VALUE=%s  XML_ID=%s  ENUM=%s\n",
            var_export($raw, true),
            var_export($xmlId, true),
            var_export($enum, true)
        );
        printf("    extractTheme() => %s\n",
            $resolved === null ? 'NULL' : $resolved
        );
        if ($resolved !== null && !in_array($resolved, $codes, true)) {
            echo "    ВНИМАНИЕ: resolved не входит в getAllCodes() — эта лекция не попадёт в дампы\n";
        }
        echo "\n";
    }
}

echo "=== 5. Сводка ===\n";
echo "  Тем в IB: " . count($themesInfo) . "\n";
echo "  Кодов в getAllCodes: " . count($codes) . "\n";
echo "\nУдалите файл после диагностики:\n  rm " . __FILE__ . "\n";