<?php
/**
 * Общая статистика PWA.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Общая статистика');

// Кнопка "Обновить сейчас"
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && isset($_POST['refresh'])) {
    try {
        $r = \X5\Pwa\Dumper::regenerateStats();
        $message = !empty($r['ok'])
            ? 'Статистика обновлена и залита в S3.'
            : 'Ошибка: ' . ($r['error'] ?? 'unknown');
    } catch (\Throwable $e) {
        $message = 'Исключение: ' . $e->getMessage();
    }
}

$stats = \X5\Pwa\StatsBuilder::buildGlobal();
$lastStatsAt = (int)Option::get($moduleId, 'last_stats_at', 0);
$interval    = (int)Option::get($moduleId, 'stats_interval_sec', 600);
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 20px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 16px 0; font-size: 15px; }
    .x5pwa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .x5pwa-metric { background: #f5f6f8; padding: 20px; border-radius: 6px; text-align: center; }
    .x5pwa-metric-value { font-size: 32px; font-weight: 700; color: #1e6bcc; }
    .x5pwa-metric-label { font-size: 12px; color: #666; text-transform: uppercase; margin-top: 6px; }
</style>

<?php if ($message): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message"><?= htmlspecialcharsbx($message) ?></div>
    </div>
<?php endif ?>

<div class="x5pwa-box">
    <h3>Итоговые показатели</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['USERS'] ?></div>
            <div class="x5pwa-metric-label">Пользователей</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['LECTURES_READ'] ?></div>
            <div class="x5pwa-metric-label">Лекций прочитано</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['TASKS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Заданий выполнено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['HABITS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Привычек отмечено</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Дополнительно</h3>
    <dl style="display:grid;grid-template-columns:220px 1fr;gap:6px 12px;margin:0">
        <dt style="color:#666">Популярная тема</dt>
        <dd style="margin:0"><b><?= htmlspecialcharsbx((string)$stats['TOP_THEME']) ?></b></dd>

        <dt style="color:#666">Обновлено</dt>
        <dd style="margin:0"><?= htmlspecialcharsbx((string)$stats['UPDATED_AT']) ?></dd>

        <dt style="color:#666">Интервал автообновления</dt>
        <dd style="margin:0"><?= (int)$interval ?> сек</dd>

        <dt style="color:#666">Файл на CDN</dt>
        <dd style="margin:0" class="x5pwa-mono">
            <a href="https://cdn.x5hd.ru/stats/global.json" target="_blank">
                https://cdn.x5hd.ru/stats/global.json
            </a>
        </dd>
    </dl>
</div>

<form method="post" action="x5_pwa_stats.php?lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <input type="submit" name="refresh" value="Обновить сейчас" class="adm-btn adm-btn-save"
           onclick="return confirm('Обновить статистику и залить в S3?')">
</form>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>