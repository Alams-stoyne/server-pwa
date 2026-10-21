<?php
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/** @var CMain $APPLICATION */
global $APPLICATION;

$module_id    = 'x5.pwa';
$MODULE_RIGHT = $APPLICATION->GetGroupRight($module_id);
if ($MODULE_RIGHT < 'W') $APPLICATION->AuthForm('Доступ запрещён');

Loader::includeModule($module_id);
Loader::includeModule('iblock');

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$isPost  = $request->isPost() && check_bitrix_sessid();

$message = null;
$error   = null;

$saveFormFields = function () use ($request, $module_id): void {
    $fields = [
        'vapid_public', 'vapid_private', 'vapid_subject',
        's3_enabled', 's3_endpoint', 's3_region', 's3_bucket',
        's3_access_key', 's3_prefix', 's3_path_style',
        'cdn_base', 'cdn_strip_bucket', 'cdn_strip_upload',
        'allowed_origins',
    ];
    foreach ($fields as $f) {
        Option::set($module_id, $f, (string)$request->getPost($f));
    }

    $secret = (string)$request->getPost('s3_secret_key');
    if ($secret !== '') {
        Option::set($module_id, 's3_secret_key', $secret);
    }

    $iv = (int)$request->getPost('stats_interval_sec');
    if ($iv < 60) $iv = 600;
    Option::set($module_id, 'stats_interval_sec', (string)$iv);
};

$validateVapid = function (string $pub, string $priv): ?string {
    $check = function (string $s, int $expectBytes): bool {
        if ($s === '') return true;
        $b = strtr($s, '-_', '+/');
        $b .= str_repeat('=', (4 - strlen($b) % 4) % 4);
        $raw = base64_decode($b, true);
        return $raw !== false && strlen($raw) === $expectBytes;
    };
    if (!$check($pub, 65))  return 'VAPID public key: ожидается 65 байт после base64url-декодирования.';
    if (!$check($priv, 32)) return 'VAPID private key: ожидается 32 байта после base64url-декодирования.';
    return null;
};

if ($isPost && $request->getPost('restore') !== null) {
    Option::delete($module_id);
    LocalRedirect(
        $APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id)
        . '&lang=' . LANGUAGE_ID . '&ok=restore'
    );
}

if ($isPost) $saveFormFields();

if ($isPost && $request->getPost('regenerate_vapid') !== null) {
    try {
        $keys = \X5\Pwa\Settings::generateVapidKeys();
        Option::set($module_id, 'vapid_public',  $keys['publicKey']);
        Option::set($module_id, 'vapid_private', $keys['privateKey']);
        $message = 'VAPID-ключи сгенерированы. Все существующие push-подписки станут недействительными.';
    } catch (\Throwable $e) {
        $error = 'Ошибка генерации VAPID: ' . $e->getMessage();
    }
}

if ($isPost && $request->getPost('test_s3') !== null) {
    try {
        if (!\X5\Pwa\Settings::getBool('s3_enabled')) {
            $error = 'S3 выключен: поставьте галочку «Включить S3» и нажмите «Сохранить».';
        } elseif (!\X5\Pwa\S3::available()) {
            $error = 'S3 выключен: библиотека aws/aws-sdk-php не установлена (composer install).';
        } else {
            $cfg = \X5\Pwa\Settings::s3();
            $missing = [];
            foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
                if ($cfg[$k] === '') $missing[] = $k;
            }
            if ($missing) {
                $error = 'S3 выключен: не заполнены — ' . implode(', ', $missing);
            } else {
                $result = \X5\Pwa\S3::testConnection();
                if ($result['ok']) {
                    $message = 'S3: подключение работает. Объект записан по пути ' . ($result['path'] ?? '');
                } else {
                    $error = 'S3 ошибка: ' . ($result['message'] ?? 'unknown');
                }
            }
        }
    } catch (\Throwable $e) {
        $error = 'S3 исключение: ' . $e->getMessage();
    }
}

