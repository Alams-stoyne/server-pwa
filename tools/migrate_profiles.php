<?php
/**
 * Миграция профилей из UserStates в UidBindings.
 * Использует общий парсер Storage::extractProfile().
 *
 * Запуск: под админом, https://api.x5hd.ru/local/modules/x5.pwa/tools/migrate_profiles.php
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

$statesDc = \X5\Pwa\Storage::dataClass('X5PwaUserStates');
$bindDc   = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');

$processed = 0;
$updated   = 0;
$skipped   = 0;
$empty     = 0;

$page = 1;
while (true) {
    $rows = $statesDc::getList([
        'select' => ['ID', 'UF_UID', 'UF_STATE'],
        'order'  => ['ID' => 'ASC'],
        'limit'  => 500,
        'offset' => ($page - 1) * 500,
    ])->fetchAll();

    if (!$rows) break;

    foreach ($rows as $r) {
        $processed++;
        $uid   = (string)$r['UF_UID'];
        $state = $r['UF_STATE'] ? json_decode((string)$r['UF_STATE'], true) : null;

        echo "\n─── UID: " . substr($uid, 0, 16) . "…\n";

        if (!is_array($state)) {
            echo "  state пуст или не парсится\n";
            $empty++;
            continue;
        }

        echo "  Ключи state: " . implode(', ', array_keys($state)) . "\n";

        $profile = \X5\Pwa\Storage::extractProfile($state);
        echo "  Извлечено: FIO=\"{$profile['FIO']}\" NICK=\"{$profile['NICKNAME']}\" "
           . "WORK=\"{$profile['WORKPLACE']}\" THEME=\"{$profile['THEME']}\"\n";

        if ($profile['FIO'] === '' && $profile['NICKNAME'] === ''
            && $profile['WORKPLACE'] === '' && $profile['THEME'] === '') {
            echo "  → ничего не извлечено, пропуск\n";
            $skipped++;
            continue;
        }

        $binding = $bindDc::getList([
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();

        if (!$binding) {
            echo "  → привязки для UID нет, пропуск\n";
            $skipped++;
            continue;
        }

        $update = [];
        if ($profile['FIO'] !== '')       $update['UF_FIO']       = $profile['FIO'];
        if ($profile['NICKNAME'] !== '')  $update['UF_NICKNAME']  = $profile['NICKNAME'];
        if ($profile['WORKPLACE'] !== '') $update['UF_WORKPLACE'] = $profile['WORKPLACE'];
        if ($profile['THEME'] !== '')     $update['UF_THEME']     = $profile['THEME'];

        if ($update) {
            $bindDc::update((int)$binding['ID'], $update);
            echo "  → обновлено: " . implode(', ', array_keys($update)) . "\n";
            $updated++;
        } else {
            echo "  → нечего обновлять\n";
            $skipped++;
        }
    }

    $page++;
    if ($page > 1000) break;
}

echo "\n\n=== Итог ===\n";
echo "Всего состояний:    {$processed}\n";
echo "Обновлено привязок: {$updated}\n";
echo "Пропущено:          {$skipped}\n";
echo "Пустых states:      {$empty}\n";