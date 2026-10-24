<?php
namespace X5\Pwa;

/**
 * @deprecated Заглушка. Не используется: /stats/global.json читает
 *             локальный кеш S3, /stats/personal — через StatsBuilder.
 *             Оставлено для обратной совместимости.
 */
class Data
{
    public static function globalStats(): array
    {
        return [
            'USERS'         => 0,
            'ARTICLES_READ' => 0,
            'TASKS_DONE'    => 0,
            'TOP_THEME'     => 'energy',
        ];
    }
}