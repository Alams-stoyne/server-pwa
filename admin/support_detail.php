<?php
/**
 * Техподдержка — деталка обращения.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\SupportBuilder;
use X5\Pwa\Storage;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$ticketId = (int)($_GET['id'] ?? $_REQUEST['id'] ?? 0);
if ($ticketId <= 0) {
    LocalRedirect('x5_pwa_support.php?lang=' . LANGUAGE_ID);
}

$res = SupportBuilder::getTicket($ticketId);
if (!$res) {
    LocalRedirect('x5_pwa_support.php?lang=' . LANGUAGE_ID);
}

$adminId = (int)$USER->GetID();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'reply') {
        $text = trim((string)($_POST['reply_text'] ?? ''));
        if ($text !== '') {
            $r = SupportBuilder::addAdminMessage($ticketId, $adminId, $text);
            if (!$r['ok']) {
                $errorMessage = 'Не удалось отправить ответ: ' . ($r['error'] ?? '');
            } else {
                LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&id=' . $ticketId);
            }
        } else {
            $errorMessage = 'Введите текст ответа.';
        }
    } elseif ($action === 'close') {
        SupportBuilder::closeTicket($ticketId, $adminId);
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&id=' . $ticketId);
    } elseif ($action === 'delete') {
        SupportBuilder::deleteTicket($ticketId);
        LocalRedirect('x5_pwa_support.php?lang=' . LANGUAGE_ID);
    }
}

$res = SupportBuilder::getTicket($ticketId);
$ticket   = $res['TICKET'];
$messages = $res['MESSAGES'];

$isGuest     = ($ticket['SOURCE'] ?? SupportBuilder::SRC_APP) === SupportBuilder::SRC_GUEST;
$userBinding = !$isGuest && !empty($ticket['UID'])
    ? Storage::getUidBinding((string)$ticket['UID'])
    : null;

// META: пробуем распарсить JSON
$metaArr = [];
if (!empty($ticket['META'])) {
    $decoded = json_decode((string)$ticket['META'], true);
    if (is_array($decoded)) $metaArr = $decoded;
}

$APPLICATION->SetTitle('Обращение №' . $ticketId . ($isGuest ? ' [гость]' : ''));
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5sup-box { background:#fff; padding:16px; margin-bottom:16px; border:1px solid #e2e5e9; }
    .x5sup-box h3 { margin:0 0 12px 0; font-size:14px; }
    .x5sup-kv { display:grid; grid-template-columns:220px 1fr; gap:6px 12px; }
    .x5sup-kv dt { color:#666; } .x5sup-kv dd { margin:0; font-weight:500; }
    .x5sup-thread { display:flex; flex-direction:column; gap:12px; }
    .x5sup-msg { padding:12px; border-radius:8px; max-width:85%; }
    .x5sup-msg-user { background:#f0f2f5; align-self:flex-start; }
    .x5sup-msg-admin { background:#e6f5d8; align-self:flex-end; }
    .x5sup-msg-head { font-size:11px; color:#666; margin-bottom:6px; }
    .x5sup-msg-text { white-space:pre-wrap; word-break:break-word; }
    .x5sup-msg-images { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .x5sup-msg-images a { display:block; }
    .x5sup-msg-images img { display:block; max-width:160px; max-height:160px; border-radius:6px; border:1px solid #ccc; }
    .x5sup-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:11px; }
    .x5sup-badge-new { background:#f5a623; color:#fff; }
    .x5sup-badge-answered { background:#3a7d1c; color:#fff; }
    .x5sup-badge-closed { background:#888; color:#fff; }
    .x5sup-badge-guest { background:#f5a623; color:#fff; }
    .x5sup-badge-user { background:#e6f5d8; color:#3a7d1c; }
    .x5sup-form textarea { width:100%; box-sizing:border-box; min-height:100px; padding:8px; font-family:inherit; }
    .x5sup-meta { font-family:monospace; font-size:11px; color:#555; white-space:pre-wrap; }
    .x5sup-warn { background:#fff4e0; border:1px solid #f5a623; padding:10px 14px; border-radius:6px; margin-bottom:12px; font-size:13px; }
</style>

<div style="margin-bottom:12px">
    <a href="x5_pwa_support.php?lang=<?= LANGUAGE_ID ?>">← Ко всем обращениям</a>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="adm-info-message-wrap adm-info-message-red" style="margin-bottom:12px">
        <div class="adm-info-message"><?= htmlspecialcharsbx($errorMessage) ?></div>
    </div>
<?php endif ?>

<?php if ($isGuest): ?>
    <div class="x5sup-warn">
        <b>Гостевое обращение.</b> Push-уведомление пользователю не отправляется — он не авторизован в приложении. Свяжитесь с ним по указанному Device ID или через дополнительные каналы.
    </div>
<?php endif ?>

<div class="x5sup-box">
    <h3>Обращение №<?= (int)$ticket['ID'] ?></h3>
    <dl class="x5sup-kv">
        <dt>Тема</dt>
        <dd><b><?= htmlspecialcharsbx($ticket['SUBJECT']) ?></b></dd>

        <dt>Статус</dt>
        <dd>
            <?php
            $stMap = [
                SupportBuilder::ST_NEW      => ['Ждёт ответа', 'new'],
                SupportBuilder::ST_ANSWERED => ['Отвечено',    'answered'],
                SupportBuilder::ST_CLOSED   => ['Закрыто',     'closed'],
            ];
            $st = $stMap[$ticket['STATUS']] ?? [$ticket['STATUS'], 'new'];
            ?>
            <span class="x5sup-badge x5sup-badge-<?= $st[1] ?>"><?= htmlspecialcharsbx($st[0]) ?></span>
            <span class="x5sup-badge x5sup-badge-<?= $isGuest ? 'guest' : 'user' ?>" style="margin-left:6px">
                <?= $isGuest ? 'Гость' : 'Пользователь' ?>
            </span>
        </dd>

        <?php if ($isGuest): ?>
            <dt>Device ID</dt>
            <dd><span style="font-family:monospace;font-size:12px"><?= htmlspecialcharsbx((string)$ticket['DEVICE_ID']) ?></span></dd>

            <dt>AUTH_ERROR</dt>
            <dd>
                <?php if ($ticket['AUTH_ERROR'] !== ''): ?>
                    <span style="font-family:monospace;background:#fff4e0;padding:2px 8px;border-radius:4px;font-size:11px">
                        <?= htmlspecialcharsbx($ticket['AUTH_ERROR']) ?>
                    </span>
                <?php else: ?>
                    <span style="color:#888">—</span>
                <?php endif ?>
            </dd>

            <dt>Заявленный UID</dt>
            <dd>
                <?php if ($ticket['UID'] !== ''): ?>
                    <span style="font-family:monospace;font-size:12px"><?= htmlspecialcharsbx((string)$ticket['UID']) ?></span>
                    <span style="color:#888;font-size:11px"> (не подтверждён)</span>
                <?php else: ?>
                    <span style="color:#888">—</span>
                <?php endif ?>
            </dd>
        <?php else: ?>
            <dt>Пользователь (UID)</dt>
            <dd>
                <span style="font-family:monospace;font-size:12px"><?= htmlspecialcharsbx((string)$ticket['UID']) ?></span>
                <?php if ($userBinding): ?>
                    — <?= htmlspecialcharsbx((string)($userBinding['UF_NICKNAME'] ?: $userBinding['UF_FIO'] ?: '—')) ?>
                    <a href="x5_pwa_user_detail.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode((string)$ticket['UID']) ?>"
                       style="margin-left:8px">(карточка)</a>
                <?php endif ?>
            </dd>
        <?php endif ?>

        <dt>Создано</dt>
        <dd><?= htmlspecialcharsbx((string)$ticket['CREATED_AT']) ?></dd>

        <dt>Обновлено</dt>
        <dd><?= htmlspecialcharsbx((string)$ticket['UPDATED_AT']) ?></dd>

        <?php if ($ticket['CLOSED_AT']): ?>
            <dt>Закрыто</dt>
            <dd><?= htmlspecialcharsbx((string)$ticket['CLOSED_AT']) ?></dd>
        <?php endif ?>

        <dt>Сообщений</dt>
        <dd><?= (int)$ticket['MESSAGES_COUNT'] ?></dd>

        <?php if ($ticket['IP'] !== ''): ?>
            <dt>IP</dt>
            <dd><span style="font-family:monospace;font-size:12px"><?= htmlspecialcharsbx((string)$ticket['IP']) ?></span></dd>
        <?php endif ?>

        <?php if ($ticket['UA'] !== ''): ?>
            <dt>User-Agent</dt>
            <dd><span style="font-family:monospace;font-size:11px;color:#555"><?= htmlspecialcharsbx((string)$ticket['UA']) ?></span></dd>
        <?php endif ?>
    </dl>
</div>

<?php if ($metaArr): ?>
    <div class="x5sup-box">
        <h3>Технические данные (META)</h3>
        <div class="x5sup-meta"><?= htmlspecialcharsbx((string)json_encode($metaArr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></div>
    </div>
<?php endif ?>

<div class="x5sup-box">
    <h3>Переписка</h3>
    <div class="x5sup-thread">
        <?php foreach ($messages as $m): ?>
            <div class="x5sup-msg x5sup-msg-<?= $m['IS_ADMIN'] ? 'admin' : 'user' ?>">
                <div class="x5sup-msg-head">
                    <b><?= $m['IS_ADMIN'] ? 'Администратор' : ($isGuest ? 'Гость' : 'Пользователь') ?></b>
                    · <?= htmlspecialcharsbx((string)$m['CREATED_AT']) ?>
                </div>
                <?php if ($m['TEXT'] !== ''): ?>
                    <div class="x5sup-msg-text"><?= htmlspecialcharsbx($m['TEXT']) ?></div>
                <?php endif ?>
                <?php if (!empty($m['IMAGES'])): ?>
                    <div class="x5sup-msg-images">
                        <?php foreach ($m['IMAGES'] as $img): ?>
                            <a href="<?= htmlspecialcharsbx($img['URL']) ?>" target="_blank" rel="noopener">
                                <img src="<?= htmlspecialcharsbx($img['THUMB']) ?>" alt="">
                            </a>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>
            </div>
        <?php endforeach ?>
    </div>
</div>

<?php if ($ticket['STATUS'] !== SupportBuilder::ST_CLOSED): ?>
    <div class="x5sup-box x5sup-form">
        <h3>Ответить</h3>
        <form method="POST">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?= (int)$ticketId ?>">
            <textarea name="reply_text" placeholder="Текст ответа..." required></textarea>
            <div style="margin-top:10px">
                <button type="submit" class="adm-btn adm-btn-save">Отправить ответ</button>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="x5sup-box" style="color:#666">
        Обращение закрыто. Чтобы ответить, сначала переоткройте — просто отправьте ответ ниже.
    </div>
    <div class="x5sup-box x5sup-form">
        <h3>Ответить (переоткроет обращение)</h3>
        <form method="POST">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="id" value="<?= (int)$ticketId ?>">
            <textarea name="reply_text" placeholder="Текст ответа..." required></textarea>
            <div style="margin-top:10px">
                <button type="submit" class="adm-btn adm-btn-save">Отправить ответ</button>
            </div>
        </form>
    </div>
<?php endif ?>

<div class="x5sup-box">
    <h3>Действия</h3>
    <?php if ($ticket['STATUS'] !== SupportBuilder::ST_CLOSED): ?>
        <form method="POST" style="display:inline-block;margin-right:8px"
              onsubmit="return confirm('Закрыть обращение? Пользователь не сможет больше отвечать.')">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="id" value="<?= (int)$ticketId ?>">
            <button type="submit" class="adm-btn">Закрыть обращение</button>
        </form>
    <?php endif ?>
    <form method="POST" style="display:inline-block"
          onsubmit="return confirm('Удалить обращение вместе с сообщениями и файлами? Необратимо.')">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$ticketId ?>">
        <button type="submit" class="adm-btn adm-btn-delete">Удалить полностью</button>
    </form>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>