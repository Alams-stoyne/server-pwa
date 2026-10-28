<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class AnalyticsBuilder
{
    /**
     * Воронка за период.
     *
     * Считает ТОЛЬКО устройства, у которых есть запись в x5_pwa_uid_bindings
     * (то есть они прошли /auth и получили UF_DEVICE_ID в привязках).
     *
     * Зачем: боты, краулеры, нагрузочные тесты и просто «зашёл-ушёл»
     * шлют app_first_open, но НЕ регистрируются. Без фильтра такие устройства
     * раздувают первый шаг воронки, а на последующих шагах их нет — получается
     * фиктивная конверсия 0.5%.
     *
     * С JOIN конверсия честная: app_first_open → auth всегда 100% по
     * определению выборки, а дальше видна реальная воронка онбординга.
     */
    public static function getFunnel(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $baseWhere = "e.UF_SERVER_AT >= '{$fromSafe}' AND e.UF_SERVER_AT <= '{$toSafe}'";

        $steps = [
            'app_first_open'       => "e.UF_EVENT_NAME = 'app_first_open'",
            'install_accepted'     => "e.UF_EVENT_NAME = 'install_accepted'",
            'registration_success' => "e.UF_EVENT_NAME = 'registration_success'",
            'consent_granted'      => "e.UF_EVENT_NAME = 'consent_granted'",
            'onboarding_completed' => "e.UF_EVENT_NAME = 'onboarding_completed'",
            'first_action'         => "e.UF_EVENT_NAME IN ('lecture_read', 'practice_done', 'habit_completed', 'steps_sent', 'event_confirmed', 'reflection_sent')"
        ];

        $funnel = [];
        foreach ($steps as $key => $where) {
            try {
                $row = $conn->query(
                    "SELECT COUNT(DISTINCT e.UF_DEVICE_ID) AS C
                     FROM x5_pwa_analytics_events e
                     INNER JOIN x5_pwa_uid_bindings b
                       ON b.UF_DEVICE_ID = e.UF_DEVICE_ID
                     WHERE {$baseWhere} AND {$where}"
                )->fetch();
                $funnel[$key] = (int)($row['C'] ?? 0);
            } catch (\Throwable $e) {
                $funnel[$key] = 0;
            }
        }

        return $funnel;
    }

    /**
     * Ежедневная динамика новых устройств.
     * JOIN с привязками — считаем только те, кто в итоге зарегистрировался.
     */
    public static function getDailyDynamic(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $sql = "SELECT
                    DATE(e.UF_SERVER_AT) AS D,
                    COUNT(DISTINCT e.UF_DEVICE_ID) AS C
                FROM x5_pwa_analytics_events e
                INNER JOIN x5_pwa_uid_bindings b
                  ON b.UF_DEVICE_ID = e.UF_DEVICE_ID
                WHERE e.UF_SERVER_AT >= '{$fromSafe}'
                  AND e.UF_SERVER_AT <= '{$toSafe}'
                  AND e.UF_EVENT_NAME = 'app_first_open'
                GROUP BY DATE(e.UF_SERVER_AT)
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
     * Определяет, сколько устройств пришло через браузер (ссылка) и сколько
     * через ярлык (PWA).
     *
     * Логика: для каждого устройства берём самое первое событие app_first_open
     * в периоде и смотрим на поле isStandalone. JOIN с привязками отсекает
     * ботов и «зашёл-ушёл» — считаем только тех, кто дошёл до /auth.
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
            INNER JOIN x5_pwa_uid_bindings b
                ON b.UF_DEVICE_ID = main.UF_DEVICE_ID
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
     * JOIN с привязками — deviceIds заведомо «живые» (прошли /auth).
     * Это гарантирует, что воронка по источникам согласована с общей воронкой.
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
            INNER JOIN x5_pwa_uid_bindings b
                ON b.UF_DEVICE_ID = main.UF_DEVICE_ID
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
     * deviceIds уже отфильтрованы JOIN-ом в getDeviceIdsBySource — здесь
     * дополнительный JOIN не нужен.
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
     *
     * Здесь JOIN с привязками НЕ применяется:
     *   • ios_gate / android_prompt — это «сколько устройств видели экран»,
     *     а не «сколько из них зарегистрировались»;
     *   • android_installed — установки, считаются по факту install_accepted;
     *   • reg_uid_taken / access_denied — операционные ошибки, нужны все случаи.
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

    /**
     * Разбивка install_accepted по полю PROPS.via:
     *   • prompt       — установка через нативный promptInstall()
     *   • appinstalled — установка через меню браузера (событие appinstalled)
     *   • detected     — эвристика для iOS (isStandalone + install_gate_viewed)
     *   • unknown      — старая версия клиента (via не передан)
     *
     * Считаем уникальные устройства. Дубли по одному устройству не задваивают метрику.
     *
     * @return array<string,int>  via => количество устройств
     */
    public static function getInstallByVia(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $baseWhere = "UF_SERVER_AT >= '{$fromSafe}'
                      AND UF_SERVER_AT <= '{$toSafe}'
                      AND UF_EVENT_NAME = 'install_accepted'";

        $out = [];

        try {
            $rows = $conn->query(
                "SELECT
                    SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"via\":\"', -1), '\"', 1) AS VIA,
                    COUNT(DISTINCT UF_DEVICE_ID) AS C
                 FROM x5_pwa_analytics_events
                 WHERE {$baseWhere}
                   AND UF_PROPS LIKE '%\"via\":\"%'
                 GROUP BY VIA
                 ORDER BY C DESC"
            )->fetchAll();

            foreach ($rows as $r) {
                $via = trim((string)($r['VIA'] ?? ''));
                $cnt = (int)($r['C'] ?? 0);
                if ($via === '' || $cnt <= 0) continue;
                $out[$via] = $cnt;
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'getInstallByVia failed: ' . $e->getMessage(),
                'AnalyticsBuilder::getInstallByVia',
                '/x5.pwa.log'
            );
        }

        try {
            $row = $conn->query(
                "SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C
                 FROM x5_pwa_analytics_events
                 WHERE {$baseWhere}
                   AND UF_PROPS NOT LIKE '%\"via\":\"%'"
            )->fetch();
            $unknown = (int)($row['C'] ?? 0);
            if ($unknown > 0) $out['unknown'] = $unknown;
        } catch (\Throwable $e) {}

        return $out;
    }

    /**
     * Разбивка install_accepted по полю PROPS.platform (ios / android / desktop / unknown).
     *
     * @return array<string,int>  platform => количество устройств
     */
    public static function getInstallByPlatform(string $from, string $to): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $fromSafe = $sqlHelper->forSql($from . ' 00:00:00');
        $toSafe   = $sqlHelper->forSql($to . ' 23:59:59');

        $baseWhere = "UF_SERVER_AT >= '{$fromSafe}'
                      AND UF_SERVER_AT <= '{$toSafe}'
                      AND UF_EVENT_NAME = 'install_accepted'";

        $out = [];

        try {
            $rows = $conn->query(
                "SELECT
                    SUBSTRING_INDEX(SUBSTRING_INDEX(UF_PROPS, '\"platform\":\"', -1), '\"', 1) AS PLATFORM,
                    COUNT(DISTINCT UF_DEVICE_ID) AS C
                 FROM x5_pwa_analytics_events
                 WHERE {$baseWhere}
                   AND UF_PROPS LIKE '%\"platform\":\"%'
                 GROUP BY PLATFORM
                 ORDER BY C DESC"
            )->fetchAll();

            foreach ($rows as $r) {
                $platform = strtolower(trim((string)($r['PLATFORM'] ?? '')));
                $cnt      = (int)($r['C'] ?? 0);
                if ($platform === '' || $cnt <= 0) continue;
                $out[$platform] = $cnt;
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'getInstallByPlatform failed: ' . $e->getMessage(),
                'AnalyticsBuilder::getInstallByPlatform',
                '/x5.pwa.log'
            );
        }

        try {
            $row = $conn->query(
                "SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C
                 FROM x5_pwa_analytics_events
                 WHERE {$baseWhere}
                   AND UF_PROPS NOT LIKE '%\"platform\":\"%'"
            )->fetch();
            $unknown = (int)($row['C'] ?? 0);
            if ($unknown > 0) $out['unknown'] = $unknown;
        } catch (\Throwable $e) {}

        return $out;
    }
}