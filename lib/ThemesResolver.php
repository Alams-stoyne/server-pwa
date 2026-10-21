<?php
namespace X5\Pwa;

/**
 * Разрешает значение свойства THEME в символьный код темы.
 *
 * Свойство может быть:
 *   • L (список) — значение 'energy' (VALUE_ENUM/XML_ID)
 *   • E (привязка к элементу) — значение '5' (ID элемента темы)
 *
 * Плюс — умеет собирать актуальный список кодов тем из IB
 * «Направления» (для тех мест, где раньше был хардкод).
 */
class ThemesResolver
{
    private static bool $loaded = false;

    /** ID элемента темы → CODE ('5' → 'energy') */
    private static array $byId = [];

    /** Порядок кодов в том виде, как они выведены в IB */
    private static array $codes = [];

    public static function resolve($value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) $value = reset($value);

        $raw = trim((string)$value);
        if ($raw === '') return null;

        self::loadOnce();

        // Числовое значение → ищем CODE по ID темы
        if (ctype_digit($raw) && isset(self::$byId[$raw])) {
            return self::$byId[$raw];
        }

        // Уже строка-код (обратная совместимость со старыми L-значениями)
        return $raw;
    }

    /**
     * Список актуальных кодов тем, отсортированный как в IB.
     * @return string[]
     */
    public static function getAllCodes(): array
    {
        self::loadOnce();
        return self::$codes;
    }

    /**
     * Сброс кэша. Нужен в Dumper перед пересборкой,
     * чтобы подхватить свежий список тем.
     */
    public static function resetCache(): void
    {
        self::$loaded = false;
        self::$byId   = [];
        self::$codes  = [];
    }

    private static function loadOnce(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        if (!\Bitrix\Main\Loader::includeModule('iblock')) return;

        $ibId = Settings::iblockId('themes');
        if (!$ibId) return;

        $rs = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'CODE', 'XML_ID']
        );

        while ($el = $rs->Fetch()) {
            $code = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);
            self::$byId[(string)$el['ID']] = $code;
            self::$codes[] = $code;
        }
    }
}