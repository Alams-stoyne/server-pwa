<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
use X5\Pwa\Settings;
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

        // TZ из тела
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        Storage::addTask($uid, $k);
        Storage::touchBindingByUid($uid);

        $rewardPoints = 0;
        $limitReached = false;
        $limitInfo    = [];

        try {
            if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $k, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveTaskInfo($elementId);

                if ($info === null) {
                    Settings::log(
                        'task resolve null uid=' . substr($uid, 0, 12) . '… taskId=' . $k
                        . ' elementId=' . $elementId,
                        'Tasks::complete'
                    );
                } else {
                    $res = RewardsBuilder::awardTask(
                        $uid,
                        $elementId,
                        (int)$info['points'],
                        (string)$info['title'],
                        (string)$info['date'],
                        $localDate
                    );

                    if (!empty($res['added'])) {
                        $rewardPoints = (int)($res['points'] ?? 0);
                    } elseif (($res['reason'] ?? '') === 'DAILY_LIMIT_REACHED') {
                        $limitReached = true;
                        $limitInfo    = [
                            'limit' => (int)($res['limit'] ?? 0),
                            'count' => (int)($res['count'] ?? 0),
                        ];
                    }

                    Settings::log(
                        sprintf(
                            'task award uid=%s elementId=%d points=%d taskDate=%s localDate=%s added=%s reason=%s',
                            substr($uid, 0, 12) . '…',
                            $elementId,
                            (int)$info['points'],
                            $info['date'],
                            $localDate,
                            !empty($res['added']) ? 'yes' : 'no',
                            (string)(
                                $res['reason']
                                ?? (!empty($res['already']) ? 'ALREADY' : (!empty($res['added']) ? 'OK' : 'SKIPPED'))
                            )
                        ),
                        'Tasks::complete'
                    );
                }
            } else {
                Settings::log(
                    'task id not recognized uid=' . substr($uid, 0, 12) . '… taskId=' . $k,
                    'Tasks::complete'
                );
            }
        } catch (\Throwable $e) {
            Settings::logError(
                'award on task complete failed uid=' . substr($uid, 0, 12) . '… k=' . $k
                . ': ' . $e->getMessage(),
                'Tasks::complete'
            );
        }

        $response = [
            'RESULT'          => 'OK',
            'ID_COMPLETE'     => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($rewardPoints > 0) {
            $response['REWARD_POINTS'] = $rewardPoints;
            $response['REWARD_TYPE']   = 'task';
        }

        if ($limitReached) {
            $response['REWARD_LIMIT_REACHED'] = true;
            $response['REWARD_LIMIT_INFO']    = $limitInfo;
        }

        return $response;
    }
}