<?php
namespace X5\Pwa;

class S3
{
    private const DEFAULT_CACHE = 'public, max-age=3600';

    public static function available(): bool
    {
        return class_exists(\Aws\S3\S3Client::class);
    }

    private static function client(): ?\Aws\S3\S3Client
    {
        if (!self::available()) return null;
        $cfg = Settings::s3();
        if (!$cfg['endpoint'] || !$cfg['bucket']
            || !$cfg['access_key'] || !$cfg['secret_key']) {
            return null;
        }
        return new \Aws\S3\S3Client([
            'version'   => 'latest',
            'region'    => $cfg['region'] ?: 'us-east-1',
            'endpoint'  => $cfg['endpoint'],
            'use_path_style_endpoint' => $cfg['path_style'],
            'credentials' => [
                'key'    => $cfg['access_key'],
                'secret' => $cfg['secret_key'],
            ],
        ]);
    }

    /**
     * Cache-Control по ключу файла.
     *
     * Политика:
     *   • events/YYYY-MM-DD.json  — 10 минут (админ может добавить событие
     *     «на сегодня», клиент должен увидеть его быстро);
     *   • stats/global.json       — 1 минута;
     *   • manifest.json           — 1 минута;
     *   • всё остальное           — 1 час.
     */
    public static function cacheControlForKey(string $key): string
    {
        $key = ltrim($key, '/');

        if (preg_match('#^events/\d{4}-\d{2}-\d{2}\.json$#', $key)) return 'public, max-age=600';
        if (preg_match('#^events/#', $key))                       return 'public, max-age=600';
        if ($key === 'stats/global.json')                         return 'public, max-age=60';
        if ($key === 'manifest.json')                             return 'public, max-age=60';

        return self::DEFAULT_CACHE;
    }

    public static function upload(
        string $key,
        string $content,
        string $contentType = 'application/json',
        ?string $cacheControl = null
    ): array {
        if (!Settings::getBool('s3_enabled')) {
            return ['ok' => false, 'key' => $key,
                    'message' => 'S3 disabled (включите галочку «Включить S3»)'];
        }
        if (!self::available()) {
            return ['ok' => false, 'key' => $key,
                    'message' => 'aws/aws-sdk-php не установлен'];
        }

        $cfg = Settings::s3();
        $missing = [];
        foreach (['endpoint', 'bucket', 'access_key', 'secret_key'] as $k) {
            if ($cfg[$k] === '') $missing[] = $k;
        }
        if ($missing) {
            return ['ok' => false, 'key' => $key,
                    'message' => 'S3 не настроен: не заполнены поля — ' . implode(', ', $missing)];
        }

        $client = self::client();
        if (!$client) {
            return ['ok' => false, 'key' => $key,
                    'message' => 'S3 клиент не создан'];
        }

        try {
            $prefix  = trim((string)$cfg['prefix'], '/');
            $fullKey = ($prefix ? $prefix . '/' : '') . ltrim($key, '/');

            $cc = $cacheControl ?? self::cacheControlForKey($key);

            $client->putObject([
                'Bucket'       => $cfg['bucket'],
                'Key'          => $fullKey,
                'Body'         => $content,
                'ContentType'  => $contentType,
                'CacheControl' => $cc,
            ]);

            $localPath = self::localCachePath($fullKey);
            @mkdir(dirname($localPath), 0775, true);
            @file_put_contents($localPath, $content);

            return ['ok' => true, 'key' => $fullKey, 'message' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'key' => $key, 'message' => $e->getMessage()];
        }
    }

    public static function localCachePath(string $key): string
    {
        return __DIR__ . '/../storage/content-cache/' . ltrim($key, '/');
    }

    public static function readLocal(string $key): ?string
    {
        $cfg = Settings::s3();
        $prefix  = trim((string)($cfg['prefix'] ?? ''), '/');
        $fullKey = ($prefix ? $prefix . '/' : '') . ltrim($key, '/');
        $p = self::localCachePath($fullKey);
        return is_file($p) ? (string)file_get_contents($p) : null;
    }

    public static function testConnection(): array
    {
        $ts   = (new \Bitrix\Main\Type\DateTime())->toString();
        $body = json_encode(['ts' => $ts, 'ok' => true]);
        $r    = self::upload('_healthcheck.json', (string)$body);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'path' => $r['key'], 'message' => null];
    }
}