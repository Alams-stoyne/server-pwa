<?php
/**
 * PWA — Максимум яблок по дням.
 * Учитываются только активные направления (темы) — фильтр в ThemesResolver.
 *
 * Дополнительно: проверка БМ — все темы должны давать одинаковый дневной максимум.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\Settings;
use X5\Pwa\StatsBuilder;
use X5\Pwa\Storage;
use X5\Pwa\ThemesResolver;
use X5\Pwa\HabitsBuilder;

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
$themes           = ThemesResolver::getAllCodes();
$themesConfigured = ThemesResolver::isConfigured();
$noActiveThemes   = $themesConfigured && !$themes;

$themeTitles = [];
try {
    foreach (\X5\Pwa\ThemesBuilder::getAll() as $t) {
        $code = (string)($t['id'] ?? '');
        if ($code !== '') {
            $themeTitles[$code] = (string)($t['name'] ?? $code);
        }
    }
} catch (\Throwable $e) {}

// ── Верхняя граница вклада привычки одного пользователя за день ──
$habitMaxPerDay = 0;
try {
    $habits = HabitsBuilder::getAll();
    foreach ($habits as $habit) {
        $points = (int)($habit['rewardPoints'] ?? 0);
        $times  = max(1, (int)($habit['timesPerDay'] ?? 1));
        $max    = $points * $times;
        if ($max > $habitMaxPerDay) $habitMaxPerDay = $max;
    }
} catch (\Throwable $e) {}

$rows = [];
$cursor = strtotime($from);
$endTs  = strtotime($to);
$totals = [
    'BASE'      => 0,
    'THEMES'    => array_fill_keys($themes, 0),
    'MAX_THEME' => 0,
    'CEILING'   => 0,
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

        $themeValues = [];
        foreach ($themes as $code) {
            $themeValues[$code] = (int)($cached['THEMES'][$code] ?? 0);
        }

        $maxTheme     = 0;
        $maxThemeCode = null;
        foreach ($themeValues as $code => $v) {
            if ($maxThemeCode === null || $v > $maxTheme) {
                $maxTheme = $v;
                $maxThemeCode = $code;
            }
        }

        $base    = (int)($cached['BASE'] ?? 0);
        $ceiling = $base + $maxTheme + $habitMaxPerDay;

        $rows[] = [
            'DATE'           => $d,
            'BASE'           => $base,
            'THEMES'         => $themeValues,
            'SOURCE'         => $source,
            'HABIT'          => $habitMaxPerDay,
            'MAX_THEME'      => $maxTheme,
            'MAX_THEME_CODE' => $maxThemeCode,
            'CEILING'        => $ceiling,
        ];

        $totals['BASE']      += $base;
        $totals['MAX_THEME'] += $maxTheme;
        $totals['CEILING']   += $ceiling;
        foreach ($themes as $code) {
            $totals['THEMES'][$code] += $themeValues[$code];
        }

        $cursor = strtotime('+1 day', $cursor);
    }
}

// ── Детализация на дату ──

/**
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
    .x5da-base    { font-weight:600; color:#1e6bcc; }
    .x5da-theme   { color:#3a7d1c; }
    .x5da-habit   { color:#8e44ad; font-weight:600; }
    .x5da-max     { color:#d35400; font-weight:600; }
    .x5da-ceiling { color:#c0392b; font-weight:700; }
    .x5da-badge-cache   { background:#e6f5d8; color:#3a7d1c; padding:1px 6px; border-radius:8px; font-size:10px; }
    .x5da-badge-calc    { background:#fff4e0; color:#a52020; padding:1px 6px; border-radius:8px; font-size:10px; }
    .x5da-form { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
    .x5da-form label { display:flex; flex-direction:column; font-size:12px; color:#666; gap:4px; }
    .x5da-form input[type=date] { padding:6px 8px; border:1px solid #c5c9cf; border-radius:3px; }
    .x5da-legend { font-size:11px; color:#666; margin-top:8px; }
    .x5da-code { color:#888; font-size:10px; margin-left:4px; }
    .x5da-bm-ok { background:#e6f5d8; border:1px solid #3a7d1c; padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; color:#2c3e50; }
    .x5da-bm-warn { background:#fff4e0; border:1px solid #f5a623; padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; color:#8a4b00; }
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
<?php if ($noActiveThemes): ?>
    <div class="adm-info-message-wrap adm-info-message-yellow">
        <div class="adm-info-message">
            <b>Нет активных направлений.</b>
            В ИБ «Направления» есть элементы, но ни у одного не установлен флаг активности.
            В таблице ниже остались только BASE, Habit (max) и потолок BASE + Habit (max).
            Активируйте нужные направления — колонки появятся автоматически.
        </div>
    </div>
<?php elseif (!$themesConfigured): ?>
    <div class="adm-info-message-wrap adm-info-message-yellow">
        <div class="adm-info-message">
            <b>ИБ «Направления» не настроен.</b>
            В настройках модуля не указан IBLOCK_ID для «Направления» — темы не учитываются.
            Проверьте «Настройки модуля» → соответствующий пункт.
        </div>
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

<?php
// ── Проверка БМ: равенство дневного максимума по активным темам ──
// Считаем по выбранной дате «from» (детализация ниже).
$checkDate    = $from;
$checkApples  = Storage::getDailyApplesFromCache($checkDate);
if ($checkApples === null) {
    $checkApples = StatsBuilder::calculateDailyApples($checkDate);
}
$checkBase     = (int)($checkApples['BASE'] ?? 0);
$checkThemes   = $checkApples['THEMES'] ?? [];
$checkValues   = [];
foreach ($themes as $code) {
    $checkValues[$code] = $checkBase + (int)($checkThemes[$code] ?? 0);
}
$checkMin = null;
$checkMax = null;
$checkMinCode = null;
$checkMaxCode = null;
foreach ($checkValues as $code => $v) {
    if ($checkMin === null || $v < $checkMin) { $checkMin = $v; $checkMinCode = $code; }
    if ($checkMax === null || $v > $checkMax) { $checkMax = $v; $checkMaxCode = $code; }
}
$bmDiff = ($checkMin !== null && $checkMax !== null) ? ($checkMax - $checkMin) : 0;
?>
<?php if ($themes && $checkMin !== null): ?>
    <?php if ($bmDiff === 0): ?>
        <div class="x5da-bm-ok">
            <b>Проверка БМ: ОК.</b>
            Все активные направления дают одинаковый дневной максимум
            (без привычки): <b><?= (int)$checkMax ?> яблок</b> на <?= htmlspecialcharsbx($checkDate) ?>.
            Пользователь не получает преимущества при смене темы.
        </div>
    <?php else: ?>
        <div class="x5da-bm-warn">
            <b>Проверка БМ: нарушение.</b>
            На <?= htmlspecialcharsbx($checkDate) ?> дневной максимум по активным направлениям различается
            (без привычки):
            <br>
            минимум — «<?= htmlspecialcharsbx($themeTitles[$checkMinCode] ?? $checkMinCode) ?>»:
            <b><?= (int)$checkMin ?></b> яблок;
            максимум — «<?= htmlspecialcharsbx($themeTitles[$checkMaxCode] ?? $checkMaxCode) ?>»:
            <b><?= (int)$checkMax ?></b> яблок.
            Разброс: <b>+<?= (int)$bmDiff ?></b>.
            <br>
            <span style="font-size:12px;color:#666;margin-top:6px;display:inline-block">
                По БМ все направления должны давать одинаковый дневной максимум,
                иначе пользователь получит преимущество при смене темы.
                Проверьте количество и REWARD_POINTS лекций/практик по направлениям на эту дату.
            </span>
        </div>
    <?php endif ?>
<?php endif ?>

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
                        <th>Habit (max)</th>
                        <th>MAX (тема)</th>
                        <th>Потолок с привычкой</th>
                        <th>Источник</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><b><?= htmlspecialcharsbx($row['DATE']) ?></b></td>
                            <td class="x5da-base"><?= (int)$row['BASE'] ?></td>
                            <?php foreach ($themes as $code): ?>
                                <td class="x5da-theme"><?= (int)$row['THEMES'][$code] ?></td>
                            <?php endforeach ?>
                            <td class="x5da-habit"><?= (int)$row['HABIT'] ?></td>
                            <td class="x5da-max">
                                <?= (int)$row['MAX_THEME'] ?>
                                <?php if ($row['MAX_THEME_CODE'] !== null && isset($themeTitles[$row['MAX_THEME_CODE']])): ?>
                                    <span class="x5da-code">(<?= htmlspecialcharsbx($themeTitles[$row['MAX_THEME_CODE']]) ?>)</span>
                                <?php endif ?>
                            </td>
                            <td class="x5da-ceiling"><?= (int)$row['CEILING'] ?></td>
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
                        <td class="x5da-habit"><?= (int)$habitMaxPerDay ?></td>
                        <td class="x5da-max"><?= (int)$totals['MAX_THEME'] ?></td>
                        <td class="x5da-ceiling"><?= (int)$totals['CEILING'] ?></td>
                        <td>—</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="x5da-legend">
            <b>BASE</b> = charging + general_lectures + events + reflection + steps (отправка + подтверждение).<br>
            <b>Направление</b> = theme_lectures (THEME=код) + tasks (THEME=код).<br>
            <b>Habit (max)</b> = верхняя граница вклада привычки одного пользователя за день
            (<code>rewardPoints × timesPerDay</code> по самой «дорогой» привычке).<br>
            <b>MAX (тема)</b> = BASE + максимум по активным направлениям за день.<br>
            <b>Потолок с привычкой</b> = MAX (тема) + Habit (max).<br>
            <b>Σ период</b> для MAX и Потолка — сумма дневных значений.
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

    $baseLive = (int)($charging['sum'] + $general['sum'] + $events['sum']
        + $reflPoints + $stepsSent + $stepsApproved);
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
        <tr>
            <td>habit (max)</td>
            <td>—</td>
            <td class="x5da-habit"><?= (int)$habitMaxPerDay ?></td>
        </tr>
        <tr style="background:#f0f2f5">
            <td><b>BASE (итог)</b></td>
            <td>—</td>
            <td class="x5da-base"><b><?= (int)$baseLive ?></b></td>
        </tr>
    </table>

    <h3 style="margin-top:16px">Детализация по активным темам на <?= htmlspecialcharsbx($from) ?></h3>
    <?php if (!$themes): ?>
        <div style="color:#888">
            <?= $noActiveThemes
                ? 'Нет активных направлений.'
                : 'ИБ «Направления» не настроен.' ?>
        </div>
    <?php else: ?>
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

                $themeSums = [];
                foreach ($themes as $code) {
                    $lectures = countIblockForDateByTheme($ibTheme, $from, $code);
                    $tasks    = countIblockForDateByTheme($ibTasks, $from, $code);
                    $themeSums[$code] = (int)($lectures['sum'] + $tasks['sum']);
                }
                foreach ($themes as $code):
                    $lectures = countIblockForDateByTheme($ibTheme, $from, $code);
                    $tasks    = countIblockForDateByTheme($ibTasks, $from, $code);
                ?>
                    <tr>
                        <td><?= htmlspecialcharsbx($themeTitles[$code] ?? $code) ?></td>
                        <td><?= (int)$lectures['count'] ?> эл. / <?= (int)$lectures['sum'] ?> ябл.</td>
                        <td><?= (int)$tasks['count'] ?> эл. / <?= (int)$tasks['sum'] ?> ябл.</td>
                        <td class="x5da-theme"><?= (int)$themeSums[$code] ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <h3 style="margin-top:16px">Потолок пользователя по активным направлениям на <?= htmlspecialcharsbx($from) ?></h3>
        <table class="x5da-table" style="width:auto">
            <thead>
                <tr>
                    <th>Направление</th>
                    <th>BASE</th>
                    <th>+ тема</th>
                    <th>+ habit (max)</th>
                    <th>= Потолок</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $bestCode  = null;
                $worstCode = null;
                foreach ($themeSums as $code => $sum) {
                    if ($bestCode  === null || $sum > $themeSums[$bestCode])  $bestCode  = $code;
                    if ($worstCode === null || $sum < $themeSums[$worstCode]) $worstCode = $code;
                }

                foreach ($themes as $code):
                    $themeSum = $themeSums[$code];
                    $ceiling  = (int)($baseLive + $themeSum + $habitMaxPerDay);
                ?>
                    <tr>
                        <td><?= htmlspecialcharsbx($themeTitles[$code] ?? $code) ?></td>
                        <td class="x5da-base"><?= (int)$baseLive ?></td>
                        <td class="x5da-theme"><?= (int)$themeSum ?></td>
                        <td class="x5da-habit"><?= (int)$habitMaxPerDay ?></td>
                        <td class="x5da-ceiling"><?= (int)$ceiling ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <div class="x5da-legend">
            Потолок считается по <i>свежим</i> данным ИБ (не из кэша), чтобы администратор видел
            актуальную картину, даже если кэш ещё не пересчитан.
            Если у пользователя нет привычки — его реальный потолок будет ниже на <b>habit (max)</b>.
        </div>
    <?php endif ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';