<?php
namespace X5\Pwa;

use Bitrix\Main\Loader;

Loader::includeModule('iblock');

class ContentBuilder
{
    private const DEBUG_PROPS = false;

    private static function log(string $msg, string $label = 'ContentBuilder debug'): void
    {
        if (!self::DEBUG_PROPS) return;
        \Bitrix\Main\Diag\Debug::writeToFile(
            '[' . date('H:i:s') . '] ' . $msg,
            $label,
            '/x5.pwa.log'
        );
    }

    private static function loadProps(int $iblockId, int $elementId): array
    {
        $props = [];
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc']);
        while ($p = $rs->Fetch()) {
            $code = (string)($p['CODE'] ?: ('PROP_' . $p['ID']));
            $isMultiple = ($p['MULTIPLE'] ?? 'N') === 'Y';

            if (!isset($props[$code])) {
                $props[$code] = [
                    'PROPERTY_TYPE' => (string)$p['PROPERTY_TYPE'],
                    'MULTIPLE'      => $isMultiple ? 'Y' : 'N',
                    'VALUE'         => $isMultiple ? [] : null,
                    'VALUE_ENUM'    => $isMultiple ? [] : null,
                    'VALUE_XML_ID'  => $isMultiple ? [] : null,
                ];
            }

            if ($isMultiple) {
                if ($p['VALUE'] !== null && $p['VALUE'] !== '') {
                    $props[$code]['VALUE'][]        = $p['VALUE'];
                    $props[$code]['VALUE_ENUM'][]   = $p['VALUE_ENUM']   ?? null;
                    $props[$code]['VALUE_XML_ID'][] = $p['VALUE_XML_ID'] ?? null;
                }
            } else {
                $props[$code]['VALUE']        = $p['VALUE']        ?? null;
                $props[$code]['VALUE_ENUM']   = $p['VALUE_ENUM']   ?? null;
                $props[$code]['VALUE_XML_ID'] = $p['VALUE_XML_ID'] ?? null;
            }
        }
        return $props;
    }

    private static function extractTheme(array $props): ?string
    {
        if (empty($props['THEME'])) return null;

        $p    = $props['THEME'];
        $type = (string)($p['PROPERTY_TYPE'] ?? '');

        $raw = $p['VALUE'] ?? null;
        if (is_array($raw)) $raw = reset($raw);
        $raw = trim((string)$raw);

        if ($raw === '') return null;

        if ($type === 'L') {
            $xmlId = $p['VALUE_XML_ID'] ?? null;
            if (is_array($xmlId)) $xmlId = reset($xmlId);
            $xmlId = trim((string)$xmlId);
            if ($xmlId !== '') return $xmlId;

            $enum = $p['VALUE_ENUM'] ?? null;
            if (is_array($enum)) $enum = reset($enum);
            $enum = trim((string)$enum);
            if ($enum !== '') return $enum;

            return ThemesResolver::resolve($raw);
        }

        return ThemesResolver::resolve($raw);
    }

    /**
     * Достаёт REWARD_POINTS из свойств. Поддерживает int или строку.
     * Отрицательные значения считаются нулём.
     */
    private static function extractRewardPoints(array $props): int
    {
        if (empty($props['REWARD_POINTS'])) return 0;

        $v = $props['REWARD_POINTS']['VALUE'] ?? null;
        if (is_array($v)) $v = reset($v);

        $v = trim((string)$v);
        if ($v === '') return 0;

        $int = (int)$v;
        return $int > 0 ? $int : 0;
    }

    public static function _debugExtractTheme(int $iblockId, int $elementId): ?string
    {
        $props = self::loadProps($iblockId, $elementId);
        return self::extractTheme($props);
    }

    private static function query(int $iblockId): array
    {
        if (!$iblockId) return [];

        $rs = \CIBlockElement::GetList(
            ['ACTIVE_FROM' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID'         => $iblockId,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'XML_ID', 'PREVIEW_TEXT', 'DETAIL_TEXT',
             'PREVIEW_PICTURE', 'DETAIL_PICTURE', 'ACTIVE_FROM']
        );

        $out = [];
        while ($fields = $rs->Fetch()) {
            $elementId = (int)$fields['ID'];
            $props = self::loadProps($iblockId, $elementId);

            $extId = $fields['XML_ID'] ?: ($fields['CODE'] ?: (string)$fields['ID']);

            $photos       = self::extractPhotos($props);
            $videoLink    = self::extractVideoLink($props);
            $theme        = self::extractTheme($props);
            $rewardPoints = self::extractRewardPoints($props);

            $cover = $fields['PREVIEW_PICTURE']
                ? \CFile::GetPath($fields['PREVIEW_PICTURE'])
                : null;

            $dateActive = null;
            if (!empty($fields['ACTIVE_FROM'])) {
                $ts = \MakeTimeStamp((string)$fields['ACTIVE_FROM'], \FORMAT_DATETIME);
                if ($ts <= 0) $ts = strtotime((string)$fields['ACTIVE_FROM']);
                if ($ts > 0) $dateActive = date('Y-m-d', $ts);
            }

            $out[] = [
                'ID'            => $extId,
                'NAME'          => $fields['NAME'],
                'DESCRIPTION'   => $fields['PREVIEW_TEXT'],
                'TEXT'          => $fields['DETAIL_TEXT'] ?: '',
                'COVER'         => $cover,
                'VIDEO_LINK'    => $videoLink,
                'PHOTOS'        => $photos,
                'THEME'         => $theme,
                'REWARD_POINTS' => $rewardPoints,
                'DATE_ACTIVE'   => $dateActive,
            ];
        }
        return $out;
    }

