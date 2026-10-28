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
     *
     * PD consent проверяется здесь (UF_CONSENT_GRANTED = 'Y').
     * Поле CONSENT в теле — отдельное согласие «фото мои, можно отправлять»;
     * дополнительно проверяется в StepsBuilder::send().
     */
    public static function send(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        // ─── PD GATE (152-ФЗ) ───
        // Отправка шагов пишет в x5_pwa_steps + сохраняет фото (файлы) —
        // это обработка ПД. Проверяем ДО StepsBuilder::send(), чтобы не
        // сохранять ни записи, ни файлы у пользователей без согласия.
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
     * Ответ: { RESULT, ..., CONFIRMED_DATES, CONFIRMED_DAYS_COUNT, PERIOD }
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
}