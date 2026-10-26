<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Loader;
use X5\Pwa\Outbox;

Loader::includeModule('x5.pwa');

$kind  = in_array($_GET['kind'] ?? '', ['tasks', 'lectures', 'all'], true)
    ? $_GET['kind'] : 'all';
$batch = max(1, min(5000, (int)($_GET['batch'] ?? 500)));

echo '<pre style="font:12px monospace;background:#fff;padding:16px">';
echo "Outbox manual run: kind={$kind} batch={$batch}\n\n";

$t = 0; $l = 0;
if ($kind === 'all' || $kind === 'tasks')    $t = Outbox::processTasks($batch);
if ($kind === 'all' || $kind === 'lectures') $l = Outbox::processLectures($batch);

echo "processed: tasks={$t}, lectures={$l}\n";

$stats = Outbox::getStats();
echo "\nСостояния: ";
echo "pending={$stats['pending']} awarded={$stats['awarded']} ";
echo "skipped={$stats['skipped']} failed={$stats['failed']}\n";
echo "</pre>";