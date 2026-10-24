<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use X5\Pwa\Settings;
use X5\Pwa\StatsBuilder;
use X5\Pwa\Storage;
use X5\Pwa\RewardsBuilder;
use X5\Pwa\WorkplacesBuilder;

if (!$USER->IsAdmin()) die('admin only');

set_time_limit(300);

Loader::includeModule('x5.pwa');
Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

$uid       = trim((string)($_GET['uid'] ?? ''));
$companyId = trim((string)($_GET['company'] ?? ''));

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "=== DIAG EFFICIENCY ===\n\n";

[$from, $to] = StatsBuilder::getEffectivePeriod();
echo "Период: {$from} — {$to}\n";
echo "Настройки:\n";
echo "  stats_period_start = '" . Settings::statsPeriodStart() . "'\n";
echo "  stats_period_end   = '" . Settings::statsPeriodEnd() . "'\n";
echo "  reward_steps_sent_points = " . Settings::rewardStepsSentPoints() . "\n";
echo "  reward_steps_approved_points = " . Settings::rewardStepsApprovedPoints() . "\n";
echo "  reward_reflection_points = " . Settings::rewardReflectionPoints() . "\n\n";

// ─── 1. DailyMax по датам периода ───
echo "── DailyMax по датам ──\n";
$maxByDate = [];
$cursor = strtotime($from);
$endTs  = strtotime($to);
while ($cursor <= $endTs) {
    $d = date('Y-m-d', $cursor);
    $cached = Storage::getDailyMaxFromCache($d);
    if ($cached !== null) {
        $maxByDate[$d] = $cached;
        echo "  {$d}: {$cached} (из кеша)\n";
    } else {
        $calculated = StatsBuilder::calculateDailyMax($d);
        $maxByDate[$d] = $calculated;
        echo "  {$d}: {$calculated} (рассчитано, не закешировано)\n";
    }
    $cursor = strtotime('+1 day', $cursor);
}
$totalMax = array_sum($maxByDate);
echo "  ИТОГО MAX за период: {$totalMax}\n\n";

// ─── 2. Персональная эффективность ───
if ($uid !== '') {
    if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
        echo "❌ Неверный UID\n";
    } else {
        echo "── Пользователь " . substr($uid, 0, 12) . "… ──\n";

        $binding = Storage::getUidBinding($uid);
        if (!$binding) {
            echo "  ❌ UID не найден в UidBindings\n";
        } else {
            $createdTs = !empty($binding['UF_CREATED_AT'])
                ? $binding['UF_CREATED_AT']->getTimestamp() : 0;
            echo "  created_at: " . ($createdTs ? date('Y-m-d H:i', $createdTs) : '—') . "\n";
            echo "  workplace: '" . ($binding['UF_WORKPLACE'] ?? '') . "'\n";
            echo "  consent: '" . ($binding['UF_CONSENT_GRANTED'] ?? '—') . "'\n";
            echo "  blocked: '" . ($binding['UF_BLOCKED'] ?? '—') . "'\n";

            [$userFrom, $userTo] = StatsBuilder::getUserPeriod($createdTs, $from, $to);
            echo "  период пользователя: {$userFrom} — {$userTo}\n\n";

            $earnedAll = Storage::getEarnedBetween($uid, $userFrom, $userTo);
            $maxAll = 0;
            $cursor = strtotime($userFrom);
            $endTs = strtotime($userTo);
            while ($cursor <= $endTs) {
                $d = date('Y-m-d', $cursor);
                $maxAll += $maxByDate[$d] ?? 0;
                $cursor = strtotime('+1 day', $cursor);
            }

            $effAll = $maxAll > 0 ? round($earnedAll / $maxAll * 100, 2) : 0.0;

            $today = date('Y-m-d');
            $earnedToday = Storage::getEarnedOnDate($uid, $today);
            $maxToday = $maxByDate[$today] ?? 0;
            $effDay = $maxToday > 0 ? round($earnedToday / $maxToday * 100, 2) : 0.0;

            echo "  EARNED_ALL    = {$earnedAll}\n";
            echo "  MAX_ALL       = {$maxAll}\n";
            echo "  EFFICIENCY_ALL = {$effAll}%   (пересчёт)\n";
            echo "  UF_EFFICIENCY_ALL в БД = " . (float)($binding['UF_EFFICIENCY_ALL'] ?? 0) . "%\n";
            echo "  " . (abs((float)($binding['UF_EFFICIENCY_ALL'] ?? 0) - $effAll) < 0.01 ? "✅ совпадает" : "⚠️  НЕ совпадает (запустите «Пересчитать кеши»)") . "\n\n";

            echo "  EARNED_TODAY    = {$earnedToday}\n";
            echo "  MAX_TODAY       = {$maxToday}\n";
            echo "  EFFICIENCY_DAY  = {$effDay}%   (пересчёт)\n";
            echo "  UF_EFFICIENCY_DAY в БД = " . (float)($binding['UF_EFFICIENCY_DAY'] ?? 0) . "%\n";
            echo "  " . (abs((float)($binding['UF_EFFICIENCY_DAY'] ?? 0) - $effDay) < 0.01 ? "✅ совпадает" : "⚠️  НЕ совпадает") . "\n\n";

            echo "  Раскладка начислений по дням (RewardLog):\n";
            $conn = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $safe = $sqlHelper->forSql($uid);
            $rows = $conn->query(
                "SELECT UF_LOCAL_DATE, UF_ACTION_TYPE, SUM(UF_POINTS) AS P
                 FROM x5_pwa_reward_log
                 WHERE UF_UID = '{$safe}'
                   AND UF_LOCAL_DATE >= '" . $sqlHelper->forSql($userFrom) . "'
                   AND UF_LOCAL_DATE <= '" . $sqlHelper->forSql($userTo) . "'
                 GROUP BY UF_LOCAL_DATE, UF_ACTION_TYPE
                 ORDER BY UF_LOCAL_DATE, UF_ACTION_TYPE"
            )->fetchAll();
            $byDate = [];
            foreach ($rows as $r) {
                $byDate[$r['UF_LOCAL_DATE']][$r['UF_ACTION_TYPE']] = (int)$r['P'];
            }
            foreach ($byDate as $d => $types) {
                $sum = array_sum($types);
                $max = $maxByDate[$d] ?? 0;
                $parts = [];
                foreach ($types as $t => $p) $parts[] = "{$t}:{$p}";
                echo "    {$d}: " . implode(', ', $parts) . " = {$sum} / {$max}\n";
            }
        }
    }
}

