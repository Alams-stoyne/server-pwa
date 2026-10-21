<?php
// prolog.php
define('X5_PWA_MODULE_ID', 'x5.pwa');<?php
/**
 * Разовая регистрация событий модуля x5.pwa.
 * Запуск: перейти в браузере под администратором,
 * или выполнить через CLI: php -f tools/register_events.php
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

if (PHP_SAPI !== 'cli') {
    // В браузере — только админам
    global $USER;
    if (!$USER->IsAdmin()) {
        http_response_code(403);
        die('Access denied');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

Loader::includeModule('x5.pwa');
Loader::includeModule('iblock');

echo "Модуль установлен: " . (ModuleManager::isModuleInstalled('x5.pwa') ? 'да' : 'нет') . PHP_EOL;

$events = [
    ['iblock', 'OnAfterIBlockElementAdd',    'X5\\Pwa\\Dumper',      'onElementSave'],
    ['iblock', 'OnAfterIBlockElementUpdate', 'X5\\Pwa\\Dumper',      'onElementSave'],
    ['iblock', 'OnAfterIBlockElementDelete', 'X5\\Pwa\\Dumper',      'onElementDelete'],
    ['main',   'OnBeforeProlog',             'X5\\Pwa\\Api\\Router', 'handle'],
    ['main',   'OnAfterEpilog',              'X5\\Pwa\\Scheduler',   'lazyRun'],
];

foreach ($events as [$fromMod, $event, $toClass, $toMethod]) {
    RegisterModuleDependences($fromMod, $event, 'x5.pwa', $toClass, $toMethod, 100);
    echo "Registered: {$fromMod}/{$event} → {$toClass}::{$toMethod}" . PHP_EOL;
}

if (ModuleManager::isModuleInstalled('rest')) {
    RegisterModuleDependences(
        'rest',
        'OnRestServiceBuildDescription',
        'x5.pwa',
        'X5\\Pwa\\Api\\RestBridge',
        'onRestServiceBuildDescription',
        100
    );
    echo "Registered: rest/OnRestServiceBuildDescription → X5\\Pwa\\Api\\RestBridge" . PHP_EOL;
}

echo PHP_EOL . "Готово. Проверьте таблицу:" . PHP_EOL;
echo "  SELECT * FROM b_module_to_module WHERE TO_MODULE_ID = 'x5.pwa';" . PHP_EOL;