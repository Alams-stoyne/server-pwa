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

        // ─── Начисление награды ───
        // Поддерживаем оба формата ID_LECTURE:
        //   • "{elementId}-{YYYY-MM-DD}"          — «чистый» (из CDN)
        //   • "{YYYY-MM-DD}:{elementId}-{YYYY-MM-DD}" — с префиксом даты
        // Legacy-формат "lecture-theme:YYYY-MM-DD:xyz" не парсится — награда не начисляется.
        try {
            if (preg_match('/(?:^|:)(\d+)-(\d{4}-\d{2}-\d{2})$/', $id, $m)) {
                $elementId = (int)$m[1];
                $localDate = RewardsBuilder::userLocalDate($uid);
                $info      = RewardsBuilder::resolveLectureInfo($elementId);

                if ($info === null) {
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        'lecture resolve null uid=' . substr($uid, 0, 12) . '… lectureId=' . $id
                        . ' elementId=' . $elementId,
                        'Reads::add',
                        '/x5.pwa.log'
                    );
                } else {
                    $res = RewardsBuilder::awardLecture(
                        $uid,
                        $elementId,
                        (string)$info['type'],
                        (int)$info['points'],
                        (string)$info['title'],
                        (string)$info['date'],
                        $localDate
                    );

                    \Bitrix\Main\Diag\Debug::writeToFile(
                        sprintf(
                            'lecture award uid=%s elementId=%d type=%s points=%d lectureDate=%s localDate=%s added=%s',
                            substr($uid, 0, 12) . '…',
                            $elementId,
                            $info['type'],
                            (int)$info['points'],
                            $info['date'],
                            $localDate,
                            !empty($res['added']) ? 'yes' : (!empty($res['already']) ? 'already' : 'no')
                        ),
                        'Reads::add',
                        '/x5.pwa.log'
                    );
                }
            } else {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'lecture id not recognized uid=' . substr($uid, 0, 12) . '… lectureId=' . $id,
                    'Reads::add',
                    '/x5.pwa.log'
                );
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