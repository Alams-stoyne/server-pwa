<?php
/**
 * Общая статистика PWA.
 * С абсолютными показателями: собрано / возможно / недостаёт / средняя эффективность.
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

// Новые агрегаты
$totalEarned  = (int)($gp['EARNED_TOTAL'] ?? 0);
$totalMax     = (int)($gp['MAX_TOTAL'] ?? 0);
$totalMissing = (int)($gp['MISSING_TOTAL'] ?? 0);
$avgEff       = (float)($gp['AVG_EFFICIENCY'] ?? 0);

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
    .x5pwa-table { width: 100%; border-collapse: collapse; }
    .x5pwa-table th { text-align: left; padding: 8px 12px; border-bottom: 2px solid #e2e5e9; background: #f8f9fa; font-size: 12px; font-weight: 600; color: #555; }
    .x5pwa-table td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
    .x5pwa-table tr:hover td { background: #f8f9fa; }
    .x5pwa-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e0efff; color: #1e6bcc; }
    .x5pwa-bar { background: #e2e5e9; height: 8px; border-radius: 4px; overflow: hidden; width: 120px; display: inline-block; vertical-align: middle; }
    .x5pwa-bar-fill { height: 100%; }
    .x5pwa-mono { font-family: monospace; }
    .x5pwa-num { font-family: monospace; text-align: right; }
    .x5pwa-num-green { color: #3a7d1c; font-family: monospace; text-align: right; }
    .x5pwa-num-red { color: #c0392b; font-family: monospace; text-align: right; }
    .x5pwa-num-grey { color: #555; font-family: monospace; text-align: right; }
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

<!-- ЭФФЕКТИВНОСТЬ: собрано / возможно / недостаёт -->
<div class="x5pwa-box">
    <h3>Эффективность</h3>
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

<div class="x5pwa-box">
    <h3>Итоговые показатели</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['TOTAL_APPLES'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Всего яблок<br><small>в балансах</small></div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['TOTAL_USERS'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Участников</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['LECTURES_READ'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Лекций прочитано</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['TASKS_DONE'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Заданий выполнено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['HABITS_DAYS'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Дней привычки</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['STEPS_CONFIRMED'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Шагов подтверждено</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Компании (<?= count($companies) ?>)</h3>
    <?php if (empty($companies)): ?>
        <div style="color:#888">Нет данных по компаниям.</div>
    <?php else: ?>
        <table class="x5pwa-table">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Компания</th>
                    <th style="text-align:right;width:80px">Юзеров</th>
                    <th style="text-align:right">Собрано</th>
                    <th style="text-align:right">Возможно</th>
                    <th style="text-align:right">Недостаёт</th>
                    <th style="text-align:right;width:80px">Эфф.</th>
                    <th style="width:180px">Прогресс</th>
                    <th style="text-align:right;width:100px">Баланс</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $i => $c): ?>
                    <?php
                    $percent = (float)($c['PERCENT'] ?? 0);
                    $pct     = max(0, min(100, $percent));
                    $color   = $effColor($percent);
                    $users   = (int)($c['USERS'] ?? 0);
                    $earned  = (int)($c['EARNED'] ?? 0);
                    $max     = (int)($c['MAX'] ?? 0);
                    $missing = (int)($c['MISSING'] ?? 0);
                    $balance = (int)($c['TOTAL_APPLES'] ?? 0);
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
                        <td class="x5pwa-num-red">
                            <?= $missing > 0 ? '−' . $fmt($missing) : '0' ?>
                        </td>
                        <td style="text-align:right;font-weight:600;color:<?= $color ?>;font-family:monospace">
                            <?= number_format($percent, 1, '.', '') ?>%
                        </td>
                        <td>
                            <div class="x5pwa-bar" style="width:100%">
                                <div class="x5pwa-bar-fill"
                                     style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                            </div>
                        </td>
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
                    <td class="x5pwa-num-red">
                        <?= $totalMissing > 0 ? '−' . $fmt($totalMissing) : '0' ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:<?= $effColor($avgEff) ?>;font-family:monospace">
                        <?= number_format($avgEff, 1, '.', '') ?>%
                    </td>
                    <td></td>
                    <td class="x5pwa-num"><?= $fmt($gp['TOTAL_APPLES'] ?? 0) ?></td>
                </tr>
            </tfoot>
        </table>

        <div style="font-size:11px;color:#888;margin-top:12px">
            <b>Собрано</b> — сумма начисленных яблок за период (из журнала наград).
            <b>Возможно</b> — сумма дневных максимумов для пользователей компании (BASE + направление + привычка).
            <b>Баланс</b> — текущий баланс яблок пользователей компании (накопительный итог).
            Если «Собрано» и «Баланс» расходятся — это нормально: баланс включает более ранние периоды.
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