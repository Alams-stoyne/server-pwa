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

        // Уникальные устройства на каждом шаге
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
                // Считаем уникальные устройства (чтобы один юзер не накручивал)
                $row = $conn->query("SELECT COUNT(DISTINCT UF_DEVICE_ID) AS C FROM x5_pwa_analytics_events WHERE {$baseWhere} AND {$where}")->fetch();
                $funnel[$key] = (int)($row['C'] ?? 0);
            } catch (\Throwable $e) {
                $funnel[$key] = 0;
            }
        }

        return $funnel;
    }
    
    /**
     * Возвращает ежедневную динамику (для графика).
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
}