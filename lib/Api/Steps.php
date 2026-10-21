<?php
namespace X5\Pwa\Api;

use X5\Pwa\StepsBuilder;
use X5\Pwa\Storage;

class Steps
{
    /**
     * POST /steps/send
     * Body: { ID_USER, STEPS, IMAGES:[base64], CONSENT, LOCAL_DATETIME }
     */
    public static function send(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        // Обновляем таймзону, если пришла
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $localDate = StepsBuilder::userLocalDate($uid);

        $result = StepsBuilder::send(
            $uid,
            $b['STEPS']  ?? null,
            $b['IMAGES'] ?? null,
            $b['CONSENT']?? null,
            $localDate
        );

        // Обновляем «последний визит» — шаги тоже активность
        if (($result['RESULT'] ?? '') === 'OK') {
            Storage::touchBindingByUid($uid);
        }

        return $result;
    }

    /**
     * POST /steps/summary
     * Body: { ID_USER }
     */
    public static function summary(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $summary = StepsBuilder::getSummary($uid);

        return array_merge(['RESULT' => 'OK'], $summary);
    }
}