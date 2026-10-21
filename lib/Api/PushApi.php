<?php
namespace X5\Pwa\Api;

use X5\Pwa\Push;
use X5\Pwa\Storage;
use X5\Pwa\Settings;

class PushApi
{
    public static function vapidInfo(): void
    {
        $v = Settings::vapid();
        Router::json([
            'RESULT'           => 'OK',
            'VAPID_PUBLIC_KEY' => $v['public'],
            'VAPID_SUBJECT'    => $v['subject'],
            'SUBSCRIPTIONS'    => count(Storage::listSubscriptions()),
        ]);
    }

    public static function subscribe(array $b): void
    {
        $uid = (string)($b['ID_USER'] ?? '');
        $sub = $b['subscription'] ?? null;
        if (!$uid || !is_array($sub) || empty($sub['endpoint'])) {
            Router::json(['RESULT' => 'ERROR'], 400);
            return;
        }
        Storage::saveSubscription($uid, $sub);
        Router::json(['RESULT' => 'OK']);
    }

    public static function unsubscribe(array $b): void
    {
        $uid = (string)($b['ID_USER'] ?? '');
        if (!$uid) { Router::json(['RESULT' => 'ERROR'], 400); return; }
        Storage::deleteSubscription($uid);
        Router::json(['RESULT' => 'OK']);
    }

    public static function send(array $b): void
    {
        $uid   = (string)($b['ID_USER'] ?? '');
        $title = (string)($b['title'] ?? '');
        $body  = (string)($b['body']  ?? '');
        if (!$uid || !$title || !$body) {
            Router::json(['RESULT' => 'ERROR'], 400);
            return;
        }

        $sub = Storage::getSubscription($uid);
        if (!$sub) { Router::json(['RESULT' => 'NOT_FOUND'], 404); return; }

        $payload = [
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => $b['url'] ?? '/',
            'tag'   => $b['tag'] ?? 'admin-push',
        ];

        $r = Push::send($uid, $sub, $payload);

        Storage::addPushHistory([
            'type'   => 'send',
            'target' => $uid,
            'title'  => $title,
            'body'   => $body,
            'url'    => $payload['url'],
            'status' => (string)$r['statusCode'],
        ]);

        if (!$r['ok'] && $r['gone']) {
            Storage::deleteSubscription($uid);
        }

        Router::json([
            'RESULT'  => $r['ok'] ? 'OK' : 'ERROR',
            'STATUS'  => $r['statusCode'],
            'MESSAGE' => $r['message'],
        ], $r['ok'] ? 200 : 500);
    }

    public static function broadcast(array $b): void
    {
        $title = (string)($b['title'] ?? '');
        $body  = (string)($b['body']  ?? '');
        if (!$title || !$body) { Router::json(['RESULT' => 'ERROR'], 400); return; }

        $payload = [
            'title' => $title,
            'body'  => $body,
            'icon'  => '/icons/icon-192.png',
            'badge' => '/icons/icon-192.png',
            'url'   => $b['url'] ?? '/',
            'tag'   => $b['tag'] ?? 'admin-broadcast',
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

        Router::json([
            'RESULT'  => 'OK',
            'SENT'    => $r['sent'],
            'FAILED'  => $r['failed'],
            'REMOVED' => $r['removed'],
            'ERRORS'  => $r['errors'],
        ]);
    }
}