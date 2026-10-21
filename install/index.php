<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;

Loc::loadMessages(__FILE__);

class x5_pwa extends CModule
{
    public $MODULE_ID           = 'x5.pwa';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'Y';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION      = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME         = 'PWA API (x5)';
        $this->MODULE_DESCRIPTION  = 'REST API для PWA: UID-привязки, лекции, задания, привычки, статистика, push, S3, рефлексия';
    }

    public function DoInstall(): bool
    {
        global $APPLICATION;

        if (!ModuleManager::isModuleInstalled('highloadblock')) {
            $APPLICATION->ThrowException('Требуется модуль highloadblock');
            return false;
        }
        if (!ModuleManager::isModuleInstalled('iblock')) {
            $APPLICATION->ThrowException('Требуется модуль iblock');
            return false;
        }

        ModuleManager::registerModule($this->MODULE_ID);

        Loader::includeModule($this->MODULE_ID);
        Loader::includeModule('highloadblock');
        Loader::includeModule('iblock');

        \X5\Pwa\HlSetup::install();
        \X5\Pwa\IblockSetup::install();
        \X5\Pwa\Module::installDefaults();
        \X5\Pwa\Module::register();

        // Обёртки в /bitrix/admin/
        \X5\Pwa\AdminSetup::install();

        try {
            \X5\Pwa\Dumper::regenerateAll();
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                $e->getMessage(), 'install dumper', '/x5.pwa.log'
            );
        }

        return true;
    }

    public function DoUninstall(): bool
    {
        Loader::includeModule('highloadblock');
        Loader::includeModule('iblock');
        Loader::includeModule($this->MODULE_ID);

        // Удаляем только наши обёртки
        \X5\Pwa\AdminSetup::uninstall();

        \X5\Pwa\Module::unregister();
        \X5\Pwa\HlSetup::uninstall();
        \X5\Pwa\IblockSetup::uninstall();
        \Bitrix\Main\Config\Option::delete($this->MODULE_ID);

        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}