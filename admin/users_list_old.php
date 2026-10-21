<?php
/**
 * Список пользователей PWA: фильтры, сортировка, пагинация, AJAX, экспорт.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

$moduleId     = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
Loader::includeModule('highloadblock');

$APPLICATION->SetTitle('PWA — Пользователи');

/** Читает параметры из $_GET с валидацией. */
function x5_pwa_get_params(): array
{
    $sortMap = ['uid','fio','nickname','workplace','theme','created','last_seen'];
    $sort = in_array(($_GET['sort'] ?? ''), $sortMap, true) ? $_GET['sort'] : 'last_seen';
    $sortDir = strtoupper((string)($_GET['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    return [
        'search'     => trim((string)($_GET['q'] ?? '')),
        'theme'      => trim((string)($_GET['theme'] ?? '')),
        'activeDays' => max(0, (int)($_GET['activeDays'] ?? 0)),
        'withPush'   => in_array(($_GET['withPush'] ?? ''), ['Y','N'], true) ? $_GET['withPush'] : '',
        'sort'       => $sort,
        'sortDir'    => $sortDir,
        'page'       => max(1, (int)($_GET['page'] ?? 1)),
        'perPage'    => max(10, min(200, (int)($_GET['perPage'] ?? 50))),
    ];
}

/** Рендерит HTML таблицы. Вызывается и из полной страницы, и из AJAX-режима. */
function x5_pwa_render_table(array $data): void
{
    $params = x5_pwa_get_params();
    $rows   = $data['rows'];

    // Хелпер для ссылок-сортировки
    $sortLink = function (string $field) use ($params): string {
        $dir = 'ASC';
        if ($params['sort'] === $field && $params['sortDir'] === 'ASC') $dir = 'DESC';
        $q = array_merge($_GET, ['sort' => $field, 'sortDir' => $dir, 'page' => 1]);
        return '?' . http_build_query($q);
    };

    $sortArrow = function (string $field) use ($params): string {
        if ($params['sort'] !== $field) return '';
        return $params['sortDir'] === 'ASC' ? ' ▲' : ' ▼';
    };

    $fmt = function (?int $ms): string {
        if (!$ms) return '—';
        return date('d.m.Y H:i', (int)($ms / 1000));
    };

    ?>
    <div style="margin-bottom:12px">
        Найдено пользователей: <b><?= (int)$data['total'] ?></b>
        <?php if ($data['total'] > 0): ?>
            (страница <?= (int)$data['page'] ?> из <?= (int)$data['pages'] ?>)
        <?php endif ?>
    </div>

    <?php if (!$rows): ?>
        <div class="adm-info-message-wrap adm-info-message-gray">
            <div class="adm-info-message">Ничего не найдено.</div>
        </div>
    <?php else: ?>
        <table class="x5pwa-table">
            <thead>
            <tr>
                <th><a href="<?= htmlspecialcharsbx($sortLink('fio')) ?>">Ф.И.О<?= $sortArrow('fio') ?></a></th>
                <th><a href="<?= htmlspecialcharsbx($sortLink('nickname')) ?>">Никнейм<?= $sortArrow('nickname') ?></a></th>
                <th><a href="<?= htmlspecialcharsbx($sortLink('workplace')) ?>">Место работы<?= $sortArrow('workplace') ?></a></th>
                <th><a href="<?= htmlspecialcharsbx($sortLink('theme')) ?>">Направление<?= $sortArrow('theme') ?></a></th>
                <th><a href="<?= htmlspecialcharsbx($sortLink('last_seen')) ?>">Активность<?= $sortArrow('last_seen') ?></a></th>
                <th style="width:60px">Push</th>
                <th><a href="<?= htmlspecialcharsbx($sortLink('uid')) ?>">Лекций<?= $sortArrow('uid') ?></a></th>
                <th>Заданий</th>
                <th>Привычек</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
                <tr>
                    <td>
                        <?= htmlspecialcharsbx($u['FIO'] ?: '—') ?>
                        <div class="x5pwa-uid" style="color:#888;font-size:10px">
                            <?= htmlspecialcharsbx(substr($u['UID'], 0, 12)) ?>…
                        </div>
                    </td>
                    <td><?= htmlspecialcharsbx($u['NICKNAME'] ?: '—') ?></td>
                    <td><?= htmlspecialcharsbx($u['WORKPLACE'] ?: '—') ?></td>
                    <td>
                        <?php if ($u['THEME'] !== ''): ?>
                            <span class="x5pwa-badge x5pwa-badge--theme"><?= htmlspecialcharsbx($u['THEME']) ?></span>
                        <?php else: ?>
                            <span style="color:#aaa">—</span>
                        <?php endif ?>
                    </td>
                    <td><?= $fmt($u['LAST_SEEN_AT']) ?></td>
                    <td>
                        <?php if ($u['HAS_SUBSCRIPTION']): ?>
                            <span class="x5pwa-badge x5pwa-badge--yes">есть</span>
                        <?php else: ?>
                            <span class="x5pwa-badge x5pwa-badge--no">нет</span>
                        <?php endif ?>
                    </td>
                    <td><b><?= (int)$u['LECTURES_READ'] ?></b></td>
                    <td><b><?= (int)$u['TASKS_DONE'] ?></b></td>
                    <td><b><?= (int)$u['HABITS_DONE'] ?></b></td>
                    <td class="x5pwa-actions">
                        <a href="x5_pwa_user_detail.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($u['UID']) ?>">Подробно</a>
                        <a href="x5_pwa_push.php?lang=<?= LANGUAGE_ID ?>&uid=<?= urlencode($u['UID']) ?>">PUSH</a>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>

        <?php if ($data['pages'] > 1): ?>
            <div class="x5pwa-pagination">
                <?php
                $page = $data['page'];
                $pages = $data['pages'];
                $pStart = max(1, $page - 3);
                $pEnd   = min($pages, $page + 3);

                $pageLink = function (int $p) use ($params): string {
                    $q = array_merge($_GET, ['page' => $p]);
                    return '?' . http_build_query($q);
                };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialcharsbx($pageLink(1)) ?>" data-page="1">« первая</a>
                    <a href="<?= htmlspecialcharsbx($pageLink($page - 1)) ?>" data-page="<?= $page - 1 ?>">‹ назад</a>
                <?php endif ?>

                <?php for ($p = $pStart; $p <= $pEnd; $p++): ?>
                    <?php if ($p === $page): ?>
                        <b class="current"><?= $p ?></b>
                    <?php else: ?>
                        <a href="<?= htmlspecialcharsbx($pageLink($p)) ?>" data-page="<?= $p ?>"><?= $p ?></a>
                    <?php endif ?>
                <?php endfor ?>

                <?php if ($page < $pages): ?>
                    <a href="<?= htmlspecialcharsbx($pageLink($page + 1)) ?>" data-page="<?= $page + 1 ?>">вперёд ›</a>
                    <a href="<?= htmlspecialcharsbx($pageLink($pages)) ?>" data-page="<?= $pages ?>">последняя »</a>
                <?php endif ?>
            </div>
        <?php endif ?>
    <?php endif ?>
    <?php
}

// ─────────── AJAX-режим: возвращаем только таблицу ───────────
if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: text/html; charset=utf-8');
    $params = x5_pwa_get_params();
    $data = \X5\Pwa\StatsBuilder::listUsers($params);
    x5_pwa_render_table($data);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    exit;
}

// ─────────── Обычная страница ───────────
$params = x5_pwa_get_params();
$data   = \X5\Pwa\StatsBuilder::listUsers($params);
?>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; ?>

<style>
    .x5pwa-table { width: 100%; border-collapse: collapse; background: #fff; }
    .x5pwa-table th, .x5pwa-table td { padding: 8px 12px; border-bottom: 1px solid #e2e5e9; text-align: left; font-size: 13px; }
    .x5pwa-table th { background: #f5f6f8; font-weight: 600; white-space: nowrap; }
    .x5pwa-table th a { color: #333; text-decoration: none; }
    .x5pwa-table tr:hover { background: #fafafa; }
    .x5pwa-uid { font-family: monospace; font-size: 11px; }
    .x5pwa-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    .x5pwa-badge--yes   { background: #e6f5d8; color: #3a7d1c; }
    .x5pwa-badge--no    { background: #f5e0e0; color: #a52020; }
    .x5pwa-badge--theme { background: #e0efff; color: #1e6bcc; }
    .x5pwa-actions a { margin-right: 8px; white-space: nowrap; }
    .x5pwa-filters { background:#fff; padding:14px 16px; border:1px solid #e2e5e9; margin-bottom:16px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
    .x5pwa-filters input[type=text], .x5pwa-filters input[type=number], .x5pwa-filters select { padding:6px 8px; }
    .x5pwa-pagination { margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; }
    .x5pwa-pagination a, .x5pwa-pagination b.current { padding:6px 10px; border:1px solid #ccc; text-decoration:none; border-radius:4px; background:#fff; color:#333; }
    .x5pwa-pagination b.current { background:#1e6bcc; color:#fff; border-color:#1e6bcc; }
    .x5pwa-loading { opacity: 0.5; transition: opacity .15s; }
</style>

<form method="get" action="x5_pwa_users.php" class="x5pwa-filters" id="x5pwa-filters">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="sort" value="<?= htmlspecialcharsbx($params['sort']) ?>" id="x5pwa-f-sort">
    <input type="hidden" name="sortDir" value="<?= htmlspecialcharsbx($params['sortDir']) ?>" id="x5pwa-f-sortdir">

    <div>
        <label>Поиск:</label>
        <input type="text" name="q" value="<?= htmlspecialcharsbx($params['search']) ?>"
               placeholder="Ф.И.О, никнейм, место, UID" style="width:260px">
    </div>

    <div>
        <label>Направление:</label>
        <select name="theme">
            <option value="">Все</option>
            <?php foreach (['energy','antistress','body','balance'] as $t): ?>
                <option value="<?= $t ?>" <?= $params['theme'] === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach ?>
        </select>
    </div>

    <div>
        <label>Активные за:</label>
        <select name="activeDays">
            <option value="0">все время</option>
            <?php foreach ([1,3,7,14,30,90] as $d): ?>
                <option value="<?= $d ?>" <?= $params['activeDays'] === $d ? 'selected' : '' ?>><?= $d ?> дн.</option>
            <?php endforeach ?>
        </select>
    </div>

    <div>
        <label>Push:</label>
        <select name="withPush">
            <option value="">Все</option>
            <option value="Y" <?= $params['withPush'] === 'Y' ? 'selected' : '' ?>>Есть подписка</option>
            <option value="N" <?= $params['withPush'] === 'N' ? 'selected' : '' ?>>Нет подписки</option>
        </select>
    </div>

    <div>
        <label>На странице:</label>
        <select name="perPage">
            <?php foreach ([25,50,100,200] as $pp): ?>
                <option value="<?= $pp ?>" <?= $params['perPage'] === $pp ? 'selected' : '' ?>><?= $pp ?></option>
            <?php endforeach ?>
        </select>
    </div>

    <input type="submit" value="Применить" class="adm-btn adm-btn-save">
    <a href="x5_pwa_users.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn">Сбросить</a>

    <span style="flex:1"></span>

    <a href="x5_pwa_users_export.php?lang=<?= LANGUAGE_ID ?>&<?= http_build_query(array_diff_key($_GET, ['lang' => 1, 'page' => 1, 'perPage' => 1])) ?>"
       class="adm-btn">
        Экспорт в CSV
    </a>
</form>

<div id="x5pwa-table-wrap" style="position:relative">
    <?php x5_pwa_render_table($data); ?>
</div>

<script>
(function () {
    const wrap = document.getElementById('x5pwa-table-wrap');
    const form = document.getElementById('x5pwa-filters');

    // AJAX-подгрузка по клику на пагинацию или сортировку
    wrap.addEventListener('click', function (e) {
        const a = e.target.closest('a');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!href.includes('x5_pwa_users.php')) {
            // ссылки на detail/push — не перехватываем
            if (!a.dataset.page && !href.startsWith('?')) return;
        }
        if (!a.dataset.page && !href.startsWith('?')) return;
        e.preventDefault();

        const url = new URL(a.href || location.origin + location.pathname + href, location.origin);
        url.searchParams.set('ajax', '1');
        loadAjax(url);
    });

    function loadAjax(url) {
        wrap.classList.add('x5pwa-loading');

        fetch(url.toString(), { credentials: 'same-origin' })
            .then(r => r.text())
            .then(html => {
                wrap.innerHTML = html;
                wrap.classList.remove('x5pwa-loading');
                // Обновляем URL без перезагрузки
                const clean = new URL(url.toString());
                clean.searchParams.delete('ajax');
                history.replaceState({}, '', clean.toString());
            })
            .catch(err => {
                wrap.classList.remove('x5pwa-loading');
                alert('Ошибка загрузки: ' + err.message);
            });
    }
})();
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>