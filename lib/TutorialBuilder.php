<?php
namespace X5\Pwa;

class TutorialBuilder
{
    /**
     * Слайды для первичного экрана «Обучение».
     * Возвращает массив слайдов, отсортированный по SORT (ASC).
     */
    public static function getSlides(): array
    {
        $ibId = Settings::iblockId('tutorial');
        if (!$ibId) return [];

        $elements = IbReader::elements(
            $ibId,
            [],
            ['SORT' => 'ASC', 'ID' => 'ASC']
        );

        $out = [];
        foreach ($elements as $el) {
            $id = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);

            $out[] = [
                'id'    => $id,
                'title' => $el['NAME'],
                'text'  => $el['PREVIEW_TEXT'],
                'image' => IbReader::imageUrl($el['PREVIEW_PICTURE']),
            ];
        }
        return $out;
    }
}