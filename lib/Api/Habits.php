<?php
namespace X5\Pwa\Api;

use X5\Pwa\HabitsBuilder;
use X5\Pwa\RewardsBuilder;
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

    public static function selection(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required', 'HABIT_ID' => null];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER', 'HABIT_ID' => null];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.', 'HABIT_ID' => null];
        }

        $binding = Storage::getUidBinding($uid);
        if (!$binding) {
            return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.', 'HABIT_ID' => null];
        }

        $habitId = Storage::getSelectedHabit($uid);

        $result = [
            'RESULT'   => 'OK',
            'HABIT_ID' => $habitId,
        ];

        if ($habitId !== null) {
            foreach (HabitsBuilder::getAll() as $h) {
                if ((string)$h['id'] === (string)$habitId) {
                    $result['HABIT_TITLE'] = (string)$h['title'];
                    break;
                }
            }
            if (!empty($binding['UF_HABIT_SELECTED_AT'])) {
                try {
                    $result['SELECTED_AT'] = $binding['UF_HABIT_SELECTED_AT']->format('Y-m-d\TH:i:sP');
                } catch (\Throwable $e) { /* ignore */ }
            }
        }

        Storage::touchBindingByUid($uid);

        return $result;
    }

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

        // ─── PD GATE (152-ФЗ) ───
        // Выбор привычки записывает UF_HABIT_ID в x5_pwa_uid_bindings — ПД.
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

    public static function complete(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? '');
        $id  = (string)($b['HABIT_ID'] ?? '');
        if (!$uid || !$id) return ['RESULT' => 'ERROR'];

        // ─── PD GATE (152-ФЗ) ───
        // Отметка привычки пишет в x5_pwa_habit_logs — обработка ПД.
        // Проверяется ДО любых записей в БД, чтобы даже в случае DAILY_LIMIT
        // не осталось orphan-записей у пользователей без согласия.
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
        $habitCfg    = null;
        $timesPerDay = null;
        $lastDoneAt  = null;
        foreach (HabitsBuilder::getAll() as $h) {
            if ((string)$h['id'] !== $id) continue;
            $habitCfg    = $h;
            $timesPerDay = (int)$h['timesPerDay'];
            break;
        }
        if ($timesPerDay === null || $habitCfg === null) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Unknown habit'];
        }

        // ─── Сколько раз уже выполнено сегодня ───
        $logs = Storage::getHabits($uid);
        $completionsToday = 0;
        $latestServerMs   = 0;

        foreach (($logs[$id] ?? []) as $dt) {
            $serverMs = self::toServerMs($dt);
            if ($serverMs <= 0) continue;

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

        // ─── Лимит уже достигнут — отказ ───
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

        // ─── Записываем ───
        $t = Storage::addHabit($uid, $id);
        Storage::setLastNotified($uid, $id);

        $doneToday = $completionsToday + 1;

        // ─── Награда (только при достижении нормы) ───
        $rewardPoints = 0;

        if ($doneToday >= $timesPerDay) {
            $hint = isset($habitCfg['timesPerDayHint'])
                ? (int)$habitCfg['timesPerDayHint']
                : 1;
            Storage::markHabitDayDone($uid, $id, $userLocalDate, $hint);

            $awardRes = RewardsBuilder::awardHabit($uid, $habitCfg, $doneToday, $userLocalDate);

            if (!empty($awardRes['added'])) {
                $rewardPoints = (int)($awardRes['points'] ?? 0);
            }
        }

        Storage::touchBindingByUid($uid);

        $response = [
            'RESULT'          => 'OK',
            'HABIT_ID'        => $id,
            'DONE_TODAY'      => $doneToday,
            'TIMES_PER_DAY'   => $timesPerDay,
            'LAST_DONE_AT'    => $t->format('Y-m-d\TH:i:sP'),
            'CAN_COMPLETE'    => $doneToday < $timesPerDay,
            'SERVER_DATETIME' => $t->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($rewardPoints > 0) {
            $response['REWARD_POINTS'] = $rewardPoints;
        }

        return $response;
    }

    private static function toServerMs($dt): int
    {
        if ($dt instanceof \Bitrix\Main\Type\DateTime
            || $dt instanceof \DateTimeInterface) {
            return $dt->getTimestamp() * 1000;
        }
        $s = trim((string)$dt);
        if ($s === '') return 0;
        $ts = strtotime($s);
        return $ts > 0 ? $ts * 1000 : 0;
    }
}