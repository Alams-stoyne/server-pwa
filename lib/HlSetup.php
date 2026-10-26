<?php
namespace X5\Pwa;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('highloadblock');

class HlSetup
{
    public static function currentVersion(): int
    {
        return 18;
    }

    public static function config(): array
    {
        return [
            'UidBindings' => [
                'name'  => 'X5PwaUidBindings',
                'table' => 'x5_pwa_uid_bindings',
                'fields' => [
                    'UF_UID'               => ['string', ['SIZE' => 40]],
                    'UF_DEVICE_ID'         => ['string', ['SIZE' => 64]],
                    'UF_CREATED_AT'        => ['datetime', []],
                    'UF_LAST_SEEN_AT'      => ['datetime', []],
                    'UF_DEVICE_CREATED_AT' => ['datetime', []],
                    'UF_FIO'               => ['string', ['SIZE' => 255]],
                    'UF_NICKNAME'          => ['string', ['SIZE' => 100]],
                    'UF_WORKPLACE'         => ['string', ['SIZE' => 255]],
                    'UF_THEME'             => ['string', ['SIZE' => 32]],
                    'UF_BLOCKED'           => ['string', ['SIZE' => 1]],
                    'UF_BLOCKED_AT'        => ['datetime', []],
                    'UF_BLOCK_REASON'      => ['string', ['SIZE' => 255]],
                    'UF_IS_TEST'           => ['string', ['SIZE' => 1]], // ← v18: флаг тестового аккаунта
                    'UF_TZ_OFFSET_MIN'     => ['integer', []],
                    'UF_TOTAL_STEPS'       => ['integer', []],
                    'UF_CONSENT_GRANTED'   => ['string', ['SIZE' => 1]],
                    'UF_CONSENT_DATETIME'  => ['datetime', []],
                    'UF_CONSENT_LOCAL_DT'  => ['string', ['SIZE' => 32]],
                    'UF_HABIT_ID'          => ['string', ['SIZE' => 32]],
                    'UF_HABIT_SELECTED_AT' => ['datetime', []],
                    'UF_REWARD_BALANCE'    => ['integer', []],
                    'UF_RANK'              => ['integer', []],
                    'UF_EFFICIENCY_ALL'    => ['double', []],
                    'UF_EFFICIENCY_DAY'    => ['double', []],
                    'UF_HABITS_DAYS'       => ['integer', []],
                    'UF_EARNED_ALL'        => ['integer', []],
                    'UF_MAX_ALL'           => ['integer', []],
                    'UF_MISSING_ALL'       => ['integer', []],
                    'UF_EARNED_DAY'        => ['integer', []],
                    'UF_MAX_DAY'           => ['integer', []],
                ],
            ],
            'UserStates' => [
                'name'  => 'X5PwaUserStates',
                'table' => 'x5_pwa_user_states',
                'fields' => [
                    'UF_UID'   => ['string', ['SIZE' => 40]],
                    'UF_STATE' => ['string', ['SIZE' => 500, 'ROWS' => 10]],
                ],
            ],
            'LectureReads' => [
                'name'  => 'X5PwaLectureReads',
                'table' => 'x5_pwa_lecture_reads',
                'fields' => [
                    'UF_UID'        => ['string', ['SIZE' => 40]],
                    'UF_LECTURE_ID' => ['string', ['SIZE' => 128]],
                    'UF_READ_AT'    => ['datetime', []],
                ],
            ],
            'TaskCompletions' => [
                'name'  => 'X5PwaTaskCompletions',
                'table' => 'x5_pwa_task_completions',
                'fields' => [
                    'UF_UID'          => ['string', ['SIZE' => 40]],
                    'UF_TASK_ID'      => ['string', ['SIZE' => 128]],
                    'UF_COMPLETED_AT' => ['datetime', []],
                ],
            ],
            'HabitLogs' => [
                'name'  => 'X5PwaHabitLogs',
                'table' => 'x5_pwa_habit_logs',
                'fields' => [
                    'UF_UID'          => ['string', ['SIZE' => 40]],
                    'UF_HABIT_ID'     => ['string', ['SIZE' => 32]],
                    'UF_COMPLETED_AT' => ['datetime', []],
                ],
            ],
            'PushSubscriptions' => [
                'name'  => 'X5PwaPushSubs',
                'table' => 'x5_pwa_push_subs',
                'fields' => [
                    'UF_UID'        => ['string', ['SIZE' => 40]],
                    'UF_ENDPOINT'   => ['string', ['SIZE' => 500, 'ROWS' => 4]],
                    'UF_P256DH'     => ['string', ['SIZE' => 255]],
                    'UF_AUTH'       => ['string', ['SIZE' => 255]],
                    'UF_UPDATED_AT' => ['datetime', []],
                ],
            ],
            'NotificationLog' => [
                'name'  => 'X5PwaNotifLog',
                'table' => 'x5_pwa_notif_log',
                'fields' => [
                    'UF_UID'         => ['string', ['SIZE' => 40]],
                    'UF_HABIT_ID'    => ['string', ['SIZE' => 32]],
                    'UF_NOTIFIED_AT' => ['datetime', []],
                    'UF_TODAY_COUNT' => ['integer', []],
                    'UF_TODAY_DATE'  => ['string', ['SIZE' => 10]],
                ],
            ],
            'PushHistory' => [
                'name'  => 'X5PwaPushHistory',
                'table' => 'x5_pwa_push_history',
                'fields' => [
                    'UF_TYPE'       => ['string', ['SIZE' => 32]],
                    'UF_TARGET'     => ['string', ['SIZE' => 64]],
                    'UF_TITLE'      => ['string', ['SIZE' => 255]],
                    'UF_BODY'       => ['string', ['SIZE' => 1000, 'ROWS' => 4]],
                    'UF_URL'        => ['string', ['SIZE' => 500, 'ROWS' => 2]],
                    'UF_STATUS'     => ['string', ['SIZE' => 32]],
                    'UF_CREATED_AT' => ['datetime', []],
                ],
            ],
            'RateLimits' => [
                'name'  => 'X5PwaRateLimits',
                'table' => 'x5_pwa_rate_limits',
                'fields' => [
                    'UF_KEY'        => ['string', ['SIZE' => 128]],
                    'UF_TIMESTAMPS' => ['string', ['SIZE' => 500, 'ROWS' => 6]],
                ],
            ],
            'QuizAnswers' => [
                'name'  => 'X5PwaQuizAnswers',
                'table' => 'x5_pwa_quiz_answers',
                'fields' => [
                    'UF_QUESTION_CODE'    => ['string', ['SIZE' => 16]],
                    'UF_CODE'             => ['string', ['SIZE' => 16]],
                    'UF_TEXT'             => ['string', ['SIZE' => 500]],
                    'UF_SCORE_ENERGY'     => ['integer', []],
                    'UF_SCORE_ANTISTRESS' => ['integer', []],
                    'UF_SCORE_BODY'       => ['integer', []],
                    'UF_SCORE_BALANCE'    => ['integer', []],
                    'UF_SORT'             => ['integer', []],
                ],
            ],
            'Reflections' => [
                'name'  => 'X5PwaReflections',
                'table' => 'x5_pwa_reflections',
                'fields' => [
                    'UF_UID'         => ['string', ['SIZE' => 40]],
                    'UF_TEXT'        => ['string', ['SIZE' => 5000, 'ROWS' => 10]],
                    'UF_LOCAL_DATE'  => ['string', ['SIZE' => 10]],
                    'UF_SENT_AT'     => ['datetime', []],
                    'UF_APPROVED'    => ['string', ['SIZE' => 1]],
                    'UF_APPROVED_AT' => ['datetime', []],
                    'UF_APPROVED_BY' => ['integer', []],
                ],
            ],
            'Steps' => [
                'name'  => 'X5PwaSteps',
                'table' => 'x5_pwa_steps',
                'fields' => [
                    'UF_UID'            => ['string', ['SIZE' => 40]],
                    'UF_STEPS'          => ['integer', []],
                    'UF_LOCAL_DATE'     => ['string', ['SIZE' => 10]],
                    'UF_SENT_AT'        => ['datetime', []],
                    'UF_IMAGES'         => ['string', ['SIZE' => 1000]],
                    'UF_CONSENT'        => ['string', ['SIZE' => 1]],
                    'UF_STATUS'         => ['string', ['SIZE' => 1]],
                    'UF_MODERATED_AT'   => ['datetime', []],
                    'UF_MODERATED_BY'   => ['integer', []],
                    'UF_REJECT_REASON'  => ['string', ['SIZE' => 255]],
                ],
            ],
            'ConsentLog' => [
                'name'  => 'X5PwaConsentLog',
                'table' => 'x5_pwa_consent_log',
                'fields' => [
                    'UF_UID'       => ['string', ['SIZE' => 40]],
                    'UF_GRANTED'   => ['string', ['SIZE' => 1]],
                    'UF_LOCAL_DT'  => ['string', ['SIZE' => 32]],
                    'UF_SERVER_AT' => ['datetime', []],
                    'UF_IP'        => ['string', ['SIZE' => 45]],
                    'UF_SOURCE'    => ['string', ['SIZE' => 32]],
                ],
            ],
            'SupportTickets' => [
                'name'  => 'X5PwaSupportTickets',
                'table' => 'x5_pwa_support_tickets',
                'fields' => [
                    'UF_UID'            => ['string', ['SIZE' => 40]],
                    'UF_DEVICE_ID'      => ['string', ['SIZE' => 64]],
                    'UF_SOURCE'         => ['string', ['SIZE' => 16]],
                    'UF_AUTH_ERROR'     => ['string', ['SIZE' => 32]],
                    'UF_META'           => ['string', ['SIZE' => 2000, 'ROWS' => 4]],
                    'UF_SUBJECT'        => ['string', ['SIZE' => 255]],
                    'UF_STATUS'         => ['string', ['SIZE' => 16]],
                    'UF_CREATED_AT'     => ['datetime', []],
                    'UF_UPDATED_AT'     => ['datetime', []],
                    'UF_CLOSED_AT'      => ['datetime', []],
                    'UF_CLOSED_BY'      => ['integer', []],
                    'UF_MESSAGES_COUNT' => ['integer', []],
                    'UF_IP'             => ['string', ['SIZE' => 45]],
                    'UF_UA'             => ['string', ['SIZE' => 255]],
                ],
            ],
            'SupportMessages' => [
                'name'  => 'X5PwaSupportMessages',
                'table' => 'x5_pwa_support_messages',
                'fields' => [
                    'UF_TICKET_ID'  => ['integer', []],
                    'UF_UID'        => ['string', ['SIZE' => 40]],
                    'UF_IS_ADMIN'   => ['string', ['SIZE' => 1]],
                    'UF_ADMIN_ID'   => ['integer', []],
                    'UF_TEXT'       => ['string', ['SIZE' => 5000, 'ROWS' => 10]],
                    'UF_IMAGES'     => ['string', ['SIZE' => 2000, 'ROWS' => 4]],
                    'UF_CREATED_AT' => ['datetime', []],
                ],
            ],
            'EventActions' => [
                'name'  => 'X5PwaEventActions',
                'table' => 'x5_pwa_event_actions',
                'fields' => [
                    'UF_UID'            => ['string', ['SIZE' => 40]],
                    'UF_EVENT_ID'       => ['string', ['SIZE' => 128]],
                    'UF_EVENT_DATE'     => ['string', ['SIZE' => 10]],
                    'UF_EVENT_TITLE'    => ['string', ['SIZE' => 255]],
                    'UF_REWARD_TEXT'    => ['string', ['SIZE' => 64]],
                    'UF_REWARD_POINTS'  => ['integer', []],
                    'UF_CLICKED_AT'     => ['datetime', []],
                    'UF_CONFIRMED_AT'   => ['datetime', []],
                    'UF_REWARDED'       => ['string', ['SIZE' => 1]],
                ],
            ],
            'RewardLog' => [
                'name'  => 'X5PwaRewardLog',
                'table' => 'x5_pwa_reward_log',
                'fields' => [
                    'UF_UID'         => ['string', ['SIZE' => 40]],
                    'UF_ACTION_TYPE' => ['string', ['SIZE' => 32]],
                    'UF_ACTION_ID'   => ['string', ['SIZE' => 128]],
                    'UF_UNIQUE_KEY'  => ['string', ['SIZE' => 32]],
                    'UF_POINTS'      => ['integer', []],
                    'UF_TITLE'       => ['string', ['SIZE' => 255]],
                    'UF_LOCAL_DATE'  => ['string', ['SIZE' => 10]],
                    'UF_CREATED_AT'  => ['datetime', []],
                ],
            ],
            'DailyApples' => [
                'name'  => 'X5PwaDailyApples',
                'table' => 'x5_pwa_daily_apples',
                'fields' => [
                    'UF_DATE'          => ['string', ['SIZE' => 10]],
                    'UF_THEME_CODE'    => ['string', ['SIZE' => 32]],
                    'UF_APPLES'        => ['integer', []],
                    'UF_CALCULATED_AT' => ['datetime', []],
                ],
            ],
        ];
    }

