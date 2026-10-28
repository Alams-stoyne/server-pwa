<?php
namespace X5\Pwa\Api;

use X5\Pwa\EventsBuilder;
use X5\Pwa\Storage;

class Events
{
    /**
     * POST /events/status
     *
     * Возвращает два блока:
     *   • STATUS — активность пользователя (обратная совместимость);
     *   • STATES — статусы клика/подтверждения по каждому событию.
     */
    public static function status(array $input): array
    {
        $uid = trim((string)(
            $input['uid']
            ?? $input['UID']
            ?? $input['ID_USER']
            ?? ''
        ));

        if ($uid === '') {
            return [
                'RESULT'  => 'ERROR',
                'MESSAGE' => 'uid required',
            ];
        }

        // ─── Блок 1: активность (как было) ───
        $stats = [
            'HABITS'    => [],
            'LAST_READ' => null,
            'LAST_TASK' => null,
            'LAST_HABIT'=> null,
        ];

        $reads     = Storage::getReads($uid);
        $tasks     = Storage::getTasks($uid);
        $habitLogs = Storage::getHabits($uid);

        $lastRead = null;
        foreach ($reads as $r) {
            $ts = strtotime((string)$r);
            if ($ts && (!$lastRead || $ts > $lastRead)) $lastRead = $ts;
        }
        $stats['LAST_READ'] = $lastRead ? date('Y-m-d\TH:i:sP', $lastRead) : null;

        $lastTask = null;
        foreach ($tasks as $t) {
            $ts = strtotime((string)$t);
            if ($ts && (!$lastTask || $ts > $lastTask)) $lastTask = $ts;
        }
        $stats['LAST_TASK'] = $lastTask ? date('Y-m-d\TH:i:sP', $lastTask) : null;

        $lastHabit = null;
        foreach ($habitLogs as $habitId => $logs) {
            foreach ((array)$logs as $log) {
                $ts = strtotime((string)$log);
                if ($ts && (!$lastHabit || $ts > $lastHabit)) $lastHabit = $ts;
            }
        }
        $stats['LAST_HABIT'] = $lastHabit ? date('Y-m-d\TH:i:sP', $lastHabit) : null;

        // ─── Блок 2: статусы событий (новое) ───
        $states = [];
        try {
            $states = EventsBuilder::getStatesForUser($uid);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'events/status: getStates failed uid=' . substr($uid, 0, 12) . '…: '
                . $e->getMessage(),
                'Events::status',
                '/x5.pwa.log'
            );
        }

        return [
            'RESULT' => 'OK',
            'STATUS' => $stats,
            'STATES' => empty($states) ? (object)[] : $states,
        ];
    }

    /**
     * Действие пользователя над событием: click | confirm.
     *
     * PD consent проверяется здесь. EventsBuilder::recordClick/recordConfirm
     * валидирует блокировку, rate-limit, таймер, начисление наград — но
     * ПД-гейт должен стоять ДО них, чтобы клик/подтверждение не писали
     * в x5_pwa_event_actions без явного согласия.
     */
    public static function action(array $input): array
    {
        $uid = trim((string)(
            $input['ID_USER']
            ?? $input['UID']
            ?? $input['uid']
            ?? ''
        ));

        $action = strtolower(trim((string)(
            $input['ACTION']
            ?? $input['action']
            ?? ''
        )));

        $eventId = trim((string)(
            $input['EVENT_ID']
            ?? $input['eventId']
            ?? $input['event_id']
            ?? ''
        ));

        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if ($action === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ACTION required'];
        }
        if ($eventId === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'EVENT_ID required'];
        }

        // ─── PD GATE (152-ФЗ) ───
        // click/confirm пишут в x5_pwa_event_actions — обработка ПД.
        $binding = Storage::getUidBinding($uid);
        if (!$binding) {
            return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.'];
        }
        if (($binding['UF_BLOCKED'] ?? 'N') === 'Y') {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }
        if (($binding['UF_CONSENT_GRANTED'] ?? '') !== 'Y') {
            return [
                'RESULT'  => 'CONSENT_REQUIRED',
                'MESSAGE' => 'Необходимо согласие на обработку персональных данных.',
            ];
        }

        // ─── ACTION = click ───
        if ($action === 'click') {
            $r = EventsBuilder::recordClick($uid, $eventId);

            if (!$r['ok']) {
                return [
                    'RESULT'   => 'ERROR',
                    'REASON'   => $r['reason'],
                    'MESSAGE'  => self::reasonMessage($r['reason']),
                    'ID_USER'  => $uid,
                    'EVENT_ID' => $eventId,
                    'ACTION'   => $action,
                ];
            }

            $out = [
                'RESULT'     => 'OK',
                'ID_USER'    => $uid,
                'EVENT_ID'   => $eventId,
                'ACTION'     => $action,
                'CLICKED_AT' => $r['clickedAt'],
                'ALREADY'    => !empty($r['already']),
            ];

            if (!empty($r['confirmedAt'])) {
                $out['CONFIRMED_AT']  = $r['confirmedAt'];
                $out['REWARD_POINTS'] = (int)($r['rewardPoints'] ?? 0);
                $out['REWARD_TEXT']   = (string)($r['rewardText'] ?? '');
            }

            return $out;
        }

        // ─── ACTION = confirm ───
        if ($action === 'confirm') {
            $r = EventsBuilder::recordConfirm($uid, $eventId);

            if (!$r['ok']) {
                return [
                    'RESULT'     => 'ERROR',
                    'REASON'     => $r['reason'],
                    'MESSAGE'    => self::reasonMessage($r['reason']),
                    'ID_USER'    => $uid,
                    'EVENT_ID'   => $eventId,
                    'ACTION'     => $action,
                    'CLICKED_AT' => $r['clickedAt'] ?? null,
                ];
            }

            return [
                'RESULT'        => 'OK',
                'ID_USER'       => $uid,
                'EVENT_ID'      => $eventId,
                'ACTION'        => $action,
                'CLICKED_AT'    => $r['clickedAt']    ?? null,
                'CONFIRMED_AT'  => $r['confirmedAt']  ?? null,
                'REWARD_POINTS' => (int)($r['rewardPoints'] ?? 0),
                'REWARD_TEXT'   => (string)($r['rewardText'] ?? ''),
                'ALREADY'       => $r['reason'] === 'ALREADY_CONFIRMED',
            ];
        }

        // ─── Прочие ACTION ───
        return [
            'RESULT'  => 'ERROR',
            'MESSAGE' => 'Unknown ACTION: ' . $action,
            'ALLOWED' => ['click', 'confirm'],
        ];
    }

    private static function reasonMessage(string $reason): string
    {
        return [
            'OK'                => 'OK',
            'UID_NOT_FOUND'     => 'Пользователь не найден или заблокирован.',
            'EVENT_NOT_FOUND'   => 'Событие не найдено.',
            'RATE_LIMITED'      => 'Слишком много действий. Попробуйте позже.',
            'NO_CLICK'          => 'Сначала нужно нажать на событие (ACTION=click).',
            'ALREADY_CONFIRMED' => 'Событие уже подтверждено ранее.',
            'TOO_EARLY'         => 'Слишком рано подтверждать — таймер ещё не истёк.',
            'EXPIRED'           => 'Подтверждение устарело (прошло больше 24 часов).',
            'DB_ERROR'          => 'Ошибка записи. Попробуйте ещё раз.',
        ][$reason] ?? $reason;
    }
}