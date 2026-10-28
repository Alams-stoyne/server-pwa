<?php
/**
 * Общая статистика PWA.
 *
 * GLOBAL_PROGRESS: LECTURES_READ / TASKS_DONE / HABITS_DAYS / STEPS_CONFIRMED /
 * EVENTS_* — за период. Total-версии — в ключах *_TOTAL.
 * Эффективность (EARNED/MAX/MISSING/AVG) — за период + day-секция (сегодня).
 * TOTAL_APPLES — сумма балансов пользователей (накопительный).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use X5\Pwa\Dumper;
use X5\Pwa\Settings;
use X5\Pwa\StatsBuilder;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Общая статистика');

// ── Действия ──
$message = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['x5_action'] ?? '');

    if ($action === 'recalc_caches') {
        try {
            $r = StatsBuilder::recalcAllStats();
            $message = sprintf(
                'Кеши пересчитаны: обработано %d пользователей за %ss.',
                (int)($r['processed'] ?? 0),
                (string)($r['elapsed'] ?? '?')
            );
        } catch (\Throwable $e) {
            $error = 'Ошибка пересчёта: ' . $e->getMessage();
        }
    }

    if ($action === 'refresh_stats') {
        try {
            $r = Dumper::regenerateStats();
            $message = !empty($r['ok'])
                ? 'stats/global.json обновлён и залит в S3.'
                : 'Ошибка: ' . ($r['error'] ?? 'unknown');
        } catch (\Throwable $e) {
            $error = 'Исключение: ' . $e->getMessage();
        }
    }
}

// ── Данные ──
$stats       = StatsBuilder::buildGlobal();
$lastStatsAt = (int)Option::get($moduleId, 'last_stats_at', 0);
$interval    = (int)Option::get($moduleId, 'stats_interval_sec', 600);
$recalcSec   = Settings::statsRecalcIntervalSec();
$lastRecalc  = Settings::statsRecalcLastAt();

$periodStart = Settings::statsPeriodStart();
$periodEnd   = Settings::statsPeriodEnd();

$gp        = $stats['GLOBAL_PROGRESS'] ?? [];
$companies = $stats['COMPANIES']['LIST'] ?? [];
$period    = $stats['PERIOD'] ?? ['FROM' => '—', 'TO' => '—'];

$fmt = function ($n) {
    return number_format((int)$n, 0, '', ' ');
};

$fmtDate = function (?int $ts): string {
    if (!$ts || $ts <= 0) return '—';
    return date('d.m.Y H:i:s', $ts);
};

$effColor = function (float $p): string {
    return $p < 30 ? '#c0392b' : ($p < 70 ? '#f5a623' : '#3a7d1c');
};

$totalEarned  = (int)($gp['EARNED_TOTAL'] ?? 0);
$totalMax     = (int)($gp['MAX_TOTAL'] ?? 0);
$totalMissing = (int)($gp['MISSING_TOTAL'] ?? 0);
$avgEff       = (float)($gp['AVG_EFFICIENCY'] ?? 0);

$totalEarnedDay  = (int)($gp['EARNED_DAY_TOTAL'] ?? 0);
$totalMaxDay     = (int)($gp['MAX_DAY_TOTAL'] ?? 0);
$totalMissingDay = (int)($gp['MISSING_DAY_TOTAL'] ?? 0);
$avgEffDay       = (float)($gp['AVG_EFFICIENCY_DAY'] ?? 0);
$todayLabel      = (string)($gp['TODAY'] ?? date('Y-m-d'));

$settingsUrl = 'settings.php?mid=' . urlencode($moduleId)
    . '&lang=' . LANGUAGE_ID
    . '&mid_menu=1'
    . '&tabControl_active_tab=edit_stats';
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 16px 0; font-size: 15px; }
    .x5pwa-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .x5pwa-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .x5pwa-metric { background: #f5f6f8; padding: 20px; border-radius: 6px; text-align: center; }
    .x5pwa-metric-value { font-size: 32px; font-weight: 700; color: #1e6bcc; }
    .x5pwa-metric-value.x5pwa-green  { color: #3a7d1c; }
    .x5pwa-metric-value.x5pwa-red    { color: #c0392b; }
    .x5pwa-metric-value.x5pwa-grey   { color: #555; }
    .x5pwa-metric-label { font-size: 12px; color: #666; text-transform: uppercase; margin-top: 6px; }
    .x5pwa-subtitle { font-size: 13px; color: #666; margin: 4px 0 16px 0; font-weight: 400; }
    .x5pwa-table { width: 100%; border-collapse: collapse; }
    .x5pwa-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid #e2e5e9; background: #f8f9fa; font-size: 12px; font-weight: 600; color: #555; }
    .x5pwa-table td { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 13px; }
    .x5pwa-table tr:hover td { background: #f8f9fa; }
    .x5pwa-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e0efff; color: #1e6bcc; }
    .x5pwa-bar { background: #e2e5e9; height: 8px; border-radius: 4px; overflow: hidden; width: 120px; display: inline-block; vertical-align: middle; }
    .x5pwa-bar-fill { height: 100%; }
    .x5pwa-mono { font-family: monospace; }
    .x5pwa-num { font-family: monospace; text-align: right; }
    .x5pwa-num-green { color: #3a7d1c; font-family: monospace; text-align: right; }
    .x5pwa-num-red { color: #c0392b; font-family: monospace; text-align: right; }
    .x5pwa-num-grey { color: #555; font-family: monospace; text-align: right; }
    .x5pwa-activity-table { width:100%; border-collapse:collapse; }
    .x5pwa-activity-table th { text-align:left; padding:8px 12px; background:#f8f9fa; font-size:12px; color:#555; border-bottom:2px solid #e2e5e9; }
    .x5pwa-activity-table td { padding:8px 12px; border-bottom:1px solid #eee; font-size:13px; }
    .x5pwa-activity-table td.num { text-align:right; font-family:monospace; }
    .x5pwa-activity-table td.total { color:#888; }
</style>

<?php if ($message): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message"><?= htmlspecialcharsbx($message) ?></div>
    </div>
<?php endif ?>
<?php if ($error): ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message"><?= htmlspecialcharsbx($error) ?></div>
    </div>
<?php endif ?>

<div class="x5pwa-box">
    <h3>Период статистики</h3>
    <dl style="display:grid;grid-template-columns:260px 1fr;gap:6px 12px;margin:0">
        <dt style="color:#666">Настройки периода</dt>
        <dd style="margin:0">
            Старт: <b><?= $periodStart !== '' ? htmlspecialcharsbx($periodStart) : 'авто' ?></b>
            · Конец: <b><?= $periodEnd !== '' ? htmlspecialcharsbx($periodEnd) : 'авто (сегодня)' ?></b>
            &nbsp;
            <a href="<?= htmlspecialcharsbx($settingsUrl) ?>">Изменить в настройках модуля →</a>
        </dd>

        <dt style="color:#666">Действующий период</dt>
        <dd style="margin:0"><b><?= htmlspecialcharsbx((string)($period['FROM'] ?? '—')) ?></b>
            — <b><?= htmlspecialcharsbx((string)($period['TO'] ?? '—')) ?></b></dd>

        <dt style="color:#666">Обновление CDN</dt>
        <dd style="margin:0">Каждые <b><?= (int)$interval ?></b> сек (последнее: <?= $fmtDate((int)($lastStatsAt / 1000)) ?>)</dd>

        <dt style="color:#666">Пересчёт кешей</dt>
        <dd style="margin:0">Каждые <b><?= (int)$recalcSec ?></b> сек (последнее: <?= $fmtDate($lastRecalc) ?>)</dd>

        <dt style="color:#666">CDN-файлы</dt>
        <dd style="margin:0" class="x5pwa-mono">
            <a href="https://cdn.x5hd.ru/stats/global.json" target="_blank">stats/global.json</a>
            ·
            <a href="https://cdn.x5hd.ru/config/period.json" target="_blank">config/period.json</a>
        </dd>
    </dl>
</div>

<!-- ЭФФЕКТИВНОСТЬ: за период -->
<div class="x5pwa-box">
    <h3>Эффективность</h3>
    <div class="x5pwa-subtitle">
        <b>За период</b> (<?= htmlspecialcharsbx((string)($period['FROM'] ?? '—')) ?> — <?= htmlspecialcharsbx((string)($period['TO'] ?? '—')) ?>)
    </div>
    <div class="x5pwa-grid-4">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-green"><?= $fmt($totalEarned) ?></div>
            <div class="x5pwa-metric-label">Собрано яблок</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-grey"><?= $fmt($totalMax) ?></div>
            <div class="x5pwa-metric-label">Максимум возможно</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-red"><?= $fmt($totalMissing) ?></div>
            <div class="x5pwa-metric-label">Недостаёт</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value" style="color:<?= $effColor($avgEff) ?>">
                <?= number_format($avgEff, 1, '.', '') ?>%
            </div>
            <div class="x5pwa-metric-label">Средняя эффективность</div>
        </div>
    </div>
    <div class="x5pwa-bar" style="width:100%;margin-top:16px">
        <div class="x5pwa-bar-fill"
             style="width:<?= max(0, min(100, $avgEff)) ?>%;background:<?= $effColor($avgEff) ?>"></div>
    </div>
</div>

<!-- ЭФФЕКТИВНОСТЬ: сегодня -->
<div class="x5pwa-box">
    <h3>Эффективность — сегодня</h3>
    <div class="x5pwa-subtitle">
        <b><?= htmlspecialcharsbx($todayLabel) ?></b>
        (независимо от периода статистики; учитываются только пользователи с выбранной темой и согласием)
    </div>
    <div class="x5pwa-grid-4">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-green"><?= $fmt($totalEarnedDay) ?></div>
            <div class="x5pwa-metric-label">Собрано сегодня</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-grey"><?= $fmt($totalMaxDay) ?></div>
            <div class="x5pwa-metric-label">Возможно сегодня</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-red"><?= $fmt($totalMissingDay) ?></div>
            <div class="x5pwa-metric-label">Недостаёт сегодня</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value" style="color:<?= $effColor($avgEffDay) ?>">
                <?= number_format($avgEffDay, 1, '.', '') ?>%
            </div>
            <div class="x5pwa-metric-label">Средняя эффективность сегодня</div>
        </div>
    </div>
    <div class="x5pwa-bar" style="width:100%;margin-top:16px">
        <div class="x5pwa-bar-fill"
             style="width:<?= max(0, min(100, $avgEffDay)) ?>%;background:<?= $effColor($avgEffDay) ?>"></div>
    </div>
</div>

<!-- АКТИВНОСТЬ: период + всего -->
<div class="x5pwa-box">
    <h3>Активность участников</h3>
    <table class="x5pwa-activity-table">
        <thead>
            <tr>
                <th>Показатель</th>
                <th style="text-align:right">
                    За период<br>
                    <small style="color:#888;font-weight:400"><?= htmlspecialcharsbx((string)($period['FROM'] ?? '—')) ?> — <?= htmlspecialcharsbx((string)($period['TO'] ?? '—')) ?></small>
                </th>
                <th style="text-align:right">За всё время</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Участников</td>
                <td class="num"><b><?= $fmt($gp['TOTAL_USERS'] ?? 0) ?></b></td>
                <td class="num total">—</td>
            </tr>
            <tr>
                <td>Лекций прочитано</td>
                <td class="num"><b><?= $fmt($gp['LECTURES_READ'] ?? 0) ?></b></td>
                <td class="num total"><?= $fmt($gp['LECTURES_READ_TOTAL'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Заданий выполнено</td>
                <td class="num"><b><?= $fmt($gp['TASKS_DONE'] ?? 0) ?></b></td>
                <td class="num total"><?= $fmt($gp['TASKS_DONE_TOTAL'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Привычек отмечено</td>
                <td class="num"><b><?= $fmt($gp['HABITS_DAYS'] ?? 0) ?></b></td>
                <td class="num total"><?= $fmt($gp['HABITS_DAYS_TOTAL'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Шагов подтверждено</td>
                <td class="num"><b><?= $fmt($gp['STEPS_CONFIRMED'] ?? 0) ?></b></td>
                <td class="num total"><?= $fmt($gp['STEPS_CONFIRMED_TOTAL'] ?? 0) ?></td>
            </tr>
            <tr>
                <td>Событий посещено</td>
                <td class="num"><b><?= $fmt($gp['EVENTS_VISITED'] ?? 0) ?></b></td>
                <td class="num total">—</td>
            </tr>
            <tr>
                <td>Событий подтверждено</td>
                <td class="num"><b><?= $fmt($gp['EVENTS_CONFIRMED'] ?? 0) ?></b></td>
                <td class="num total">—</td>
            </tr>
        </tbody>
    </table>
    <div style="font-size:11px;color:#888;margin-top:12px">
        «За период» — активности в диапазоне <?= htmlspecialcharsbx((string)($period['FROM'] ?? '—')) ?> — <?= htmlspecialcharsbx((string)($period['TO'] ?? '—')) ?>.
        «За всё время» — за всё время существования пользователей.
        Учитываются только пользователи с выбранной темой и согласием на ПД.
    </div>
</div>
<?php
// Сводка по согласиям — для аудита
$consentStats = StatsBuilder::getConsentStats();
$dirtyBalance = (int)$consentStats['dirty_balance'];
$dirtyUsers   = (int)$consentStats['dirty_users'];
$dirtyWithBal = (int)$consentStats['dirty_with_balance'];
?>

<?php if ($dirtyBalance > 0 || $dirtyWithBal > 0): ?>
<div class="x5pwa-box" style="border-left:4px solid #c0392b">
    <h3 style="color:#c0392b">Пользователи без согласия ПД</h3>
    <div class="x5pwa-subtitle">
        Такие пользователи <b>не участвуют</b> в статистике ниже — фильтр в
        <code>Storage::getActiveUidsForStats()</code> и <code>StatsBuilder::buildGlobal()</code>.
        Командная эффективность <b>корректна</b>: их данные игнорируются.
    </div>

    <table class="x5pwa-table" style="max-width:640px">
        <thead>
            <tr>
                <th>Состояние</th>
                <th style="text-align:right">Пользователей</th>
                <th style="text-align:right">С балансом &gt; 0</th>
                <th style="text-align:right">Сумма баланса</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><b>Дано</b> (участвуют в статистике)</td>
                <td class="x5pwa-num"><?= $fmt($consentStats['granted']['users']) ?></td>
                <td class="x5pwa-num"><?= $fmt($consentStats['granted']['users_with_balance']) ?></td>
                <td class="x5pwa-num-green"><?= $fmt($consentStats['granted']['balance']) ?></td>
            </tr>
            <tr style="background:#fff5f5">
                <td><b>Отказ</b> (UF_CONSENT_GRANTED = 'N')</td>
                <td class="x5pwa-num"><?= $fmt($consentStats['refused']['users']) ?></td>
                <td class="x5pwa-num"><?= $fmt($consentStats['refused']['users_with_balance']) ?></td>
                <td class="x5pwa-num-red"><?= $fmt($consentStats['refused']['balance']) ?></td>
            </tr>
            <tr style="background:#f5f5f5">
                <td><b>Не спрашивали</b> (пусто)</td>
                <td class="x5pwa-num"><?= $fmt($consentStats['not_asked']['users']) ?></td>
                <td class="x5pwa-num"><?= $fmt($consentStats['not_asked']['users_with_balance']) ?></td>
                <td class="x5pwa-num-red"><?= $fmt($consentStats['not_asked']['balance']) ?></td>
            </tr>
            <?php if ((int)$consentStats['other']['users'] > 0): ?>
            <tr>
                <td><b>Другое</b> (некорректное значение)</td>
                <td class="x5pwa-num"><?= $fmt($consentStats['other']['users']) ?></td>
                <td class="x5pwa-num">—</td>
                <td class="x5pwa-num-red"><?= $fmt($consentStats['other']['balance']) ?></td>
            </tr>
            <?php endif ?>
        </tbody>
        <tfoot>
            <tr style="background:#f0f2f5;font-weight:600">
                <td>Итого</td>
                <td class="x5pwa-num"><?= $fmt($consentStats['total']['users']) ?></td>
                <td class="x5pwa-num"><?= $fmt($consentStats['total']['users_with_balance']) ?></td>
                <td class="x5pwa-num"><?= $fmt($consentStats['total']['balance']) ?></td>
            </tr>
        </tfoot>
    </table>

    <div style="font-size:11px;color:#888;margin-top:12px">
        Проверка по компаниям: <code>stats/global.json</code> считает только пользователей с
        <code>UF_CONSENT_GRANTED = 'Y'</code>. Если компания отсутствует в таблице ниже — значит,
        у всех её пользователей нет согласия, либо у них пустая <code>UF_THEME</code>.
        Для проверки конкретных пользователей: <b>PWA → Пользователи</b>, фильтр «Согласие ПД → Не дано».
    </div>
</div>
<?php endif ?>
<!-- Балансы -->
<div class="x5pwa-box">
    <h3>Баланс яблок</h3>
    <div class="x5pwa-grid" style="grid-template-columns: repeat(2, 1fr)">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['TOTAL_APPLES'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Всего в балансах<br><small>накопительно</small></div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-green"><?= $fmt($totalEarned) ?></div>
            <div class="x5pwa-metric-label">Собрано за период</div>
        </div>
    </div>
    <div style="font-size:11px;color:#888;margin-top:12px">
        «Всего в балансах» — сумма текущих балансов всех пользователей (накопительно за всё время).
        «Собрано за период» — сумма начислений в диапазоне периода. Разные цифры — норма: баланс включает более ранние периоды.
    </div>
</div>

<!-- Компании -->
<div class="x5pwa-box">
    <h3>Компании (<?= count($companies) ?>)</h3>
    <?php if (empty($companies)): ?>
        <div style="color:#888">Нет данных по компаниям.</div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="x5pwa-table">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Компания</th>
                        <th style="text-align:right;width:70px">Юзеров</th>
                        <th style="text-align:right">Собрано<br><small>период</small></th>
                        <th style="text-align:right">Возможно<br><small>период</small></th>
                        <th style="text-align:right">Недостаёт<br><small>период</small></th>
                        <th style="text-align:right;width:70px">Эфф.<br><small>период</small></th>
                        <th style="text-align:right">Собрано<br><small>сегодня</small></th>
                        <th style="text-align:right">Возможно<br><small>сегодня</small></th>
                        <th style="text-align:right">Недостаёт<br><small>сегодня</small></th>
                        <th style="text-align:right;width:70px">Эфф.<br><small>сегодня</small></th>
                        <th style="text-align:right">Лекций</th>
                        <th style="text-align:right">Заданий</th>
                        <th style="text-align:right">Привычек</th>
                        <th style="text-align:right">Шагов</th>
                        <th style="text-align:right">Событий</th>
                        <th style="text-align:right">Баланс<br><small style="font-weight:400;color:#888">всего</small></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($companies as $i => $c): ?>
                        <?php
                        $percent = (float)($c['PERCENT'] ?? 0);
                        $color   = $effColor($percent);
                        $users   = (int)($c['USERS'] ?? 0);
                        $earned  = (int)($c['EARNED'] ?? 0);
                        $max     = (int)($c['MAX'] ?? 0);
                        $missing = (int)($c['MISSING'] ?? 0);
                        $earnedDay  = (int)($c['EARNED_DAY'] ?? 0);
                        $maxDay     = (int)($c['MAX_DAY'] ?? 0);
                        $missingDay = (int)($c['MISSING_DAY'] ?? 0);
                        $percentDay = (float)($c['PERCENT_DAY'] ?? 0);
                        $colorDay   = $effColor($percentDay);
                        $balance = (int)($c['TOTAL_APPLES'] ?? 0);
                        $lect    = (int)($c['LECTURES'] ?? 0);
                        $task    = (int)($c['TASKS'] ?? 0);
                        $habit   = (int)($c['HABITS'] ?? 0);
                        $stp     = (int)($c['STEPS'] ?? 0);
                        $evt     = (int)($c['EVENTS_CONFIRMED'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <b><?= htmlspecialcharsbx((string)($c['NAME'] ?? $c['ID'] ?? '—')) ?></b>
                                <?php if (!empty($c['ID'])): ?>
                                    <span class="x5pwa-tag"><?= htmlspecialcharsbx((string)$c['ID']) ?></span>
                                <?php endif ?>
                            </td>
                            <td class="x5pwa-num"><?= $fmt($users) ?></td>
                            <td class="x5pwa-num-green"><?= $fmt($earned) ?></td>
                            <td class="x5pwa-num-grey"><?= $fmt($max) ?></td>
                            <td class="x5pwa-num-red"><?= $missing > 0 ? '−' . $fmt($missing) : '0' ?></td>
                            <td style="text-align:right;font-weight:600;color:<?= $color ?>;font-family:monospace">
                                <?= number_format($percent, 1, '.', '') ?>%
                            </td>
                            <td class="x5pwa-num-green"><?= $fmt($earnedDay) ?></td>
                            <td class="x5pwa-num-grey"><?= $fmt($maxDay) ?></td>
                            <td class="x5pwa-num-red"><?= $missingDay > 0 ? '−' . $fmt($missingDay) : '0' ?></td>
                            <td style="text-align:right;font-weight:600;color:<?= $colorDay ?>;font-family:monospace">
                                <?= number_format($percentDay, 1, '.', '') ?>%
                            </td>
                            <td class="x5pwa-num"><?= $fmt($lect) ?></td>
                            <td class="x5pwa-num"><?= $fmt($task) ?></td>
                            <td class="x5pwa-num"><?= $fmt($habit) ?></td>
                            <td class="x5pwa-num"><?= $fmt($stp) ?></td>
                            <td class="x5pwa-num"><?= $fmt($evt) ?></td>
                            <td class="x5pwa-num"><?= $fmt($balance) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0f2f5;font-weight:600">
                        <td colspan="2">Итого</td>
                        <td class="x5pwa-num"><?= $fmt($gp['TOTAL_USERS'] ?? 0) ?></td>
                        <td class="x5pwa-num-green"><?= $fmt($totalEarned) ?></td>
                        <td class="x5pwa-num-grey"><?= $fmt($totalMax) ?></td>
                        <td class="x5pwa-num-red"><?= $totalMissing > 0 ? '−' . $fmt($totalMissing) : '0' ?></td>
                        <td style="text-align:right;font-weight:700;color:<?= $effColor($avgEff) ?>;font-family:monospace">
                            <?= number_format($avgEff, 1, '.', '') ?>%
                        </td>
                        <td class="x5pwa-num-green"><?= $fmt($totalEarnedDay) ?></td>
                        <td class="x5pwa-num-grey"><?= $fmt($totalMaxDay) ?></td>
                        <td class="x5pwa-num-red"><?= $totalMissingDay > 0 ? '−' . $fmt($totalMissingDay) : '0' ?></td>
                        <td style="text-align:right;font-weight:700;color:<?= $effColor($avgEffDay) ?>;font-family:monospace">
                            <?= number_format($avgEffDay, 1, '.', '') ?>%
                        </td>
                        <td class="x5pwa-num"><?= $fmt($gp['LECTURES_READ'] ?? 0) ?></td>
                        <td class="x5pwa-num"><?= $fmt($gp['TASKS_DONE'] ?? 0) ?></td>
                        <td class="x5pwa-num"><?= $fmt($gp['HABITS_DAYS'] ?? 0) ?></td>
                        <td class="x5pwa-num"><?= $fmt($gp['STEPS_CONFIRMED'] ?? 0) ?></td>
                        <td class="x5pwa-num"><?= $fmt($gp['EVENTS_CONFIRMED'] ?? 0) ?></td>
                        <td class="x5pwa-num"><?= $fmt($gp['TOTAL_APPLES'] ?? 0) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div style="font-size:11px;color:#888;margin-top:12px">
            Колонки «Собрано / Возможно / Недостаёт / Эфф.» — <b>за период</b> и <b>за сегодня</b>.
            Колонки «Лекций / Заданий / Привычек / Шагов / Событий» — за период.
            Колонка «Баланс» — накопительная, за всё время.
            Учитываются только пользователи с выбранной темой и согласием на ПД.
        </div>
    <?php endif ?>
</div>

<div class="x5pwa-box">
    <h3>Быстрые действия</h3>
    <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block; margin-right:12px">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="x5_action" value="recalc_caches">
        <input type="submit" value="Пересчитать кеши" class="adm-btn"
               onclick="return confirm('Пересчитать RANK / EFFICIENCY / EARNED / MAX / MISSING по всем активным пользователям?')">
    </form>

    <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="x5_action" value="refresh_stats">
        <input type="submit" value="Обновить stats/global.json на CDN" class="adm-btn adm-btn-save"
               onclick="return confirm('Пересобрать stats/global.json и залить в S3?')">
    </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';