    private static function extractVideoLink(array $props): ?string
    {
        $candidates = ['VIDEO_LINK', 'VIDEO', 'VIDEO_URL', 'LINK'];
        foreach ($candidates as $code) {
            if (empty($props[$code])) continue;
            $v = $props[$code]['VALUE'] ?? null;
            if (is_array($v)) $v = reset($v);
            $v = trim((string)$v);
            if ($v !== '') return $v;
        }
        return null;
    }

    private static function extractPhotos(array $props): array
    {
        $candidates = ['PHOTOS', 'PHOTO', 'PHOTOES', 'IMAGES', 'GALLERY'];
        $prop = null;
        foreach ($candidates as $code) {
            if (!empty($props[$code]) && !empty($props[$code]['VALUE'])) {
                $prop = $props[$code];
                break;
            }
        }
        if (!$prop) return [];

        $type  = $prop['PROPERTY_TYPE'] ?? '?';
        $value = $prop['VALUE'] ?? null;
        if ($value === null || $value === '') return [];

        $values = is_array($value) ? $value : [$value];
        $out = [];

        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;

            if ($type === 'F') {
                $path = \CFile::GetPath((int)$v);
                if ($path) $out[] = $path;
            } else {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function filterByDate(array $items, ?string $date): array
    {
        if ($date === null) return $items;
        return array_values(array_filter(
            $items,
            fn($i) => ($i['DATE_ACTIVE'] ?? null) === $date
        ));
    }

    // ── Публичные методы ──

    public static function generalLectures(?string $date = null): array
    {
        $id    = Settings::iblockId('general_lectures');
        $items = self::filterByDate(self::query($id), $date);

        return array_map(fn($i) => [
            'ID_LECTURE'    => $i['ID'],
            'TITLE'         => $i['NAME'],
            'DESCRIPTION'   => $i['DESCRIPTION'],
            'COVER'         => $i['COVER'],
            'TEXT'          => $i['TEXT'],
            'VIDEO_LINK'    => $i['VIDEO_LINK'],
            'PHOTOS'        => $i['PHOTOS'],
            'REWARD_POINTS' => (int)($i['REWARD_POINTS'] ?? 0),
        ], $items);
    }

    public static function chargingLectures(?string $date = null): array
    {
        $id    = Settings::iblockId('charging');
        $items = self::filterByDate(self::query($id), $date);

        return array_map(fn($i) => [
            'ID_LECTURE'    => $i['ID'],
            'TITLE'         => $i['NAME'],
            'DESCRIPTION'   => $i['DESCRIPTION'],
            'COVER'         => $i['COVER'],
            'TEXT'          => $i['TEXT'],
            'VIDEO_LINK'    => $i['VIDEO_LINK'],
            'PHOTOS'        => $i['PHOTOS'],
            'REWARD_POINTS' => (int)($i['REWARD_POINTS'] ?? 0),
        ], $items);
    }

    public static function themeLectures(string $theme, ?string $date = null): array
    {
        $id    = Settings::iblockId('theme_lectures');
        $items = self::query($id);
        $items = array_values(array_filter($items, fn($i) => $i['THEME'] === $theme));
        $items = self::filterByDate($items, $date);

        return array_map(fn($i) => [
            'ID_LECTURE'    => $i['ID'],
            'TITLE'         => $i['NAME'],
            'DESCRIPTION'   => $i['DESCRIPTION'],
            'COVER'         => $i['COVER'],
            'TEXT'          => $i['TEXT'],
            'VIDEO_LINK'    => $i['VIDEO_LINK'],
            'PHOTOS'        => $i['PHOTOS'],
            'THEME'         => $i['THEME'],
            'REWARD_POINTS' => (int)($i['REWARD_POINTS'] ?? 0),
        ], $items);
    }

    /**
     * Практики (задания) — расширенный формат, как у лекций.
     *
     * @return array<int, array{
     *   ID_TASK:string, TITLE:string, DESCRIPTION:string, TEXT:string,
     *   COVER:?string, VIDEO_LINK:?string, THEME:string, REWARD_POINTS:int
     * }>
     */
    public static function themeTasks(string $theme, ?string $date = null): array
    {
        $id    = Settings::iblockId('tasks'); 
        $items = self::query($id);
        $items = array_values(array_filter($items, fn($i) => $i['THEME'] === $theme));
        $items = self::filterByDate($items, $date);

        return array_map(fn($i) => [
            'ID_TASK'       => $i['ID'],
            'TITLE'         => $i['NAME'],
            'DESCRIPTION'   => $i['DESCRIPTION'],
            'TEXT'          => $i['TEXT'],
            'COVER'         => $i['COVER'],
            'VIDEO_LINK'    => $i['VIDEO_LINK'],
            'THEME'         => $i['THEME'],
            'REWARD_POINTS' => (int)($i['REWARD_POINTS'] ?? 0),
        ], $items);
    }

    public static function rawAll(): array
    {
        return [
            'general_lectures' => self::query(Settings::iblockId('general_lectures')),
            'charging'         => self::query(Settings::iblockId('charging')),
            'theme_lectures'   => self::query(Settings::iblockId('theme_lectures')),
            'tasks'            => self::query(Settings::iblockId('tasks')),
        ];
    }
}