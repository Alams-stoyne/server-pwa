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
 *
 * Учитываются ТОЛЬКО активные темы (ACTIVE = 'Y').
 */
class ThemesResolver
{
    private static bool $loaded = false;

    /** ID элемента темы → CODE ('5' → 'energy') */
    private static array $byId = [];

    /** Порядок кодов в том виде, как они выведены в IB (только активные) */
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

        // Уже строка-код (обратная совместимость со старыми L-значениями
        // и с кодами, которые приходят из ContentBuilder для L-свойств).
        // ВНИМАНИЕ: для деактивированных E-тем здесь вернётся голый ID,
        // и вызывающий код должен сам отсеять такие значения по getAllCodes().
        return $raw;
    }

    /**
     * Список актуальных (активных) кодов тем, отсортированный как в IB.
     * @return string[]
     */
    public static function getAllCodes(): array
    {
        self::loadOnce();
        return self::$codes;
    }

    /**
     * Настроен ли ИБ «Направления».
     * Нужен, чтобы отличать «модуль не сконфигурирован» (fallback на хардкод)
     * от «все темы деактивированы» (пустой список — это валидный результат).
     */
    public static function isConfigured(): bool
    {
        return Settings::iblockId('themes') > 0;
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
            [
                'IBLOCK_ID'         => $ibId,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ],
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