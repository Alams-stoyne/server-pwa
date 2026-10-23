<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (!$USER->IsAdmin()) die('admin only');

Loader::includeModule('x5.pwa');
Loader::includeModule('highloadblock');
Loader::includeModule('iblock');

echo "<pre style='font:12px monospace;background:#fff;padding:16px'>";
echo "=== DIAG REWARDS ===\n\n";

$uid      = trim((string)($_GET['uid']       ?? ''));
$taskKey  = trim((string)($_GET['task_key']  ?? ''));
$lectureKey = trim((string)($_GET['lect_key'] ?? ''));

// ── 1. Пользователь
if ($uid !== '') {
    $balance = \X5\Pwa\RewardsBuilder::getBalance($uid);
    echo "1) Пользователь " . substr($uid, 0, 12) . "…\n";
    echo "   UF_REWARD_BALANCE = {$balance}\n\n";
}

// ── 2. Лог наград
echo "2) RewardLog (последние 20):\n";
try {
    $dc = \X5\Pwa\Storage::dataClass('X5PwaRewardLog');
    $filter = $uid !== '' ? ['=UF_UID' => $uid] : [];
    $rows = $dc::getList([
        'filter' => $filter,
        'order'  => ['UF_CREATED_AT' => 'DESC', 'ID' => 'DESC'],
        'limit'  => 20,
    ])->fetchAll();

    if (!$rows) {
        echo "   (пусто)\n";
    }
    foreach ($rows as $r) {
        echo sprintf(
            "   #%d uid=%s type=%s id=%s points=%+d date=%s created=%s title=%s\n",
            (int)$r['ID'],
            substr((string)$r['UF_UID'], 0, 12),
            (string)$r['UF_ACTION_TYPE'],
            (string)$r['UF_ACTION_ID'],
            (int)$r['UF_POINTS'],
            (string)$r['UF_LOCAL_DATE'],
            $r['UF_CREATED_AT'] ? $r['UF_CREATED_AT']->format('d.m.Y H:i:s') : '-',
            mb_strimwidth((string)$r['UF_TITLE'], 0, 40, '…', 'UTF-8')
        );
    }
} catch (\Throwable $e) {
    echo "   ERR: " . $e->getMessage() . "\n";
}
echo "\n";

// ── 3. Разбор taskKey
if ($taskKey !== '') {
    echo "3) Разбор ID_TASK = '{$taskKey}':\n";
    if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $taskKey, $m)) {
        $elementId = (int)$m[1];
        $taskDate  = $m[2];
        echo "   elementId = {$elementId}\n";
        echo "   taskDate  = {$taskDate}\n\n";

        $info = \X5\Pwa\RewardsBuilder::resolveTaskInfo($elementId);
        if ($info === null) {
            echo "   ❌ resolveTaskInfo вернул null.\n";
            echo "      Причины:\n";
            echo "        • элемента с ID={$elementId} нет в ИБ 'tasks';\n";
            echo "        • элемента нет с ACTIVE='Y';\n";
            echo "        • у элемента пустой/битый ACTIVE_FROM;\n";
            echo "        • ИБ 'tasks' не зарегистрирован (iblockId('tasks') = 0).\n\n";

            $ibTasks = \X5\Pwa\Settings::iblockId('tasks');
            echo "   iblockId('tasks') = {$ibTasks}\n";

            if ($ibTasks > 0) {
                $rs = \CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    ['ID' => $elementId, 'IBLOCK_ID' => $ibTasks, 'CHECK_PERMISSIONS' => 'N'],
                    false, false,
                    ['ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'DATE_ACTIVE_FROM']
                );
                if ($el = $rs->Fetch()) {
                    echo "   Элемент найден: ACTIVE={$el['ACTIVE']} ACTIVE_FROM='{$el['ACTIVE_FROM']}' DATE_ACTIVE_FROM='{$el['DATE_ACTIVE_FROM']}' NAME='{$el['NAME']}'\n";
                    $props = \CIBlockElement::GetProperty($ibTasks, $elementId, [], ['CODE' => 'REWARD_POINTS']);
                    if ($p = $props->Fetch()) {
                        echo "   REWARD_POINTS = " . var_export($p['VALUE'], true) . "\n";
                    } else {
                        echo "   ⚠️  свойства REWARD_POINTS у элемента НЕТ\n";
                    }
                } else {
                    echo "   ❌ элемента с ID={$elementId} в ИБ 'tasks' (ID={$ibTasks}) нет\n";
                }
            }
        } else {
            echo "   ✅ resolveTaskInfo:\n";
            echo "      title='{$info['title']}'\n";
            echo "      date='{$info['date']}'\n";
            echo "      points={$info['points']}\n";

            if ($uid !== '') {
                $localDate = \X5\Pwa\RewardsBuilder::userLocalDate($uid);
                echo "\n   localDate пользователя = {$localDate}\n";

                if ($info['date'] !== $localDate) {
                    echo "   ❌ taskDate ({$info['date']}) != localDate ({$localDate}) — награда НЕ начислится\n";
                } elseif ((int)$info['points'] <= 0) {
                    echo "   ❌ points=0 — награда НЕ начислится\n";
                } else {
                    echo "   ✅ всё ок — при повторном /task/complete награда начислится\n";
                }
            }
        }
    } else {
        echo "   ❌ Регексп не сработал. Ожидаемые форматы:\n";
        echo "      • {elementId}-{YYYY-MM-DD}\n";
        echo "      • {YYYY-MM-DD}:{elementId}-{YYYY-MM-DD}\n";
    }
    echo "\n";
}

// ── 4. Разбор lectureKey
if ($lectureKey !== '') {
    echo "4) Разбор ID_LECTURE = '{$lectureKey}':\n";
    if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $lectureKey, $m)) {
        $elementId = (int)$m[1];
        echo "   elementId = {$elementId}\n";
        $info = \X5\Pwa\RewardsBuilder::resolveLectureInfo($elementId);
        if ($info === null) {
            echo "   ❌ resolveLectureInfo вернул null\n";
        } else {
            echo "   ✅ type='{$info['type']}' title='{$info['title']}' date='{$info['date']}' points={$info['points']}\n";
        }
    } else {
        echo "   ❌ Регексп не сработал\n";
    }
}

echo "</pre>";