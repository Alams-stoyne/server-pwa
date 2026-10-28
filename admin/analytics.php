<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use X5\Pwa\AnalyticsBuilder;

$moduleId = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'R') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
$APPLICATION->SetTitle('PWA — Аналитика воронки');

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

$funnel = AnalyticsBuilder::getFunnel($from, $to);
$daily  = AnalyticsBuilder::getDailyDynamic($from, $to);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;margin-bottom:20px">
    <form method="GET">
        С даты: <input type="date" name="from" value="<?= $from ?>">
        По дату: <input type="date" name="to" value="<?= $to ?>">
        <button class="adm-btn-save" type="submit">Показать</button>
    </form>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
    <!-- ВОРОНКА -->
    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Воронка (уникальные устройства)</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr class="heading">
                <td>Шаг</td>
                <td style="text-align:right">Устройств</td>
                <td style="text-align:right">Конверсия</td>
            </tr>
            <?php 
            $base = $funnel['app_first_open'] ?: 1; // защита от /0
            $prev = $base;
            foreach ($funnel as $name => $count): 
                $convBase = round(($count / $base) * 100, 1);
                $convPrev = $prev > 0 ? round(($count / $prev) * 100, 1) : 0;
            ?>
            <tr>
                <td><b><?= $name ?></b></td>
                <td style="text-align:right; font-family:monospace; font-size:16px;"><?= number_format($count) ?></td>
                <td style="text-align:right; font-family:monospace;">
                    <?= $convBase ?>% <span style="color:#888; font-size:12px;">(от <?= $convPrev ?>%)</span>
                </td>
            </tr>
            <?php 
            $prev = $count;
            endforeach; 
            ?>
        </table>
    </div>

    <!-- ДИНАМИКА -->
    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Новые устройства по дням</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr class="heading">
                <td>Дата</td>
                <td style="text-align:right">Новых</td>
            </tr>
            <?php if(empty($daily)): ?>
                <tr><td colspan="2" style="text-align:center;color:#888;padding:20px;">Нет данных за этот период</td></tr>
            <?php endif; ?>
            <?php foreach ($daily as $day): ?>
            <tr>
                <td><?= $day['D'] ?></td>
                <td style="text-align:right; font-family:monospace;"><?= number_format($day['C']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>