if ($isPost && $request->getPost('rebuild_dumps') !== null) {
    try {
        $r = \X5\Pwa\Dumper::regenerateAll();
        $msg = sprintf(
            'Дампы: %d файлов. Отправлено: %d, ошибок: %d. Лекций: %d, заданий: %d, конфиг: %d, статистика: %d, пустых: %d. Время: %s сек.',
            $r['total'], $r['sent'], $r['failed'],
            $r['by_category']['lectures'] ?? 0,
            $r['by_category']['tasks'] ?? 0,
            $r['by_category']['config'] ?? 0,
            $r['by_category']['stats'] ?? 0,
            $r['by_category']['empty'] ?? 0,
            $r['elapsed']
        );
        if ($r['failed'] > 0) {
            $error = $msg . ' Ошибки: ' . implode('; ', array_slice($r['errors'], 0, 3));
        } else {
            $message = $msg;
        }
    } catch (\Throwable $e) {
        $error = 'Ошибка пересборки: ' . $e->getMessage();
    }
}

if ($isPost && $request->getPost('rebuild_stats') !== null) {
    try {
        $r = \X5\Pwa\Dumper::regenerateStats();
        if ($r['ok']) $message = 'Статистика обновлена. Путь: ' . ($r['key'] ?? '');
        else          $error   = 'Ошибка статистики: ' . ($r['error'] ?? 'unknown');
    } catch (\Throwable $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

if ($isPost && $request->getPost('resetup_iblock') !== null) {
    try {
        \X5\Pwa\IblockSetup::install();
        \X5\Pwa\Settings::markIblockSetupDone();
        $message = 'Инфоблоки перенастроены. ID сохранены в опциях.';
    } catch (\Throwable $e) {
        \X5\Pwa\Settings::clearIblockSetupDone();
        $error = 'Ошибка перенастройки: ' . $e->getMessage();
    }
}

// ─── Пересоздать admin-обёртки ───
if ($isPost && $request->getPost('resetup_admin') !== null) {
    try {
        $report = \X5\Pwa\AdminSetup::install();

        $parts = [];
        if ($report['created']) $parts[] = 'создано: ' . count($report['created']);
        if ($report['updated']) $parts[] = 'обновлено: ' . count($report['updated']);
        if ($report['skipped']) $parts[] = 'пропущено: ' . count($report['skipped']);
        if ($report['errors'])  $parts[] = 'ошибок: ' . count($report['errors']);

        $msg = 'Admin-обёртки пересозданы. ' . implode(', ', $parts) . '.';

        if ($report['errors']) {
            $error = $msg . ' Первые ошибки: ' . implode('; ', array_slice($report['errors'], 0, 3));
        } elseif ($report['skipped']) {
            $message = $msg . ' Пропущены: ' . implode(', ', array_slice($report['skipped'], 0, 3));
        } else {
            $message = $msg;
        }
    } catch (\Throwable $e) {
        $error = 'Ошибка пересоздания обёрток: ' . $e->getMessage();
    }
}

if ($isPost && $request->getPost('save') !== null) {
    $pub  = (string)$request->getPost('vapid_public');
    $priv = (string)$request->getPost('vapid_private');
    $vErr = $validateVapid($pub, $priv);
    if ($vErr) $error = $vErr;
    else       $message = 'Настройки сохранены.';
}

$opt = fn(string $k) => (string)Option::get($module_id, $k, '');

$s3Enabled   = $opt('s3_enabled') === 'Y';
$s3Available = \X5\Pwa\S3::available();
$pushAvail   = \X5\Pwa\Push::available();

$s3Title = 'S3 хранилище';
if (!$s3Enabled)       $s3Title .= ' — выключено';
elseif (!$s3Available) $s3Title .= ' — нет aws-sdk-php';
else                   $s3Title .= ' — включено';

$ibKeys = [
    'general_lectures' => 'Общие лекции',
    'charging'         => 'Зарядка',
    'theme_lectures'   => 'Лекции по направлениям',
    'tasks'            => 'Задания по направлениям',
    'themes'           => 'Направления',
    'quiz_questions'   => 'Вопросы теста',
    'habits'           => 'Привычки',
    'today_widgets'    => 'Виджеты Today',
    'tutorial'         => 'Слайды обучения',
    'reflections'      => 'Настройки рефлексии',
];

$ibCfg  = \X5\Pwa\IblockSetup::config();
$ibInfo = [];
foreach ($ibKeys as $key => $label) {
    $id    = (int)Option::get($module_id, 'ib_' . $key, 0);
    $count = 0;
    if ($id > 0) {
        $count = (int)\CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $id, 'CHECK_PERMISSIONS' => 'N'],
            [],
            false,
            ['ID']
        );
    }
    $ibInfo[$key] = [
        'label' => $label,
        'id'    => $id,
        'count' => $count,
        'code'  => $ibCfg[$key]['code'] ?? '',
    ];
}

