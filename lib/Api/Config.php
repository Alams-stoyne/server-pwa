<?php
namespace X5\Pwa\Api;

use Bitrix\Main\Application;
use X5\Pwa\HabitsBuilder;
use X5\Pwa\QuizBuilder;
use X5\Pwa\RewardsBuilder;
use X5\Pwa\Settings;
use X5\Pwa\Storage;
use X5\Pwa\ThemesBuilder;
use X5\Pwa\TutorialBuilder;

class Config
{
    public static function themes(): array
    {
        return [
            'RESULT' => 'OK',
            'THEMES' => ThemesBuilder::getAll(),
        ];
    }

    public static function quizTheme(): array
    {
        return [
            'RESULT'    => 'OK',
            'QUESTIONS' => QuizBuilder::getQuestions(),
        ];
    }

    public static function habits(array $input): array
    {
        $uid = trim((string)(
            $input['uid']
            ?? $input['UID']
            ?? $input['ID_USER']
            ?? ''
        ));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'uid required'];
        }

        return [
            'RESULT' => 'OK',
            'HABITS' => HabitsBuilder::getForUser($uid),
        ];
    }

    public static function tutorial(): array
    {
        return [
            'RESULT' => 'OK',
            'SLIDES' => TutorialBuilder::getSlides(),
        ];
    }

    /**
     * POST /config/limits
     * Возвращает актуальные счётчики использованных наград за локальный день
     * пользователя. Используется клиентом для синхронизации при возврате
     * на вкладку из фона (не чаще 1 раза в 5 минут).
     */
    public static function limits(array $input): array
    {
        $uid = trim((string)(
            $input['ID_USER']
            ?? $input['UID']
            ?? $input['uid']
            ?? ''
        ));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        // TZ из тела — чтобы LOCAL_DATE соответствовал текущему TZ пользователя
        $tz = Storage::parseTzOffsetFromArray($input);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        $localDate = RewardsBuilder::userLocalDate($uid);
        $used      = self::usedCounters($uid, $localDate);

        Storage::touchBindingByUid($uid);

        return [
            'RESULT'     => 'OK',
            'LOCAL_DATE' => $localDate,
            'USED'       => $used,
        ];
    }

    /**
     * Счётчики наград за локальный день пользователя.
     *
     * ВАЖНО: это "сколько НАГРАД получено", не "сколько действий сделано".
     * Legacy-тип 'lecture' учитывается консервативно — и в LECTURE_THEME, и в
     * LECTURE_GENERAL (не знаем, какой тип был), это совпадает с логикой
     * RewardsBuilder::checkDailyLimit.
     *
     * @return array{LECTURE_THEME:int, LECTURE_GENERAL:int, CHARGING:int, TASK:int}
     */
    public static function usedCounters(string $uid, string $localDate): array
    {
        $result = [
            'LECTURE_THEME'   => 0,
            'LECTURE_GENERAL' => 0,
            'CHARGING'        => 0,
            'TASK'            => 0,
        ];

        try {
            $conn      = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $safeUid   = $sqlHelper->forSql($uid);
            $safeDate  = $sqlHelper->forSql($localDate);

            $rows = $conn->query(
                "SELECT UF_ACTION_TYPE, COUNT(*) AS C
                 FROM x5_pwa_reward_log
                 WHERE UF_UID = '{$safeUid}' AND UF_LOCAL_DATE = '{$safeDate}'
                 GROUP BY UF_ACTION_TYPE"
            )->fetchAll();

            foreach ($rows as $r) {
                $type  = (string)($r['UF_ACTION_TYPE'] ?? '');
                $count = (int)($r['C'] ?? 0);
                if ($count <= 0) continue;

                switch ($type) {
                    case 'lecture':
                        $result['LECTURE_THEME']   += $count;
                        $result['LECTURE_GENERAL'] += $count;
                        break;
                    case 'lecture_theme':
                        $result['LECTURE_THEME'] += $count;
                        break;
                    case 'lecture_general':
                        $result['LECTURE_GENERAL'] += $count;
                        break;
                    case 'charging':
                        $result['CHARGING'] += $count;
                        break;
                    case 'task':
                        $result['TASK'] += $count;
                        break;
                }
            }
        } catch (\Throwable $e) {
            Settings::logError(
                'usedCounters failed uid=' . substr($uid, 0, 12) . '… date=' . $localDate
                . ': ' . $e->getMessage(),
                'Config::usedCounters'
            );
        }

        return $result;
    }
}