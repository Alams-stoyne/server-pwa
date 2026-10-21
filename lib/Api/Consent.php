<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class Consent
{
    /**
     * POST /consent/send
     * Body: { ID_USER, GRANTED, LOCAL_DATETIME }
     * Ответ: { RESULT: 'OK', SERVER_DATETIME, GRANTED }
     */
    public static function send(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        $granted = self::parseBool($b['GRANTED'] ?? null);
        if ($granted === null) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'GRANTED must be boolean'];
        }

        // Локальная дата согласия — приходит от клиента (ISO-строка).
        // Если клиент не прислал — фиксируем серверное время (без потери данных).
        $localDt = trim((string)($b['LOCAL_DATETIME'] ?? ''));
        if ($localDt === '') {
            $localDt = (new \Bitrix\Main\Type\DateTime())->format('Y-m-d\TH:i:sP');
        }

        // Синхронизируем таймзону пользователя, если клиент её прислал
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $result = Storage::saveConsent($uid, $granted, $localDt, 'app');
        if (!$result['ok']) {
            if ($result['reason'] === 'UID_NOT_FOUND') {
                return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.'];
            }
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить согласие.'];
        }

        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'GRANTED'         => $granted,
            'SERVER_DATETIME' => $result['serverAt'],
        ];
    }

    /**
     * POST /consent/status
     * Body: { ID_USER }
     * Ответ: { RESULT: 'OK', CONSENT: bool, CONSENT_AT, CONSENT_LOCAL_DT, HAS_CONSENT }
     */
    public static function status(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required', 'CONSENT' => false];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER', 'CONSENT' => false];
        }

        $c = Storage::getConsent($uid);

        return [
            'RESULT'           => 'OK',
            'CONSENT'          => $c['granted'],
            'CONSENT_AT'       => $c['serverAt'],
            'CONSENT_LOCAL_DT' => $c['localDt'],
            'HAS_CONSENT'      => $c['hasConsent'],
        ];
    }

    /**
     * @return bool|null  null — не удалось распознать
     */
    private static function parseBool($v): ?bool
    {
        if ($v === true  || $v === 1 || $v === '1' || $v === 'Y' || $v === 'y' || $v === 'true')  return true;
        if ($v === false || $v === 0 || $v === '0' || $v === 'N' || $v === 'n' || $v === 'false') return false;
        return null;
    }
}