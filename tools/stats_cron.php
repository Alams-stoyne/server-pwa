<?php
/**
 * CLI-скрипт для cron.
 *
 * Запуск:
 *     php /path/to/tools/stats_cron.php
 *     php /path/to/tools/stats_cron.php --force
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$toolsDir = __DIR__;
$logFile  = $toolsDir . '/stats_cron.log';

function cronLog(string $msg): void
{
    global $logFile;
    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    cronLog(sprintf('PHP ERROR [%d]: %s @ %s:%d', $errno, $errstr, $errfile, $errline));
    return false;
});

set_exception_handler(function (\Throwable $e) {
    cronLog(sprintf('UNCAUGHT EXCEPTION: %s @ %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        cronLog(sprintf(
            'FATAL SHUTDOWN [%d]: %s @ %s:%d',
            $e['type'], $e['message'], $e['file'], $e['line']
        ));
    }
});

cronLog('--- START ---');

// ─── DOCUMENT_ROOT ───
$docRootCandidates = [];

if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $docRootCandidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
}

$up = $toolsDir;
for ($i = 0; $i < 6; $i++) {
    $up = dirname($up);
    if ($up && $up !== '/' && $up !== '.') $docRootCandidates[] = $up;
}

$docRootCandidates[] = '/home/x/x5hd2026/x5hd.ru/public_html';

$docRoot = null;
foreach ($docRootCandidates as $cand) {
    if (is_file($cand . '/bitrix/modules/main/include/prolog_before.php')) {
        $docRoot = $cand;
        break;
    }
}

if (!$docRoot) {
    cronLog('FATAL: DOCUMENT_ROOT не найден. Проверены: ' . implode(' | ', array_unique($docRootCandidates)));
    exit(10);
}

$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('DOCUMENT_ROOT',        $docRoot);
define('NO_KEEP_STATISTIC',    true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_CRONTAB_SUPPORT',   true);
define('BX_CRONTAB',           true);
define('CHK_EVENT',            true);

cronLog('DOCUMENT_ROOT = ' . $docRoot);

cronLog('require prolog_before.php …');
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
cronLog('prolog_before.php OK');

cronLog('includeModule x5.pwa …');
if (!\Bitrix\Main\Loader::includeModule('x5.pwa')) {
    cronLog('FATAL: includeModule вернул false');
    exit(12);
}
cronLog('module x5.pwa OK');

cronLog('класс Dumper существует? ' . (class_exists('X5\\Pwa\\Dumper') ? 'YES' : 'NO'));
cronLog('класс StatsBuilder существует? ' . (class_exists('X5\\Pwa\\StatsBuilder') ? 'YES' : 'NO'));

$interval = \X5\Pwa\Dumper::statsInterval();
$last     = (int)\Bitrix\Main\Config\Option::get('x5.pwa', 'last_stats_at', 0);
$force    = in_array('--force', $argv ?? [], true);
$elapsed  = $last > 0 ? (int)((time() * 1000 - $last) / 1000) : PHP_INT_MAX;

cronLog(sprintf(
    'interval=%ds last=%s elapsed=%s force=%s',
    $interval,
    $last > 0 ? date('Y-m-d H:i:s', (int)($last / 1000)) : '—',
    $elapsed === PHP_INT_MAX ? '—' : $elapsed . 's',
    $force ? 'yes' : 'no'
));

// ─── Тихий пропуск ───
if (!$force && $last > 0 && $elapsed < $interval) {
    // Пусть Битрикс завершится штатно, чтобы его shutdown-хуки
    // не подменили код выхода.
    \Bitrix\Main\Application::getInstance()->end(0);
    exit(0);
}

// ─── Обновление ───
cronLog('вызываю Dumper::regenerateStats() …');
$r = \X5\Pwa\Dumper::regenerateStats();
cronLog('результат: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

if (!empty($r['ok'])) {
    \Bitrix\Main\Application::getInstance()->end(0);
    exit(0);
}

fwrite(STDERR, ($r['error'] ?? 'unknown') . PHP_EOL);
\Bitrix\Main\Application::getInstance()->end(20);
exit(20);