<?php
namespace X5\Pwa\Api;

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
            \Bitrix\Main\Diag\Debug::writeToFile(
                'BLOCKED uid=' . substr($uid, 0, 12) . '…',
                'Auth::blocked',
                '/x5.pwa.log'
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

        // Таймзона пользователя — если клиент прислал TZ_OFFSET_MIN
        // или LOCAL_DATETIME, обновляем.
        $tz = Storage::parseTzOffsetFromArray($body);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $habits = Storage::getHabits($uid);

        return [
            'RESULT'  => 'OK',
            'ID_USER' => $uid,
            'STATE'   => Storage::getState($uid),
            'READS'   => Storage::getReads($uid),
            'TASKS'   => Storage::getTasks($uid),
            'HABITS'  => empty($habits) ? (object)[] : $habits,
        ];
    }
}