$ibSetupDone     = $opt('ib_setup_done') === 'Y';
$hlVersion       = (int)Option::get($module_id, 'hl_setup_version', 0);
$hlVersionTarget = \X5\Pwa\HlSetup::currentVersion();

$statsInterval   = (int)Option::get($module_id, 'stats_interval_sec', 600);
$lastStatsAt     = (int)Option::get($module_id, 'last_stats_at', 0);
$lastStatsAtText = $lastStatsAt > 0 ? date('d.m.Y H:i:s', (int)($lastStatsAt / 1000)) : '—';
$statsDue        = \X5\Pwa\Dumper::statsDue();
$statsWaitSec    = $lastStatsAt > 0
    ? max(0, \X5\Pwa\Dumper::statsInterval() - (int)((time() * 1000 - $lastStatsAt) / 1000))
    : 0;

$lastDumpAt      = (int)Option::get($module_id, 'last_dump_at', 0);
$lastDumpAtText  = $lastDumpAt > 0 ? date('d.m.Y H:i:s', (int)($lastDumpAt / 1000)) : '—';

$aTabs = [
    ['DIV' => 'edit_vapid',  'TAB' => 'VAPID',      'TITLE' => 'VAPID ключи'],
    ['DIV' => 'edit_s3',     'TAB' => 'S3',         'TITLE' => $s3Title],
    ['DIV' => 'edit_iblock', 'TAB' => 'Инфоблоки',  'TITLE' => 'Инфоблоки и дампы'],
    ['DIV' => 'edit_stats',  'TAB' => 'Статистика', 'TITLE' => 'Статистика и cron'],
    ['DIV' => 'edit_cors',   'TAB' => 'CORS',       'TITLE' => 'Разрешённые источники'],
    ['DIV' => 'edit_tools',  'TAB' => 'Сервис',     'TITLE' => 'Сервисные операции'],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);
?>
<?php if ($message): ?>
    <div class="adm-info-message-wrap adm-info-message-green">
        <div class="adm-info-message"><?= htmlspecialcharsbx($message) ?></div>
    </div>
<?php endif ?>
<?php if ($error): ?>
    <div class="adm-info-message-wrap adm-info-message-red">
        <div class="adm-info-message"><?= htmlspecialcharsbx($error) ?></div>
    </div>
<?php endif ?>

<?php if (!$pushAvail): ?>
    <div class="adm-info-message-wrap adm-info-message-yellow">
        <div class="adm-info-message">
            Библиотека <code>minishlink/web-push</code> не установлена.
        </div>
    </div>
<?php endif ?>

<?php if (!$s3Available): ?>
    <div class="adm-info-message-wrap adm-info-message-yellow">
        <div class="adm-info-message">
            Библиотека <code>aws/aws-sdk-php</code> не установлена.
        </div>
    </div>
<?php endif ?>

<?php if ($hlVersion < $hlVersionTarget): ?>
    <div class="adm-info-message-wrap adm-info-message-yellow">
        <div class="adm-info-message">
            Схема HL-блоков устарела: текущая <b><?= (int)$hlVersion ?></b>,
            ожидается <b><?= (int)$hlVersionTarget ?></b>.
            Откройте любую страницу сайта — миграция выполнится автоматически.
        </div>
    </div>
