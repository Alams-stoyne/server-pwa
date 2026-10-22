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

$selectedHabit      = $stats['SELECTED_HABIT'] ?? null;
$selectedHabitTitle = null;
if ($selectedHabit) {
    foreach (\X5\Pwa\HabitsBuilder::getAll() as $h) {
        if ((string)$h['id'] === (string)$selectedHabit) {
            $selectedHabitTitle = (string)$h['title'];
            break;
        }
    }
}

$APPLICATION->SetTitle('PWA — Пользователь ' . substr($uid, 0, 12) . '…');

function fmt_ms(int $ms): string
{
    if ($ms <= 0) return '—';
    return date('d.m.Y H:i:s', (int)($ms / 1000));
}

function fmt_iso(?string $iso): string
{
    if (!$iso) return '—';
    try {
        $dt = new \DateTime($iso);
        return $dt->format('d.m.Y H:i:s P');
    } catch (\Throwable $e) {
        return htmlspecialcharsbx($iso);
    }
}

$steps = $stats['STEPS'] ?? [];
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 16px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 12px 0; font-size: 14px; }
    .x5pwa-kv { display: grid; grid-template-columns: 220px 1fr; gap: 6px 12px; }
    .x5pwa-kv dt { color: #666; }
    .x5pwa-kv dd { margin: 0; font-weight: 500; }
    .x5pwa-mono { font-family: monospace; font-size: 12px; word-break: break-all; }
    .x5pwa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
    .x5pwa-metric { background: #f5f6f8; padding: 12px; border-radius: 6px; text-align: center; }
    .x5pwa-metric-value { font-size: 22px; font-weight: 600; color: #2c3e50; }
    .x5pwa-metric-label { font-size: 11px; color: #666; text-transform: uppercase; margin-top: 4px; }
    .x5pwa-week { display: flex; gap: 6px; }
    .x5pwa-week-day { flex: 1; background: #f0f2f5; padding: 8px 4px; text-align: center; border-radius: 4px; font-size: 11px; }
    .x5pwa-week-day.has-activity { background: #d4edaa; }
    .x5pwa-week-count { font-size: 16px; font-weight: 600; }
    .x5pwa-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; }
    .x5pwa-badge-yes { background: #e6f5d8; color: #3a7d1c; }
    .x5pwa-badge-no  { background: #f5e0e0; color: #a52020; }
    .x5pwa-badge-none{ background: #f0f2f5; color: #666; }
    .x5pwa-table { width: 100%; border-collapse: collapse; }
    .x5pwa-table td { padding: 6px 12px; border-bottom: 1px solid #eee; }
    .x5pwa-table td:first-child { width: 40%; }
    .x5pwa-table td:last-child { text-align: right; }
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
    <h3>Согласие на обработку ПД и выбор привычки</h3>
    <dl class="x5pwa-kv">
        <dt>Согласие ПД</dt>
        <dd>
            <?php if (!$stats['HAS_CONSENT']): ?>
                <span class="x5pwa-badge x5pwa-badge-none">не дано</span>
            <?php elseif ($stats['CONSENT']): ?>
                <span class="x5pwa-badge x5pwa-badge-yes">дано</span>
            <?php else: ?>
                <span class="x5pwa-badge x5pwa-badge-no">отказ</span>
            <?php endif ?>
        </dd>
        <dt>Дата/время согласия (сервер)</dt>
        <dd><?= fmt_iso($stats['CONSENT_AT']) ?></dd>
        <dt>Дата/время согласия (клиент)</dt>
        <dd class="x5pwa-mono"><?= htmlspecialcharsbx((string)($stats['CONSENT_LOCAL_DT'] ?? '—')) ?></dd>

        <dt>Выбранная привычка</dt>
        <dd>
            <?php if ($selectedHabit): ?>
                <b><?= htmlspecialcharsbx($selectedHabitTitle ?: $selectedHabit) ?></b>
                <?php if ($selectedHabitTitle && $selectedHabitTitle !== $selectedHabit): ?>
                    <span class="x5pwa-mono" style="color:#888">(<?= htmlspecialcharsbx($selectedHabit) ?>)</span>
                <?php endif ?>
            <?php else: ?>
                <span class="x5pwa-badge x5pwa-badge-none">не выбрана</span>
            <?php endif ?>
        </dd>
        <dt>Дата выбора привычки</dt>
        <dd><?= fmt_iso(!empty($binding['UF_HABIT_SELECTED_AT'])
                ? $binding['UF_HABIT_SELECTED_AT']->format('Y-m-d\TH:i:sP')
                : null) ?></dd>
    </dl>
</div>

<div class="x5pwa-box">
    <h3>Итоги</h3>
    <div class="x5pwa-grid">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['LECTURES_READ'] ?></div>
            <div class="x5pwa-metric-label">Лекций прочитано<br><small>всего</small></div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['LECTURES_THEME'] ?></div>
            <div class="x5pwa-metric-label">Лекций по направлениям</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['LECTURES_GENERAL'] ?></div>
            <div class="x5pwa-metric-label">Общих лекций</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['CHARGINGS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Зарядок</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['TASKS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Заданий выполнено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['HABITS_DONE'] ?></div>
            <div class="x5pwa-metric-label">Привычек отмечено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)$stats['STREAK'] ?></div>
            <div class="x5pwa-metric-label">Дней подряд</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Мероприятия</h3>
    <div class="x5pwa-grid" style="grid-template-columns: repeat(2, 1fr)">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)($stats['EVENTS_VISITED'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Посещено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)($stats['EVENTS_CONFIRMED'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Подтверждено</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Награды</h3>
    <div class="x5pwa-grid" style="grid-template-columns: repeat(2, 1fr)">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= (int)($stats['REWARDS_BALANCE'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Баланс</div>
        </div>
        <div class="x5pwa-metric" style="display:flex;align-items:center;justify-content:center">
            <a href="x5_pwa_rewards.php?lang=<?= LANGUAGE_ID ?>&UID=<?= urlencode($uid) ?>"
               class="adm-btn">Полная история →</a>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>ШАГИ</h3>
    <?php if (empty($steps)): ?>
        <div style="color:#888">Нет данных шагомера.</div>
    <?php else: ?>
        <div class="x5pwa-grid">
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= number_format((int)($steps['TOTAL_STEPS'] ?? 0), 0, '', ' ') ?></div>
                <div class="x5pwa-metric-label">Всего шагов<br><small>одобрено</small></div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= number_format((int)($steps['TODAY_STEPS'] ?? 0), 0, '', ' ') ?></div>
                <div class="x5pwa-metric-label">Сегодня</div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= number_format((int)($steps['DAILY_AVG'] ?? 0), 0, '', ' ') ?></div>
                <div class="x5pwa-metric-label">В среднем / день</div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= (int)($steps['RANK'] ?? 0) ?></div>
                <div class="x5pwa-metric-label">Место в рейтинге<br><small>из <?= (int)($steps['TOTAL_USERS'] ?? 0) ?></small></div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= (int)($steps['TOP_PERCENT'] ?? 0) ?>%</div>
                <div class="x5pwa-metric-label">Топ-%</div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value"><?= number_format((int)($steps['GOAL'] ?? 0), 0, '', ' ') ?></div>
                <div class="x5pwa-metric-label">Цель</div>
            </div>
        </div>
    <?php endif ?>
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

<?php
$themeTitles = $stats['THEME_TITLES'] ?? [];
$themeRows = [];
foreach ($stats['BY_THEME'] as $code => $count) {
    if (in_array($code, ['general', 'charging'], true)) continue;
    if ((int)$count <= 0) continue;
    $title = $themeTitles[$code] ?? ($code === 'unknown' ? 'Не определено' : $code);
    $themeRows[$title] = (int)$count;
}
?>

<div class="x5pwa-box">
    <h3>Разбивка по направлениям</h3>
    <?php if (!$themeRows): ?>
        <div style="color:#888">Нет прочитанных лекций по направлениям.</div>
    <?php else: ?>
        <table class="x5pwa-table">
            <?php foreach ($themeRows as $title => $count): ?>
                <tr>
                    <td><?= htmlspecialcharsbx((string)$title) ?></td>
                    <td><b><?= $count ?></b></td>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endif ?>
</div>

<?php
$habitTitles = $stats['HABIT_TITLES'] ?? [];
$habitRows = [];
foreach ($stats['BY_HABIT'] as $id => $count) {
    if ((int)$count <= 0) continue;
    $title = $habitTitles[$id] ?? (string)$id;
    $habitRows[$title] = (int)$count;
}
?>

<div class="x5pwa-box">
    <h3>Привычки</h3>
    <?php if (!$habitRows): ?>
        <div style="color:#888">Нет выполненных привычек.</div>
    <?php else: ?>
        <table class="x5pwa-table">
            <?php foreach ($habitRows as $title => $count): ?>
                <tr>
                    <td><?= htmlspecialcharsbx((string)$title) ?></td>
                    <td><b><?= $count ?></b></td>
                </tr>
            <?php endforeach ?>
        </table>
    <?php endif ?>
</div>

<div class="x5pwa-box">
    <h3>Прочитанные лекции и зарядки</h3>
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