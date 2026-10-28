<?php
namespace X5\Pwa\Api;

use X5\Pwa\Push;
use X5\Pwa\Storage;
use X5\Pwa\Settings;

class PushApi
{
    public static function vapidInfo(): array
    {
        $v = Settings::vapid();
        return [
            'RESULT'           => 'OK',
            'VAPID_PUBLIC_KEY' => $v['public'],
            'VAPID_SUBJECT'    => $v['subject'],
            'SUBSCRIPTIONS'    => count(Storage::listSubscriptions()),
        ];
    }

    public static function subscribe(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        $sub = $b['subscription'] ?? null;

        if ($uid === '' || !is_array($sub) || empty($sub['endpoint'])) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER and subscription.endpoint required'];
        }

        try {
            Storage::saveSubscription($uid, $sub);
        } catch (\Throwable $e) {
            Settings::logError(
                'push subscribe failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'PushApi::subscribe'
            );
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить подписку.'];
        }

        return ['RESULT' => 'OK'];
    }

    public static function unsubscribe(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];
        }

        try {
            Storage::deleteSubscription($uid);
        } catch (\Throwable $e) {
            Settings::logError(
                'push unsubscribe failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'PushApi::unsubscribe'
            );
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось удалить подписку.'];
        }

        return ['RESULT' => 'OK'];
    }

    public static function send(array $b): array
    {
        $uid   = trim((string)($b['ID_USER'] ?? ''));
        $title = trim((string)($b['title'] ?? ''));
        $body  = trim((string)($b['body'] ?? ''));

        if ($uid === '' || $title === '' || $body === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER, title and body required'];
        }

        $sub = Storage::getSubscription($uid);
        if (!$sub) {
            return ['RESULT' => 'NOT_FOUND', 'MESSAGE' => 'Subscription not found'];
        }

        $payload = [
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => (string)($b['url'] ?? '/'),
            'tag'   => (string)($b['tag'] ?? 'admin-push'),
        ];

        $r = Push::send($uid, $sub, $payload);

        Storage::addPushHistory([
            'type'   => 'send',
            'target' => $uid,
            'title'  => $title,
            'body'   => $body,
            'url'    => $payload['url'],
            'status' => (string)($r['statusCode'] ?? ''),
        ]);

        if (!$r['ok'] && !empty($r['gone'])) {
            Storage::deleteSubscription($uid);
        }

        return [
            'RESULT'  => !empty($r['ok']) ? 'OK' : 'ERROR',
            'STATUS'  => $r['statusCode'] ?? null,
            'MESSAGE' => $r['message'] ?? null,
        ];
    }

    public static function broadcast(array $b): array
    {
        $title = trim((string)($b['title'] ?? ''));
        $body  = trim((string)($b['body'] ?? ''));

        if ($title === '' || $body === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'title and body required'];
        }

        $payload = [
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => (string)($b['url'] ?? '/'),
            'tag'   => (string)($b['tag'] ?? 'admin-broadcast'),
        ];

        $r = Push::broadcast($payload);

        Storage::addPushHistory([
            'type'   => 'broadcast',
            'target' => '*',
            'title'  => $title,
            'body'   => $body,
            'url'    => $payload['url'],
            'status' => "sent:{$r['sent']},failed:{$r['failed']}",
        ]);

        return [
            'RESULT'  => 'OK',
            'SENT'    => $r['sent'],
            'FAILED'  => $r['failed'],
            'REMOVED' => $r['removed'],
            'ERRORS'  => $r['errors'],
        ];
    }
}