    public static function install(): void
    {
        foreach (self::config() as $cfg) {
            $hlId = self::ensureHlBlock($cfg['name'], $cfg['table']);
            foreach ($cfg['fields'] as $fieldName => $spec) {
                [$type, $settings] = $spec;
                self::ensureUserField($hlId, $fieldName, $type, $settings);
            }
        }
        self::ensureColumnTypes();
        self::ensureIndexes();
        self::dropLegacyDailyMax();
    }

    public static function uninstall(): void
    {
        foreach (self::config() as $cfg) {
            $hl = HighloadBlockTable::getList([
                'filter' => ['=NAME' => $cfg['name']],
                'limit'  => 1,
            ])->fetch();
            if (!$hl) continue;

            $entityId = 'HLBLOCK_' . $hl['ID'];

            $uf = new \CUserTypeEntity();
            $fields = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
            while ($f = $fields->Fetch()) {
                try {
                    $uf->Delete($f['ID']);
                } catch (\Throwable $e) {}
            }

            try {
                HighloadBlockTable::delete($hl['ID']);
            } catch (\Throwable $e) {}
        }
    }

    private static function dropLegacyDailyMax(): void
    {
        try {
            $hl = HighloadBlockTable::getList([
                'filter' => ['=NAME' => 'X5PwaDailyMax'],
                'limit'  => 1,
            ])->fetch();
            if (!$hl) return;

            $hlId     = (int)$hl['ID'];
            $entityId = 'HLBLOCK_' . $hlId;

            $uf = new \CUserTypeEntity();
            $fields = \CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
            while ($f = $fields->Fetch()) {
                try { $uf->Delete($f['ID']); } catch (\Throwable $e) {}
            }

            HighloadBlockTable::delete($hlId);

            \Bitrix\Main\Diag\Debug::writeToFile(
                'Dropped legacy X5PwaDailyMax (ID=' . $hlId . ')',
                'HlSetup::dropLegacyDailyMax',
                '/x5.pwa.log'
            );
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'dropLegacyDailyMax failed: ' . $e->getMessage(),
                'HlSetup::dropLegacyDailyMax',
                '/x5.pwa.log'
            );
        }
    }

    public static function ensureColumnTypes(): array
    {
        $changes = [];
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        foreach (self::config() as $cfg) {
            $table = $cfg['table'];
            $exists = $conn->query(
                "SHOW TABLES LIKE '" . $sqlHelper->forSql($table) . "'"
            )->fetch();
            if (!$exists) continue;

            foreach ($cfg['fields'] as $fieldName => $spec) {
                [$type, $settings] = $spec;
                if ($type !== 'string') continue;
                $size = (int)($settings['SIZE'] ?? 0);
                if ($size <= 0 || $size > 255) continue;

                $col = $conn->query(
                    "SHOW COLUMNS FROM `" . $sqlHelper->forSql($table)
                    . "` LIKE '" . $sqlHelper->forSql($fieldName) . "'"
                )->fetch();
                if (!$col) continue;

                $currentType = strtolower((string)$col['Type']);
                if (strpos($currentType, 'varchar') === 0) continue;

                $maxRow = $conn->query(
                    "SELECT MAX(CHAR_LENGTH(`" . $sqlHelper->forSql($fieldName)
                    . "`)) AS MAXLEN FROM `" . $sqlHelper->forSql($table) . "`"
                )->fetch();
                $maxLen = (int)($maxRow['MAXLEN'] ?? 0);
                if ($maxLen > $size) {
                    $changes[] = "SKIP {$table}.{$fieldName} — max length {$maxLen} > {$size}";
                    continue;
                }

                try {
                    $conn->queryExecute(
                        "ALTER TABLE `" . $sqlHelper->forSql($table) . "` "
                        . "MODIFY `" . $sqlHelper->forSql($fieldName) . "` VARCHAR({$size})"
                    );
                    $changes[] = "OK   {$table}.{$fieldName} → VARCHAR({$size})";
                } catch (\Throwable $e) {
                    $changes[] = "FAIL {$table}.{$fieldName} — " . $e->getMessage();
                }
            }
        }
        return $changes;
    }

    public static function ensureIndexes(): array
    {
        $changes = [];
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $indexes = [
            'x5_pwa_uid_bindings' => [
                'idx_x5pwa_bindings_total'       => 'UF_TOTAL_STEPS',
                'idx_x5pwa_bindings_workpl'      => 'UF_WORKPLACE',
                'idx_x5pwa_bindings_habit'       => 'UF_HABIT_ID',
                'idx_x5pwa_bindings_consent'     => 'UF_CONSENT_GRANTED',
                'idx_x5pwa_bindings_balance'     => 'UF_REWARD_BALANCE',
                'idx_x5pwa_bindings_rank'        => 'UF_RANK',
                'idx_x5pwa_bindings_eff_all'     => 'UF_EFFICIENCY_ALL',
                'idx_x5pwa_bindings_eff_day'     => 'UF_EFFICIENCY_DAY',
                'idx_x5pwa_bindings_comp_eff'    => 'UF_WORKPLACE, UF_EFFICIENCY_ALL',
                'idx_x5pwa_bindings_last_seen'   => 'UF_LAST_SEEN_AT',
                'idx_x5pwa_bindings_earned'      => 'UF_EARNED_ALL',
                'idx_x5pwa_bindings_missing'     => 'UF_MISSING_ALL',
                'idx_x5pwa_bindings_is_test'     => 'UF_IS_TEST',
            ],
            'x5_pwa_steps' => [
                'idx_x5pwa_steps_uid_status'  => 'UF_UID, UF_STATUS',
                'idx_x5pwa_steps_status_date' => 'UF_STATUS, UF_LOCAL_DATE',
                'idx_x5pwa_steps_date'        => 'UF_LOCAL_DATE',
            ],
            'x5_pwa_consent_log' => [
                'idx_x5pwa_consent_uid'    => 'UF_UID',
                'idx_x5pwa_consent_uid_at' => 'UF_UID, UF_SERVER_AT',
            ],
            'x5_pwa_support_tickets' => [
                'idx_x5pwa_support_uid'     => 'UF_UID',
                'idx_x5pwa_support_device'  => 'UF_DEVICE_ID',
                'idx_x5pwa_support_source'  => 'UF_SOURCE',
                'idx_x5pwa_support_status'  => 'UF_STATUS',
                'idx_x5pwa_support_created' => 'UF_CREATED_AT',
                'idx_x5pwa_support_updated' => 'UF_UPDATED_AT',
                'idx_x5pwa_support_uid_upd' => 'UF_UID, UF_UPDATED_AT',
                'idx_x5pwa_support_dev_upd' => 'UF_DEVICE_ID, UF_UPDATED_AT',
            ],
            'x5_pwa_support_messages' => [
                'idx_x5pwa_support_msg_ticket'  => 'UF_TICKET_ID',
                'idx_x5pwa_support_msg_created' => 'UF_CREATED_AT',
                'idx_x5pwa_support_msg_t_c'     => 'UF_TICKET_ID, UF_CREATED_AT',
            ],
            'x5_pwa_event_actions' => [
                'idx_x5pwa_events_uid'           => 'UF_UID',
                'idx_x5pwa_events_event'         => 'UF_EVENT_ID',
                'idx_x5pwa_events_date'          => 'UF_EVENT_DATE',
                'idx_x5pwa_events_uid_event'     => 'UF_UID, UF_EVENT_ID',
                'idx_x5pwa_events_uid_clicked'   => 'UF_UID, UF_CLICKED_AT',
                'idx_x5pwa_events_uid_confirmed' => 'UF_UID, UF_CONFIRMED_AT',
            ],
            'x5_pwa_reward_log' => [
                'uniq_x5pwa_reward_key'    => 'unique:UF_UNIQUE_KEY',
                'idx_x5pwa_reward_uid'     => 'UF_UID',
                'idx_x5pwa_reward_uid_at'  => 'UF_UID, UF_CREATED_AT',
                'idx_x5pwa_reward_uid_t'   => 'UF_UID, UF_ACTION_TYPE',
                'idx_x5pwa_reward_uid_d'   => 'UF_UID, UF_LOCAL_DATE',
                'idx_x5pwa_reward_date'    => 'UF_LOCAL_DATE',
            ],
            'x5_pwa_daily_apples' => [
                'uniq_x5pwa_dailyapples_dt' => 'unique:UF_DATE, UF_THEME_CODE',
                'idx_x5pwa_dailyapples_date' => 'UF_DATE',
            ],
        ];

        foreach ($indexes as $table => $list) {
            $tableSafe = $sqlHelper->forSql($table);
            $exists = $conn->query("SHOW TABLES LIKE '{$tableSafe}'")->fetch();
            if (!$exists) continue;

            foreach ($list as $indexName => $columns) {
                $indexSafe = $sqlHelper->forSql($indexName);
                $existing = $conn->query(
                    "SHOW INDEX FROM `{$tableSafe}` WHERE Key_name = '{$indexSafe}'"
                )->fetch();
                if ($existing) continue;

                $isUnique = false;
                if (strpos($columns, 'unique:') === 0) {
                    $isUnique = true;
                    $columns  = substr($columns, 7);
                }

                try {
                    $conn->queryExecute(
                        ($isUnique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ')
                        . "`{$indexSafe}` ON `{$tableSafe}` ({$columns})"
                    );
                    $changes[] = "OK   {$table}.{$indexName}" . ($isUnique ? ' (unique)' : '');
                } catch (\Throwable $e) {
                    $changes[] = "FAIL {$table}.{$indexName} — " . $e->getMessage();
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        "Index create failed: {$table}.{$indexName} — " . $e->getMessage(),
                        'HlSetup::ensureIndexes',
                        '/x5.pwa.log'
                    );
                }
            }
        }
        return $changes;
    }

    private static function ensureHlBlock(string $name, string $table): int
    {
        $existing = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $name],
            'limit'  => 1,
        ])->fetch();
        if ($existing) return (int)$existing['ID'];

        $res = HighloadBlockTable::add(['NAME' => $name, 'TABLE_NAME' => $table]);
        if (!$res->isSuccess()) {
            throw new \RuntimeException('HL add: ' . implode('; ', $res->getErrorMessages()));
        }
        return (int)$res->getId();
    }

    private static function ensureUserField(int $hlId, string $fieldName, string $type, array $settings): void
    {
        $entityId = 'HLBLOCK_' . $hlId;
        $existing = \CUserTypeEntity::GetList([], [
            'ENTITY_ID'  => $entityId,
            'FIELD_NAME' => $fieldName,
        ])->Fetch();
        if ($existing) return;

        $uf = new \CUserTypeEntity();
        $uf->Add([
            'ENTITY_ID'         => $entityId,
            'FIELD_NAME'        => $fieldName,
            'USER_TYPE_ID'      => $type,
            'MANDATORY'         => 'N',
            'SHOW_FILTER'       => 'Y',
            'SHOW_IN_LIST'      => 'Y',
            'EDIT_IN_LIST'      => 'Y',
            'SETTINGS'          => $settings,
            'EDIT_FORM_LABEL'   => ['ru' => $fieldName],
            'LIST_COLUMN_LABEL' => ['ru' => $fieldName],
            'LIST_FILTER_LABEL' => ['ru' => $fieldName],
        ]);
    }
}