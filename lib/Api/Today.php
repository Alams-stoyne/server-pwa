<?php
namespace X5\Pwa\Api;

use X5\Pwa\TodayBuilder;

class Today
{
    public static function build(array $input): array
    {
        $uid = trim((string)(
            $input['uid']
            ?? $input['UID']
            ?? $input['ID_USER']
            ?? ''
        ));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'uid required'];
        }

        return [
            'RESULT' => 'OK',
            'TODAY'  => TodayBuilder::buildForUser($uid),
        ];
    }
}