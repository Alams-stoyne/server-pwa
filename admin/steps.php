<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Шагомер');

$sTableID = 'x5_pwa_steps';
$oSort    = new CAdminUiSorting($sTableID, 'UF_SENT_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

$stalePendingCount = 0;
try {
    $conn = \Bitrix\Main\Application::getConnection();
    $row = $conn->query(
        "SELECT COUNT(*) AS C FROM x5_pwa_steps WHERE UF_STATUS = 'N' AND UF_LOCAL_DATE < CURDATE()"
    )->fetch();
    $stalePendingCount = (int)($row['C'] ?? 0);
} catch (\Throwable $e) {}

$recalcMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid() && !empty($_POST['x5pwa_recalc_totals'])) {
    try {
        $r = \X5\Pwa\StepsBuilder::recalcAllTotals();
        $recalcMessage = sprintf('Totals пересчитаны для %d пользователей за %s сек.',
            (int)($r['processed'] ?? 0), (float)($r['elapsed'] ?? 0));
    } catch (\Throwable $e) {
        $recalcMessage = 'Ошибка пересчёта: ' . $e->getMessage();
    }
}

$filterFields = [
    [
        'id'          => 'SEARCH',
        'name'        => 'Поиск',
        'type'        => 'string',
        'filterable'  => '?',
        'quickSearch' => '?',
        'default'     => true,
    ],
    ['id' => 'LOCAL_DATE', 'name' => 'Дата (локальная)', 'type' => 'date', 'filterable' => '', 'default' => true],
    ['id' => 'STATUS',     'name' => 'Статус', 'type' => 'list', 'items' => [
        '' => '— любой —', 'N' => 'На модерации', 'Y' => 'Одобрено', 'R' => 'Отклонено',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'UID',        'name' => 'UID', 'type' => 'string', 'filterable' => '?', 'quickSearch' => '?', 'default' => true],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

// ─── Чтение значений фильтра из $arFilter (как в users.php) ───
if (!function_exists('x5pwaFilterValue')) {
    function x5pwaFilterValue(array $arFilter, string $field): string
    {
        $variants = [
            $field,
            '?' . $field,
            '~' . $field,
            '~?' . $field,
            '?' . '~' . $field,
            'find_' . $field,
            'find_?' . $field,
            'find_~' . $field,
        ];
        foreach ($variants as $key) {
            if (isset($arFilter[$key])) {
                $v = $arFilter[$key];
                if (is_array($v)) $v = reset($v);
                $v = trim((string)$v);
                if ($v !== '') return $v;
            }
        }
        foreach ($variants as $key) {
            if (isset($_GET[$key])) {
                $v = $_GET[$key];
                if (is_array($v)) $v = reset($v);
                $v = trim((string)$v);
                if ($v !== '') return $v;
            }
        }
        return '';
    }
}

$search      = x5pwaFilterValue($arFilter, 'SEARCH');
$uidFilter = x5pwaFilterValue($arFilter, 'UID');
if ($uidFilter !== '' && !preg_match('/^[a-f0-9]{40}$/i', $uidFilter)) {
    $uidFilter = '';
}
// Fallback: прямой параметр из user_detail.php
if ($uidFilter === '' && !empty($_REQUEST['x5uid'])) {
    $uidFilter = trim((string)$_REQUEST['x5uid']);
    if (!preg_match('/^[a-f0-9]{40}$/i', $uidFilter)) {
        $uidFilter = '';
    }
}
// Fallback: find_UID напрямую из URL (при переходах по ссылкам)
if ($uidFilter === '' && !empty($_REQUEST['find_UID'])) {
    $candidate = trim((string)$_REQUEST['find_UID']);
    if (preg_match('/^[a-f0-9]{40}$/i', $candidate)) {
        $uidFilter = $candidate;
    }
}

$status = x5pwaFilterValue($arFilter, 'STATUS');
if (!in_array($status, ['N', 'Y', 'R'], true)) $status = '';

$dateFrom = x5pwaFilterValue($arFilter, 'LOCAL_DATE_from');
$dateTo   = x5pwaFilterValue($arFilter, 'LOCAL_DATE_to');
if ($dateFrom === '') $dateFrom = x5pwaFilterValue($arFilter, '>=LOCAL_DATE');
if ($dateTo   === '') $dateTo   = x5pwaFilterValue($arFilter, '<=LOCAL_DATE');

// ─── Групповые действия ───
if ($arIDs = $lAdmin->GroupAction()) {
    $action = $lAdmin->GetAction();
    if (in_array($action, ['approve', 'reject', 'reset'], true)) {
        if ($lAdmin->IsGroupActionToAll()) {
            $all = \X5\Pwa\StepsBuilder::listForAdmin([
                'search' => $search,
                'uid' => $uidFilter, 
                'status' => $status,
                'date_from' => $dateFrom, 
                'date_to' => $dateTo,
                'noPaging' => true, 
                'perPage' => 0, 
                'sort' => $by, 
                'sortDir' => $order,
            ]);
            $arIDs = array_column($all['rows'], 'ID');
        }
        global $USER;
        $adminId = (int)$USER->GetID();
        $statusMap = ['approve' => 'Y', 'reject' => 'R', 'reset' => 'N'];
        $newStatus = $statusMap[$action];
        $reason = (string)($_POST['reject_reason'] ?? '');
        $processed = \X5\Pwa\StepsBuilder::setStatusBatch($arIDs, $newStatus, $adminId, $reason);
        if ($processed === 0) $lAdmin->AddGroupError('Не удалось обновить ни одной записи');
        else LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
    }
}

// ─── Одиночные действия ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()
    && !empty($_POST['single_id'])
    && in_array($_POST['action'] ?? '', ['approve', 'reject', 'reset'], true)) {
    $id = (int)$_POST['single_id'];
    $action = (string)$_POST['action'];
    if ($id > 0) {
        global $USER;
        $statusMap = ['approve' => 'Y', 'reject' => 'R', 'reset' => 'N'];
        \X5\Pwa\StepsBuilder::setStatus($id, $statusMap[$action], (int)$USER->GetID(),
            (string)($_POST['reject_reason'] ?? ''));
    }
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

$page = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));
$perPageDefault = 50;
$perPage = (int)($_REQUEST['SIZEN_1'] ?? $_REQUEST['SIZEN'] ?? 0);
if ($perPage <= 0) { try { $perPage = (int)$lAdmin->GetNavSize(); } catch (\Throwable $e) { $perPage = 0; } }
if ($perPage <= 0) $perPage = $perPageDefault;
if ($perPage > 500) $perPage = 500;

