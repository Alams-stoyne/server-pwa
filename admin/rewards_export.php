<?php
/**
 * Экспорт истории наград в CSV.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\RewardsBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

function x5rwE($v): string { return trim((string)$v); }

$uidQuery    = x5rwE($_GET['UID']             ?? '');
$type        = x5rwE($_GET['TYPE']            ?? '');
$dateFrom    = x5rwE($_GET['LOCAL_DATE_from'] ?? '');
$dateTo      = x5rwE($_GET['LOCAL_DATE_to']   ?? '');
$createdFrom = x5rwE($_GET['CREATED_AT_from'] ?? '');
$createdTo   = x5rwE($_GET['CREATED_AT_to']   ?? '');
$by          = x5rwE($_GET['by']              ?? 'UF_CREATED_AT');
$order       = x5rwE($_GET['order']           ?? 'DESC');

$dc = \X5\Pwa\Storage::dataClass('X5PwaRewardLog');

$filter = [];
if ($uidQuery !== '') $filter['%UF_UID'] = '%' . $uidQuery . '%';
if ($type !== '' && in_array($type, RewardsBuilder::ALL_TYPES, true)) {
    $filter['=UF_ACTION_TYPE'] = $type;
}
if ($dateFrom !== '') $filter['>=UF_LOCAL_DATE'] = $dateFrom;
if ($dateTo   !== '') $filter['<=UF_LOCAL_DATE'] = $dateTo;
if ($createdFrom !== '') {
    try { $filter['>=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdFrom . ' 00:00:00'); } catch (\Throwable $e) {}
}
if ($createdTo !== '') {
    try { $filter['<=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdTo . ' 23:59:59'); } catch (\Throwable $e) {}
}

$sortMap = [
    'ID'            => 'ID',
    'UF_UID'        => 'UF_UID',
    'UF_ACTION_TYPE'=> 'UF_ACTION_TYPE',
    'UF_POINTS'     => 'UF_POINTS',
    'UF_LOCAL_DATE' => 'UF_LOCAL_DATE',
    'UF_CREATED_AT' => 'UF_CREATED_AT',
];
$sortKey = $sortMap[$by] ?? 'UF_CREATED_AT';
$sortDir = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

$rows = $dc::getList([
    'filter' => $filter,
    'order'  => [$sortKey => $sortDir, 'ID' => 'DESC'],
])->fetchAll();

$filename = 'rewards_' . date('Ymd_His') . '.csv';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'ID', 'UID', 'Тип', 'Действие (ID)', 'Заголовок', 'Дата (локальная)', 'Баллы', 'Начислено',
], ';');

foreach ($rows as $r) {
    fputcsv($out, [
        (int)($r['ID'] ?? 0),
        (string)($r['UF_UID'] ?? ''),
        RewardsBuilder::typeTitle((string)($r['UF_ACTION_TYPE'] ?? '')),
        (string)($r['UF_ACTION_ID'] ?? ''),
        (string)($r['UF_TITLE'] ?? ''),
        (string)($r['UF_LOCAL_DATE'] ?? ''),
        (int)($r['UF_POINTS'] ?? 0),
        !empty($r['UF_CREATED_AT']) ? $r['UF_CREATED_AT']->format('d.m.Y H:i:s') : '',
    ], ';');
}

fclose($out);
exit;