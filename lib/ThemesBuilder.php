<?php
namespace X5\Pwa;

class ThemesBuilder
{
    /**
     * Направления для CDN (config/themes.json) и live-API.
     *
     * Формат каждого элемента:
     *   id                         — CODE элемента
     *   name                       — NAME
     *   subtitle                   — свойство SUBTITLE
     *   description                — PREVIEW_TEXT
     *   text                       — DETAIL_TEXT
     *   videoUrl                   — свойство VIDEO_URL
     *   videoDescription           — свойство VIDEO_DESC
     *   backgroundImageHome        — PREVIEW_PICTURE (картинка на главной)
     *   backgroundImageRecomendation — DETAIL_PICTURE (картинка на экране рекомендации)
     *   ThemeMascot                — свойство MASCOT
     */
    public static function getAll(): array
    {
        $ibId = Settings::iblockId('themes');
        if (!$ibId) return [];

        $elements = IbReader::elements($ibId);
        $out = [];

        foreach ($elements as $el) {
            $props = IbReader::props($ibId, $el['ID']);
            $themeId = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);

            $out[] = [
                'id'                         => $themeId,
                'name'                       => $el['NAME'],
                'subtitle'                   => IbReader::prop($props, 'SUBTITLE'),
                'description'                => $el['PREVIEW_TEXT'],
                'text'                       => $el['DETAIL_TEXT'],
                'videoUrl'                   => IbReader::prop($props, 'VIDEO_URL'),
                'videoDescription'           => IbReader::prop($props, 'VIDEO_DESC'),
                'backgroundImageHome'        => IbReader::imageUrl($el['PREVIEW_PICTURE']),
                'backgroundImageRecomendation' => IbReader::imageUrl($el['DETAIL_PICTURE']),
                'ThemeMascot'                => IbReader::prop($props, 'MASCOT'),
            ];
        }
        return $out;
    }
}