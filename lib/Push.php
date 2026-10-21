<?php
namespace X5\Pwa;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class Push
{
    public static function available(): bool
    {
        return class_exists(WebPush::class);
    }

    private static function client(): ?WebPush
    {
        if (!self::available()) return null;

        $v = Settings::vapid();
        if ($v['public'] === '' || $v['private'] === '') {
            return null;
        }

        try {
            return new WebPush([
                'VAPID' => [
                    'subject'    => $v['subject'] ?: 'mailto:admin@x5hd.ru',
                    'publicKey'  => $v['public'],
                    'privateKey' => $v['private'],
                ],
            ]);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'Push::client', '/x5.pwa.log'
            );
            return null;
        }
    }

    /**
     * @return array{ok:bool,statusCode:?int,message:?string,gone:bool}
     */
    public static function send(string $uid, array $subscription, array $payload): array
    {
        if (!self::available()) {
            return [
                'ok' => false, 'statusCode' => null,
                'message' => 'web-push library not installed', 'gone' => false,
            ];
        }

        $endpoint = (string)($subscription['endpoint'] ?? '');
        $p256dh   = (string)($subscription['keys']['p256dh'] ?? '');
        $auth     = (string)($subscription['keys']['auth']   ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return [
                'ok' => false, 'statusCode' => null,
                'message' => 'invalid subscription (no endpoint/keys)',
                'gone' => true,
            ];
        }

        $webPush = self::client();
        if (!$webPush) {
            return [
                'ok' => false, 'statusCode' => null,
                'message' => 'VAPID keys are not configured', 'gone' => false,
            ];
        }

        try {
            $sub = Subscription::create([
                'endpoint'  => $endpoint,
                'publicKey' => $p256dh,
                'authToken' => $auth,
            ]);

            $report = $webPush->sendOneNotification(
                $sub,
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );

            if ($report->isSuccess()) {
                $code = $report->getResponse()
                    ? $report->getResponse()->getStatusCode()
                    : 201;
                return ['ok' => true, 'statusCode' => $code, 'message' => null, 'gone' => false];
            }

            $code   = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
            $reason = $report->getReason();

            \Bitrix\Main\Diag\Debug::writeToFile(
                sprintf(
                    'uid=%s status=%s reason=%s',
                    substr($uid, 0, 12) . '…',
                    (string)$code,
                    (string)$reason
                ),
                'Push::send failed',
                '/x5.pwa.log'
            );

            return [
                'ok'         => false,
                'statusCode' => $code,
                'message'    => $reason,
                'gone'       => in_array($code, [404, 410], true),
            ];
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'Push::send exception', '/x5.pwa.log'
            );
            return [
                'ok' => false, 'statusCode' => null,
                'message' => $e->getMessage(), 'gone' => false,
            ];
        }
    }

    /**
     * @return array{sent:int,failed:int,removed:int,errors:array}
     */
    public static function broadcast(array $payload): array
    {
        $sent = 0; $failed = 0; $removed = 0; $errors = [];

        foreach (Storage::listSubscriptions() as $row) {
            $uid = (string)$row['UF_UID'];
            $sub = Storage::getSubscription($uid);
            if (!$sub) continue;

            $r = self::send($uid, $sub, $payload);
            if ($r['ok']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = [
                    'userId'     => substr($uid, 0, 12) . '…',
                    'statusCode' => $r['statusCode'],
                    'message'    => $r['message'],
                ];
                if ($r['gone']) {
                    Storage::deleteSubscription($uid);
                    $removed++;
                }
            }
        }

        return [
            'sent' => $sent, 'failed' => $failed,
            'removed' => $removed, 'errors' => $errors,
        ];
    }

    /**
     * Payload для push-напоминания о привычке.
     * Emoji больше не используется — только title и hint.
     */
    public static function buildHabitPayload(string $habitId, array $cfg): array
    {
        $title = trim((string)($cfg['title'] ?? ''));
        if ($title === '') $title = 'Напоминание';

        return [
            'title' => $title,
            'body'  => (string)($cfg['hint'] ?? ''),
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => '/',
            'tag'   => 'habit-' . $habitId,
        ];
    }

    public static function vapidConfig(): array
    {
        return Settings::vapid();
    }
}