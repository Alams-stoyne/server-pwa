<?php
namespace X5\Pwa\Api;

use X5\Pwa\StatsBuilder;
use X5\Pwa\StepsBuilder;
use X5\Pwa\Storage;

class Steps
{
    public static function send(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

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

        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $stepDate = trim((string)($b['STEP_DATE'] ?? ''));

        $result = StepsBuilder::send(
            $uid,
            $b['STEPS']   ?? null,
            $b['IMAGES']  ?? null,
            $b['CONSENT'] ?? null,
            $stepDate
        );

        if (($result['RESULT'] ?? '') === 'OK') {
            Storage::touchBindingByUid($uid);
        }

        return $result;
    }

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

        [$periodFrom, $periodTo] = StatsBuilder::getEffectivePeriod();

        $confirmedDates = StepsBuilder::getConfirmedDates($uid, $periodFrom, $periodTo);

        $periodPayload = StatsBuilder::buildPeriodPayload();

        $summary['CONFIRMED_DATES']      = $confirmedDates;
        $summary['CONFIRMED_DAYS_COUNT'] = count($confirmedDates);
        $summary['PERIOD'] = array_merge($periodPayload, [
            'FROM'         => $periodPayload['START'],
            'TO'           => $periodPayload['END'],
            'PLANNED_FROM' => $periodPayload['PLANNED_START'],
            'PLANNED_TO'   => $periodPayload['PLANNED_END'],
        ]);

        return array_merge(['RESULT' => 'OK'], $summary);
    }

    public static function history(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        return StepsBuilder::getHistory($uid);
    }
}