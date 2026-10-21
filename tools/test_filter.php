<?php
/**
 * Диагностика фильтра поиска в HL-блоке X5PwaUidBindings.
 * Показывает, что реально строит ORM при разных вариантах фильтра.
 *
 * Запуск: под админом,
 *   https://api.x5hd.ru/local/modules/x5.pwa/tools/test_filter.php
 * После диагностики удалить.
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
\Bitrix\Main\Loader::includeModule('highloadblock');

use Bitrix\Main\Application;

$conn = Application::getConnection();
$dc   = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');

$search = 'Root';

echo "=== 1. Прямой SQL LIKE ===\n";
$s = '%' . $conn->getSqlHelper()->forSql($search) . '%';
$sql = "SELECT ID, UF_UID, UF_FIO, UF_NICKNAME, UF_WORKPLACE
        FROM x5_pwa_uid_bindings
        WHERE UF_FIO LIKE '{$s}'
           OR UF_NICKNAME LIKE '{$s}'
           OR UF_WORKPLACE LIKE '{$s}'
           OR UF_UID LIKE '{$s}'";
echo "SQL: {$sql}\n";
$res = $conn->query($sql);
$n = 0;
while ($row = $res->fetch()) {
    $n++;
    echo "  #{$row['ID']} UID=" . substr($row['UF_UID'], 0, 12) . "… "
       . "FIO=\"{$row['UF_FIO']}\" NICK=\"{$row['UF_NICKNAME']}\" WORK=\"{$row['UF_WORKPLACE']}\"\n";
}
echo "Найдено: {$n}\n\n";

echo "=== 2. ORM: OR-группа верхним уровнем (вариант A) ===\n";
$filterA = [
    'LOGIC' => 'OR',
    '%UF_UID'        => '%' . $search . '%',
    '%UF_FIO'        => '%' . $search . '%',
    '%UF_NICKNAME'   => '%' . $search . '%',
    '%UF_WORKPLACE'  => '%' . $search . '%',
];
echo "filter = " . json_encode($filterA, JSON_UNESCAPED_UNICODE) . "\n";
try {
    $rows = $dc::getList(['filter' => $filterA, 'limit' => 10])->fetchAll();
    echo "Найдено: " . count($rows) . "\n";
} catch (\Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== 3. ORM: OR-группа вложенным элементом (вариант B) ===\n";
$filterB = [
    [
        'LOGIC' => 'OR',
        '%UF_UID'        => '%' . $search . '%',
        '%UF_FIO'        => '%' . $search . '%',
        '%UF_NICKNAME'   => '%' . $search . '%',
        '%UF_WORKPLACE'  => '%' . $search . '%',
    ],
];
echo "filter = " . json_encode($filterB, JSON_UNESCAPED_UNICODE) . "\n";
try {
    $rows = $dc::getList(['filter' => $filterB, 'limit' => 10])->fetchAll();
    echo "Найдено: " . count($rows) . "\n";
} catch (\Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== 4. ORM: OR-группа + AND-условия (вариант C — как в listUidBindings) ===\n";
$filterC = [
    [
        'LOGIC' => 'OR',
        '%UF_UID'        => '%' . $search . '%',
        '%UF_FIO'        => '%' . $search . '%',
        '%UF_NICKNAME'   => '%' . $search . '%',
        '%UF_WORKPLACE'  => '%' . $search . '%',
    ],
];
// Пробуем добавить ещё одно AND-условие, которого нет у нас в БД (пустая тема)
// чтобы проверить, склеивается ли всё в одно целое
$filterC['=UF_THEME'] = ''; // пустая строка = должен отсечь всех
echo "filter = " . json_encode($filterC, JSON_UNESCAPED_UNICODE) . "\n";
try {
    $rows = $dc::getList(['filter' => $filterC, 'limit' => 10])->fetchAll();
    echo "Найдено: " . count($rows) . " (ожидается 0, потому что UF_THEME='' ни у кого нет)\n";
} catch (\Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== 5. ORM: поиск по одному полю (контроль) ===\n";
$filterD = ['%UF_FIO' => '%' . $search . '%'];
echo "filter = " . json_encode($filterD, JSON_UNESCAPED_UNICODE) . "\n";
try {
    $rows = $dc::getList(['filter' => $filterD, 'limit' => 10])->fetchAll();
    echo "Найдено: " . count($rows) . "\n";
    foreach ($rows as $row) {
        echo "  #{$row['ID']} FIO=\"{$row['UF_FIO']}\"\n";
    }
} catch (\Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}