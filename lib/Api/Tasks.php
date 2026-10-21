<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class Tasks
{
    public static function list(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? $b['UID'] ?? '');
        if (!$uid) return ['RESULT' => 'ERROR'];

        Storage::touchBindingByUid($uid);

        return ['RESULT' => 'OK', 'TASKS' => Storage::getTasks($uid)];
    }

    public static function complete(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? '');
        $k   = (string)($b['ID_TASK'] ?? '');
        if (!$uid || !$k) return ['RESULT' => 'ERROR'];

        Storage::addTask($uid, $k);
        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'ID_COMPLETE'     => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}