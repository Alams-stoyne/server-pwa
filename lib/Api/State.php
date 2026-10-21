<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class State
{
    public static function save(array $b): void
    {
        $uid = (string)($b['UID'] ?? '');
        if (!$uid) { Router::json(['RESULT' => 'ERROR'], 400); return; }

        Storage::saveState($uid, $b['STATE'] ?? null);
        Router::json(['RESULT' => 'OK']);
    }
}