<?php
namespace X5\Pwa;
class Push
{
    public static function available(): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class);
    }
    public static function send(string $uid, array $sub, array $payload): array
    {
        if (!self::available()) {
            return ['ok' => false, 'statusCode' => null, 'message' => 'web-push not installed', 'gone' => false];
        }
        $v = self::vapidConfig();
        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject'    => $v['subject'],
                    'publicKey'  => $v['public'],
                    'privateKey' => $v['private'],
                ],
            ]);
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint'  => $sub['endpoint'],
                'publicKey' => $sub['keys']['p256dh'] ?? null,
                'authToken' => $sub['keys']['auth']   ?? null,
            ]);
            $report = $webPush->sendOneNotification(
                $subscription,
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
            if ($report->isSuccess()) {
                return ['ok' => true, 'statusCode' => 201, 'message' => null, 'gone' => false];
            }
            $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
            return ['ok' => false, 'statusCode' => $code,
                    'message' => $report->getReason(),
                    'gone' => in_array($code, [404, 410], true)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'statusCode' => null, 'message' => $e->getMessage(), 'gone' => false];
        }
    }
    public static function vapidConfig(): array
    {
        return [
            'public'  => 'BAqETm_cLHiSgmwg-dTfL9-qXU20Mpy41p2_-ZCDc_PE7wotRVex4Jacly9uiAkJtbtuJDwj5gzeww_869Iq5U8',
            'private' => 'Kff45_imZEaTFZ2-ODGMXQ09DoTKogJcTrty0yxvU4Y',
            'subject' => 'mailto:admin@x5hd.ru',
        ];
    }
}