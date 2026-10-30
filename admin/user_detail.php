<?php
/**
 * Детальная статистика пользователя.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\Storage;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$uid = trim((string)($_GET['uid'] ?? ''));
if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
    LocalRedirect('x5_pwa_users.php?lang=' . LANGUAGE_ID);
}

$binding = Storage::getUidBinding($uid);
if (!$binding) {
    LocalRedirect('x5_pwa_users.php?lang=' . LANGUAGE_ID);
}

// ── Обработка переключения тестового аккаунта ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['x5_action'] ?? '');
    if ($action === 'toggle_test') {
        $newIsTest = ($binding['UF_IS_TEST'] ?? 'N') !== 'Y';
        Storage::toggleTestAccount($uid, $newIsTest);
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid));
    }
}

$stats = \X5\Pwa\StatsBuilder::buildUser($uid);
$sub   = Storage::getSubscription($uid);

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

$effAll     = (float)($stats['EFFICIENCY_ALL'] ?? 0);
$effDay     = (float)($stats['EFFICIENCY_DAY'] ?? 0);
$earnedAll  = (int)($stats['EARNED_ALL'] ?? 0);
$maxAll     = (int)($stats['MAX_ALL'] ?? 0);
$missingAll = (int)($stats['MISSING_ALL'] ?? 0);
$earnedDay  = (int)($stats['EARNED_DAY'] ?? 0);
$maxDay     = (int)($stats['MAX_DAY'] ?? 0);
$missingDay = max(0, $maxDay - $earnedDay);
$rank       = (int)($stats['RANK'] ?? 0);
$habitsDays = (int)($stats['HABITS_DAYS'] ?? 0);

$periodFrom = (string)($stats['PERIOD_FROM'] ?? '');
$periodTo   = (string)($stats['PERIOD_TO']   ?? '');

$effColor = function (float $p): string {
    return $p < 30 ? '#c0392b' : ($p < 70 ? '#f5a623' : '#3a7d1c');
};

$themeCode     = trim((string)($binding['UF_THEME'] ?? ''));
$hasConsent    = (bool)($stats['HAS_CONSENT'] ?? false);
$consentGiven  = (bool)($stats['CONSENT'] ?? false);
$isTest        = (bool)($binding['UF_IS_TEST'] === 'Y');
$excludedStats = ($themeCode === '' || !$hasConsent || !$consentGiven || $isTest);

// ─── ТП: сводка + последние 10 тикетов ───
$tickets = [];
$ticketsStats = [
    'total'         => 0,
    'new'           => 0,
    'answered'      => 0,
    'closed'        => 0,
    'reset_pending' => 0,
];
try {
    $ticketsDc = Storage::dataClass('X5PwaSupportTickets');

    $allTickets = $ticketsDc::getList([
        'select' => ['ID', 'UF_STATUS', 'UF_AUTH_ERROR'],
        'filter' => ['=UF_UID' => $uid],
    ])->fetchAll();

    foreach ($allTickets as $t) {
        $st = (string)($t['UF_STATUS'] ?? '');
        $ae = (string)($t['UF_AUTH_ERROR'] ?? '');
        $ticketsStats['total']++;
        if (isset($ticketsStats[$st])) $ticketsStats[$st]++;
        if ($ae === 'RESET_UID_REQUEST' && $st !== 'closed') {
            $ticketsStats['reset_pending']++;
        }
    }

    $recentTickets = $ticketsDc::getList([
        'select' => ['ID', 'UF_SUBJECT', 'UF_STATUS', 'UF_AUTH_ERROR', 'UF_MESSAGES_COUNT', 'UF_CREATED_AT', 'UF_UPDATED_AT'],
        'filter' => ['=UF_UID' => $uid],
        'order'  => ['UF_UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
        'limit'  => 10,
    ])->fetchAll();

    foreach ($recentTickets as $t) {
        $tickets[] = [
            'ID'             => (int)$t['ID'],
            'SUBJECT'        => (string)($t['UF_SUBJECT'] ?? ''),
            'STATUS'         => (string)($t['UF_STATUS'] ?? ''),
            'AUTH_ERROR'     => (string)($t['UF_AUTH_ERROR'] ?? ''),
            'MESSAGES_COUNT' => (int)($t['UF_MESSAGES_COUNT'] ?? 0),
            'CREATED_TS'     => $t['UF_CREATED_AT'] ? $t['UF_CREATED_AT']->getTimestamp() : 0,
            'UPDATED_TS'     => $t['UF_UPDATED_AT'] ? $t['UF_UPDATED_AT']->getTimestamp() : 0,
        ];
    }
} catch (\Throwable $e) {
    \Bitrix\Main\Diag\Debug::writeToFile(
        'user_detail tickets: ' . $e->getMessage(),
        'user_detail',
        '/x5.pwa.log'
    );
}

// ─── Шагомер: pending/rejected + последние 10 записей ───
$stepsRecent   = [];
$stepsPending  = 0;
$stepsRejected = 0;
$stepsApproved = 0;
try {
    $stepsDc = Storage::dataClass('X5PwaSteps');

    $allSteps = $stepsDc::getList([
        'select' => ['ID', 'UF_STATUS'],
        'filter' => ['=UF_UID' => $uid],
    ])->fetchAll();

    foreach ($allSteps as $s) {
        $st = (string)($s['UF_STATUS'] ?? '');
        if ($st === 'N')      $stepsPending++;
        elseif ($st === 'R')  $stepsRejected++;
        elseif ($st === 'Y')  $stepsApproved++;
    }

    $recentSteps = $stepsDc::getList([
        'select' => ['ID', 'UF_LOCAL_DATE', 'UF_STEPS', 'UF_SENT_AT', 'UF_STATUS', 'UF_REJECT_REASON'],
        'filter' => ['=UF_UID' => $uid],
        'order'  => ['UF_SENT_AT' => 'DESC'],
        'limit'  => 10,
    ])->fetchAll();

    foreach ($recentSteps as $s) {
        $stepsRecent[] = [
            'ID'            => (int)$s['ID'],
            'LOCAL_DATE'    => (string)($s['UF_LOCAL_DATE'] ?? ''),
            'STEPS'         => (int)($s['UF_STEPS'] ?? 0),
            'STATUS'        => (string)($s['UF_STATUS'] ?? ''),
            'REJECT_REASON' => (string)($s['UF_REJECT_REASON'] ?? ''),
            'SENT_TS'       => $s['UF_SENT_AT'] ? $s['UF_SENT_AT']->getTimestamp() : 0,
        ];
    }
} catch (\Throwable $e) {
    \Bitrix\Main\Diag\Debug::writeToFile(
        'user_detail steps: ' . $e->getMessage(),
        'user_detail',
        '/x5.pwa.log'
    );
}

// Используем собственный параметр x5uid — он не конфликтует
// с машинерией CAdminUiList/Filter и гарантированно доходит до страницы.
$urlStepsAll      = 'x5_pwa_steps.php?lang=' . LANGUAGE_ID . '&x5uid=' . urlencode($uid);
$urlStepsPending  = $urlStepsAll . '&find_STATUS=N';
$urlStepsRejected = $urlStepsAll . '&find_STATUS=R';

$urlTicketsAll    = 'x5_pwa_support.php?lang=' . LANGUAGE_ID . '&x5uid=' . urlencode($uid);
$urlTicketsNew    = $urlTicketsAll . '&find_STATUS=new';
$urlTicketsReset  = $urlTicketsAll . '&find_AUTH_ERROR=RESET_UID_REQUEST';

$urlRewardsAll    = 'x5_pwa_rewards.php?lang=' . LANGUAGE_ID . '&x5uid=' . urlencode($uid);

// ─── «Требует внимания» ───
$attention = [];
if ($stepsPending > 0) {
    $attention[] = [
        'text'  => 'Шагомер: ' . $stepsPending . ' зап. на модерации',
        'url'   => $urlStepsPending,
        'color' => '#8a6b00',
        'bg'    => '#fdf3cf',
    ];
}
if ($stepsRejected > 0) {
    $attention[] = [
        'text'  => 'Шагомер: ' . $stepsRejected . ' отклонено (можно пересдать)',
        'url'   => $urlStepsRejected,
        'color' => '#a52020',
        'bg'    => '#f5e0e0',
    ];
}
if ($ticketsStats['reset_pending'] > 0) {
    $attention[] = [
        'text'  => 'ТП: ' . $ticketsStats['reset_pending'] . ' зап. на сброс UID',
        'url'   => $urlTicketsReset,
        'color' => '#a52020',
        'bg'    => '#ffeaea',
    ];
}
if ($ticketsStats['new'] > 0) {
    $attention[] = [
        'text'  => 'ТП: ' . $ticketsStats['new'] . ' новых обращений',
        'url'   => $urlTicketsNew,
        'color' => '#8a6b00',
        'bg'    => '#fdf3cf',
    ];
}

$statusTicketLabels = [
    'new'      => ['Новый',     '#fdf3cf', '#8a6b00'],
    'answered' => ['Отвечен',   '#e6f5d8', '#3a7d1c'],
    'closed'   => ['Закрыт',    '#f0f2f5', '#666'],
];
$statusStepLabels = [
    'N' => ['На модерации', '#fdf3cf', '#8a6b00'],
    'Y' => ['Одобрено',     '#e6f5d8', '#3a7d1c'],
    'R' => ['Отклонено',    '#f5e0e0', '#a52020'],
];
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
    .x5pwa-metric-value.x5pwa-green  { color: #3a7d1c; }
    .x5pwa-metric-value.x5pwa-red    { color: #c0392b; }
    .x5pwa-metric-value.x5pwa-blue   { color: #1e6bcc; }
    .x5pwa-metric-value.x5pwa-yellow { color: #8a6b00; }
    .x5pwa-week { display: flex; gap: 6px; }
    .x5pwa-week-day { flex: 1; background: #f0f2f5; padding: 8px 4px; text-align: center; border-radius: 4px; font-size: 11px; }
    .x5pwa-week-day.has-activity { background: #d4edaa; }
    .x5pwa-week-count { font-size: 16px; font-weight: 600; }
    .x5pwa-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; }
    .x5pwa-badge-yes { background: #e6f5d8; color: #3a7d1c; }
    .x5pwa-badge-no  { background: #f5e0e0; color: #a52020; }
    .x5pwa-badge-none{ background: #f0f2f5; color: #666; }
    .x5pwa-badge-test{ background: #fff4e0; color: #8a4b00; border: 1px solid #f5a623; }
    .x5pwa-table { width: 100%; border-collapse: collapse; }
    .x5pwa-table td { padding: 6px 12px; border-bottom: 1px solid #eee; }
    .x5pwa-table td:first-child { width: 40%; }
    .x5pwa-table td:last-child { text-align: right; }

    .x5pwa-eff-card { background:#fff; border:1px solid #e2e5e9; padding:20px; margin-bottom:16px; }
    .x5pwa-eff-card h3 { margin:0 0 16px 0; font-size:14px; }
    .x5pwa-eff-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
    .x5pwa-eff-block { }
    .x5pwa-eff-title { font-size:11px; color:#666; text-transform:uppercase; margin-bottom:10px; }
    .x5pwa-eff-main { display:flex; align-items:center; gap:16px; margin-bottom:10px; }
    .x5pwa-eff-value { font-size:32px; font-weight:700; }
    .x5pwa-eff-num { font-size:13px; color:#555; }
    .x5pwa-eff-num b { font-family:monospace; }
    .x5pwa-eff-bar { height:10px; background:#e2e5e9; border-radius:5px; overflow:hidden; margin-bottom:8px; }
    .x5pwa-eff-bar-fill { height:100%; transition:width .2s; }
    .x5pwa-eff-nums { display:flex; gap:20px; font-size:12px; color:#555; }
    .x5pwa-eff-nums span { display:flex; align-items:center; gap:4px; }

    .x5pwa-activity-table { width:100%; border-collapse:collapse; }
    .x5pwa-activity-table th { text-align:left; padding:8px 12px; background:#f8f9fa; font-size:12px; color:#555; border-bottom:2px solid #e2e5e9; }
    .x5pwa-activity-table td { padding:8px 12px; border-bottom:1px solid #eee; font-size:13px; }
    .x5pwa-activity-table td.num { text-align:right; font-family:monospace; }
    .x5pwa-activity-table td.total { color:#888; }

    .x5pwa-warn {
        background:#fff4e0; border:1px solid #f5a623; padding:10px 14px;
        border-radius:6px; margin-bottom:16px; font-size:13px; color:#8a4b00;
    }
    .x5pwa-test-warn {
        background:#e8f4ff; border:1px solid #1e6bcc; padding:10px 14px;
        border-radius:6px; margin-bottom:16px; font-size:13px; color:#1e6bcc;
    }

    .x5pwa-attention {
        background:#fff; border:1px solid #e2e5e9;
        padding:12px 16px; margin-bottom:16px; border-radius:6px;
    }
    .x5pwa-attention-title {
        font-size:12px; color:#666; text-transform:uppercase;
        margin-bottom:8px; font-weight:600;
    }
    .x5pwa-attention-list {
        display:flex; gap:10px; flex-wrap:wrap;
    }
    .x5pwa-attention-item {
        display:inline-block; padding:6px 12px;
        border-radius:4px; font-size:13px;
        text-decoration:none; font-weight:500;
    }
    .x5pwa-attention-item:hover {
        text-decoration:underline;
    }

    .x5pwa-tickets { width:100%; border-collapse:collapse; }
    .x5pwa-tickets th {
        text-align:left; padding:8px 12px;
        background:#f8f9fa; font-size:12px; color:#555;
        border-bottom:2px solid #e2e5e9;
    }
    .x5pwa-tickets td {
        padding:8px 12px; border-bottom:1px solid #eee; font-size:13px;
    }
    .x5pwa-tickets td.num {
        text-align:right; font-family:monospace;
    }
    .x5pwa-tickets tr:hover td {
        background:#f8f9fa;
    }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_users.php?lang=<?= LANGUAGE_ID ?>">← Ко всем пользователям</a>
</div>

<?php if ($isTest): ?>
    <div class="x5pwa-test-warn">
        <b>Тестовый аккаунт.</b> Пользователь имеет полный доступ к функциям приложения, но исключён из общей статистики, рейтингов и отчетов по компаниям (числится в «Техническом» месте работы).
    </div>
<?php endif; ?>

<?php if (!$hasConsent || !$consentGiven): ?>
    <div class="x5pwa-warn">
        <b>Нет согласия на обработку ПД.</b>
        Пользователь не участвует в статистике — эффективность и day-метрики не пересчитываются.
    </div>
<?php elseif ($themeCode === ''): ?>
    <div class="x5pwa-warn">
        <b>Направление не выбрано.</b>
        По БМ пользователь без темы не участвует в статистике — эффективность и day-метрики
        не пересчитываются (значения 0 или устаревшие). В агрегатах этот пользователь исключён.
    </div>
<?php endif ?>

<?php if ($attention): ?>
    <div class="x5pwa-attention">
        <div class="x5pwa-attention-title">⚠ Требует внимания</div>
        <div class="x5pwa-attention-list">
            <?php foreach ($attention as $a): ?>
                <a href="<?= htmlspecialcharsbx($a['url']) ?>"
                   class="x5pwa-attention-item"
                   style="background:<?= $a['bg'] ?>;color:<?= $a['color'] ?>;">
                    <?= htmlspecialcharsbx($a['text']) ?> →
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="x5pwa-box">
    <h3>Идентификация</h3>
    <dl class="x5pwa-kv">
        <dt>UID</dt>
        <dd class="x5pwa-mono"><?= htmlspecialcharsbx($uid) ?></dd>

        <dt>Ф.И.О</dt>
        <dd>
            <?php $fio = (string)($binding['UF_FIO'] ?? ''); ?>
            <?= $fio !== '' ? htmlspecialcharsbx($fio) : '<span style="color:#aaa">—</span>' ?>
        </dd>

        <dt>Никнейм</dt>
        <dd>
            <?php $nick = (string)($binding['UF_NICKNAME'] ?? ''); ?>
            <?= $nick !== '' ? htmlspecialcharsbx('@' . ltrim($nick, '@')) : '<span style="color:#aaa">—</span>' ?>
        </dd>

        <dt>Место работы</dt>
        <dd>
            <?php $wp = (string)($binding['UF_WORKPLACE'] ?? ''); ?>
            <?= $wp !== '' ? htmlspecialcharsbx($wp) : '<span style="color:#aaa">—</span>' ?>
        </dd>

        <dt>Device ID</dt>
        <dd class="x5pwa-mono"><?= htmlspecialcharsbx((string)$binding['UF_DEVICE_ID']) ?></dd>

        <dt>Создан</dt>
        <dd><?= fmt_ms($binding['UF_CREATED_AT'] ? $binding['UF_CREATED_AT']->getTimestamp() * 1000 : 0) ?></dd>

        <dt>Последняя активность</dt>
        <dd><?= fmt_ms($binding['UF_LAST_SEEN_AT'] ? $binding['UF_LAST_SEEN_AT']->getTimestamp() * 1000 : 0) ?></dd>

        <dt>Направление</dt>
        <dd>
            <?php if ($themeCode !== ''): ?>
                <span class="x5pwa-badge" style="background:#e0efff;color:#1e6bcc">
                    <?= htmlspecialcharsbx($themeCode) ?>
                </span>
            <?php else: ?>
                <span class="x5pwa-badge x5pwa-badge-none">не выбрано</span>
            <?php endif ?>
        </dd>

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

        <dt>Тестовый аккаунт</dt>
        <dd>
            <?php if ($isTest): ?>
                <span class="x5pwa-badge x5pwa-badge-test">ТЕСТОВЫЙ</span>
                <span style="color:#888;font-size:12px;margin-left:8px;">(Место работы: __TEST__)</span>
            <?php else: ?>
                <span class="x5pwa-badge x5pwa-badge-none">Нет</span>
            <?php endif ?>
            <form method="POST" style="display:inline-block; margin-left:12px;">
                <?= bitrix_sessid_post() ?>
                <input type="hidden" name="x5_action" value="toggle_test">
                <button type="submit" class="adm-btn" onclick="return confirm('Переключить статус тестового аккаунта?')">
                    <?= $isTest ? 'Сделать реальным' : 'Сделать тестовым' ?>
                </button>
            </form>
        </dd>
    </dl>
</div>

<!-- ЭФФЕКТИВНОСТЬ: период + сегодня -->
<div class="x5pwa-eff-card">
    <h3>Эффективность</h3>

    <div class="x5pwa-eff-grid">
        <div class="x5pwa-eff-block">
            <div class="x5pwa-eff-title">За период (<?= htmlspecialcharsbx($periodFrom) ?> — <?= htmlspecialcharsbx($periodTo) ?>)</div>
            <div class="x5pwa-eff-main">
                <div class="x5pwa-eff-value" style="color:<?= $effColor($effAll) ?>">
                    <?= number_format($effAll, 1, '.', '') ?>%
                </div>
                <?php if ($rank > 0): ?>
                    <div class="x5pwa-eff-num">
                        Ранг: <b>#<?= $rank ?></b>
                    </div>
                <?php endif ?>
            </div>
            <div class="x5pwa-eff-bar">
                <div class="x5pwa-eff-bar-fill"
                     style="width:<?= max(0, min(100, $effAll)) ?>%;background:<?= $effColor($effAll) ?>"></div>
            </div>
            <div class="x5pwa-eff-nums">
                <span>Собрано: <b style="color:#3a7d1c"><?= number_format($earnedAll, 0, '', ' ') ?></b></span>
                <span>Возможно: <b><?= number_format($maxAll, 0, '', ' ') ?></b></span>
                <span>Недостаёт: <b style="color:<?= $missingAll > 0 ? '#c0392b' : '#888' ?>">
                    <?= $missingAll > 0 ? '−' . number_format($missingAll, 0, '', ' ') : '0' ?>
                </b></span>
            </div>
        </div>

        <div class="x5pwa-eff-block">
            <div class="x5pwa-eff-title">Сегодня (по локальной дате пользователя)</div>
            <div class="x5pwa-eff-main">
                <div class="x5pwa-eff-value" style="color:<?= $effColor($effDay) ?>">
                    <?= number_format($effDay, 1, '.', '') ?>%
                </div>
                <div class="x5pwa-eff-num">
                    Дней привычки: <b><?= $habitsDays ?></b>
                </div>
            </div>
            <div class="x5pwa-eff-bar">
                <div class="x5pwa-eff-bar-fill"
                     style="width:<?= max(0, min(100, $effDay)) ?>%;background:<?= $effColor($effDay) ?>"></div>
            </div>
            <div class="x5pwa-eff-nums">
                <span>Собрано: <b style="color:#3a7d1c"><?= number_format($earnedDay, 0, '', ' ') ?></b></span>
                <span>Возможно: <b><?= number_format($maxDay, 0, '', ' ') ?></b></span>
                <span>Недостаёт: <b style="color:<?= $missingDay > 0 ? '#c0392b' : '#888' ?>">
                    <?= $missingDay > 0 ? '−' . number_format($missingDay, 0, '', ' ') : '0' ?>
                </b></span>
            </div>
        </div>
    </div>

    <div style="font-size:11px;color:#888;margin-top:16px">
        <b>Возможно</b> = сумма дневных максимумов за период: BASE + направление пользователя + вклад его привычки.
        <b>Собрано</b> = сумма фактически начисленных баллов из журнала наград.
        <?php if ($excludedStats): ?>
            <br>
            <b style="color:#8a4b00">Для этого пользователя кеш не пересчитывается</b>
            (тест, нет темы и/или нет согласия) — day-метрики и эффективность могут быть неактуальны.
        <?php endif ?>
    </div>
</div>

<!-- АКТИВНОСТЬ: период + всего -->
<div class="x5pwa-box">
    <h3>Активность</h3>
    <table class="x5pwa-activity-table">
        <thead>
            <tr>
                <th>Показатель</th>
                <th style="text-align:right">
                    За период<br>
                    <small style="color:#888;font-weight:400"><?= htmlspecialcharsbx($periodFrom) ?> — <?= htmlspecialcharsbx($periodTo) ?></small>
                </th>
                <th style="text-align:right">За всё время</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $activityRows = [
                ['label' => 'Лекций прочитано',       'p' => (int)($stats['LECTURES_READ_PERIOD'] ?? 0),     't' => (int)$stats['LECTURES_READ']],
                ['label' => 'Заданий выполнено',      'p' => (int)($stats['TASKS_DONE_PERIOD'] ?? 0),        't' => (int)$stats['TASKS_DONE']],
                ['label' => 'Привычек отмечено',      'p' => (int)($stats['HABITS_DONE_PERIOD'] ?? 0),       't' => (int)$stats['HABITS_DONE']],
                ['label' => 'Шагов подтверждено',     'p' => (int)($stats['STEPS_CONFIRMED_PERIOD'] ?? 0),   't' => (int)($steps['TOTAL_STEPS'] ?? 0)],
                ['label' => 'Событий посещено',       'p' => (int)($stats['EVENTS_VISITED_PERIOD'] ?? 0),    't' => (int)($stats['EVENTS_VISITED'] ?? 0)],
                ['label' => 'Событий подтверждено',   'p' => (int)($stats['EVENTS_CONFIRMED_PERIOD'] ?? 0),  't' => (int)($stats['EVENTS_CONFIRMED'] ?? 0)],
            ];
            foreach ($activityRows as $ar):
            ?>
                <tr>
                    <td><?= htmlspecialcharsbx($ar['label']) ?></td>
                    <td class="num"><b><?= number_format($ar['p'], 0, '', ' ') ?></b></td>
                    <td class="num total"><?= number_format($ar['t'], 0, '', ' ') ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <div style="font-size:11px;color:#888;margin-top:12px">
        «За период» — активности в диапазоне <?= htmlspecialcharsbx($periodFrom) ?> — <?= htmlspecialcharsbx($periodTo) ?>.
        «За всё время» — суммарно по всем активностям пользователя (для шагов — по подтверждённым модератором).
    </div>
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
    <h3>Разбивка лекций (за всё время)</h3>
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
            <div class="x5pwa-metric-value"><?= (int)$stats['STREAK'] ?></div>
            <div class="x5pwa-metric-label">Дней подряд</div>
        </div>
    </div>
</div>

<div class="x5pwa-box">
    <h3>Награды</h3>
    <div class="x5pwa-grid" style="grid-template-columns: repeat(2, 1fr)">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-green"><?= (int)($stats['REWARDS_BALANCE'] ?? 0) ?></div>
            <div class="x5pwa-metric-label">Баланс (за всё время)</div>
        </div>
        <div class="x5pwa-metric" style="display:flex;align-items:center;justify-content:center">
            <a href="<?= htmlspecialcharsbx($urlRewardsAll) ?>" class="adm-btn">Полная история →</a>
        </div>
    </div>
</div>

<!-- ══════════════ ОБРАЩЕНИЯ В ТП ══════════════ -->
<div class="x5pwa-box">
    <h3>Обращения в техподдержку</h3>

    <div class="x5pwa-grid" style="grid-template-columns: repeat(5, 1fr);margin-bottom:16px">
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value"><?= $ticketsStats['total'] ?></div>
            <div class="x5pwa-metric-label">Всего</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-yellow"><?= $ticketsStats['new'] ?></div>
            <div class="x5pwa-metric-label">Новых</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-green"><?= $ticketsStats['answered'] ?></div>
            <div class="x5pwa-metric-label">Отвечено</div>
        </div>
        <div class="x5pwa-metric">
            <div class="x5pwa-metric-value x5pwa-grey" style="color:#888;"><?= $ticketsStats['closed'] ?></div>
            <div class="x5pwa-metric-label">Закрыто</div>
        </div>
        <div class="x5pwa-metric" style="<?= $ticketsStats['reset_pending'] > 0 ? 'background:#ffeaea;' : '' ?>">
            <div class="x5pwa-metric-value x5pwa-red"><?= $ticketsStats['reset_pending'] ?></div>
            <div class="x5pwa-metric-label">Сброс UID (открыто)</div>
        </div>
    </div>

    <?php if (empty($tickets)): ?>
        <div style="color:#888;padding:8px 0;">Пользователь ни разу не обращался в ТП.</div>
    <?php else: ?>
        <table class="x5pwa-tickets">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th style="width:120px;">Тип</th>
                    <th>Тема</th>
                    <th style="width:110px;">Статус</th>
                    <th style="width:110px;">Обновлён</th>
                    <th style="width:60px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $t):
                    $isResetT = ($t['AUTH_ERROR'] === 'RESET_UID_REQUEST');
                    $stInfo   = $statusTicketLabels[$t['STATUS']] ?? ['—', '#f0f2f5', '#666'];
                    $detailUrl = 'x5_pwa_support_detail.php?lang=' . LANGUAGE_ID . '&ID=' . $t['ID'];
                    $updatedStr = $t['UPDATED_TS'] > 0 ? date('d.m.Y H:i', $t['UPDATED_TS']) : '—';
                ?>
                    <tr>
                        <td><a href="<?= htmlspecialcharsbx($detailUrl) ?>"><b>#<?= $t['ID'] ?></b></a></td>
                        <td>
                            <?php if ($isResetT): ?>
                                <span class="x5pwa-badge" style="background:#fdf3cf;color:#8a6b00;font-weight:600;">RESET UID</span>
                            <?php elseif ($t['AUTH_ERROR'] !== ''): ?>
                                <span class="x5pwa-badge" style="background:#f5e0e0;color:#a52020;font-weight:600;"><?= htmlspecialcharsbx($t['AUTH_ERROR']) ?></span>
                            <?php else: ?>
                                <span style="color:#888;font-size:12px;">Обычное</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <a href="<?= htmlspecialcharsbx($detailUrl) ?>" style="color:#1e6bcc;">
                                <?= htmlspecialcharsbx($t['SUBJECT'] !== '' ? $t['SUBJECT'] : '—') ?>
                            </a>
                            <span style="color:#888;font-size:11px;margin-left:6px;">
                                (<?= $t['MESSAGES_COUNT'] ?> сообщ.)
                            </span>
                        </td>
                        <td>
                            <span class="x5pwa-badge" style="background:<?= $stInfo[1] ?>;color:<?= $stInfo[2] ?>;">
                                <?= htmlspecialcharsbx($stInfo[0]) ?>
                            </span>
                        </td>
                        <td style="font-family:monospace;font-size:12px;color:#666;"><?= htmlspecialcharsbx($updatedStr) ?></td>
                        <td><a href="<?= htmlspecialcharsbx($detailUrl) ?>" class="adm-btn" style="padding:2px 8px;">Открыть</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="<?= htmlspecialcharsbx($urlTicketsAll) ?>" class="adm-btn">
            Все тикеты пользователя →
        </a>
        <?php if ($ticketsStats['new'] > 0): ?>
            <a href="<?= htmlspecialcharsbx($urlTicketsNew) ?>" class="adm-btn adm-btn-save">
                Открыть новые (<?= $ticketsStats['new'] ?>) →
            </a>
        <?php endif; ?>
        <?php if ($ticketsStats['reset_pending'] > 0): ?>
            <a href="<?= htmlspecialcharsbx($urlTicketsReset) ?>" class="adm-btn" style="background:#ffeaea;border-color:#c0392b;color:#a52020;">
                Запросы на сброс UID (<?= $ticketsStats['reset_pending'] ?>) →
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════ ШАГОМЕР (расширенный) ══════════════ -->
<div class="x5pwa-box">
    <h3>Шагомер</h3>

    <?php if (empty($steps)): ?>
        <div style="color:#888">Нет данных шагомера.</div>
    <?php else: ?>
        <div class="x5pwa-grid" style="margin-bottom:16px">
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

        <div class="x5pwa-grid" style="grid-template-columns: repeat(3, 1fr);margin-bottom:16px">
            <div class="x5pwa-metric" style="<?= $stepsPending > 0 ? 'background:#fdf3cf;' : '' ?>">
                <div class="x5pwa-metric-value x5pwa-yellow"><?= $stepsPending ?></div>
                <div class="x5pwa-metric-label">На модерации</div>
            </div>
            <div class="x5pwa-metric" style="<?= $stepsRejected > 0 ? 'background:#ffeaea;' : '' ?>">
                <div class="x5pwa-metric-value x5pwa-red"><?= $stepsRejected ?></div>
                <div class="x5pwa-metric-label">Отклонено</div>
            </div>
            <div class="x5pwa-metric">
                <div class="x5pwa-metric-value x5pwa-green"><?= $stepsApproved ?></div>
                <div class="x5pwa-metric-label">Одобрено</div>
            </div>
        </div>

        <?php if (!empty($stepsRecent)): ?>
            <h4 style="margin:0 0 8px 0;font-size:13px;color:#2c3e50;font-weight:600;">
                Последние 10 записей
            </h4>
            <table class="x5pwa-tickets">
                <thead>
                    <tr>
                        <th style="width:110px;">Дата (лок.)</th>
                        <th style="width:100px;text-align:right;">Шаги</th>
                        <th style="width:130px;">Статус</th>
                        <th style="width:130px;">Отправлено</th>
                        <th>Причина отказа</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stepsRecent as $s):
                        $stInfoS = $statusStepLabels[$s['STATUS']] ?? ['—', '#f0f2f5', '#666'];
                        $sentStr = $s['SENT_TS'] > 0 ? date('d.m.Y H:i', $s['SENT_TS']) : '—';
                    ?>
                        <tr>
                            <td><b><?= htmlspecialcharsbx($s['LOCAL_DATE']) ?></b></td>
                            <td class="num"><b><?= number_format($s['STEPS'], 0, '', ' ') ?></b></td>
                            <td>
                                <span class="x5pwa-badge" style="background:<?= $stInfoS[1] ?>;color:<?= $stInfoS[2] ?>;">
                                    <?= htmlspecialcharsbx($stInfoS[0]) ?>
                                </span>
                            </td>
                            <td style="font-family:monospace;font-size:12px;color:#666;"><?= htmlspecialcharsbx($sentStr) ?></td>
                            <td style="font-size:12px;color:#a52020;">
                                <?= $s['REJECT_REASON'] !== '' ? htmlspecialcharsbx($s['REJECT_REASON']) : '<span style="color:#ccc">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="<?= htmlspecialcharsbx($urlStepsAll) ?>" class="adm-btn">
                Все записи шагомера →
            </a>
            <?php if ($stepsPending > 0): ?>
                <a href="<?= htmlspecialcharsbx($urlStepsPending) ?>" class="adm-btn adm-btn-save">
                    Открыть на модерации (<?= $stepsPending ?>) →
                </a>
            <?php endif; ?>
            <?php if ($stepsRejected > 0): ?>
                <a href="<?= htmlspecialcharsbx($urlStepsRejected) ?>" class="adm-btn" style="background:#ffeaea;border-color:#c0392b;color:#a52020;">
                    Открыть отклонённые (<?= $stepsRejected ?>) →
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
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
    <h3>Разбивка по направлениям (за всё время)</h3>
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
    <h3>Привычки (за всё время)</h3>
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
    <h3>Прочитанные лекции и зарядки (за всё время)</h3>
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
    <h3>Выполненные задания (за всё время)</h3>
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
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="x5_pwa_push.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($uid) ?>" class="adm-btn adm-btn-save">
            Отправить PUSH
        </a>
        <a href="<?= htmlspecialcharsbx($urlStepsAll) ?>" class="adm-btn">
            Записи шагомера (<?= $stepsPending + $stepsApproved + $stepsRejected ?>)
        </a>
        <a href="<?= htmlspecialcharsbx($urlTicketsAll) ?>" class="adm-btn">
            Тикеты ТП (<?= $ticketsStats['total'] ?>)
        </a>
        <a href="<?= htmlspecialcharsbx($urlRewardsAll) ?>" class="adm-btn">
            История наград
        </a>
    </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';