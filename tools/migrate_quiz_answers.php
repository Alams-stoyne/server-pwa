<?php
/**
 * Миграция ответов теста из свойства ANSWERS (JSON-строка) в
 * ANSWERS_LIST (множественное пользовательское свойство).
 *
 * Запуск под админом:
 *   https://api.x5hd.ru/local/modules/x5.pwa/tools/migrate_quiz_answers.php
 * Или через CLI:
 *   php /path/to/tools/migrate_quiz_answers.php
 *
 * Скрипт идемпотентный: пропускает элементы, у которых ANSWERS_LIST уже заполнен.
 * После проверки удалить.
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

\Bitrix\Main\Loader::includeModule('x5.pwa');
\Bitrix\Main\Loader::includeModule('iblock');

$ibId = \X5\Pwa\Settings::iblockId('quiz_questions');
if (!$ibId) {
    echo "IB 'quiz_questions' не найден\n";
    exit;
}

// Убедимся, что свойство ANSWERS_LIST существует
$propList = \CIBlockProperty::GetList([], [
    'IBLOCK_ID' => $ibId,
    'CODE'      => 'ANSWERS_LIST',
])->Fetch();
if (!$propList) {
    echo "Свойство ANSWERS_LIST не найдено. Сначала откройте админку, чтобы оно создалось.\n";
    exit;
}

$elements = \CIBlockElement::GetList(
    ['SORT' => 'ASC', 'ID' => 'ASC'],
    ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    ['ID', 'NAME', 'CODE', 'XML_ID']
);

$total = $processed = $skipped = 0;

while ($el = $elements->Fetch()) {
    $total++;
    $qCode = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);

    echo "── {$qCode} ({$el['NAME']})\n";

    // Уже есть новые ответы — пропускаем
    $existing = \CIBlockElement::GetProperty($ibId, (int)$el['ID'], [], ['CODE' => 'ANSWERS_LIST']);
    $hasNew = false;
    while ($p = $existing->Fetch()) {
        if (!empty($p['VALUE'])) { $hasNew = true; break; }
    }
    if ($hasNew) {
        echo "   ANSWERS_LIST уже заполнен, пропуск\n";
        $skipped++;
        continue;
    }

    // Читаем старое значение
    $old = \CIBlockElement::GetProperty($ibId, (int)$el['ID'], [], ['CODE' => 'ANSWERS']);
    $rawJson = '';
    while ($p = $old->Fetch()) {
        if (!empty($p['VALUE'])) { $rawJson = (string)$p['VALUE']; break; }
    }
    if ($rawJson === '') {
        echo "   старых ответов нет, пропуск\n";
        $skipped++;
        continue;
    }

    $answers = json_decode($rawJson, true);
    if (!is_array($answers)) {
        echo "   старый JSON не парсится, пропуск\n";
        $skipped++;
        continue;
    }

    // Пишем новые значения
    $values = [];
    foreach ($answers as $a) {
        if (!is_array($a)) continue;
        $id     = trim((string)($a['id']   ?? ''));
        $text   = trim((string)($a['text'] ?? ''));
        if ($id === '' || $text === '') continue;

        $scores = is_array($a['scores'] ?? null) ? $a['scores'] : [];
        $scoresFiltered = [];
        foreach (['energy', 'antistress', 'body', 'balance'] as $theme) {
            $v = (int)($scores[$theme] ?? 0);
            if ($v > 0) $scoresFiltered[$theme] = $v;
        }

        $payload = [
            'id'     => $id,
            'text'   => $text,
            'scores' => empty($scoresFiltered) ? new \stdClass() : $scoresFiltered,
        ];
        $values[] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    if (!$values) {
        echo "   нет валидных ответов для переноса\n";
        $skipped++;
        continue;
    }

    \CIBlockElement::SetPropertyValuesEx(
        (int)$el['ID'],
        $ibId,
        ['ANSWERS_LIST' => $values]
    );

    echo "   перенесено ответов: " . count($values) . "\n";
    $processed++;
}

echo "\n=== Итог ===\n";
echo "Всего элементов: {$total}\n";
echo "Обработано:      {$processed}\n";
echo "Пропущено:       {$skipped}\n";
echo "\nУдалите файл после проверки:\n  rm " . __FILE__ . "\n";