<?php
/**
 * Экспорт пользователей PWA в CSV (с учётом блокировки).
 *
 * Колонки:
 *   — «За период» (stats_period_start..stats_period_end) — активности + эффективность;
 *   — «За сегодня» — собрано/возможно/недостаёт/эффективность;
 *   — «За всё время» — total-активности + баланс.
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

// Период — для подписи и, если понадобится, для агрегата
[$periodFrom, $periodTo] = \X5\Pwa\StatsBuilder::getEffectivePeriod();

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
    // За период
    'Лекций (период)', 'Заданий (период)', 'Привычек (период)',
    'Шагов (период)', 'Событий посещено (период)', 'Событий подтверждено (период)',
    'Собрано (период)', 'Возможно (период)', 'Недостаёт (период)', 'Эффективность % (период)',
    // За сегодня
    'Собрано (сегодня)', 'Возможно (сегодня)', 'Недостаёт (сегодня)', 'Эффективность % (сегодня)',
    // За всё время
    'Лекций (всего)', 'Заданий (всего)', 'Привычек (всего)', 'Баланс (всего)',
    // Блокировка
    'Заблокирован с', 'Причина блокировки',
]);

$fmt = fn(?int $ms): string => $ms ? date('Y-m-d H:i:s', (int)($ms / 1000)) : '';

/**
 * @param array $u — нормализованная строка пользователя с полями:
 *   UID, BLOCKED, FIO, NICKNAME, WORKPLACE, THEME, DEVICE_ID,
 *   CREATED_AT, LAST_ACTIVITY_AT, HAS_SUBSCRIPTION, BLOCKED_AT, BLOCK_REASON,
 *   --- period ---
 *   LECTURES_READ, TASKS_DONE, HABITS_DONE, STEPS_CONFIRMED,
 *   EVENTS_VISITED, EVENTS_CONFIRMED,
 *   EARNED_ALL, MAX_ALL, MISSING_ALL, EFFICIENCY_ALL,
 *   --- day ---
 *   EARNED_DAY, MAX_DAY, EFFICIENCY_DAY,
 *   --- total ---
 *   LECTURES_READ_TOTAL, TASKS_DONE_TOTAL, HABITS_DONE_TOTAL, REWARD_BALANCE
 */
$writeRow = function (array $u) use ($out, $fmt): void {
    $earnedAll  = (int)($u['EARNED_ALL']  ?? 0);
    $maxAll     = (int)($u['MAX_ALL']     ?? 0);
    $missingAll = (int)($u['MISSING_ALL'] ?? max(0, $maxAll - $earnedAll));
    $effAll     = (float)($u['EFFICIENCY_ALL'] ?? 0);

    $earnedDay  = (int)($u['EARNED_DAY'] ?? 0);
    $maxDay     = (int)($u['MAX_DAY']    ?? 0);
    $missingDay = max(0, $maxDay - $earnedDay);
    $effDay     = (float)($u['EFFICIENCY_DAY'] ?? 0);

    fputcsv($out, [
        $u['UID'],
        !empty($u['BLOCKED']) ? 'Заблокирован' : 'Активен',
        $u['FIO'],
        $u['NICKNAME'],
        $u['WORKPLACE'],
        $u['THEME'],
        $u['DEVICE_ID'],
        $fmt((int)($u['CREATED_AT']       ?? 0)),
        $fmt((int)($u['LAST_ACTIVITY_AT'] ?? 0)),
        !empty($u['HAS_SUBSCRIPTION']) ? 'да' : 'нет',

        // За период
        (int)($u['LECTURES_READ']    ?? 0),
        (int)($u['TASKS_DONE']       ?? 0),
        (int)($u['HABITS_DONE']      ?? 0),
        (int)($u['STEPS_CONFIRMED']  ?? 0),
        (int)($u['EVENTS_VISITED']   ?? 0),
        (int)($u['EVENTS_CONFIRMED'] ?? 0),
        $earnedAll,
        $maxAll,
        $missingAll,
        number_format($effAll, 2, '.', ''),

        // За сегодня
        $earnedDay,
        $maxDay,
        $missingDay,
        number_format($effDay, 2, '.', ''),

        // За всё время
        (int)($u['LECTURES_READ_TOTAL'] ?? 0),
        (int)($u['TASKS_DONE_TOTAL']    ?? 0),
        (int)($u['HABITS_DONE_TOTAL']   ?? 0),
        (int)($u['REWARD_BALANCE']      ?? 0),

        // Блокировка
        $fmt((int)($u['BLOCKED_AT']   ?? 0)),
        (string)($u['BLOCK_REASON']   ?? ''),
    ]);
};

