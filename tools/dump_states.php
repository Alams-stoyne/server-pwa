<?php
/**
 * Дамп содержимого UserStates и UidBindings.
 * Показывает реальную структуру state, чтобы понять, откуда брать профиль.
 *
 * Запуск: под админом, https://api.x5hd.ru/local/modules/x5.pwa/tools/dump_states.php
 * Или: php /path/to/tools/dump_states.php
 *
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

echo "=== UserStates ===\n";
$dc = \X5\Pwa\Storage::dataClass('X5PwaUserStates');
$rows = $dc::getList(['limit' => 20])->fetchAll();
foreach ($rows as $r) {
    echo "\nID={$r['ID']} UID={$r['UF_UID']}\n";
    echo "UF_STATE RAW (первые 1000 символов):\n";
    echo substr((string)$r['UF_STATE'], 0, 1000) . "\n";
    $decoded = json_decode((string)$r['UF_STATE'], true);
    if (is_array($decoded)) {
        echo "Ключи верхнего уровня: " . implode(', ', array_keys($decoded)) . "\n";
        if (isset($decoded['profile']) && is_array($decoded['profile'])) {
            echo "profile.*: " . implode(', ', array_keys($decoded['profile'])) . "\n";
        }
    } else {
        echo "JSON не парсится\n";
    }
}

echo "\n\n=== UidBindings ===\n";
$dcB = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');
$rowsB = $dcB::getList(['limit' => 20])->fetchAll();
foreach ($rowsB as $r) {
    echo "\nID={$r['ID']} UID={$r['UF_UID']}\n";
    echo "  UF_FIO       = '" . (string)($r['UF_FIO'] ?? '') . "'\n";
    echo "  UF_NICKNAME  = '" . (string)($r['UF_NICKNAME'] ?? '') . "'\n";
    echo "  UF_WORKPLACE = '" . (string)($r['UF_WORKPLACE'] ?? '') . "'\n";
    echo "  UF_THEME     = '" . (string)($r['UF_THEME'] ?? '') . "'\n";
}

echo "\n\n=== Профиль вообще приходит на сервер? ===\n";
echo "Проверьте /x5.pwa.log на строки 'Profile::save' и '[bootstrap]' —\n";
echo "если их нет, фронт не отправляет профиль на сервер, только держит локально.\n";