<?php
namespace X5\Pwa\Api;

use X5\Pwa\ReflectionBuilder;
use X5\Pwa\Storage;

class Reflection
{
    /**
     * POST /reflection/send
     * Body: { ID_USER, TEXT, LOCAL_DATETIME }
     * Ответ: { RESULT: 'OK', SERVER_DATETIME } | { RESULT: 'ALREADY_SENT_TODAY', ... }
     */
    public static function send(array $b): array
    {
        $uid  = trim((string)($b['ID_USER'] ?? ''));
        $text = trim((string)($b['TEXT'] ?? ''));

        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if ($text === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        // Обновляем таймзону, если клиент прислал LOCAL_DATETIME / TZ_OFFSET_MIN
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        // Локальная дата пользователя
        $localDate = ReflectionBuilder::userLocalDate($uid);
        $serverNow = gmdate('Y-m-d\TH:i:s\Z');

        $result = ReflectionBuilder::send($uid, $text, $localDate, $serverNow);

        if (!$result['ok']) {
            if ($result['reason'] === 'ALREADY_SENT_TODAY') {
                return [
                    'RESULT'       => 'ALREADY_SENT_TODAY',
                    'MESSAGE'      => 'Сегодня вы уже отправляли рефлексию.',
                    'LAST_SENT_AT' => $result['sentAt'],
                ];
            }
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить.'];
        }

        // Обновляем «последний визит» — рефлексия тоже активность
        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'SERVER_DATETIME' => $result['sentAt'],
        ];
    }

    /**
     * POST /reflection/status
     * Body: { ID_USER }
     * Ответ: { RESULT: 'OK', SENT_TODAY, LAST_SENT_AT }
     */
    public static function status(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $status = ReflectionBuilder::getStatus($uid);

        return [
            'RESULT'       => 'OK',
            'SENT_TODAY'   => $status['sentToday'],
            'LAST_SENT_AT' => $status['lastSentAt'],
        ];
    }

    /**
     * GET /config/reflection.json — настройки на сегодня (по локальной дате пользователя).
     * uid опционален; без uid — берём date = today (UTC).
     */
    public static function config(array $input): array
    {
        $uid = trim((string)(
            $input['uid']
            ?? $input['UID']
            ?? $input['ID_USER']
            ?? ''
        ));

        $date = $uid !== ''
            ? ReflectionBuilder::userLocalDate($uid)
            : gmdate('Y-m-d');

        return [
            'RESULT'  => 'OK',
            'SETTING' => ReflectionBuilder::getForDate($date),
        ];
    }
}