// ── Режим: выбранные UID ──
if ($uids) {
    $periodStats = \X5\Pwa\Storage::getPeriodStatsForUids($uids, $periodFrom, $periodTo);
    $totalStats  = \X5\Pwa\Storage::getStatsForUids($uids);
    $subs        = \X5\Pwa\Storage::getSubscriptionsForUids($uids);
    $activity    = \X5\Pwa\Storage::getLastActivityForUids($uids);

    foreach ($uids as $uid) {
        $binding = \X5\Pwa\Storage::getUidBinding($uid);
        if (!$binding) continue;

        $personal = \X5\Pwa\Storage::getBindingForPersonal($uid);
        $ps = $periodStats[$uid] ?? [
            'lectures' => 0, 'tasks' => 0, 'habits' => 0, 'steps' => 0,
            'events_visited' => 0, 'events_confirmed' => 0,
        ];
        $ts = $totalStats[$uid] ?? ['lectures' => 0, 'tasks' => 0, 'habits' => 0];

        $lastSeen = $binding['UF_LAST_SEEN_AT']
            ? $binding['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0;
        $lastActive = max($lastSeen, $activity[$uid] ?? 0);

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
            'BLOCKED'          => ($binding['UF_BLOCKED'] ?? 'N') === 'Y',
            'BLOCKED_AT'       => !empty($binding['UF_BLOCKED_AT'])
                ? $binding['UF_BLOCKED_AT']->getTimestamp() * 1000 : 0,
            'BLOCK_REASON'     => (string)($binding['UF_BLOCK_REASON'] ?? ''),

            // За период
            'LECTURES_READ'    => (int)$ps['lectures'],
            'TASKS_DONE'       => (int)$ps['tasks'],
            'HABITS_DONE'      => (int)$ps['habits'],
            'STEPS_CONFIRMED'  => (int)$ps['steps'],
            'EVENTS_VISITED'   => (int)$ps['events_visited'],
            'EVENTS_CONFIRMED' => (int)$ps['events_confirmed'],
            'EARNED_ALL'       => (int)($personal['earned_all']  ?? 0),
            'MAX_ALL'          => (int)($personal['max_all']     ?? 0),
            'MISSING_ALL'      => (int)($personal['missing_all'] ?? 0),
            'EFFICIENCY_ALL'   => (float)($personal['eff_all']   ?? 0),

            // За сегодня
            'EARNED_DAY'       => (int)($personal['earned_day']  ?? 0),
            'MAX_DAY'          => (int)($personal['max_day']     ?? 0),
            'EFFICIENCY_DAY'   => (float)($personal['eff_day']   ?? 0),

            // За всё время
            'LECTURES_READ_TOTAL' => (int)$ts['lectures'],
            'TASKS_DONE_TOTAL'    => (int)$ts['tasks'],
            'HABITS_DONE_TOTAL'   => (int)$ts['habits'],
            'REWARD_BALANCE'      => (int)($binding['UF_REWARD_BALANCE'] ?? 0),
        ]);
    }

    fclose($out);
    $APPLICATION->FinalActions();
    exit;
}

// ── Режим: по фильтру ──
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