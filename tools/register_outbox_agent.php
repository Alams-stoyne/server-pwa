<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Loader;
use X5\Pwa\Settings;

Loader::includeModule('x5.pwa');

$agentName = '\\X5\\Pwa\\Outbox::run();';
$interval  = Settings::outboxIntervalSec();

$rs = CAgent::GetList([], ['NAME' => $agentName]);
$removed = 0;
while ($a = $rs->Fetch()) {
    CAgent::Delete($a['ID']);
    $removed++;
}

$id = CAgent::AddAgent(
    $agentName,
    'x5.pwa',
    'N',                       // периодический
    $interval,                 // сек
    '',                        // datecheck — сейчас
    'Y',                       // active
    date('d.m.Y H:i:s'),
    'N'
);

echo '<pre style="font:12px monospace;background:#fff;padding:16px">';
echo "=== Register Outbox agent ===\n";
echo "agent: {$agentName}\n";
echo "interval: {$interval} сек\n";
echo "removed old: {$removed}\n";
if ($id) {
    echo "✔ created agent ID={$id}\n";
    echo "\nПроверить в админке: Настройки → Агенты.\n";
} else {
    echo "✖ FAIL: " . (string)$GLOBALS['APPLICATION']->GetException() . "\n";
}
echo '</pre>';