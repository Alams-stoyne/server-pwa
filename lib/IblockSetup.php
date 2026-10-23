<?php
namespace X5\Pwa;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('iblock');

class IblockSetup
{
    public const TYPE_ID = 'x5pwa_content';

    public static function config(): array
    {
        return [
            'themes' => [
                'name' => 'X5 PWA — Направления',
                'code' => 'x5pwa_themes',
                'props' => [
                    'SUBTITLE'   => ['type' => 'S'],
                    'VIDEO_URL'  => ['type' => 'S'],
                    'VIDEO_DESC' => ['type' => 'S'],
                    'MASCOT'     => ['type' => 'S'],
                ],
            ],
            'general_lectures' => [
                'name' => 'X5 PWA — Общие лекции',
                'code' => 'x5pwa_general_lectures',
                'props' => [
                    'VIDEO_LINK'    => ['type' => 'S'],
                    'PHOTOS'        => ['type' => 'F', 'multiple' => 'Y'],
                    'REWARD_POINTS' => ['type' => 'N'],
                ],
            ],
            'charging' => [
                'name' => 'X5 PWA — Зарядка',
                'code' => 'x5pwa_charging',
                'props' => [
                    'VIDEO_LINK'    => ['type' => 'S'],
                    'PHOTOS'        => ['type' => 'F', 'multiple' => 'Y'],
                    'REWARD_POINTS' => ['type' => 'N'],
                ],
            ],
            'theme_lectures' => [
                'name' => 'X5 PWA — Лекции по направлениям',
                'code' => 'x5pwa_theme_lectures',
                'props' => [
                    'THEME'         => ['type' => 'E', 'linkIblock' => 'themes'],
                    'VIDEO_LINK'    => ['type' => 'S'],
                    'PHOTOS'        => ['type' => 'F', 'multiple' => 'Y'],
                    'REWARD_POINTS' => ['type' => 'N'],
                ],
            ],
            'tasks' => [
                'name' => 'X5 PWA — Задания по направлениям',
                'code' => 'x5pwa_tasks',
                'props' => [
                    'THEME'         => ['type' => 'E', 'linkIblock' => 'themes'],
                    'VIDEO_LINK'    => ['type' => 'S'],
                    'REWARD_POINTS' => ['type' => 'N'],
                ],
            ],
            'quiz_questions' => [
                'name' => 'X5 PWA — Вопросы теста',
                'code' => 'x5pwa_quiz_questions',
                'props' => [
                    'ANSWERS_LIST' => [
                        'type'         => 'S',
                        'userType'     => 'x5_quiz_answer',
                        'multiple'     => 'Y',
                        'multiple_cnt' => 1,
                    ],
                    'ANSWERS' => ['type' => 'S', 'rows' => 15],
                ],
            ],
            'habits' => [
                'name' => 'X5 PWA — Привычки',
                'code' => 'x5pwa_habits',
                'props' => [
                    'HINT'               => ['type' => 'S'],
                    'TIMES_PER_DAY'      => ['type' => 'N'],
                    'MIN_INTERVAL_MIN'   => ['type' => 'N'],
                    'TIMES_PER_DAY_HINT' => ['type' => 'N'],
                    'MIN_INTERVAL_HINT'  => ['type' => 'N'],
                    'NOTIFY_FROM_TIME'   => ['type' => 'S'],
                    'REWARD_POINTS'      => ['type' => 'N'],
                ],
            ],
            'today_widgets' => [
                'name' => 'X5 PWA — Виджеты Today',
                'code' => 'x5pwa_today_widgets',
                'props' => [
                    'STATE'         => ['type' => 'L', 'values' => ['active', 'done']],
                    'SCENARIO'      => ['type' => 'L', 'values' => ['empty', 'progress', 'done_habits', 'done_all']],
                    'TITLE'         => ['type' => 'S'],
                    'DESCRIPTION'   => ['type' => 'S'],
                    'BUTTON_LABEL'  => ['type' => 'S'],
                    'BUTTON_URL'    => ['type' => 'S'],
                    'BUTTON_ACTION' => ['type' => 'L', 'values' => ['internal', 'external']],
                ],
            ],
            'tutorial' => [
                'name' => 'X5 PWA — Слайды обучения',
                'code' => 'x5pwa_tutorial',
                'props' => [],
            ],
            'reflections' => [
                'name' => 'X5 PWA — Настройки рефлексии',
                'code' => 'x5pwa_reflections',
                'props' => [
                    'OPEN_TEXT'         => ['type' => 'S'],
                    'SEND_TEXT'         => ['type' => 'S'],
                    'AFTER_TEXT'        => ['type' => 'S'],
                    'REWARD_POINTS'     => ['type' => 'N'],
                ],
            ],
            'events' => [
                'name' => 'X5 PWA — События дня',
                'code' => 'x5pwa_events',
                'props' => [
                    'LINK'          => ['type' => 'S'],
                    'BUTTON_TEXT'   => ['type' => 'S'],
                    'TIMER_MINS'    => ['type' => 'N'],
                    'REWARD_TEXT'   => ['type' => 'S'],
                    'REWARD_POINTS' => ['type' => 'N'],
                ],
            ],
        ];
    }

