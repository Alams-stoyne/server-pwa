<?php
/**
 * PWA — ТОП шагомера.
 *
 * Показывает:
 *   • Сводку по всем участникам.
 *   • ТОП-15 (all-time или на выбранную дату).
 *   • ТОП по местам работы.
 *   • Прогресс школы (school.json).
 *
 * Источник данных: StepsBuilder::buildLeaderboardForDate() / buildSchoolProgress().
 * Кнопка «Пересобрать на CDN» вызывает Dumper::regenerateStepLeaderboards() —
 * тот же код, что обновляет JSON на S3.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\Dumper;
use X5\Pwa\S3;
use X5\Pwa\StepsBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — ТОП шагомера');

// ── Параметры ──────────────────────────────────────────────────
$dateRaw = trim((string)($_GET['date'] ?? ''));
$date    = null;
$dateTitle = 'За всё время';

if ($dateRaw === 'today') {
    $date = date('Y-m-d');
} elseif ($dateRaw === 'yesterday') {
    $date = date('Y-m-d', strtotime('-1 day'));
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
    $date = $dateRaw;
}

if ($date !== null) {
    $dateTitle = 'На ' . date('d.m.Y', strtotime($date));
}

$asOf = $date;

// ── Действия ──────────────────────────────────────────────────
$actionMessage = '';
$actionError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['x5_action'] ?? '');

    if ($action === 'regenerate') {
        try {
            $res = Dumper::regenerateStepLeaderboards(true);
            if ((int)$res['failed'] === 0) {
                $actionMessage = sprintf(
                    'Готово: %d файлов загружено в S3 за %ss.',
                    (int)$res['sent'],
                    (string)$res['elapsed']
                );
            } else {
                $actionMessage = sprintf(
                    'Загружено: %d, ошибок: %d за %ss.',
                    (int)$res['sent'], (int)$res['failed'], (string)$res['elapsed']
                );
                if (!empty($res['errors'])) {
                    $actionError = implode("\n", $res['errors']);
                }
            }
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    }

    if ($action === 'download') {
        // Скачиваем актуальный JSON (как будто с CDN — тот же код)
        $payload = StepsBuilder::buildLeaderboardForDate($asOf);
        $filename = $asOf !== null
            ? 'steps_leaderboard_' . $asOf . '.json'
            : 'steps_leaderboard.json';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($action === 'download_school') {
        $payload = StepsBuilder::buildSchoolProgress();

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="steps_school.json"');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ── Загрузка данных ──────────────────────────────────────────
$leaderboard = StepsBuilder::buildLeaderboardForDate($asOf);
$school      = StepsBuilder::buildSchoolProgress();

// Проверим, что реально лежит в локальном кеше S3 (что видят клиенты)
$cacheKey = $asOf !== null
    ? 'steps/leaderboard/' . $asOf . '.json'
    : 'steps/leaderboard.json';

$cdnCachedRaw = S3::readLocal($cacheKey);
$cdnCached = null;
if ($cdnCachedRaw !== null) {
    $decoded = json_decode($cdnCachedRaw, true);
    if (is_array($decoded)) $cdnCached = $decoded;
}

// CDN URL
$s3cfg = X5\Pwa\Settings::s3();
$cdnBase = trim((string)($s3cfg['endpoint'] ?? 'https://cdn.x5hd.ru'), '/');
$cdnUrl = $cdnBase . '/' . ($s3cfg['prefix'] ?? '') . '/' . $cacheKey;
$cdnUrl = preg_replace('#(?<!:)//+#', '/', $cdnUrl);

$formatSteps = function (int $n): string {
    return number_format($n, 0, '', ' ');
};

// Опции для селекта дат (all-time + 14 дней)
$dateOptions = [
    ''          => 'За всё время',
    'today'     => 'Сегодня (' . date('d.m.Y') . ')',
    'yesterday' => 'Вчера (' . date('d.m.Y', strtotime('-1 day')) . ')',
];
for ($i = 2; $i <= 14; $i++) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dateOptions[$d] = date('d.m.Y', strtotime($d));
}
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5lb-box { background:#fff; padding:16px; margin-bottom:16px; border:1px solid #e2e5e9; }
    .x5lb-box h3 { margin:0 0 12px 0; font-size:14px; }
    .x5lb-table { width:100%; border-collapse:collapse; }
    .x5lb-table th { text-align:left; padding:8px 12px; border-bottom:2px solid #e2e5e9; background:#f8f9fa; font-size:12px; font-weight:600; color:#555; }
    .x5lb-table td { padding:8px 12px; border-bottom:1px solid #eee; font-size:13px; }
    .x5lb-table tr:hover td { background:#f8f9fa; }
    .x5lb-rank { font-weight:700; color:#2c3e50; width:60px; }
    .x5lb-rank-1 { color:#c9a227; }
    .x5lb-rank-2 { color:#8a8a8a; }
    .x5lb-rank-3 { color:#a8703f; }
    .x5lb-steps { text-align:right; font-weight:600; font-family:monospace; }
    .x5lb-mono { font-family:monospace; font-size:12px; }
    .x5lb-metric { background:#f5f6f8; padding:12px; border-radius:6px; text-align:center; }
    .x5lb-metric-value { font-size:22px; font-weight:600; color:#2c3e50; }
    .x5lb-metric-label { font-size:11px; color:#666; text-transform:uppercase; margin-top:4px; }
    .x5lb-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:16px; }
    .x5lb-workplace { border:1px solid #e2e5e9; padding:12px; margin-bottom:12px; border-radius:6px; }
    .x5lb-workplace-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #eee; }
    .x5lb-workplace-name { font-weight:600; }
    .x5lb-workplace-stats { font-size:12px; color:#666; }
    .x5lb-toolbar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
    .x5lb-toolbar form { display:inline-flex; gap:8px; align-items:center; }
    .x5lb-tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; background:#e0efff; color:#1e6bcc; }
    .x5lb-warn { background:#fff4e0; border:1px solid #f5a623; padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_steps.php?lang=<?= LANGUAGE_ID ?>">← К модерации шагов</a>
</div>

<?php if ($actionMessage !== ''): ?>
    <div class="adm-info-message-wrap adm-info-message-green" style="margin-bottom:12px">
        <div class="adm-info-message"><?= htmlspecialcharsbx($actionMessage) ?></div>
    </div>
<?php endif ?>
<?php if ($actionError !== ''): ?>
    <div class="adm-info-message-wrap adm-info-message-red" style="margin-bottom:12px">
        <div class="adm-info-message"><pre style="margin:0;white-space:pre-wrap"><?= htmlspecialcharsbx($actionError) ?></pre></div>
    </div>
<?php endif ?>

<div class="x5lb-box">
    <h3>Параметры</h3>
    <div class="x5lb-toolbar">
        <form method="GET" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>">
            <?= bitrix_sessid_post() ?>
            <label>Период:</label>
            <select name="date" onchange="this.form.submit()">
                <?php foreach ($dateOptions as $val => $label): ?>
                    <?php
                    $sel = '';
                    if ($val === '' && $date === null) $sel = 'selected';
                    elseif ($val === 'today' && $date === date('Y-m-d')) $sel = 'selected';
                    elseif ($val === 'yesterday' && $date === date('Y-m-d', strtotime('-1 day'))) $sel = 'selected';
                    elseif ($val !== '' && $val === $date) $sel = 'selected';
                    ?>
                    <option value="<?= htmlspecialcharsbx($val) ?>" <?= $sel ?>><?= htmlspecialcharsbx($label) ?></option>
                <?php endforeach ?>
            </select>
            <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        </form>

        <form method="POST" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?lang=<?= LANGUAGE_ID ?>">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="x5_action" value="regenerate">
            <button type="submit" class="adm-btn adm-btn-save"
                    onclick="return confirm('Пересобрать все файлы ТОПа на CDN? Это может занять несколько секунд.')">
                Пересобрать на CDN
            </button>
        </form>

        <form method="POST" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?lang=<?= LANGUAGE_ID ?>">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="x5_action" value="download">
            <button type="submit" class="adm-btn">Скачать JSON ТОПа</button>
        </form>

        <form method="POST" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?lang=<?= LANGUAGE_ID ?>">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="x5_action" value="download_school">
            <button type="submit" class="adm-btn">Скачать JSON школы</button>
        </form>
    </div>
    <div style="margin-top:8px; font-size:12px; color:#666">
        Дата: <b><?= htmlspecialcharsbx($dateTitle) ?></b>
        · CDN: <a href="<?= htmlspecialcharsbx($cdnUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialcharsbx($cdnUrl) ?></a>
    </div>
</div>

<div class="x5lb-box">
    <h3>Сводка</h3>
    <div class="x5lb-grid">
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= $formatSteps((int)($leaderboard['TOTAL_PARTICIPANTS'] ?? 0)) ?></div>
            <div class="x5lb-metric-label">Участников</div>
        </div>
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= $formatSteps((int)($leaderboard['TOTAL_STEPS'] ?? 0)) ?></div>
            <div class="x5lb-metric-label">Всего шагов</div>
        </div>
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= $formatSteps((int)($school['AVG_STEPS'] ?? 0)) ?></div>
            <div class="x5lb-metric-label">Средний шаг</div>
        </div>
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= htmlspecialcharsbx((string)($school['AVG_PROGRESS_PERCENT'] ?? '0')) ?>%</div>
            <div class="x5lb-metric-label">Средний прогресс</div>
        </div>
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= $formatSteps((int)($school['USERS_AT_GOAL'] ?? 0)) ?></div>
            <div class="x5lb-metric-label">Достигли цели</div>
        </div>
        <div class="x5lb-metric">
            <div class="x5lb-metric-value"><?= $formatSteps((int)($school['USERS_ABOVE_HALF'] ?? 0)) ?></div>
            <div class="x5lb-metric-label">Больше половины</div>
        </div>
    </div>
</div>

<div class="x5lb-box">
    <h3>ТОП-15 · <?= htmlspecialcharsbx($dateTitle) ?></h3>
    <?php if (empty($leaderboard['ALL'])): ?>
        <div style="color:#888">Пока нет подтверждённых шагов.</div>
    <?php else: ?>
        <table class="x5lb-table">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th>Никнейм</th>
                    <th>Место работы</th>
                    <th style="text-align:right">Шаги</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaderboard['ALL'] as $row): ?>
                    <?php
                    $rank = (int)($row['rank'] ?? 0);
                    $rankClass = '';
                    if ($rank === 1) $rankClass = 'x5lb-rank-1';
                    elseif ($rank === 2) $rankClass = 'x5lb-rank-2';
                    elseif ($rank === 3) $rankClass = 'x5lb-rank-3';
                    ?>
                    <tr>
                        <td class="x5lb-rank <?= $rankClass ?>"><?= $rank ?></td>
                        <td><?= htmlspecialcharsbx((string)($row['nickname'] ?? '—')) ?></td>
                        <td>
                            <?php if (!empty($row['workplace'])): ?>
                                <span class="x5lb-tag"><?= htmlspecialcharsbx((string)$row['workplace']) ?></span>
                            <?php else: ?>
                                <span style="color:#aaa">—</span>
                            <?php endif ?>
                        </td>
                        <td class="x5lb-steps"><?= $formatSteps((int)($row['steps'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>

<div class="x5lb-box">
    <h3>По местам работы</h3>
    <?php if (empty($leaderboard['BY_WORKPLACE']) || !is_array($leaderboard['BY_WORKPLACE'])): ?>
        <div style="color:#888">Нет данных по местам работы.</div>
    <?php else: ?>
        <?php foreach ($leaderboard['BY_WORKPLACE'] as $wpKey => $wp): ?>
            <div class="x5lb-workplace">
                <div class="x5lb-workplace-header">
                    <div class="x5lb-workplace-name"><?= htmlspecialcharsbx((string)($wp['workplace'] ?? $wpKey)) ?></div>
                    <div class="x5lb-workplace-stats">
                        <?= $formatSteps((int)($wp['TOTAL_PARTICIPANTS'] ?? 0)) ?> участников ·
                        <?= $formatSteps((int)($wp['TOTAL_STEPS'] ?? 0)) ?> шагов
                    </div>
                </div>
                <?php if (empty($wp['TOP'])): ?>
                    <div style="color:#888;font-size:12px">Нет участников.</div>
                <?php else: ?>
                    <table class="x5lb-table">
                        <thead>
                            <tr>
                                <th style="width:60px">#</th>
                                <th>Никнейм</th>
                                <th style="text-align:right">Шаги</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wp['TOP'] as $r): ?>
                                <tr>
                                    <td class="x5lb-rank"><?= (int)($r['rank'] ?? 0) ?></td>
                                    <td><?= htmlspecialcharsbx((string)($r['nickname'] ?? '—')) ?></td>
                                    <td class="x5lb-steps"><?= $formatSteps((int)($r['steps'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</div>

<div class="x5lb-box">
    <h3>Что реально лежит на CDN (локальный кеш)</h3>
    <?php if ($cdnCached === null): ?>
        <div class="x5lb-warn">
            Файл <span class="x5lb-mono"><?= htmlspecialcharsbx($cacheKey) ?></span>
            отсутствует в локальном кеше. Скорее всего, файлы ещё не генерировались,
            либо их не удалось скачать с S3. Нажмите «Пересобрать на CDN».
        </div>
    <?php else: ?>
        <div style="font-size:12px; color:#666; margin-bottom:8px">
            UPDATED_AT: <b><?= htmlspecialcharsbx((string)($cdnCached['UPDATED_AT'] ?? '—')) ?></b>
            · AS_OF_DATE: <b><?= htmlspecialcharsbx((string)($cdnCached['AS_OF_DATE'] ?? 'null')) ?></b>
            · Участников: <b><?= $formatSteps((int)($cdnCached['TOTAL_PARTICIPANTS'] ?? 0)) ?></b>
            · Шагов: <b><?= $formatSteps((int)($cdnCached['TOTAL_STEPS'] ?? 0)) ?></b>
        </div>
        <?php if (!empty($cdnCached['ALL'])): ?>
            <div style="font-size:12px; color:#666; margin-bottom:4px">Первая строка топа на CDN:</div>
            <div class="x5lb-mono">
                <?php
                $first = $cdnCached['ALL'][0] ?? null;
                if ($first !== null) {
                    echo htmlspecialcharsbx(json_encode($first, JSON_UNESCAPED_UNICODE));
                }
                ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>