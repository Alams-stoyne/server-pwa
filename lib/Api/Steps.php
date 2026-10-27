<?php
namespace X5\Pwa\Api;

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
     *     PROGRESS_PERCENT,
     *     TODAY_STATUS, TODAY_REJECT_REASON, TODAY_SENT_STEPS,
     *     CONFIRMED_DATES: [YYYY-MM-DD, ...],
     *     CONFIRMED_DAYS_COUNT: int,
     *     PERIOD: {
     *        START, END,               ← эффективный период (END зажат к today)
     *        PLANNED_START, PLANNED_END ← СТАБИЛЬНЫЕ границы (не зависят от today)
     *        PERIOD_CONFIGURED,         ← bool: задан ли период в админке
     *        TODAY,
     *        FROM, TO,                  ← legacy-алиасы START/END
     *        PLANNED_FROM, PLANNED_TO   ← legacy-алиасы PLANNED_START/END
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

        // Эффективный период — для фильтрации confirmed-dates.
        [$periodFrom, $periodTo] = StatsBuilder::getEffectivePeriod();

        $confirmedDates = StepsBuilder::getConfirmedDates($uid, $periodFrom, $periodTo);

        // PERIOD — единый контракт с config/period.json (см. Dumper).
        // Канонические имена: START/END/PLANNED_START/PLANNED_END/PERIOD_CONFIGURED/TODAY.
        // Legacy-алиасы FROM/TO/PLANNED_FROM/PLANNED_TO — для обратной совместимости:
        //   • PLANNED_FROM/PLANNED_TO теперь СТАБИЛЬНЫ (при ненастроенном периоде — '2000-01-01'/'2099-12-31',
        //     а не «clamped to today», как было раньше — именно из-за этого у клиента
        //     после полуночи некорректно считался «осталось N дней»).
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
}