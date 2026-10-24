<?php
/**
 * Общая статистика PWA.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use X5\Pwa\Dumper;
use X5\Pwa\S3;
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

    // ── Сохранение периода ──
    if ($action === 'save_period' || $action === 'clear_period') {
        $startRaw = (string)($_POST['stats_period_start'] ?? '');
        $endRaw   = (string)($_POST['stats_period_end']   ?? '');
        $start    = trim($startRaw);
        $end      = trim($endRaw);

        if ($action === 'clear_period') {
            $start = '';
            $end   = '';
        }

        $valid = true;
        if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $error = 'Некорректная дата старта (ожидается YYYY-MM-DD).';
            $valid = false;
        }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $error = 'Некорректная дата конца (ожидается YYYY-MM-DD).';
            $valid = false;
        }
        if ($valid && $start !== '' && $end !== '' && $start > $end) {
            $error = 'Дата старта не может быть позже даты конца.';
            $valid = false;
        }

        if ($valid) {
            Settings::set('stats_period_start', $start);
            Settings::set('stats_period_end',   $end);

            $doRecalc = $action === 'save_period'
                && (($_POST['do_recalc'] ?? '') === 'Y');

            $report = [];

            if ($doRecalc) {
                try {
                    $r = StatsBuilder::recalcAllStats();
                    $report[] = sprintf(
                        'Кеши пересчитаны: обработано %d за %ss',
                        (int)($r['processed'] ?? 0),
                        (string)($r['elapsed'] ?? '?')
                    );
                } catch (\Throwable $e) {
                    $error = 'Ошибка пересчёта: ' . $e->getMessage();
                }
            }

            // Всегда обновляем stats/global.json и config/period.json
            try {
                $r = Dumper::regenerateStats();
                $report[] = !empty($r['ok'])
                    ? 'stats/global.json обновлён'
                    : 'stats/global.json: ' . ($r['error'] ?? '?');
            } catch (\Throwable $e) {
                $report[] = 'stats/global.json FAIL: ' . $e->getMessage();
            }

            try {
                $payload = json_encode(
                    ['START' => $start, 'END' => $end],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );
                $r = S3::upload('config/period.json', (string)$payload);
                $report[] = !empty($r['ok'])
                    ? 'config/period.json обновлён'
                    : 'config/period.json: ' . ($r['message'] ?? '?');
            } catch (\Throwable $e) {
                $report[] = 'config/period.json FAIL: ' . $e->getMessage();
            }

            if ($error === null) {
                $message = ($action === 'clear_period'
                    ? 'Период очищен. '
                    : 'Период сохранён. ') . implode(' · ', $report);
            }
        }
    }

    // ── Принудительный пересчёт кешей ──
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

    // ── Обновление stats/global.json ──
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
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 16px 0; font-size: 15px; }
    .x5pwa-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .x5pwa-metric { background: #f5f6f8; padding: 20px; border-radius: 6px; text-align: center; }
    .x5pwa-metric-value { font-size: 32px; font-weight: 700; color: #1e6bcc; }
    .x5pwa-metric-label { font-size: 12px; color: #666; text-transform: uppercase; margin-top: 6px; }
    .x5pwa-table { width: 100%; border-collapse: collapse; }
    .x5pwa-table th { text-align: left; padding: 8px 12px; border-bottom: 2px solid #e2e5e9; background: #f8f9fa; font-size: 12px; font-weight: 600; color: #555; }
    .x5pwa-table td { padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
    .x5pwa-table tr:hover td { background: #f8f9fa; }
    .x5pwa-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e0efff; color: #1e6bcc; }
    .x5pwa-bar { background: #e2e5e9; height: 8px; border-radius: 4px; overflow: hidden; width: 120px; display: inline-block; vertical-align: middle; }
    .x5pwa-bar-fill { background: #3a7d1c; height: 100%; }
    .x5pwa-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
    .x5pwa-period-form { display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
    .x5pwa-period-form label { display: flex; flex-direction: column; font-size: 12px; color: #666; gap: 4px; }
    .x5pwa-period-form input[type="date"] { padding: 6px 8px; font-size: 13px; border: 1px solid #c5c9cf; border-radius: 3px; font-family: inherit; }
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
    <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>" style="margin-bottom:12px">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="x5_action" value="save_period">

        <div class="x5pwa-period-form">
            <label>
                Старт
                <input type="date" name="stats_period_start"
                       value="<?= htmlspecialcharsbx($periodStart) ?>">
            </label>
            <label>
                Конец
                <input type="date" name="stats_period_end"
                       value="<?= htmlspecialcharsbx($periodEnd) ?>">
            </label>
            <label style="flex-direction:row; align-items:center; gap:6px; padding-bottom:8px">
                <input type="checkbox" name="do_recalc" value="Y" checked>
                Пересчитать кеши после сохранения
            </label>
            <input type="submit" value="Сохранить" class="adm-btn adm-btn-save"
                   onclick="return confirm('Сохранить период?\n\nЕсли «Пересчитать» включено — это может занять время.')">
        </div>

        <div style="margin-top:12px; font-size:12px; color:#666">
            Если поля пусты — период вычисляется автоматически:
            <b>от минимальной даты регистрации</b> (не раньше чем за 365 дней) <b>до сегодня</b>.
            После сохранения обновятся <span class="x5pwa-tag">stats/global.json</span>
            и <span class="x5pwa-tag">config/period.json</span> на CDN.
        </div>
    </form>

    <?php if ($periodStart !== '' || $periodEnd !== ''): ?>
        <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="x5_action" value="clear_period">
            <input type="submit" value="Очистить период (авто)" class="adm-btn"
                   onclick="return confirm('Убрать явные даты периода и вернуть автоопределение?')">
        </form>
    <?php endif ?>

    <dl style="display:grid;grid-template-columns:260px 1fr;gap:6px 12px;margin:16px 0 0 0">
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

<div class="x5pwa-box">
    <h3>Итоговые показатели</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $fmt($gp['TOTAL_APPLES'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Всего яблок</div>
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
                    <th style="width:60px">#</th>
                    <th>Компания</th>
                    <th style="text-align:right">Яблок</th>
                    <th style="text-align:right; width:80px">Эффект.</th>
                    <th style="width:160px">Прогресс</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $i => $c): ?>
                    <?php
                    $percent = (float)($c['PERCENT'] ?? 0);
                    $pct = max(0, min(100, $percent));
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <b><?= htmlspecialcharsbx((string)($c['NAME'] ?? $c['ID'] ?? '—')) ?></b>
                            <?php if (!empty($c['ID'])): ?>
                                <span class="x5pwa-tag"><?= htmlspecialcharsbx((string)$c['ID']) ?></span>
                            <?php endif ?>
                        </td>
                        <td style="text-align:right; font-family:monospace">
                            <?= $fmt($c['TOTAL_APPLES'] ?? 0) ?>
                        </td>
                        <td style="text-align:right; font-weight:600">
                            <?= number_format($percent, 1, '.', '') ?>%
                        </td>
                        <td>
                            <div class="x5pwa-bar">
                                <div class="x5pwa-bar-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>

<div class="x5pwa-box">
    <h3>Действия</h3>
    <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block; margin-right:12px">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="x5_action" value="recalc_caches">
        <input type="submit" value="Пересчитать кеши" class="adm-btn"
               onclick="return confirm('Пересчитать RANK / EFFICIENCY / HABITS_DAYS по всем активным пользователям?')">
    </form>

    <form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="x5_action" value="refresh_stats">
        <input type="submit" value="Обновить stats/global.json на CDN" class="adm-btn adm-btn-save"
               onclick="return confirm('Пересобрать stats/global.json и залить в S3?')">
    </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>