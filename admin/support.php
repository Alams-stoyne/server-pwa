<?php
/**
 * Техподдержка — список обращений.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\SupportBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Обращения в поддержку');

$sTableID = 'x5_pwa_support';
$oSort    = new CAdminUiSorting($sTableID, 'UF_UPDATED_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

$filterFields = [
    ['id' => 'USER',      'name' => 'Пользователь / Device ID', 'type' => 'string', 'filterable' => '?', 'quickSearch' => '?', 'default' => true],
    ['id' => 'STATUS',    'name' => 'Статус', 'type' => 'list', 'items' => [
        ''                   => '— любой —',
        SupportBuilder::ST_NEW      => 'Ждёт ответа',
        SupportBuilder::ST_ANSWERED => 'Отвечено',
        SupportBuilder::ST_CLOSED   => 'Закрыто',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'SOURCE',    'name' => 'Источник', 'type' => 'list', 'items' => [
        ''                       => '— любой —',
        SupportBuilder::SRC_APP  => 'Авторизованный',
        SupportBuilder::SRC_GUEST=> 'Гость (не авторизован)',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'CREATED_AT', 'name' => 'Дата обращения',        'type' => 'date', 'filterable' => '', 'default' => true],
    ['id' => 'UPDATED_AT', 'name' => 'Последняя коммуникация','type' => 'date', 'filterable' => '', 'default' => true],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

function x5supFilterValue(array $arFilter, string $field): string
{
    $variants = [$field, '?' . $field, '~' . $field, '~?' . $field, '?' . '~' . $field, 'find_' . $field, 'find_?' . $field, 'find_~' . $field];
    foreach ($variants as $key) {
        if (isset($arFilter[$key])) {
            $v = $arFilter[$key]; if (is_array($v)) $v = reset($v);
            $v = trim((string)$v); if ($v !== '') return $v;
        }
    }
    foreach ($variants as $key) {
        if (isset($_GET[$key])) {
            $v = $_GET[$key]; if (is_array($v)) $v = reset($v);
            $v = trim((string)$v); if ($v !== '') return $v;
        }
    }
    return '';
}

$userQuery = x5supFilterValue($arFilter, 'USER');
$status    = x5supFilterValue($arFilter, 'STATUS');
$source    = x5supFilterValue($arFilter, 'SOURCE');
$dateFrom  = x5supFilterValue($arFilter, 'CREATED_AT_from');
$dateTo    = x5supFilterValue($arFilter, 'CREATED_AT_to');
$updFrom   = x5supFilterValue($arFilter, 'UPDATED_AT_from');
$updTo     = x5supFilterValue($arFilter, 'UPDATED_AT_to');

if ($arIDs = $lAdmin->GroupAction()) {
    $action = $lAdmin->GetAction();
    if (in_array($action, ['close', 'delete'], true)) {
        if ($lAdmin->IsGroupActionToAll()) {
            $all = SupportBuilder::listForAdmin([
                'user' => $userQuery, 'status' => $status, 'source' => $source,
                'date_from' => $dateFrom, 'date_to' => $dateTo,
                'upd_from' => $updFrom, 'upd_to' => $updTo,
                'noPaging' => true, 'perPage' => 0,
            ]);
            $arIDs = array_column($all['rows'], 'ID');
        }
        $adminId = (int)$USER->GetID();
        foreach ($arIDs as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            if ($action === 'close')  SupportBuilder::closeTicket($id, $adminId);
            if ($action === 'delete') SupportBuilder::deleteTicket($id);
        }
        if (!$lAdmin->hasGroupErrors()) {
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
        }
    }
}

$page    = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));
$perPage = 50;

$data = SupportBuilder::listForAdmin([
    'user' => $userQuery, 'status' => $status, 'source' => $source,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
    'upd_from' => $updFrom, 'upd_to' => $updTo,
    'sort' => $by, 'sortDir' => $order,
    'page' => $page, 'perPage' => $perPage,
]);

$dbRes = new CDBResult();
$dbRes->InitFromArray(array_values($data['rows']));

$rsData = new CAdminUiResult($dbRes, $sTableID);
$rsData->NavNum         = 1;
$rsData->NavPageSize    = $perPage;
$rsData->NavPageNomer   = $page;
$rsData->NavPageCount   = max(1, (int)$data['pages']);
$rsData->NavRecordCount = (int)$data['total'];

$lAdmin->SetNavigationParams($rsData, ['nPageSize' => $perPage]);

$arHeader = [
    ['id' => 'ID',            'content' => 'ID',       'sort' => 'ID',            'default' => true],
    ['id' => 'STATUS',        'content' => 'Статус',                                'default' => true],
    ['id' => 'SOURCE',        'content' => 'Источник',                              'default' => true],
    ['id' => 'USER',          'content' => 'Пользователь / Device ID',              'default' => true],
    ['id' => 'SUBJECT',       'content' => 'Тема',                                  'default' => true],
    ['id' => 'MESSAGES_COUNT','content' => 'Сообщений',                             'default' => true],
    ['id' => 'CREATED_AT',    'content' => 'Создано',   'sort' => 'UF_CREATED_AT', 'default' => true],
    ['id' => 'UPDATED_AT',    'content' => 'Обновлено', 'sort' => 'UF_UPDATED_AT', 'default' => true],
];
$lAdmin->AddHeaders($arHeader);

$formatDate = function (?int $ms): string {
    if (!$ms) return '—';
    return date('d.m.Y H:i', (int)($ms / 1000));
};

$statusMap = [
    SupportBuilder::ST_NEW      => ['Ждёт ответа',  '#f5a623', '#fff'],
    SupportBuilder::ST_ANSWERED => ['Отвечено',     '#3a7d1c', '#fff'],
    SupportBuilder::ST_CLOSED   => ['Закрыто',      '#888',    '#fff'],
];

foreach ($data['rows'] as $t) {
    $id      = (int)$t['ID'];
    $status  = (string)$t['STATUS'];
    $src     = (string)$t['SOURCE'];
    $isGuest = $src === SupportBuilder::SRC_GUEST;
    $badge   = $statusMap[$status] ?? [$status, '#ccc', '#000'];

    // Пользователь
    $userLabel = '';
    $userSub   = '';
    if ($isGuest) {
        $dev = (string)$t['DEVICE_ID'];
        $userLabel = '[Гость] ' . ($dev !== '' ? substr($dev, 0, 8) : '');
        $userSub   = $dev;
        if ($t['AUTH_ERROR']) {
            $userSub .= ' · ' . (string)$t['AUTH_ERROR'];
        }
    } else {
        $userLabel = trim((string)$t['NICKNAME']);
        if ($userLabel === '') $userLabel = trim((string)$t['FIO']);
        if ($userLabel === '') $userLabel = substr((string)$t['UID'], 0, 10) . '…';
        $userSub = substr((string)$t['UID'], 0, 16);
    }

    $arRes = [
        'ID'             => $id,
        'STATUS'         => $status,
        'SOURCE'         => $src,
        'USER'           => $userLabel,
        'SUBJECT'        => (string)$t['SUBJECT'],
        'MESSAGES_COUNT' => (int)$t['MESSAGES_COUNT'],
        'CREATED_AT'     => $formatDate((int)$t['CREATED_AT']),
        'UPDATED_AT'     => $formatDate((int)$t['UPDATED_AT']),
    ];

    $row = $lAdmin->AddRow($id, $arRes);
    $detailUrl = 'x5_pwa_support_detail.php?lang=' . LANGUAGE_ID . '&id=' . $id;

    $row->AddViewField('ID',
        '<a href="' . htmlspecialcharsbx($detailUrl) . '" style="font-weight:600">' . $id . '</a>');

    $row->AddViewField('STATUS',
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;'
        . 'background:' . $badge[1] . ';color:' . $badge[2] . ';font-size:11px">'
        . htmlspecialcharsbx($badge[0]) . '</span>');

    $row->AddViewField('SOURCE',
        $isGuest
            ? '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f5a623;color:#fff;font-size:11px">Гость</span>'
            : '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e6f5d8;color:#3a7d1c;font-size:11px">Пользователь</span>');

    $subjectFull  = (string)$t['SUBJECT'];
    $subjectShort = mb_strimwidth($subjectFull, 0, 80, '…', 'UTF-8');
    $row->AddViewField('SUBJECT',
        '<a href="' . htmlspecialcharsbx($detailUrl) . '" title="'
        . htmlspecialcharsbx($subjectFull) . '">'
        . htmlspecialcharsbx($subjectShort) . '</a>');

    $row->AddViewField('USER',
        htmlspecialcharsbx($userLabel)
        . ($userSub !== ''
            ? '<br><span style="font-family:monospace;font-size:10px;color:#888">'
              . htmlspecialcharsbx($userSub) . '</span>'
            : ''));

    $row->AddActions([
        [
            'ICON'    => 'view',
            'TEXT'    => 'Открыть',
            'DEFAULT' => true,
            'LINK'    => $detailUrl,
        ],
        [
            'ICON' => '',
            'TEXT' => 'Закрыть',
            'ONCLICK' => $status === SupportBuilder::ST_CLOSED
                ? ''
                : "x5supSingleAction(" . $id . ", 'close'); return false;",
        ],
        [
            'ICON'    => 'delete',
            'TEXT'    => 'Удалить',
            'ONCLICK' => "x5supSingleAction(" . $id . ", 'delete'); return false;",
        ],
    ]);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->AddGroupActionTable([
    'close' => [
        'type' => 'button', 'name' => 'Закрыть', 'value' => 'close',
        'action' => "if(confirm('Закрыть выбранные обращения?')) x5supGroupAction('close');",
    ],
    'delete' => [
        'type' => 'button', 'name' => 'Удалить', 'value' => 'delete',
        'action' => "if(confirm('Удалить обращения вместе с сообщениями и файлами?')) x5supGroupAction('delete');",
    ],
]);

$lAdmin->CheckListMode();

$exportUrl = 'x5_pwa_support_export.php?lang=' . LANGUAGE_ID . '&' . http_build_query(array_filter([
    'USER'              => $userQuery,
    'STATUS'            => $status,
    'SOURCE'            => $source,
    'CREATED_AT_from'   => $dateFrom,
    'CREATED_AT_to'     => $dateTo,
    'UPDATED_AT_from'   => $updFrom,
    'UPDATED_AT_to'     => $updTo,
    'by'                => $by,
    'order'             => $order,
], fn($v) => $v !== '' && $v !== null));

$lAdmin->SetContextMenu([
    ['TEXT' => 'Экспорт в CSV', 'LINK' => $exportUrl, 'ICON' => 'btn_download', 'LINK_PARAM' => ''],
]);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<script>
function x5supGetForm() {
    return document.getElementById('form_<?= \CUtil::JSEscape($sTableID) ?>')
        || document.querySelector('form[name="form_<?= \CUtil::JSEscape($sTableID) ?>"]');
}
function x5supSubmitAction(action) {
    var form = x5supGetForm();
    if (!form) { alert('Форма не найдена'); return; }
    var input = form.querySelector('input[name="action"]');
    if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'action'; form.appendChild(input); }
    input.value = action;
    var checked = form.querySelectorAll('input[name="ID[]"]:checked');
    if (!checked.length) { alert('Выберите хотя бы одно обращение'); return; }
    form.submit();
}
function x5supGroupAction(action) { x5supSubmitAction(action); }
function x5supSingleAction(id, action) {
    if (action === 'delete' && !confirm('Удалить обращение вместе с сообщениями и файлами? Необратимо.')) return;
    if (action === 'close' && !confirm('Закрыть обращение?')) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
    form.style.display = 'none';
    var fields = { sessid: '<?= bitrix_sessid() ?>', action: action, single_id: id };
    for (var k in fields) {
        var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; form.appendChild(i);
    }
    document.body.appendChild(form); form.submit();
}
</script>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()
    && !empty($_POST['single_id'])
    && in_array($_POST['action'] ?? '', ['close', 'delete'], true)
) {
    $id = (int)$_POST['single_id'];
    $action = (string)$_POST['action'];
    if ($id > 0) {
        if ($action === 'close')  \X5\Pwa\SupportBuilder::closeTicket($id, (int)$USER->GetID());
        if ($action === 'delete') \X5\Pwa\SupportBuilder::deleteTicket($id);
    }
    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';