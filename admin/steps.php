<?php
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

$filterFields = [
    ['id' => 'STATUS',   'name' => 'Статус', 'type' => 'list', 'items' => [
        '' => '— любой —', 'new' => 'Новые', 'answered' => 'Отвеченные', 'closed' => 'Закрытые',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'SOURCE',   'name' => 'Источник', 'type' => 'list', 'items' => [
        '' => '— любой —', 'app' => 'Авторизованные', 'app_unauth' => 'Гости (без входа)',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'AUTH_ERROR', 'name' => 'Тип обращения', 'type' => 'list', 'items' => [
        ''                  => '— любой —',
        'RESET_UID_REQUEST' => 'Запрос на сброс UID',
        'EMPTY'             => 'Обычное (без ошибки)',
        'UID_TAKEN'         => 'UID_TAKEN — уже активирован',
        'UID_LOCKED'        => 'UID_LOCKED — привязан к другому',
        'DEVICE_ROTATED'    => 'DEVICE_ROTATED — смена устройства',
        'BLOCKED'           => 'BLOCKED — заблокирован',
        'RATE_LIMITED'      => 'RATE_LIMITED — много попыток',
        'NO_RESET'          => 'Все (кроме «Запрос на сброс UID»)',
    ], 'filterable' => '', 'default' => true],
    ['id' => 'DATE_CREATED', 'name' => 'Дата создания', 'type' => 'date', 'filterable' => '', 'default' => true],
    ['id' => 'DATE_UPDATED', 'name' => 'Дата обновления', 'type' => 'date', 'filterable' => '', 'default' => true],
    ['id' => 'USER',     'name' => 'Пользователь (UID, ФИО, ник, компания)', 'type' => 'string', 'filterable' => '?', 'quickSearch' => '?', 'default' => true],
];

// ─── Fallback на старые ссылки из user_detail.php ───
// user_detail.php передаёт ?x5uid=<full_uid>, но не apply_filter/find_USER.
// Мапим x5uid в find_USER и принудительно применяем фильтр.
if (empty($_REQUEST['find_USER']) && empty($_REQUEST['USER'])
    && (!empty($_REQUEST['x5uid']) || !empty($_REQUEST['find_UID']))) {
    $candidate = trim((string)($_REQUEST['x5uid'] ?? $_REQUEST['find_UID'] ?? ''));
    if ($candidate !== '') {
        $_REQUEST['find_USER']    = $candidate;
        $_REQUEST['apply_filter'] = 'Y';
        $_REQUEST['set_filter']   = 'Y';
    }
}

// ─── Программная установка фильтра из URL ───
if (!empty($_REQUEST['apply_filter'])) {
    $presetFields = [];
    foreach ($filterFields as $ff) {
        $findKey = 'find_' . $ff['id'];
        if (!empty($_REQUEST[$findKey])) $presetFields[$findKey] = trim((string)$_REQUEST[$findKey]);
        if (!empty($_REQUEST[$findKey . '_from'])) $presetFields[$findKey . '_from'] = trim((string)$_REQUEST[$findKey . '_from']);
        if (!empty($_REQUEST[$findKey . '_to'])) $presetFields[$findKey . '_to'] = trim((string)$_REQUEST[$findKey . '_to']);
    }
    if ($presetFields) {
        $presetFields['FILTER_APPLIED'] = true;
        CUserOptions::SetOption('admin_filter', $sTableID . '_filter', $presetFields);
    }
} elseif (!empty($_REQUEST['del_filter'])) {
    CUserOptions::SetOption('admin_filter', $sTableID . '_filter', ['FILTER_APPLIED' => false]);
}

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

if (!function_exists('x5supFilterValue')) {
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
            if (isset($_REQUEST[$key])) {
                $v = $_REQUEST[$key]; if (is_array($v)) $v = reset($v);
                $v = trim((string)$v); if ($v !== '') return $v;
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

// ─── Сводка (без фильтра) ───
$summary = [
    'by_status'            => ['new' => 0, 'answered' => 0, 'closed' => 0],
    'by_source'            => ['app' => 0, 'app_unauth' => 0],
    'by_status_source'     => [],
    'by_auth_error'        => [],
    'by_auth_error_status' => [],
    'reset_pending'        => 0,
    'total'                => 0,
];
try {
    $conn = \Bitrix\Main\Application::getConnection();
    $rows = $conn->query(
        "SELECT UF_STATUS, UF_SOURCE, UF_AUTH_ERROR, COUNT(*) AS C
         FROM x5_pwa_support_tickets
         GROUP BY UF_STATUS, UF_SOURCE, UF_AUTH_ERROR"
    )->fetchAll();

    foreach ($rows as $r) {
        $st  = (string)($r['UF_STATUS'] ?? '');
        $src = (string)($r['UF_SOURCE'] ?? 'app');
        $ae  = (string)($r['UF_AUTH_ERROR'] ?? '');
        $c   = (int)$r['C'];
        $aeKey = $ae === '' ? '_EMPTY_' : $ae;

        $summary['total'] += $c;
        if (isset($summary['by_status'][$st])) $summary['by_status'][$st] += $c;
        if (isset($summary['by_source'][$src])) $summary['by_source'][$src] += $c;
        if (!isset($summary['by_status_source'][$st])) $summary['by_status_source'][$st] = ['app' => 0, 'app_unauth' => 0];
        if (isset($summary['by_status_source'][$st][$src])) $summary['by_status_source'][$st][$src] += $c;
        if (!isset($summary['by_auth_error'][$aeKey])) $summary['by_auth_error'][$aeKey] = 0;
        $summary['by_auth_error'][$aeKey] += $c;
        if (!isset($summary['by_auth_error_status'][$aeKey])) $summary['by_auth_error_status'][$aeKey] = ['new' => 0, 'answered' => 0, 'closed' => 0];
        if (isset($summary['by_auth_error_status'][$aeKey][$st])) $summary['by_auth_error_status'][$aeKey][$st] += $c;
    }

    $resetRow = $conn->query(
        "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
         WHERE UF_AUTH_ERROR = 'RESET_UID_REQUEST' AND UF_STATUS != 'closed'"
    )->fetch();
    $summary['reset_pending'] = (int)($resetRow['C'] ?? 0);
} catch (\Throwable $e) {}

// ─── URL-хелпер ───
$baseUrl = $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID;
$buildFilterUrl = function (array $params = []) use ($baseUrl): string {
    if (!$params) return $baseUrl . '&del_filter=Y';
    return $baseUrl . '&apply_filter=Y&set_filter=Y&' . http_build_query($params);
};

// ─── Данные ───
$page = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));

$perPageDefault = 50;
$perPage = (int)($_REQUEST['SIZEN_1'] ?? $_REQUEST['SIZEN'] ?? 0);
if ($perPage <= 0) {
    try { $perPage = (int)$lAdmin->GetNavSize(); } catch (\Throwable $e) { $perPage = 0; }
}
if ($perPage <= 0) $perPage = $perPageDefault;
if ($perPage > 500) $perPage = 500;

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

$arHeader = [
    ['id' => 'ID',             'content' => 'ID',           'sort' => 'ID',            'default' => true],
    ['id' => 'AUTH_ERROR',     'content' => 'Тип',                                       'default' => true],
    ['id' => 'UF_UPDATED_AT',  'content' => 'Обновлён',     'sort' => 'UF_UPDATED_AT', 'default' => true],
    ['id' => 'USER',           'content' => 'Пользователь',                              'default' => true],
    ['id' => 'UF_SOURCE',      'content' => 'Источник',                                  'default' => true],
    ['id' => 'UF_SUBJECT',     'content' => 'Тема',                                      'default' => true],
    ['id' => 'UF_STATUS',      'content' => 'Статус',       'sort' => 'UF_STATUS',     'default' => true],
    ['id' => 'UF_MESSAGES_COUNT','content' => 'Сообщений',                              'default' => true],
    ['id' => 'UF_CREATED_AT',  'content' => 'Создан',       'sort' => 'UF_CREATED_AT', 'default' => false],
    ['id' => 'ACTIONS',        'content' => 'Действия',                                  'default' => true],
];
$lAdmin->AddHeaders($arHeader);

$statusLabels = [
    'new'      => ['Новый', '#fdf3cf', '#8a6b00'],
    'answered' => ['Отвечен', '#e6f5d8', '#3a7d1c'],
    'closed'   => ['Закрыт', '#f0f2f5', '#666'],
];

$authErrorLabels = [
    '' => 'Обычное', 'RESET_UID_REQUEST' => 'Сброс UID',
    'UID_TAKEN' => 'UID_TAKEN', 'UID_LOCKED' => 'UID_LOCKED',
    'DEVICE_ROTATED' => 'DEVICE_ROTATED', 'BLOCKED' => 'BLOCKED',
    'RATE_LIMITED' => 'RATE_LIMITED',
];

foreach ($data['rows'] as $t) {
    $ticketId  = (int)$t['ID'];
    $uid       = (string)$t['UID'];
    $subject   = (string)$t['SUBJECT'];
    $st        = (string)$t['STATUS'];
    $stInfo    = $statusLabels[$st] ?? $statusLabels['new'];
    $ae        = (string)($t['AUTH_ERROR'] ?? '');
    $isReset   = ($ae === 'RESET_UID_REQUEST');

    $createdAt = $t['CREATED_AT'] > 0 ? date('d.m.Y H:i', (int)($t['CREATED_AT'] / 1000)) : '—';
    $updatedAt = $t['UPDATED_AT'] > 0 ? date('d.m.Y H:i', (int)($t['UPDATED_AT'] / 1000)) : '—';

    $userLabel = trim((string)($t['NICKNAME'] ?: $t['FIO']));
    if ($userLabel === '') $userLabel = substr($uid, 0, 10) . '…';
    $userHtml = '<b>' . htmlspecialcharsbx($userLabel) . '</b>';
    if ($uid !== '') {
        $userHtml .= '<br><span style="font-family:monospace;font-size:10px;color:#888">'
                   . htmlspecialcharsbx(substr($uid, 0, 16)) . '…</span>';
    }

    $arRes = [
        'ID' => $ticketId, 'AUTH_ERROR' => $ae,
        'UF_UPDATED_AT' => $updatedAt, 'USER' => $userLabel,
        'UF_SOURCE' => (string)$t['SOURCE'], 'UF_SUBJECT' => $subject,
        'UF_STATUS' => $st, 'UF_MESSAGES_COUNT' => (int)$t['MESSAGES_COUNT'],
        'UF_CREATED_AT' => $createdAt,
    ];

    $row = $lAdmin->AddRow($ticketId, $arRes);
    $detailUrl = 'x5_pwa_support_detail.php?lang=' . LANGUAGE_ID . '&ID=' . $ticketId;

    $row->AddViewField('ID', '<a href="' . $detailUrl . '"><b>' . $ticketId . '</b></a>');
    $row->AddViewField('UF_SUBJECT', '<a href="' . $detailUrl . '">' . htmlspecialcharsbx($subject) . '</a>');

    if ($isReset) {
        $row->AddViewField('AUTH_ERROR',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#fdf3cf;color:#8a6b00;font-size:11px;font-weight:600;" title="Запрос на сброс привязки UID">RESET UID</span>');
    } elseif ($ae !== '') {
        $row->AddViewField('AUTH_ERROR',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f5e0e0;color:#a52020;font-size:11px;font-weight:600;">'
            . htmlspecialcharsbx($ae) . '</span>');
    } else {
        $row->AddViewField('AUTH_ERROR', '<span style="color:#888;font-size:11px">Обычное</span>');
    }

    $row->AddViewField('USER', $userHtml);
    $row->AddViewField('UF_SOURCE', ($t['SOURCE'] === 'app_unauth') ? 'Гость' : 'Авторизован');
    $row->AddViewField('UF_STATUS',
        '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:'
        . $stInfo[1] . ';color:' . $stInfo[2] . ';font-size:11px">'
        . htmlspecialcharsbx($stInfo[0]) . '</span>');

    $row->AddActions([
        ['ICON' => 'view', 'TEXT' => 'Открыть', 'DEFAULT' => true, 'LINK' => $detailUrl],
    ]);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->CheckListMode();

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<?php
$hasAnyActiveFilter = ($status !== '' || $source !== '' || $authError !== ''
    || $userQuery !== '' || $dateCreatedFrom !== '' || $dateCreatedTo !== ''
    || $dateUpdatedFrom !== '' || $dateUpdatedTo !== '');

$statusRows = ['new' => 'Новые', 'answered' => 'Отвечено', 'closed' => 'Закрыто'];
$sourceCols = ['app' => 'Авторизованные', 'app_unauth' => 'Гости'];
$authErrorRows = [
    '_EMPTY_' => 'Обычное (без ошибки)', 'RESET_UID_REQUEST' => 'Запрос на сброс UID',
    'UID_TAKEN' => 'UID_TAKEN', 'UID_LOCKED' => 'UID_LOCKED',
    'DEVICE_ROTATED' => 'DEVICE_ROTATED', 'BLOCKED' => 'BLOCKED',
    'RATE_LIMITED' => 'RATE_LIMITED',
];
$knownAe = array_keys($authErrorRows);
$otherAeTotal = 0;
foreach ($summary['by_auth_error'] as $aeKey => $cnt) {
    if (!in_array($aeKey, $knownAe, true)) $otherAeTotal += (int)$cnt;
}
?>

<div style="background:#fff;padding:16px 20px;border:1px solid #e2e5e9;margin-bottom:14px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div style="font-size:14px;font-weight:600;color:#2c3e50;">
            Сводка по обращениям
            <span style="font-weight:400;color:#888;font-size:12px;margin-left:8px;">всего: <?= (int)$summary['total'] ?></span>
        </div>
        <?php if ($hasAnyActiveFilter): ?>
            <a href="<?= htmlspecialcharsbx($baseUrl) ?>"
               style="font-size:12px;color:#1e6bcc;text-decoration:none;">✕ Сбросить фильтр</a>
        <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
        <!-- Левая матрица -->
        <div>
            <div style="font-size:12px;color:#666;text-transform:uppercase;margin-bottom:8px;">По статусу и источнику</div>
            <table style="border-collapse:collapse;font-size:13px;width:100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:6px 12px 6px 0;font-weight:500;color:#666;border-bottom:1px solid #e2e5e9;"></th>
                        <?php foreach ($sourceCols as $srcKey => $srcLabel): ?>
                            <th style="text-align:right;padding:6px 12px;font-weight:500;color:#666;border-bottom:1px solid #e2e5e9;">
                                <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_SOURCE' => $srcKey])) ?>"
                                   style="color:#555;text-decoration:none;"><?= htmlspecialcharsbx($srcLabel) ?></a>
                            </th>
                        <?php endforeach; ?>
                        <th style="text-align:right;padding:6px 0 6px 12px;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e5e9;">Всего</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statusRows as $stKey => $stLabel): ?>
                        <?php
                        $appCount   = (int)($summary['by_status_source'][$stKey]['app']        ?? 0);
                        $guestCount = (int)($summary['by_status_source'][$stKey]['app_unauth'] ?? 0);
                        $rowTotal   = $appCount + $guestCount;
                        ?>
                        <tr>
                            <td style="padding:6px 12px 6px 0;color:#555;white-space:nowrap;">
                                <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_STATUS' => $stKey])) ?>"
                                   style="color:#555;text-decoration:none;"><?= htmlspecialcharsbx($stLabel) ?></a>
                            </td>
                            <?php foreach ($sourceCols as $srcKey => $srcLabel): ?>
                                <?php $cnt = (int)($summary['by_status_source'][$stKey][$srcKey] ?? 0); ?>
                                <td style="text-align:right;padding:6px 12px;font-family:monospace;">
                                    <?php if ($cnt > 0): ?>
                                        <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_STATUS' => $stKey, 'find_SOURCE' => $srcKey])) ?>"
                                           style="color:#1e6bcc;text-decoration:none;font-weight:600;"><?= $cnt ?></a>
                                    <?php else: ?><span style="color:#ccc">0</span><?php endif ?>
                                </td>
                            <?php endforeach; ?>
                            <td style="text-align:right;padding:6px 0 6px 12px;font-family:monospace;font-weight:600;">
                                <?php if ($rowTotal > 0): ?>
                                    <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_STATUS' => $stKey])) ?>"
                                       style="color:#2c3e50;text-decoration:none;"><?= $rowTotal ?></a>
                                <?php else: ?><span style="color:#ccc">0</span><?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Правая матрица -->
        <div>
            <div style="font-size:12px;color:#666;text-transform:uppercase;margin-bottom:8px;">По типу обращения и статусу</div>
            <table style="border-collapse:collapse;font-size:13px;width:100%;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:6px 12px 6px 0;font-weight:500;color:#666;border-bottom:1px solid #e2e5e9;">Тип</th>
                        <?php foreach ($statusRows as $stKey => $stLabel): ?>
                            <th style="text-align:right;padding:6px 12px;font-weight:500;color:#666;border-bottom:1px solid #e2e5e9;">
                                <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_STATUS' => $stKey])) ?>"
                                   style="color:#555;text-decoration:none;"><?= htmlspecialcharsbx($stLabel) ?></a>
                            </th>
                        <?php endforeach; ?>
                        <th style="text-align:right;padding:6px 0 6px 12px;font-weight:600;color:#2c3e50;border-bottom:1px solid #e2e5e9;">Всего</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($authErrorRows as $aeKey => $aeLabel): ?>
                        <?php $rowTotal = (int)($summary['by_auth_error'][$aeKey] ?? 0); if ($rowTotal <= 0) continue; ?>
                        <?php $filterValue = $aeKey === '_EMPTY_' ? 'EMPTY' : $aeKey; ?>
                        <tr>
                            <td style="padding:6px 12px 6px 0;color:#555;white-space:nowrap;">
                                <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_AUTH_ERROR' => $filterValue])) ?>"
                                   style="color:#555;text-decoration:none;"><?= htmlspecialcharsbx($aeLabel) ?></a>
                            </td>
                            <?php foreach ($statusRows as $stKey => $stLabel): ?>
                                <?php $cnt = (int)($summary['by_auth_error_status'][$aeKey][$stKey] ?? 0); ?>
                                <td style="text-align:right;padding:6px 12px;font-family:monospace;">
                                    <?php if ($cnt > 0): ?>
                                        <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_AUTH_ERROR' => $filterValue, 'find_STATUS' => $stKey])) ?>"
                                           style="color:#1e6bcc;text-decoration:none;font-weight:600;"><?= $cnt ?></a>
                                    <?php else: ?><span style="color:#ccc">0</span><?php endif ?>
                                </td>
                            <?php endforeach; ?>
                            <td style="text-align:right;padding:6px 0 6px 12px;font-family:monospace;font-weight:600;">
                                <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_AUTH_ERROR' => $filterValue])) ?>"
                                   style="color:#2c3e50;text-decoration:none;"><?= $rowTotal ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($summary['reset_pending'] > 0): ?>
        <div style="margin-top:14px;padding:10px 14px;background:#fff4e0;border-left:4px solid #d9a000;border-radius:4px;font-size:13px;">
            <b>⚠ Запросы на сброс UID:</b>
            <a href="<?= htmlspecialcharsbx($buildFilterUrl(['find_AUTH_ERROR' => 'RESET_UID_REQUEST', 'find_STATUS' => 'new'])) ?>"
               style="color:#8a4b00;font-weight:600;text-decoration:underline;"><?= (int)$summary['reset_pending'] ?> не закрыто</a>
            — требуют решения.
        </div>
    <?php endif; ?>
</div>

<?php
$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';