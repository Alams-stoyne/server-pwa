<?php
namespace X5\Pwa;

class WorkplacesBuilder
{
    private static ?array $cache = null;

    public static function getAll(): array
    {
        if (self::$cache !== null) return self::$cache;

        $ibId = Settings::iblockId('workplaces');
        if (!$ibId) return self::$cache = [];

        $elements = IbReader::elements($ibId);
        $out = [];

        foreach ($elements as $el) {
            $props = IbReader::props($ibId, (int)$el['ID']);

            $code = (string)($el['CODE'] ?: ($el['XML_ID'] ?: $el['ID']));

            $logoUrl = '';
            if (!empty($props['LOGO'])) {
                $v = $props['LOGO']['VALUE'] ?? null;
                if (is_array($v)) $v = reset($v);
                $logoId = (int)$v;
                if ($logoId > 0) {
                    $p = \CFile::GetPath($logoId);
                    if ($p) $logoUrl = $p;
                }
            }

            $out[] = [
                'id'   => $code,
                'name' => (string)$el['NAME'],
                'logo' => $logoUrl,
            ];
        }

        return self::$cache = $out;
    }

    public static function getByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') return null;
        foreach (self::getAll() as $wp) {
            if ((string)$wp['id'] === $code) return $wp;
        }
        return null;
    }

    public static function getMap(): array
    {
        $map = [];
        foreach (self::getAll() as $wp) {
            $map[(string)$wp['id']] = $wp;
        }
        return $map;
    }

    public static function getForCdn(): array
    {
        return ['workplaces' => self::getAll()];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}