<?php
namespace X5\Pwa;

class WorkplacesBuilder
{
    private static ?array $cache = null;

    /**
     * Собирает публичный URL файла через CDN base URL.
     *
     * CFile::GetFileArray()['SRC'] может содержать:
     *   • абсолютный S3-URL: https://s3.ru1.storage.beget.cloud/bucket/iblock/.../file.svg
     *   • относительный путь: /upload/iblock/.../file.svg
     *
     * Логика:
     *   1. Из SRC извлекаем path (без схемы и хоста).
     *   2. Убираем /<bucket>/ если включён cdn_strip_bucket.
     *   3. Убираем /upload/ если включён cdn_strip_upload.
     *   4. Убираем /<prefix>/ если задан s3_prefix.
     *   5. Прилепляем результат к Settings::cdnBase().
     *
     * Пример:
     *   SRC = https://s3.ru1.storage.beget.cloud/786114594bf8-cdn-x5hd/iblock/6ac/...svg
     *   cdnBase = https://cdn.x5hd.ru
     *   результат = https://cdn.x5hd.ru/iblock/6ac/...svg
     */
    private static function buildCdnFileUrl(string $src): string
    {
        $src = trim($src);
        if ($src === '') return '';

        // ── 1. Извлекаем относительный путь из SRC ──
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

        // ── 2. Убираем /<bucket>/ ──
        if (Settings::getBool('cdn_strip_bucket') && $bucket !== '') {
            $bucketPath = '/' . $bucket . '/';
            if (strpos($relPath, $bucketPath) === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen($bucketPath)), '/');
            }
        }

        // ── 3. Убираем /upload/ ──
        if (Settings::getBool('cdn_strip_upload')) {
            if (strpos($relPath, '/upload/') === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen('/upload/')), '/');
            }
        }

        // ── 4. Убираем /<prefix>/ (если S3-префикс задан и он есть в начале) ──
        if ($prefix !== '') {
            $prefixPath = '/' . $prefix . '/';
            if (strpos($relPath, $prefixPath) === 0) {
                $relPath = '/' . ltrim(substr($relPath, strlen($prefixPath)), '/');
            }
        }

        // ── 5. Прилепляем к CDN base ──
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

            // Прямое чтение F-свойства LOGO
            // (IbReader::props() для F возвращает строку, а не массив ['VALUE' => ...])
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

        $summary = [];
        foreach ($out as $wp) {
            $summary[] = $wp['id'] . ' => ' . ($wp['logo'] !== '' ? 'OK' : 'EMPTY');
        }
        \Bitrix\Main\Diag\Debug::writeToFile(
            'workplaces loaded: ' . implode(', ', $summary)
            . ' | cdnBase=' . Settings::cdnBase()
            . ' | strip_bucket=' . (Settings::getBool('cdn_strip_bucket') ? 'Y' : 'N')
            . ' | strip_upload=' . (Settings::getBool('cdn_strip_upload') ? 'Y' : 'N'),
            'WorkplacesBuilder::getAll',
            '/x5.pwa.log'
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