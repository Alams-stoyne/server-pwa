<?php
namespace X5\Pwa;

class HabitsBuilder
{
    private static ?array $cache = null;

    private const DEFAULT_HINT = 'Выполните текущую полезную активность.';
    private const DEFAULT_NOTIFY_FROM_TIME = '09:00';

    public static function getAll(): array
    {
        if (self::$cache !== null) return self::$cache;

        $ibId = Settings::iblockId('habits');
        if (!$ibId) return self::$cache = [];

        $elements = IbReader::elements($ibId);
        $out = [];

        foreach ($elements as $el) {
            $props = IbReader::props($ibId, $el['ID']);

            $habitId = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);

            $hint = IbReader::prop($props, 'HINT');
            if ($hint === '') $hint = self::DEFAULT_HINT;

            $notifyFromTime = IbReader::prop($props, 'NOTIFY_FROM_TIME');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $notifyFromTime)) {
                $notifyFromTime = self::DEFAULT_NOTIFY_FROM_TIME;
            }

            $out[] = [
                'id'              => $habitId,
                'title'           => $el['NAME'],
                'description'     => $el['PREVIEW_TEXT'],
                'task'            => $el['DETAIL_TEXT'],
                'picture'         => IbReader::imageUrl($el['PREVIEW_PICTURE']),
                'timesPerDay'     => max(1, IbReader::propInt($props, 'TIMES_PER_DAY', 1)),
                'minIntervalMin'  => max(0, IbReader::propInt($props, 'MIN_INTERVAL_MIN', 0)),
                'hint'            => $hint,
                'timesPerDayHint' => max(1, IbReader::propInt($props, 'TIMES_PER_DAY_HINT', 1)),
                'minIntervalHint' => max(0, IbReader::propInt($props, 'MIN_INTERVAL_HINT', 0)),
                'notifyFromTime'  => $notifyFromTime,
                'rewardPoints'    => max(0, IbReader::propInt($props, 'REWARD_POINTS', 0)),
            ];
        }

        return self::$cache = $out;
    }

    public static function getForUser(string $uid): array
    {
        $habits = self::getAll();
        if (!$habits) return [];

        $tzOffsetMin  = Storage::getUserTzOffsetMin($uid);
        $offsetMs     = $tzOffsetMin * 60 * 1000;
        $userLocalMs  = (time() * 1000) + $offsetMs;
        $userLocalDate = gmdate('Y-m-d', (int)($userLocalMs / 1000));

        $logs = Storage::getHabits($uid);

        foreach ($habits as &$h) {
            $list = $logs[$h['id']] ?? [];
            $list = array_values($list);

            $completionsToday = 0;
            $latestServerMs   = 0;

            foreach ($list as $dt) {
                $serverMs  = strtotime((string)$dt) * 1000;
                $localMs   = $serverMs + $offsetMs;
                $localDate = gmdate('Y-m-d', (int)($localMs / 1000));

                if ($localDate !== $userLocalDate) continue;

                $completionsToday++;
                if ($serverMs > $latestServerMs) $latestServerMs = $serverMs;
            }

            $h['doneToday']   = $completionsToday;
            $h['lastDoneAt']  = $latestServerMs > 0
                ? date('Y-m-d\TH:i:sP', (int)($latestServerMs / 1000))
                : null;
            $h['canComplete'] = $completionsToday < (int)$h['timesPerDay'];
        }
        unset($h);

        return $habits;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}