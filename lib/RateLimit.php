<?php
namespace X5\Pwa;

class RateLimit
{
    public const WINDOW_MS = 60000;
    public const MAX       = 10;

    /**
     * @return array{ok:bool,retryAfterMs:int}
     */
    public static function check(string $key): array
    {
        $now = (int)(microtime(true) * 1000);
        $bucket = Storage::getRateBucket($key);
        $bucket = array_values(array_filter(
            $bucket,
            fn($t) => ($now - (int)$t) < self::WINDOW_MS
        ));
        if (count($bucket) >= self::MAX) {
            return [
                'ok' => false,
                'retryAfterMs' => self::WINDOW_MS - ($now - (int)$bucket[0]),
            ];
        }
        $bucket[] = $now;
        Storage::setRateBucket($key, $bucket);
        return ['ok' => true, 'retryAfterMs' => 0];
    }
}