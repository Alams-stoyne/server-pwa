<?php
/**
 * PWA — Детали тикета поддержки.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\SupportBuilder;
use X5\Pwa\Storage;

$moduleId = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$ticketId = (int)($_REQUEST['ID'] ?? 0);
if ($ticketId <= 0) {
    LocalRedirect('x5_pwa_support.php?lang=' . LANGUAGE_ID);
}

$ticketData = SupportBuilder::getTicket($ticketId);
if (!$ticketData) {
    LocalRedirect('x5_pwa_support.php?lang=' . LANGUAGE_ID);
}

$ticket   = $ticketData['TICKET'];
$messages = $ticketData['MESSAGES'];

// ─── Обработка действий ───
$isReset   = ($ticket['AUTH_ERROR'] === 'RESET_UID_REQUEST');
$uid       = (string)$ticket['UID'];
$deviceId  = (string)$ticket['DEVICE_ID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    global $USER;
    $adminId = (int)$USER->GetID();

    if (isset($_POST['do_reset_uid']) && $isReset) {
        // Проверяем, что привязка всё ещё актуальна
        $binding = Storage::getUidBinding($uid);
        $isActual = ($binding && (string)$binding['UF_DEVICE_ID'] === $deviceId);

        if (!$isActual) {
            SupportBuilder::addAdminMessage($ticketId, $adminId, 'Сброс невозможен: пользователь уже привязан к другому устройству.');
            SupportBuilder::closeTicket($ticketId, $adminId);
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
        }

        $success = Storage::clearDeviceBinding($uid, $deviceId);
        if ($success) {
            SupportBuilder::addAdminMessage($ticketId, $adminId, 'Привязка UID к устройству сброшена администратором. Пользователь может войти заново.');
            SupportBuilder::closeTicket($ticketId, $adminId);
            
            \Bitrix\Main\Diag\Debug::writeToFile(
                "Admin reset UID: ticket=#{$ticketId} uid=" . substr($uid, 0, 12) . "… device=" . substr($deviceId, 0, 8) . "… admin={$adminId}",
                'admin-reset-uid',
                '/x5.pwa.log'
            );
        } else {
            SupportBuilder::addAdminMessage($ticketId, $adminId, 'Ошибка при сбросе привязки. Проверьте логи.');
        }
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
    }

    if (isset($_POST['do_reject_reset']) && $isReset) {
        SupportBuilder::addAdminMessage($ticketId, $adminId, 'Запрос на сброс отклонен. Привязка UID к устройству остается в силе.');
        SupportBuilder::closeTicket($ticketId, $adminId);
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
    }

    if (isset($_POST['do_close'])) {
        SupportBuilder::closeTicket($ticketId, $adminId);
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
    }

    if (isset($_POST['do_reply']) && !empty($_POST['reply_text'])) {
        $text = trim((string)$_POST['reply_text']);
        if ($text !== '') {
            SupportBuilder::addAdminMessage($ticketId, $adminId, $text);
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
        }
    }
}

$APPLICATION->SetTitle('Тикет #' . $ticketId . ' — ' . $ticket['SUBJECT']);

$stLabels = [
    'new'      => 'Новый',
    'answered' => 'Отвечен',
    'closed'   => 'Закрыт',
];

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<div style="margin-bottom:12px">
    <a href="x5_pwa_support.php?lang=<?= LANGUAGE_ID ?>">← Ко всем тикетам</a>
</div>

<?php if ($isReset): ?>
    <?php
    // Проверяем актуальность привязки
    $binding = Storage::getUidBinding($uid);
    $isActual = ($binding && (string)$binding['UF_DEVICE_ID'] === $deviceId);
    $meta = json_decode($ticket['META'] ?? '{}', true) ?: [];
    $reasons = $meta['RESET_REASONS'] ?? [];
    ?>
    <div class="adm-info-message-wrap adm-info-message-yellow" style="border-left: 4px solid #d9a000;">
        <div class="adm-info-message" style="padding: 16px;">
            <h3 style="margin-top:0; color:#8a6b00;">⚠ Запрос на сброс привязки UID ↔ DEVICE_ID</h3>
            <table class="internal" style="width:100%; margin-bottom:16px;">
                <tr><td width="200"><b>UID:</b></td><td><code><?= htmlspecialcharsbx($uid) ?></code></td></tr>
                <tr><td><b>Device ID:</b></td><td><code><?= htmlspecialcharsbx($deviceId) ?></code></td></tr>
                <tr><td><b>Причины:</b></td><td><?= htmlspecialcharsbx(implode(', ', $reasons)) ?></td></tr>
            </table>
            
            <?php if (!$isActual): ?>
                <div style="color:#a52020; font-weight:600; margin-bottom:12px;">
                    Запрос устарел. В данный момент UID привязан к другому устройству (или не привязан).
                </div>
                <form method="POST" style="margin:0;">
                    <?= bitrix_sessid_post() ?>
                    <input type="submit" name="do_reject_reset" value="Закрыть тикет" class="adm-btn">
                </form>
            <?php else: ?>
                <div style="margin-bottom:12px; color:#555;">
                    Привязка актуальна. Вы можете сбросить привязку, и пользователь сможет привязать этот же UID к новому устройству.
                </div>
                <form method="POST" style="margin:0; display:flex; gap:12px;">
                    <?= bitrix_sessid_post() ?>
                    <input type="submit" name="do_reset_uid" value="Сбросить привязку UID" class="adm-btn adm-btn-save" 
                           onclick="return confirm('Сбросить привязку? Пользователь сможет войти с текущего устройства.')">
                    <input type="submit" name="do_reject_reset" value="Отклонить запрос" class="adm-btn"
                           onclick="return confirm('Отклонить запрос?')">
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: 1fr 300px; gap:20px;">
    <div>
        <!-- Сообщения -->
        <div class="adm-info-message-wrap" style="background:#fff; border:1px solid #e2e5e9; padding:20px;">
            <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">Сообщения</h3>
            
            <?php foreach ($messages as $msg): ?>
                <div style="margin-bottom:20px; padding:12px; border-radius:6px; background:<?= $msg['IS_ADMIN'] ? '#e8f4ff' : '#f5f6f8' ?>;">
                    <div style="font-size:11px; color:#888; margin-bottom:6px;">
                        <?= $msg['IS_ADMIN'] ? 'Администратор' : 'Пользователь' ?> 
                        · <?= $msg['CREATED_AT'] ? date('d.m.Y H:i:s', strtotime($msg['CREATED_AT'])) : '' ?>
                    </div>
                    <div style="white-space:pre-wrap;"><?= htmlspecialcharsbx($msg['TEXT']) ?></div>
                    <?php if (!empty($msg['IMAGES'])): ?>
                        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($msg['IMAGES'] as $img): ?>
                                <a href="<?= htmlspecialcharsbx($img['URL']) ?>" target="_blank">
                                    <img src="<?= htmlspecialcharsbx($img['THUMB']) ?>" style="max-width:100px; max-height:100px; border-radius:4px;">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($ticket['STATUS'] !== 'closed'): ?>
                <form method="POST" style="margin-top:24px; border-top:1px solid #eee; padding-top:16px;">
                    <?= bitrix_sessid_post() ?>
                    <div style="margin-bottom:8px; font-weight:600;">Ответить:</div>
                    <textarea name="reply_text" rows="4" style="width:100%; padding:8px; border:1px solid #c5c9cf; border-radius:3px; font-family:inherit;" placeholder="Введите текст ответа..."></textarea>
                    <div style="margin-top:8px;">
                        <input type="submit" name="do_reply" value="Отправить ответ" class="adm-btn adm-btn-save">
                        <input type="submit" name="do_close" value="Закрыть тикет без ответа" class="adm-btn" onclick="return confirm('Закрыть тикет?')">
                    </div>
                </form>
            <?php else: ?>
                <div style="margin-top:24px; color:#888; font-style:italic;">
                    Тикет закрыт. Новые сообщения отправлять нельзя.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Сайдбар -->
    <div>
        <div class="adm-info-message-wrap" style="background:#fff; border:1px solid #e2e5e9; padding:16px;">
            <h4 style="margin-top:0;">Информация</h4>
            <table class="internal" style="width:100%;">
                <tr><td style="color:#666;">Статус:</td><td><b><?= $stLabels[$ticket['STATUS']] ?? $ticket['STATUS'] ?></b></td></tr>
                <tr><td style="color:#666;">Источник:</td><td><?= $ticket['SOURCE'] === 'app_unauth' ? 'Гость' : 'Авторизован' ?></td></tr>
                <tr><td style="color:#666;">Создан:</td><td><?= date('d.m.Y H:i', strtotime($ticket['CREATED_AT'])) ?></td></tr>
                <tr><td style="color:#666;">Обновлён:</td><td><?= date('d.m.Y H:i', strtotime($ticket['UPDATED_AT'])) ?></td></tr>
                <?php if ($ticket['UID']): ?>
                    <tr><td style="color:#666;">UID:</td><td><code style="font-size:11px; word-break:break-all;"><?= htmlspecialcharsbx($ticket['UID']) ?></a></td></tr>
                    <tr><td colspan="2"><a href="x5_pwa_user_detail.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($ticket['UID']) ?>" class="adm-btn" style="width:100%; text-align:center; margin-top:8px;">Профиль пользователя</a></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>