<?php
/**
 * Экспорт списка пользователей PWA в CSV.
 * Учитывает те же фильтры, что и x5_pwa_users.php (кроме пагинации).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

// Параметры фильтра
$params = [
    'search'     => trim((string)($_GET['q'] ?? '')),
    'theme'      => trim((string)($_GET['theme'] ?? '')),
    'activeDays' => max(0, (int)($_GET['activeDays'] ?? 0)),
    'withPush'   => in_array(($_GET['withPush'] ?? ''), ['Y','N'], true) ? $_GET['withPush'] : '',
    'sort'       => in_array(($_GET['sort'] ?? ''), ['uid','fio','nickname','workplace','theme','created','last_seen'], true) ? $_GET['sort'] : 'last_seen',
    'sortDir'    => strtoupper((string)($_GET['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
    'page'       => 1,
    'perPage'    => 500,
];

$filename = 'x5_pwa_users_' . date('Y-m-d_H-i') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// BOM для Excel, чтобы русские буквы не поехали
fwrite($out, "\xEF\xBB\xBF");

// Заголовок
fputcsv($out, [
    'UID',
    'Ф.И.О',
    'Никнейм',
    'Место работы',
    'Направление',
    'Device ID',
    'Создан',
    'Последняя активность',
    'Push-подписка',
    'Лекций',
    'Заданий',
    'Привычек',
]);

$fmt = function (?int $ms): string {
    return $ms ? date('Y-m-d H:i:s', (int)($ms / 1000)) : '';
};

// Идём страницами по 500 — можно выгрузить и 15000, и 100000 без падений
$page = 1;
$maxPages = 1000; // предохранитель
while ($page <= $maxPages) {
    $batch = \X5\Pwa\StatsBuilder::listUsers(array_merge($params, ['page' => $page]));

    if (empty($batch['rows'])) break;

    foreach ($batch['rows'] as $u) {
        fputcsv($out, [
            $u['UID'],
            $u['FIO'],
            $u['NICKNAME'],
            $u['WORKPLACE'],
            $u['THEME'],
            $u['DEVICE_ID'],
            $fmt($u['CREATED_AT']),
            $fmt($u['LAST_SEEN_AT']),
            $u['HAS_SUBSCRIPTION'] ? 'да' : 'нет',
            (int)$u['LECTURES_READ'],
            (int)$u['TASKS_DONE'],
            (int)$u['HABITS_DONE'],
        ]);
    }

    if ($page >= $batch['pages']) break;

    $page++;

    // Сброс буфера на каждой странице — чтобы данные уходили клиенту сразу
    if (function_exists('fastcgi_finish_request') === false) {
        @ob_flush();
        @flush();
    }
}

fclose($out);

// Отключаем вывод любых эмбедов/панелей после CSV
$APPLICATION->FinalActions();
exit;