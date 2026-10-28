<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;
use X5\Pwa\RateLimit;
use X5\Pwa\Settings;

class Analytics
{
    public static function event(array $b): array
    {
        $deviceId  = trim((string)($b['DEVICE_ID'] ?? ''));
        $uid       = trim((string)($b['ID_USER'] ?? ''));
        $sessionId = trim((string)($b['SESSION_ID'] ?? ''));
        $appVersion= trim((string)($b['APP_VERSION'] ?? ''));
        $tzOffset  = (int)($b['TZ_OFFSET_MIN'] ?? 0);
        $localDt   = trim((string)($b['LOCAL_DATETIME'] ?? ''));
        $events    = $b['EVENTS'] ?? [];

        if (!is_array($events) || empty($events)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'EVENTS required'];
        }
        if (count($events) > 50) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Too many events (max 50)'];
        }

        // ─── PD GATE (152-ФЗ) ───
        $canWrite = true;
        
        if ($uid !== '') {
            // Если UID передан — проверяем его согласие
            $binding = Storage::getUidBinding($uid);
            if (!$binding || ($binding['UF_CONSENT_GRANTED'] ?? '') !== 'Y') {
                $canWrite = false;
            }
        } elseif ($deviceId !== '') {
            // Если UID нет, но устройство уже привязано к UID без согласия — не пишем
            $boundUid = Storage::getUidByDeviceId($deviceId);
            if ($boundUid !== null) {
                $binding = Storage::getUidBinding($boundUid);
                if (!$binding || ($binding['UF_CONSENT_GRANTED'] ?? '') !== 'Y') {
                    $canWrite = false;
                }
            }
        }

        if (!$canWrite) {
            // Возвращаем OK, чтобы клиент не спамил ретраями. Просто молча отбрасываем.
            return ['RESULT' => 'OK', 'RECEIVED' => count($events), 'SKIPPED' => true];
        }

        // ─── Rate Limit ───
        $rateKey = 'analytics:' . ($deviceId ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $rl = RateLimit::check($rateKey);
        if (!$rl['ok']) {
            return ['RESULT' => 'RATE_LIMITED', 'RETRY_AFTER_MS' => $rl['retryAfterMs']];
        }

        $received = 0;
        $serverNow = Storage::now();

        foreach ($events as $ev) {
            if (!is_array($ev)) continue;
            
            $name = trim((string)($ev['NAME'] ?? ''));
            if ($name === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $name)) continue;

            $ts = (int)($ev['TS'] ?? 0);
            if ($ts <= 0) $ts = time() * 1000;

            $props = $ev['PROPS'] ?? [];
            $propsJson = '';
            if (is_array($props) && !empty($props)) {
                $propsJson = json_encode($props, JSON_UNESCAPED_UNICODE);
                if (strlen($propsJson) > 2000) {
                    $propsJson = mb_substr($propsJson, 0, 1990) . '...';
                }
            }

            Storage::addAnalyticsEvent([
                'eventName'     => $name,
                'deviceId'      => mb_substr($deviceId, 0, 64),
                'uid'           => mb_substr($uid, 0, 40),
                'sessionId'     => mb_substr($sessionId, 0, 64),
                'ts'            => $ts,
                'serverAt'      => $serverNow,
                'props'         => $propsJson,
                'appVersion'    => mb_substr($appVersion, 0, 32),
                'tzOffsetMin'   => $tzOffset,
                'localDatetime' => mb_substr($localDt, 0, 32),
            ]);
            $received++;
        }

        return ['RESULT' => 'OK', 'RECEIVED' => $received];
    }
}