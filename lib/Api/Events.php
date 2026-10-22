<?php
namespace X5\Pwa\Api;

use X5\Pwa\EventsBuilder;
use X5\Pwa\Storage;

class Events
{
    /**
     * POST /events/status
     * Body: { ID_USER, EVENT_ID? }
     * Ответ: { RESULT: 'OK', STATES: { eventId: { CLICKED_AT, CONFIRMED_AT } } }
     */
    public static function status(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required', 'STATES' => (object)[]];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER', 'STATES' => (object)[]];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.', 'STATES' => (object)[]];
        }

        $eventId = null;
        if (isset($b['EVENT_ID']) && $b['EVENT_ID'] !== '') {
            $eventId = trim((string)$b['EVENT_ID']);
        }

        $states = EventsBuilder::getStatesForUser($uid, $eventId);

        return [
            'RESULT' => 'OK',
            'STATES' => empty($states) ? (object)[] : $states,
        ];
    }

    /**
     * POST /events/action
     * Body: { ID_USER, EVENT_ID, ACTION: 'click'|'confirm' }
     */
    public static function action(array $b): array
    {
        $uid     = trim((string)($b['ID_USER']  ?? ''));
        $eventId = trim((string)($b['EVENT_ID'] ?? ''));
        $action  = strtolower(trim((string)($b['ACTION'] ?? '')));

        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }
        if ($eventId === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'EVENT_ID required'];
        }
        if (!in_array($action, ['click', 'confirm'], true)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => "ACTION must be 'click' or 'confirm'"];
        }

        if ($action === 'click') {
            return self::handleClick($uid, $eventId);
        }

        return self::handleConfirm($uid, $eventId);
    }

    private static function handleClick(string $uid, string $eventId): array
    {
        $res = EventsBuilder::recordClick($uid, $eventId);

        if (!$res['ok']) {
            $status = 200;
            return [
                'RESULT'  => (string)$res['reason'],
                'MESSAGE' => self::clickReasonMessage((string)$res['reason']),
            ];
        }

        return [
            'RESULT'     => 'OK',
            'CLICKED_AT' => $res['clickedAt'],
            'ALREADY'    => (bool)$res['already'],
        ];
    }

    private static function handleConfirm(string $uid, string $eventId): array
    {
        $res = EventsBuilder::recordConfirm($uid, $eventId);

        switch ($res['reason']) {
            case 'OK':
                return [
                    'RESULT'        => 'OK',
                    'CLICKED_AT'    => $res['clickedAt'],
                    'CONFIRMED_AT'  => $res['confirmedAt'],
                    'REWARD_TEXT'   => $res['rewardText'],
                    'REWARD_POINTS' => (int)$res['rewardPoints'],
                ];

            case 'ALREADY_CONFIRMED':
                return [
                    'RESULT'        => 'ALREADY_CONFIRMED',
                    'MESSAGE'       => 'Событие уже подтверждено. Награда не начисляется повторно.',
                    'CLICKED_AT'    => $res['clickedAt'],
                    'CONFIRMED_AT'  => $res['confirmedAt'],
                    'REWARD_TEXT'   => $res['rewardText'],
                    'REWARD_POINTS' => (int)$res['rewardPoints'],
                ];

            case 'TOO_EARLY':
                return [
                    'RESULT'     => 'TOO_EARLY',
                    'MESSAGE'    => 'Таймер ещё не истёк. Подождите и попробуйте снова.',
                    'CLICKED_AT' => $res['clickedAt'],
                ];

            case 'NO_CLICK':
                return [
                    'RESULT'  => 'NO_CLICK',
                    'MESSAGE' => 'Сначала нужно открыть событие (click), потом подтвердить.',
                ];

            case 'EXPIRED':
                return [
                    'RESULT'  => 'EXPIRED',
                    'MESSAGE' => 'Время на подтверждение истекло (больше 24 часов с момента клика).',
                ];

            case 'EVENT_NOT_FOUND':
                return ['RESULT' => 'EVENT_NOT_FOUND', 'MESSAGE' => 'Событие не найдено.'];

            case 'UID_NOT_FOUND':
                return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.'];

            case 'RATE_LIMITED':
                return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Слишком много подтверждений за сутки.'];

            default:
                return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить действие.'];
        }
    }

    private static function clickReasonMessage(string $reason): string
    {
        switch ($reason) {
            case 'EVENT_NOT_FOUND': return 'Событие не найдено.';
            case 'UID_NOT_FOUND':   return 'Пользователь не найден.';
            case 'RATE_LIMITED':    return 'Слишком много кликов за час.';
            default:                return 'Не удалось записать клик.';
        }
    }
}