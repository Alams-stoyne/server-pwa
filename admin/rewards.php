<?php
/**
 * PWA — История наград (все пользователи).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use X5\Pwa\RewardsBuilder;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — История наград');

$sTableID = 'x5_pwa_rewards';
$oSort    = new CAdminUiSorting($sTableID, 'UF_CREATED_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

$filterFields = [
    ['id' => 'UID',        'name' => 'UID (частично)', 'type' => 'string', 'filterable' => '?', 'quickSearch' => '?', 'default' => true],
    ['id' => 'TYPE',       'name' => 'Тип действия', 'type' => 'list', 'items' => array_merge(
        ['' => '— любой —'],
        array_combine(RewardsBuilder::ALL_TYPES, array_map(
            fn($t) => RewardsBuilder::typeTitle($t),
            RewardsBuilder::ALL_TYPES
        ))
    ), 'filterable' => '', 'default' => true],
    ['id' => 'LOCAL_DATE', 'name' => 'Дата (локальная)', 'type' => 'date', 'filterable' => '', 'default' => true],
    ['id' => 'CREATED_AT', 'name' => 'Дата начисления',   'type' => 'date', 'filterable' => '', 'default' => true],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

function x5rwFilterValue(array $arFilter, string $field): string
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

$uidQuery  = x5rwFilterValue($arFilter, 'UID');
// Fallback: если AddFilter не применил фильтр (нет apply_filter=Y или передан UID без префикса),
// читаем UID напрямую из запроса.
if ($uidQuery === '') {
    foreach (['find_UID', '~UID', '?UID', 'UID'] as $k) {
        $v = trim((string)($_REQUEST[$k] ?? ''));
        if ($v !== '') { $uidQuery = $v; break; }
    }
}

$type      = x5rwFilterValue($arFilter, 'TYPE');
$dateFrom  = x5rwFilterValue($arFilter, 'LOCAL_DATE_from');
$dateTo    = x5rwFilterValue($arFilter, 'LOCAL_DATE_to');
$createdFrom = x5rwFilterValue($arFilter, 'CREATED_AT_from');
$createdTo   = x5rwFilterValue($arFilter, 'CREATED_AT_to');

$page    = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));
$perPage = 50;

// ── Построение фильтра и выборки
$dc = \X5\Pwa\Storage::dataClass('X5PwaRewardLog');

$filter = [];

if ($uidQuery !== '') {
    $filter['%UF_UID'] = '%' . $uidQuery . '%';
}
if ($type !== '' && in_array($type, RewardsBuilder::ALL_TYPES, true)) {
    $filter['=UF_ACTION_TYPE'] = $type;
}
if ($dateFrom !== '') $filter['>=UF_LOCAL_DATE'] = $dateFrom;
if ($dateTo   !== '') $filter['<=UF_LOCAL_DATE'] = $dateTo;
if ($createdFrom !== '') {
    try { $filter['>=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdFrom . ' 00:00:00'); } catch (\Throwable $e) {}
}
if ($createdTo !== '') {
    try { $filter['<=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdTo . ' 23:59:59'); } catch (\Throwable $e) {}
}

$sortMap = [
    'ID'            => 'ID',
    'UF_UID'        => 'UF_UID',
    'UF_ACTION_TYPE'=> 'UF_ACTION_TYPE',
    'UF_POINTS'     => 'UF_POINTS',
    'UF_LOCAL_DATE' => 'UF_LOCAL_DATE',
    'UF_CREATED_AT' => 'UF_CREATED_AT',
];
$sortKey = $sortMap[$by] ?? 'UF_CREATED_AT';
$sortDir = $order === 'ASC' ? 'ASC' : 'DESC';
$orderBy = [$sortKey => $sortDir, 'ID' => 'DESC'];

$totalRow = $dc::getList([
    'select'  => ['CNT'],
    'filter'  => $filter,
    'runtime' => [new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')],
])->fetch();
$total = (int)($totalRow['CNT'] ?? 0);

$rows = $dc::getList([
    'filter' => $filter,
    'order'  => $orderBy,
    'limit'  => $perPage,
    'offset' => ($page - 1) * $perPage,
])->fetchAll();

// Подтянем ники/ФИО
$uids = array_values(array_unique(array_column($rows, 'UF_UID')));
$users = [];
if ($uids) {
    try {
        $bdc = \X5\Pwa\Storage::dataClass('X5PwaUidBindings');
        $brs = $bdc::getList([
            'select' => ['UF_UID', 'UF_NICKNAME', 'UF_FIO'],
            'filter' => ['=UF_UID' => $uids],
        ])->fetchAll();
        foreach ($brs as $b) $users[$b['UF_UID']] = $b;
    } catch (\Throwable $e) {}
}

$dbRes = new CDBResult();
$dbRes->InitFromArray($rows);

$rsData = new CAdminUiResult($dbRes, $sTableID);
$rsData->NavNum         = 1;
$rsData->NavPageSize    = $perPage;
$rsData->NavPageNomer   = $page;
$rsData->NavPageCount   = $total > 0 ? (int)ceil($total / $perPage) : 1;
$rsData->NavRecordCount = $total;

$lAdmin->SetNavigationParams($rsData, ['nPageSize' => $perPage]);

$arHeader = [
    ['id' => 'ID',            'content' => 'ID',         'sort' => 'ID',            'default' => true],
    ['id' => 'UF_CREATED_AT', 'content' => 'Начислено',  'sort' => 'UF_CREATED_AT', 'default' => true],
    ['id' => 'UF_UID',        'content' => 'Пользователь','sort' => 'UF_UID',       'default' => true],
    ['id' => 'UF_ACTION_TYPE','content' => 'Тип',        'sort' => 'UF_ACTION_TYPE','default' => true],
    ['id' => 'UF_TITLE',      'content' => 'За что',                                 'default' => true],
    ['id' => 'UF_LOCAL_DATE', 'content' => 'Дата',       'sort' => 'UF_LOCAL_DATE', 'default' => true],
    ['id' => 'UF_POINTS',     'content' => 'Баллы',      'sort' => 'UF_POINTS',     'default' => true],
];
$lAdmin->AddHeaders($arHeader);

foreach ($rows as $r) {
    $uid  = (string)($r['UF_UID'] ?? '');
    $user = $users[$uid] ?? [];
    $userLabel = trim((string)($user['UF_NICKNAME'] ?? ''));
    if ($userLabel === '') $userLabel = trim((string)($user['UF_FIO'] ?? ''));
    if ($userLabel === '') $userLabel = substr($uid, 0, 10) . '…';

    $created = !empty($r['UF_CREATED_AT'])
        ? $r['UF_CREATED_AT']->format('d.m.Y H:i:s')
        : '—';

    $typeTitle = RewardsBuilder::typeTitle((string)($r['UF_ACTION_TYPE'] ?? ''));

    $arRes = [
        'ID'             => (int)$r['ID'],
        'UF_CREATED_AT'  => $created,
        'UF_UID'         => $uid,
        'UF_ACTION_TYPE' => (string)($r['UF_ACTION_TYPE'] ?? ''),
        'UF_TITLE'       => (string)($r['UF_TITLE'] ?? ''),
        'UF_LOCAL_DATE'  => (string)($r['UF_LOCAL_DATE'] ?? ''),
        'UF_POINTS'      => (int)($r['UF_POINTS'] ?? 0),
    ];

    $row = $lAdmin->AddRow((int)$r['ID'], $arRes);

    $row->AddViewField('UF_UID',
        htmlspecialcharsbx($userLabel)
        . '<br><span style="font-family:monospace;font-size:10px;color:#888">'
        . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>');

    $row->AddViewField('UF_ACTION_TYPE',
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e0efff;color:#1e6bcc;font-size:11px">'
        . htmlspecialcharsbx($typeTitle) . '</span>');

    $row->AddViewField('UF_POINTS',
        '<b style="color:#3a7d1c">+' . (int)$r['UF_POINTS'] . '</b>');

    $row->AddActions([
        [
            'ICON'    => 'view',
            'TEXT'    => 'К пользователю',
            'DEFAULT' => true,
            'LINK'    => 'x5_pwa_user_detail.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid),
        ],
    ]);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => $total],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->CheckListMode();

$exportUrl = 'x5_pwa_rewards_export.php?lang=' . LANGUAGE_ID . '&' . http_build_query(array_filter([
    'UID'              => $uidQuery,
    'TYPE'             => $type,
    'LOCAL_DATE_from'  => $dateFrom,
    'LOCAL_DATE_to'    => $dateTo,
    'CREATED_AT_from'  => $createdFrom,
    'CREATED_AT_to'    => $createdTo,
    'by'               => $by,
    'order'            => $order,
], fn($v) => $v !== '' && $v !== null));

$lAdmin->SetContextMenu([
    ['TEXT' => 'Экспорт в CSV', 'LINK' => $exportUrl, 'ICON' => 'btn_download', 'LINK_PARAM' => ''],
]);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';