<?php
namespace X5\Pwa\Api;

use X5\Pwa\HabitsBuilder;
use X5\Pwa\Storage;

class Habits
{
    public static function list(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? $b['UID'] ?? '');
        if (!$uid) return ['RESULT' => 'ERROR'];

        Storage::touchBindingByUid($uid);

        $h = Storage::getHabits($uid);

        return [
            'RESULT'         => 'OK',
            'HABITS'         => empty($h) ? (object)[] : $h,
            'SELECTED_HABIT' => Storage::getSelectedHabit($uid),
        ];
    }

    /**
     * POST /habits/select
     * Body: { ID_USER, HABIT_ID }
     * Ответ:
     *   OK               — привычка выбрана впервые (или повторно тот же id)
     *   ALREADY_SELECTED — уже выбрана другая привычка, менять нельзя
     */
    public static function select(array $b): array
    {
        $uid     = trim((string)($b['ID_USER']  ?? ''));
        $habitId = trim((string)($b['HABIT_ID'] ?? ''));

        if ($uid === '' || $habitId === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER and HABIT_ID required'];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        // Проверяем, что привычка есть в справочнике
        $exists = false;
        foreach (HabitsBuilder::getAll() as $h) {
            if ((string)$h['id'] === $habitId) { $exists = true; break; }
        }
        if (!$exists) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Unknown habit'];
        }

        $result = Storage::selectHabit($uid, $habitId);

        if (!$result['ok']) {
            if ($result['reason'] === 'ALREADY_SELECTED') {
                return [
                    'RESULT'      => 'ALREADY_SELECTED',
                    'MESSAGE'     => 'Привычка уже выбрана. Изменить нельзя.',
                    'HABIT_ID'    => $result['habitId'],
                    'SELECTED_AT' => $result['selectedAt'],
                ];
            }
            if ($result['reason'] === 'UID_NOT_FOUND') {
                return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.'];
            }
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить привычку.'];
        }

        Storage::touchBindingByUid($uid);

        return [
            'RESULT'      => 'OK',
            'HABIT_ID'    => $result['habitId'],
            'SELECTED_AT' => $result['selectedAt'],
        ];
    }

    /**
     * Отметка выполнения привычки.
     */
    public static function complete(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? '');
        $id  = (string)($b['HABIT_ID'] ?? '');
        if (!$uid || !$id) return ['RESULT' => 'ERROR'];

        // ─── Таймзона и локальная дата пользователя ───
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        } else {
            $tz = Storage::getUserTzOffsetMin($uid);
        }
        $offsetMs       = $tz * 60 * 1000;
        $userLocalMs    = (time() * 1000) + $offsetMs;
        $userLocalDate  = gmdate('Y-m-d', (int)($userLocalMs / 1000));

        // ─── Настройки привычки ───
        $timesPerDay = null;
        $lastDoneAt  = null;
        foreach (HabitsBuilder::getAll() as $h) {
            if ($h['id'] !== $id) continue;
            $timesPerDay = (int)$h['timesPerDay'];
            break;
        }
        if ($timesPerDay === null) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Unknown habit'];
        }

        // ─── Сколько раз уже выполнено сегодня ───
        $logs = Storage::getHabits($uid);
        $completionsToday = 0;
        $latestServerMs   = 0;

        foreach (($logs[$id] ?? []) as $dt) {
            $serverMs  = strtotime((string)$dt) * 1000;
            $localMs   = $serverMs + $offsetMs;
            $localDate = gmdate('Y-m-d', (int)($localMs / 1000));

            if ($localDate !== $userLocalDate) continue;

            $completionsToday++;
            if ($serverMs > $latestServerMs) {
                $latestServerMs = $serverMs;
            }
        }

        if ($latestServerMs > 0) {
            $lastDoneAt = date('Y-m-d\TH:i:sP', (int)($latestServerMs / 1000));
        }

        if ($completionsToday >= $timesPerDay) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                sprintf(
                    'LIMIT_REACHED uid=%s habit=%s doneToday=%d/%d localDate=%s',
                    substr($uid, 0, 12) . '…', $id,
                    $completionsToday, $timesPerDay, $userLocalDate
                ),
                'Habits::complete',
                '/x5.pwa.log'
            );

            return [
                'RESULT'        => 'LIMIT_REACHED',
                'MESSAGE'       => 'На сегодня норма выполнения привычки исчерпана.',
                'HABIT_ID'      => $id,
                'DONE_TODAY'    => $completionsToday,
                'TIMES_PER_DAY' => $timesPerDay,
                'LAST_DONE_AT'  => $lastDoneAt,
                'CAN_COMPLETE'  => false,
            ];
        }

        $t = Storage::addHabit($uid, $id);
        Storage::setLastNotified($uid, $id);
        Storage::touchBindingByUid($uid);

        $doneToday = $completionsToday + 1;

        return [
            'RESULT'          => 'OK',
            'HABIT_ID'        => $id,
            'DONE_TODAY'      => $doneToday,
            'TIMES_PER_DAY'   => $timesPerDay,
            'LAST_DONE_AT'    => $t->format('Y-m-d\TH:i:sP'),
            'CAN_COMPLETE'    => $doneToday < $timesPerDay,
            'SERVER_DATETIME' => $t->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}