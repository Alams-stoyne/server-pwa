<?php
namespace X5\Pwa;

class RewardsBuilder
{
    public const TYPE_HABIT           = 'habit';
    public const TYPE_LECTURE         = 'lecture';
    public const TYPE_CHARGING        = 'charging';
    public const TYPE_TASK            = 'task';
    public const TYPE_STEPS_SENT      = 'steps_sent';
    public const TYPE_STEPS_APPROVED  = 'steps_approved';
    public const TYPE_EVENT           = 'event';

    public const ALL_TYPES = [
        self::TYPE_HABIT,
        self::TYPE_LECTURE,
        self::TYPE_CHARGING,
        self::TYPE_TASK,
        self::TYPE_STEPS_SENT,
        self::TYPE_STEPS_APPROVED,
        self::TYPE_EVENT,
    ];

    public const TITLES = [
        self::TYPE_HABIT          => 'Привычка',
        self::TYPE_LECTURE        => 'Лекция',
        self::TYPE_CHARGING       => 'Зарядка',
        self::TYPE_TASK           => 'Практика',
        self::TYPE_STEPS_SENT     => 'Шаги (отправка)',
        self::TYPE_STEPS_APPROVED => 'Шаги (подтверждено)',
        self::TYPE_EVENT          => 'Мероприятие',
    ];

    // ───────── Основная точка входа ─────────

    /**
     * Универсальное начисление.
     *
     * Если points <= 0 — ничего не делает (успех, без записи).
     * Если UF_UNIQUE_KEY уже существует — возвращает ['added' => false, 'already' => true]
     * без изменения баланса.
     *
     * @return array{ok:bool,added:bool,already:bool,points:int,balance:int}
     */
    public static function award(
        string $uid,
        string $actionType,
        string $actionId,
        int $points,
        string $title = '',
        ?string $localDate = null
    ): array {
        $uid        = trim($uid);
        $actionType = trim($actionType);
        $actionId   = trim($actionId);
        $points     = max(0, $points);

        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['ok' => false, 'added' => false, 'already' => false, 'points' => 0, 'balance' => 0];
        }
        if (!in_array($actionType, self::ALL_TYPES, true)) {
            return ['ok' => false, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }
        if ($actionId === '' || $points <= 0) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        $localDate = $localDate ?: self::userLocalDate($uid);

        $uniqueKey = md5($uid . ':' . $actionType . ':' . $actionId);

        try {
            Storage::addRewardLog([
                'uid'        => $uid,
                'actionType' => $actionType,
                'actionId'   => $actionId,
                'uniqueKey'  => $uniqueKey,
                'points'     => $points,
                'title'      => mb_substr($title, 0, 255),
                'localDate'  => $localDate,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '1062') !== false
                || stripos($msg, 'Duplicate entry') !== false
                || stripos($msg, 'uniq_x5pwa_reward_key') !== false) {
                return [
                    'ok'      => true,
                    'added'   => false,
                    'already' => true,
                    'points'  => $points,
                    'balance' => self::getBalance($uid),
                ];
            }
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award failed uid=' . substr($uid, 0, 12) . '… type=' . $actionType
                . ' id=' . $actionId . ': ' . $msg,
                'RewardsBuilder::award',
                '/x5.pwa.log'
            );
            return [
                'ok'      => false,
                'added'   => false,
                'already' => false,
                'points'  => 0,
                'balance' => self::getBalance($uid),
            ];
        }

        Storage::incrementRewardBalance($uid, $points);

        \Bitrix\Main\Diag\Debug::writeToFile(
            sprintf(
                'award uid=%s type=%s id=%s points=+%d',
                substr($uid, 0, 12) . '…', $actionType, $actionId, $points
            ),
            'RewardsBuilder::award',
            '/x5.pwa.log'
        );

