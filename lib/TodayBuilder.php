<?php
namespace X5\Pwa;

class TodayBuilder
{
    /**
     * Собирает виджет «Сегодня» для пользователя.
     *
     * Возвращает:
     *   [
     *     'id'           => 'done-2026-10-15',
     *     'state'        => 'done' | 'active',
     *     'title'        => '...',
     *     'description'  => '...',
     *     'buttonLabel'  => '...',        // только для active
     *     'buttonAction' => ['type'=>'internal','url'=>'/stats'],
     *   ]
     */
    public static function buildForUser(string $uid): array
    {
        $today = gmdate('Y-m-d');

        $habits        = HabitsBuilder::getForUser($uid);
        $userLogs      = Storage::getHabits($uid);

        $totalDone    = 0;
        $allHabitsDone = true;

        if (empty($habits)) {
            // Если привычек вообще нет — считаем, что все выполнены,
            // но не блокируем виджет преждевременно
            $allHabitsDone = true;
        } else {
            foreach ($habits as $h) {
                $list = $userLogs[$h['id']] ?? [];
                $list = array_values($list);

                $todayCount = 0;
                foreach ($list as $dt) {
                    if (substr((string)$dt, 0, 10) === $today) $todayCount++;
                }

                $totalDone += $todayCount;

                if ($todayCount < (int)$h['timesPerDay']) {
                    $allHabitsDone = false;
                }
            }
        }

        // Прочитанные лекции сегодня
        $reads = Storage::getReads($uid);
        $readsToday = 0;
        foreach ($reads as $key) {
            if (strpos((string)$key, $today) !== false) $readsToday++;
        }

        // ─── Сценарий ───
        if ($allHabitsDone && $readsToday > 0) {
            $scenario = 'done_all';
        } elseif ($allHabitsDone) {
            $scenario = 'done_habits';
        } elseif ($totalDone === 0) {
            $scenario = 'empty';
        } else {
            $scenario = 'progress';
        }

        $state = ($scenario === 'done_all') ? 'done' : 'active';

        // ─── Пытаемся взять текст из IB ───
        $widget = self::loadWidget($scenario);
        if (!$widget) {
            $widget = self::fallback($scenario, $totalDone);
        }

        $widget['id']    = $scenario . '-' . $today;
        $widget['state'] = $state;

        // Кнопка — только для активных состояний
        if ($state === 'done') {
            unset($widget['buttonLabel'], $widget['buttonAction']);
        }

        return $widget;
    }

    /**
     * Ищет виджет по сценарию в инфоблоке X5 PWA — Виджеты Today.
     * Приоритет — элемент с PROPERTY_SCENARIO = $scenario. Если нет —
     * элемент с PROPERTY_STATE = 'active'|'done' (упрощённый вариант).
     */
    private static function loadWidget(string $scenario): ?array
    {
        $ibId = Settings::iblockId('today_widgets');
        if (!$ibId) return null;

        // 1) Пробуем по SCENARIO
        $elements = IbReader::elements(
            $ibId,
            ['PROPERTY_SCENARIO' => $scenario],
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            1
        );

        // 2) Fallback по STATE — если SCENARIO не задано
        if (!$elements) {
            $state = ($scenario === 'done_all') ? 'done' : 'active';
            $elements = IbReader::elements(
                $ibId,
                ['PROPERTY_STATE' => $state],
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                1
            );
        }

        if (!$elements) return null;

        $el    = $elements[0];
        $props = IbReader::props($ibId, $el['ID']);

        $title       = IbReader::prop($props, 'TITLE');
        $description = IbReader::prop($props, 'DESCRIPTION');

        $widget = [
            'title'       => $title !== '' ? $title : $el['NAME'],
            'description' => $description,
        ];

        $btnLabel = IbReader::prop($props, 'BUTTON_LABEL');
        if ($btnLabel !== '') {
            $widget['buttonLabel'] = $btnLabel;
            $widget['buttonAction'] = [
                'type' => IbReader::prop($props, 'BUTTON_ACTION') ?: 'internal',
                'url'  => IbReader::prop($props, 'BUTTON_URL'),
            ];
        }

        return $widget;
    }

    /**
     * Тексты-заглушки, идентичные мок-серверу.
     */
    private static function fallback(string $scenario, int $totalDone): array
    {
        switch ($scenario) {
            case 'done_all':
                return [
                    'title'       => 'Все дела на сегодня закрыты',
                    'description' => 'Вы выполнили ' . $totalDone
                        . ' привычек и прочитали материалы. Отличная работа!',
                ];

            case 'done_habits':
                return [
                    'title'        => 'Привычки выполнены!',
                    'description'  => 'Посмотрите, как изменились ваши показатели за неделю.',
                    'buttonLabel'  => 'Смотреть статистику',
                    'buttonAction' => ['type' => 'internal', 'url' => '/stats'],
                ];

            case 'empty':
                return [
                    'title'        => 'Доброе утро!',
                    'description'  => 'Начните день с маленького шага — выполните первую привычку.',
                    'buttonLabel'  => 'К привычкам',
                    'buttonAction' => ['type' => 'internal', 'url' => '/'],
                ];

            case 'progress':
            default:
                return [
                    'title'        => 'Продолжайте в том же духе',
                    'description'  => 'Выполнено ' . $totalDone . ' привычек. Осталось немного.',
                    'buttonLabel'  => 'Продолжить',
                    'buttonAction' => ['type' => 'internal', 'url' => '/'],
                ];
        }
    }
}