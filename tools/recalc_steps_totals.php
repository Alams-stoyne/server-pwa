<?php
/**
 * Пересчёт UF_TOTAL_STEPS для всех пользователей.
 *
 * Запуск:
 *   https://api.x5hd.ru/local/modules/x5.pwa/tools/recalc_steps_totals.php
 * Или из CLI:
 *   php /path/to/tools/recalc_steps_totals.php
 *
 * Скрипт идемпотентный — можно запускать сколько угодно раз.
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
\Bitrix\Main\Loader::includeModule('highloadblock');

echo "Пересчёт UF_TOTAL_STEPS…\n";
$result = \X5\Pwa\StepsBuilder::recalcAllTotals();

echo "Готово.\n";
echo "  Обработано пользователей: " . (int)($result['processed'] ?? 0) . "\n";
echo "  Время: " . (float)($result['elapsed'] ?? 0) . " сек.\n";

// Проверка: топ-5 по шагам
$conn = \Bitrix\Main\Application::getConnection();
$rows = $conn->query(
    "SELECT UF_UID, UF_TOTAL_STEPS, UF_NICKNAME
     FROM x5_pwa_uid_bindings
     WHERE UF_TOTAL_STEPS > 0
     ORDER BY UF_TOTAL_STEPS DESC
     LIMIT 5"
)->fetchAll();

echo "\nТоп-5 пользователей по подтверждённым шагам:\n";
foreach ($rows as $r) {
    printf(
        "  %s… (%s): %d шагов\n",
        substr((string)$r['UF_UID'], 0, 8),
        $r['UF_NICKNAME'] ?: '—',
        (int)$r['UF_TOTAL_STEPS']
    );
}

echo "\nУдалите файл после проверки:\n  rm " . __FILE__ . "\n";