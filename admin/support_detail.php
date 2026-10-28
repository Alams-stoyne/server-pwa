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

$isReset   = ($ticket['AUTH_ERROR'] === 'RESET_UID_REQUEST');
$uid       = (string)$ticket['UID'];
$deviceId  = (string)$ticket['DEVICE_ID']; // запрашивающее устройство

// Текущая привязка в базе
$binding        = $uid !== '' ? Storage::getUidBinding($uid) : null;
$currentBoundId = $binding ? (string)($binding['UF_DEVICE_ID'] ?? '') : '';

// ─── Логика «запрос актуален»:
//     UID существует и привязан К ДРУГОМУ устройству (не к текущему и не пустой)
$isActual = ($currentBoundId !== '' && $currentBoundId !== $deviceId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    global $USER;
    $adminId = (int)$USER->GetID();

    if (isset($_POST['do_reset_uid']) && $isReset) {
        if (!$isActual) {
            SupportBuilder::addAdminMessage(
                $ticketId, $adminId,
                'Сброс не выполнен: UID уже ' . ($currentBoundId === '' ? 'не привязан ни к одному устройству' : 'привязан к запрашивающему устройству') . '.'
            );
            SupportBuilder::closeTicket($ticketId, $adminId);
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
        }

        $success = Storage::clearDeviceBinding($uid, $currentBoundId);
        if ($success) {
            SupportBuilder::addAdminMessage(
                $ticketId, $adminId,
                'Привязка UID к устройству ' . $currentBoundId . ' сброшена администратором. Пользователь может войти заново с любого устройства.'
            );
            SupportBuilder::closeTicket($ticketId, $adminId);

            \Bitrix\Main\Diag\Debug::writeToFile(
                "Admin reset UID: ticket=#{$ticketId} uid=" . substr($uid, 0, 12)
                . "… bound=" . substr($currentBoundId, 0, 8)
                . "… requested=" . substr($deviceId, 0, 8)
                . "… admin={$adminId}",
                'admin-reset-uid',
                '/x5.pwa.log'
            );
        } else {
            SupportBuilder::addAdminMessage(
                $ticketId, $adminId,
                'Ошибка при сбросе привязки. Возможно, пользователь уже перепривязался — обновите карточку.'
            );
        }
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&ID=' . $ticketId);
    }

    if (isset($_POST['do_reject_reset']) && $isReset) {
        SupportBuilder::addAdminMessage($ticketId, $adminId, 'Запрос на сброс привязки отклонен администратором.');
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
    'new'      => ['Новый',     '#fdf3cf', '#8a6b00'],
    'answered' => ['Отвечен',   '#e6f5d8', '#3a7d1c'],
    'closed'   => ['Закрыт',    '#f0f2f5', '#666'],
];
$st = $stLabels[$ticket['STATUS']] ?? ['—', '#f0f2f5', '#666'];

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<style>
    .x5pwa-box { background:#fff; padding:20px; margin-bottom:16px; border:1px solid #e2e5e9; border-radius:6px; }
    .x5pwa-box h3 { margin:0 0 16px 0; font-size:15px; }
    .x5pwa-subtitle { font-size:13px; color:#666; margin:4px 0 16px 0; font-weight:400; }
    .x5pwa-mono { font-family:monospace; font-size:12px; word-break:break-all; }
    .x5pwa-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:11px; font-weight:500; }
    .x5pwa-grid-2 { display:grid; grid-template-columns: 1fr 360px; gap:20px; align-items:start; }
    @media (max-width:1100px) { .x5pwa-grid-2 { grid-template-columns:1fr; } }

    /* Reset UID block */
    .x5pwa-reset-block { border-left:4px solid #d9a000; background:#fffdf5; }
    .x5pwa-reset-block.actual { border-left-color:#c0392b; background:#fff5f5; }
    .x5pwa-reset-block.ok { border-left-color:#3a7d1c; background:#f5fff8; }
    .x5pwa-reset-block h3 { color:#8a6b00; margin-bottom:12px; }
    .x5pwa-reset-block.actual h3 { color:#a52020; }
    .x5pwa-reset-block.ok h3 { color:#3a7d1c; }
    .x5pwa-reset-kv { width:100%; border-collapse:collapse; margin-bottom:16px; }
    .x5pwa-reset-kv td { padding:6px 0; vertical-align:top; }
    .x5pwa-reset-kv td:first-child { width:240px; color:#666; font-weight:500; }

    /* Messages chat */
    .x5pwa-chat { display:flex; flex-direction:column; gap:14px; max-height:600px; overflow-y:auto; padding-right:4px; margin-bottom:20px; }
    .x5pwa-msg { max-width:80%; padding:12px 14px; border-radius:10px; font-size:13px; line-height:1.5; position:relative; }
    .x5pwa-msg.user  { background:#f5f6f8; border:1px solid #e2e5e9; align-self:flex-start; border-top-left-radius:2px; }
    .x5pwa-msg.admin { background:#e8f4ff; border:1px solid #c9e1fc; align-self:flex-end; border-top-right-radius:2px; }
    .x5pwa-msg-head { font-size:11px; color:#888; margin-bottom:6px; font-weight:500; }
    .x5pwa-msg.admin .x5pwa-msg-head { text-align:right; }
    .x5pwa-msg-text { white-space:pre-wrap; }
    .x5pwa-msg-images { margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
    .x5pwa-msg-images img { max-width:120px; max-height:120px; border-radius:4px; border:1px solid #d0d4d9; }

    /* Sidebar */
    .x5pwa-sidebar-kv { display:grid; grid-template-columns: 120px 1fr; gap:8px 12px; font-size:13px; }
    .x5pwa-sidebar-kv dt { color:#666; }
    .x5pwa-sidebar-kv dd { margin:0; font-weight:500; }
    .x5pwa-sidebar-section { padding-top:14px; margin-top:14px; border-top:1px solid #eee; }
</style>

<div style="margin-bottom:16px">
    <a href="x5_pwa_support.php?lang=<?= LANGUAGE_ID ?>" style="font-size:13px;">← Ко всем тикетам</a>
</div>

<?php if ($isReset): ?>
    <?php
    $meta = json_decode($ticket['META'] ?? '{}', true) ?: [];
    $reasons = $meta['RESET_REASONS'] ?? [];

    if ($currentBoundId === '') {
        $statusText  = 'UID уже ни к чему не привязан. Сброс не требуется.';
        $statusClass = 'ok';
    } elseif ($currentBoundId === $deviceId) {
        $statusText  = 'Пользователь уже привязал этот UID к запрашивающему устройству. Сброс не требуется.';
        $statusClass = 'ok';
    } else {
        $statusText  = 'UID привязан к другому устройству — требуется сброс.';
        $statusClass = 'actual';
    }
    ?>
    <div class="x5pwa-box x5pwa-reset-block <?= $statusClass ?>">
        <h3>⚠ Запрос на сброс привязки UID ↔ DEVICE_ID</h3>

        <table class="x5pwa-reset-kv">
            <tr>
                <td>UID:</td>
                <td class="x5pwa-mono"><?= htmlspecialcharsbx($uid) ?></td>
            </tr>
            <tr>
                <td>Запрашивающее устройство:</td>
                <td class="x5pwa-mono"><?= htmlspecialcharsbx($deviceId) ?></td>
            </tr>
            <tr>
                <td>Текущая привязка в базе:</td>
                <td class="x5pwa-mono">
                    <?php if ($currentBoundId === ''): ?>
                        <span style="color:#888">— (нет)</span>
                    <?php elseif ($currentBoundId === $deviceId): ?>
                        <?= htmlspecialcharsbx($currentBoundId) ?>
                        <span style="color:#3a7d1c"> ← совпадает с запрашивающим</span>
                    <?php else: ?>
                        <?= htmlspecialcharsbx($currentBoundId) ?>
                    <?php endif; ?>
                </td>
            </tr>
            
            <!-- НОВЫЙ БЛОК: время активности старого устройства -->
            <?php if (!empty($meta['BOUND_DEVICE_LAST_SEEN'])): ?>
                <?php
                $lastSeenTs = strtotime($meta['BOUND_DEVICE_LAST_SEEN']);
                $isRecent = ($lastSeenTs > 0 && (time() - $lastSeenTs) < 3600);
                ?>
                <tr>
                    <td>Последняя активность старого уст.:</td>
                    <td>
                        <?= htmlspecialcharsbx((string)$meta['BOUND_DEVICE_LAST_SEEN']) ?>
                        <?php if ($isRecent): ?>
                            <span class="x5pwa-badge" style="background:#f5e0e0;color:#a52020;margin-left:8px;">Менее часа назад</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

            <?php if (!empty($meta['ORIGINAL_AUTH_ERROR'])): ?>
                <tr>
                    <td>Исходная ошибка входа:</td>
                    <td><span class="x5pwa-badge" style="background:#f5e0e0;color:#a52020"><?= htmlspecialcharsbx((string)$meta['ORIGINAL_AUTH_ERROR']) ?></span></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($meta['PLATFORM'])): ?>
                <tr>
                    <td>User-Agent:</td>
                    <td style="font-size:11px;color:#666;word-break:break-all;"><?= htmlspecialcharsbx((string)$meta['PLATFORM']) ?></td>
                </tr>
            <?php endif; ?>
            <?php if (!empty($meta['TIMESTAMP_LOCAL'])): ?>
                <tr>
                    <td>Время запроса (локальное):</td>
                    <td><?= htmlspecialcharsbx((string)$meta['TIMESTAMP_LOCAL']) ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td>Указанные причины:</td>
                <td>
                    <?php if (!empty($reasons)): ?>
                        <?php foreach ($reasons as $reason): ?>
                            <span class="x5pwa-badge" style="background:#fdf3cf;color:#8a6b00"><?= htmlspecialcharsbx((string)$reason) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color:#888">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div style="font-weight:600;margin-bottom:14px;">
            <?= htmlspecialcharsbx($statusText) ?>
        </div>

        <form method="POST" style="margin:0;display:flex;gap:10px;flex-wrap:wrap;">
            <?= bitrix_sessid_post() ?>
            <?php if ($isActual): ?>
                <input type="submit" name="do_reset_uid" value="Сбросить привязку UID" class="adm-btn adm-btn-save"
                       onclick="return confirm('Сбросить привязку? Пользователь сможет войти с любого устройства.')">
            <?php endif; ?>
            <input type="submit" name="do_reject_reset" value="<?= $isActual ? 'Отклонить запрос' : 'Закрыть тикет' ?>" class="adm-btn"
                   onclick="return confirm('<?= $isActual ? 'Отклонить запрос?' : 'Закрыть тикет?' ?>')">
        </form>
    </div>
<?php endif; ?>

<div class="x5pwa-grid-2">
    <!-- Левая колонка: Переписка -->
    <div class="x5pwa-box">
        <h3>Переписка <span style="color:#888;font-weight:400;font-size:13px">(<?= count($messages) ?>)</span></h3>

        <?php if (empty($messages)): ?>
            <p style="color:#888;padding:20px 0;text-align:center;">Сообщений нет.</p>
        <?php else: ?>
            <div class="x5pwa-chat">
                <?php foreach ($messages as $msg): ?>
                    <div class="x5pwa-msg <?= $msg['IS_ADMIN'] ? 'admin' : 'user' ?>">
                        <div class="x5pwa-msg-head">
                            <?php if ($msg['IS_ADMIN']): ?>
                                👤 Администратор ·
                            <?php else: ?>
                                <?= $ticket['SOURCE'] === 'app_unauth' ? 'Гость' : 'Пользователь' ?> ·
                            <?php endif; ?>
                            <?= $msg['CREATED_AT'] ? date('d.m.Y H:i:s', strtotime($msg['CREATED_AT'])) : '' ?>
                        </div>
                        <div class="x5pwa-msg-text"><?= htmlspecialcharsbx($msg['TEXT']) ?></div>
                        <?php if (!empty($msg['IMAGES'])): ?>
                            <div class="x5pwa-msg-images">
                                <?php foreach ($msg['IMAGES'] as $img): ?>
                                    <a href="<?= htmlspecialcharsbx($img['URL']) ?>" target="_blank">
                                        <img src="<?= htmlspecialcharsbx($img['THUMB']) ?>" alt="">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($ticket['STATUS'] !== 'closed'): ?>
            <form method="POST" style="border-top:1px solid #eee;padding-top:16px;">
                <?= bitrix_sessid_post() ?>
                <div style="font-weight:600;margin-bottom:8px;">Ответить пользователю</div>
                <textarea name="reply_text" rows="4"
                          style="width:100%;padding:10px;border:1px solid #c5c9cf;border-radius:4px;font-family:inherit;font-size:13px;box-sizing:border-box;"
                          placeholder="Введите текст ответа..."></textarea>
                <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                    <input type="submit" name="do_reply" value="Отправить ответ" class="adm-btn adm-btn-save">
                    <input type="submit" name="do_close" value="Закрыть без ответа" class="adm-btn"
                           onclick="return confirm('Закрыть тикет без ответа?')">
                </div>
            </form>
        <?php else: ?>
            <div style="margin-top:20px;padding:14px;background:#f5f6f8;border-radius:4px;color:#666;font-size:13px;text-align:center;">
                🔒 Тикет закрыт. Новые сообщения отправлять нельзя.
            </div>
        <?php endif; ?>
    </div>

    <!-- Правая колонка: Сайдбар -->
    <div class="x5pwa-box">
        <h3>Информация</h3>

        <dl class="x5pwa-sidebar-kv">
            <dt>ID:</dt>
            <dd><b>#<?= $ticketId ?></b></dd>

            <dt>Статус:</dt>
            <dd>
                <span class="x5pwa-badge" style="background:<?= $st[1] ?>;color:<?= $st[2] ?>">
                    <?= htmlspecialcharsbx($st[0]) ?>
                </span>
            </dd>

            <dt>Источник:</dt>
            <dd><?= $ticket['SOURCE'] === 'app_unauth' ? 'Гость (без входа)' : 'Авторизован' ?></dd>

            <dt>Создан:</dt>
            <dd><?= $ticket['CREATED_AT'] ? date('d.m.Y H:i', strtotime($ticket['CREATED_AT'])) : '—' ?></dd>

            <dt>Обновлён:</dt>
            <dd><?= $ticket['UPDATED_AT'] ? date('d.m.Y H:i', strtotime($ticket['UPDATED_AT'])) : '—' ?></dd>

            <?php if (!empty($ticket['CLOSED_AT'])): ?>
                <dt>Закрыт:</dt>
                <dd><?= date('d.m.Y H:i', strtotime($ticket['CLOSED_AT'])) ?></dd>
            <?php endif; ?>
        </dl>

        <?php if ($ticket['UID']): ?>
            <div class="x5pwa-sidebar-section">
                <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:6px;">UID</div>
                <div class="x5pwa-mono" style="font-size:11px;"><?= htmlspecialcharsbx($ticket['UID']) ?></div>
                <a href="x5_pwa_user_detail.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($ticket['UID']) ?>"
                   class="adm-btn" style="display:block;text-align:center;margin-top:10px;">
                    Открыть профиль пользователя →
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($ticket['DEVICE_ID'])): ?>
            <div class="x5pwa-sidebar-section">
                <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:6px;">DEVICE_ID (запроса)</div>
                <div class="x5pwa-mono" style="font-size:11px;"><?= htmlspecialcharsbx($ticket['DEVICE_ID']) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($ticket['IP']) || !empty($ticket['UA'])): ?>
            <div class="x5pwa-sidebar-section">
                <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:6px;">Технические данные</div>
                <?php if (!empty($ticket['IP'])): ?>
                    <div style="font-size:12px;margin-bottom:4px;"><span style="color:#666">IP:</span> <span class="x5pwa-mono"><?= htmlspecialcharsbx($ticket['IP']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($ticket['UA'])): ?>
                    <div style="font-size:11px;color:#666;word-break:break-all;"><?= htmlspecialcharsbx($ticket['UA']) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($meta)): ?>
            <div class="x5pwa-sidebar-section">
                <div style="font-size:11px;color:#888;text-transform:uppercase;margin-bottom:6px;">META</div>
                <pre style="font-size:10px;background:#f5f6f8;padding:8px;border-radius:4px;overflow-x:auto;margin:0;"><?= htmlspecialcharsbx(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>