        return [
            'ok'      => true,
            'added'   => true,
            'already' => false,
            'points'  => $points,
            'balance' => self::getBalance($uid),
        ];
    }

    // ───────── Хелперы для конкретных типов ─────────

    public static function awardHabit(string $uid, array $habitCfg, int $doneToday, string $localDate): array
    {
        $timesPerDay = (int)($habitCfg['timesPerDay'] ?? 1);
        $points      = (int)($habitCfg['rewardPoints'] ?? 0);

        if ($timesPerDay < 1 || $doneToday < $timesPerDay) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }
        if ($points <= 0) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        $habitId = (string)($habitCfg['id'] ?? '');
        if ($habitId === '') {
            return ['ok' => false, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        return self::award(
            $uid,
            self::TYPE_HABIT,
            $habitId . ':' . $localDate,
            $points,
            (string)($habitCfg['title'] ?? ''),
            $localDate
        );
    }

    public static function awardLecture(
        string $uid,
        int $elementId,
        string $elementType,
        int $points,
        string $title,
        string $elementDate,
        string $localDate
    ): array {
        if ($elementDate !== $localDate) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }
        if ($points <= 0) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        $actionType = $elementType === 'charging' ? self::TYPE_CHARGING : self::TYPE_LECTURE;

        return self::award(
            $uid,
            $actionType,
            $elementId . ':' . $localDate,
            $points,
            $title,
            $localDate
        );
    }

    public static function awardTask(
        string $uid,
        int $elementId,
        int $points,
        string $title,
        string $elementDate,
        string $localDate
    ): array {
        if ($elementDate !== $localDate) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }
        if ($points <= 0) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        return self::award(
            $uid,
            self::TYPE_TASK,
            $elementId . ':' . $localDate,
            $points,
            $title,
            $localDate
        );
    }

    public static function awardStepsSent(string $uid, string $localDate): array
    {
        $points = Settings::getInt('reward_steps_sent_points', 1);
        return self::award(
            $uid,
            self::TYPE_STEPS_SENT,
            $localDate,
            $points,
            'Шаги за ' . $localDate,
            $localDate
        );
    }

    public static function awardStepsApproved(string $uid, string $stepsLocalDate): array
    {
        $points = Settings::getInt('reward_steps_approved_points', 10);
        return self::award(
            $uid,
            self::TYPE_STEPS_APPROVED,
            $stepsLocalDate,
            $points,
            'Шаги подтверждены за ' . $stepsLocalDate,
            $stepsLocalDate
        );
    }

    public static function awardEvent(
        string $uid,
        string $eventId,
        int $points,
        string $title,
        string $localDate
    ): array {
        if ($points <= 0) {
            return ['ok' => true, 'added' => false, 'already' => false, 'points' => 0, 'balance' => self::getBalance($uid)];
        }

        return self::award(
            $uid,
            self::TYPE_EVENT,
            $eventId,
            $points,
            $title,
            $localDate
        );
    }

    // ───────── Резолв лекции/задания из ИБ ─────────

    /**
     * Определяет тип лекции (general/charging/theme) и её метаданные.
     *
     * @return array{type:string,title:string,date:string,points:int}|null
     */
    public static function resolveLectureInfo(int $elementId): ?array
    {
        if ($elementId <= 0) return null;

        $ibGeneral  = (int)Settings::iblockId('general_lectures');
        $ibCharging = (int)Settings::iblockId('charging');
        $ibTheme    = (int)Settings::iblockId('theme_lectures');

        $ibIds = array_values(array_filter([$ibGeneral, $ibCharging, $ibTheme]));
        if (!$ibIds) return null;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $elementId, 'IBLOCK_ID' => $ibIds, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID', 'NAME', 'ACTIVE_FROM', 'DATE_ACTIVE_FROM']
        );
        if (!$el = $rs->Fetch()) return null;

        $ibId = (int)$el['IBLOCK_ID'];
        $type = $ibId === $ibCharging ? 'charging' : 'lecture';

        $dateStr = '';
        foreach ([$el['ACTIVE_FROM'] ?? '', $el['DATE_ACTIVE_FROM'] ?? ''] as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') continue;
            $ts = \MakeTimeStamp($raw, \FORMAT_DATETIME);
            if ($ts <= 0) $ts = strtotime($raw);
            if ($ts > 0) { $dateStr = date('Y-m-d', $ts); break; }
        }
        if ($dateStr === '') return null;

        $points = 0;
        $props = \CIBlockElement::GetProperty($ibId, $elementId, [], ['CODE' => 'REWARD_POINTS']);
        if ($p = $props->Fetch()) {
            $points = max(0, (int)($p['VALUE'] ?? 0));
        }

        return [
            'type'   => $type,
            'title'  => (string)$el['NAME'],
            'date'   => $dateStr,
            'points' => $points,
        ];
    }

    /**
     * @return array{title:string,date:string,points:int}|null
     */
    public static function resolveTaskInfo(int $elementId): ?array
    {
        if ($elementId <= 0) return null;

        $ibId = (int)Settings::iblockId('tasks');
        if ($ibId <= 0) return null;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $elementId, 'IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'ACTIVE_FROM', 'DATE_ACTIVE_FROM']
        );
        if (!$el = $rs->Fetch()) return null;

        $dateStr = '';
        foreach ([$el['ACTIVE_FROM'] ?? '', $el['DATE_ACTIVE_FROM'] ?? ''] as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') continue;
            $ts = \MakeTimeStamp($raw, \FORMAT_DATETIME);
            if ($ts <= 0) $ts = strtotime($raw);
            if ($ts > 0) { $dateStr = date('Y-m-d', $ts); break; }
        }
        if ($dateStr === '') return null;

        $points = 0;
        $props = \CIBlockElement::GetProperty($ibId, $elementId, [], ['CODE' => 'REWARD_POINTS']);
        if ($p = $props->Fetch()) {
            $points = max(0, (int)($p['VALUE'] ?? 0));
        }

        return [
            'title'  => (string)$el['NAME'],
            'date'   => $dateStr,
            'points' => $points,
        ];
    }

    // ───────── Баланс / история ─────────

    public static function getBalance(string $uid): int
    {
        return Storage::getRewardBalance($uid);
    }

    public static function recalcBalance(string $uid): int
    {
        return Storage::recalcRewardBalance($uid);
    }

    public static function history(string $uid, array $params = []): array
    {
        return Storage::getRewardHistory($uid, $params);
    }

    // ───────── Хелперы ─────────

    public static function userLocalDate(string $uid): string
    {
        $tzOffsetMin = Storage::getUserTzOffsetMin($uid);
        $localMs     = (time() * 1000) + $tzOffsetMin * 60 * 1000;
        return gmdate('Y-m-d', (int)($localMs / 1000));
    }

    public static function typeTitle(string $type): string
    {
        return self::TITLES[$type] ?? $type;
    }
}