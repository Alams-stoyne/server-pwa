<?php
namespace X5\Pwa;

class IbReader
{
    public static function props(int $iblockId, int $elementId): array
    {
        $out = [];
        $rs = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc']);
        while ($p = $rs->Fetch()) {
            $code = (string)($p['CODE'] ?? '');
            if ($code === '') continue;

            $isMultiple = ($p['MULTIPLE'] ?? 'N') === 'Y';
            if ($isMultiple) {
                if (!isset($out[$code])) $out[$code] = [];
                if ($p['VALUE'] !== null && $p['VALUE'] !== '') {
                    $out[$code][] = $p['VALUE'];
                }
            } else {
                $out[$code] = $p['VALUE'] ?? '';
            }
        }
        return $out;
    }

    public static function prop(array $props, string $code): string
    {
        if (!isset($props[$code])) return '';
        $v = $props[$code];
        if (is_array($v)) $v = reset($v);
        return trim((string)$v);
    }

    public static function propInt(array $props, string $code, int $default = 0): int
    {
        $v = self::prop($props, $code);
        if ($v === '') return $default;
        return (int)$v;
    }

    /**
     * Читает элементы IB.
     *
     * @return array<int, array{
     *   ID:int, NAME:string, CODE:string, XML_ID:string, SORT:int,
     *   PREVIEW_TEXT:string, DETAIL_TEXT:string,
     *   PREVIEW_PICTURE:int, DETAIL_PICTURE:int
     * }>
     */
    public static function elements(
        int $iblockId,
        array $filter = [],
        array $order = ['SORT' => 'ASC', 'ID' => 'ASC'],
        int $limit = 0
    ): array {
        if (!$iblockId) return [];

        $rs = \CIBlockElement::GetList(
            $order,
            array_merge([
                'IBLOCK_ID'         => $iblockId,
                'ACTIVE'            => 'Y',
                'CHECK_PERMISSIONS' => 'N',
            ], $filter),
            false,
            $limit > 0 ? ['nTopCount' => $limit] : false,
            ['ID', 'NAME', 'CODE', 'XML_ID', 'SORT',
             'PREVIEW_TEXT', 'DETAIL_TEXT',
             'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );

        $out = [];
        while ($f = $rs->Fetch()) {
            $out[] = [
                'ID'              => (int)$f['ID'],
                'NAME'            => (string)$f['NAME'],
                'CODE'            => (string)$f['CODE'],
                'XML_ID'          => (string)$f['XML_ID'],
                'SORT'            => (int)$f['SORT'],
                'PREVIEW_TEXT'    => (string)$f['PREVIEW_TEXT'],
                'DETAIL_TEXT'     => (string)$f['DETAIL_TEXT'],
                'PREVIEW_PICTURE' => (int)$f['PREVIEW_PICTURE'],
                'DETAIL_PICTURE'  => (int)$f['DETAIL_PICTURE'],
            ];
        }
        return $out;
    }

    /**
     * URL файла для отдачи клиенту.
     *
     * `CFile::GetPath()` может вернуть:
     *   • относительный путь: /upload/iblock/b9e/.../xxx.jpg
     *   • абсолютный URL:     https://s3.ru1.storage.beget.cloud/<bucket>/iblock/.../xxx.jpg
     *
     * Нормализуем в относительный путь и склеиваем с Settings::cdnBase().
     */
    public static function imageUrl(int $fileId): string
    {
        if ($fileId <= 0) return '';

        $path = \CFile::GetPath($fileId);
        if (!$path) return '';

        $normalized = self::normalizeFilePath((string)$path);
        if ($normalized === '') return '';

        return Settings::cdnBase() . '/' . ltrim($normalized, '/');
    }

    /**
     * Нормализует путь к файлу:
     *   1) из абсолютного URL берёт pathname;
     *   2) убирает /<bucket>/ (по флагу cdn_strip_bucket);
     *   3) убирает /upload/  (по флагу cdn_strip_upload).
     *
     * Результат — относительный путь (без ведущего http(s):// и хоста).
     */
    private static function normalizeFilePath(string $path): string
    {
        // 1. Абсолютный URL → pathname
        if (preg_match('#^https?://#i', $path)) {
            $parsed = parse_url($path);
            $path = isset($parsed['path']) ? (string)$parsed['path'] : '';
        }

        if ($path === '') return '';

        // 2. Убираем сегмент с именем бакета из начала
        if (Settings::getBool('cdn_strip_bucket', true)) {
            $bucket = trim((string)Settings::get('s3_bucket', ''), '/');
            if ($bucket !== '') {
                $prefix = '/' . $bucket;
                if (strpos($path, $prefix . '/') === 0) {
                    $path = substr($path, strlen($prefix));
                }
            }
        }

        // 3. Убираем /upload, если включено
        if (Settings::getBool('cdn_strip_upload', false)) {
            if (strpos($path, '/upload/') === 0) {
                $path = substr($path, strlen('/upload'));
            }
        }

        return $path;
    }
}