<?php
/**
 * PWA — Шагомер: детальный просмотр / редактирование записи.
 *
 * Комментарий обязателен при изменении шагов или даты.
 * Пишется в UF_ADMIN_COMMENT (HL X5PwaSteps) и НЕ отдаётся в API/CDN.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\Storage;
use X5\Pwa\StepsBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$id = (int)($_REQUEST['ID'] ?? 0);
if ($id <= 0) {
    LocalRedirect('x5_pwa_steps.php?lang=' . LANGUAGE_ID);
}

$step = StepsBuilder::getById($id);
if (!$step) {
    LocalRedirect('x5_pwa_steps.php?lang=' . LANGUAGE_ID);
}

$uid = (string)$step['UF_UID'];

// ── Пользователь ──
$binding = Storage::getUidBinding($uid);
$userLabel = '';
if ($binding) {
    $userLabel = trim((string)($binding['UF_NICKNAME'] ?? ''));
    if ($userLabel === '') $userLabel = trim((string)($binding['UF_FIO'] ?? ''));
}
if ($userLabel === '') $userLabel = substr($uid, 0, 12) . '…';

// ── Действия ──
$message = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    global $USER;
    $adminId = (int)$USER->GetID();
    $action  = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $newSteps = (int)($_POST['steps'] ?? 0);
        $newDate  = trim((string)($_POST['local_date'] ?? ''));
        $comment  = trim((string)($_POST['comment'] ?? ''));

        $res = StepsBuilder::updateAdmin($id, $newSteps, $newDate, $comment, $adminId);
        if (!empty($res['ok'])) {
            $message = $res['message'] ?? 'OK';
        } else {
            $error = $res['error'] ?? 'Не удалось сохранить.';
        }

        // Перечитать запись — вдруг изменилась
        $step = StepsBuilder::getById($id) ?: $step;
    }

    if (in_array($action, ['approve', 'reject', 'reset'], true)) {
        $statusMap = ['approve' => 'Y', 'reject' => 'R', 'reset' => 'N'];
        $reason    = (string)($_POST['reject_reason'] ?? '');

        $ok = StepsBuilder::setStatus($id, $statusMap[$action], $adminId, $reason);
        if ($ok) {
            $message = 'Статус изменён.';
        } else {
            $error = 'Не удалось изменить статус.';
        }
        $step = StepsBuilder::getById($id) ?: $step;
    }
}

$APPLICATION->SetTitle('Запись шагомера #' . $id);

// ── Данные для отображения ──
$steps      = (int)($step['UF_STEPS'] ?? 0);
$localDate  = (string)($step['UF_LOCAL_DATE'] ?? '');
$status     = (string)($step['UF_STATUS'] ?? 'N');
$sentAt     = $step['UF_SENT_AT'] ? $step['UF_SENT_AT']->format('d.m.Y H:i:s') : '—';
$moderAt    = !empty($step['UF_MODERATED_AT']) ? $step['UF_MODERATED_AT']->format('d.m.Y H:i:s') : '—';
$rejectRsn  = (string)($step['UF_REJECT_REASON'] ?? '');
$adminCmt   = (string)($step['UF_ADMIN_COMMENT'] ?? '');
$images     = StepsBuilder::expandImages((string)($step['UF_IMAGES'] ?? ''));

$statusLabels = [
    'N' => ['На модерации', '#fdf3cf', '#8a6b00'],
    'Y' => ['Одобрено',     '#e6f5d8', '#3a7d1c'],
    'R' => ['Отклонено',    '#f5e0e0', '#a52020'],
];
$stInfo = $statusLabels[$status] ?? $statusLabels['N'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<style>
    .x5pwa-box { background:#fff; padding:16px; margin-bottom:16px; border:1px solid #e2e5e9; }
    .x5pwa-box h3 { margin:0 0 12px 0; font-size:14px; }
    .x5pwa-kv { display:grid; grid-template-columns:200px 1fr; gap:6px 12px; font-size:13px; }
    .x5pwa-kv dt { color:#666; }
    .x5pwa-kv dd { margin:0; font-weight:500; }
    .x5pwa-mono { font-family:monospace; font-size:12px; word-break:break-all; }
    .x5pwa-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:11px; }
    .x5pwa-images { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .x5pwa-images a { display:block; }
    .x5pwa-images img {
        max-width:160px; max-height:160px;
        border-radius:6px; border:1px solid #d0d4d9; display:block;
    }
    .x5pwa-form-row { display:grid; grid-template-columns:200px 1fr; gap:12px; align-items:start; margin-bottom:12px; }
    .x5pwa-form-row label { color:#555; padding-top:6px; }
    .x5pwa-form-row input[type=number],
    .x5pwa-form-row input[type=date],
    .x5pwa-form-row input[type=text],
    .x5pwa-form-row textarea {
        padding:6px 8px; border:1px solid #c5c9cf; border-radius:4px; font-size:13px;
    }
    .x5pwa-form-row textarea { font-family:inherit; width:100%; box-sizing:border-box; }
    .x5pwa-hint { color:#888; font-size:12px; margin-top:4px; }
    .x5pwa-comment-history {
        background:#f8f9fa; border:1px solid #e2e5e9; border-radius:4px;
        padding:10px 12px; font-family:monospace; font-size:12px;
        white-space:pre-wrap; max-height:200px; overflow-y:auto;
    }
    .x5pwa-actions { display:flex; gap:10px; flex-wrap:wrap; }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_steps.php?lang=<?= LANGUAGE_ID ?>&x5uid=<?= urlencode($uid) ?>">← К записям шагомера</a>
    &nbsp;·&nbsp;
    <a href="x5_pwa_user_detail.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($uid) ?>">Профиль пользователя →</a>
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

<!-- ══════════ 1. ТЕКУЩАЯ ЗАПИСЬ ══════════ -->
<div class="x5pwa-box">
    <h3>Запись #<?= (int)$id ?> · <span class="x5pwa-badge" style="background:<?= $stInfo[1] ?>;color:<?= $stInfo[2] ?>"><?= htmlspecialcharsbx($stInfo[0]) ?></span></h3>

    <dl class="x5pwa-kv">
        <dt>Пользователь</dt>
        <dd>
            <b><?= htmlspecialcharsbx($userLabel) ?></b>
            <div class="x5pwa-mono" style="color:#888;margin-top:2px"><?= htmlspecialcharsbx($uid) ?></div>
        </dd>

        <dt>Дата (локальная)</dt>
        <dd><b><?= htmlspecialcharsbx($localDate) ?></b></dd>

        <dt>Шаги</dt>
        <dd><b style="font-family:monospace;font-size:16px"><?= number_format($steps, 0, '', ' ') ?></b></dd>

        <dt>Отправлено</dt>
        <dd><?= htmlspecialcharsbx($sentAt) ?></dd>

        <dt>Модерация</dt>
        <dd><?= htmlspecialcharsbx($moderAt) ?></dd>

        <?php if ($rejectRsn !== ''): ?>
            <dt>Причина отказа</dt>
            <dd style="color:#a52020"><?= htmlspecialcharsbx($rejectRsn) ?></dd>
        <?php endif ?>

        <dt>Фото (<?= count($images) ?>)</dt>
        <dd>
            <?php if (empty($images)): ?>
                <span style="color:#aaa">— нет —</span>
            <?php else: ?>
                <div class="x5pwa-images">
                    <?php foreach ($images as $img): ?>
                        <a href="<?= htmlspecialcharsbx($img['URL']) ?>" target="_blank">
                            <img src="<?= htmlspecialcharsbx($img['THUMB']) ?>" alt="">
                        </a>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </dd>
    </dl>
</div>

<!-- ══════════ 2. РЕДАКТИРОВАНИЕ ══════════ -->
<div class="x5pwa-box">
    <h3>Редактирование</h3>
    <p style="color:#666;font-size:13px;margin:0 0 16px 0;">
        При изменении <b>шагов</b> или <b>даты</b> комментарий <b>обязателен</b>.
        Он сохраняется в истории изменений и <b>не отдаётся в API/CDN</b>.
    </p>

    <form method="POST" name="x5pwa_edit_step" onsubmit="return x5pwaValidateEdit(this);">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="save">

        <div class="x5pwa-form-row">
            <label>Шаги:</label>
            <div>
                <input type="number" name="steps" id="x5pwa_steps"
                       min="0" max="<?= StepsBuilder::MAX_STEPS ?>" step="1"
                       value="<?= (int)$steps ?>" style="width:160px">
                <span class="x5pwa-hint">Максимум <?= number_format(StepsBuilder::MAX_STEPS, 0, '', ' ') ?>.</span>
            </div>
        </div>

        <div class="x5pwa-form-row">
            <label>Дата (YYYY-MM-DD):</label>
            <div>
                <input type="date" name="local_date" id="x5pwa_date"
                       value="<?= htmlspecialcharsbx($localDate) ?>" style="width:200px">
                <span class="x5pwa-hint">
                    Смена на дату, где у этого же пользователя уже есть запись, запрещена.
                </span>
            </div>
        </div>

        <div class="x5pwa-form-row">
            <label>Комментарий <span style="color:#c0392b">*</span>:</label>
            <div>
                <textarea name="comment" id="x5pwa_comment" rows="3"
                          placeholder="Например: исправлена опечатка — фактически 7400 шагов"></textarea>
                <div class="x5pwa-hint">
                    Обязательно, если меняете шаги или дату. В API не попадает.
                </div>
            </div>
        </div>

        <div class="x5pwa-actions">
            <input type="submit" value="Сохранить изменения" class="adm-btn adm-btn-save">
        </div>
    </form>

    <script>
    function x5pwaValidateEdit(form) {
        var origSteps = <?= (int)$steps ?>;
        var origDate  = <?= json_encode($localDate) ?>;
        var newSteps  = parseInt(form.steps.value || '0', 10);
        var newDate   = form.local_date.value || '';
        var comment   = (form.comment.value || '').trim();

        var stepsChanged = newSteps !== origSteps;
        var dateChanged  = newDate !== origDate;

        if ((stepsChanged || dateChanged) && comment === '') {
            alert('Комментарий обязателен при изменении шагов или даты.');
            form.comment.focus();
            return false;
        }
        return true;
    }
    </script>
</div>

<!-- ══════════ 3. ИСТОРИЯ ИЗМЕНЕНИЙ ══════════ -->
<div class="x5pwa-box">
    <h3>История изменений (только админка)</h3>
    <?php if ($adminCmt === ''): ?>
        <div style="color:#888;font-size:13px">Изменений не было.</div>
    <?php else: ?>
        <div class="x5pwa-comment-history"><?= htmlspecialcharsbx($adminCmt) ?></div>
    <?php endif ?>
</div>

<!-- ══════════ 4. МОДЕРАЦИЯ ══════════ -->
<?php if ($status !== 'Y' || $status !== 'N'): ?>
<div class="x5pwa-box">
    <h3>Модерация</h3>
    <form method="POST" style="display:inline-flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="reject_reason" value="">

        <?php if ($status !== 'Y'): ?>
            <button type="submit" name="action" value="approve" class="adm-btn adm-btn-save"
                    onclick="return confirm('Одобрить запись?')">Одобрить</button>
        <?php endif ?>

        <?php if ($status !== 'R'): ?>
            <button type="submit" name="action" value="reject" class="adm-btn"
                    onclick="var r = prompt('Причина отклонения:', ''); if (r === null) return false; this.form.reject_reason.value = r; return true;">
                Отклонить
            </button>
        <?php endif ?>

        <?php if ($status !== 'N'): ?>
            <button type="submit" name="action" value="reset" class="adm-btn"
                    onclick="return confirm('Сбросить в «на модерации»?')">Сбросить в «на модерации»</button>
        <?php endif ?>
    </form>
</div>
<?php endif ?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';