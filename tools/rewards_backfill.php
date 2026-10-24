<?php
/**
 * Backfill наград за прошлые действия.
 *
 * Режимы:
 *   • Без параметров — DRY RUN (только показывает, что будет начислено).
 *   • ?apply=1      — реально начислить.
 *
 * Параметры:
 *   • uid=…              — только для этого пользователя (пусто → все);
 *   • since=YYYY-MM-DD   — с какой даты (по умолчанию −30 дней);
 *   • types=…            — какие типы (habit,task,lecture,charging,reflection,
 *                          steps_sent,steps_approved,event). По умолчанию — все.
 *
 * Идемпотентен: повторный запуск не создаст дублей (UF_UNIQUE_KEY в RewardLog).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use X5\Pwa\RewardsBuilder;
use X5\Pwa\Storage;
use X5\Pwa\HabitsBuilder;

if (!$USER->IsAdmin()) die('admin only');

set_time_limit(600);

Loader::includeModule('x5.pwa');
Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

$apply      = (isset($_GET['apply']) && $_GET['apply'] === '1');
$uidFilter  = trim((string)($_GET['uid']   ?? ''));
$sinceRaw   = trim((string)($_GET['since'] ?? ''));
$typesRaw   = trim((string)($_GET['types'] ?? ''));

$since = $sinceRaw !== ''
    ? date('Y-m-d', strtotime($sinceRaw))
    : date('Y-m-d', strtotime('-30 days'));

$types = $typesRaw !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $typesRaw)),
        fn($t) => in_array($t, RewardsBuilder::ALL_TYPES, true)))
    : RewardsBuilder::ALL_TYPES;

if (!$types) $types = RewardsBuilder::ALL_TYPES;

$conn      = Application::getConnection();
$sqlHelper = $conn->getSqlHelper();

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "=== REWARDS BACKFILL " . ($apply ? '** APPLY **' : '[DRY RUN]') . " ===\n\n";
echo "since: {$since}\n";
echo "uid:   " . ($uidFilter !== '' ? $uidFilter : '(все)') . "\n";
echo "types: " . implode(', ', $types) . "\n\n";

// ────────────────────────────────────────────────────────────────
// Предупреждение: элементы с REWARD_POINTS = 0 (награда за них = 0)
// ────────────────────────────────────────────────────────────────
function listZeroPoints(): array
{
    $out = [];
    foreach (['habits', 'tasks', 'theme_lectures', 'general_lectures', 'charging', 'reflections'] as $ibKey) {
        $ibId = (int)\X5\Pwa\Settings::iblockId($ibKey);
        if ($ibId <= 0) continue;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
            false, false,
            ['ID', 'NAME']
        );
        while ($el = $rs->Fetch()) {
            $points = 0;
            $props = \CIBlockElement::GetProperty($ibId, (int)$el['ID'], [], ['CODE' => 'REWARD_POINTS']);
            if ($p = $props->Fetch()) $points = (int)($p['VALUE'] ?? 0);
            if ($points <= 0) {
                $out[] = ['iblock' => $ibKey, 'id' => (int)$el['ID'], 'name' => (string)$el['NAME']];
            }
        }
    }
    return $out;
}

$zeroPoints = listZeroPoints();
if ($zeroPoints) {
    echo "⚠️  Внимание: у следующих элементов REWARD_POINTS = 0 — награда за них НЕ начислится.\n";
    echo "   Заполните свойство в админке ИБ и запустите backfill повторно.\n";
    foreach (array_slice($zeroPoints, 0, 20) as $z) {
        echo "     · [{$z['iblock']}] #{$z['id']} «{$z['name']}»\n";
    }
    if (count($zeroPoints) > 20) {
        echo "     … и ещё " . (count($zeroPoints) - 20) . "\n";
    }
    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// Общие аккумуляторы
// ────────────────────────────────────────────────────────────────
$summary = [
    'checked'   => 0,
    'would_add' => 0,
    'added'     => 0,
    'already'   => 0,
    'skipped'   => 0,
    'points'    => 0,
    'details'   => [],
];

function recordResult(array &$summary, string $verdict, string $uid, string $type, string $actionId, int $points, string $title): void
{
    $summary['checked']++;

    switch ($verdict) {
        case 'add':     $summary['would_add']++; $summary['points'] += $points; break;
        case 'added':   $summary['added']++;     $summary['points'] += $points; break;
        case 'already': $summary['already']++;   break;
        case 'skip':    $summary['skipped']++;   break;
    }

    if (count($summary['details']) < 50 && in_array($verdict, ['add', 'added'], true)) {
        $summary['details'][] = sprintf(
            "   %-5s %s %-14s %s +%d «%s»",
            '[' . $verdict . ']',
            substr($uid, 0, 12) . '…',
            $type,
            $actionId,
            $points,
            mb_strimwidth($title, 0, 40, '…', 'UTF-8')
        );
    }
}

function tryAward(
    bool $apply,
    array &$summary,
    string $uid,
    string $type,
    string $actionId,
    int $points,
    string $title,
    string $localDate,
    callable $awardFn
): void {
    if ($points <= 0) {
        recordResult($summary, 'skip', $uid, $type, $actionId, 0, $title . ' [points=0]');
        return;
    }

    if ($apply) {
        try {
            $r = $awardFn();
            if (!empty($r['added'])) {
                recordResult($summary, 'added', $uid, $type, $actionId, (int)$r['points'], $title);
            } elseif (!empty($r['already'])) {
                recordResult($summary, 'already', $uid, $type, $actionId, 0, $title);
            } else {
                recordResult($summary, 'skip', $uid, $type, $actionId, 0, $title . ' [' . ($r['reason'] ?? '?') . ']');
            }
        } catch (\Throwable $e) {
            recordResult($summary, 'skip', $uid, $type, $actionId, 0, $title . ' [ERR: ' . $e->getMessage() . ']');
        }
    } else {
        recordResult($summary, 'add', $uid, $type, $actionId, $points, $title);
    }
}

// ────────────────────────────────────────────────────────────────
// HABITS
// ────────────────────────────────────────────────────────────────
function processHabits(bool $apply, string $since, string $uidFilter, array &$summary): void
{
    echo "── Привычки (habit) ──\n";

    $habits = [];
    foreach (HabitsBuilder::getAll() as $h) {
        $habits[(string)$h['id']] = $h;
    }
    if (!$habits) {
        echo "   (нет привычек в каталоге)\n\n";
        return;
    }

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_COMPLETED_AT >= '" . $sqlHelper->forSql($since . ' 00:00:00') . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_HABIT_ID, UF_COMPLETED_AT
         FROM x5_pwa_habit_logs
         WHERE {$filter}
         ORDER BY UF_COMPLETED_AT ASC"
    )->fetchAll();

    echo "   Исходных выполнений: " . count($rows) . "\n";

    $groups = [];
    foreach ($rows as $r) {
        $uid = (string)$r['UF_UID'];
        $hid = (string)$r['UF_HABIT_ID'];
        if (!isset($habits[$hid])) continue;

        $tz = Storage::getUserTzOffsetMin($uid);
        $offsetMs = $tz * 60 * 1000;
        $ts = strtotime((string)$r['UF_COMPLETED_AT']);
        if ($ts <= 0) continue;
        $localDate = gmdate('Y-m-d', (int)(($ts * 1000 + $offsetMs) / 1000));

        $key = $uid . '|' . $hid . '|' . $localDate;
        if (!isset($groups[$key])) {
            $groups[$key] = ['uid' => $uid, 'hid' => $hid, 'date' => $localDate, 'count' => 0];
        }
        $groups[$key]['count']++;
    }

    echo "   Групп (uid+habit+date): " . count($groups) . "\n";

    foreach ($groups as $g) {
        $cfg = $habits[$g['hid']];
        $points = (int)($cfg['rewardPoints'] ?? 0);
        $times  = (int)($cfg['timesPerDay'] ?? 1);

        if ($g['count'] < $times) {
            continue;
        }

        tryAward(
            $apply, $summary,
            $g['uid'], RewardsBuilder::TYPE_HABIT,
            $g['hid'] . ':' . $g['date'],
            $points,
            (string)($cfg['title'] ?? ''),
            $g['date'],
            fn() => RewardsBuilder::awardHabit($g['uid'], $cfg, $g['count'], $g['date'])
        );
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// TASKS
// ────────────────────────────────────────────────────────────────
function processTasks(bool $apply, string $since, string $uidFilter, array &$summary): void
{
    echo "── Задания (task) ──\n";

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_COMPLETED_AT >= '" . $sqlHelper->forSql($since . ' 00:00:00') . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_TASK_ID, UF_COMPLETED_AT
         FROM x5_pwa_task_completions
         WHERE {$filter}
         ORDER BY UF_COMPLETED_AT ASC"
    )->fetchAll();

    echo "   Исходных выполнений: " . count($rows) . "\n";

    $resolveCache = [];

    foreach ($rows as $r) {
        $uid     = (string)$r['UF_UID'];
        $taskKey = (string)$r['UF_TASK_ID'];

        if (!preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $taskKey, $m)) {
            $summary['skipped']++;
            continue;
        }
        $elementId = (int)$m[1];
        $taskDate  = $m[2];

        $tz = Storage::getUserTzOffsetMin($uid);
        $offsetMs = $tz * 60 * 1000;
        $ts = strtotime((string)$r['UF_COMPLETED_AT']);
        if ($ts <= 0) continue;
        $localDate = gmdate('Y-m-d', (int)(($ts * 1000 + $offsetMs) / 1000));

        if (!isset($resolveCache[$elementId])) {
            $resolveCache[$elementId] = RewardsBuilder::resolveTaskInfo($elementId);
        }
        $info = $resolveCache[$elementId];

        if ($info === null) {
            $summary['skipped']++;
            continue;
        }

        tryAward(
            $apply, $summary,
            $uid, RewardsBuilder::TYPE_TASK,
            $elementId . ':' . $localDate,
            (int)$info['points'],
            (string)$info['title'],
            $localDate,
            fn() => RewardsBuilder::awardTask(
                $uid, $elementId,
                (int)$info['points'],
                (string)$info['title'],
                (string)$info['date'],
                $localDate
            )
        );
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// LECTURES + CHARGING
// ────────────────────────────────────────────────────────────────
function processLectures(bool $apply, string $since, string $uidFilter, array &$summary, bool $wantLecture, bool $wantCharging): void
{
    echo "── Лекции / Зарядки ──\n";

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_READ_AT >= '" . $sqlHelper->forSql($since . ' 00:00:00') . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_LECTURE_ID, UF_READ_AT
         FROM x5_pwa_lecture_reads
         WHERE {$filter}
         ORDER BY UF_READ_AT ASC"
    )->fetchAll();

    echo "   Исходных прочтений: " . count($rows) . "\n";

    $resolveCache = [];

    foreach ($rows as $r) {
        $uid = (string)$r['UF_UID'];
        $lid = (string)$r['UF_LECTURE_ID'];

        if (!preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $lid, $m)) {
            $summary['skipped']++;
            continue;
        }
        $elementId = (int)$m[1];

        $tz = Storage::getUserTzOffsetMin($uid);
        $offsetMs = $tz * 60 * 1000;
        $ts = strtotime((string)$r['UF_READ_AT']);
        if ($ts <= 0) continue;
        $localDate = gmdate('Y-m-d', (int)(($ts * 1000 + $offsetMs) / 1000));

        if (!isset($resolveCache[$elementId])) {
            $resolveCache[$elementId] = RewardsBuilder::resolveLectureInfo($elementId);
        }
        $info = $resolveCache[$elementId];

        if ($info === null) {
            $summary['skipped']++;
            continue;
        }

        $isCharging = ($info['type'] === 'charging');
        if ($isCharging && !$wantCharging) { $summary['skipped']++; continue; }
        if (!$isCharging && !$wantLecture) { $summary['skipped']++; continue; }

        tryAward(
            $apply, $summary,
            $uid,
            $isCharging ? RewardsBuilder::TYPE_CHARGING : RewardsBuilder::TYPE_LECTURE,
            $elementId . ':' . $localDate,
            (int)$info['points'],
            (string)$info['title'],
            $localDate,
            fn() => RewardsBuilder::awardLecture(
                $uid, $elementId,
                (string)$info['type'],
                (int)$info['points'],
                (string)$info['title'],
                (string)$info['date'],
                $localDate
            )
        );
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// REFLECTIONS
// ────────────────────────────────────────────────────────────────
function processReflections(bool $apply, string $since, string $uidFilter, array &$summary): void
{
    echo "── Рефлексии (reflection) ──\n";

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_SENT_AT >= '" . $sqlHelper->forSql($since . ' 00:00:00') . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_LOCAL_DATE, UF_SENT_AT
         FROM x5_pwa_reflections
         WHERE {$filter}
         ORDER BY UF_SENT_AT ASC"
    )->fetchAll();

    echo "   Исходных рефлексий: " . count($rows) . "\n";

    foreach ($rows as $r) {
        $uid       = (string)$r['UF_UID'];
        $localDate = (string)($r['UF_LOCAL_DATE'] ?? '');

        if ($localDate === '') {
            $tz = Storage::getUserTzOffsetMin($uid);
            $offsetMs = $tz * 60 * 1000;
            $ts = strtotime((string)$r['UF_SENT_AT']);
            if ($ts > 0) {
                $localDate = gmdate('Y-m-d', (int)(($ts * 1000 + $offsetMs) / 1000));
            }
        }
        if ($localDate === '') {
            $summary['skipped']++;
            continue;
        }

        // Баллы: из ИБ (через ReflectionBuilder), fallback — Settings
        $points = 0;
        $title  = 'Рефлексия за ' . $localDate;
        try {
            $setting = \X5\Pwa\ReflectionBuilder::getForDate($localDate);
            if (!empty($setting['title'])) {
                $title = 'Рефлексия: ' . $setting['title'];
            }
            $points = (int)($setting['rewardPoints'] ?? 0);
        } catch (\Throwable $e) {
            // fallback ниже
        }
        if ($points <= 0) {
            $points = (int)\X5\Pwa\Settings::rewardReflectionPoints();
        }

        tryAward(
            $apply, $summary,
            $uid, RewardsBuilder::TYPE_REFLECTION,
            $localDate,
            $points,
            $title,
            $localDate,
            fn() => RewardsBuilder::awardReflection($uid, $localDate)
        );
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// STEPS
// ────────────────────────────────────────────────────────────────
function processSteps(bool $apply, string $since, string $uidFilter, array &$summary, bool $wantSent, bool $wantApproved): void
{
    echo "── Шаги ──\n";

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_LOCAL_DATE >= '" . $sqlHelper->forSql($since) . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_LOCAL_DATE, UF_STATUS
         FROM x5_pwa_steps
         WHERE {$filter}
         ORDER BY UF_LOCAL_DATE ASC"
    )->fetchAll();

    echo "   Исходных записей шагов: " . count($rows) . "\n";

    $sentPoints     = (int)\X5\Pwa\Settings::rewardStepsSentPoints();     // дефолт 0
    $approvedPoints = (int)\X5\Pwa\Settings::rewardStepsApprovedPoints(); // дефолт 5

    echo "   Настройки: отправка = {$sentPoints}, подтверждение = {$approvedPoints}\n";

    foreach ($rows as $r) {
        $uid       = (string)$r['UF_UID'];
        $localDate = (string)$r['UF_LOCAL_DATE'];
        $status    = (string)($r['UF_STATUS'] ?? 'N');

        // Отправка: начисляем для N (ждёт) и Y (одобрено).
        // Для R (отклонено) — не начисляем.
        if ($wantSent && in_array($status, ['N', 'Y'], true)) {
            tryAward(
                $apply, $summary,
                $uid, RewardsBuilder::TYPE_STEPS_SENT,
                $localDate,
                $sentPoints,
                'Шаги за ' . $localDate,
                $localDate,
                fn() => RewardsBuilder::awardStepsSent($uid, $localDate)
            );
        }

        // Approve: только для статуса Y
        if ($wantApproved && $status === 'Y') {
            tryAward(
                $apply, $summary,
                $uid, RewardsBuilder::TYPE_STEPS_APPROVED,
                $localDate,
                $approvedPoints,
                'Шаги подтверждены за ' . $localDate,
                $localDate,
                fn() => RewardsBuilder::awardStepsApproved($uid, $localDate)
            );
        }
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// EVENTS
// ────────────────────────────────────────────────────────────────
function processEvents(bool $apply, string $since, string $uidFilter, array &$summary): void
{
    echo "── Мероприятия (event) ──\n";

    $conn = Application::getConnection();
    $sqlHelper = $conn->getSqlHelper();

    $filter = "UF_CONFIRMED_AT >= '" . $sqlHelper->forSql($since . ' 00:00:00') . "'";
    if ($uidFilter !== '') {
        $filter .= " AND UF_UID = '" . $sqlHelper->forSql($uidFilter) . "'";
    }

    $rows = $conn->query(
        "SELECT UF_UID, UF_EVENT_ID, UF_EVENT_TITLE, UF_REWARD_POINTS, UF_CONFIRMED_AT
         FROM x5_pwa_event_actions
         WHERE {$filter}
         ORDER BY UF_CONFIRMED_AT ASC"
    )->fetchAll();

    echo "   Исходных подтверждений: " . count($rows) . "\n";

    foreach ($rows as $r) {
        $uid       = (string)$r['UF_UID'];
        $eventId   = (string)$r['UF_EVENT_ID'];
        $points    = (int)($r['UF_REWARD_POINTS'] ?? 0);
        $title     = (string)($r['UF_EVENT_TITLE'] ?? '');

        $tz = Storage::getUserTzOffsetMin($uid);
        $offsetMs = $tz * 60 * 1000;
        $ts = strtotime((string)$r['UF_CONFIRMED_AT']);
        if ($ts <= 0) continue;
        $localDate = gmdate('Y-m-d', (int)(($ts * 1000 + $offsetMs) / 1000));

        tryAward(
            $apply, $summary,
            $uid, RewardsBuilder::TYPE_EVENT,
            $eventId,
            $points,
            $title,
            $localDate,
            fn() => RewardsBuilder::awardEvent($uid, $eventId, $points, $title, $localDate)
        );
    }

    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// Запуск
// ────────────────────────────────────────────────────────────────

if (in_array(RewardsBuilder::TYPE_HABIT, $types, true)) {
    processHabits($apply, $since, $uidFilter, $summary);
}
if (in_array(RewardsBuilder::TYPE_TASK, $types, true)) {
    processTasks($apply, $since, $uidFilter, $summary);
}
if (in_array(RewardsBuilder::TYPE_LECTURE, $types, true) || in_array(RewardsBuilder::TYPE_CHARGING, $types, true)) {
    processLectures(
        $apply, $since, $uidFilter, $summary,
        in_array(RewardsBuilder::TYPE_LECTURE, $types, true),
        in_array(RewardsBuilder::TYPE_CHARGING, $types, true)
    );
}
if (in_array(RewardsBuilder::TYPE_REFLECTION, $types, true)) {
    processReflections($apply, $since, $uidFilter, $summary);
}
if (in_array(RewardsBuilder::TYPE_STEPS_SENT, $types, true) || in_array(RewardsBuilder::TYPE_STEPS_APPROVED, $types, true)) {
    processSteps(
        $apply, $since, $uidFilter, $summary,
        in_array(RewardsBuilder::TYPE_STEPS_SENT, $types, true),
        in_array(RewardsBuilder::TYPE_STEPS_APPROVED, $types, true)
    );
}
if (in_array(RewardsBuilder::TYPE_EVENT, $types, true)) {
    processEvents($apply, $since, $uidFilter, $summary);
}

// ────────────────────────────────────────────────────────────────
// Сводка
// ────────────────────────────────────────────────────────────────
echo "══ Сводка ══\n";
echo "   Проверено записей: {$summary['checked']}\n";

if ($apply) {
    echo "   Начислено:          {$summary['added']}\n";
    echo "   Уже было ранее:     {$summary['already']}\n";
    echo "   Пропущено:          {$summary['skipped']}\n";
} else {
    echo "   Будет начислено:    {$summary['would_add']}\n";
    echo "   Пропущено:          {$summary['skipped']}\n";
}
echo "   Сумма баллов:       +{$summary['points']}\n\n";

if ($summary['details']) {
    echo "Примеры (первые " . count($summary['details']) . "):\n";
    foreach ($summary['details'] as $line) {
        echo $line . "\n";
    }
    echo "\n";
}

// ────────────────────────────────────────────────────────────────
// Кнопки
// ────────────────────────────────────────────────────────────────
if (!$apply) {
    $applyUrl = '?apply=1'
        . ($uidFilter !== '' ? '&uid='   . urlencode($uidFilter) : '')
        . ($sinceRaw !== ''  ? '&since=' . urlencode($sinceRaw)  : '&since=' . urlencode($since))
        . '&types=' . urlencode(implode(',', $types));

    echo "▶ Это был dry run. Чтобы реально начислить, откройте:\n";
    echo "   " . htmlspecialcharsbx($applyUrl) . "\n";
} else {
    echo "✅ Backfill применён. Повторный запуск безопасен — дубли не создаются.\n";
}

echo "</pre>";