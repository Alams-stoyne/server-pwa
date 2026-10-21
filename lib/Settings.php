<?php
namespace X5\Pwa;

use Bitrix\Main\Config\Option;

class Settings
{
    private const MODULE = 'x5.pwa';

    /** Значение CDN по умолчанию, если настройка пуста. */
    private const CDN_DEFAULT = 'https://cdn.x5hd.ru';

    public static function get(string $key, string $default = ''): string
    {
        return (string)Option::get(self::MODULE, $key, $default);
    }

    public static function set(string $key, string $value): void
    {
        Option::set(self::MODULE, $key, $value);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int)Option::get(self::MODULE, $key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return Option::get(self::MODULE, $key, $default ? 'Y' : 'N') === 'Y';
    }

    /**
     * Базовый URL CDN для ссылок на изображения.
     *
     * Всегда возвращает валидный URL (без завершающего слеша):
     *   • если cdn_base заполнен и не похож на S3 endpoint — возвращает его;
     *   • если cdn_base пуст — возвращает https://cdn.x5hd.ru;
     *   • если в cdn_base случайно записан S3 endpoint — игнорирует его,
     *     пишет предупреждение в /x5.pwa.log и возвращает https://cdn.x5hd.ru.
     */
    public static function cdnBase(): string
    {
        $raw = trim(self::get('cdn_base', ''));

        if ($raw === '') {
            return self::CDN_DEFAULT;
        }

        // Проверяем хост: если похоже на S3 endpoint — используем дефолт
        $host = parse_url($raw, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $isS3 = (bool)preg_match(
                '#(^s3[.\-]|\.s3[.\-]|storage\.|\.beget\.cloud$|\.amazonaws\.com$|^minio[.\-]|minio\.)#i',
                $host
            );
            if ($isS3) {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'cdn_base looks like S3 endpoint: "' . $raw . '" — falling back to ' . self::CDN_DEFAULT,
                    'Settings::cdnBase',
                    '/x5.pwa.log'
                );
                return self::CDN_DEFAULT;
            }
        }

        return rtrim($raw, '/');
    }

    // ── VAPID ──
    public static function vapid(): array
    {
        return [
            'public'  => self::get('vapid_public'),
            'private' => self::get('vapid_private'),
            'subject' => self::get('vapid_subject', 'mailto:admin@x5hd.ru'),
        ];
    }

    public static function generateVapidKeys(): array
    {
        if (class_exists(\Minishlink\WebPush\VAPID::class)) {
            try {
                return \Minishlink\WebPush\VAPID::createVapidKeys();
            } catch (\Throwable $e) {
                // fallback на OpenSSL
            }
        }

        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if (!$res) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($res);
        if (!isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new \RuntimeException('openssl_pkey_get_details: no EC info');
        }

        $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        return [
            'publicKey'  => self::base64urlEncode($publicRaw),
            'privateKey' => self::base64urlEncode($details['ec']['d']),
        ];
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── S3 ──
    public static function s3(): array
    {
        return [
            'enabled'    => self::getBool('s3_enabled'),
            'endpoint'   => self::get('s3_endpoint'),
            'region'     => self::get('s3_region'),
            'bucket'     => self::get('s3_bucket'),
            'access_key' => self::get('s3_access_key'),
            'secret_key' => self::get('s3_secret_key'),
            'prefix'     => self::get('s3_prefix', ''),
            'path_style' => self::getBool('s3_path_style'),
        ];
    }

    // ── CORS ──
    public static function allowedOrigins(): array
    {
        $raw   = self::get('allowed_origins');
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $lines),
            fn($s) => $s !== ''
        ));
    }

    // ── IBlock IDs ──
    private static function normalizeIblockKey(string $key): string
    {
        return strpos($key, 'ib_') === 0 ? $key : 'ib_' . $key;
    }

    public static function iblockId(string $key): int
    {
        return self::getInt(self::normalizeIblockKey($key), 0);
    }

    public static function setIblockId(string $key, int $id): void
    {
        self::set(self::normalizeIblockKey($key), (string)$id);
    }

    public static function isIblockSetupDone(): bool
    {
        return self::getBool('ib_setup_done', false);
    }

    public static function markIblockSetupDone(): void
    {
        self::set('ib_setup_done', 'Y');
    }

    public static function clearIblockSetupDone(): void
    {
        self::set('ib_setup_done', 'N');
    }

    // ── Служебное ──
    public static function touchDumpTime(): void
    {
        self::set('last_dump_at', (string)(time() * 1000));
    }

    public static function lastDumpAt(): int
    {
        return self::getInt('last_dump_at', 0);
    }
}