<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
use X5\Pwa\Settings;
use X5\Pwa\Storage;
use X5\Pwa\RateLimit;

class Auth
{
    public static function auth(array $body): array
    {
        $uid             = trim((string)($body['UID'] ?? ''));
        $deviceId        = trim((string)($body['DEVICE_ID'] ?? ''));
        $deviceRotatedAt = (int)($body['DEVICE_ROTATED_AT'] ?? 0);
        $deviceCreatedAt = (int)($body['DEVICE_ID_CREATED_AT'] ?? 0);

        if ($uid === '' || $deviceId === '') {
            return ['RESULT' => 'ERROR', 'ID_USER' => null];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'UID_NOT_FOUND', 'ID_USER' => null];
        }

        if (Storage::isBlocked($uid)) {
            Settings::log(
                'BLOCKED uid=' . substr($uid, 0, 12) . '…',
                'Auth::blocked'
            );
            return [
                'RESULT'  => 'BLOCKED',
                'MESSAGE' => 'Доступ приостановлен. Обратитесь к администратору.',
                'ID_USER' => null,
            ];
        }

        $rl1 = RateLimit::check('device:' . $deviceId);
        $rl2 = RateLimit::check('ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'x'));
        if (!$rl1['ok'] || !$rl2['ok']) {
            return [
                'RESULT'         => 'RATE_LIMITED',
                'RETRY_AFTER_MS' => max($rl1['retryAfterMs'], $rl2['retryAfterMs']),
                'ID_USER'        => null,
            ];
        }

        $lookup         = Storage::lookupBinding($uid, $deviceId);
        $uidBinding     = $lookup['binding'];
        $deviceBoundUid = $lookup['deviceBoundUid'];

        if ($uidBinding && $uidBinding['UF_DEVICE_ID'] !== $deviceId) {
            $rotatedRecently = $deviceRotatedAt > 0
                && (time() * 1000 - $deviceRotatedAt) < 60_000;
            if ($rotatedRecently) {
                return ['RESULT' => 'DEVICE_ROTATED', 'TAKEN_UID' => $uid, 'ID_USER' => null];
            }
            return ['RESULT' => 'UID_TAKEN', 'TAKEN_UID' => $uid, 'ID_USER' => null];
        }
        if ($deviceBoundUid && $deviceBoundUid !== $uid) {
            return ['RESULT' => 'UID_LOCKED', 'LOCKED_UID' => $deviceBoundUid, 'ID_USER' => null];
        }

        if (!$uidBinding) Storage::saveBinding($uid, $deviceId, $deviceCreatedAt);
        else              Storage::touchBinding((int)$uidBinding['ID']);

        // TZ
        $tz = Storage::parseTzOffsetFromArray($body);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $habits        = Storage::getHabits($uid);
        $storedState   = Storage::getState($uid);
        $consent       = Storage::getConsent($uid);
        $selectedHabit = Storage::getSelectedHabit($uid);

        // Локальная дата пользователя (по сохранённому TZ)
        $localDate = RewardsBuilder::userLocalDate($uid);
        $limitsUsed = Config::usedCounters($uid, $localDate);

        // ─── Склеиваем STATE с серверными полями ───
        $state = is_array($storedState) ? $storedState : [];

        $state['HABIT_SELECTED'] = $selectedHabit;

        $state['CONSENT'] = [
            'ANSWERED' => (bool)$consent['hasConsent'],
            'GRANTED'  => (bool)$consent['granted'],
            'AT'       => $consent['serverAt'],
        ];

        $state['REWARDS'] = [
            'BALANCE' => RewardsBuilder::getBalance($uid),
        ];

        return [
            'RESULT'  => 'OK',
            'ID_USER' => $uid,
            'STATE'   => $state,
            'READS'   => Storage::getReads($uid),
            'TASKS'   => Storage::getTasks($uid),
            'HABITS'  => empty($habits) ? (object)[] : $habits,

            // Персональные счётчики использованных наград за локальный день
            'LIMITS_USED' => [
                'LOCAL_DATE'      => $localDate,
                'LECTURE_THEME'   => (int)$limitsUsed['LECTURE_THEME'],
                'LECTURE_GENERAL' => (int)$limitsUsed['LECTURE_GENERAL'],
                'CHARGING'        => (int)$limitsUsed['CHARGING'],
                'TASK'            => (int)$limitsUsed['TASK'],
            ],
        ];
    }
}