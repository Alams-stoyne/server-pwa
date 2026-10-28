<?php
/**
 * Список пользователей PWA (CAdminUiList).
 *
 * Колонки «Лекций / Заданий / Привычек / Шагов / Событий» — ЗА ПЕРИОД.
 * Total-версии («* (всего)») включены через «Настроить» / скрытые колонки.
 * Эффективность/Собрано/Недостаёт — за период и за сегодня (в шапке).
 * Баланс — накопительный, за всё время.
 *
 * Согласие ПД: фильтр, колонка, предупреждение в шапке.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Пользователи');

// ─────────────────────────────────────────────────────────
// Сортировка
// ─────────────────────────────────────────────────────────
$sTableID = 'x5_pwa_users';
$oSort    = new CAdminUiSorting($sTableID, 'UF_LAST_SEEN_AT', 'desc');
$by       = mb_strtoupper($oSort->getField());
$order    = mb_strtoupper($oSort->getOrder());

$lAdmin = new CAdminUiList($sTableID, $oSort);

// ─────────────────────────────────────────────────────────
// Фильтр
// ─────────────────────────────────────────────────────────
$filterFields = [
    [
        'id'          => 'SEARCH',
        'name'        => 'Поиск',
        'type'        => 'string',
        'filterable'  => '?',
        'quickSearch' => '?',
        'default'     => true,
    ],
    [
        'id'         => 'CONSENT',
        'name'       => 'Согласие ПД',
        'type'       => 'list',
        'items'      => [
            'Y'     => 'Дано',
            'N'     => 'Отказ',
            'NOT_Y' => 'Не дано (любое)',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'THEME',
        'name'       => 'Направление',
        'type'       => 'list',
        'items'      => [
            'energy'     => 'energy',
            'antistress' => 'antistress',
            'body'       => 'body',
            'balance'    => 'balance',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'BLOCKED',
        'name'       => 'Статус',
        'type'       => 'list',
        'items'      => [
            'N' => 'Активные',
            'Y' => 'Заблокированные',
        ],
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'LAST_SEEN_AT',
        'name'       => 'Активность',
        'type'       => 'date',
        'filterable' => '',
        'default'    => true,
    ],
    [
        'id'         => 'HAS_SUBSCRIPTION',
        'name'       => 'Push-подписка',
        'type'       => 'list',
        'items'      => [
            'Y' => 'Есть',
            'N' => 'Нет',
        ],
        'filterable' => '',
        'default'    => true,
    ],
];

$arFilter = [];
$lAdmin->AddFilter($filterFields, $arFilter);

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

$search      = x5pwaFilterValue($arFilter, 'SEARCH');
$theme       = x5pwaFilterValue($arFilter, 'THEME');
$blocked     = x5pwaFilterValue($arFilter, 'BLOCKED');
$consent     = x5pwaFilterValue($arFilter, 'CONSENT');
if (!in_array($consent, ['Y', 'N', 'NOT_Y'], true)) $consent = '';
$activeFrom  = x5pwaFilterValue($arFilter, 'LAST_SEEN_AT_from');
$activeTo    = x5pwaFilterValue($arFilter, 'LAST_SEEN_AT_to');
$withPushRaw = x5pwaFilterValue($arFilter, 'HAS_SUBSCRIPTION');
$withPush = in_array($withPushRaw, ['Y', 'N'], true) ? $withPushRaw : '';

// ─────────────────────────────────────────────────────────
// Размер страницы
// ─────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────
// Групповые действия
// ─────────────────────────────────────────────────────────
if ($arIDs = $lAdmin->GroupAction()) {
    $action = $lAdmin->GetAction();

    if (in_array($action, ['block', 'unblock', 'delete'], true)) {
        if ($lAdmin->IsGroupActionToAll()) {
            $all = \X5\Pwa\StatsBuilder::listUsers([
                'search'      => $search,
                'consent'     => $consent,
                'theme'       => $theme,
                'blocked'     => $blocked,
                'active_from' => $activeFrom,
                'active_to'   => $activeTo,
                'withPush'    => $withPush,
                'noPaging'    => true,
                'perPage'     => 0,
            ]);
            $arIDs = array_column($all['rows'], 'UID');
        }

        $processed = 0;
        foreach ($arIDs as $uid) {
            $uid = (string)$uid;
            if ($uid === '') continue;

            $ok = false;
            switch ($action) {
                case 'block':
                    $ok = \X5\Pwa\Storage::blockUser($uid, 'Заблокирован из админки');
                    break;
                case 'unblock':
                    $ok = \X5\Pwa\Storage::unblockUser($uid);
                    break;
                case 'delete':
                    \X5\Pwa\Storage::deleteUser($uid);
                    $ok = true;
                    break;
            }

            if ($ok) $processed++;
            else {
                $lAdmin->AddGroupError(
                    'Ошибка обработки ' . substr($uid, 0, 12) . '…',
                    $uid
                );
            }
        }

        if (!$lAdmin->hasGroupErrors()) {
            LocalRedirect(
                $APPLICATION->GetCurPage()
                . '?lang=' . LANGUAGE_ID
                . '&SIZEN_1=' . $perPage
            );
        }
    }
}

// ─────────────────────────────────────────────────────────
// Пагинация и выборка
// ─────────────────────────────────────────────────────────
$page = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));

$params = [
    'search'      => $search,
    'consent'     => $consent,
    'theme'       => $theme,
    'blocked'     => $blocked,
    'active_from' => $activeFrom,
    'active_to'   => $activeTo,
    'withPush'    => $withPush,
    'sort'        => $by,
    'sortDir'     => $order,
    'page'        => $page,
    'perPage'     => $perPage,
];

$data = \X5\Pwa\StatsBuilder::listUsers($params);

// Период (для подписи в шапке и колонках)
[$periodFrom, $periodTo] = \X5\Pwa\StatsBuilder::getEffectivePeriod();
$todayLabel = date('Y-m-d');

// Агрегат по фильтру (без пагинации)
$agg = \X5\Pwa\StatsBuilder::getUsersAggregate([
    'search'      => $search,
    'consent'     => $consent,
    'theme'       => $theme,
    'blocked'     => $blocked,
    'active_from' => $activeFrom,
    'active_to'   => $activeTo,
    'withPush'    => $withPush,
]);

// Сводка по согласиям (для предупреждения)
$consentStats = \X5\Pwa\StatsBuilder::getConsentStats();
$dirtyBalance = (int)$consentStats['dirty_balance'];
$dirtyUsers   = (int)$consentStats['dirty_users'];
$dirtyWithBal = (int)$consentStats['dirty_with_balance'];

$dbRes = new CDBResult();
$dbRes->InitFromArray(array_values($data['rows']));

$rsData = new CAdminUiResult($dbRes, $sTableID);
$rsData->NavNum         = 1;
$rsData->NavPageSize    = $perPage;
$rsData->NavPageNomer   = $page;
$rsData->NavPageCount   = max(1, (int)$data['pages']);
$rsData->NavRecordCount = (int)$data['total'];

$lAdmin->SetNavigationParams($rsData, ['nPageSize' => $perPage]);

// ─────────────────────────────────────────────────────────
// Колонки
// ─────────────────────────────────────────────────────────
$arHeader = [
    ['id' => 'STATUS',           'content' => 'Статус',          'default' => true],
    ['id' => 'CONSENT',          'content' => 'Согласие ПД',     'default' => true],
    ['id' => 'FIO',              'content' => 'Ф.И.О',           'sort' => 'FIO',            'default' => true],
    ['id' => 'NICKNAME',         'content' => 'Никнейм',         'sort' => 'NICKNAME',       'default' => true],
    ['id' => 'WORKPLACE',        'content' => 'Место работы',    'sort' => 'WORKPLACE',      'default' => true],
    ['id' => 'THEME',            'content' => 'Направление',     'sort' => 'THEME',          'default' => true],
    ['id' => 'LAST_SEEN_AT',     'content' => 'Активность',      'sort' => 'LAST_SEEN_AT',   'default' => true],
    ['id' => 'HAS_SUBSCRIPTION', 'content' => 'Push',                                         'default' => true],
    ['id' => 'EFFICIENCY_ALL',   'content' => 'Эффективность',   'sort' => 'EFFICIENCY_ALL', 'default' => true],
    ['id' => 'EARNED_ALL',       'content' => 'Собрано',         'sort' => 'EARNED_ALL',     'default' => true],
    ['id' => 'MISSING_ALL',      'content' => 'Недостаёт',       'sort' => 'MISSING_ALL',    'default' => true],
    ['id' => 'LECTURES_READ',    'content' => 'Лекций (период)',    'default' => true],
    ['id' => 'TASKS_DONE',       'content' => 'Заданий (период)',   'default' => true],
    ['id' => 'HABITS_DONE',      'content' => 'Привычек (период)',  'default' => true],
    ['id' => 'STEPS_CONFIRMED',  'content' => 'Шагов (период)',     'default' => false],
    ['id' => 'EVENTS_CONFIRMED', 'content' => 'Событий (период)',   'default' => false],
    ['id' => 'LECTURES_READ_TOTAL',  'content' => 'Лекций (всего)',    'default' => false],
    ['id' => 'TASKS_DONE_TOTAL',     'content' => 'Заданий (всего)',   'default' => false],
    ['id' => 'HABITS_DONE_TOTAL',    'content' => 'Привычек (всего)',  'default' => false],
    ['id' => 'MAX_ALL',              'content' => 'Возможно (период)', 'default' => false],
    ['id' => 'EFFICIENCY_DAY',       'content' => 'Эфф. сегодня',      'default' => false],
    ['id' => 'EARNED_DAY',           'content' => 'Сегодня собрано',   'default' => false],
    ['id' => 'MAX_DAY',              'content' => 'Сегодня возможно',  'default' => false],
    ['id' => 'REWARD_BALANCE',       'content' => 'Баланс (всего)',    'default' => false],
    ['id' => 'UID',                  'content' => 'UID',      'sort' => 'UID',       'default' => false],
    ['id' => 'CREATED_AT',           'content' => 'Создан',   'sort' => 'CREATED_AT','default' => false],
];

$lAdmin->AddHeaders($arHeader);
$lAdmin->AddVisibleHeaderColumn('STEPS_CONFIRMED');
$lAdmin->AddVisibleHeaderColumn('EVENTS_CONFIRMED');
$lAdmin->AddVisibleHeaderColumn('LECTURES_READ_TOTAL');
$lAdmin->AddVisibleHeaderColumn('TASKS_DONE_TOTAL');
$lAdmin->AddVisibleHeaderColumn('HABITS_DONE_TOTAL');
$lAdmin->AddVisibleHeaderColumn('MAX_ALL');
$lAdmin->AddVisibleHeaderColumn('EFFICIENCY_DAY');
$lAdmin->AddVisibleHeaderColumn('EARNED_DAY');
$lAdmin->AddVisibleHeaderColumn('MAX_DAY');
$lAdmin->AddVisibleHeaderColumn('REWARD_BALANCE');
$lAdmin->AddVisibleHeaderColumn('UID');
$lAdmin->AddVisibleHeaderColumn('CREATED_AT');

$formatDate = function (?int $ms): string {
    if (!$ms) return '—';
    return date('d.m.Y H:i', (int)($ms / 1000));
};

$renderEfficiencyBar = function (float $pct): string {
    $p = max(0.0, min(100.0, $pct));
    $color = $p < 30 ? '#e04a4a' : ($p < 70 ? '#f5a623' : '#3a7d1c');
    $text  = number_format($p, 1, '.', '') . '%';
    return '<div style="display:flex;align-items:center;gap:8px;min-width:120px">'
        . '<div style="flex:1;height:6px;background:#e2e5e9;border-radius:3px;overflow:hidden">'
        . '<div style="width:' . $p . '%;height:100%;background:' . $color . '"></div>'
        . '</div>'
        . '<b style="color:' . $color . ';font-family:monospace">' . $text . '</b>'
        . '</div>';
};

foreach ($data['rows'] as $u) {
    $uid     = (string)$u['UID'];
    $blocked = !empty($u['BLOCKED']);

    $arRes = [
        'UID'              => $uid,
        'STATUS'           => $blocked ? 'Y' : 'N',
        'CONSENT'          => (string)($u['CONSENT'] ?? ''),
        'FIO'              => (string)$u['FIO'],
        'NICKNAME'         => (string)$u['NICKNAME'],
        'WORKPLACE'        => (string)$u['WORKPLACE'],
        'THEME'            => (string)$u['THEME'],
        'LAST_SEEN_AT'     => $formatDate((int)$u['LAST_ACTIVITY_AT']),
        'CREATED_AT'       => $formatDate((int)$u['CREATED_AT']),
        'HAS_SUBSCRIPTION' => $u['HAS_SUBSCRIPTION'] ? 'Y' : 'N',
        'EFFICIENCY_ALL'   => (float)$u['EFFICIENCY_ALL'],
        'EFFICIENCY_DAY'   => (float)$u['EFFICIENCY_DAY'],
        'EARNED_ALL'       => (int)$u['EARNED_ALL'],
        'MAX_ALL'          => (int)$u['MAX_ALL'],
        'MISSING_ALL'      => (int)$u['MISSING_ALL'],
        'EARNED_DAY'       => (int)$u['EARNED_DAY'],
        'MAX_DAY'          => (int)$u['MAX_DAY'],
        'REWARD_BALANCE'   => (int)$u['REWARD_BALANCE'],
        'LECTURES_READ'    => (int)$u['LECTURES_READ'],
        'TASKS_DONE'       => (int)$u['TASKS_DONE'],
        'HABITS_DONE'      => (int)$u['HABITS_DONE'],
        'STEPS_CONFIRMED'  => (int)$u['STEPS_CONFIRMED'],
        'EVENTS_CONFIRMED' => (int)$u['EVENTS_CONFIRMED'],
        'LECTURES_READ_TOTAL' => (int)$u['LECTURES_READ_TOTAL'],
        'TASKS_DONE_TOTAL'    => (int)$u['TASKS_DONE_TOTAL'],
        'HABITS_DONE_TOTAL'   => (int)$u['HABITS_DONE_TOTAL'],
    ];

    $row = $lAdmin->AddRow($uid, $arRes);

    $row->AddViewField('STATUS',
        $blocked
            ? '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f5e0e0;color:#a52020;font-size:11px"'
              . ($u['BLOCK_REASON'] !== ''
                  ? ' title="' . htmlspecialcharsbx($u['BLOCK_REASON']) . '"'
                  : '')
              . '>Заблокирован</span>'
            : '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e6f5d8;color:#3a7d1c;font-size:11px">Активен</span>');

    $consentVal = (string)($u['CONSENT'] ?? '');
    $consentAt  = (int)($u['CONSENT_AT'] ?? 0);
    $consentTitle = $consentAt > 0
        ? 'Согласие: ' . date('d.m.Y H:i', (int)($consentAt / 1000))
        : ($consentVal === 'N' ? 'Пользователь нажал «Не согласен»' : 'Экран согласия не открывался или не завершён');

    if ($consentVal === 'Y') {
        $row->AddViewField('CONSENT',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e6f5d8;color:#3a7d1c;font-size:11px" title="'
            . htmlspecialcharsbx($consentTitle) . '">Дано</span>');
    } elseif ($consentVal === 'N') {
        $row->AddViewField('CONSENT',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f5e0e0;color:#a52020;font-size:11px" title="'
            . htmlspecialcharsbx($consentTitle) . '">Отказ</span>');
    } else {
        $row->AddViewField('CONSENT',
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#f0f0f0;color:#666;font-size:11px" title="'
            . htmlspecialcharsbx($consentTitle) . '">Не дано</span>');
    }

    $row->AddViewField('FIO',
        $arRes['FIO'] !== '' ? htmlspecialcharsbx($arRes['FIO']) : '<span style="color:#aaa">—</span>');
    $row->AddViewField('NICKNAME',
        $arRes['NICKNAME'] !== '' ? htmlspecialcharsbx($arRes['NICKNAME']) : '<span style="color:#aaa">—</span>');
    $row->AddViewField('WORKPLACE',
        $arRes['WORKPLACE'] !== '' ? htmlspecialcharsbx($arRes['WORKPLACE']) : '<span style="color:#aaa">—</span>');
    $row->AddViewField('THEME',
        $arRes['THEME'] !== ''
            ? '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e0efff;color:#1e6bcc;font-size:11px">'
              . htmlspecialcharsbx($arRes['THEME']) . '</span>'
            : '<span style="color:#aaa">—</span>');
    $row->AddViewField('HAS_SUBSCRIPTION',
        $arRes['HAS_SUBSCRIPTION'] === 'Y'
            ? '<span style="color:#3a7d1c">есть</span>'
            : '<span style="color:#a52020">нет</span>');
    $row->AddViewField('UID',
        '<span style="font-family:monospace;font-size:11px">' . htmlspecialcharsbx($uid) . '</span>');

    $row->AddViewField('EFFICIENCY_ALL', $renderEfficiencyBar((float)$u['EFFICIENCY_ALL']));
    $row->AddViewField('EFFICIENCY_DAY', $renderEfficiencyBar((float)$u['EFFICIENCY_DAY']));

    $row->AddViewField('EARNED_ALL',
        '<b style="color:#3a7d1c;font-family:monospace">' . (int)$u['EARNED_ALL'] . '</b>');
    $row->AddViewField('MAX_ALL',
        '<span style="color:#555;font-family:monospace">' . (int)$u['MAX_ALL'] . '</span>');
    $row->AddViewField('MISSING_ALL',
        ((int)$u['MISSING_ALL'] > 0
            ? '<b style="color:#c0392b;font-family:monospace">−' . (int)$u['MISSING_ALL'] . '</b>'
            : '<span style="color:#888;font-family:monospace">0</span>'));
    $row->AddViewField('EARNED_DAY',
        '<span style="color:#3a7d1c;font-family:monospace">' . (int)$u['EARNED_DAY'] . '</span>');
    $row->AddViewField('MAX_DAY',
        '<span style="color:#555;font-family:monospace">' . (int)$u['MAX_DAY'] . '</span>');
    $row->AddViewField('REWARD_BALANCE',
        '<b style="font-family:monospace">' . (int)$u['REWARD_BALANCE'] . '</b>');

    $actions = [
        [
            'ICON'    => 'view',
            'TEXT'    => 'Подробно',
            'DEFAULT' => true,
            'LINK'    => 'x5_pwa_user_detail.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid),
        ],
        [
            'ICON' => '',
            'TEXT' => 'Отправить PUSH',
            'LINK' => 'x5_pwa_push.php?lang=' . LANGUAGE_ID . '&uid=' . urlencode($uid),
        ],
        [
            'ICON'    => '',
            'TEXT'    => 'Скопировать UID',
            'ONCLICK' => "x5pwaCopyUid('" . \CUtil::JSEscape($uid) . "'); return false;",
        ],
    ];

    if ($blocked) {
        $actions[] = [
            'ICON'    => '',
            'TEXT'    => 'Разблокировать',
            'ONCLICK' => "x5pwaSingleAction('" . \CUtil::JSEscape($uid) . "', 'unblock'); return false;",
        ];
    } else {
        $actions[] = [
            'ICON'    => '',
            'TEXT'    => 'Заблокировать',
            'ONCLICK' => "x5pwaBlockPrompt('" . \CUtil::JSEscape($uid) . "'); return false;",
        ];
    }

    $actions[] = [
        'ICON'    => 'delete',
        'TEXT'    => 'Удалить полностью',
        'ONCLICK' => "x5pwaDeletePrompt('" . \CUtil::JSEscape($uid) . "'); return false;",
    ];

    $row->AddActions($actions);
}

$lAdmin->AddFooter([
    ['title' => 'Всего', 'value' => (int)$data['total']],
    ['counter' => true, 'title' => 'Выделено', 'value' => '0'],
]);

$lAdmin->AddGroupActionTable([
    'block' => [
        'type'   => 'button',
        'name'   => 'Заблокировать',
        'value'  => 'block',
        'action' => "x5pwaGroupAction('block');",
    ],
    'unblock' => [
        'type'   => 'button',
        'name'   => 'Разблокировать',
        'value'  => 'unblock',
        'action' => "x5pwaGroupAction('unblock');",
    ],
    'delete' => [
        'type'   => 'button',
        'name'   => 'Удалить полностью',
        'value'  => 'delete',
        'action' => "x5pwaGroupAction('delete');",
    ],
]);

$lAdmin->CheckListMode();

$exportUrl = 'x5_pwa_users_export.php?lang=' . LANGUAGE_ID . '&' . http_build_query(array_filter([
    'SEARCH'            => $search,
    'CONSENT'           => $consent,
    'THEME'             => $theme,
    'BLOCKED'           => $blocked,
    'LAST_SEEN_AT_from' => $activeFrom,
    'LAST_SEEN_AT_to'   => $activeTo,
    'HAS_SUBSCRIPTION'  => $withPush,
    'by'                => $by,
    'order'             => $order,
], fn($v) => $v !== '' && $v !== null));

$lAdmin->SetContextMenu([
    [
        'TEXT'       => 'Экспорт в CSV',
        'LINK'       => $exportUrl,
        'ICON'       => 'btn_download',
        'LINK_PARAM' => '',
    ],
]);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<?php if ($dirtyBalance > 0 || $dirtyWithBal > 0): ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message">
            <b>Найдены пользователи без согласия на обработку ПД с сохранёнными данными.</b><br>
            Всего: <b><?= number_format($dirtyUsers, 0, '', ' ') ?></b> польз.
            (из них <b><?= number_format($dirtyWithBal, 0, '', ' ') ?></b> с ненулевым балансом).
            Сумма баланса без согласия: <b><?= number_format($dirtyBalance, 0, '', ' ') ?></b> яблок.
            <br><br>
            Такие пользователи <b>не участвуют</b> в агрегатах статистики и командной эффективности
            (фильтр в <code>getActiveUidsForStats()</code>).<br>
            Проверить по фильтру «Согласие ПД → Не дано (любое)».<br>
            Управление: либо очистить их данные через <code>tools/purge_refused_consent.php</code>,
            либо оставить как есть (данные скрыты в приложении, начисления не производятся).
        </div>
    </div>
<?php endif; ?>

<div style="background:#fff;padding:14px 18px;border:1px solid #e2e5e9;margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px">
        <div>
            <div style="font-size:11px;color:#666;text-transform:uppercase">Всего пользователей</div>
            <div style="font-size:22px;font-weight:600;color:#2c3e50"><?= (int)$agg['total'] ?></div>
            <div style="font-size:10px;color:#888;margin-top:2px">только с согласием ПД</div>
        </div>
        <div>
            <div style="font-size:11px;color:#666;text-transform:uppercase">Собрано за период</div>
            <div style="font-size:22px;font-weight:600;color:#3a7d1c"><?= number_format((int)$agg['earned_sum'], 0, '', ' ') ?></div>
        </div>
        <div>
            <div style="font-size:11px;color:#666;text-transform:uppercase">Возможно за период</div>
            <div style="font-size:22px;font-weight:600;color:#555"><?= number_format((int)$agg['max_sum'], 0, '', ' ') ?></div>
        </div>
        <div>
            <div style="font-size:11px;color:#666;text-transform:uppercase">Недостаёт</div>
            <div style="font-size:22px;font-weight:600;color:#c0392b"><?= number_format((int)$agg['missing_sum'], 0, '', ' ') ?></div>
        </div>
        <div>
            <div style="font-size:11px;color:#666;text-transform:uppercase">Средняя эффективность</div>
            <div style="font-size:22px;font-weight:600;color:#1e6bcc">
                <?= number_format((float)$agg['avg_eff'], 1, '.', '') ?>%
            </div>
        </div>
    </div>

    <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #d6d9de">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
            <div>
                <div style="font-size:11px;color:#666;text-transform:uppercase">Собрано сегодня</div>
                <div style="font-size:22px;font-weight:600;color:#3a7d1c"><?= number_format((int)$agg['earned_day_sum'], 0, '', ' ') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#666;text-transform:uppercase">Возможно сегодня</div>
                <div style="font-size:22px;font-weight:600;color:#555"><?= number_format((int)$agg['max_day_sum'], 0, '', ' ') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#666;text-transform:uppercase">Недостаёт сегодня</div>
                <div style="font-size:22px;font-weight:600;color:#c0392b"><?= number_format((int)$agg['missing_day_sum'], 0, '', ' ') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#666;text-transform:uppercase">Средняя эффективность сегодня</div>
                <div style="font-size:22px;font-weight:600;color:#1e6bcc">
                    <?= number_format((float)$agg['avg_eff_day'], 1, '.', '') ?>%
                </div>
            </div>
        </div>
    </div>

    <div style="font-size:11px;color:#888;margin-top:10px">
        За период: <b><?= htmlspecialcharsbx($periodFrom) ?></b> — <b><?= htmlspecialcharsbx($periodTo) ?></b>
        · Сегодня: <b><?= htmlspecialcharsbx($todayLabel) ?></b> (независимо от периода).
        Колонки «Лекций / Заданий / Привычек (период)» — за период.
        Total-версии («* (всего)») включаются через «Настроить».
        «Баланс» — накопительный, за всё время.
        В агрегате участвуют только пользователи с выбранной темой и согласием на ПД
        (пользователи без согласия не искажают статистику).
    </div>
</div>

<script>
    function x5pwaCopyUid(uid) {
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

    function x5pwaGetListForm() {
        return document.getElementById('form_<?= \CUtil::JSEscape($sTableID) ?>')
            || document.querySelector('form[name="form_<?= \CUtil::JSEscape($sTableID) ?>"]');
    }

    function x5pwaSubmitAction(action) {
        var form = x5pwaGetListForm();
        if (!form) { alert('Форма не найдена'); return; }

        var input = form.querySelector('input[name="action"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            form.appendChild(input);
        }
        input.value = action;

        var checked = form.querySelectorAll('input[name="ID[]"]:checked');
        if (!checked.length) { alert('Выберите хотя бы одного пользователя'); return; }

        form.submit();
    }

    function x5pwaGroupAction(action) {
        var confirmMsg = {
            block: 'Заблокировать выбранных пользователей? Они потеряют доступ к приложению.',
            unblock: 'Разблокировать выбранных пользователей?',
            delete: 'Удалить пользователей полностью? Действие необратимо. Все данные (прогресс, история привычек, подписка) будут удалены.',
        };
        if (!confirm(confirmMsg[action] || 'Продолжить?')) return;

        if (action === 'block') {
            var reason = prompt('Причина блокировки (опционально):', '');
            var form = x5pwaGetListForm();
            if (!form) return;
            var rInput = form.querySelector('input[name="block_reason"]');
            if (!rInput) {
                rInput = document.createElement('input');
                rInput.type = 'hidden';
                rInput.name = 'block_reason';
                form.appendChild(rInput);
            }
            rInput.value = reason || '';
        }

        x5pwaSubmitAction(action);
    }

    function x5pwaSingleAction(uid, action) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
        form.style.display = 'none';

        var fields = {
            sessid: '<?= bitrix_sessid() ?>',
            action: action,
            single_uid: uid,
        };

        for (var key in fields) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = key;
            inp.value = fields[key];
            form.appendChild(inp);
        }

        document.body.appendChild(form);
        form.submit();
    }

    function x5pwaBlockPrompt(uid) {
        var reason = prompt('Причина блокировки (опционально):', '');
        if (reason === null) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
        form.style.display = 'none';

        var fields = {
            sessid: '<?= bitrix_sessid() ?>',
            action: 'block',
            single_uid: uid,
            block_reason: reason,
        };
        for (var key in fields) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = key;
            inp.value = fields[key];
            form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
    }

    function x5pwaDeletePrompt(uid) {
        if (!confirm('Удалить пользователя полностью?\n\nВсе данные (прогресс, история, подписка) будут удалены. Действие необратимо.')) {
            return;
        }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = location.pathname + '?lang=<?= LANGUAGE_ID ?>';
        form.style.display = 'none';

        var fields = {
            sessid: '<?= bitrix_sessid() ?>',
            action: 'delete',
            single_uid: uid,
        };
        for (var key in fields) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = key;
            inp.value = fields[key];
            form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
    }
</script>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && check_bitrix_sessid()
    && !empty($_POST['single_uid'])
    && in_array($_POST['action'] ?? '', ['block', 'unblock', 'delete'], true)) {

    $uid    = (string)$_POST['single_uid'];
    $action = (string)$_POST['action'];

    if (preg_match('/^[a-f0-9]{40}$/i', $uid)) {
        switch ($action) {
            case 'block':
                $reason = (string)($_POST['block_reason'] ?? 'Заблокирован из админки');
                \X5\Pwa\Storage::blockUser($uid, $reason);
                break;
            case 'unblock':
                \X5\Pwa\Storage::unblockUser($uid);
                break;
            case 'delete':
                \X5\Pwa\Storage::deleteUser($uid);
                break;
        }
    }

    LocalRedirect(
        $APPLICATION->GetCurPage()
        . '?lang=' . LANGUAGE_ID
        . '&SIZEN_1=' . $perPage
    );
}

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';