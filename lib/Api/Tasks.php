<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
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

        // Начисление награды (только если задание опубликовано сегодня по локале)
        try {
            // ID_TASK приходит в формате "{elementId}-{YYYY-MM-DD}" (см. Content)
            if (preg_match('/^(\d+)-(\d{4}-\d{2}-\d{2})$/', $k, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveTaskInfo($elementId);

                if ($info !== null) {
                    RewardsBuilder::awardTask(
                        $uid,
                        $elementId,
                        $info['points'],
                        $info['title'],
                        $info['date'],
                        $localDate
                    );
                }
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award on task complete failed uid=' . substr($uid, 0, 12) . '… k=' . $k
                . ': ' . $e->getMessage(),
                'Tasks::complete',
                '/x5.pwa.log'
            );
        }

        return [
            'RESULT'          => 'OK',
            'ID_COMPLETE'     => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}