    public static function deprecatedProps(): array
    {
        return [
            'habits' => ['ICON', 'EMOJI'],
        ];
    }

    public static function install(): void
    {
        self::ensureType();
        $sites = self::getSites();

        foreach (self::config() as $key => $cfg) {
            $id = self::ensureIblock($cfg['code'], $cfg['name'], $sites);
            Settings::setIblockId($key, $id);
        }

        foreach (self::config() as $key => $cfg) {
            $ibId = Settings::iblockId($key);
            if ($ibId <= 0) continue;
            foreach ($cfg['props'] as $propCode => $prop) {
                self::ensureProperty($ibId, $propCode, $prop);
            }
        }

        self::cleanupDeprecated();

        foreach (self::config() as $key => $cfg) {
            if (Settings::iblockId($key) <= 0) {
                throw new \RuntimeException(
                    "Не удалось создать/найти инфоблок {$cfg['code']}"
                );
            }
        }
    }

    public static function uninstall(): void
    {
        foreach (self::config() as $cfg) {
            $ib = \CIBlock::GetList(
                [],
                ['=CODE' => $cfg['code'], 'CHECK_PERMISSIONS' => 'N']
            )->Fetch();
            if ($ib) \CIBlock::Delete($ib['ID']);
        }
    }

    public static function cleanupDeprecated(): void
    {
        foreach (self::deprecatedProps() as $ibKey => $propCodes) {
            $ibId = Settings::iblockId($ibKey);
            if ($ibId <= 0) continue;

            foreach ($propCodes as $code) {
                $prop = \CIBlockProperty::GetList([], [
                    'IBLOCK_ID' => $ibId,
                    'CODE'      => $code,
                ])->Fetch();
                if ($prop) {
                    \CIBlockProperty::Delete((int)$prop['ID']);
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        "Deleted deprecated property {$code} from IB {$ibId} ({$ibKey})",
                        'IblockSetup::cleanupDeprecated',
                        '/x5.pwa.log'
                    );
                }
            }
        }
    }

    private static function ensureType(): void
    {
        $existing = \CIBlockType::GetList([], ['=ID' => self::TYPE_ID])->Fetch();
        if ($existing) return;

        $ob = new \CIBlockType();
        $ob->Add([
            'ID'       => self::TYPE_ID,
            'SECTIONS' => 'Y',
            'IN_RSS'   => 'N',
            'SORT'     => 500,
            'LANG'     => [
                'ru' => ['NAME' => 'PWA-материалы'],
                'en' => ['NAME' => 'PWA materials'],
            ],
        ]);
    }

    private static function getSites(): array
    {
        $sites = [];
        $rs = \CSite::GetList($by = 'sort', $order = 'asc');
        while ($s = $rs->Fetch()) $sites[] = $s['LID'];
        return $sites;
    }

    private static function ensureIblock(string $code, string $name, array $sites): int
    {
        $existing = \CIBlock::GetList(
            [],
            ['=CODE' => $code, 'CHECK_PERMISSIONS' => 'N']
        )->Fetch();
        if ($existing) return (int)$existing['ID'];

        $ib = new \CIBlock();
        $id = $ib->Add([
            'ACTIVE'         => 'Y',
            'NAME'           => $name,
            'CODE'           => $code,
            'IBLOCK_TYPE_ID' => self::TYPE_ID,
            'SITE_ID'        => $sites,
            'SORT'           => 500,
            'VERSION'        => 1,
            'GROUP_ID'       => ['2' => 'R'],
            'FIELDS'         => [
                'CODE'            => ['IS_REQUIRED' => 'N'],
                'XML_ID'          => ['IS_REQUIRED' => 'N'],
                'PREVIEW_TEXT'    => ['IS_REQUIRED' => 'N'],
                'DETAIL_TEXT'     => ['IS_REQUIRED' => 'N'],
                'PREVIEW_PICTURE' => ['IS_REQUIRED' => 'N'],
            ],
        ]);
        if (!$id) {
            throw new \RuntimeException('CIBlock::Add failed: ' . $ib->LAST_ERROR);
        }
        return (int)$id;
    }

    private static function ensureProperty(int $iblockId, string $code, array $prop): void
    {
        $existing = \CIBlockProperty::GetList([], [
            'IBLOCK_ID' => $iblockId,
            'CODE'      => $code,
        ])->Fetch();

        $linkIblockId = 0;
        if (!empty($prop['linkIblock'])) {
            $linkIblockId = (int)Settings::iblockId($prop['linkIblock']);
        }

        $wantType        = (string)$prop['type'];
        $wantMultiple    = (string)($prop['multiple'] ?? 'N');
        $wantUserType    = (string)($prop['userType'] ?? '');
        $wantRowCount    = isset($prop['rows'])         ? (int)$prop['rows']         : null;
        $wantMultipleCnt = isset($prop['multiple_cnt']) ? (int)$prop['multiple_cnt'] : null;

        if (!$existing) {
            $fields = [
                'IBLOCK_ID'     => $iblockId,
                'NAME'          => $code,
                'CODE'          => $code,
                'ACTIVE'        => 'Y',
                'SORT'          => 500,
                'PROPERTY_TYPE' => $wantType,
                'MULTIPLE'      => $wantMultiple,
            ];

            if ($wantUserType !== '')      $fields['USER_TYPE']    = $wantUserType;
            if ($wantRowCount !== null)    $fields['ROW_COUNT']    = $wantRowCount;
            if ($wantMultipleCnt !== null) $fields['MULTIPLE_CNT'] = $wantMultipleCnt;
            if ($wantType === 'E' && $linkIblockId > 0) {
                $fields['LINK_IBLOCK_ID'] = $linkIblockId;
            }

            if ($wantType === 'L' && !empty($prop['values'])) {
                $fields['VALUES'] = array_map(
                    fn($v, $i) => ['VALUE' => $v, 'DEF' => 'N', 'SORT' => 100 * ($i + 1)],
                    $prop['values'],
                    array_keys($prop['values'])
                );
            }

            $ibp = new \CIBlockProperty();
            if (!$ibp->Add($fields)) {
                throw new \RuntimeException(
                    "CIBlockProperty::Add failed for $code: " . $ibp->LAST_ERROR
                );
            }
            return;
        }

        $update = [
            'IBLOCK_ID'     => $iblockId,
            'NAME'          => (string)($existing['NAME'] ?: $code),
            'CODE'          => (string)($existing['CODE'] ?: $code),
            'ACTIVE'        => (string)($existing['ACTIVE'] ?? 'Y'),
            'SORT'          => (int)($existing['SORT'] ?? 500),
            'PROPERTY_TYPE' => $wantType,
            'MULTIPLE'      => $wantMultiple,
            'USER_TYPE'     => $wantUserType !== ''
                ? $wantUserType
                : (string)($existing['USER_TYPE'] ?? ''),
        ];

        if ($wantRowCount !== null)    $update['ROW_COUNT']    = $wantRowCount;
        if ($wantMultipleCnt !== null) $update['MULTIPLE_CNT'] = $wantMultipleCnt;
        if ($wantType === 'E' && $linkIblockId > 0) {
            $update['LINK_IBLOCK_ID'] = $linkIblockId;
        }

        $ibp = new \CIBlockProperty();
        $ibp->Update((int)$existing['ID'], $update);

        self::verifyAndFixProperty((int)$existing['ID'], [
            'MULTIPLE'       => $wantMultiple,
            'MULTIPLE_CNT'   => $wantMultipleCnt,
            'USER_TYPE'      => $wantUserType,
            'PROPERTY_TYPE'  => $wantType,
            'LINK_IBLOCK_ID' => ($wantType === 'E' && $linkIblockId > 0) ? $linkIblockId : null,
        ]);
    }

    private static function verifyAndFixProperty(int $propertyId, array $expected): void
    {
        $row = \CIBlockProperty::GetList([], ['ID' => $propertyId])->Fetch();
        if (!$row) return;

        $fixes = [];

        if (array_key_exists('MULTIPLE', $expected) && $expected['MULTIPLE'] !== null) {
            $now = (string)($row['MULTIPLE'] ?? '');
            if ($now !== (string)$expected['MULTIPLE']) {
                $fixes['MULTIPLE'] = (string)$expected['MULTIPLE'];
            }
        }

        if (array_key_exists('MULTIPLE_CNT', $expected) && $expected['MULTIPLE_CNT'] !== null) {
            $now = (int)($row['MULTIPLE_CNT'] ?? 0);
            if ($now !== (int)$expected['MULTIPLE_CNT']) {
                $fixes['MULTIPLE_CNT'] = (int)$expected['MULTIPLE_CNT'];
            }
        }

        if (array_key_exists('USER_TYPE', $expected) && $expected['USER_TYPE'] !== '') {
            $now = (string)($row['USER_TYPE'] ?? '');
            if ($now !== (string)$expected['USER_TYPE']) {
                $fixes['USER_TYPE'] = (string)$expected['USER_TYPE'];
            }
        }

        if (array_key_exists('PROPERTY_TYPE', $expected) && $expected['PROPERTY_TYPE'] !== null) {
            $now = (string)($row['PROPERTY_TYPE'] ?? '');
            if ($now !== (string)$expected['PROPERTY_TYPE']) {
                $fixes['PROPERTY_TYPE'] = (string)$expected['PROPERTY_TYPE'];
            }
        }

        if (array_key_exists('LINK_IBLOCK_ID', $expected) && $expected['LINK_IBLOCK_ID'] !== null) {
            $now = (int)($row['LINK_IBLOCK_ID'] ?? 0);
            if ($now !== (int)$expected['LINK_IBLOCK_ID']) {
                $fixes['LINK_IBLOCK_ID'] = (int)$expected['LINK_IBLOCK_ID'];
            }
        }

        if (!$fixes) return;

        \Bitrix\Main\Diag\Debug::writeToFile(
            'Property ' . (int)$propertyId . ' (' . ($row['CODE'] ?? '?') . ') '
            . 'fixes=' . json_encode($fixes, JSON_UNESCAPED_UNICODE),
            'IblockSetup::verifyAndFixProperty',
            '/x5.pwa.log'
        );

        try {
            $conn = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();

            $allowed = ['MULTIPLE', 'MULTIPLE_CNT', 'USER_TYPE', 'PROPERTY_TYPE', 'LINK_IBLOCK_ID'];

            $setParts = [];
            foreach ($fixes as $field => $value) {
                if (!in_array($field, $allowed, true)) continue;
                $safeField = $sqlHelper->forSql($field);
                $safeValue = $sqlHelper->forSql((string)$value);
                $setParts[] = "`{$safeField}` = '{$safeValue}'";
            }

            if ($setParts) {
                $conn->queryExecute(
                    'UPDATE b_iblock_property SET ' . implode(', ', $setParts)
                    . ' WHERE ID = ' . (int)$propertyId
                );
            }
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'SQL fix failed: ' . $e->getMessage(),
                'IblockSetup::verifyAndFixProperty',
                '/x5.pwa.log'
            );
        }
    }
}