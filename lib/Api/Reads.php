<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
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

        // Начисление награды (только если лекция опубликована сегодня по локале)
        try {
            // ID_LECTURE приходит в формате "{elementId}-{YYYY-MM-DD}" (см. Content)
            if (preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})$/', $id, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveLectureInfo($elementId);

                if ($info !== null) {
                    RewardsBuilder::awardLecture(
                        $uid,
                        $elementId,
                        $info['type'],
                        $info['points'],
                        $info['title'],
                        $info['date'],
                        $localDate
                    );
                }
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award on read failed uid=' . substr($uid, 0, 12) . '… id=' . $id
                . ': ' . $e->getMessage(),
                'Reads::add',
                '/x5.pwa.log'
            );
        }

        return [
            'RESULT'          => 'OK',
            'ID_READ'         => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}