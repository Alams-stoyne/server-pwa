<?php
/**
 * Экспорт обращений техподдержки в CSV.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\SupportBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

function x5supE($v): string { return trim((string)$v); }

$userQuery = x5supE($_GET['USER'] ?? '');
$status    = x5supE($_GET['STATUS'] ?? '');
$source    = x5supE($_GET['SOURCE'] ?? '');
$dateFrom  = x5supE($_GET['CREATED_AT_from'] ?? '');
$dateTo    = x5supE($_GET['CREATED_AT_to'] ?? '');
$updFrom   = x5supE($_GET['UPDATED_AT_from'] ?? '');
$updTo     = x5supE($_GET['UPDATED_AT_to'] ?? '');
$by        = x5supE($_GET['by'] ?? 'UF_UPDATED_AT');
$order     = x5supE($_GET['order'] ?? 'DESC');

$data = SupportBuilder::listForAdmin([
    'user' => $userQuery, 'status' => $status, 'source' => $source,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
    'upd_from' => $updFrom, 'upd_to' => $updTo,
    'sort' => $by, 'sortDir' => $order,
    'noPaging' => true, 'perPage' => 0,
]);

$filename = 'support_' . date('Ymd_His') . '.csv';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// BOM для Excel
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'ID', 'Источник', 'Статус', 'UID', 'Device ID', 'AUTH_ERROR',
    'Никнейм', 'ФИО', 'Место работы',
    'Тема', 'Сообщений', 'Создано', 'Обновлено', 'Закрыто',
], ';');

$statusTitle = [
    SupportBuilder::ST_NEW      => 'Ждёт ответа',
    SupportBuilder::ST_ANSWERED => 'Отвечено',
    SupportBuilder::ST_CLOSED   => 'Закрыто',
];
$sourceTitle = [
    SupportBuilder::SRC_APP   => 'Пользователь',
    SupportBuilder::SRC_GUEST => 'Гость',
];

$fmt = function ($ms) { return $ms > 0 ? date('d.m.Y H:i', (int)($ms / 1000)) : ''; };

foreach ($data['rows'] as $t) {
    fputcsv($out, [
        (int)$t['ID'],
        $sourceTitle[$t['SOURCE']] ?? (string)$t['SOURCE'],
        $statusTitle[$t['STATUS']] ?? (string)$t['STATUS'],
        (string)$t['UID'],
        (string)$t['DEVICE_ID'],
        (string)$t['AUTH_ERROR'],
        (string)$t['NICKNAME'],
        (string)$t['FIO'],
        (string)$t['WORKPLACE'],
        (string)$t['SUBJECT'],
        (int)$t['MESSAGES_COUNT'],
        $fmt((int)$t['CREATED_AT']),
        $fmt((int)$t['UPDATED_AT']),
        $fmt((int)$t['CLOSED_AT']),
    ], ';');
}

fclose($out);
exit;