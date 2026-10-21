<?php
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin()) { http_response_code(403); die('Access denied'); }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "1. PHP: " . PHP_VERSION . "\n";
echo "2. Модуль установлен: "
    . (\Bitrix\Main\ModuleManager::isModuleInstalled('x5.pwa') ? 'да' : 'нет') . "\n";

try {
    $ok = \Bitrix\Main\Loader::includeModule('x5.pwa');
    echo "3. includeModule: " . ($ok ? 'OK' : 'FAIL') . "\n";
} catch (\Throwable $e) {
    echo "3. includeModule THROW: " . $e->getMessage() . "\n";
}

echo "4. autoload.php: "
    . (is_file($_SERVER['DOCUMENT_ROOT'] . '/local/modules/x5.pwa/vendor/autoload.php') ? 'есть' : 'НЕТ') . "\n";

$classes = [
    'X5\\Pwa\\Settings',
    'X5\\Pwa\\Dumper',
    'X5\\Pwa\\ContentBuilder',
    'X5\\Pwa\\Api\\Router',
];
foreach ($classes as $c) {
    echo "5. {$c}: " . (class_exists($c) ? 'OK' : 'NOT FOUND') . "\n";
}

if (class_exists('X5\\Pwa\\Api\\Router')) {
    $ref = new ReflectionClass('X5\\Pwa\\Api\\Router');
    echo "6. Router::handle: "        . ($ref->hasMethod('handle')        ? 'OK' : 'НЕТ') . "\n";
    echo "7. Router::normalizePath: " . ($ref->hasMethod('normalizePath') ? 'OK' : 'НЕТ') . "\n";
    $const = $ref->getConstants();
    echo "8. ALIASES: " . (empty($const['ALIASES']) ? 'ПУСТО' : count($const['ALIASES']) . ' записей') . "\n";
}

if (class_exists('X5\\Pwa\\Api\\Router')) {
    $_SERVER['REQUEST_URI']    = '/mock/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
        ob_start();
        \X5\Pwa\Api\Router::handle();
        $out = ob_get_clean();
        echo "9. handle() вернул: " . mb_substr($out, 0, 300) . "\n";
    } catch (\Throwable $e) {
        echo "9. handle() EXCEPTION: " . $e->getMessage() . "\n";
        echo "   " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}