// ─── 3. Компания ───
if ($companyId !== '') {
    echo "\n── Компания '{$companyId}' ──\n";

    $wp = WorkplacesBuilder::getByCode($companyId);
    if (!$wp) {
        echo "  ❌ Компания не найдена в ИБ workplaces\n";
    } else {
        echo "  name: '{$wp['name']}'\n";

        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safe = $sqlHelper->forSql($companyId);

        $users = $conn->query(
            "SELECT UF_UID, UF_CREATED_AT FROM x5_pwa_uid_bindings
             WHERE UF_WORKPLACE = '{$safe}'
               AND UF_CONSENT_GRANTED = 'Y'
               AND (UF_BLOCKED IS NULL OR UF_BLOCKED = 'N')"
        )->fetchAll();

        echo "  участников (сырых): " . count($users) . "\n";

        $sumEarned = 0;
        $sumMax = 0;
        $activeUsers = 0;

        $activeUids = Storage::getActiveUidsForStats();
        $activeSet = array_flip($activeUids);

        foreach ($users as $u) {
            $u = (string)$u['UF_UID'];
            if (!isset($activeSet[$u])) continue;
            $activeUsers++;

            $createdTs = !empty($u['UF_CREATED_AT'])
                ? strtotime((string)$u['UF_CREATED_AT']) : 0;
            [$userFrom, $userTo] = StatsBuilder::getUserPeriod((int)$createdTs, $from, $to);

            $earned = Storage::getEarnedBetween($u, $userFrom, $userTo);
            $max = 0;
            $cursor = strtotime($userFrom);
            $endTs = strtotime($userTo);
            while ($cursor <= $endTs) {
                $d = date('Y-m-d', $cursor);
                $max += $maxByDate[$d] ?? 0;
                $cursor = strtotime('+1 day', $cursor);
            }

            $sumEarned += $earned;
            $sumMax += $max;
        }

        $percent = $sumMax > 0 ? round($sumEarned / $sumMax * 100, 2) : 0.0;

        echo "  участников (прошли фильтр consent+active24h): {$activeUsers}\n";
        echo "  COMPANY_EARNED = {$sumEarned}\n";
        echo "  COMPANY_MAX    = {$sumMax}\n";
        echo "  PERCENT        = {$percent}%\n\n";

        // Сверить с CDN
        $raw = \X5\Pwa\S3::readLocal('stats/global.json');
        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            $list = $decoded['COMPANIES']['LIST'] ?? [];
            foreach ($list as $c) {
                if ((string)($c['ID'] ?? '') === $companyId) {
                    echo "  В stats/global.json: PERCENT = " . (float)($c['PERCENT'] ?? 0) . "%\n";
                    echo "  " . (abs((float)($c['PERCENT'] ?? 0) - $percent) < 0.01 ? "✅ совпадает" : "⚠️  НЕ совпадает (CDN устарел — «Обновить stats/global.json»)") . "\n";
                    break;
                }
            }
        }
    }
}

echo "\n</pre>";