<?php endif ?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->Begin(); ?>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><label>VAPID Subject:</label></td>
        <td width="60%">
            <input type="text" size="60" name="vapid_subject"
                   value="<?= htmlspecialcharsbx($opt('vapid_subject')) ?>">
        </td>
    </tr>
    <tr>
        <td><label>VAPID Public Key:</label></td>
        <td>
            <textarea name="vapid_public" rows="3" cols="70"
                      style="font-family:monospace;font-size:11px"><?= htmlspecialcharsbx($opt('vapid_public')) ?></textarea>
        </td>
    </tr>
    <tr>
        <td><label>VAPID Private Key:</label></td>
        <td>
            <textarea name="vapid_private" rows="3" cols="70"
                      style="font-family:monospace;font-size:11px"><?= htmlspecialcharsbx($opt('vapid_private')) ?></textarea>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><label>Включить S3:</label></td>
        <td width="60%">
            <input type="hidden"   name="s3_enabled" value="N">
            <input type="checkbox" name="s3_enabled" value="Y"
                   <?= $opt('s3_enabled') === 'Y' ? 'checked' : '' ?>>
            <span style="margin-left:8px;color:#666">
                <?= $s3Enabled ? 'сейчас: включено' : 'сейчас: выключено' ?>
            </span>
        </td>
    </tr>
    <tr>
        <td><label>Endpoint:</label></td>
        <td>
            <input type="text" size="60" name="s3_endpoint"
                   value="<?= htmlspecialcharsbx($opt('s3_endpoint')) ?>">
        </td>
    </tr>
    <tr>
        <td><label>Регион:</label></td>
        <td><input type="text" size="30" name="s3_region"
                   value="<?= htmlspecialcharsbx($opt('s3_region')) ?>"></td>
    </tr>
    <tr>
        <td><label>Bucket:</label></td>
        <td><input type="text" size="40" name="s3_bucket"
                   value="<?= htmlspecialcharsbx($opt('s3_bucket')) ?>"></td>
    </tr>
    <tr>
        <td><label>Access Key:</label></td>
        <td><input type="text" size="40" name="s3_access_key"
                   value="<?= htmlspecialcharsbx($opt('s3_access_key')) ?>"></td>
    </tr>
    <tr>
        <td><label>Secret Key:</label></td>
        <td>
            <input type="password" size="40" name="s3_secret_key" value="">
            <div class="adm-info-message" style="margin-top:6px">
                <?= $opt('s3_secret_key') !== '' ? 'Текущий ключ сохранён (скрыт).' : 'Ключ не задан.' ?>
            </div>
        </td>
    </tr>
    <tr>
        <td><label>Префикс (папка в бакете):</label></td>
        <td><input type="text" size="30" name="s3_prefix"
                   value="<?= htmlspecialcharsbx($opt('s3_prefix')) ?>"></td>
    </tr>
    <tr>
        <td><label>Path-Style (MinIO):</label></td>
        <td>
            <input type="hidden"   name="s3_path_style" value="N">
            <input type="checkbox" name="s3_path_style" value="Y"
                   <?= $opt('s3_path_style') === 'Y' ? 'checked' : '' ?>>
        </td>
    </tr>

    <tr class="heading">
        <td colspan="2">CDN — префикс для ссылок на изображения</td>
    </tr>
    <tr>
        <td><label>CDN base URL:</label></td>
        <td>
            <input type="text" size="60" name="cdn_base"
                   value="<?= htmlspecialcharsbx($opt('cdn_base')) ?>"
                   placeholder="https://cdn.x5hd.ru">
        </td>
    </tr>
    <tr>
        <td><label>Убирать /&lt;bucket&gt;/ из пути:</label></td>
        <td>
            <input type="hidden"   name="cdn_strip_bucket" value="N">
            <input type="checkbox" name="cdn_strip_bucket" value="Y"
                   <?= $opt('cdn_strip_bucket') === 'Y' ? 'checked' : '' ?>>
        </td>
    </tr>
    <tr>
        <td><label>Убирать /upload/ из пути:</label></td>
        <td>
            <input type="hidden"   name="cdn_strip_upload" value="N">
            <input type="checkbox" name="cdn_strip_upload" value="Y"
                   <?= $opt('cdn_strip_upload') === 'Y' ? 'checked' : '' ?>>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td colspan="2">
            <p>Состояние инициализации инфоблоков:
                <b><?= $ibSetupDone ? 'выполнена' : 'не выполнена' ?></b>
                · версия HL-схемы: <b><?= (int)$hlVersion ?></b>
                (актуальная: <?= (int)$hlVersionTarget ?>)
            </p>
            <table class="internal" style="width:100%">
                <tr class="heading">
                    <td width="30%">Инфоблок</td>
                    <td width="12%">ID</td>
                    <td width="15%">Элементов</td>
                    <td>Код</td>
                </tr>
                <?php foreach ($ibInfo as $key => $info): ?>
                    <tr>
                        <td><?= htmlspecialcharsbx($info['label']) ?></td>
                        <td>
                            <?php if ($info['id'] > 0): ?>
                                <?= $info['id'] ?>
                            <?php else: ?>
                                <span style="color:#c00">не задан</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($info['id'] > 0): ?>
                                <?= $info['count'] ?>
                                <?php if ($info['count'] === 0): ?>
                                    <span style="color:#a60">(пусто)</span>
                                <?php endif ?>
                            <?php else: ?>
                                —
                            <?php endif ?>
                        </td>
                        <td><?= htmlspecialcharsbx($info['code']) ?></td>
                    </tr>
                <?php endforeach ?>
            </table>
            <p style="margin-top:12px">
                Если ID = 0 или элементы не находятся — нажмите
                «Перенастроить инфоблоки».
            </p>
            <input type="submit" name="resetup_iblock" value="Перенастроить инфоблоки"
                   class="adm-btn" onclick="return confirm('Пересоздать/проверить инфоблоки?')">
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <p><b>Пересобрать дампы.</b> Заливает в S3 лекции, задания,
                конфигурацию и статистику.</p>
            <input type="submit" name="rebuild_dumps" value="Пересобрать дампы"
                   class="adm-btn" onclick="return confirm('Пересобрать все дампы в S3?')">
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><label>Интервал обновления статистики (сек):</label></td>
        <td width="60%">
            <input type="number" name="stats_interval_sec" min="60" step="60"
                   value="<?= (int)$statsInterval ?>" style="width:120px">
        </td>
    </tr>
    <tr>
        <td><label>Состояние:</label></td>
        <td>
            Последнее обновление: <b><?= htmlspecialcharsbx($lastStatsAtText) ?></b><br>
            Ближайшее: <b><?= $statsDue
                ? 'сейчас (по запросу)'
                : 'через ' . (int)$statsWaitSec . ' сек' ?></b>
        </td>
    </tr>
    <tr>
        <td><label>Последний полный дамп:</label></td>
        <td><b><?= htmlspecialcharsbx($lastDumpAtText) ?></b></td>
    </tr>
    <tr>
        <td><label>Cron-команда:</label></td>
        <td>
            <div class="adm-info-message" style="font-family:monospace;font-size:11px;white-space:pre-wrap"><?php
                $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
                echo htmlspecialcharsbx(
                    "*/5 * * * * /usr/bin/php {$docRoot}/local/modules/x5.pwa/tools/stats_cron.php >/dev/null 2>&1"
                );
            ?></div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <p><b>Обновить статистику сейчас.</b></p>
            <input type="submit" name="rebuild_stats" value="Обновить статистику" class="adm-btn">
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td width="40%"><label>Разрешённые origin (по одному в строке):</label></td>
        <td width="60%">
            <textarea name="allowed_origins" rows="6" cols="60"><?= htmlspecialcharsbx($opt('allowed_origins')) ?></textarea>
        </td>
    </tr>

    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td colspan="2">
            <p>Проверить соединение с S3.</p>
            <input type="submit" name="test_s3" value="Проверить S3" class="adm-btn">
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <p><b>Осторожно:</b> генерация новых VAPID-ключей сделает
                недействительными все существующие push-подписки.</p>
            <input type="submit" name="regenerate_vapid" value="Сгенерировать новые VAPID-ключи"
                   class="adm-btn" onclick="return confirm('Сгенерировать новые ключи?')">
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <p>
                <b>Пересоздать admin-обёртки.</b> Обновит файлы в
                <code>/bitrix/admin/</code> — они подключают реальные страницы
                из <code>/local/modules/x5.pwa/admin/</code>.
                Используйте после добавления новых admin-файлов в модуль,
                чтобы не переустанавливать его.
                Чужие файлы (не наши обёртки) не тронет.
            </p>
            <input type="submit" name="resetup_admin" value="Пересоздать admin-обёртки"
                   class="adm-btn" onclick="return confirm('Пересоздать файлы-обёртки в /bitrix/admin/?')">
        </td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="save" value="Сохранить" class="adm-btn-save">
    <input type="submit" name="restore" value="Сбросить к значениям по умолчанию"
           onclick="return confirm('Сбросить настройки?')">
    <?php $tabControl->End(); ?>
</form>