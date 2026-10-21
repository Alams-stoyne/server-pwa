<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class Profile
{
    public static function save(array $b): array
    {
        \Bitrix\Main\Diag\Debug::writeToFile(
            'RAW: ' . json_encode($b, JSON_UNESCAPED_UNICODE),
            'Profile::save raw',
            '/x5.pwa.log'
        );

        $uid = (string)($b['ID_USER'] ?? $b['UID'] ?? $b['uid'] ?? '');
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'];
        }

        // Профиль может приходить как PROFILE/profile, или плоско,
        // или внутри STATE. Storage::extractProfile сам разберётся.
        $source = $b;

        if (isset($b['STATE']) && is_array($b['STATE'])) {
            $source = $b['STATE'];
        } elseif (isset($b['PROFILE']) || isset($b['profile'])) {
            $source = $b;
        }

        $profile = Storage::extractProfile($source);

        \Bitrix\Main\Diag\Debug::writeToFile(
            sprintf(
                'uid=%s FIO="%s" NICK="%s" WORK="%s" THEME="%s"',
                substr($uid, 0, 12),
                $profile['FIO'], $profile['NICKNAME'],
                $profile['WORKPLACE'], $profile['THEME']
            ),
            'Profile::save parsed',
            '/x5.pwa.log'
        );

        Storage::syncBindingProfile($uid, $source);

        return ['RESULT' => 'OK'];
    }
}