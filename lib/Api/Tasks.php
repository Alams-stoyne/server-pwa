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

        // ─── Начисление награды ───
        // Поддерживаем оба формата ID_TASK:
        //   • "{elementId}-{YYYY-MM-DD}"          — «чистый» (из CDN)
        //   • "{YYYY-MM-DD}:{elementId}-{YYYY-MM-DD}" — с префиксом даты
        //
        // Дневной лимит на практике применяется в RewardsBuilder::award()
        // через Settings::dailyTaskLimit(). При исчерпании лимита:
        //   • яблоки НЕ начисляются (UF_REWARD_BALANCE не меняется);
        //   • но запись в X5PwaTaskCompletions уже создана (прогресс сохранён);
        //   • клиент получает REWARD_LIMIT_REACHED: true без REWARD_POINTS.
        $rewardPoints = 0;
        $limitReached = false;
        $limitInfo    = [];

        try {
            if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $k, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveTaskInfo($elementId);

                if ($info === null) {
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        'task resolve null uid=' . substr($uid, 0, 12) . '… taskId=' . $k
                        . ' elementId=' . $elementId,
                        'Tasks::complete',
                        '/x5.pwa.log'
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

                    \Bitrix\Main\Diag\Debug::writeToFile(
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
                        'Tasks::complete',
                        '/x5.pwa.log'
                    );
                }
            } else {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'task id not recognized uid=' . substr($uid, 0, 12) . '… taskId=' . $k,
                    'Tasks::complete',
                    '/x5.pwa.log'
                );
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award on task complete failed uid=' . substr($uid, 0, 12) . '… k=' . $k
                . ': ' . $e->getMessage(),
                'Tasks::complete',
                '/x5.pwa.log'
            );
        }

        $response = [
            'RESULT'          => 'OK',
            'ID_COMPLETE'     => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($rewardPoints > 0) {
            $response['REWARD_POINTS'] = $rewardPoints;
        }

        if ($limitReached) {
            $response['REWARD_LIMIT_REACHED'] = true;
            $response['REWARD_LIMIT_INFO']    = $limitInfo;
        }

        return $response;
    }
}