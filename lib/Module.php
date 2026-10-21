<?php
namespace X5\Pwa;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class Module
{
    public const ID = 'x5.pwa';

    public static function register(): void
    {
        $em = EventManager::getInstance();

        // ─── Свои обработчики ───
        $em->addEventHandler('main', 'OnBeforeProlog', ['\\X5\\Pwa\\Api\\Router', 'handle']);
        $em->addEventHandler('main', 'OnAfterEpilog',  ['\\X5\\Pwa\\Scheduler',   'lazyRun']);

        $em->addEventHandler('iblock', 'OnAfterIBlockElementAdd',    ['\\X5\\Pwa\\Dumper', 'onElementSave']);
        $em->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', ['\\X5\\Pwa\\Dumper', 'onElementSave']);
        $em->addEventHandler('iblock', 'OnAfterIBlockElementDelete', ['\\X5\\Pwa\\Dumper', 'onElementDelete']);

        // ─── Пользовательский ТИП СВОЙСТВА ИНФОБЛОКА ───
        // Правильное событие: iblock/OnIBlockPropertyBuildList.
        // main/OnUserTypeBuildList — для UF-полей HL-блоков и юзеров,
        // для свойств инфоблоков не подходит.
        $em->addEventHandler('iblock', 'OnIBlockPropertyBuildList', [
            '\\X5\\Pwa\\Property\\QuizAnswer', 'GetUserTypeDescription',
        ]);

        // ─── REST ───
        if (ModuleManager::isModuleInstalled('rest')) {
            Loader::includeModule('rest');
            $em->addEventHandler('rest', 'OnRestServiceBuildDescription', [
                '\\X5\\Pwa\\Api\\RestBridge', 'onRestServiceBuildDescription',
            ]);
        }
    }

    public static function unregister(): void
    {
        $em = EventManager::getInstance();

        $em->removeEventHandler('main', 'OnBeforeProlog', ['\\X5\\Pwa\\Api\\Router', 'handle']);
        $em->removeEventHandler('main', 'OnAfterEpilog',  ['\\X5\\Pwa\\Scheduler',   'lazyRun']);

        $em->removeEventHandler('iblock', 'OnAfterIBlockElementAdd',    ['\\X5\\Pwa\\Dumper', 'onElementSave']);
        $em->removeEventHandler('iblock', 'OnAfterIBlockElementUpdate', ['\\X5\\Pwa\\Dumper', 'onElementSave']);
        $em->removeEventHandler('iblock', 'OnAfterIBlockElementDelete', ['\\X5\\Pwa\\Dumper', 'onElementDelete']);

        $em->removeEventHandler('iblock', 'OnIBlockPropertyBuildList', [
            '\\X5\\Pwa\\Property\\QuizAnswer', 'GetUserTypeDescription',
        ]);

        if (ModuleManager::isModuleInstalled('rest')) {
            $em->removeEventHandler('rest', 'OnRestServiceBuildDescription', [
                '\\X5\\Pwa\\Api\\RestBridge', 'onRestServiceBuildDescription',
            ]);
        }
    }

    public static function installDefaults(): void
    {
        $file = __DIR__ . '/../default_option.php';
        if (!is_file($file)) return;

        $default_option = [];
        include $file;
        if (!is_array($default_option)) return;

        foreach ($default_option as $k => $v) {
            $current = Option::get(self::ID, $k, null);
            if ($current === null) {
                Option::set(self::ID, $k, (string)$v);
            }
        }
    }
}