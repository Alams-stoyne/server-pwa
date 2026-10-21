<?php
/**
 * Экспорт пользователей PWA в CSV (с учётом блокировки).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$uids = [];
if (!empty($_GET['uids']) && is_array($_GET['uids'])) {
    foreach ($_GET['uids'] as $u) {
        $u = trim((string)$u);
        if ($u !== '' && preg_match('/^[a-f0-9]{40}$/i', $u)) {
            $uids[] = $u;
        }
    }
}

$params = [
    'search'      => trim((string)($_GET['SEARCH'] ?? $_GET['FIND'] ?? $_GET['q'] ?? '')),
    'theme'       => trim((string)($_GET['THEME'] ?? $_GET['theme'] ?? '')),
    'blocked'     => in_array($_GET['BLOCKED'] ?? '', ['Y', 'N'], true) ? $_GET['BLOCKED'] : '',
    'active_from' => (string)($_GET['LAST_SEEN_AT_from'] ?? ''),
    'active_to'   => (string)($_GET['LAST_SEEN_AT_to']   ?? ''),
    'activeDays'  => max(0, (int)($_GET['activeDays'] ?? 0)),
    'withPush'    => in_array(($_GET['HAS_SUBSCRIPTION'] ?? $_GET['withPush'] ?? ''), ['Y','N'], true)
                        ? ($_GET['HAS_SUBSCRIPTION'] ?? $_GET['withPush']) : '',
    'sort'        => in_array(($_GET['by'] ?? $_GET['sort'] ?? ''), ['UID','FIO','NICKNAME','WORKPLACE','THEME','CREATED_AT','LAST_SEEN_AT'], true)
                        ? ($_GET['by'] ?? $_GET['sort']) : 'LAST_SEEN_AT',
    'sortDir'     => strtoupper((string)($_GET['order'] ?? $_GET['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
    'page'        => 1,
    'perPage'     => 500,
];

$filename = 'x5_pwa_users_' . date('Y-m-d_H-i') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'UID', 'Статус', 'Ф.И.О', 'Никнейм', 'Место работы', 'Направление',
    'Device ID', 'Создан', 'Последняя активность', 'Push-подписка',
    'Лекций', 'Заданий', 'Привычек',
    'Заблокирован с', 'Причина блокировки',
]);

$fmt = fn(?int $ms): string => $ms ? date('Y-m-d H:i:s', (int)($ms / 1000)) : '';

$writeRow = function ($u) use ($out, $fmt) {
    fputcsv($out, [
        $u['UID'],
        !empty($u['BLOCKED']) ? 'Заблокирован' : 'Активен',
        $u['FIO'],
        $u['NICKNAME'],
        $u['WORKPLACE'],
        $u['THEME'],
        $u['DEVICE_ID'],
        $fmt($u['CREATED_AT']),
        $fmt($u['LAST_ACTIVITY_AT']),
        $u['HAS_SUBSCRIPTION'] ? 'да' : 'нет',
        (int)$u['LECTURES_READ'],
        (int)$u['TASKS_DONE'],
        (int)$u['HABITS_DONE'],
        $fmt($u['BLOCKED_AT'] ?? 0),
        $u['BLOCK_REASON'] ?? '',
    ]);
};

// Режим: выбранные UID
if ($uids) {
    foreach ($uids as $uid) {
        $binding = \X5\Pwa\Storage::getUidBinding($uid);
        if (!$binding) continue;

        $stats  = \X5\Pwa\Storage::getStatsForUids([$uid]);
        $subs   = \X5\Pwa\Storage::getSubscriptionsForUids([$uid]);
        $active = \X5\Pwa\Storage::getLastActivityForUids([$uid]);

        $s = $stats[$uid] ?? ['lectures' => 0, 'tasks' => 0, 'habits' => 0];
        $lastSeen = $binding['UF_LAST_SEEN_AT']
            ? $binding['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0;
        $lastActive = max($lastSeen, $active[$uid] ?? 0);

        $writeRow([
            'UID'              => $uid,
            'DEVICE_ID'        => (string)$binding['UF_DEVICE_ID'],
            'FIO'              => (string)($binding['UF_FIO']       ?? ''),
            'NICKNAME'         => (string)($binding['UF_NICKNAME']  ?? ''),
            'WORKPLACE'        => (string)($binding['UF_WORKPLACE'] ?? ''),
            'THEME'            => (string)($binding['UF_THEME']     ?? ''),
            'CREATED_AT'       => $binding['UF_CREATED_AT']
                ? $binding['UF_CREATED_AT']->getTimestamp() * 1000 : 0,
            'LAST_ACTIVITY_AT' => $lastActive,
            'HAS_SUBSCRIPTION' => !empty($subs[$uid]),
            'LECTURES_READ'    => $s['lectures'],
            'TASKS_DONE'       => $s['tasks'],
            'HABITS_DONE'      => $s['habits'],
            'BLOCKED'          => ($binding['UF_BLOCKED'] ?? 'N') === 'Y',
            'BLOCKED_AT'       => !empty($binding['UF_BLOCKED_AT'])
                ? $binding['UF_BLOCKED_AT']->getTimestamp() * 1000 : 0,
            'BLOCK_REASON'     => (string)($binding['UF_BLOCK_REASON'] ?? ''),
        ]);
    }

    fclose($out);
    $APPLICATION->FinalActions();
    exit;
}

// Режим: по фильтру
$page     = 1;
$maxPages = 1000;
while ($page <= $maxPages) {
    $batch = \X5\Pwa\StatsBuilder::listUsers(array_merge($params, ['page' => $page]));
    if (empty($batch['rows'])) break;

    foreach ($batch['rows'] as $u) {
        $writeRow($u);
    }

    if ($page >= $batch['pages']) break;
    $page++;

    @ob_flush();
    @flush();
}

fclose($out);

$APPLICATION->FinalActions();
exit;