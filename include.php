<?php
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

Loader::registerAutoLoadClasses('x5.pwa', [
    'X5\\Pwa\\Module'            => 'lib/Module.php',
    'X5\\Pwa\\Settings'          => 'lib/Settings.php',
    'X5\\Pwa\\S3'                => 'lib/S3.php',
    'X5\\Pwa\\HlSetup'           => 'lib/HlSetup.php',
    'X5\\Pwa\\IblockSetup'       => 'lib/IblockSetup.php',
    'X5\\Pwa\\AdminSetup'        => 'lib/AdminSetup.php',
    'X5\\Pwa\\ContentBuilder'    => 'lib/ContentBuilder.php',
    'X5\\Pwa\\IbReader'          => 'lib/IbReader.php',
    'X5\\Pwa\\ThemesResolver'    => 'lib/ThemesResolver.php',
    'X5\\Pwa\\ThemesBuilder'     => 'lib/ThemesBuilder.php',
    'X5\\Pwa\\QuizBuilder'       => 'lib/QuizBuilder.php',
    'X5\\Pwa\\HabitsBuilder'     => 'lib/HabitsBuilder.php',
    'X5\\Pwa\\TodayBuilder'      => 'lib/TodayBuilder.php',
    'X5\\Pwa\\TutorialBuilder'   => 'lib/TutorialBuilder.php',
    'X5\\Pwa\\ReflectionBuilder' => 'lib/ReflectionBuilder.php',
    'X5\\Pwa\\StepsBuilder'      => 'lib/StepsBuilder.php',
    'X5\\Pwa\\SupportBuilder'    => 'lib/SupportBuilder.php',
    'X5\\Pwa\\EventsBuilder'     => 'lib/EventsBuilder.php',
    'X5\\Pwa\\RewardsBuilder'    => 'lib/RewardsBuilder.php',
    'X5\\Pwa\\WorkplacesBuilder' => 'lib/WorkplacesBuilder.php',
    'X5\\Pwa\\Dumper'            => 'lib/Dumper.php',
    'X5\\Pwa\\Storage'           => 'lib/Storage.php',
    'X5\\Pwa\\Data'              => 'lib/Data.php',
    'X5\\Pwa\\Push'              => 'lib/Push.php',
    'X5\\Pwa\\Scheduler'         => 'lib/Scheduler.php',
    'X5\\Pwa\\RateLimit'         => 'lib/RateLimit.php',
    'X5\\Pwa\\Cors'              => 'lib/Cors.php',
    'X5\\Pwa\\StatsBuilder'      => 'lib/StatsBuilder.php',
    'X5\\Pwa\\Outbox'            => 'lib/Outbox.php',
    'X5\\Pwa\\ApplesBuilder'     => 'lib/ApplesBuilder.php',

    'X5\\Pwa\\Property\\QuizAnswer' => 'lib/Property/QuizAnswer.php',

    'X5\\Pwa\\Api\\Router'     => 'lib/Api/Router.php',
    'X5\\Pwa\\Api\\RestBridge' => 'lib/Api/RestBridge.php',
    'X5\\Pwa\\Api\\Auth'       => 'lib/Api/Auth.php',
    'X5\\Pwa\\Api\\Reads'      => 'lib/Api/Reads.php',
    'X5\\Pwa\\Api\\Tasks'      => 'lib/Api/Tasks.php',
    'X5\\Pwa\\Api\\Habits'     => 'lib/Api/Habits.php',
    'X5\\Pwa\\Api\\State'      => 'lib/Api/State.php',
    'X5\\Pwa\\Api\\Stats'      => 'lib/Api/Stats.php',
    'X5\\Pwa\\Api\\Content'    => 'lib/Api/Content.php',
    'X5\\Pwa\\Api\\PushApi'    => 'lib/Api/PushApi.php',
    'X5\\Pwa\\Api\\Admin'      => 'lib/Api/Admin.php',
    'X5\\Pwa\\Api\\Profile'    => 'lib/Api/Profile.php',
    'X5\\Pwa\\Api\\Config'     => 'lib/Api/Config.php',
    'X5\\Pwa\\Api\\Today'      => 'lib/Api/Today.php',
    'X5\\Pwa\\Api\\Reflection' => 'lib/Api/Reflection.php',
    'X5\\Pwa\\Api\\Steps'      => 'lib/Api/Steps.php',
    'X5\\Pwa\\Api\\Consent'    => 'lib/Api/Consent.php',
    'X5\\Pwa\\Api\\Support'    => 'lib/Api/Support.php',
    'X5\\Pwa\\Api\\Events'     => 'lib/Api/Events.php',
    'X5\\Pwa\\Api\\Rewards'    => 'lib/Api/Rewards.php',
    'X5\\Pwa\\Api\\Apples'     => 'lib/Api/Apples.php',
]);

