<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Loader;
use X5\Pwa\AnalyticsBuilder;

$moduleId = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($moduleId);
if ($MODULE_RIGHT < 'R') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($moduleId);
$APPLICATION->SetTitle('PWA — Аналитика воронки');

$error = null;

// ─── Обработка удаления ошибки ───
$request = \Bitrix\Main\Context::getCurrent()->getRequest();
if ($request->isPost() && check_bitrix_sessid() && $request->getPost('action') === 'delete_error') {
    $errorId = (int)$request->getPost('error_id');
    if (AnalyticsBuilder::deleteError($errorId)) {
        LocalRedirect($APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&from=' . urlencode($request->getPost('from')) . '&to=' . urlencode($request->getPost('to')));
    } else {
        $error = 'Не удалось удалить запись.';
    }
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

$funnel           = AnalyticsBuilder::getFunnel($from, $to);
$daily            = AnalyticsBuilder::getDailyDynamic($from, $to);
$entryPoints      = AnalyticsBuilder::getEntryPoints($from, $to);
$funnelBrowser    = AnalyticsBuilder::getOnboardingFunnelBySource($from, $to, 'browser');
$funnelPwa        = AnalyticsBuilder::getOnboardingFunnelBySource($from, $to, 'pwa');
$installStats     = AnalyticsBuilder::getInstallAndRegStats($from, $to);
$installByVia     = AnalyticsBuilder::getInstallByVia($from, $to);
$installByPlatform= AnalyticsBuilder::getInstallByPlatform($from, $to);
$topScreens       = AnalyticsBuilder::getTopScreens($from, $to, 10);
$recentErrors     = AnalyticsBuilder::getRecentErrors($from, $to, 20);

// Человеко-читаемые названия шагов для менеджера
$stepLabels = [
    'app_first_open'           => 'Первый визит (браузер/ярлык)',
    'install_accepted'         => '✅ Установили (ярлык на устройстве)',
    'registration_success'     => 'Зарегистрировались',
    'consent_granted'          => 'Согласие на ПД',
    'onboarding_profile_done'  => 'Заполнили профиль',
    'quiz_done'                => 'Прошли тест',
    'onboarding_habits_done'   => 'Выбрали привычку',
    'onboarding_tutorial_done' => 'Прошли обучение',
    'onboarding_completed'     => '🎉 Онбординг завершён',
    'first_action'             => 'Первое целевое действие',
];

// Человеко-читаемые названия для install_accepted.via
$viaLabels = [
    'prompt'       => 'Prompt (нативный)',
    'appinstalled' => 'Меню браузера (appinstalled)',
    'detected'     => 'Эвристика iOS',
    'unknown'      => 'Старый клиент (via не передан)',
];

// Человеко-читаемые названия для install_accepted.platform
$platformLabels = [
    'ios'     => 'iOS',
    'android' => 'Android',
    'desktop' => 'Desktop',
    'unknown' => 'Не определено (старый клиент)',
];

// Рендерер одной воронки (переиспользуется для двух таблиц)
$renderFunnel = function (array $funnel, string $title, string $color, string $hint) use ($stepLabels): string {
    if (empty($funnel)) {
        return '<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">'
            . '<h3 style="color:' . $color . ';">' . htmlspecialcharsbx($title) . '</h3>'
            . '<p style="color:#888;font-size:13px;">' . htmlspecialcharsbx($hint) . '</p>'
            . '<div style="text-align:center;color:#888;padding:20px;">Нет данных за период</div>'
            . '</div>';
    }

    $base = reset($funnel) ?: 1;
    $prev = $base;

    $html = '<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">';
    $html .= '<h3 style="color:' . $color . ';">' . htmlspecialcharsbx($title) . '</h3>';
    $html .= '<p style="color:#666;font-size:12px;margin:0 0 12px 0;">' . htmlspecialcharsbx($hint) . '</p>';
    $html .= '<table class="internal" style="width:100%; border-collapse:collapse;">';
    $html .= '<tr class="heading"><td>Шаг</td><td style="text-align:right">Устройств</td><td style="text-align:right">Конверсия</td></tr>';

    foreach ($funnel as $name => $count) {
        $label    = $stepLabels[$name] ?? $name;
        $convBase = $base > 0 ? round(($count / $base) * 100, 1) : 0;
        $convPrev = $prev > 0 ? round(($count / $prev) * 100, 1) : 0;

        $html .= '<tr>';
        $html .= '<td>' . htmlspecialcharsbx($label) . '</td>';
        $html .= '<td style="text-align:right; font-family:monospace; font-size:15px;">' . number_format($count) . '</td>';
        $html .= '<td style="text-align:right; font-family:monospace;">'
              . $convBase . '% <span style="color:#888; font-size:11px;">(шаг ' . $convPrev . '%)</span>'
              . '</td>';
        $html .= '</tr>';

        $prev = $count;
    }

    $html .= '</table></div>';
    return $html;
};

// Рендерер маленькой таблицы «установки по разрезу»
$renderInstallBreakdown = function (array $data, string $title, array $labels, string $hint, string $color) {
    $total = array_sum($data);

    $html  = '<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">';
    $html .= '<h3 style="color:' . $color . ';">' . htmlspecialcharsbx($title) . '</h3>';
    $html .= '<p style="color:#666;font-size:12px;margin:0 0 12px 0;">' . htmlspecialcharsbx($hint) . '</p>';

    if ($total === 0) {
        $html .= '<div style="text-align:center;color:#888;padding:16px;">Нет данных за период</div>';
        $html .= '</div>';
        return $html;
    }

    $html .= '<table class="internal" style="width:100%; border-collapse:collapse;">';
    $html .= '<tr class="heading"><td>Канал</td><td style="text-align:right">Устройств</td><td style="text-align:right">Доля</td></tr>';

    foreach ($data as $key => $count) {
        if ($count <= 0) continue;
        $label = $labels[$key] ?? $key;
        $share = $total > 0 ? round(($count / $total) * 100, 1) : 0;
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialcharsbx($label) . '</td>';
        $html .= '<td style="text-align:right; font-family:monospace; font-size:15px;">' . number_format($count) . '</td>';
        $html .= '<td style="text-align:right; font-family:monospace;">' . $share . '%</td>';
        $html .= '</tr>';
    }

    $html .= '<tr style="background:#f5f6f8; font-weight:600;">';
    $html .= '<td>Итого</td>';
    $html .= '<td style="text-align:right; font-family:monospace;">' . number_format($total) . '</td>';
    $html .= '<td style="text-align:right; font-family:monospace;">100%</td>';
    $html .= '</tr>';

    $html .= '</table></div>';
    return $html;
};

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>

<!-- АЛЕРТ ОБ ОШИБКАХ В ШАПКЕ -->
<?php if (!empty($recentErrors)): ?>
    <div class="adm-info-message-wrap adm-info-message-red" style="margin-bottom:20px">
        <div class="adm-info-message">
            <b>Внимание!</b> За выбранный период зафиксировано <b><?= count($recentErrors) ?></b> JS-ошибок в приложении.
            Проверьте блок «Последние ошибки» внизу страницы.
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="adm-info-message-wrap adm-info-message-red" style="margin-bottom:20px">
        <div class="adm-info-message"><?= htmlspecialcharsbx($error) ?></div>
    </div>
<?php endif; ?>

<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;margin-bottom:20px">
    <form method="GET">
        С даты: <input type="date" name="from" value="<?= $from ?>">
        По дату: <input type="date" name="to" value="<?= $to ?>">
        <button class="adm-btn-save" type="submit">Показать</button>
    </form>
</div>

<!-- ═══════════════ БЛОК 1: ОТКУДА ПРИХОДЯТ ═══════════════ -->
<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;margin-bottom:20px;">
    <h3>Откуда приходят пользователи</h3>
    <p style="color:#666;font-size:12px;margin:0 0 14px 0;">
        Разделяем два принципиально разных пути: пришли по ссылке в браузере (приглашение) или открыли уже установленное приложение (ярлык на экране).
    </p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <div style="background:#f5f8ff;padding:20px;border-radius:6px;text-align:center;border-left:4px solid #1e6bcc;">
            <div style="font-size:13px;color:#555;">📨 По приглашению</div>
            <div style="font-size:36px;font-weight:700;color:#1e6bcc;font-family:monospace;">
                <?= number_format((int)($entryPoints['browser'] ?? 0)) ?>
            </div>
            <div style="font-size:12px;color:#888;">
                пришли по ссылке в браузере
                <?php if (($entryPoints['total'] ?? 0) > 0): ?>
                    — <?= round((($entryPoints['browser'] ?? 0) / $entryPoints['total']) * 100, 1) ?>% от всех
                <?php endif; ?>
            </div>
        </div>
        <div style="background:#f5fff8;padding:20px;border-radius:6px;text-align:center;border-left:4px solid #3a7d1c;">
            <div style="font-size:13px;color:#555;">📱 По ярлыку (PWA)</div>
            <div style="font-size:36px;font-weight:700;color:#3a7d1c;font-family:monospace;">
                <?= number_format((int)($entryPoints['pwa'] ?? 0)) ?>
            </div>
            <div style="font-size:12px;color:#888;">
                открыли уже установленное приложение
                <?php if (($entryPoints['total'] ?? 0) > 0): ?>
                    — <?= round((($entryPoints['pwa'] ?? 0) / $entryPoints['total']) * 100, 1) ?>% от всех
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ БЛОК 2: ОБЩАЯ ВОРОНКА И ДИНАМИКА ═══════════════ -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Общая воронка (все устройства)</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr class="heading">
                <td>Шаг</td>
                <td style="text-align:right">Устройств</td>
                <td style="text-align:right">Конверсия</td>
            </tr>
            <?php
            $base = $funnel['app_first_open'] ?: 1;
            $prev = $base;
            foreach ($funnel as $name => $count):
                $label = $stepLabels[$name] ?? $name;
                $convBase = round(($count / $base) * 100, 1);
                $convPrev = $prev > 0 ? round(($count / $prev) * 100, 1) : 0;
            ?>
            <tr>
                <td><b><?= htmlspecialcharsbx($label) ?></b></td>
                <td style="text-align:right; font-family:monospace; font-size:16px;"><?= number_format($count) ?></td>
                <td style="text-align:right; font-family:monospace;">
                    <?= $convBase ?>% <span style="color:#888; font-size:12px;">(от <?= $convPrev ?>%)</span>
                </td>
            </tr>
            <?php $prev = $count; endforeach; ?>
        </table>
    </div>

    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Новые устройства по дням</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr class="heading">
                <td>Дата</td>
                <td style="text-align:right">Новых</td>
            </tr>
            <?php if(empty($daily)): ?>
                <tr><td colspan="2" style="text-align:center;color:#888;padding:20px;">Нет данных за этот период</td></tr>
            <?php endif; ?>
            <?php foreach ($daily as $day): ?>
            <tr>
                <td><?= $day['D'] ?></td>
                <td style="text-align:right; font-family:monospace;"><?= number_format($day['C']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- ═══════════════ БЛОК 3: ВОРОНКА ПО ИСТОЧНИКАМ ═══════════════ -->
<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;margin-top:20px;">
    <h3>Воронка онбординга — раздельно по источникам</h3>
    <p style="color:#666;font-size:12px;margin:0 0 14px 0;">
        Сравните: <b>те, кто установил приложение</b>, проходят онбординг гораздо лучше. Если процент установки низкий — проблема в привлечении, а не в онбординге.
    </p>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <?= $renderFunnel(
            $funnelBrowser,
            '📨 Пришли по ссылке (браузер)',
            '#1e6bcc',
            'Устройства, у которых первый вход был в браузере. Ключевой шаг — установка приложения.'
        ) ?>

        <?= $renderFunnel(
            $funnelPwa,
            '📱 Открыли ярлык (PWA)',
            '#3a7d1c',
            'Устройства, у которых уже установлено приложение. Шаг установки пропущен — они уже установили.'
        ) ?>
    </div>
</div>

<!-- ═══════════════ БЛОК 4: УСТАНОВКА И РЕГИСТРАЦИЯ ═══════════════ -->
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:20px;">
    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Установка и Регистрация (общая)</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr><td>iOS — увидели экран установки</td><td style="text-align:right;font-family:monospace;font-weight:bold;"><?= number_format($installStats['ios_gate']) ?></td></tr>
            <tr><td>Android — увидели нативный prompt</td><td style="text-align:right;font-family:monospace;font-weight:bold;"><?= number_format($installStats['android_prompt']) ?></td></tr>
            <tr><td>Android — установили (accepted)</td><td style="text-align:right;font-family:monospace;font-weight:bold;color:#3a7d1c"><?= number_format($installStats['android_installed']) ?></td></tr>
            <tr><td colspan="2"><hr style="border:none;border-top:1px solid #eee;margin:10px 0"></td></tr>
            <tr><td>Успешных регистраций</td><td style="text-align:right;font-family:monospace;font-weight:bold;color:#3a7d1c"><?= number_format($installStats['reg_success']) ?></td></tr>
            <tr><td>Ошибок регистрации (UID_TAKEN)</td><td style="text-align:right;font-family:monospace;font-weight:bold;color:#c0392b"><?= number_format($installStats['reg_uid_taken']) ?></td></tr>
            <tr><td>Экран «Доступ запрещен» (no-uid)</td><td style="text-align:right;font-family:monospace;font-weight:bold;color:#c0392b"><?= number_format($installStats['access_denied']) ?></td></tr>
        </table>
    </div>

    <div style="background:#fff;padding:20px;border:1px solid #e2e5e9;">
        <h3>Топ-10 экранов по просмотрам</h3>
        <table class="internal" style="width:100%; border-collapse:collapse;">
            <tr class="heading"><td>Path</td><td style="text-align:right">Просмотров</td></tr>
            <?php if (empty($topScreens)): ?>
                <tr><td colspan="2" style="text-align:center;color:#888;">Нет данных</td></tr>
            <?php endif; ?>
            <?php foreach ($topScreens as $scr): ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;"><?= htmlspecialcharsbx($scr['PATH'] ?? '') ?></td>
                <td style="text-align:right;font-family:monospace;"><?= number_format($scr['C']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- ═══════════════ БЛОК 4.5: РАЗБИВКА УСТАНОВОК ═══════════════ -->
<?php if (!empty($installByVia) || !empty($installByPlatform)): ?>
    <?php
    $totalVia = array_sum($installByVia);
    $hasNewVia = isset($installByVia['appinstalled']) || isset($installByVia['detected']);
    ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:20px;">
        <?= $renderInstallBreakdown(
            $installByVia,
            'Установки: по каналу (via)',
            $viaLabels,
            'Как именно пользователь установил приложение. Если всё в «unknown» — клиент старой версии, не передаёт via.',
            '#1e6bcc'
        ) ?>

        <?= $renderInstallBreakdown(
            $installByPlatform,
            'Установки: по платформе (platform)',
            $platformLabels,
            'iOS Safari не поддерживает appinstalled — там останутся только установки через prompt/эвристику. iOS в «unknown» — клиент без platform.',
            '#3a7d1c'
        ) ?>
    </div>

    <?php if ($totalVia > 0 && !$hasNewVia): ?>
        <div class="adm-info-message-wrap adm-info-message-yellow" style="margin-top:16px;">
            <div class="adm-info-message">
                <b>Внимание:</b> в разбивке нет каналов <code>appinstalled</code> и <code>detected</code>.
                Скорее всего, клиент ещё не задеплоен — события <code>install_accepted</code>
                приходят только из <code>promptInstall()</code> (старая версия).
                После деплоя фронта разбивка начнёт заполняться всеми каналами.
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ═══════════════ БЛОК 5: ОШИБКИ ═══════════════ -->
<div style="background:#fff;padding:20px;border:1px solid #e2e5e9;margin-top:20px;">
    <h3 style="color:#c0392b;">Последние JS-ошибки (App Errors)</h3>
    <p style="font-size:12px;color:#888;margin-bottom:10px;">
        Колонки «Тип» и «Платформа» — только для новой версии клиента.
        Если там «-», это старая версия (кэш PWA).
    </p>
    <table class="internal" style="width:100%; border-collapse:collapse;">
        <tr class="heading">
            <td style="width:140px;">Время</td>
            <td>Ошибка</td>
            <td>Файл : Строка</td>
            <td>Экран</td>
            <td>Тип</td>
            <td>Платформа</td>
            <td style="width:40px;"></td>
        </tr>
        <?php if (empty($recentErrors)): ?>
            <tr><td colspan="7" style="text-align:center;color:#3a7d1c;padding:20px;">🎉 Ошибок за этот период не найдено</td></tr>
        <?php endif; ?>
        <?php foreach ($recentErrors as $err): ?>
        <?php $lineNo = (int)$err['LINE']; ?>
        <tr>
            <td style="font-family:monospace;font-size:12px;color:#666;"><?= $err['UF_SERVER_AT'] ?></td>
            <td style="color:#c0392b;font-weight:bold;"><?= htmlspecialcharsbx($err['MSG']) ?></td>
            <td style="font-family:monospace;font-size:12px;color:#555;">
                <?= htmlspecialcharsbx($err['FILE']) ?><?= $lineNo > 0 ? ':' . $lineNo : '' ?>
            </td>
            <td style="font-family:monospace;font-size:12px;color:#1e6bcc;"><?= htmlspecialcharsbx($err['SCREEN']) ?></td>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialcharsbx($err['KIND']) ?></td>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialcharsbx($err['PLATFORM']) ?></td>
            <td style="text-align:center;">
                <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить эту ошибку?');">
                    <?= bitrix_sessid_post() ?>
                    <input type="hidden" name="action" value="delete_error">
                    <input type="hidden" name="error_id" value="<?= (int)$err['ID'] ?>">
                    <input type="hidden" name="from" value="<?= $from ?>">
                    <input type="hidden" name="to" value="<?= $to ?>">
                    <button type="submit" style="background:none; border:none; cursor:pointer; color:#c0392b; font-size:16px; font-weight:bold;" title="Удалить">&times;</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>