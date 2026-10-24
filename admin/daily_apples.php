<?php
/**
 * PWA — Максимум яблок по дням.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\Settings;
use X5\Pwa\StatsBuilder;
use X5\Pwa\Storage;
use X5\Pwa\ThemesResolver;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

$APPLICATION->SetTitle('PWA — Максимум яблок по дням');

// ── Период ──
[$periodFrom, $periodTo] = StatsBuilder::getEffectivePeriod();

$from = trim((string)($_REQUEST['from'] ?? ''));
$to   = trim((string)($_REQUEST['to']   ?? ''));

if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $periodFrom;
if ($to   === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $periodTo;
if ($from > $to) [$from, $to] = [$to, $from];

// ── Действия ──
$message = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['x5_action'] ?? '');

    if ($action === 'recalc_range' || $action === 'clear_and_recalc') {
        set_time_limit(300);
        try {
            $startTime = microtime(true);

            if ($action === 'clear_and_recalc') {
                $conn      = Application::getConnection();
                $sqlHelper = $conn->getSqlHelper();
                $fromSafe  = $sqlHelper->forSql($from);
                $toSafe    = $sqlHelper->forSql($to);
                $conn->queryExecute(
                    "DELETE FROM x5_pwa_daily_apples
                     WHERE UF_DATE >= '{$fromSafe}' AND UF_DATE <= '{$toSafe}'"
                );
            }

            $cursor = strtotime($from);
            $endTs  = strtotime($to);
            $cnt = 0;
            if ($cursor !== false && $endTs !== false) {
                while ($cursor <= $endTs) {
                    $d = date('Y-m-d', $cursor);
                    $apples = StatsBuilder::calculateDailyApples($d);
                    Storage::saveDailyApples($d, $apples);
                    $cnt++;
                    $cursor = strtotime('+1 day', $cursor);
                }
            }
            $elapsed = round(microtime(true) - $startTime, 2);
            $message = "Пересчитано {$cnt} дн. за {$elapsed} сек." . ($action === 'clear_and_recalc' ? ' (с очисткой)' : '');
        } catch (\Throwable $e) {
            $error = 'Ошибка пересчёта: ' . $e->getMessage();
        }
    }

    if ($action === 'clear_range') {
        try {
            $conn      = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $fromSafe  = $sqlHelper->forSql($from);
            $toSafe    = $sqlHelper->forSql($to);

            $cntRow = $conn->query(
                "SELECT COUNT(*) AS C FROM x5_pwa_daily_apples
                 WHERE UF_DATE >= '{$fromSafe}' AND UF_DATE <= '{$toSafe}'"
            )->fetch();
            $before = (int)($cntRow['C'] ?? 0);

            $conn->queryExecute(
                "DELETE FROM x5_pwa_daily_apples
                 WHERE UF_DATE >= '{$fromSafe}' AND UF_DATE <= '{$toSafe}'"
            );

            $message = "Удалено {$before} записей кеша за период {$from}..{$to}.";
        } catch (\Throwable $e) {
            $error = 'Ошибка удаления: ' . $e->getMessage();
        }
    }
}

// ── Данные ──
$themes = ThemesResolver::getAllCodes();
if (!$themes) $themes = ['energy', 'antistress', 'body', 'balance'];

$themeTitles = [];
foreach (\X5\Pwa\ThemesBuilder::getAll() as $t) {
    $themeTitles[(string)$t['id']] = (string)$t['name'];
}

$rows = [];
$cursor = strtotime($from);
$endTs  = strtotime($to);
$totals = [
    'BASE'   => 0,
    'THEMES' => array_fill_keys($themes, 0),
];

if ($cursor !== false && $endTs !== false) {
    while ($cursor <= $endTs) {
        $d = date('Y-m-d', $cursor);

        $cached = Storage::getDailyApplesFromCache($d);
        $source = 'кеш';
        if ($cached === null) {
            $cached = StatsBuilder::calculateDailyApples($d);
            $source = 'расчёт';
        }

        $row = [
            'DATE'   => $d,
            'BASE'   => (int)($cached['BASE'] ?? 0),
            'THEMES' => [],
            'SOURCE' => $source,
        ];
        foreach ($themes as $code) {
            $row['THEMES'][$code] = (int)($cached['THEMES'][$code] ?? 0);
        }

        $totals['BASE'] += $row['BASE'];
        foreach ($themes as $code) {
            $totals['THEMES'][$code] += $row['THEMES'][$code];
        }

        $rows[] = $row;
        $cursor = strtotime('+1 day', $cursor);
    }
}

// ── Детализация на дату ──

/**
 * Прямой фильтр по DATE_ACTIVE_FROM (формат даты сайта, SHORT).
 * См. комментарий в StatsBuilder::sumRewardPointsForDate.
 *
 * @return array{count:int,sum:int}
 */
