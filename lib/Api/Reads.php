<?php
namespace X5\Pwa\Api;

use X5\Pwa\RewardsBuilder;
use X5\Pwa\Settings;
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

        // TZ из тела — чтобы убрать рассинхрон при смене TZ в середине сессии
        $tz = Storage::parseTzOffsetFromArray($b);
        if ($tz !== null) {
            Storage::saveUserTzOffsetMin($uid, $tz);
        }

        Storage::addRead($uid, $id);
        Storage::touchBindingByUid($uid);

        $rewardPoints = 0;
        $rewardType   = '';           // lecture_theme | lecture_general | charging
        $limitReached = false;
        $limitInfo    = [];

        try {
            if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $id, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveLectureInfo($elementId);

                if ($info === null) {
                    Settings::log(
                        'lecture resolve null uid=' . substr($uid, 0, 12) . '… lectureId=' . $id
                        . ' elementId=' . $elementId,
                        'Reads::add'
                    );
                } else {
                    // Маппинг source → REWARD_TYPE
                    $source = (string)($info['source'] ?? '');
                    $rewardType = match ($source) {
                        'general'  => 'lecture_general',
                        'charging' => 'charging',
                        default    => 'lecture_theme',
                    };

                    $res = RewardsBuilder::awardLecture(
                        $uid,
                        $elementId,
                        (string)$info['type'],
                        (int)$info['points'],
                        (string)$info['title'],
                        (string)$info['date'],
                        $localDate,
                        $source
                    );

                    if (!empty($res['added'])) {
                        $rewardPoints = (int)($res['points'] ?? 0);
                    } elseif (($res['reason'] ?? '') === 'DAILY_LIMIT_REACHED') {
                        $limitReached = true;
                        $limitInfo    = [
                            'type'  => $source,
                            'limit' => (int)($res['limit'] ?? 0),
                            'count' => (int)($res['count'] ?? 0),
                        ];
                    }

                    Settings::log(
                        sprintf(
                            'lecture award uid=%s elementId=%d source=%s points=%d lectureDate=%s localDate=%s added=%s',
                            substr($uid, 0, 12) . '…',
                            $elementId,
                            $source !== '' ? $source : '?',
                            (int)$info['points'],
                            $info['date'],
                            $localDate,
                            !empty($res['added'])
                                ? 'yes'
                                : (($res['reason'] ?? '') === 'DAILY_LIMIT_REACHED'
                                    ? 'daily_limit'
                                    : (!empty($res['already']) ? 'already' : 'no'))
                        ),
                        'Reads::add'
                    );
                }
            } else {
                Settings::log(
                    'lecture id not recognized uid=' . substr($uid, 0, 12) . '… lectureId=' . $id,
                    'Reads::add'
                );
            }
        } catch (\Throwable $e) {
            Settings::logError(
                'award on read failed uid=' . substr($uid, 0, 12) . '… id=' . $id
                . ': ' . $e->getMessage(),
                'Reads::add'
            );
        }

        $response = [
            'RESULT'          => 'OK',
            'ID_READ'         => (int)(microtime(true) * 1000),
            'SERVER_DATETIME' => Storage::now()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($rewardPoints > 0) {
            $response['REWARD_POINTS'] = $rewardPoints;
            if ($rewardType !== '') {
                $response['REWARD_TYPE'] = $rewardType;
            }
        }

        if ($limitReached) {
            $response['REWARD_LIMIT_REACHED'] = true;
            $response['REWARD_LIMIT_INFO']    = $limitInfo;
        }

        return $response;
    }
}