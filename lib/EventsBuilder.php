<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class EventsBuilder
{
    public const MAX_CONFIRM_HOURS      = 24;
    public const CLICK_RATE_LIMIT_HOUR  = 60;
    public const CONFIRM_RATE_LIMIT_DAY = 10;

    /** Буфер дат для CDN: сколько дней назад и вперёд генерировать. */
    public const CDN_DAYS_BACK    = 3;
    public const CDN_DAYS_FORWARD = 7;

    // ───────── Чтение событий из ИБ ─────────

    public static function getAll(): array
    {
        $ibId = Settings::iblockId('events');
        if (!$ibId) return [];

        $rs = \CIBlockElement::GetList(
            ['ACTIVE_FROM' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID'         => $ibId,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            false,
            [
                'ID', 'CODE', 'XML_ID', 'NAME', 'PREVIEW_TEXT',
                'ACTIVE_FROM', 'DATE_ACTIVE_FROM',
            ]
        );

        $out = [];
        while ($el = $rs->Fetch()) {
            $date = self::extractDateFromFields($el);
            if ($date === null) continue;

            $props = IbReader::props($ibId, (int)$el['ID']);

            $code = (string)($el['CODE'] ?: $el['XML_ID'] ?: $el['ID']);

            $out[] = [
                'ID'            => (int)$el['ID'],
                'CODE'          => $code,
                'DATE'          => $date,
                'NAME'          => (string)$el['NAME'],
                'PREVIEW_TEXT'  => (string)$el['PREVIEW_TEXT'],
                'LINK'          => (string)IbReader::prop($props, 'LINK'),
                'BUTTON_TEXT'   => (string)IbReader::prop($props, 'BUTTON_TEXT'),
                'TIMER_MINS'    => max(0, IbReader::propInt($props, 'TIMER_MINS', 0)),
                'REWARD_TEXT'   => (string)IbReader::prop($props, 'REWARD_TEXT'),
                'REWARD_POINTS' => max(0, IbReader::propInt($props, 'REWARD_POINTS', 0)),
            ];
        }
        return $out;
    }

    public static function getForCdn(?string $date = null): array
    {
        $date = $date ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        $events = [];
        foreach (self::getAll() as $e) {
            if ($e['DATE'] !== $date) continue;

            $item = [
                'id'          => $e['CODE'],
                'date'        => $e['DATE'],
                'title'       => $e['NAME'],
                'description' => $e['PREVIEW_TEXT'],
                'link'        => $e['LINK'],
                'timerMins'   => $e['TIMER_MINS'],
            ];
            if ($e['BUTTON_TEXT'] !== '') $item['buttonText'] = $e['BUTTON_TEXT'];
            if ($e['REWARD_TEXT'] !== '') $item['rewardText'] = $e['REWARD_TEXT'];
            if (int $e['REWARD_POINTS'] > 0 ) $item['rewardPoints'] = $e['REWARD_POINTS'];

            $events[] = $item;
        }

        return $events;
    }

    public static function getCdnDates(): array
    {
        $dates = [];

        foreach (self::getAll() as $e) {
            $dates[$e['DATE']] = true;
        }

        $today = date('Y-m-d');
        for ($i = -self::CDN_DAYS_BACK; $i <= self::CDN_DAYS_FORWARD; $i++) {
            $d = date('Y-m-d', strtotime("{$today} {$i} days"));
            $dates[$d] = true;
        }

        $out = array_keys($dates);
        sort($out);
        return $out;
    }

    public static function getByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;

        $ibId = Settings::iblockId('events');
        if (!$ibId) return null;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID'         => $ibId,
                '=CODE'             => $code,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            ['nTopCount' => 1],
            ['ID', 'CODE', 'NAME', 'PREVIEW_TEXT', 'ACTIVE_FROM', 'DATE_ACTIVE_FROM']
        );
        if (!$el = $rs->Fetch()) return null;

        $props = IbReader::props($ibId, (int)$el['ID']);

        return [
            'ID'            => (int)$el['ID'],
            'CODE'          => (string)$el['CODE'],
            'DATE'          => self::extractDateFromFields($el) ?? '',
            'NAME'          => (string)$el['NAME'],
            'PREVIEW_TEXT'  => (string)$el['PREVIEW_TEXT'],
            'LINK'          => (string)IbReader::prop($props, 'LINK'),
            'BUTTON_TEXT'   => (string)IbReader::prop($props, 'BUTTON_TEXT'),
            'TIMER_MINS'    => max(0, IbReader::propInt($props, 'TIMER_MINS', 0)),
            'REWARD_TEXT'   => (string)IbReader::prop($props, 'REWARD_TEXT'),
            'REWARD_POINTS' => max(0, IbReader::propInt($props, 'REWARD_POINTS', 0)),
        ];
    }

    private static function extractDateFromFields(array $fields): ?string
    {
        $candidates = [
            (string)($fields['DATE_ACTIVE']      ?? ''),
            (string)($fields['ACTIVE_FROM']      ?? ''),
            (string)($fields['DATE_ACTIVE_FROM'] ?? ''),
        ];

        foreach ($candidates as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;

            $ts = \MakeTimeStamp($raw, \FORMAT_DATETIME);
            if ($ts <= 0) $ts = strtotime($raw);
            if ($ts > 0) return date('Y-m-d', $ts);
        }

        return null;
    }

    // ───────── Запись действий ─────────

    public static function recordClick(string $uid, string $eventId): array
    {
        $uid     = trim($uid);
        $eventId = trim($eventId);

        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['ok' => false, 'reason' => 'UID_NOT_FOUND', 'clickedAt' => null, 'already' => false];
        }
        if ($eventId === '') {
            return ['ok' => false, 'reason' => 'EVENT_NOT_FOUND', 'clickedAt' => null, 'already' => false];
        }

        if (Storage::isBlocked($uid) || !Storage::getUidBinding($uid)) {
            return ['ok' => false, 'reason' => 'UID_NOT_FOUND', 'clickedAt' => null, 'already' => false];
        }

        $event = self::getByCode($eventId);
        if (!$event) {
            return ['ok' => false, 'reason' => 'EVENT_NOT_FOUND', 'clickedAt' => null, 'already' => false];
        }

        if (!self::checkClickRate($uid)) {
            return ['ok' => false, 'reason' => 'RATE_LIMITED', 'clickedAt' => null, 'already' => false];
        }

        $existing = Storage::getEventAction($uid, $eventId);

        if ($existing && !empty($existing['UF_CLICKED_AT'])) {
            return [
                'ok'        => true,
                'reason'    => 'OK',
                'clickedAt' => self::fmtDt($existing['UF_CLICKED_AT']),
                'already'   => true,
            ];
        }

        $res = Storage::upsertEventClick($uid, $eventId, $event);
        if (!$res['ok']) {
            return ['ok' => false, 'reason' => 'DB_ERROR', 'clickedAt' => null, 'already' => false];
        }

        return [
            'ok'        => true,
            'reason'    => 'OK',
            'clickedAt' => $res['clickedAt'],
            'already'   => false,
        ];
    }

    public static function recordConfirm(string $uid, string $eventId): array
    {
        $uid     = trim($uid);
        $eventId = trim($eventId);

        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return self::confirmResult('UID_NOT_FOUND');
        }
        if ($eventId === '') {
            return self::confirmResult('EVENT_NOT_FOUND');
        }

        if (Storage::isBlocked($uid) || !Storage::getUidBinding($uid)) {
            return self::confirmResult('UID_NOT_FOUND');
        }

        $event = self::getByCode($eventId);
        if (!$event) {
            return self::confirmResult('EVENT_NOT_FOUND');
        }

        $existing = Storage::getEventAction($uid, $eventId);

        if (!$existing || empty($existing['UF_CLICKED_AT'])) {
            return self::confirmResult('NO_CLICK');
        }

        if (!empty($existing['UF_CONFIRMED_AT'])) {
            return self::confirmResult(
                'ALREADY_CONFIRMED',
                self::fmtDt($existing['UF_CONFIRMED_AT']),
                (int)($existing['UF_REWARD_POINTS'] ?? 0),
                (string)($existing['UF_REWARD_TEXT'] ?? ''),
                self::fmtDt($existing['UF_CLICKED_AT'])
            );
        }

        $clickedTs = $existing['UF_CLICKED_AT']->getTimestamp();
        $nowTs     = time();
        $elapsed   = $nowTs - $clickedTs;

        $timerMin = (int)($event['TIMER_MINS'] ?? 0);
        if ($timerMin > 0 && $elapsed < $timerMin * 60) {
            return self::confirmResult(
                'TOO_EARLY',
                null, 0, '',
                self::fmtDt($existing['UF_CLICKED_AT'])
            );
        }

        if ($elapsed > self::MAX_CONFIRM_HOURS * 3600) {
            return self::confirmResult('EXPIRED', null, 0, '', self::fmtDt($existing['UF_CLICKED_AT']));
        }

        if (!self::checkConfirmRate($uid)) {
            return self::confirmResult('RATE_LIMITED');
        }

        $res = Storage::confirmEventAction($uid, $eventId, $event);
        if (!$res['ok']) {
            return self::confirmResult('DB_ERROR');
        }

        // ─── Награда за мероприятие ───
        try {
            $localDate = RewardsBuilder::userLocalDate($uid);
            RewardsBuilder::awardEvent(
                $uid,
                $eventId,
                (int)($event['REWARD_POINTS'] ?? 0),
                (string)($event['NAME'] ?? ''),
                $localDate
            );
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award event failed uid=' . substr($uid, 0, 12) . '… event=' . $eventId
                . ': ' . $e->getMessage(),
                'EventsBuilder::recordConfirm',
                '/x5.pwa.log'
            );
        }

        return self::confirmResult(
            'OK',
            $res['confirmedAt'],
            (int)$event['REWARD_POINTS'],
            (string)$event['REWARD_TEXT'],
            self::fmtDt($existing['UF_CLICKED_AT'])
        );
    }

    private static function confirmResult(
        string $reason,
        ?string $confirmedAt = null,
        int $rewardPoints = 0,
        string $rewardText = '',
        ?string $clickedAt = null
    ): array {
        return [
            'ok'           => $reason === 'OK' || $reason === 'ALREADY_CONFIRMED',
            'reason'       => $reason,
            'confirmedAt'  => $confirmedAt,
            'rewardPoints' => $rewardPoints,
            'rewardText'   => $rewardText,
            'clickedAt'    => $clickedAt,
        ];
    }

    // ───────── Списки действий ─────────

    public static function getStatesForUser(string $uid, ?string $onlyEventId = null): array
    {
        $rows = $onlyEventId !== null
            ? Storage::getEventActionsForUid($uid, $onlyEventId)
            : Storage::getEventActionsForUid($uid);

        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['UF_EVENT_ID']] = [
                'CLICKED_AT'   => self::fmtDt($r['UF_CLICKED_AT'] ?? null),
                'CONFIRMED_AT' => self::fmtDt($r['UF_CONFIRMED_AT'] ?? null),
            ];
        }
        return $out;
    }

    // ───────── Rate limits ─────────

    private static function checkClickRate(string $uid): bool
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid = $sqlHelper->forSql($uid);

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_event_actions
             WHERE UF_UID = '{$safeUid}'
               AND UF_CLICKED_AT > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->fetch();
        return (int)($row['C'] ?? 0) < self::CLICK_RATE_LIMIT_HOUR;
    }

    private static function checkConfirmRate(string $uid): bool
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid = $sqlHelper->forSql($uid);

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_event_actions
             WHERE UF_UID = '{$safeUid}'
               AND UF_CONFIRMED_AT > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetch();
        return (int)($row['C'] ?? 0) < self::CONFIRM_RATE_LIMIT_DAY;
    }

    private static function fmtDt($dt): ?string
    {
        if (!$dt) return null;
        try {
            return $dt->format('Y-m-d\TH:i:sP');
        } catch (\Throwable $e) {
            return null;
        }
    }
}