<?php
/**
 * Список пользователей PWA (CAdminUiList) с блокировкой и удалением.
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

$search     = x5pwaFilterValue($arFilter, 'SEARCH');
$theme      = x5pwaFilterValue($arFilter, 'THEME');
$blocked    = x5pwaFilterValue($arFilter, 'BLOCKED');
$activeFrom = x5pwaFilterValue($arFilter, 'LAST_SEEN_AT_from');
$activeTo   = x5pwaFilterValue($arFilter, 'LAST_SEEN_AT_to');
$withPushRaw = x5pwaFilterValue($arFilter, 'HAS_SUBSCRIPTION');
$withPush = in_array($withPushRaw, ['Y', 'N'], true) ? $withPushRaw : '';

// ─────────────────────────────────────────────────────────
// Обработка групповых действий
// ─────────────────────────────────────────────────────────
if ($arIDs = $lAdmin->GroupAction()) {
    $action = $lAdmin->GetAction();

    if (in_array($action, ['block', 'unblock', 'delete'], true)) {
        // Если выбрано «все» — собираем UID по фильтру
        if ($lAdmin->IsGroupActionToAll()) {
            $all = \X5\Pwa\StatsBuilder::listUsers([
                'search'      => $search,
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
            LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
        }
    }
}

// ─────────────────────────────────────────────────────────
// Пагинация и выборка
// ─────────────────────────────────────────────────────────
$page    = max(1, (int)($_REQUEST['page'] ?? $_REQUEST['PAGEN_1'] ?? 1));
$perPage = 50;

$params = [
    'search'      => $search,
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
    ['id' => 'STATUS',           'content' => 'Статус',       'default' => true],
    ['id' => 'FIO',              'content' => 'Ф.И.О',       'sort' => 'FIO',          'default' => true],
    ['id' => 'NICKNAME',         'content' => 'Никнейм',      'sort' => 'NICKNAME',     'default' => true],
    ['id' => 'WORKPLACE',        'content' => 'Место работы', 'sort' => 'WORKPLACE',    'default' => true],
    ['id' => 'THEME',            'content' => 'Направление',  'sort' => 'THEME',        'default' => true],
    ['id' => 'LAST_SEEN_AT',     'content' => 'Активность',   'sort' => 'LAST_SEEN_AT', 'default' => true],
    ['id' => 'HAS_SUBSCRIPTION', 'content' => 'Push',         'default' => true],
    ['id' => 'LECTURES_READ',    'content' => 'Лекций',       'default' => true],
    ['id' => 'TASKS_DONE',       'content' => 'Заданий',      'default' => true],
    ['id' => 'HABITS_DONE',      'content' => 'Привычек',     'default' => true],
    ['id' => 'UID',              'content' => 'UID',          'sort' => 'UID',          'default' => false],
    ['id' => 'CREATED_AT',       'content' => 'Создан',       'sort' => 'CREATED_AT',   'default' => false],
];

$lAdmin->AddHeaders($arHeader);
$lAdmin->AddVisibleHeaderColumn('UID');
$lAdmin->AddVisibleHeaderColumn('CREATED_AT');

$formatDate = function (?int $ms): string {
    if (!$ms) return '—';
    return date('d.m.Y H:i', (int)($ms / 1000));
};

foreach ($data['rows'] as $u) {
    $uid     = (string)$u['UID'];
    $blocked = !empty($u['BLOCKED']);

    $arRes = [
        'UID'              => $uid,
        'STATUS'           => $blocked ? 'Y' : 'N',
        'FIO'              => (string)$u['FIO'],
        'NICKNAME'         => (string)$u['NICKNAME'],
        'WORKPLACE'        => (string)$u['WORKPLACE'],
        'THEME'            => (string)$u['THEME'],
        'LAST_SEEN_AT'     => $formatDate((int)$u['LAST_ACTIVITY_AT']),
        'CREATED_AT'       => $formatDate((int)$u['CREATED_AT']),
        'HAS_SUBSCRIPTION' => $u['HAS_SUBSCRIPTION'] ? 'Y' : 'N',
        'LECTURES_READ'    => (int)$u['LECTURES_READ'],
        'TASKS_DONE'       => (int)$u['TASKS_DONE'],
        'HABITS_DONE'      => (int)$u['HABITS_DONE'],
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

    // Одиночные действия
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

// ─────────────────────────────────────────────────────────
// Групповые действия
// ─────────────────────────────────────────────────────────
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

    /**
     * Форма списка (CAdminList рендерит её с id = "form_<sTableID>").
     * Для групповых действий добавляем в неё скрытый input name=action и submit.
     */
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

        // Проверяем, что есть выбранные чекбоксы
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

    /**
     * Одиночные действия. Отправляем через скрытую форму, чтобы
     * не мешать основной форме списка.
     */
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
// ─────────────────────────────────────────────────────────
// Обработка одиночных действий (single_uid + action)
// ─────────────────────────────────────────────────────────
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

    LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID);
}

$lAdmin->DisplayFilter($filterFields);
$lAdmin->DisplayList();

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';