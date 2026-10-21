<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin()) { http_response_code(403); die('Access denied'); }
    header('Content-Type: text/plain; charset=utf-8');
}

\Bitrix\Main\Loader::includeModule('x5.pwa');
\Bitrix\Main\Loader::includeModule('highloadblock');

$dc = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');

// Проверяем, что ORM знает про поля
$fields = $dc::getEntity()->getFields();
echo "Поля, известные ORM:\n";
foreach ($fields as $name => $field) {
    echo "  {$name}: " . get_class($field);
    if (method_exists($field, 'getDataType')) {
        echo " (" . $field->getDataType() . ")";
    }
    echo "\n";
}

echo "\nПробуем LIKE по UF_FIO:\n";
$rows = $dc::getList([
    'select' => ['ID', 'UF_FIO'],
    'filter' => ['UF_FIO' => '%Root%'],
    'limit'  => 5,
])->fetchAll();
echo "Найдено: " . count($rows) . "\n";
foreach ($rows as $r) echo "  ID={$r['ID']} FIO={$r['UF_FIO']}\n";

echo "\nПробуем OR-группу:\n";
$filter = [[
    'LOGIC'         => 'OR',
    'UF_UID'       => '%Root%',
    'UF_FIO'       => '%Root%',
    'UF_NICKNAME'  => '%Root%',
    'UF_WORKPLACE' => '%Root%',
]];
$q = $dc::query()->setSelect(['ID'])->setFilter($filter)->setLimit(5);
echo "SQL: " . $q->getQuery() . "\n";

$rows = $dc::getList([
    'select' => ['ID', 'UF_FIO', 'UF_NICKNAME'],
    'filter' => $filter,
    'limit'  => 5,
])->fetchAll();
echo "Найдено: " . count($rows) . "\n";
foreach ($rows as $r) echo "  ID={$r['ID']} FIO={$r['UF_FIO']}\n";