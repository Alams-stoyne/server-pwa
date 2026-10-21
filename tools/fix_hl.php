<?php
/**
 * Восстанавливает недостающие пользовательские поля (UF_*)
 * в HL-блоках модуля x5.pwa.
 *
 * Безопасно запускать многократно.
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (PHP_SAPI !== 'cli') {
    global $USER;
    if (!$USER->IsAdmin()) { http_response_code(403); die('Access denied'); }
    header('Content-Type: text/plain; charset=utf-8');
}

\Bitrix\Main\Loader::includeModule('highloadblock');
\Bitrix\Main\Loader::includeModule('x5.pwa');

$config = \X5\Pwa\HlSetup::config();

echo "Обработка HL-блоков модуля x5.pwa\n";
echo str_repeat('=', 50) . "\n";

foreach ($config as $key => $cfg) {
    echo "\n[{$key}] {$cfg['name']} / {$cfg['table']}\n";

    $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
        'filter' => ['=NAME' => $cfg['name']],
        'limit'  => 1,
    ])->fetch();

    if (!$hl) {
        echo "  HL-блок НЕ найден, создаю...\n";
        $res = \Bitrix\Highloadblock\HighloadBlockTable::add([
            'NAME'       => $cfg['name'],
            'TABLE_NAME' => $cfg['table'],
        ]);
        if (!$res->isSuccess()) {
            echo '  ОШИБКА создания: ' . implode('; ', $res->getErrorMessages()) . "\n";
            continue;
        }
        $hlId = (int)$res->getId();
        echo "  Создан HL-блок ID={$hlId}\n";
    } else {
        $hlId = (int)$hl['ID'];
        echo "  HL-блок существует, ID={$hlId}\n";
    }

    $entityId = 'HLBLOCK_' . $hlId;

    foreach ($cfg['fields'] as $fieldName => $spec) {
        [$type, $settings] = $spec;

        $existing = \CUserTypeEntity::GetList([], [
            'ENTITY_ID'  => $entityId,
            'FIELD_NAME' => $fieldName,
        ])->Fetch();

        if ($existing) {
            echo "    {$fieldName}: уже есть (ID={$existing['ID']})\n";
            continue;
        }

        $uf = new \CUserTypeEntity();
        $ok = $uf->Add([
            'ENTITY_ID'         => $entityId,
            'FIELD_NAME'        => $fieldName,
            'USER_TYPE_ID'      => $type,
            'MANDATORY'         => 'N',
            'SHOW_FILTER'       => 'Y',
            'SHOW_IN_LIST'      => 'Y',
            'EDIT_IN_LIST'      => 'Y',
            'SETTINGS'          => $settings,
            'EDIT_FORM_LABEL'   => ['ru' => $fieldName],
            'LIST_COLUMN_LABEL' => ['ru' => $fieldName],
            'LIST_FILTER_LABEL' => ['ru' => $fieldName],
        ]);

        if ($ok) {
            echo "    {$fieldName}: СОЗДАНО ({$type})\n";
        } else {
            echo "    {$fieldName}: ОШИБКА — " . $uf->LAST_ERROR
               . ' (тип ' . $type . ')' . "\n";
        }
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Готово.\n";

echo "\nСодержимое таблиц:\n";
foreach ($config as $cfg) {
    $hl = \Bitrix\Highloadblock\HighloadBlockTable::getList([
        'filter' => ['=NAME' => $cfg['name']],
        'limit'  => 1,
    ])->fetch();
    if (!$hl) { echo "  {$cfg['table']}: НЕТ\n"; continue; }

    $cnt = \Bitrix\Main\Application::getConnection()
        ->query('SELECT COUNT(*) AS C FROM `' . $cfg['table'] . '`')
        ->fetch()['C'];
    echo "  {$cfg['table']}: ROWS={$cnt}\n";
}