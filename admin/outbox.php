<?php
/**
 * Outbox наград x5.pwa — мониторинг и ручной запуск.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\Outbox;
use X5\Pwa\Settings;

$moduleId = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);

$APPLICATION->SetTitle('Outbox наград x5.pwa');

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$flash = [];
$error = null;

if ($request->isPost() && check_bitrix_sessid()) {
    $action = (string)$request->getPost('action');
    $batch  = max(1, min(5000, (int)($request->getPost('batch') ?: Settings::outboxBatchSize())));

    // Защита от параллельного запуска (тот же лок, что и в Outbox::run)
    $lockFile = sys_get_temp_dir() . '/x5.pwa.outbox.lock';
    $fp = fopen($lockFile, 'c+');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        try {
            switch ($action) {
                case 'run_all':
                    // Используем встроенный метод, который сам обновляет очередь
                    Outbox::run();
                    $flash[] = "Запуск Outbox::run() выполнен (результаты в логе).";
                    break;

                case 'run_tasks':
                    $n = Outbox::processTasks($batch);
                    $flash[] = "Tasks: {$n}";
                    break;
                case 'run_lectures':
                    $n = Outbox::processLectures($batch);
                    $flash[] = "Lectures: {$n}";
                    break;
                case 'run_habits':
                    $n = Outbox::processHabits(min(200, $batch));
                    $flash[] = "Habits: {$n}";
                    break;
                case 'run_reflections':
                    $n = Outbox::processReflections($batch);
                    $flash[] = "Reflections: {$n}";
                    break;
                case 'run_steps':
                    $n = Outbox::processSteps($batch);
                    $flash[] = "Steps: {$n}";
                    break;
                case 'run_events':
                    $n = Outbox::processEvents($batch);
                    $flash[] = "Events: {$n}";
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
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    } else {
        $error = 'Другой процесс Outbox уже запущен. Попробуйте позже.';
    }
}

$stats = Outbox::getStats();

$labels = [
    'tasks'          => 'Практики (TASKS)',
    'lectures'       => 'Лекции (LECTURES)',
    'habits'         => 'Привычки (HABITS)',
    'reflections'    => 'Рефлексии (REFLECTIONS)',
    'steps_sent'     => 'Шаги — отправка',
    'steps_approved' => 'Шаги — подтверждение',
    'events'         => 'Мероприятия (EVENTS)',
];

$totals = ['pending' => 0, 'awarded' => 0, 'skipped' => 0, 'failed' => 0];
foreach ($stats as $s) {
    foreach ($totals as $k => $_) {
        $totals[$k] += $s[$k] ?? 0;
    }
}

$lastRun = Settings::getInt('outbox_last_run', 0);
$lastRunText = $lastRun > 0 ? date('d.m.Y H:i:s', $lastRun) : '—';
$intervalSec = Settings::outboxIntervalSec();
$nextRunIn   = $lastRun > 0 ? max(0, $intervalSec - (time() - $lastRun)) : 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<style>
    .x5pwa-box { background:#fff; padding:20px; margin-bottom:16px; border:1px solid #e2e5e9; }
    .x5pwa-box h3 { margin:0 0 16px 0; font-size:15px; }
    .x5pwa-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:16px; }
    .x5pwa-metric { background:#f5f6f8; padding:20px; border-radius:6px; text-align:center; }
    .x5pwa-metric-value { font-size:32px; font-weight:700; font-family:monospace; }
    .x5pwa-metric-label { font-size:12px; color:#666; text-transform:uppercase; margin-top:6px; }
    .x5pwa-pending { color:#b8860b; }
    .x5pwa-awarded { color:#3a7d1c; }
    .x5pwa-skipped { color:#6e7781; }
    .x5pwa-failed  { color:#c0392b; }
    .x5pwa-table { width:100%; border-collapse:collapse; }
    .x5pwa-table th { text-align:left; padding:10px 12px; border-bottom:2px solid #e2e5e9; background:#f8f9fa; font-size:12px; font-weight:600; color:#555; }
    .x5pwa-table td { padding:10px 12px; border-bottom:1px solid #eee; font-size:13px; }
    .x5pwa-table td.num { text-align:right; font-family:monospace; }
    .x5pwa-note { font-size:12px; color:#666; margin-top:12px; line-height:1.6; }
    .x5pwa-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
</style>

<?php foreach ($flash as $f): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message"><?= htmlspecialcharsbx($f) ?></div>
    </div>
<?php endforeach; ?>

<?php if ($error): ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message"><?= htmlspecialcharsbx($error) ?></div>
    </div>
<?php endif; ?>

<div class="x5pwa-box">
    <h3>Сводка по всем источникам наград</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-pending"><?= (int)$totals['pending'] ?></div>
            <div class="x5pwa-metric-label">Pending</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-awarded"><?= (int)$totals['awarded'] ?></div>
            <div class="x5pwa-metric-label">Awarded</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-skipped"><?= (int)$totals['skipped'] ?></div>
            <div class="x5pwa-metric-label">Skipped</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-failed"><?= (int)$totals['failed'] ?></div>
            <div class="x5pwa-metric-label">Failed</div>
        </div>
    </div>

    <table class="x5pwa-table">
        <thead>
            <tr>
                <th>Источник награды</th>
                <th style="text-align:right">Pending</th>
                <th style="text-align:right">Awarded</th>
                <th style="text-align:right">Skipped</th>
                <th style="text-align:right">Failed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($labels as $key => $label): ?>
                <?php $s = $stats[$key] ?? ['pending'=>0,'awarded'=>0,'skipped'=>0,'failed'=>0]; ?>
                <tr>
                    <td><b><?= htmlspecialcharsbx($label) ?></b></td>
                    <td class="num"><?= (int)$s['pending'] ?></td>
                    <td class="num" style="color:#3a7d1c"><?= (int)$s['awarded'] ?></td>
                    <td class="num"><?= (int)$s['skipped'] ?></td>
                    <td class="num" style="color:#c0392b"><?= (int)$s['failed'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="x5pwa-note">
        Агент запускается раз в <b><?= (int)$intervalSec ?> сек</b>.
        Последний запуск: <b><?= htmlspecialcharsbx($lastRunText) ?></b>.
        <?php if ($lastRun > 0 && $nextRunIn > 0): ?>
            Следующий через <b><?= (int)$nextRunIn ?> сек</b>.
        <?php endif; ?>
        <br>Также резервный вызов через <code>Scheduler::lazyRun()</code> при каждом хите.
    </div>
</div>

<div class="x5pwa-box">
    <h3>Действия</h3>
    <div class="x5pwa-actions">
        <form method="post">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="run_all">
            <input type="hidden" name="batch"  value="<?= (int)Settings::outboxBatchSize() ?>">
            <button class="adm-btn adm-btn-save" type="submit">Запустить всё</button>
        </form>
    </div>

    <div class="x5pwa-actions">
        <form method="post"><input type="hidden" name="action" value="run_tasks">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="500">
            <button class="adm-btn" type="submit">Только задачи</button></form>
        <form method="post"><input type="hidden" name="action" value="run_lectures">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="500">
            <button class="adm-btn" type="submit">Только лекции</button></form>
        <form method="post"><input type="hidden" name="action" value="run_habits">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="200">
            <button class="adm-btn" type="submit">Только привычки</button></form>
        <form method="post"><input type="hidden" name="action" value="run_reflections">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="500">
            <button class="adm-btn" type="submit">Только рефлексии</button></form>
        <form method="post"><input type="hidden" name="action" value="run_steps">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="500">
            <button class="adm-btn" type="submit">Только шаги</button></form>
        <form method="post"><input type="hidden" name="action" value="run_events">
            <?= bitrix_sessid_post() ?><input type="hidden" name="batch" value="500">
            <button class="adm-btn" type="submit">Только мероприятия</button></form>
    </div>

    <hr style="margin:16px 0;border:none;border-top:1px solid #eaeef2">

    <div class="x5pwa-actions">
        <form method="post" onsubmit="return confirm('Вернуть все failed в pending?')">
            <?= bitrix_sessid_post() ?><input type="hidden" name="action" value="reset_failed">
            <button class="adm-btn" type="submit" style="color:#c0392b">Reset failed → pending</button>
        </form>
        <form method="post" onsubmit="return confirm('Вернуть все skipped в pending? Перепроверит лимиты и даты.')">
            <?= bitrix_sessid_post() ?><input type="hidden" name="action" value="reset_skipped">
            <button class="adm-btn" type="submit" style="color:#c0392b">Reset skipped → pending</button>
        </form>
    </div>
</div>

<div class="x5pwa-box" style="font-size:12px;color:#555;line-height:1.6">
    <b>Pending</b> — в очереди.<br>
    <b>Awarded</b> — награда начислена (или уже была).<br>
    <b>Skipped</b> — награда не положена: лимит / NO_POINTS / дата мимо / не выполнено условие.<br>
    <b>Failed</b> — ошибка при обработке: разбирать вручную.
    <br><br>
    <i>Если поле <code>UF_REWARD_STATE*</code> отсутствует в БД, статистика будет нулевой — запустите <code>tools/outbox_migrate.php?apply=Y</code>.</i>
</div>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';