if (ModuleManager::isModuleInstalled('x5.pwa')) {
    \X5\Pwa\Module::register();

    if (Loader::includeModule('highloadblock')) {
        $needHl = \X5\Pwa\Settings::getInt('hl_setup_version', 0)
                  < \X5\Pwa\HlSetup::currentVersion();
        if ($needHl) {
            try {
                \X5\Pwa\HlSetup::install();
                \X5\Pwa\Settings::set(
                    'hl_setup_version',
                    (string)\X5\Pwa\HlSetup::currentVersion()
                );
            } catch (\Throwable $e) {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    $e->getMessage(), 'include hl auto-setup', '/x5.pwa.log'
                );
            }
        }
    }
}

if (Loader::includeModule('iblock')) {
    $moduleId = 'x5.pwa';

    $currentKeys = [
        'themes', 'general_lectures', 'charging', 'theme_lectures',
        'tasks', 'quiz_questions', 'habits', 'today_widgets',
        'tutorial', 'reflections', 'events', 'workplaces',
    ];

    $requiredProps = [
        'themes'           => ['SUBTITLE', 'VIDEO_URL', 'VIDEO_DESC', 'MASCOT'],
        'general_lectures' => ['VIDEO_LINK', 'PHOTOS', 'REWARD_POINTS'],
        'charging'         => ['VIDEO_LINK', 'PHOTOS', 'REWARD_POINTS'],
        'theme_lectures'   => ['THEME', 'VIDEO_LINK', 'PHOTOS', 'REWARD_POINTS'],
        'tasks'            => ['THEME', 'VIDEO_LINK', 'REWARD_POINTS'],
        'quiz_questions'   => ['ANSWERS_LIST'],
        'habits'           => ['HINT', 'TIMES_PER_DAY', 'MIN_INTERVAL_MIN', 'TIMES_PER_DAY_HINT', 'MIN_INTERVAL_HINT', 'NOTIFY_FROM_TIME', 'REWARD_POINTS'],
        'today_widgets'    => ['STATE', 'SCENARIO', 'TITLE', 'DESCRIPTION'],
        'tutorial'         => [],
        'reflections'      => ['OPEN_TEXT','SEND_TEXT','AFTER_TEXT','REWARD_POINTS'],
        'events'           => ['LINK', 'BUTTON_TEXT', 'TIMER_MINS','REWARD_TEXT', 'REWARD_POINTS'],
        'workplaces'       => ['LOGO'],
    ];

    $needsSetup = Option::get($moduleId, 'ib_setup_done', 'N') !== 'Y';

    if (!$needsSetup) {
        foreach ($currentKeys as $k) {
            if ((int)Option::get($moduleId, 'ib_' . $k, 0) <= 0) {
                $needsSetup = true;
                break;
            }
        }
    }

    if (!$needsSetup) {
        foreach ($requiredProps as $key => $propsList) {
            if (!$propsList) continue;
            $ibId = (int)Option::get($moduleId, 'ib_' . $key, 0);
            if ($ibId <= 0) { $needsSetup = true; break; }

            $existing = [];
            $rs = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $ibId]);
            while ($p = $rs->Fetch()) $existing[$p['CODE']] = true;

            foreach ($propsList as $code) {
                if (empty($existing[$code])) {
                    $needsSetup = true;
                    break 2;
                }
            }
        }
    }

    if ($needsSetup) {
        try {
            \X5\Pwa\IblockSetup::install();
            \X5\Pwa\Settings::markIblockSetupDone();
        } catch (\Throwable $e) {
            \X5\Pwa\Settings::clearIblockSetupDone();
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'include iblock auto-setup', '/x5.pwa.log'
            );
        }
    }
}