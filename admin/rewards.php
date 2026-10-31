<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use X5\Pwa\RewardsBuilder;
use X5\Pwa\Storage;

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
    ['id' => 'SEARCH',     'name' => 'Поиск (UID, ФИО, ник, компания)', 'type' => 'string', 'filterable' => '?', 'quickSearch' => '?', 'default' => true],
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
    $variants = [$field, '?' . $field, '~' . $field, '~?' . $field, '?' . '~' . $field,
        'find_' . $field, 'find_?' . $field, 'find_~' . $field];
    foreach ($variants as $key) {
        if (isset($arFilter[$key])) {
            $v = $arFilter[$key];
            if (is_array($v)) $v = reset($v);
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
    }
    foreach ($variants as $key) {
        if (isset($_REQUEST[$key])) {
            $v = $_REQUEST[$key];
            if (is_array($v)) $v = reset($v);
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
    }
    return '';
}

$search = x5rwFilterValue($arFilter, 'SEARCH');

// ─── Fallback: старые ссылки из user_detail.php ───
if ($search === '' && !empty($_REQUEST['x5uid'])) {
    $candidate = trim((string)$_REQUEST['x5uid']);
    if ($candidate !== '') $search = $candidate;
}
if ($search === '' && !empty($_REQUEST['UID'])) {
    $candidate = trim((string)$_REQUEST['UID']);
    if ($candidate !== '') $search = $candidate;
}

$type = x5rwFilterValue($arFilter, 'TYPE');
if (!in_array($type, RewardsBuilder::ALL_TYPES, true)) $type = '';

$dateFrom    = x5rwFilterValue($arFilter, 'LOCAL_DATE_from');
$dateTo      = x5rwFilterValue($arFilter, 'LOCAL_DATE_to');
$createdFrom = x5rwFilterValue($arFilter, 'CREATED_AT_from');
$createdTo   = x5rwFilterValue($arFilter, 'CREATED_AT_to');

$page = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));

$perPageDefault = 50;
$perPage = (int)($_REQUEST['SIZEN_1'] ?? $_REQUEST['SIZEN'] ?? 0);
if ($perPage <= 0) {
    try { $perPage = (int)$lAdmin->GetNavSize(); } catch (\Throwable $e) { $perPage = 0; }
}
if ($perPage <= 0) $perPage = $perPageDefault;
if ($perPage > 500) $perPage = 500;

/**
 * Резолвит UID'ы из x5_pwa_uid_bindings по подстроке:
 * UF_UID / UF_FIO / UF_NICKNAME / UF_WORKPLACE — как в users.php.
 */
function x5rwResolveUidsBySearch(string $query, int $limit = 5000): array
{
    $query = trim($query);
    if ($query === '') return [];

    try {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $like      = "'%" . $sqlHelper->forSql($query) . "%'";

        $rows = $conn->query(
            "SELECT UF_UID FROM x5_pwa_uid_bindings
             WHERE UF_UID LIKE {$like}
                OR UF_FIO LIKE {$like}
                OR UF_NICKNAME LIKE {$like}
                OR UF_WORKPLACE LIKE {$like}
             LIMIT " . (int)$limit
        )->fetchAll();

        return array_map(fn($r) => (string)$r['UF_UID'], $rows);
    } catch (\Throwable $e) {
        \X5\Pwa\Settings::logError(
            'rewards resolveUids failed query="' . mb_substr($query, 0, 60)
            . '": ' . $e->getMessage(),
            'rewards.php'
        );
        return [];
    }
}

$dc = Storage::dataClass('X5PwaRewardLog');

$resolvedUids = null; // null = фильтр по UID не задан
if ($search !== '') {
    $resolvedUids = x5rwResolveUidsBySearch($search);

    // Ничего не нашли — пустой результат, не тянуть всю таблицу.
    if (empty($resolvedUids)) {
        $rows = [];
        $total = 0;
        $dbRes = new CDBResult();
        $dbRes->InitFromArray([]);
    }
}

