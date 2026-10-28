<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class AnalyticsBuilder
{
    /**
     * Собирает воронку за период.
     */
    public static function getFunnel(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $baseWhere = "UF_SERVER_AT >= '{$fromSafe}' AND UF_SERVER_AT <= '{$toSafe}'";

        $steps = [
            'app_first_open' => "UF_EVENT_NAME = 'app_first_open'",
            'install_accepted' => "UF_EVENT_NAME = 'install_accepted'",
            'registration_success' => "UF_EVENT_NAME = 'registration_success'",
            'consent_granted' => "UF_EVENT_NAME = 'consent_granted'",
            'onboarding_completed' => "UF_EVENT_NAME = 'onboarding_completed'",
            'first_action' => "UF_EVENT_NAME IN ('lecture_read', 'practice_done', 'habit_completed', 'steps_sent', 'event_confirmed', 'reflection_sent')"
        ];

        $funnel = [];
        foreach ($steps as $key => $where) {
            try {
                $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND {$where}")->fetch();
                $funnel[$key] = (int)($row['C'] ?? 0);
            } catch (\Throwable $e) {
                $funnel[$key] = 0;
            }
        }

        return $funnel;
    }

    /**
     * Ежедневная динамика.
     */
    public static function getDailyDynamic(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $sql = "SELECT
                    DATE(UF_SERVER_AT) AS D,
                    COUNT(DISTINCT UF_DEVICE_ID) AS C
                FROM x5_pwa_analytics_events
                WHERE UF_SERVER_AT >= '{$fromSafe}' AND UF_SERVER_AT <= '{$toSafe}' AND UF_EVENT_NAME = 'app_first_open'
                GROUP BY DATE(UF_SERVER_AT)
                ORDER BY D ASC";
        try {
            return $conn->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Воронка онбординга (устарела, оставлена для совместимости).
     * @deprecated используйте getOnboardingFunnelBySource('browser' | 'pwa')
     */
    public static function getOnboardingFunnel(string $from, string $to): array
    {
        return array_merge(
            self::getOnboardingFunnelBySource($from, $to, 'browser'),
            self::getOnboardingFunnelBySource($from, $to, 'pwa')
        );
    }

    /**
     * Определяет, сколько устройств пришло через браузер (ссылка) и сколько через ярлык (PWA).
     *
     * Логика: для каждого устройства берём самое первое событие app_first_open в периоде
     * и смотрим на поле isStandalone.
     *   false → пришёл по ссылке в браузере (приглашение);
     *   true  → открыл уже установленное приложение (ярлык).
     *
     * @return array{browser:int, pwa:int, total:int}
     */
    public static function getEntryPoints(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $sql = "
            SELECT
                IF(main.UF_PROPS LIKE '%\"isStandalone\":true%', 'pwa', 'browser') AS entry_type,
                COUNT(*) AS devices
            FROM x5_pwa_analytics_events main
            INNER JOIN (
                SELECT UF_DEVICE_ID, MIN(UF_SERVER_AT) AS min_ts
                FROM x5_pwa_analytics_events
                WHERE UF_EVENT_NAME = 'app_first_open'
                  AND UF_SERVER_AT >= '{$fromSafe}'
                  AND UF_SERVER_AT <= '{$toSafe}'
                GROUP BY UF_DEVICE_ID
            ) first ON main.UF_DEVICE_ID = first.UF_DEVICE_ID
                   AND main.UF_SERVER_AT = first.min_ts
            WHERE main.UF_EVENT_NAME = 'app_first_open'
            GROUP BY entry_type
        ";

        try {
            $rows = $conn->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return ['browser' => 0, 'pwa' => 0, 'total' => 0];
        }

        $out = ['browser' => 0, 'pwa' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $type = (string)($r['entry_type'] ?? 'browser');
            $cnt  = (int)($r['devices'] ?? 0);
            if (!isset($out[$type])) $out[$type] = 0;
            $out[$type] += $cnt;
            $out['total'] += $cnt;
        }
        return $out;
    }

    /**
     * Возвращает список UF_DEVICE_ID для указанного источника входа.
     *
     * @param string $source 'browser' | 'pwa'
     * @return string[]
     */
    private static function getDeviceIdsBySource(string $from, string $to, string $source): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $pattern = ($source === 'pwa')
            ? "%\"isStandalone\":true%"
            : "%\"isStandalone\":false%";

        $sql = "
            SELECT DISTINCT main.UF_DEVICE_ID
            FROM x5_pwa_analytics_events main
            INNER JOIN (
                SELECT UF_DEVICE_ID, MIN(UF_SERVER_AT) AS min_ts
                FROM x5_pwa_analytics_events
                WHERE UF_EVENT_NAME = 'app_first_open'
                  AND UF_SERVER_AT >= '{$fromSafe}'
                  AND UF_SERVER_AT <= '{$toSafe}'
                GROUP BY UF_DEVICE_ID
            ) first ON main.UF_DEVICE_ID = first.UF_DEVICE_ID
                   AND main.UF_SERVER_AT = first.min_ts
            WHERE main.UF_EVENT_NAME = 'app_first_open'
              AND main.UF_PROPS LIKE '{$pattern}'
        ";

        try {
            $rows = $conn->query($sql)->fetchAll();
            return array_map('strval', array_column($rows, 'UF_DEVICE_ID'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Воронка онбординга для конкретного источника входа.
     *
     * В отличие от старого метода — считает конверсию ТОЛЬКО по устройствам,
     * которые впервые пришли из указанного источника (браузер или ярлык PWA).
     *
     * Для группы 'pwa' шаг 'install_accepted' пропускается — они уже установлены.
     */
    public static function getOnboardingFunnelBySource(string $from, string $to, string $source): array
    {
        $deviceIds = self::getDeviceIdsBySource($from, $to, $source);
        if (empty($deviceIds)) return [];

        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $safeIds = array_map([$sqlHelper, 'forSql'], $deviceIds);
        $inSql = "'" . implode("','", $safeIds) . "'";

        $steps = [
            'app_first_open'           => "UF_EVENT_NAME = 'app_first_open'",
            'install_accepted'         => "UF_EVENT_NAME = 'install_accepted'",
            'registration_success'     => "UF_EVENT_NAME = 'registration_success'",
            'consent_granted'          => "UF_EVENT_NAME = 'consent_granted'",
            'onboarding_profile_done'  => "UF_EVENT_NAME = 'onboarding_profile_done'",
            'quiz_done'                => "UF_EVENT_NAME = 'quiz_done'",
            'onboarding_habits_done'   => "UF_EVENT_NAME = 'onboarding_habits_done'",
            'onboarding_tutorial_done' => "UF_EVENT_NAME = 'onboarding_tutorial_done'",
            'onboarding_completed'     => "UF_EVENT_NAME = 'onboarding_completed'",
        ];

        // Для группы 'pwa' убираем шаг установки — они уже установлены
        if ($source === 'pwa') {
            unset($steps['install_accepted']);
        }

        $funnel = [];
        foreach ($steps as $key => $where) {
            try {
                $row = $conn->query(
                    "SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C
                     FROM x5_pwa_analytics_events
                     WHERE UF_SERVER_AT >= '{$fromSafe}'
                       AND UF_SERVER_AT <= '{$toSafe}'
                       AND UF_DEVICE_ID IN ({$inSql})
                       AND {$where}"
                )->fetch();
                $funnel[$key] = (int)($row['C'] ?? 0);
            } catch (\Throwable $e) {
                $funnel[$key] = 0;
            }
        }
        return $funnel;
    }

    /**
     * Установки и регистрации (общая статистика).
     */
    public static function getInstallAndRegStats(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');
        $baseWhere = "UF_SERVER_AT >= '{$fromSafe}' AND UF_SERVER_AT <= '{$toSafe}'";

        $out = [
            'ios_gate' => 0, 'android_prompt' => 0, 'android_installed' => 0,
            'reg_success' => 0, 'reg_uid_taken' => 0, 'reg_other_fail' => 0,
            'access_denied' => 0
        ];

        $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'install_gate_viewed' AND UF_PROPS LIKE '%\"isIOS\":true%'")->fetch();
        $out['ios_gate'] = (int)($row['C'] ?? 0);

        $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'install_prompt_shown' AND UF_PROPS LIKE '%\"platform\":\"android\"%'")->fetch();
        $out['android_prompt'] = (int)($row['C'] ?? 0);

        $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'install_accepted'")->fetch();
        $out['android_installed'] = (int)($row['C'] ?? 0);

        $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'registration_success'")->fetch();
        $out['reg_success'] = (int)($row['C'] ?? 0);

        $row = $conn->query("SELECT COUNT(*) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'registration_failed' AND UF_PROPS LIKE '%\"reason\":\"UID_TAKEN\"%'")->fetch();
        $out['reg_uid_taken'] = (int)($row['C'] ?? 0);

        $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND UF_EVENT_NAME = 'access_denied_shown'")->fetch();
        $out['access_denied'] = (int)($row['C'] ?? 0);

        return $out;
    }

    /**
     * Топ экранов.
     */
    public static function getTopScreens(string $from, string $to, int $limit = 10): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');
        $limit    = (int)$limit;

        $sql = "SELECT
                    SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"path\":\"', -1), '\"', 1) AS PATH,
                    COUNT(*) AS C
                FROM x5_pwa_analytics_events
                WHERE UF_SERVER_AT >= '{$fromSafe}'
                  AND UF_SERVER_AT <= '{$toSafe}'
                  AND UF_EVENT_NAME = 'screen_view'
                  AND UF_PROPS LIKE '%\"path\":\"%'
                GROUP BY PATH
                ORDER BY C DESC
                LIMIT {$limit}";

        try {
            return $conn->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Последние ошибки приложения (App Errors).
     */
    public static function getRecentErrors(string $from, string $to, int $limit = 20): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');
        $limit    = (int)$limit;

        $sql = "SELECT
                    ID,
                    UF_SERVER_AT,
                    UF_DEVICE_ID,
                    UF_PROPS,
                    IF(UF_PROPS LIKE '%\"message\":\"%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"message\":\"', -1), '\"', 1), 'Unknown') AS MSG,
                    IF(UF_PROPS LIKE '%\"filename\":\"%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"filename\":\"', -1), '\"', 1), '') AS FILE,
                    IF(UF_PROPS LIKE '%\"lineno\":%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"lineno\":', -1), ',', 1), '0') AS LINE,
                    IF(UF_PROPS LIKE '%\"lastScreen\":\"%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"lastScreen\":\"', -1), '\"', 1), 'Unknown') AS SCREEN,
                    IF(UF_PROPS LIKE '%\"kind\":\"%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"kind\":\"', -1), '\"', 1), '-') AS KIND,
                    IF(UF_PROPS LIKE '%\"platform\":\"%', SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"platform\":\"', -1), '\"', 1), '-') AS PLATFORM
                FROM x5_pwa_analytics_events
                WHERE UF_SERVER_AT >= '{$fromSafe}'
                  AND UF_SERVER_AT <= '{$toSafe}'
                  AND UF_EVENT_NAME = 'app_error'
                  AND UF_PROPS NOT LIKE '%\"message\":\"Script error.%'
                  AND UF_PROPS NOT LIKE '%MetaMask%'
                  AND UF_PROPS NOT LIKE '%ethereum%'
                  AND UF_PROPS NOT LIKE '%chrome-extension://%'
                  AND UF_PROPS NOT LIKE '%moz-extension://%'
                ORDER BY UF_SERVER_AT DESC
                LIMIT {$limit}";

        try {
            return $conn->query($sql)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Удаление записи об ошибке.
     */
    public static function deleteError(int $id): bool
    {
        if ($id <= 0) return false;
        try {
            $conn = Application::getConnection();
            $safeId = (int)$id;
            $conn->queryExecute("DELETE FROM x5_pwa_analytics_events WHERE ID = {$safeId} AND UF_EVENT_NAME = 'app_error'");
            return true;
        } catch (\Throwable $e) {
            Settings::logError('deleteError failed id=' . $id . ': ' . $e->getMessage(), 'AnalyticsBuilder');
            return false;
        }
    }
}