$data = \X5\Pwa\StepsBuilder::listForAdmin([
    'search'      => $search, 'uid' => $uidFilter, 'status' => $status,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
    'sort' => $by, 'sortDir' => $order, 'page' => $page, 'perPage' => $perPage,
]);

$dbRes = new CDBResult();
$dbRes->InitFromArray(array_values($data['rows']));

$rsData = new CAdminUiResult($dbRes, $sTableID);
$rsData->NavNum = 1; $rsData->NavPageSize = $perPage; $rsData->NavPageNomer = $page;
$rsData->NavPageCount = max(1, (int)$data['pages']); $rsData->NavRecordCount = (int)$data['total'];

$lAdmin->SetNavigationParams($rsData, ['nPageSize' => $perPage]);

$uids = array_values(array_unique(array_column($data['rows'], 'UF_UID')));
$usersInfo = [];
if ($uids) {
    $dc = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');
    $rs = $dc::getList([
        'select' => ['UF_UID', 'UF_FIO', 'UF_NICKNAME'],
        'filter' => ['=UF_UID' => $uids],
    ])->fetchAll();
    foreach ($rs as $u) {
        $usersInfo[$u['UF_UID']] = [
            'FIO' => (string)($u['UF_FIO'] ?? ''),
            'NICKNAME' => (string)($u['UF_NICKNAME'] ?? ''),
        ];
    }
}