function countIblockForDate(int $iblockId, string $date): array
{
    if ($iblockId <= 0) return ['count' => 0, 'sum' => 0];

    $dateTs = strtotime($date);
    if ($dateTs === false) return ['count' => 0, 'sum' => 0];

    $dateSite = \ConvertTimeStamp($dateTs, 'SHORT');
    if ($dateSite === false || $dateSite === '') return ['count' => 0, 'sum' => 0];

    $rs = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID'          => $iblockId,
            'ACTIVE'             => 'Y',
            'CHECK_PERMISSIONS'  => 'N',
            '>=DATE_ACTIVE_FROM' => $dateSite,
            '<=DATE_ACTIVE_FROM' => $dateSite,
        ],
        false, false,
        ['ID']
    );

    $count = 0; $sum = 0;
    while ($el = $rs->Fetch()) {
        $count++;
        $props = \CIBlockElement::GetProperty($iblockId, (int)$el['ID'], [], ['CODE' => 'REWARD_POINTS']);
        if ($p = $props->Fetch()) $sum += max(0, (int)($p['VALUE'] ?? 0));
    }
    return ['count' => $count, 'sum' => $sum];
}

/**
 * @return array{count:int,sum:int}
 */
function countIblockForDateByTheme(int $iblockId, string $date, string $themeCode): array
{
    if ($iblockId <= 0 || $themeCode === '') return ['count' => 0, 'sum' => 0];

    $dateTs = strtotime($date);
    if ($dateTs === false) return ['count' => 0, 'sum' => 0];

    $dateSite = \ConvertTimeStamp($dateTs, 'SHORT');
    if ($dateSite === false || $dateSite === '') return ['count' => 0, 'sum' => 0];

    $rs = \CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID'          => $iblockId,
            'ACTIVE'             => 'Y',
            'CHECK_PERMISSIONS'  => 'N',
            '>=DATE_ACTIVE_FROM' => $dateSite,
            '<=DATE_ACTIVE_FROM' => $dateSite,
        ],
        false, false,
        ['ID']
    );

    $count = 0; $sum = 0;
    while ($el = $rs->Fetch()) {
        $elId = (int)$el['ID'];

        $themeValue = '';
        $props = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'THEME']);
        if ($p = $props->Fetch()) {
            $themeValue = (string)($p['VALUE'] ?? '');
        }
        if ($themeValue === '') continue;

        $resolved = ThemesResolver::resolve($themeValue);
        if ($resolved !== $themeCode) continue;

        $count++;
        $props2 = \CIBlockElement::GetProperty($iblockId, $elId, [], ['CODE' => 'REWARD_POINTS']);
        if ($p2 = $props2->Fetch()) $sum += max(0, (int)($p2['VALUE'] ?? 0));
    }
    return ['count' => $count, 'sum' => $sum];
}
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5da-box { background:#fff; padding:16px; margin-bottom:16px; border:1px solid #e2e5e9; }
    .x5da-box h3 { margin:0 0 12px 0; font-size:14px; }
    .x5da-table { width:100%; border-collapse:collapse; }
    .x5da-table th { text-align:left; padding:8px 10px; border-bottom:2px solid #e2e5e9; background:#f8f9fa; font-size:11px; font-weight:600; color:#555; }
    .x5da-table td { padding:6px 10px; border-bottom:1px solid #eee; font-size:12px; font-family:monospace; }
    .x5da-table tr:hover td { background:#f8f9fa; }
    .x5da-base { font-weight:600; color:#1e6bcc; }
    .x5da-theme { color:#3a7d1c; }
    .x5da-total { font-weight:600; color:#2c3e50; }
    .x5da-badge-cache   { background:#e6f5d8; color:#3a7d1c; padding:1px 6px; border-radius:8px; font-size:10px; }
    .x5da-badge-calc    { background:#fff4e0; color:#a52020; padding:1px 6px; border-radius:8px; font-size:10px; }
    .x5da-form { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .x5da-form label { display:flex; flex-direction:column; font-size:12px; color:#666; gap:4px; }
    .x5da-form input[type=date] { padding:6px 8px; border:1px solid #c5c9cf; border-radius:3px; }
    .x5da-legend { font-size:11px; color:#666; margin-top:8px; }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>">← К общей статистике</a>
</div>

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

<div class="x5da-box">
    <h3>Период</h3>
    <form method="POST" action="x5_pwa_daily_apples.php?lang=<?= LANGUAGE_ID ?>" style="margin-bottom:12px">
        <?= bitrix_sessid_post() ?>
        <div class="x5da-form">
            <label>С
                <input type="date" name="from" value="<?= htmlspecialcharsbx($from) ?>">
            </label>
            <label>По
                <input type="date" name="to" value="<?= htmlspecialcharsbx($to) ?>">
            </label>
            <input type="submit" value="Показать" class="adm-btn">
        </div>
    </form>

    <form method="POST" action="x5_pwa_daily_apples.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block;margin-right:8px">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="from" value="<?= htmlspecialcharsbx($from) ?>">
        <input type="hidden" name="to"   value="<?= htmlspecialcharsbx($to) ?>">
        <input type="hidden" name="x5_action" value="recalc_range">
        <input type="submit" value="Пересчитать кеш на диапазон" class="adm-btn adm-btn-save"
               onclick="return confirm('Пересчитать максимум по дням за <?= htmlspecialcharsbx($from) ?> — <?= htmlspecialcharsbx($to) ?>?')">
    </form>

    <form method="POST" action="x5_pwa_daily_apples.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block;margin-right:8px">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="from" value="<?= htmlspecialcharsbx($from) ?>">
        <input type="hidden" name="to"   value="<?= htmlspecialcharsbx($to) ?>">
        <input type="hidden" name="x5_action" value="clear_and_recalc">
        <input type="submit" value="Очистить + Пересчитать" class="adm-btn adm-btn-save"
               onclick="return confirm('Удалить старый кеш и пересчитать максимум за <?= htmlspecialcharsbx($from) ?> — <?= htmlspecialcharsbx($to) ?>?\n\nЭто перезапишет некорректные значения.')">
    </form>

    <form method="POST" action="x5_pwa_daily_apples.php?lang=<?= LANGUAGE_ID ?>" style="display:inline-block">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="from" value="<?= htmlspecialcharsbx($from) ?>">
        <input type="hidden" name="to"   value="<?= htmlspecialcharsbx($to) ?>">
        <input type="hidden" name="x5_action" value="clear_range">
        <input type="submit" value="Очистить кеш на диапазон" class="adm-btn"
               onclick="return confirm('Удалить записи кеша за <?= htmlspecialcharsbx($from) ?> — <?= htmlspecialcharsbx($to) ?>?\n\nСледующий пересчёт восстановит их.')">
    </form>

    <div class="x5da-legend">
        Действующий период статистики:
        <b><?= htmlspecialcharsbx($periodFrom) ?></b> — <b><?= htmlspecialcharsbx($periodTo) ?></b>.
        Настройка — в разделе «Настройки модуля» → вкладка «Статистика».
        <br>После правки данных в ИБ — используйте «Очистить + Пересчитать».
    </div>
</div>

<div class="x5da-box">
    <h3>Максимум яблок по дням (<?= count($rows) ?> дн.)</h3>

    <?php if (!$rows): ?>
        <div style="color:#888">Нет данных.</div>
    <?php else: ?>
        <div style="overflow-x:auto">
            <table class="x5da-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>BASE</th>
                        <?php foreach ($themes as $code): ?>
                            <th><?= htmlspecialcharsbx($themeTitles[$code] ?? $code) ?></th>
                        <?php endforeach ?>
                        <th>Итого (без привычки)</th>
                        <th>Источник</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $totalWithThemes = $row['BASE'];
                        foreach ($themes as $code) {
                            $totalWithThemes += $row['THEMES'][$code];
                        }
                        ?>
                        <tr>
                            <td><b><?= htmlspecialcharsbx($row['DATE']) ?></b></td>
                            <td class="x5da-base"><?= (int)$row['BASE'] ?></td>
                            <?php foreach ($themes as $code): ?>
                                <td class="x5da-theme"><?= (int)$row['THEMES'][$code] ?></td>
                            <?php endforeach ?>
                            <td class="x5da-total"><?= (int)$totalWithThemes ?></td>
                            <td>
                                <?php if ($row['SOURCE'] === 'кеш'): ?>
                                    <span class="x5da-badge-cache">кеш</span>
                                <?php else: ?>
                                    <span class="x5da-badge-calc">расчёт</span>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0f2f5">
                        <td><b>Σ период</b></td>
                        <td class="x5da-base"><?= (int)$totals['BASE'] ?></td>
                        <?php foreach ($themes as $code): ?>
                            <td class="x5da-theme"><?= (int)$totals['THEMES'][$code] ?></td>
                        <?php endforeach ?>
                        <td class="x5da-total">
                            <?= (int)($totals['BASE'] + array_sum($totals['THEMES'])) ?>
                        </td>
                        <td>—</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="x5da-legend">
            <b>BASE</b> = charging + general_lectures + events + reflection + steps (отправка + подтверждение).<br>
            <b>Направление</b> = theme_lectures (THEME=код) + tasks (THEME=код).<br>
            <b>MAX пользователя</b> = BASE + направление пользователя + REWARD_POINTS его привычки.
            Привычка в таблице не показана — она персональная.
        </div>
    <?php endif ?>
</div>

<div class="x5da-box">
    <h3>Детализация BASE на <?= htmlspecialcharsbx($from) ?></h3>
    <?php
    $charging   = countIblockForDate((int)Settings::iblockId('charging'), $from);
    $general    = countIblockForDate((int)Settings::iblockId('general_lectures'), $from);
    $events     = countIblockForDate((int)Settings::iblockId('events'), $from);
    $reflPoints = Settings::rewardReflectionPoints();
    try {
        $setting = \X5\Pwa\ReflectionBuilder::getForDate($from);
        if (!empty($setting['rewardPoints'])) $reflPoints = (int)$setting['rewardPoints'];
    } catch (\Throwable $e) {}
    $stepsSent     = Settings::rewardStepsSentPoints();
    $stepsApproved = Settings::rewardStepsApprovedPoints();
    ?>
    <table class="x5da-table" style="width:auto">
        <tr>
            <td>charging</td>
            <td><?= (int)$charging['count'] ?> эл.</td>
            <td class="x5da-base"><?= (int)$charging['sum'] ?></td>
        </tr>
        <tr>
            <td>general_lectures</td>
            <td><?= (int)$general['count'] ?> эл.</td>
            <td class="x5da-base"><?= (int)$general['sum'] ?></td>
        </tr>
        <tr>
            <td>events</td>
            <td><?= (int)$events['count'] ?> эл.</td>
            <td class="x5da-base"><?= (int)$events['sum'] ?></td>
        </tr>
        <tr>
            <td>reflection</td>
            <td>—</td>
            <td class="x5da-base"><?= (int)$reflPoints ?></td>
        </tr>
        <tr>
            <td>steps sent</td>
            <td>—</td>
            <td class="x5da-base"><?= (int)$stepsSent ?></td>
        </tr>
        <tr>
            <td>steps approved</td>
            <td>—</td>
            <td class="x5da-base"><?= (int)$stepsApproved ?></td>
        </tr>
    </table>

    <h3 style="margin-top:16px">Детализация по темам на <?= htmlspecialcharsbx($from) ?></h3>
    <table class="x5da-table" style="width:auto">
        <thead>
            <tr>
                <th>Тема</th>
                <th>theme_lectures</th>
                <th>tasks</th>
                <th>Итого</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $ibTheme = (int)Settings::iblockId('theme_lectures');
            $ibTasks = (int)Settings::iblockId('tasks');
            foreach ($themes as $code):
                $lectures = countIblockForDateByTheme($ibTheme, $from, $code);
                $tasks    = countIblockForDateByTheme($ibTasks, $from, $code);
            ?>
                <tr>
                    <td><?= htmlspecialcharsbx($themeTitles[$code] ?? $code) ?></td>
                    <td><?= (int)$lectures['count'] ?> эл. / <?= (int)$lectures['sum'] ?> ябл.</td>
                    <td><?= (int)$tasks['count'] ?> эл. / <?= (int)$tasks['sum'] ?> ябл.</td>
                    <td class="x5da-theme"><?= (int)($lectures['sum'] + $tasks['sum']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>