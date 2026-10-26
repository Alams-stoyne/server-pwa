<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!$USER->IsAdmin()) die('admin only');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\Outbox;
use X5\Pwa\Settings;

Loader::includeModule('x5.pwa');

$request = \Bitrix\Main\Context::getCurrent()->getRequest();

$flash = [];
$error = null;

if ($request->isPost() && check_bitrix_sessid()) {
    $action = (string)$request->getPost('action');
    $batch  = (int)($request->getPost('batch') ?: Settings::outboxBatchSize());
    $batch  = max(1, min(5000, $batch));

    try {
        switch ($action) {
            case 'run_tasks':
                $n = Outbox::processTasks($batch);
                $flash[] = "tasks: обработано {$n}";
                break;

            case 'run_lectures':
                $n = Outbox::processLectures($batch);
                $flash[] = "lectures: обработано {$n}";
                break;

            case 'run_all':
                $t = Outbox::processTasks($batch);
                $l = Outbox::processLectures($batch);
                $flash[] = "tasks: {$t}, lectures: {$l}";
                break;

            case 'reset_failed':
                $n = Outbox::resetFailedToPending();
                $flash[] = "failed → pending: {$n}";
                break;

            case 'reset_skipped':
                $n = Outbox::resetSkippedToPending();
                $flash[] = "skipped → pending: {$n}";
                break;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats = Outbox::getStats();

// детализация по таблицам
$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();
$perTable  = [];

foreach ([Outbox::TABLE_TASKS => 'TASKS', Outbox::TABLE_LECTURES => 'LECTURES'] as $t => $label) {
    $tSafe = $sqlHelper->forSql($t);
    $perTable[$label] = ['pending' => 0, 'awarded' => 0, 'skipped' => 0, 'failed' => 0];
    try {
        $rs = $conn->query(
            "SELECT UF_REWARD_STATE, COUNT(*) AS C FROM `{$tSafe}` "
            . "GROUP BY UF_REWARD_STATE"
        );
        while ($r = $rs->fetch()) {
            $st = (string)$r['UF_REWARD_STATE'];
            if (isset($perTable[$label][$st])) {
                $perTable[$label][$st] = (int)$r['C'];
            }
        }
    } catch (\Throwable $e) {
        // миграция не применена
    }
}

// залипшие pending (старше 1 часа)
$stuck = 0;
foreach ([Outbox::TABLE_TASKS, Outbox::TABLE_LECTURES] as $t) {
    $tSafe = $sqlHelper->forSql($t);
    try {
        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM `{$tSafe}` "
            . "WHERE UF_REWARD_STATE='pending' "
            . "AND ID > 0"
        )->fetch();
        // нельзя надёжно отфильтровать по времени без явной колонки, поэтому
        // показываем только общее число pending, "залипшие" — производная
        // от (pending > 0 и last_run старше интервала)
    } catch (\Throwable $e) {}
}

$lastRun = Settings::getInt('outbox_last_run', 0);
$lastRunText = $lastRun > 0 ? date('d.m.Y H:i:s', $lastRun) : '—';
$intervalSec = Settings::outboxIntervalSec();
$nextRunIn   = $lastRun > 0
    ? max(0, $intervalSec - (time() - $lastRun))
    : 0;

$app = $GLOBALS['APPLICATION'];
$app->SetTitle('Outbox наград x5.pwa');
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8">
<title>Outbox наград x5.pwa</title>
<style>
body { font: 13px/1.5 -apple-system, Segoe UI, Arial, sans-serif; background:#f6f8fa; padding:20px; color:#24292f; }
h1 { font-size:20px; margin:0 0 16px; }
.card { background:#fff; border:1px solid #d0d7de; border-radius:6px; padding:16px 20px; margin-bottom:16px; }
.flash { background:#e6ffed; border:1px solid #a5e0b7; padding:10px 14px; margin-bottom:12px; border-radius:4px; }
.err { background:#ffeef0; border:1px solid #ffb3ba; }
table { border-collapse: collapse; width:100%; }
td, th { padding:6px 12px; text-align:left; border-bottom:1px solid #eaeef2; }
th { background:#f6f8fa; font-weight:600; }
.btn { display:inline-block; padding:7px 14px; background:#0969da; color:#fff; text-decoration:none; border:none; border-radius:6px; cursor:pointer; font-size:13px; }
.btn.red { background:#cf222e; }
.btn.gray { background:#6e7781; }
.btn + .btn { margin-left:6px; }
.stat { display:inline-block; padding:4px 10px; margin-right:6px; border-radius:4px; font-family:monospace; }
.stat.pending { background:#fff8c5; color:#7d4e00; }
.stat.awarded { background:#d1f7c4; color:#1a5d1a; }
.stat.skipped { background:#eaeef2; color:#57606a; }
.stat.failed  { background:#ffeef0; color:#a40e26; }
.note { font-size:12px; color:#57606a; margin-top:6px; }
</style></head><body>

<h1>Outbox наград x5.pwa</h1>

<?php foreach ($flash as $f): ?>
    <div class="flash"><?= htmlspecialchars($f) ?></div>
<?php endforeach; ?>
<?php if ($error): ?>
    <div class="flash err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0">Состояния</h3>
    <div style="margin-bottom:10px">
        <span class="stat pending">pending <?= (int)$stats['pending'] ?></span>
        <span class="stat awarded">awarded <?= (int)$stats['awarded'] ?></span>
        <span class="stat skipped">skipped <?= (int)$stats['skipped'] ?></span>
        <span class="stat failed">failed <?= (int)$stats['failed'] ?></span>
    </div>
    <table>
        <tr><th>Таблица</th><th>pending</th><th>awarded</th><th>skipped</th><th>failed</th></tr>
        <?php foreach ($perTable as $label => $c): ?>
        <tr>
            <td><?= htmlspecialchars($label) ?></td>
            <td><?= (int)$c['pending'] ?></td>
            <td><?= (int)$c['awarded'] ?></td>
            <td><?= (int)$c['skipped'] ?></td>
            <td><?= (int)$c['failed']  ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="note">
        Агент: раз в <b><?= (int)$intervalSec ?> сек</b>.
        Последний запуск: <b><?= htmlspecialchars($lastRunText) ?></b>
        <?php if ($lastRun > 0 && $nextRunIn > 0): ?>
            (следующий через <?= (int)$nextRunIn ?> сек)
        <?php endif; ?>.
        <br>
        Также резервный вызов из <code>Scheduler::lazyRun()</code> при каждом хите.
        Batch: <b><?= (int)Settings::outboxBatchSize() ?></b>.
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0">Действия</h3>
    <form method="post" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="run_all">
        <input type="hidden" name="batch"  value="<?= (int)Settings::outboxBatchSize() ?>">
        <button class="btn" type="submit">run_all (<?= (int)Settings::outboxBatchSize() ?>)</button>
    </form>
    <form method="post" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="run_all">
        <input type="hidden" name="batch"  value="2000">
        <button class="btn" type="submit">run_all (2000)</button>
    </form>
    <form method="post" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="run_tasks">
        <input type="hidden" name="batch"  value="<?= (int)Settings::outboxBatchSize() ?>">
        <button class="btn gray" type="submit">run_tasks</button>
    </form>
    <form method="post" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="run_lectures">
        <input type="hidden" name="batch"  value="<?= (int)Settings::outboxBatchSize() ?>">
        <button class="btn gray" type="submit">run_lectures</button>
    </form>

    <hr style="margin:14px 0;border:none;border-top:1px solid #eaeef2">

    <form method="post" style="display:inline-block"
          onsubmit="return confirm('Вернуть все failed в pending?')">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="reset_failed">
        <button class="btn red" type="submit">reset failed → pending</button>
    </form>
    <form method="post" style="display:inline-block"
          onsubmit="return confirm('Вернуть все skipped в pending? Это перепроверит лимиты и даты.')">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="reset_skipped">
        <button class="btn red" type="submit">reset skipped → pending</button>
    </form>
</div>

<div class="card" style="font-size:12px;color:#57606a">
    <b>pending</b> — в очереди, будет обработано агентом или кнопкой.<br>
    <b>awarded</b> — награда начислена (или уже была — deduplicated).<br>
    <b>skipped</b> — награда не положена: лимит / NO_POINTS / дата мимо / element gone.<br>
    <b>failed</b> — не удалось: resolver не нашёл элемент, exception. Разбирать вручную; можно вернуть в pending.
    <br><br>
    Если поле <code>UF_REWARD_STATE</code> отсутствует в БД, статистика будет нулевой — запустите
    <code>tools/outbox_migrate.php?apply=Y</code>.
</div>

</body></html>