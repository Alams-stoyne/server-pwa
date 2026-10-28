<?php
namespace X5\Pwa;

class AdminSetup
{
    private const MODULE_ROOT   = 'local/modules/x5.pwa/';
    private const DETECT_MARKER = 'local/modules/x5.pwa/admin/';

    public static function files(): array
    {
        return [
            'x5.pwa_menu.php'                 => ['path' => 'admin/menu.php',                  'wrapper' => 'return'],
            'x5_pwa_users.php'                => ['path' => 'admin/users.php',                 'wrapper' => 'plain'],
            'x5_pwa_user_detail.php'          => ['path' => 'admin/user_detail.php',           'wrapper' => 'plain'],
            'x5_pwa_push.php'                 => ['path' => 'admin/push.php',                  'wrapper' => 'plain'],
            'x5_pwa_stats.php'                => ['path' => 'admin/stats.php',                 'wrapper' => 'plain'],
            'x5_pwa_users_export.php'         => ['path' => 'admin/export_csv.php',            'wrapper' => 'plain'],
            'x5_pwa_reflections.php'          => ['path' => 'admin/reflections.php',           'wrapper' => 'plain'],
            'x5_pwa_steps.php'                => ['path' => 'admin/steps.php',                 'wrapper' => 'plain'],
            'x5_pwa_steps_leaderboard.php'    => ['path' => 'admin/steps_leaderboard.php',     'wrapper' => 'plain'],
            'x5_pwa_daily_apples.php'         => ['path' => 'admin/daily_apples.php',          'wrapper' => 'plain'],
            'x5_pwa_support.php'              => ['path' => 'admin/support.php',               'wrapper' => 'plain'],
            'x5_pwa_support_detail.php'       => ['path' => 'admin/support_detail.php',        'wrapper' => 'plain'],
            'x5_pwa_support_export.php'       => ['path' => 'admin/export_support_csv.php',    'wrapper' => 'plain'],
            'x5_pwa_rewards.php'              => ['path' => 'admin/rewards.php',               'wrapper' => 'plain'],
            'x5_pwa_rewards_export.php'       => ['path' => 'admin/rewards_export.php',        'wrapper' => 'plain'],
            'x5_pwa_outbox.php'               => ['path' => 'admin/outbox.php',                'wrapper' => 'plain'],
            'x5_pwa_analytics.php'            => ['path' => 'admin/analytics.php',             'wrapper' => 'plain'], // <── ДОБАВЛЕНО
        ];
    }

    public static function install(): array
    {
        $report = ['created' => [], 'updated' => [], 'skipped' => [], 'errors' => []];

        $moduleRoot = self::moduleRoot();
        if ($moduleRoot === null) {
            $report['errors'][] = 'Не удалось определить путь к модулю';
            return $report;
        }

        $adminDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/bitrix/admin';
        if (!is_dir($adminDir)) {
            $report['errors'][] = "Каталог {$adminDir} не существует";
            return $report;
        }

        foreach (self::files() as $dstName => $cfg) {
            $srcPath = $moduleRoot . '/' . $cfg['path'];
            $dstPath = $adminDir . '/' . $dstName;

            if (!is_file($srcPath)) {
                $report['skipped'][] = "{$dstName} (нет исходника {$cfg['path']})";
                continue;
            }

            $desired = self::buildWrapper($cfg['path'], $cfg['wrapper']);

            if (!is_file($dstPath)) {
                if (@file_put_contents($dstPath, $desired) !== false) {
                    $report['created'][] = $dstName;
                } else {
                    $report['errors'][] = "Не удалось создать {$dstName}";
                }
                continue;
            }

            $current = (string)@file_get_contents($dstPath);
            if (self::isOurWrapper($current)) {
                if (trim($current) !== trim($desired)) {
                    if (@file_put_contents($dstPath, $desired) !== false) {
                        $report['updated'][] = $dstName;
                    } else {
                        $report['errors'][] = "Не удалось обновить {$dstName}";
                    }
                }
                continue;
            }

            $report['skipped'][] = "{$dstName} (файл существует, не наш — не тронут)";
        }

        if ($report['created'] || $report['updated'] || $report['errors']) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                json_encode($report, JSON_UNESCAPED_UNICODE),
                'AdminSetup::install',
                '/x5.pwa.log'
            );
        }

        return $report;
    }

    public static function uninstall(): array
    {
        $report = ['removed' => [], 'skipped' => [], 'errors' => []];

        $adminDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/bitrix/admin';
        if (!is_dir($adminDir)) return $report;

        foreach (array_keys(self::files()) as $dstName) {
            $dstPath = $adminDir . '/' . $dstName;
            if (!is_file($dstPath)) continue;

            $current = (string)@file_get_contents($dstPath);
            if (!self::isOurWrapper($current)) {
                $report['skipped'][] = $dstName;
                continue;
            }

            if (@unlink($dstPath)) {
                $report['removed'][] = $dstName;
            } else {
                $report['errors'][] = "Не удалось удалить {$dstName}";
            }
        }

        return $report;
    }

    public static function isOurWrapper(string $content): bool
    {
        return strpos($content, self::DETECT_MARKER) !== false;
    }

    private static function buildWrapper(string $modulePath, string $type): string
    {
        $relPath = '/' . self::MODULE_ROOT . $modulePath;

        if ($type === 'return') {
            return "<?php\n"
                . "return require(\$_SERVER['DOCUMENT_ROOT'] . '" . $relPath . "');\n";
        }

        return "<?php\n"
            . "require(\$_SERVER['DOCUMENT_ROOT'] . '" . $relPath . "');\n";
    }

    private static function moduleRoot(): ?string
    {
        $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $moduleRoot = $root . '/' . rtrim(self::MODULE_ROOT, '/');
        if (!is_dir($moduleRoot)) return null;
        return $moduleRoot;
    }
}