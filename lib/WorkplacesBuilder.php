<?php
namespace X5\Pwa;

class WorkplacesBuilder
{
    private static ?array $cache = null;

    private static function buildCdnFileUrl(string $src): string
    {
        $src = trim($src);
        if ($src === '') return '';

        $relPath = '';
        if (preg_match('#^https?://#i', $src)) {
            $parsed  = parse_url($src);
            $relPath = (string)($parsed['path'] ?? '');
        } elseif (strpos($src, '//') === 0) {
            $parsed  = parse_url('https:' . $src);
            $relPath = (string)($parsed['path'] ?? '');
        } else {
            $relPath = $src;
        }

        $relPath = '/' . ltrim($relPath, '/');

        $cfg    = Settings::s3();
        $bucket = trim((string)($cfg['bucket'] ?? ''), '/');
        $prefix = trim((string)($cfg['prefix'] ?? ''), '/');

        if (Settings::getBool('cdn_strip_bucket') && $bucket !== '') {
            $bucketPath = '/' . $bucket . '/';
            if (strpos($relPath, $bucketPath) === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen($bucketPath)), '/');
            }
        }

        if (Settings::getBool('cdn_strip_upload')) {
            if (strpos($relPath, '/upload/') === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen('/upload/')), '/');
            }
        }

        if ($prefix !== '') {
            $prefixPath = '/' . $prefix . '/';
            if (strpos($relPath, $prefixPath) === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen($prefixPath)), '/');
            }
        }

        $cdnBase = rtrim(Settings::cdnBase(), '/');

        return $cdnBase . $relPath;
    }

    public static function getAll(): array
    {
        if (self::$cache !== null) return self::$cache;

        $ibId = Settings::iblockId('workplaces');
        if (!$ibId) return self::$cache = [];

        $out = [];

        $rs = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'XML_ID']
        );

        while ($el = $rs->Fetch()) {
            $elId = (int)$el['ID'];
            $code = (string)($el['CODE'] ?: ($el['XML_ID'] ?: $elId));

            $logoId = 0;
            $propRs = \CIBlockElement::GetProperty($ibId, $elId, [], ['CODE' => 'LOGO']);
            if ($p = $propRs->Fetch()) {
                $logoId = (int)($p['VALUE'] ?? 0);
            }

            $logoUrl = '';
            if ($logoId > 0) {
                $fileArr = \CFile::GetFileArray($logoId);
                if ($fileArr && !empty($fileArr['SRC'])) {
                    $logoUrl = self::buildCdnFileUrl((string)$fileArr['SRC']);
                }
            }

            $out[] = [
                'id'   => $code,
                'name' => (string)$el['NAME'],
                'logo' => $logoUrl,
            ];
        }

        // Диагностика — операционный лог (гейтится debug_log_enabled)
        $summary = [];
        foreach ($out as $wp) {
            $summary[] = $wp['id'] . ' => ' . ($wp['logo'] !== '' ? 'OK' : 'EMPTY');
        }
        Settings::log(
            'workplaces loaded: ' . implode(', ', $summary)
            . ' | cdnBase=' . Settings::cdnBase()
            . ' | strip_bucket=' . (Settings::getBool('cdn_strip_bucket') ? 'Y' : 'N')
            . ' | strip_upload=' . (Settings::getBool('cdn_strip_upload') ? 'Y' : 'N'),
            'WorkplacesBuilder::getAll'
        );

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