$arHeader = [
    ['id' => 'PHOTO',         'content' => 'Фото',        'default' => true, 'width' => 80],
    ['id' => 'UF_LOCAL_DATE', 'content' => 'Дата (лок.)', 'sort' => 'UF_LOCAL_DATE', 'default' => true, 'width' => 110],
    ['id' => 'USER',          'content' => 'Пользователь','default' => true, 'width' => 200],
    ['id' => 'UF_STEPS',      'content' => 'Шаги',        'sort' => 'UF_STEPS', 'default' => true, 'width' => 100],
    ['id' => 'UF_SENT_AT',    'content' => 'Отправлено',  'sort' => 'UF_SENT_AT', 'default' => true, 'width' => 140],
    ['id' => 'UF_STATUS',     'content' => 'Статус',      'sort' => 'UF_STATUS', 'default' => true, 'width' => 130],
    ['id' => 'UF_REJECT_REASON','content' => 'Причина отказа','default' => false],
    ['id' => 'UF_MODERATED_AT','content' => 'Модерация', 'default' => false, 'width' => 140],
    ['id' => 'UF_ADMIN_COMMENT','content' => 'Комментарий', 'default' => false],
    ['id' => 'ID',            'content' => 'ID',          'sort' => 'ID', 'default' => false, 'width' => 60],
];
$lAdmin->AddHeaders($arHeader);
$lAdmin->AddVisibleHeaderColumn('UF_REJECT_REASON');
$lAdmin->AddVisibleHeaderColumn('UF_MODERATED_AT');
$lAdmin->AddVisibleHeaderColumn('UF_ADMIN_COMMENT');
$lAdmin->AddVisibleHeaderColumn('ID');

$statusLabels = [
    'N' => ['На модерации', '#fdf3cf', '#8a6b00'],
    'Y' => ['Одобрено',     '#e6f5d8', '#3a7d1c'],
    'R' => ['Отклонено',    '#f5e0e0', '#a52020'],
];

