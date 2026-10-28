<?php
/**
 * PWA — Техподдержка (список тикетов).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\SupportBuilder;

$moduleId = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Техподдержка');

$sTableID = 'x5_pwa_support';
$oSort    = new CAdminUiSorting($sTableID, 'UF_UPDATED_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

// ─── Фильтр ───
$filterFields = [
    [
        'id'         => 'STATUS',
        'name'       => 'Статус',
        'type'       => 'list',
        'items'      => [
            ''         => '— любой —',
            'new'      => 'Новые',
            'answered' => 'Отвеченные',
            'closed'   => 'Закрытые',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'SOURCE',
        'name'       => 'Источник',
        'type'       => 'list',
        'items'      => [
            ''           => '— любой —',
            'app'        => 'Авторизованные',
            'app_unauth' => 'Гости (без входа)',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'AUTH_ERROR',
        'name'       => 'Тип ошибки / Reset',
        'type'       => 'list',
        'items'      => [
            ''                  => '— любой —',
            'RESET_UID_REQUEST' => 'Запросы на сброс UID',
            'NO_RESET'          => 'Без запросов на сброс',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'DATE_CREATED',
        'name'       => 'Дата создания',
        'type'       => 'date',
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'DATE_UPDATED',
        'name'       => 'Дата обновления',
        'type'       => 'date',
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'USER',
        'name'       => 'Пользователь (UID, ФИО, ник)',
        'type'       => 'string',
        'filterable' => '?',
        'quickSearch'=> '?',
        'default'    => true,
    ],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

if (!function_exists('x5supFilterValue')) {
    function x5supFilterValue(array $arFilter, string $field): string
    {
        $variants = [
            $field, '?' . $field, '~' . $field, '~?' . $field, '?' . '~' . $field,
            'find_' . $field, 'find_?' . $field, 'find_~' . $field,
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

$status    = x5supFilterValue($arFilter, 'STATUS');
$source    = x5supFilterValue($arFilter, 'SOURCE');
$authError = x5supFilterValue($arFilter, 'AUTH_ERROR');
$userQuery = x5supFilterValue($arFilter, 'USER');

$dateCreatedFrom = x5supFilterValue($arFilter, 'DATE_CREATED_from');
$dateCreatedTo   = x5supFilterValue($arFilter, 'DATE_CREATED_to');
$dateUpdatedFrom = x5supFilterValue($arFilter, 'DATE_UPDATED_from');
$dateUpdatedTo   = x5supFilterValue($arFilter, 'DATE_UPDATED_to');

// ─── Данные ───
$page    = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));
$perPage = 50;

$data = SupportBuilder::listForAdmin([
    'status'     => $status,
    'source'     => $source,
    'auth_error' => $authError,
    'user'       => $userQuery,
    'date_from'  => $dateCreatedFrom,
    'date_to'    => $dateCreatedTo,
    'upd_from'   => $dateUpdatedFrom,
    'upd_to'     => $dateUpdatedTo,
    'sort'       => $by,
    'sortDir'    => $order,
    'page'       => $page,
    'perPage'    => $perPage,
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

// ─── Колонки ───
$arHeader = [
    ['id' => 'ID',             'content' => 'ID',            'sort' => 'ID',             'default' => true],
    ['id' => 'AUTH_ERROR',     'content' => 'Reset UID',                                  'default' => true],
    ['id' => 'UF_UPDATED_AT',  'content' => 'Обновлён',      'sort' => 'UF_UPDATED_AT',  'default' => true],
    ['id' => 'USER',           'content' => 'Пользователь',                               'default' => true],
    ['id' => 'UF_SOURCE',      'content' => 'Источник',                                   'default' => true],
    ['id' => 'UF_SUBJECT',     'content' => 'Тема',                                       'default' => true],
    ['id' => 'UF_STATUS',      'content' => 'Статус',        'sort' => 'UF_STATUS',      'default' => true],
    ['id' => 'UF_MESSAGES_COUNT', 'content' => 'Сообщений',                               'default' => true],
    ['id' => 'UF_CREATED_AT',  'content' => 'Создан',        'sort' => 'UF_CREATED_AT',  'default' => false],
    ['id' => 'ACTIONS',        'content' => 'Действия',                                   'default' => true],
];
$lAdmin->AddHeaders($arHeader);

$statusLabels = [
    'new'      => ['Новый', '#fdf3cf', '#8a6b00'],
    'answered' => ['Отвечен', '#e6f5d8', '#3a7d1c'],
    'closed'   => ['Закрыт', '#f0f2f5', '#666'],
];

foreach ($data['rows'] as $t) {
    $ticketId  = (int)$t['ID'];
    $uid       = (string)$t['UID'];
    $deviceId  = (string)$t['DEVICE_ID'];
    $subject   = (string)$t['SUBJECT'];
    $st        = (string)$t['STATUS'];
    $stInfo    = $statusLabels[$st] ?? $statusLabels['new'];
    $isReset   = ($t['AUTH_ERROR'] === 'RESET_UID_REQUEST');
    
    // Форматирование дат
    $createdAt = $t['CREATED_AT'] > 0 ? date('d.m.Y H:i', (int)($t['CREATED_AT'] / 1000)) : '—';
    $updatedAt = $t['UPDATED_AT'] > 0 ? date('d.m.Y H:i', (int)($t['UPDATED_AT'] / 1000)) : '—';

    // Пользователь
    $userLabel = trim((string)($t['NICKNAME'] ?: $t['FIO']));
    if ($userLabel === '') $userLabel = substr($uid, 0, 10) . '…';
    $userHtml = '<b>' . htmlspecialcharsbx($userLabel) . '</b>';
    if ($uid !== '') {
        $userHtml .= '<br><span style="font-family:monospace;font-size:10px;color:#888">' 
                   . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>';
    }

    $arRes = [
        'ID'              => $ticketId,
        'AUTH_ERROR'      => $isReset ? 'RESET' : '',
        'UF_UPDATED_AT'   => $updatedAt,
        'USER'            => $userLabel,
        'UF_SOURCE'       => (string)$t['SOURCE'],
        'UF_SUBJECT'      => $subject,
        'UF_STATUS'       => $st,
        'UF_MESSAGES_COUNT'=> (int)$t['MESSAGES_COUNT'],
        'UF_CREATED_AT'   => $createdAt,
    ];

    $row = $lAdmin->AddRow($ticketId, $arRes);

    $detailUrl = 'x5_pwa_support_detail.php?lang=' . LANGUAGE_ID . '&ID=' . $ticketId;

    // ID — ссылка
    $row->AddViewField('ID', '<a href="' . $detailUrl . '"><b>' . $ticketId . '</b></a>');
    
    // Тема — ссылка
    $row->AddViewField('UF_SUBJECT', '<a href="' . $detailUrl . '">' . htmlspecialcharsbx($subject) . '</a>');

    // Бейдж Reset UID
    if ($isReset) {
        $row->AddViewField('AUTH_ERROR', 
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#fdf3cf;color:#8a6b00;font-size:11px;font-weight:600;" title="Запрос на сброс привязки UID">RESET</span>'
        );
    } else {
        $row->AddViewField('AUTH_ERROR', '<span style="color:#aaa">—</span>');
    }

    $row->AddViewField('USER', $userHtml);
    
    $sourceLabel = ($t['SOURCE'] === 'app_unauth') ? 'Гость' : 'Авторизован';
    $row->AddViewField('UF_SOURCE', $sourceLabel);

    $row->AddViewField('UF_STATUS', 
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' 
        . $stInfo[1] . ';color:' . $stInfo[2] . ';font-size:11px">' 
        . htmlspecialcharsbx($stInfo[0]) . '</span>');

    $actions = [
        [
            'ICON'    => 'view',
            'TEXT'    => 'Открыть',
            'DEFAULT' => true,
            'LINK'    => $detailUrl,
        ],
    ];

    $row->AddActions($actions);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';