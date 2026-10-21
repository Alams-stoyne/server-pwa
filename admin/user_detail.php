<?php
/**
 * Детальная статистика пользователя.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
    LocalRedirect('x5_pwa_users.php?lang=' . LANGUAGE_ID);
}

$binding = \X5\Pwa\Storage::getUidBinding($uid);
if (!$binding) {
    LocalRedirect('x5_pwa_users.php?lang=' . LANGUAGE_ID);
}

$stats = \X5\Pwa\StatsBuilder::buildUser($uid);
$sub   = \X5\Pwa\Storage::getSubscription($uid);

$APPLICATION->SetTitle('PWA — Пользователь ' . substr($uid, 0, 12) . '…');

function fmt_ms(int $ms): string
{
    if ($ms <= 0) return '—';
    return date('d.m.Y H:i:s', (int)($ms / 1000));
}
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 16px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 12px 0; font-size: 14px; }
    .x5pwa-kv { display: grid; grid-template-columns: 220px 1fr; gap: 6px 12px; }
    .x5pwa-kv dt { color: #666; }
    .x5pwa-kv dd { margin: 0; font-weight: 500; }
    .x5pwa-mono { font-family: monospace; font-size: 12px; word-break: break-all; }
    .x5pwa-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .x5pwa-metric { background: #f5f6f8; padding: 12px; border-radius: 6px; text-align: center; }
    .x5pwa-metric-value { font-size: 24px; font-weight: 600; color: #2c3e50; }
    .x5pwa-metric-label { font-size: 11px; color: #666; text-transform: uppercase; margin-top: 4px; }
    .x5pwa-week { display: flex; gap: 6px; }
    .x5pwa-week-day { flex: 1; background: #f0f2f5; padding: 8px 4px; text-align: center; border-radius: 4px; font-size: 11px; }
    .x5pwa-week-day.has-activity { background: #d4edaa; }
    .x5pwa-week-count { font-size: 16px; font-weight: 600; }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_users.php?lang=<?= LANGUAGE_ID ?>">← Ко всем пользователям</a>
</div>

<div class="x5pwa-box">
    <h3>Идентификация</h3>
    <dl class="x5pwa-kv">
        <dt>UID</dt>
        <dd class="x5pwa-mono"><?= htmlspecialcharsbx($uid) ?></dd>
        <dt>Device ID</dt>
        <dd class="x5pwa-mono"><?= htmlspecialcharsbx((string)$binding['UF_DEVICE_ID']) ?></dd>
        <dt>Создан</dt>
        <dd><?= fmt_ms($binding['UF_CREATED_AT'] ? $binding['UF_CREATED_AT']->getTimestamp() * 1000 : 0) ?></dd>
        <dt>Последняя активность</dt>
        <dd><?= fmt_ms($binding['UF_LAST_SEEN_AT'] ? $binding['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0) ?></dd>
        <dt>Push-подписка</dt>
        <dd>
            <?php if ($sub): ?>
                <span style="color:#3a7d1c">активна</span>
                <div class="x5pwa-mono" style="margin-top:4px;color:#888">
                    <?= htmlspecialcharsbx(substr($sub['endpoint'], 0, 80)) ?>…
                </div>
            <?php else: ?>
                <span style="color:#a52020">отсутствует</span>
            <?php endif ?>
        </dd>
    </dl>
</div>

<div class="x5pwa-box">
    <h3>Итоги</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $stats['LECTURES_READ'] ?></div>
            <div class="x5pwa-metric-label">Лекций прочитано</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $stats['TASKS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Заданий выполнено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $stats['HABITS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Привычек отмечено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $stats['STREAK'] ?></div>
            <div class="x5pwa-metric-label">Дней подряд</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Активность за 7 дней</h3>
    <div class="x5pwa-week">
        <?php foreach ($stats['WEEK_ACTIVITY'] as $day): ?>
            <div class="x5pwa-week-day <?= $day['count'] > 0 ? 'has-activity' : '' ?>">
                <div><?= htmlspecialcharsbx(substr($day['date'], 5)) ?></div>
                <div class="x5pwa-week-count"><?= (int)$day['count'] ?></div>
            </div>
        <?php endforeach ?>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Разбивка по темам</h3>
    <table style="width:100%;border-collapse:collapse">
        <?php foreach ($stats['BY_THEME'] as $theme => $count): ?>
            <tr>
                <td style="padding:6px 12px;border-bottom:1px solid #eee;width:200px"><?= htmlspecialcharsbx($theme) ?></td>
                <td style="padding:6px 12px;border-bottom:1px solid #eee"><b><?= (int)$count ?></b></td>
            </tr>
        <?php endforeach ?>
    </table>
</div>

<div class="x5pwa-box">
    <h3>Привычки</h3>
    <table style="width:100%;border-collapse:collapse">
        <?php foreach ($stats['BY_HABIT'] as $habitId => $count): ?>
            <tr>
                <td style="padding:6px 12px;border-bottom:1px solid #eee;width:200px"><?= htmlspecialcharsbx($habitId) ?></td>
                <td style="padding:6px 12px;border-bottom:1px solid #eee"><b><?= (int)$count ?></b></td>
            </tr>
        <?php endforeach ?>
    </table>
</div>

<div class="x5pwa-box">
    <h3>Прочитанные лекции</h3>
    <?php if (!$stats['READS']): ?>
        <div style="color:#888">Нет записей.</div>
    <?php else: ?>
        <ul style="margin:0;padding-left:20px">
            <?php foreach ($stats['READS'] as $id): ?>
                <li class="x5pwa-mono"><?= htmlspecialcharsbx((string)$id) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>

<div class="x5pwa-box">
    <h3>Выполненные задания</h3>
    <?php if (!$stats['TASKS']): ?>
        <div style="color:#888">Нет записей.</div>
    <?php else: ?>
        <ul style="margin:0;padding-left:20px">
            <?php foreach ($stats['TASKS'] as $id): ?>
                <li class="x5pwa-mono"><?= htmlspecialcharsbx((string)$id) ?></li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</div>

<div class="x5pwa-box">
    <h3>Действия</h3>
    <a href="x5_pwa_push.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($uid) ?>" class="adm-btn adm-btn-save">
        Отправить PUSH этому пользователю
    </a>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>