foreach ($data['rows'] as $r) {
    $id    = (int)$r['ID'];
    $uid   = (string)$r['UF_UID'];
    $steps = (int)($r['UF_STEPS'] ?? 0);
    $date  = (string)($r['UF_LOCAL_DATE'] ?? '');
    $sentAt = $r['UF_SENT_AT'] ? $r['UF_SENT_AT']->format('d.m.Y H:i') : '—';
    $st     = (string)($r['UF_STATUS'] ?? 'N');
    $stInfo = $statusLabels[$st] ?? $statusLabels['N'];
    $moderAt = !empty($r['UF_MODERATED_AT']) ? $r['UF_MODERATED_AT']->format('d.m.Y H:i') : '—';
    $rejectRsn = (string)($r['UF_REJECT_REASON'] ?? '');

    $userInfo = $usersInfo[$uid] ?? null;
    $displayName = '';
    if ($userInfo) {
        if ($userInfo['FIO'] !== '') $displayName = $userInfo['FIO'];
        elseif ($userInfo['NICKNAME'] !== '') $displayName = '@' . $userInfo['NICKNAME'];
    }

    $images = \X5\Pwa\StepsBuilder::expandImages((string)($r['UF_IMAGES'] ?? ''));

    $arRes = [
        'ID' => $id, 'UF_UID' => $uid, 'UF_STEPS' => $steps,
        'UF_LOCAL_DATE' => $date, 'UF_SENT_AT' => $sentAt,
        'UF_STATUS' => $st, 'UF_MODERATED_AT' => $moderAt,
        'UF_REJECT_REASON' => $rejectRsn, 'USER' => $displayName, 'PHOTO' => '',
        'UF_ADMIN_COMMENT' => (string)($r['UF_ADMIN_COMMENT'] ?? ''),
    ];

    $row = $lAdmin->AddRow($id, $arRes);

    if ($images) {
        $first = $images[0];
        $photoHtml = '<a href="' . htmlspecialcharsbx($first['URL']) . '" target="_blank">'
            . '<img src="' . htmlspecialcharsbx($first['THUMB']) . '" '
            . 'style="max-width:64px;max-height:64px;border-radius:4px;display:block"></a>';
        if (count($images) > 1) {
            $photoHtml .= '<div style="font-size:10px;color:#888;margin-top:2px">+' . (count($images) - 1) . ' фото</div>';
        }
        $row->AddViewField('PHOTO', $photoHtml);
    } else {
        $row->AddViewField('PHOTO', '<span style="color:#aaa">—</span>');
    }

    $userHtml = '';
    if ($displayName !== '') $userHtml .= '<b>' . htmlspecialcharsbx($displayName) . '</b><br>';
    $userHtml .= '<span style="font-family:monospace;font-size:11px;color:#888">'
        . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>';
    $row->AddViewField('USER', $userHtml);

    $row->AddViewField('UF_STEPS', '<b>' . number_format($steps, 0, '.', ' ') . '</b>');
    $row->AddViewField('UF_STATUS',
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;'
        . 'background:' . $stInfo[1] . ';color:' . $stInfo[2] . ';font-size:11px">'
        . htmlspecialcharsbx($stInfo[0]) . '</span>');
    $row->AddViewField('UF_REJECT_REASON', $rejectRsn !== '' ? htmlspecialcharsbx($rejectRsn) : '—');

    $adminCmt = (string)($r['UF_ADMIN_COMMENT'] ?? '');
    if ($adminCmt !== '') {
        $short = mb_substr($adminCmt, -220);
        if (mb_strlen($adminCmt) > 220) $short = '…' . $short;
        $row->AddViewField('UF_ADMIN_COMMENT',
            '<span style="font-family:monospace;font-size:11px;white-space:pre-wrap">'
            . htmlspecialcharsbx($short) . '</span>');
    } else {
        $row->AddViewField('UF_ADMIN_COMMENT', '<span style="color:#aaa">—</span>');
    }

    $detailUrl = 'x5_pwa_step_detail.php?lang=' . LANGUAGE_ID . '&ID=' . $id;

    $row->AddViewField('ID', '<a href="' . $detailUrl . '"><b>' . $id . '</b></a>');

    $actions = [
        ['ICON' => 'edit', 'TEXT' => 'Открыть / Редактировать', 'DEFAULT' => true, 'LINK' => $detailUrl],
        ['ICON' => 'view', 'TEXT' => 'Профиль', 'DEFAULT' => false,
         'LINK' => 'x5_pwa_user_detail.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid)],
    ];
    if ($st !== 'Y') $actions[] = ['ICON' => '', 'TEXT' => 'Одобрить', 'ONCLICK' => "x5pwaStepAction($id, 'approve'); return false;"];
    if ($st !== 'R') $actions[] = ['ICON' => '', 'TEXT' => 'Отклонить', 'ONCLICK' => "x5pwaStepRejectPrompt($id); return false;"];
    if ($st !== 'N') $actions[] = ['ICON' => '', 'TEXT' => 'Сбросить', 'ONCLICK' => "x5pwaStepAction($id, 'reset'); return false;"];
    $actions[] = ['ICON' => '', 'TEXT' => 'Скопировать UID', 'ONCLICK' => "x5pwaStepCopyUid('" . \CUtil::JSEscape($uid) . "'); return false;"];

    $row->AddActions($actions);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->AddGroupActionTable([
    'approve' => ['type' => 'button', 'name' => 'Одобрить', 'value' => 'approve', 'action' => "x5pwaStepGroupAction('approve');"],
    'reject'  => ['type' => 'button', 'name' => 'Отклонить', 'value' => 'reject', 'action' => "x5pwaStepGroupAction('reject');"],
    'reset'   => ['type' => 'button', 'name' => 'Сбросить в «на модерации»', 'value' => 'reset', 'action' => "x5pwaStepGroupAction('reset');"],
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<?php if ($stalePendingCount > 0):
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $filterUrl = '?lang=' . LANGUAGE_ID
        . '&apply_filter=Y&set_filter=Y&find_STATUS=N&find_LOCAL_DATE_to=' . urlencode($yesterday);
?>
    <div class="adm-info-message-wrap adm-info-message-yellow" style="margin-bottom:12px">
        <div class="adm-info-message">
            <b>Внимание:</b> <?= (int)$stalePendingCount ?> записей на модерации за прошлые дни.
            <br>Если отклонить такую запись, пользователь <b>не сможет пересдать шаги</b>.
            Рекомендуем одобрять или отклонять записи в день отправки.
            <br><br>
            <a href="<?= htmlspecialcharsbx($filterUrl) ?>" style="font-weight:600">Показать старые записи →</a>
        </div>
    </div>
<?php endif; ?>

<?php if ($recalcMessage !== null): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message"><?= htmlspecialcharsbx($recalcMessage) ?></div>
    </div>
<?php endif ?>

<form method="post" style="margin-bottom:12px">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="x5pwa_recalc_totals" value="1">
    <input type="submit" value="Пересчитать UF_TOTAL_STEPS для всех пользователей"
           class="adm-btn"
           onclick="return confirm('Пересчитать суммы подтверждённых шагов для всех пользователей?')">
    <span style="margin-left:12px;color:#888;font-size:12px">Обычно не требуется.</span>
</form>

<script>
function x5pwaStepCopyUid(uid) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(uid).then(function () {
            BX.UI.Notification.Center.notify({content: 'UID скопирован'});
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = uid; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }
}
function x5pwaStepPost(fields) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
    form.style.display = 'none';
    for (var key in fields) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = key; inp.value = fields[key];
        form.appendChild(inp);
    }
    document.body.appendChild(form); form.submit();
}
function x5pwaStepAction(id, action) {
    if (action === 'approve' && !confirm('Одобрить?')) return;
    if (action === 'reset' && !confirm('Сбросить?')) return;
    x5pwaStepPost({ sessid: '<?= bitrix_sessid() ?>', action: action, single_id: id });
}
function x5pwaStepRejectPrompt(id) {
    var reason = prompt('Причина отклонения:', '');
    if (reason === null) return;
    x5pwaStepPost({ sessid: '<?= bitrix_sessid() ?>', action: 'reject', single_id: id, reject_reason: reason });
}
function x5pwaStepGetListForm() {
    return document.getElementById('form_<?= \CUtil::JSEscape($sTableID) ?>')
        || document.querySelector('form[name="form_<?= \CUtil::JSEscape($sTableID) ?>"]');
}
function x5pwaStepGroupAction(action) {
    var confirmMsg = { approve: 'Одобрить выбранные?', reject: 'Отклонить выбранные?', reset: 'Сбросить?' };
    if (!confirm(confirmMsg[action] || 'Продолжить?')) return;
    var reason = '';
    if (action === 'reject') {
        reason = prompt('Причина отклонения (для всех):', '');
        if (reason === null) return;
    }
    var form = x5pwaStepGetListForm();
    if (!form) { alert('Форма не найдена'); return; }
    var checked = form.querySelectorAll('input[name="ID[]"]:checked');
    if (!checked.length) { alert('Выберите хотя бы одну запись'); return; }
    var input = form.querySelector('input[name="action"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden'; input.name = 'action';
        form.appendChild(input);
    }
    input.value = action;
    if (action === 'reject') {
        var rInput = form.querySelector('input[name="reject_reason"]');
        if (!rInput) {
            rInput = document.createElement('input');
            rInput.type = 'hidden'; rInput.name = 'reject_reason'; 
            form.appendChild(rInput);
        }
        rInput.value = reason;
    }
    form.submit();
}
</script>

<?php
$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';