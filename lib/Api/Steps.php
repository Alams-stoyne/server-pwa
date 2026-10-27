<?php
namespace X5\Pwa\Api;

use X5\Pwa\Settings;
use X5\Pwa\StatsBuilder;
use X5\Pwa\StepsBuilder;
use X5\Pwa\Storage;

class Steps
{
    /**
     * POST /steps/send
     * Body: { ID_USER, STEPS, IMAGES:[base64], CONSENT, LOCAL_DATETIME }
     *
     * Ответ (RESULT=OK):
     *   { RESULT, SERVER_DATETIME, REWARD_POINTS }
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

        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $localDate = StepsBuilder::userLocalDate($uid);

        $result = StepsBuilder::send(
            $uid,
            $b['STEPS']   ?? null,
            $b['IMAGES']  ?? null,
            $b['CONSENT'] ?? null,
            $localDate
        );

        if (($result['RESULT'] ?? '') === 'OK') {
            Storage::touchBindingByUid($uid);
        }

        // Прикрепляем актуальный статус «за сегодня», чтобы клиенту
        // не нужно было делать повторный /steps/summary после send().
        $resCode = (string)($result['RESULT'] ?? '');
        if (in_array($resCode, ['OK', 'ALREADY_SENT_TODAY'], true)) {
            $result = array_merge($result, StepsBuilder::getTodayStatus($uid, $localDate));
        }

        return $result;
    }

    /**
     * POST /steps/summary
     * Body: { ID_USER, TZ_OFFSET_MIN?, LOCAL_DATETIME? }
     *
     * Ответ:
     *   {
     *     RESULT,
     *     TOTAL_STEPS, GOAL, RANK, TOTAL_USERS,
     *     TODAY_STEPS, DAILY_AVG, TOP_PERCENT,
     *     PROGRESS_PERCENT,           ← % к цели (fallback)
     *     CONFIRMED_DATES: [YYYY-MM-DD, ...],
     *     CONFIRMED_DAYS_COUNT: int,
     *     PERIOD: {
     *        FROM, TO,                 ← эффективный период (START..END)
     *        PLANNED_FROM, PLANNED_TO  ← запланированный (сырые настройки модуля)
     *     }
     *   }
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

        [$periodFrom, $periodTo] = StatsBuilder::getEffectivePeriod();

        $plannedStart = Settings::statsPeriodStart();
        $plannedEnd   = Settings::statsPeriodEnd();

        $confirmedDates = StepsBuilder::getConfirmedDates($uid, $periodFrom, $periodTo);

        $summary['CONFIRMED_DATES']      = $confirmedDates;
        $summary['CONFIRMED_DAYS_COUNT'] = count($confirmedDates);
        $summary['PERIOD'] = [
            'FROM'         => $periodFrom,
            'TO'           => $periodTo,
            'PLANNED_FROM' => $plannedStart !== '' ? $plannedStart : $periodFrom,
            'PLANNED_TO'   => $plannedEnd   !== '' ? $plannedEnd   : $periodTo,
        ];

        return array_merge(['RESULT' => 'OK'], $summary);
    }
}