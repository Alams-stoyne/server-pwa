<?php
/**
 * Рефлексии пользователей PWA.
 * Реализован через CAdminUiList — так же, как x5_pwa_users.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Рефлексии');

// ─────────────────────────────────────────────────────────
// Сортировка
// ─────────────────────────────────────────────────────────
$sTableID = 'x5_pwa_reflections';
$oSort    = new CAdminUiSorting($sTableID, 'UF_SENT_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

// ─────────────────────────────────────────────────────────
// Поля фильтра
// ─────────────────────────────────────────────────────────
$filterFields = [
    [
        'id'          => 'SEARCH',
        'name'        => 'Текст',
        'type'        => 'string',
        'filterable'  => '?',
        'quickSearch' => '?',
        'default'     => true,
    ],
    [
        'id'         => 'LOCAL_DATE',
        'name'       => 'Дата (локальная)',
        'type'       => 'date',
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'APPROVED',
        'name'       => 'Статус',
        'type'       => 'list',
        'items'      => [
            'Y' => 'Одобрено',
            'N' => 'На модерации',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'UID',
        'name'       => 'UID',
        'type'       => 'string',
        'filterable' => '?',
        'default'    => false,
    ],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

// Универсальная читалка фильтра — та же, что в users.php.
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

$search    = x5pwaFilterValue($arFilter, 'SEARCH');
$uidFilter = x5pwaFilterValue($arFilter, 'UID');

// Диапазон дат: Bitrix может дать «LOCAL_DATE_from/to» или «>=LOCAL_DATE» / «<=LOCAL_DATE»
$dateFrom = x5pwaFilterValue($arFilter, 'LOCAL_DATE_from');
$dateTo   = x5pwaFilterValue($arFilter, 'LOCAL_DATE_to');
if ($dateFrom === '') $dateFrom = x5pwaFilterValue($arFilter, '>=LOCAL_DATE');
if ($dateTo   === '') $dateTo   = x5pwaFilterValue($arFilter, '<=LOCAL_DATE');

$approved = x5pwaFilterValue($arFilter, 'APPROVED');
if (!in_array($approved, ['Y', 'N'], true)) $approved = '';

// ─────────────────────────────────────────────────────────
// Групповые действия (approve / unapprove)
// ─────────────────────────────────────────────────────────
if ($arIDs = $lAdmin->GroupAction()) {
    $action = $lAdmin->GetAction();

    if (in_array($action, ['approve', 'unapprove'], true)) {
        if ($lAdmin->IsGroupActionToAll()) {
            $all = \X5\Pwa\ReflectionBuilder::listForAdmin([
                'search'    => $search,
                'uid'       => $uidFilter,
                'approved'  => $approved,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
                'noPaging'  => true,
                'perPage'   => 0,
                'sort'      => $by,
                'sortDir'   => $order,
            ]);
            $arIDs = array_column($all['rows'], 'ID');
        }

        global $USER;
        $adminId   = (int)$USER->GetID();
        $isApprove = $action === 'approve';

        foreach ($arIDs as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            if (!\X5\Pwa\ReflectionBuilder::setApproved($id, $isApprove, $adminId)) {
                $lAdmin->AddGroupError('Не удалось обновить #' . $id, $id);
            }
        }

        if (!$lAdmin->hasGroupErrors()) {
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
        }
    }
}

// ─────────────────────────────────────────────────────────
// Одиночные действия (approve / unapprove) через POST
// ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && check_bitrix_sessid()
    && !empty($_POST['single_id'])
    && in_array($_POST['action'] ?? '', ['approve', 'unapprove'], true)) {

    $id     = (int)$_POST['single_id'];
    $action = (string)$_POST['action'];

    if ($id > 0) {
        global $USER;
        \X5\Pwa\ReflectionBuilder::setApproved(
            $id,
            $action === 'approve',
            (int)$USER->GetID()
        );
    }

    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

// ─────────────────────────────────────────────────────────
// Пагинация + данные
// ─────────────────────────────────────────────────────────
$page = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));

$perPageDefault = 50;
$perPage = (int)($_REQUEST['SIZEN_1'] ?? $_REQUEST['SIZEN'] ?? 0);
if ($perPage <= 0) {
    try {
        $perPage = (int)$lAdmin->GetNavSize();
    } catch (\Throwable $e) {
        $perPage = 0;
    }
}
if ($perPage <= 0) $perPage = $perPageDefault;
if ($perPage > 500) $perPage = 500;

$params = [
    'search'    => $search,
    'uid'       => $uidFilter,
    'approved'  => $approved,
    'date_from' => $dateFrom,
    'date_to'   => $dateTo,
    'sort'      => $by,
    'sortDir'   => $order,
    'page'      => $page,
    'perPage'   => $perPage,
];

$data = \X5\Pwa\ReflectionBuilder::listForAdmin($params);

$dbRes = new CDBResult();
$dbRes->InitFromArray(array_values($data['rows']));

$rsData = new CAdminUiResult($dbRes, $sTableID);
$rsData->NavNum         = 1;
$rsData->NavPageSize    = $perPage;
$rsData->NavPageNomer   = $page;
$rsData->NavPageCount   = max(1, (int)$data['pages']);
$rsData->NavRecordCount = (int)$data['total'];

$lAdmin->SetNavigationParams($rsData, ['nPageSize' => $perPage]);

// Пользователи для отображения ФИО/ника
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
            'FIO'      => (string)($u['UF_FIO'] ?? ''),
            'NICKNAME' => (string)($u['UF_NICKNAME'] ?? ''),
        ];
    }
}

// ─────────────────────────────────────────────────────────
// Колонки
// ─────────────────────────────────────────────────────────
$arHeader = [
    ['id' => 'UF_LOCAL_DATE',  'content' => 'Дата (лок.)',     'sort' => 'UF_LOCAL_DATE', 'default' => true, 'width' => 110],
    ['id' => 'USER',           'content' => 'Пользователь',    'default' => true, 'width' => 200],
    ['id' => 'UF_SENT_AT',     'content' => 'Время отправки',  'sort' => 'UF_SENT_AT',    'default' => true, 'width' => 140],
    ['id' => 'UF_TEXT',        'content' => 'Текст',           'default' => true],
    ['id' => 'UF_APPROVED',    'content' => 'Статус',          'sort' => 'UF_APPROVED',   'default' => true, 'width' => 130],
    ['id' => 'UF_APPROVED_AT', 'content' => 'Одобрено',        'default' => false, 'width' => 140],
    ['id' => 'ID',             'content' => 'ID',              'sort' => 'ID',            'default' => false, 'width' => 60],
];

$lAdmin->AddHeaders($arHeader);
$lAdmin->AddVisibleHeaderColumn('UF_APPROVED_AT');
$lAdmin->AddVisibleHeaderColumn('ID');

// ─────────────────────────────────────────────────────────
// Строки
// ─────────────────────────────────────────────────────────
foreach ($data['rows'] as $r) {
    $id     = (int)$r['ID'];
    $uid    = (string)$r['UF_UID'];
    $text   = (string)($r['UF_TEXT'] ?? '');
    $date   = (string)($r['UF_LOCAL_DATE'] ?? '');
    $sentAt = $r['UF_SENT_AT'] ? $r['UF_SENT_AT']->format('d.m.Y H:i') : '—';
    $approved = ($r['UF_APPROVED'] ?? 'N') === 'Y';
    $approvedAt = !empty($r['UF_APPROVED_AT'])
        ? $r['UF_APPROVED_AT']->format('d.m.Y H:i')
        : '—';

    $userInfo    = $usersInfo[$uid] ?? null;
    $displayName = '';
    if ($userInfo) {
        if ($userInfo['FIO'] !== '')           $displayName = $userInfo['FIO'];
        elseif ($userInfo['NICKNAME'] !== '')  $displayName = '@' . $userInfo['NICKNAME'];
    }

    $arRes = [
        'ID'             => $id,
        'UF_UID'         => $uid,
        'UF_LOCAL_DATE'  => $date,
        'UF_SENT_AT'     => $sentAt,
        'UF_TEXT'        => $text,
        'UF_APPROVED'    => $r['UF_APPROVED'] ?? 'N',
        'UF_APPROVED_AT' => $approvedAt,
        'USER'           => $displayName,
    ];

    $row = $lAdmin->AddRow($id, $arRes);

    // Пользователь: ФИО + фрагмент UID
    $userHtml = '';
    if ($displayName !== '') {
        $userHtml .= '<b>' . htmlspecialcharsbx($displayName) . '</b><br>';
    }
    $userHtml .= '<span style="font-family:monospace;font-size:11px;color:#888">'
        . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>';
    $row->AddViewField('USER', $userHtml);

    // Текст: длинный обрезаем через <details>
    if (mb_strlen($text) > 300) {
        $preview = mb_substr($text, 0, 100);
        $row->AddViewField(
            'UF_TEXT',
            '<details>'
            . '<summary style="cursor:pointer;color:#1e6bcc">'
            . htmlspecialcharsbx($preview) . '…</summary>'
            . '<div style="white-space:pre-wrap;margin-top:6px">'
            . htmlspecialcharsbx($text) . '</div>'
            . '</details>'
        );
    } else {
        $row->AddViewField(
            'UF_TEXT',
            '<div style="white-space:pre-wrap">' . htmlspecialcharsbx($text) . '</div>'
        );
    }

    // Статус
    if ($approved) {
        $row->AddViewField(
            'UF_APPROVED',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;'
            . 'background:#e6f5d8;color:#3a7d1c;font-size:11px">Одобрено</span>'
        );
    } else {
        $row->AddViewField(
            'UF_APPROVED',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;'
            . 'background:#fdf3cf;color:#8a6b00;font-size:11px">На модерации</span>'
        );
    }

    // Действия
    $actions = [
        [
            'ICON'    => 'view',
            'TEXT'    => 'Профиль',
            'DEFAULT' => true,
            'LINK'    => 'x5_pwa_user_detail.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid),
        ],
    ];

    if ($approved) {
        $actions[] = [
            'ICON'    => '',
            'TEXT'    => 'Снять отметку',
            'ONCLICK' => "x5pwaRefAction(" . $id . ", 'unapprove'); return false;",
        ];
    } else {
        $actions[] = [
            'ICON'    => '',
            'TEXT'    => 'Одобрить',
            'ONCLICK' => "x5pwaRefAction(" . $id . ", 'approve'); return false;",
        ];
    }

    $actions[] = [
        'ICON'    => '',
        'TEXT'    => 'Скопировать UID',
        'ONCLICK' => "x5pwaRefCopyUid('" . \CUtil::JSEscape($uid) . "'); return false;",
    ];

    $row->AddActions($actions);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

// ─────────────────────────────────────────────────────────
// Групповые действия
// ─────────────────────────────────────────────────────────
$lAdmin->AddGroupActionTable([
    'approve' => [
        'type'   => 'button',
        'name'   => 'Одобрить',
        'value'  => 'approve',
        'action' => "x5pwaRefGroupAction('approve');",
    ],
    'unapprove' => [
        'type'   => 'button',
        'name'   => 'Снять отметку',
        'value'  => 'unapprove',
        'action' => "x5pwaRefGroupAction('unapprove');",
    ],
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<script>
    function x5pwaRefCopyUid(uid) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(uid).then(function () {
                BX.UI.Notification.Center.notify({content: 'UID скопирован'});
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = uid;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }
    }

    function x5pwaRefAction(id, action) {
        if (action === 'unapprove' && !confirm('Снять отметку публикации?')) return;
        if (action === 'approve' && !confirm('Одобрить для публикации?')) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
        form.style.display = 'none';

        var fields = {
            sessid:    '<?= bitrix_sessid() ?>',
            action:    action,
            single_id: id
        };
        for (var key in fields) {
            var inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = key;
            inp.value = fields[key];
            form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
    }

    function x5pwaRefGetListForm() {
        return document.getElementById('form_<?= \CUtil::JSEscape($sTableID) ?>')
            || document.querySelector('form[name="form_<?= \CUtil::JSEscape($sTableID) ?>"]');
    }

    function x5pwaRefGroupAction(action) {
        var confirmMsg = {
            approve:   'Одобрить выбранные рефлексии для публикации?',
            unapprove: 'Снять отметку публикации с выбранных рефлексий?'
        };
        if (!confirm(confirmMsg[action] || 'Продолжить?')) return;

        var form = x5pwaRefGetListForm();
        if (!form) { alert('Форма не найдена'); return; }

        var checked = form.querySelectorAll('input[name="ID[]"]:checked');
        if (!checked.length) { alert('Выберите хотя бы одну рефлексию'); return; }

        var input = form.querySelector('input[name="action"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            form.appendChild(input);
        }
        input.value = action;

        form.submit();
    }
</script>

<?php
$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';