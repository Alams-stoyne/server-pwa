<?php
/**
 * Отправка PUSH: одному пользователю или всем.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId   = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Отправить PUSH');

$message = null;
$error   = null;

$presetUid = trim((string)($_GET['uid'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $mode  = (string)($_POST['mode'] ?? 'one');
    $uid   = trim((string)($_POST['uid'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $body  = trim((string)($_POST['body'] ?? ''));
    $url   = trim((string)($_POST['url'] ?? '/'));
    $tag   = trim((string)($_POST['tag'] ?? 'admin-push'));

    if ($title === '' || $body === '') {
        $error = 'Заполните заголовок и текст.';
    } elseif ($mode === 'one') {
        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            $error = 'Неверный UID.';
        } else {
            $sub = \X5\Pwa\Storage::getSubscription($uid);
            if (!$sub) {
                $error = 'У пользователя нет активной push-подписки.';
            } else {
                $payload = [
                    'title' => $title,
                    'body'  => $body,
                    'icon'  => '/icons/icon-192.png',
                    'badge' => '/icons/icon-192.png',
                    'url'   => $url !== '' ? $url : '/',
                    'tag'   => $tag !== '' ? $tag : 'admin-push',
                ];
                $r = \X5\Pwa\Push::send($uid, $sub, $payload);
                \X5\Pwa\Storage::addPushHistory([
                    'type' => 'send', 'target' => $uid,
                    'title' => $title, 'body' => $body,
                    'url' => $payload['url'],
                    'status' => (string)$r['statusCode'],
                ]);
                if ($r['ok']) {
                    $message = 'PUSH отправлен.';
                } else {
                    $error = 'Ошибка отправки: ' . ($r['message'] ?? 'unknown');
                    if (!empty($r['gone'])) {
                        \X5\Pwa\Storage::deleteSubscription($uid);
                        $error .= ' Подписка удалена (устройство отписано).';
                    }
                }
            }
        }
    } else { // broadcast
        $payload = [
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => $url !== '' ? $url : '/',
            'tag'   => $tag !== '' ? $tag : 'admin-broadcast',
        ];
        $r = \X5\Pwa\Push::broadcast($payload);
        \X5\Pwa\Storage::addPushHistory([
            'type' => 'broadcast', 'target' => '*',
            'title' => $title, 'body' => $body,
            'url' => $payload['url'],
            'status' => "sent:{$r['sent']},failed:{$r['failed']}",
        ]);
        $message = sprintf(
            'Рассылка: успешно %d, ошибок %d, удалено подписок %d.',
            $r['sent'], $r['failed'], $r['removed']
        );
        if (!empty($r['errors'])) {
            $error = 'Ошибки: ' . implode('; ', array_slice($r['errors'], 0, 3));
        }
    }
}

$history = \X5\Pwa\Storage::getPushHistory(50);
$subsCount = count(\X5\Pwa\Storage::listSubscriptions());
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-box { background: #fff; padding: 16px; margin-bottom: 16px; border: 1px solid #e2e5e9; }
    .x5pwa-box h3 { margin: 0 0 12px 0; font-size: 14px; }
    .x5pwa-form-row { display: grid; grid-template-columns: 140px 1fr; gap: 12px; align-items: center; margin-bottom: 12px; }
    .x5pwa-form-row input[type=text], .x5pwa-form-row textarea { width: 100%; padding: 6px 8px; }
    .x5pwa-form-row label { color: #555; }
    .x5pwa-history td { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 12px; }
    .x5pwa-history { width: 100%; border-collapse: collapse; background: #fff; }
</style>

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

<div class="adm-info-message-wrap adm-info-message-blue">
    <div class="adm-info-message">
        Всего активных подписок: <b><?= $subsCount ?></b>
    </div>
</div>

<form method="post" action="x5_pwa_push.php?lang=<?= LANGUAGE_ID ?>" class="x5pwa-box">
    <?= bitrix_sessid_post() ?>

    <h3>Получатель</h3>

    <div class="x5pwa-form-row">
        <label>Кому</label>
        <div>
            <label style="margin-right:16px">
                <input type="radio" name="mode" value="one" <?= $presetUid !== '' ? 'checked' : 'checked' ?>
                       onchange="document.getElementById('x5pwa-uid-row').style.display='grid'">
                Одному пользователю
            </label>
            <label>
                <input type="radio" name="mode" value="all"
                       onchange="document.getElementById('x5pwa-uid-row').style.display='none'">
                Всем подписчикам
            </label>
        </div>
    </div>

    <div class="x5pwa-form-row" id="x5pwa-uid-row">
        <label>UID</label>
        <input type="text" name="uid" value="<?= htmlspecialcharsbx($presetUid) ?>"
               placeholder="40 hex-символов" pattern="[a-fA-F0-9]{40}">
    </div>

    <h3 style="margin-top:20px">Сообщение</h3>

    <div class="x5pwa-form-row">
        <label>Заголовок</label>
        <input type="text" name="title" maxlength="100" required
               value="<?= htmlspecialcharsbx((string)($_POST['title'] ?? '')) ?>">
    </div>

    <div class="x5pwa-form-row">
        <label>Текст</label>
        <textarea name="body" rows="3" maxlength="300" required><?= htmlspecialcharsbx((string)($_POST['body'] ?? '')) ?></textarea>
    </div>

    <div class="x5pwa-form-row">
        <label>URL (при клике)</label>
        <input type="text" name="url" value="<?= htmlspecialcharsbx((string)($_POST['url'] ?? '/')) ?>">
    </div>

    <div class="x5pwa-form-row">
        <label>Tag</label>
        <input type="text" name="tag" value="<?= htmlspecialcharsbx((string)($_POST['tag'] ?? 'admin-push')) ?>">
    </div>

    <div style="margin-top:16px">
        <input type="submit" value="Отправить" class="adm-btn adm-btn-save"
               onclick="return confirm('Отправить PUSH?')">
    </div>
</form>

<div class="x5pwa-box">
    <h3>История последних отправок</h3>

    <?php if (!$history): ?>
        <div style="color:#888">Ещё не отправляли.</div>
    <?php else: ?>
        <table class="x5pwa-history">
            <thead>
            <tr>
                <th>Дата</th>
                <th>Тип</th>
                <th>Кому</th>
                <th>Заголовок</th>
                <th>Статус</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td>
                        <?php
                        $dt = $h['UF_CREATED_AT'] ?? null;
                        echo $dt ? htmlspecialcharsbx($dt->toString()) : '—';
                        ?>
                    </td>
                    <td><?= htmlspecialcharsbx((string)$h['UF_TYPE']) ?></td>
                    <td class="x5pwa-mono">
                        <?= htmlspecialcharsbx(substr((string)$h['UF_TARGET'], 0, 14)) ?>…
                    </td>
                    <td><?= htmlspecialcharsbx((string)$h['UF_TITLE']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$h['UF_STATUS']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>