if (!isset($rows)) {
    $filter = [];
    if ($resolvedUids !== null) $filter['=UF_UID'] = $resolvedUids;
    if ($type !== '')     $filter['=UF_ACTION_TYPE'] = $type;
    if ($dateFrom !== '') $filter['>=UF_LOCAL_DATE'] = $dateFrom;
    if ($dateTo   !== '') $filter['<=UF_LOCAL_DATE'] = $dateTo;
    if ($createdFrom !== '') {
        try { $filter['>=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdFrom . ' 00:00:00'); } catch (\Throwable $e) {}
    }
    if ($createdTo !== '') {
        try { $filter['<=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime($createdTo . ' 23:59:59'); } catch (\Throwable $e) {}
    }

    $sortMap = [
        'ID' => 'ID', 'UF_UID' => 'UF_UID', 'UF_ACTION_TYPE' => 'UF_ACTION_TYPE',
        'UF_POINTS' => 'UF_POINTS', 'UF_LOCAL_DATE' => 'UF_LOCAL_DATE', 'UF_CREATED_AT' => 'UF_CREATED_AT',
    ];
    $sortKey = $sortMap[$by] ?? 'UF_CREATED_AT';
    $sortDir = $order === 'ASC' ? 'ASC' : 'DESC';
    $orderBy = [$sortKey => $sortDir, 'ID' => 'DESC'];

    $totalRow = $dc::getList([
        'select' => ['CNT'], 'filter' => $filter,
        'runtime' => [new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')],
    ])->fetch();
    $total = (int)($totalRow['CNT'] ?? 0);

    $rows = $dc::getList([
        'filter' => $filter, 'order' => $orderBy,
        'limit' => $perPage, 'offset' => ($page - 1) * $perPage,
    ])->fetchAll();

    $dbRes = new CDBResult();
    $dbRes->InitFromArray($rows);
}

$uids = array_values(array_unique(array_column($rows, 'UF_UID')));
$users = [];
if ($uids) {
    try {
        $bdc = Storage::dataClass('X5PwaUidBindings');
        $brs = $bdc::getList(['select' => ['UF_UID', 'UF_NICKNAME', 'UF_FIO'], 'filter' => ['=UF_UID' => $uids]])->fetchAll();
        foreach ($brs as $b) $users[$b['UF_UID']] = $b;
    } catch (\Throwable $e) {}
}

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

    $created = !empty($r['UF_CREATED_AT']) ? $r['UF_CREATED_AT']->format('d.m.Y H:i:s') : '—';
    $typeTitle = RewardsBuilder::typeTitle((string)($r['UF_ACTION_TYPE'] ?? ''));

    $arRes = [
        'ID' => (int)$r['ID'], 'UF_CREATED_AT' => $created, 'UF_UID' => $uid,
        'UF_ACTION_TYPE' => (string)($r['UF_ACTION_TYPE'] ?? ''),
        'UF_TITLE' => (string)($r['UF_TITLE'] ?? ''),
        'UF_LOCAL_DATE' => (string)($r['UF_LOCAL_DATE'] ?? ''),
        'UF_POINTS' => (int)($r['UF_POINTS'] ?? 0),
    ];

    $row = $lAdmin->AddRow((int)$r['ID'], $arRes);

    $row->AddViewField('UF_UID', htmlspecialcharsbx($userLabel)
        . '<br><span style="font-family:monospace;font-size:10px;color:#888">'
        . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>');

    $row->AddViewField('UF_ACTION_TYPE',
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e0efff;color:#1e6bcc;font-size:11px">'
        . htmlspecialcharsbx($typeTitle) . '</span>');

    $row->AddViewField('UF_POINTS', '<b style="color:#3a7d1c">+' . (int)$r['UF_POINTS'] . '</b>');

    $row->AddActions([[
        'ICON' => 'view', 'TEXT' => 'К пользователю', 'DEFAULT' => true,
        'LINK' => 'x5_pwa_user_detail.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid),
    ]]);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => $total],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->CheckListMode();

// Экспорт: SEARCH (единое поле) → parameters SEARCH
$exportUrl = 'x5_pwa_rewards_export.php?lang=' . LANGUAGE_ID . '&' . http_build_query(array_filter([
    'SEARCH' => $search, 'TYPE' => $type,
    'LOCAL_DATE_from' => $dateFrom, 'LOCAL_DATE_to' => $dateTo,
    'CREATED_AT_from' => $createdFrom, 'CREATED_AT_to' => $createdTo,
    'by' => $by, 'order' => $order,
], fn($v) => $v !== '' && $v !== null));

$lAdmin->SetContextMenu([
    ['TEXT' => 'Экспорт в CSV', 'LINK' => $exportUrl, 'ICON' => 'btn_download', 'LINK_PARAM' => ''],
]);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';