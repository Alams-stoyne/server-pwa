<?php
namespace X5\Pwa\Api;

use X5\Pwa\ContentBuilder;
use X5\Pwa\Data;

class Content
{
    public static function articles(string $theme, string $date): array
    {
        return [];
    }

    public static function general(string $date): array
    {
        return [];
    }

    public static function useful(string $date): array
    {
        return [];
    }

    public static function archive(string $theme): array
    {
        return [];
    }

    /**
     * Практики (задания) на дату — в том же формате, что CDN:
     * ID_TASK / DATE / TITLE / DESCRIPTION / TEXT / COVER / VIDEO_LINK
     */
    public static function tasks(string $theme, string $date): array
    {
        $items = ContentBuilder::themeTasks($theme, $date);
        foreach ($items as &$i) {
            $i['ID_TASK'] = ($i['ID_TASK'] ?? 'x') . '-' . $date;
            $i['DATE']    = $date;
        }
        unset($i);
        return $items;
    }

    public static function lecturesGeneral(string $date): array
    {
        $items = ContentBuilder::generalLectures($date);
        foreach ($items as &$i) {
            $i['ID_LECTURE'] = ($i['ID_LECTURE'] ?? 'x') . '-' . $date;
            $i['TITLE']      = ($i['TITLE'] ?? '');
            $i['DATE']       = $date;
        }
        unset($i);
        return $items;
    }

    public static function lecturesCharging(string $date): array
    {
        $items = ContentBuilder::chargingLectures($date);
        foreach ($items as &$i) {
            $i['ID_LECTURE'] = ($i['ID_LECTURE'] ?? 'x') . '-' . $date;
            $i['TITLE']      = ($i['TITLE'] ?? '');
            $i['DATE']       = $date;
        }
        unset($i);
        return $items;
    }

    public static function lecturesTheme(string $theme, string $date): array
    {
        $items = ContentBuilder::themeLectures($theme, $date);
        foreach ($items as &$i) {
            $i['ID_LECTURE'] = ($i['ID_LECTURE'] ?? 'x') . '-' . $date;
            $i['TITLE']      = ($i['TITLE'] ?? '');
            $i['DATE']       = $date;
        }
        unset($i);
        return $items;
    }
}