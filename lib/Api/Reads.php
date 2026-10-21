<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class Reads
{
    public static function list(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? $b['UID'] ?? '');
        if (!$uid) return ['RESULT' => 'ERROR'];

        Storage::touchBindingByUid($uid);

        return ['RESULT' => 'OK', 'READS' => Storage::getReads($uid)];
    }

    public static function add(array $b): array
    {
        $uid = (string)($b['ID_USER'] ?? '');
        $id  = (string)($b['ID_LECTURE'] ?? '');
        if (!$uid || !$id) return ['RESULT' => 'ERROR'];

        Storage::addRead($uid, $id);
        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'ID_READ'         => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}