<?php
namespace X5\Pwa;

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

Loader::includeModule('highloadblock');

class Storage
{
    public const DEFAULT_TZ_OFFSET_MIN = 180;

    private static array $dataClasses = [];

    public static function dataClass(string $hlName): string
    {
        if (isset(self::$dataClasses[$hlName])) return self::$dataClasses[$hlName];

        $hl = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $hlName],
            'limit'  => 1,
        ])->fetch();
        if (!$hl) throw new \RuntimeException("HL '{$hlName}' not found");

        $entity = HighloadBlockTable::compileEntity($hl);
        return self::$dataClasses[$hlName] = $entity->getDataClass();
    }

    public static function now(): DateTime { return new DateTime(); }

    // ───────── Таймзона ─────────

    public static function parseTzOffsetFromArray(array $body): ?int
    {
        if (isset($body['TZ_OFFSET_MIN']) && is_numeric($body['TZ_OFFSET_MIN'])) {
            $v = (int)$body['TZ_OFFSET_MIN'];
            if ($v >= -720 && $v <= 840) return $v;
        }

        if (!empty($body['LOCAL_DATETIME']) && is_string($body['LOCAL_DATETIME'])) {
            $iso = trim($body['LOCAL_DATETIME']);

            if (preg_match('/([+-])(\d{2}):?(\d{2})$/', $iso, $m)) {
                $sign = $m[1] === '-' ? -1 : 1;
                $v = $sign * ((int)$m[2] * 60 + (int)$m[3]);
                if ($v >= -720 && $v <= 840) return $v;
            }
            if (preg_match('/Z$/', $iso)) return 0;
        }

        return null;
    }

    public static function getUserTzOffsetMin(string $uid): int
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['UF_TZ_OFFSET_MIN'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();

        if (!$row) return self::DEFAULT_TZ_OFFSET_MIN;

        $val = $row['UF_TZ_OFFSET_MIN'];
        if ($val === null || $val === '') return self::DEFAULT_TZ_OFFSET_MIN;

        $int = (int)$val;
        if ($int < -720 || $int > 840) return self::DEFAULT_TZ_OFFSET_MIN;

        return $int;
    }

    public static function saveUserTzOffsetMin(string $uid, int $offsetMin): void
    {
        if ($offsetMin < -720 || $offsetMin > 840) return;

        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['ID', 'UF_TZ_OFFSET_MIN'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return;

        $old = $row['UF_TZ_OFFSET_MIN'];
        $oldInt = ($old === null || $old === '') ? null : (int)$old;
        if ($oldInt === $offsetMin) return;

        $dc::update((int)$row['ID'], ['UF_TZ_OFFSET_MIN' => $offsetMin]);

        \Bitrix\Main\Diag\Debug::writeToFile(
            'uid=' . substr($uid, 0, 12) . '… tz_offset_min: ' . var_export($oldInt, true) . ' → ' . $offsetMin,
            'Storage::saveUserTzOffsetMin',
            '/x5.pwa.log'
        );
    }

    // ───────── UID bindings ─────────

    public static function lookupBinding(string $uid, string $deviceId): array
    {
        $dc = self::dataClass('X5PwaUidBindings');

        $uidBinding = $dc::getList([
            'filter' => ['=UF_UID' => $uid], 'limit' => 1,
        ])->fetch();

        $deviceBoundUid = $dc::getList([
            'filter' => ['=UF_DEVICE_ID' => $deviceId], 'limit' => 1,
        ])->fetch();

        return [
            'binding'        => $uidBinding ?: null,
            'deviceBoundUid' => $deviceBoundUid ? $deviceBoundUid['UF_UID'] : null,
        ];
    }

    public static function saveBinding(string $uid, string $deviceId, int $deviceCreatedAt = 0): void
    {
        $dc = self::dataClass('X5PwaUidBindings');
        $dc::add([
            'UF_UID'               => $uid,
            'UF_DEVICE_ID'         => $deviceId,
            'UF_CREATED_AT'        => self::now(),
            'UF_LAST_SEEN_AT'      => self::now(),
            'UF_DEVICE_CREATED_AT' => $deviceCreatedAt
                ? DateTime::createFromTimestamp((int)($deviceCreatedAt / 1000))
                : self::now(),
            'UF_BLOCKED'           => 'N',
            'UF_TZ_OFFSET_MIN'     => self::DEFAULT_TZ_OFFSET_MIN,
        ]);
    }

    public static function touchBinding(int $id): void
    {
        $dc = self::dataClass('X5PwaUidBindings');
        $dc::update($id, ['UF_LAST_SEEN_AT' => self::now()]);
    }

    public static function touchBindingByUid(string $uid): void
    {
        if ($uid === '') return;
        $dc = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['ID'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return;
        $dc::update((int)$row['ID'], ['UF_LAST_SEEN_AT' => self::now()]);
    }

    public static function blockUser(string $uid, string $reason = ''): bool
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return false;

        $res = $dc::update((int)$row['ID'], [
            'UF_BLOCKED'      => 'Y',
            'UF_BLOCKED_AT'   => self::now(),
            'UF_BLOCK_REASON' => mb_substr($reason, 0, 255),
        ]);

        self::deleteSubscription($uid);

        \Bitrix\Main\Diag\Debug::writeToFile(
            'block uid=' . substr($uid, 0, 12) . '… reason="' . $reason . '"',
            'Storage::blockUser',
            '/x5.pwa.log'
        );

        return $res->isSuccess();
    }

    public static function unblockUser(string $uid): bool
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return false;

        $res = $dc::update((int)$row['ID'], [
            'UF_BLOCKED'      => 'N',
            'UF_BLOCKED_AT'   => null,
            'UF_BLOCK_REASON' => '',
        ]);

        \Bitrix\Main\Diag\Debug::writeToFile(
            'unblock uid=' . substr($uid, 0, 12) . '…',
            'Storage::unblockUser',
            '/x5.pwa.log'
        );

        return $res->isSuccess();
    }

    public static function isBlocked(string $uid): bool
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['UF_BLOCKED'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return false;
        return ($row['UF_BLOCKED'] ?? 'N') === 'Y';
    }

    public static function deleteUser(string $uid): array
    {
        $deleted = [];

        // 1. Файлы шагомера
        try {
            $dc = self::dataClass('X5PwaSteps');
            $rows = $dc::getList(['select' => ['UF_IMAGES'], 'filter' => ['=UF_UID' => $uid]])->fetchAll();
            $cnt = 0;
            foreach ($rows as $r) {
                $ids = json_decode((string)$r['UF_IMAGES'], true) ?: [];
                foreach ($ids as $fid) {
                    $fid = (int)$fid;
                    if ($fid > 0 && \CFile::Delete($fid)) $cnt++;
                }
            }
            $deleted['X5PwaSteps_files'] = $cnt;
        } catch (\Throwable $e) {
            $deleted['X5PwaSteps_files'] = 'ERR: ' . $e->getMessage();
        }

        // 2. Тикеты поддержки + сообщения + файлы
        try {
            $ticketsDc = self::dataClass('X5PwaSupportTickets');
            $tickets = $ticketsDc::getList([
                'select' => ['ID'],
                'filter' => ['=UF_UID' => $uid],
            ])->fetchAll();
            $ticketIds = array_map('intval', array_column($tickets, 'ID'));

            $msgDeleted = 0;
            $msgFilesDeleted = 0;
            if ($ticketIds) {
                $msgDc = self::dataClass('X5PwaSupportMessages');
                $msgs = $msgDc::getList([
                    'select' => ['ID', 'UF_IMAGES'],
                    'filter' => ['=UF_TICKET_ID' => $ticketIds],
                ])->fetchAll();
                foreach ($msgs as $m) {
                    $ids = json_decode((string)$m['UF_IMAGES'], true) ?: [];
                    foreach ($ids as $fid) {
                        $fid = (int)$fid;
                        if ($fid > 0 && \CFile::Delete($fid)) $msgFilesDeleted++;
                    }
                    $msgDc::delete((int)$m['ID']);
                    $msgDeleted++;
                }
            }
            $deleted['X5PwaSupportMessages'] = $msgDeleted;
            $deleted['X5PwaSupportMessages_files'] = $msgFilesDeleted;
        } catch (\Throwable $e) {
            $deleted['X5PwaSupportMessages'] = 'ERR: ' . $e->getMessage();
        }

        // 3. Остальные таблицы (включая RewardLog)
        $sources = [
            'X5PwaLectureReads'    => 'UF_UID',
            'X5PwaTaskCompletions' => 'UF_UID',
            'X5PwaHabitLogs'       => 'UF_UID',
            'X5PwaNotifLog'        => 'UF_UID',
            'X5PwaPushSubs'        => 'UF_UID',
            'X5PwaUserStates'      => 'UF_UID',
            'X5PwaReflections'     => 'UF_UID',
            'X5PwaSteps'           => 'UF_UID',
            'X5PwaConsentLog'      => 'UF_UID',
            'X5PwaSupportTickets'  => 'UF_UID',
            'X5PwaEventActions'    => 'UF_UID',
            'X5PwaRewardLog'       => 'UF_UID',
            'X5PwaUidBindings'     => 'UF_UID',
        ];

        foreach ($sources as $hlName => $field) {
            try {
                $dc = self::dataClass($hlName);
                $rows = $dc::getList(['filter' => ['=' . $field => $uid]])->fetchAll();
                $cnt = 0;
                foreach ($rows as $r) {
                    $dc::delete((int)$r['ID']);
                    $cnt++;
                }
                $deleted[$hlName] = $cnt;
            } catch (\Throwable $e) {
                $deleted[$hlName] = 'ERR: ' . $e->getMessage();
            }
        }

        \Bitrix\Main\Diag\Debug::writeToFile(
            'delete uid=' . substr($uid, 0, 12) . '… deleted='
                . json_encode($deleted, JSON_UNESCAPED_UNICODE),
            'Storage::deleteUser',
            '/x5.pwa.log'
        );

        return $deleted;
    }

    public static function deleteUsers(array $uids): array
    {
        $summary = [];
        foreach ($uids as $uid) {
            $summary[$uid] = self::deleteUser((string)$uid);
        }
        return $summary;
    }

    public static function getLastActivityForUids(array $uids): array
    {
        if (!$uids) return [];

        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $safeUids = array_map([$sqlHelper, 'forSql'], $uids);
        $inList   = "'" . implode("','", $safeUids) . "'";

        $out = [];

        $sources = [
            ['x5_pwa_lecture_reads',    'UF_READ_AT'],
            ['x5_pwa_task_completions', 'UF_COMPLETED_AT'],
            ['x5_pwa_habit_logs',       'UF_COMPLETED_AT'],
            ['x5_pwa_event_actions',    'UF_CLICKED_AT'],
        ];

        foreach ($sources as [$table, $col]) {
            $tableSafe = $sqlHelper->forSql($table);
            $colSafe   = $sqlHelper->forSql($col);

            $sql = "SELECT UF_UID, MAX(`{$colSafe}`) AS MAXDT
                    FROM `{$tableSafe}`
                    WHERE UF_UID IN ({$inList})
                    GROUP BY UF_UID";

            try {
                $res = $conn->query($sql);
                while ($row = $res->fetch()) {
                    $uid = (string)$row['UF_UID'];
                    $dt  = (string)$row['MAXDT'];
                    if ($dt === '' || $dt === '0000-00-00 00:00:00') continue;

                    $ts = strtotime($dt);
                    if ($ts <= 0) continue;
                    $ms = $ts * 1000;

                    if (!isset($out[$uid]) || $ms > $out[$uid]) {
                        $out[$uid] = $ms;
                    }
                }
            } catch (\Throwable $e) {
                // Таблица может отсутствовать при первом хите
            }
        }

        return $out;
    }

    public static function listUidBindings(array $params = []): array
    {
        $dc = self::dataClass('X5PwaUidBindings');

        $filter = [];

        if (!empty($params['search'])) {
            $s = '%' . $params['search'] . '%';
            $filter[] = [
                'LOGIC'         => 'OR',
                'UF_UID'        => $s,
                'UF_FIO'        => $s,
                'UF_NICKNAME'   => $s,
                'UF_WORKPLACE'  => $s,
            ];
        }

        if (!empty($params['theme'])) {
            $filter['=UF_THEME'] = (string)$params['theme'];
        }

        $blocked = (string)($params['blocked'] ?? '');
        if ($blocked === 'Y') {
            $filter['=UF_BLOCKED'] = 'Y';
        } elseif ($blocked === 'N') {
            $filter['!=UF_BLOCKED'] = 'Y';
        }

        if (!empty($params['active_from'])) {
            try {
                $filter['>=UF_LAST_SEEN_AT'] = new DateTime((string)$params['active_from']);
            } catch (\Throwable $e) { /* ignore */ }
        }
        if (!empty($params['active_to'])) {
            try {
                $filter['<=UF_LAST_SEEN_AT'] = new DateTime((string)$params['active_to']);
            } catch (\Throwable $e) { /* ignore */ }
        }

        if (empty($params['active_from']) && empty($params['active_to']) && !empty($params['activeDays'])) {
            $days = max(1, (int)$params['activeDays']);
            $from = new DateTime();
            $from->add('-' . $days . ' days');
            $filter['>=UF_LAST_SEEN_AT'] = $from;
        }

        $sortMap = [
            'uid'       => 'UF_UID',
            'UID'       => 'UF_UID',
            'fio'       => 'UF_FIO',
            'FIO'       => 'UF_FIO',
            'nickname'  => 'UF_NICKNAME',
            'NICKNAME'  => 'UF_NICKNAME',
            'workplace' => 'UF_WORKPLACE',
            'WORKPLACE' => 'UF_WORKPLACE',
            'theme'     => 'UF_THEME',
            'THEME'     => 'UF_THEME',
            'created'   => 'UF_CREATED_AT',
            'CREATED_AT'=> 'UF_CREATED_AT',
            'last_seen' => 'UF_LAST_SEEN_AT',
            'LAST_SEEN_AT' => 'UF_LAST_SEEN_AT',
        ];
        $sortKey   = (string)($params['sort'] ?? 'LAST_SEEN_AT');
        $sortField = $sortMap[$sortKey] ?? 'UF_LAST_SEEN_AT';
        $sortDir   = strtoupper((string)($params['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $order     = [$sortField => $sortDir, 'ID' => 'DESC'];

        $noPaging = !empty($params['noPaging']) || (int)($params['perPage'] ?? -1) === 0;
        $page     = max(1, (int)($params['page'] ?? 1));
        $perPage  = $noPaging ? 0 : max(1, min(200, (int)($params['perPage'] ?? 50)));
        $offset   = $noPaging ? 0 : ($page - 1) * $perPage;

        $totalRow = $dc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        $total = (int)($totalRow['CNT'] ?? 0);

        $queryParams = ['filter' => $filter, 'order' => $order];
        if (!$noPaging) {
            $queryParams['limit']  = $perPage;
            $queryParams['offset'] = $offset;
        }
        $rows = $dc::getList($queryParams)->fetchAll();

        if (in_array($params['withPush'] ?? '', ['Y', 'N'], true)) {
            $uids     = array_column($rows, 'UF_UID');
            $withSubs = $uids ? self::getSubscriptionsForUids($uids) : [];
            $want     = $params['withPush'] === 'Y';

            $rows = array_values(array_filter($rows, function ($row) use ($withSubs, $want) {
                $has = !empty($withSubs[$row['UF_UID']]);
                return $has === $want;
            }));
        }

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $noPaging ? 1 : ($total > 0 ? (int)ceil($total / $perPage) : 1),
        ];
    }

    public static function getUidBinding(string $uid): ?array
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    public static function getStatsForUids(array $uids): array
    {
        if (!$uids) return [];

        $out = [];
        foreach ($uids as $u) {
            $out[$u] = ['lectures' => 0, 'tasks' => 0, 'habits' => 0];
        }

        $countByUid = function (string $hlName) use ($uids): array {
            $dc = self::dataClass($hlName);
            $rows = $dc::getList([
                'select'  => ['UF_UID', 'CNT'],
                'filter'  => ['=UF_UID' => $uids],
                'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
                'group'   => ['UF_UID'],
            ])->fetchAll();
            $r = [];
            foreach ($rows as $row) $r[$row['UF_UID']] = (int)$row['CNT'];
            return $r;
        };

        foreach ($countByUid('X5PwaLectureReads') as $uid => $n) {
            if (isset($out[$uid])) $out[$uid]['lectures'] = $n;
        }
        foreach ($countByUid('X5PwaTaskCompletions') as $uid => $n) {
            if (isset($out[$uid])) $out[$uid]['tasks'] = $n;
        }
        foreach ($countByUid('X5PwaHabitLogs') as $uid => $n) {
            if (isset($out[$uid])) $out[$uid]['habits'] = $n;
        }

        return $out;
    }

    public static function getSubscriptionsForUids(array $uids): array
    {
        if (!$uids) return [];
        $dc = self::dataClass('X5PwaPushSubs');
        $rows = $dc::getList([
            'select' => ['UF_UID'],
            'filter' => ['=UF_UID' => $uids],
        ])->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['UF_UID']] = true;
        return $out;
    }

    // ───────── States ─────────

    public static function getState(string $uid): ?array
    {
        $dc = self::dataClass('X5PwaUserStates');
        $row = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        if (!$row) return null;
        $s = $row['UF_STATE'] ?? null;
        return $s ? (json_decode($s, true) ?: null) : null;
    }

    public static function saveState(string $uid, $state): void
    {
        if (is_array($state)) {
            unset($state['CONSENT'], $state['HABIT_SELECTED'], $state['REWARDS']);
        }

        $dc = self::dataClass('X5PwaUserStates');
        $existing = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        $value = $state === null ? '' : json_encode($state, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $dc::update($existing['ID'], ['UF_STATE' => $value]);
        } else {
            $dc::add(['UF_UID' => $uid, 'UF_STATE' => $value]);
        }

        self::syncBindingProfile($uid, $state);
    }

    public static function syncBindingProfile(string $uid, $state): void
    {
        if (!is_array($state)) return;

        $profile = self::extractProfile($state);

        if ($profile['FIO'] === '' && $profile['NICKNAME'] === ''
            && $profile['WORKPLACE'] === '' && $profile['THEME'] === '') {
            return;
        }

        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        if (!$row) return;

        $update = [];
        if ($profile['FIO'] !== '')       $update['UF_FIO']       = $profile['FIO'];
        if ($profile['NICKNAME'] !== '')  $update['UF_NICKNAME']  = $profile['NICKNAME'];
        if ($profile['WORKPLACE'] !== '') $update['UF_WORKPLACE'] = $profile['WORKPLACE'];
        if ($profile['THEME'] !== '')     $update['UF_THEME']     = $profile['THEME'];

        if ($update) $dc::update((int)$row['ID'], $update);
    }

    public static function extractProfile(array $state): array
    {
        $containers = [];
        foreach (['PROFILE', 'profile', 'Profile', 'user', 'USER', 'user_profile'] as $key) {
            if (isset($state[$key]) && is_array($state[$key])) {
                $containers[] = $state[$key];
            }
        }
        $containers[] = $state;

        $fio       = '';
        $nickname  = '';
        $workplace = '';

        foreach ($containers as $c) {
            if ($fio === '') {
                foreach (['FIO','fio','Fio','fullname','full_name','name','NAME'] as $k) {
                    if (isset($c[$k]) && is_string($c[$k]) && trim($c[$k]) !== '') {
                        $fio = trim($c[$k]);
                        break;
                    }
                }
            }
            if ($nickname === '') {
                foreach (['NICKNAME','nickname','Nickname','nick','NICK'] as $k) {
                    if (isset($c[$k]) && is_string($c[$k]) && trim($c[$k]) !== '') {
                        $nickname = trim($c[$k]);
                        break;
                    }
                }
            }
            if ($workplace === '') {
                foreach (['WORKPLACE','workplace','Workplace','job','company','place'] as $k) {
                    if (isset($c[$k]) && is_string($c[$k]) && trim($c[$k]) !== '') {
                        $workplace = trim($c[$k]);
                        break;
                    }
                }
            }
        }

        $theme = '';

        foreach (['THEME', 'theme'] as $themeKey) {
            if ($theme !== '') break;
            if (!isset($state[$themeKey])) continue;
            $t = $state[$themeKey];

            if (is_array($t)) {
                foreach (['SELECTED','selected','id','ID','value'] as $k) {
                    if (isset($t[$k]) && is_string($t[$k]) && trim($t[$k]) !== '') {
                        $theme = trim($t[$k]);
                        break;
                    }
                }
            } elseif (is_string($t) && trim($t) !== '') {
                $theme = trim($t);
            }
        }

        if ($theme === '' && isset($state['QUIZ']) && is_array($state['QUIZ'])) {
            foreach (['RECOMMENDED','recommended','recommendation'] as $k) {
                if (isset($state['QUIZ'][$k]) && is_string($state['QUIZ'][$k]) && trim($state['QUIZ'][$k]) !== '') {
                    $theme = trim($state['QUIZ'][$k]);
                    break;
                }
            }
        }

        if ($theme === '') {
            foreach (['theme','direction','directionId'] as $k) {
                if (isset($state[$k]) && is_string($state[$k]) && trim($state[$k]) !== '') {
                    $theme = trim($state[$k]);
                    break;
                }
            }
        }

        return [
            'FIO'       => $fio,
            'NICKNAME'  => $nickname,
            'WORKPLACE' => $workplace,
            'THEME'     => $theme,
        ];
    }

    // ───────── Reads / Tasks / Habits ─────────

    public static function getReads(string $uid): array
    {
        $dc = self::dataClass('X5PwaLectureReads');
        return array_column(
            $dc::getList([
                'select' => ['UF_LECTURE_ID'],
                'filter' => ['=UF_UID' => $uid],
            ])->fetchAll(),
            'UF_LECTURE_ID'
        );
    }

    public static function addRead(string $uid, string $lectureId): void
    {
        $dc = self::dataClass('X5PwaLectureReads');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_LECTURE_ID' => $lectureId],
            'limit'  => 1,
        ])->fetch();
        if ($existing) return;
        $dc::add([
            'UF_UID' => $uid, 'UF_LECTURE_ID' => $lectureId,
            'UF_READ_AT' => self::now(),
        ]);
    }

    public static function getTasks(string $uid): array
    {
        $dc = self::dataClass('X5PwaTaskCompletions');
        return array_column(
            $dc::getList([
                'select' => ['UF_TASK_ID'],
                'filter' => ['=UF_UID' => $uid],
            ])->fetchAll(),
            'UF_TASK_ID'
        );
    }

    public static function addTask(string $uid, string $taskKey): void
    {
        $dc = self::dataClass('X5PwaTaskCompletions');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_TASK_ID' => $taskKey],
            'limit'  => 1,
        ])->fetch();
        if ($existing) return;
        $dc::add([
            'UF_UID' => $uid, 'UF_TASK_ID' => $taskKey,
            'UF_COMPLETED_AT' => self::now(),
        ]);
    }

    public static function getHabits(string $uid): array
    {
        $dc = self::dataClass('X5PwaHabitLogs');
        $rows = $dc::getList([
            'select' => ['UF_HABIT_ID', 'UF_COMPLETED_AT'],
            'filter' => ['=UF_UID' => $uid],
            'order'  => ['UF_COMPLETED_AT' => 'ASC'],
        ])->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['UF_HABIT_ID']][] = $r['UF_COMPLETED_AT'];
        }
        return $out;
    }

    public static function addHabit(string $uid, string $habitId): DateTime
    {
        $dc = self::dataClass('X5PwaHabitLogs');
        $now = self::now();
        $dc::add([
            'UF_UID' => $uid, 'UF_HABIT_ID' => $habitId,
            'UF_COMPLETED_AT' => $now,
        ]);
        return $now;
    }

    // ───────── Push ─────────

    public static function getSubscription(string $uid): ?array
    {
        $dc = self::dataClass('X5PwaPushSubs');
        $row = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        if (!$row) return null;
        return [
            'endpoint' => $row['UF_ENDPOINT'],
            'keys'     => ['p256dh' => $row['UF_P256DH'], 'auth' => $row['UF_AUTH']],
        ];
    }

    public static function saveSubscription(string $uid, array $subscription): void
    {
        $dc = self::dataClass('X5PwaPushSubs');
        $existing = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        $data = [
            'UF_UID'        => $uid,
            'UF_ENDPOINT'   => (string)($subscription['endpoint'] ?? ''),
            'UF_P256DH'     => (string)($subscription['keys']['p256dh'] ?? ''),
            'UF_AUTH'       => (string)($subscription['keys']['auth']   ?? ''),
            'UF_UPDATED_AT' => self::now(),
        ];
        if ($existing) $dc::update($existing['ID'], $data);
        else $dc::add($data);
    }

    public static function deleteSubscription(string $uid): void
    {
        $dc = self::dataClass('X5PwaPushSubs');
        $row = $dc::getList(['filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        if ($row) $dc::delete($row['ID']);
    }

    public static function listSubscriptions(): array
    {
        $dc = self::dataClass('X5PwaPushSubs');
        return $dc::getList()->fetchAll();
    }

    // ───────── Notification log ─────────

    public static function getLastNotified(string $uid, string $habitId): int
    {
        $dc = self::dataClass('X5PwaNotifLog');
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_HABIT_ID' => $habitId],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return 0;
        return $row['UF_NOTIFIED_AT']
            ? $row['UF_NOTIFIED_AT']->getTimestamp() * 1000
            : 0;
    }

    public static function setLastNotified(string $uid, string $habitId): void
    {
        $dc = self::dataClass('X5PwaNotifLog');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_HABIT_ID' => $habitId],
            'limit'  => 1,
        ])->fetch();
        if ($existing) {
            $dc::update($existing['ID'], ['UF_NOTIFIED_AT' => self::now()]);
        } else {
            $dc::add([
                'UF_UID' => $uid, 'UF_HABIT_ID' => $habitId,
                'UF_NOTIFIED_AT' => self::now(),
                'UF_TODAY_COUNT' => 0,
                'UF_TODAY_DATE'  => '',
            ]);
        }
    }

    public static function markHabitDayDone(
        string $uid,
        string $habitId,
        string $localDate,
        int $timesPerDayHint
    ): void {
        $dc = self::dataClass('X5PwaNotifLog');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_HABIT_ID' => $habitId],
            'limit'  => 1,
        ])->fetch();

        $now   = self::now();
        $count = max(1, $timesPerDayHint);

        if ($existing) {
            $dc::update((int)$existing['ID'], [
                'UF_NOTIFIED_AT' => $now,
                'UF_TODAY_COUNT' => $count,
                'UF_TODAY_DATE'  => $localDate,
            ]);
        } else {
            $dc::add([
                'UF_UID'         => $uid,
                'UF_HABIT_ID'    => $habitId,
                'UF_NOTIFIED_AT' => $now,
                'UF_TODAY_COUNT' => $count,
                'UF_TODAY_DATE'  => $localDate,
            ]);
        }
    }

    public static function getNotifState(string $uid, string $habitId, ?string $localDate = null): array
    {
        $dc = self::dataClass('X5PwaNotifLog');
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_HABIT_ID' => $habitId],
            'limit'  => 1,
        ])->fetch();

        if (!$row) return ['lastAt' => 0, 'todayCount' => 0];

        $lastAt = $row['UF_NOTIFIED_AT']
            ? $row['UF_NOTIFIED_AT']->getTimestamp() * 1000
            : 0;

        $compareDate = $localDate ?? gmdate('Y-m-d');
        $rowDate     = (string)($row['UF_TODAY_DATE'] ?? '');
        $count       = $rowDate === $compareDate ? (int)($row['UF_TODAY_COUNT'] ?? 0) : 0;

        return ['lastAt' => $lastAt, 'todayCount' => $count];
    }

    public static function setNotified(string $uid, string $habitId, ?string $localDate = null): void
    {
        $dc = self::dataClass('X5PwaNotifLog');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_HABIT_ID' => $habitId],
            'limit'  => 1,
        ])->fetch();

        $today = $localDate ?? gmdate('Y-m-d');

        if ($existing) {
            $rowDate = (string)($existing['UF_TODAY_DATE'] ?? '');
            $count   = $rowDate === $today
                ? (int)($existing['UF_TODAY_COUNT'] ?? 0) + 1
                : 1;

            $dc::update((int)$existing['ID'], [
                'UF_NOTIFIED_AT' => self::now(),
                'UF_TODAY_COUNT' => $count,
                'UF_TODAY_DATE'  => $today,
            ]);
        } else {
            $dc::add([
                'UF_UID'         => $uid,
                'UF_HABIT_ID'    => $habitId,
                'UF_NOTIFIED_AT' => self::now(),
                'UF_TODAY_COUNT' => 1,
                'UF_TODAY_DATE'  => $today,
            ]);
        }
    }

    // ───────── Push history ─────────

    public static function addPushHistory(array $entry): void
    {
        $dc = self::dataClass('X5PwaPushHistory');
        $dc::add([
            'UF_TYPE'       => (string)($entry['type']   ?? ''),
            'UF_TARGET'     => (string)($entry['target'] ?? ''),
            'UF_TITLE'      => (string)($entry['title']  ?? ''),
            'UF_BODY'       => (string)($entry['body']   ?? ''),
            'UF_URL'        => mb_substr((string)($entry['url'] ?? ''), 0, 500),
            'UF_STATUS'     => (string)($entry['status'] ?? ''),
            'UF_CREATED_AT' => self::now(),
        ]);
    }

    public static function getPushHistory(int $limit = 200): array
    {
        $dc = self::dataClass('X5PwaPushHistory');
        return $dc::getList([
            'order' => ['UF_CREATED_AT' => 'DESC'],
            'limit' => $limit,
        ])->fetchAll();
    }

    public static function clearPushHistory(): void
    {
        $dc = self::dataClass('X5PwaPushHistory');
        $rows = $dc::getList()->fetchAll();
        foreach ($rows as $r) {
            $dc::delete((int)$r['ID']);
        }
    }

    // ───────── Rate limit ─────────

    public static function getRateBucket(string $key): array
    {
        $dc = self::dataClass('X5PwaRateLimits');
        $row = $dc::getList(['filter' => ['=UF_KEY' => $key], 'limit' => 1])->fetch();
        if (!$row) return [];
        return json_decode((string)$row['UF_TIMESTAMPS'], true) ?: [];
    }

    public static function setRateBucket(string $key, array $timestamps): void
    {
        $dc = self::dataClass('X5PwaRateLimits');
        $row = $dc::getList(['filter' => ['=UF_KEY' => $key], 'limit' => 1])->fetch();
        $value = json_encode(array_values($timestamps));
        if ($row) $dc::update($row['ID'], ['UF_TIMESTAMPS' => $value]);
        else $dc::add(['UF_KEY' => $key, 'UF_TIMESTAMPS' => $value]);
    }

    // ───────── Consent ─────────

    public static function saveConsent(string $uid, bool $granted, string $localDt, string $source = 'app'): array
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList(['select' => ['ID'], 'filter' => ['=UF_UID' => $uid], 'limit' => 1])->fetch();
        if (!$row) return ['ok' => false, 'reason' => 'UID_NOT_FOUND', 'granted' => false, 'serverAt' => null];

        $now = self::now();
        $localDt = mb_substr($localDt, 0, 32);

        $res = $dc::update((int)$row['ID'], [
            'UF_CONSENT_GRANTED'  => $granted ? 'Y' : 'N',
            'UF_CONSENT_DATETIME' => $now,
            'UF_CONSENT_LOCAL_DT' => $localDt,
        ]);
        if (!$res->isSuccess()) {
            return ['ok' => false, 'reason' => 'DB_ERROR', 'granted' => false, 'serverAt' => null];
        }

        try {
            $logDc = self::dataClass('X5PwaConsentLog');
            $logDc::add([
                'UF_UID'       => $uid,
                'UF_GRANTED'   => $granted ? 'Y' : 'N',
                'UF_LOCAL_DT'  => $localDt,
                'UF_SERVER_AT' => $now,
                'UF_IP'        => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'UF_SOURCE'    => mb_substr($source, 0, 32),
            ]);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'consent log failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'Storage::saveConsent',
                '/x5.pwa.log'
            );
        }

        return [
            'ok'       => true,
            'reason'   => null,
            'granted'  => $granted,
            'serverAt' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    public static function getConsent(string $uid): array
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['UF_CONSENT_GRANTED', 'UF_CONSENT_DATETIME', 'UF_CONSENT_LOCAL_DT'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();

        if (!$row) {
            return ['granted' => false, 'hasConsent' => false, 'serverAt' => null, 'localDt' => null];
        }

        $rawGranted = $row['UF_CONSENT_GRANTED'] ?? null;
        $hasConsent = ($rawGranted === 'Y' || $rawGranted === 'N');
        $granted    = ($rawGranted === 'Y');

        $serverAt = null;
        if (!empty($row['UF_CONSENT_DATETIME'])) {
            try {
                $serverAt = $row['UF_CONSENT_DATETIME']->format('Y-m-d\TH:i:sP');
            } catch (\Throwable $e) {
                $serverAt = null;
            }
        }

        $localDt = trim((string)($row['UF_CONSENT_LOCAL_DT'] ?? ''));

        return [
            'granted'    => $granted,
            'hasConsent' => $hasConsent,
            'serverAt'   => $serverAt,
            'localDt'    => $localDt !== '' ? $localDt : null,
        ];
    }

    public static function getConsentHistory(string $uid, int $limit = 100): array
    {
        try {
            $dc = self::dataClass('X5PwaConsentLog');
            return $dc::getList([
                'filter' => ['=UF_UID' => $uid],
                'order'  => ['UF_SERVER_AT' => 'DESC', 'ID' => 'DESC'],
                'limit'  => max(1, $limit),
            ])->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ───────── Selected habit ─────────

    public static function getSelectedHabit(string $uid): ?string
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['UF_HABIT_ID'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return null;
        $h = trim((string)($row['UF_HABIT_ID'] ?? ''));
        return $h !== '' ? $h : null;
    }

    public static function selectHabit(string $uid, string $habitId): array
    {
        $dc  = self::dataClass('X5PwaUidBindings');
        $row = $dc::getList([
            'select' => ['ID', 'UF_HABIT_ID', 'UF_HABIT_SELECTED_AT'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$row) return ['ok' => false, 'reason' => 'UID_NOT_FOUND', 'habitId' => null, 'selectedAt' => null];

        $existing = trim((string)($row['UF_HABIT_ID'] ?? ''));
        if ($existing !== '') {
            if ($existing === $habitId) {
                $at = !empty($row['UF_HABIT_SELECTED_AT'])
                    ? $row['UF_HABIT_SELECTED_AT']->format('Y-m-d\TH:i:sP')
                    : null;
                return ['ok' => true, 'reason' => 'OK', 'habitId' => $existing, 'selectedAt' => $at];
            }
            $at = !empty($row['UF_HABIT_SELECTED_AT'])
                ? $row['UF_HABIT_SELECTED_AT']->format('Y-m-d\TH:i:sP')
                : null;
            return ['ok' => false, 'reason' => 'ALREADY_SELECTED', 'habitId' => $existing, 'selectedAt' => $at];
        }

        $now = self::now();
        $res = $dc::update((int)$row['ID'], [
            'UF_HABIT_ID'          => mb_substr($habitId, 0, 32),
            'UF_HABIT_SELECTED_AT' => $now,
        ]);
        if (!$res->isSuccess()) {
            return ['ok' => false, 'reason' => 'DB_ERROR', 'habitId' => null, 'selectedAt' => null];
        }

        return [
            'ok'         => true,
            'reason'     => 'OK',
            'habitId'    => $habitId,
            'selectedAt' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    // ───────── EventActions ─────────

    public static function getEventAction(string $uid, string $eventId): ?array
    {
        try {
            $dc = self::dataClass('X5PwaEventActions');
            $row = $dc::getList([
                'filter' => ['=UF_UID' => $uid, '=UF_EVENT_ID' => $eventId],
                'limit'  => 1,
            ])->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getEventActionsForUid(string $uid, ?string $eventId = null): array
    {
        try {
            $dc = self::dataClass('X5PwaEventActions');
            $filter = ['=UF_UID' => $uid];
            if ($eventId !== null) $filter['=UF_EVENT_ID'] = $eventId;

            return $dc::getList([
                'filter' => $filter,
                'order'  => ['UF_CLICKED_AT' => 'DESC', 'ID' => 'DESC'],
            ])->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function upsertEventClick(string $uid, string $eventId, array $event): array
    {
        $dc = self::dataClass('X5PwaEventActions');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid, '=UF_EVENT_ID' => $eventId],
            'limit'  => 1,
        ])->fetch();

        $now = self::now();

        if ($existing) {
            if (!empty($existing['UF_CLICKED_AT'])) {
                try {
                    return ['ok' => true, 'clickedAt' => $existing['UF_CLICKED_AT']->format('Y-m-d\TH:i:sP')];
                } catch (\Throwable $e) {
                    return ['ok' => true, 'clickedAt' => null];
                }
            }
            $res = $dc::update((int)$existing['ID'], ['UF_CLICKED_AT' => $now]);
            if (!$res->isSuccess()) return ['ok' => false, 'clickedAt' => null];
            return ['ok' => true, 'clickedAt' => $now->format('Y-m-d\TH:i:sP')];
        }

        $res = $dc::add([
            'UF_UID'           => $uid,
            'UF_EVENT_ID'      => mb_substr($eventId, 0, 128),
            'UF_EVENT_DATE'    => (string)($event['DATE'] ?? ''),
            'UF_EVENT_TITLE'   => mb_substr((string)($event['NAME'] ?? ''), 0, 255),
            'UF_REWARD_TEXT'   => mb_substr((string)($event['REWARD_TEXT'] ?? ''), 0, 64),
            'UF_REWARD_POINTS' => (int)($event['REWARD_POINTS'] ?? 0),
            'UF_CLICKED_AT'    => $now,
            'UF_CONFIRMED_AT'  => null,
            'UF_REWARDED'      => 'N',
        ]);
        if (!$res->isSuccess()) return ['ok' => false, 'clickedAt' => null];

        return ['ok' => true, 'clickedAt' => $now->format('Y-m-d\TH:i:sP')];
    }

    public static function confirmEventAction(string $uid, string $eventId, array $event): array
    {
        $dc = self::dataClass('X5PwaEventActions');
        $existing = $dc::getList([
            'select' => ['ID', 'UF_CONFIRMED_AT'],
            'filter' => ['=UF_UID' => $uid, '=UF_EVENT_ID' => $eventId],
            'limit'  => 1,
        ])->fetch();

        if (!$existing) return ['ok' => false, 'confirmedAt' => null];

        if (!empty($existing['UF_CONFIRMED_AT'])) {
            try {
                return ['ok' => true, 'confirmedAt' => $existing['UF_CONFIRMED_AT']->format('Y-m-d\TH:i:sP')];
            } catch (\Throwable $e) {
                return ['ok' => true, 'confirmedAt' => null];
            }
        }

        $now = self::now();
        $res = $dc::update((int)$existing['ID'], [
            'UF_CONFIRMED_AT'  => $now,
            'UF_REWARDED'      => 'Y',
            'UF_REWARD_TEXT'   => mb_substr((string)($event['REWARD_TEXT'] ?? ''), 0, 64),
            'UF_REWARD_POINTS' => (int)($event['REWARD_POINTS'] ?? 0),
            'UF_EVENT_DATE'    => (string)($event['DATE'] ?? ''),
            'UF_EVENT_TITLE'   => mb_substr((string)($event['NAME'] ?? ''), 0, 255),
        ]);
        if (!$res->isSuccess()) return ['ok' => false, 'confirmedAt' => null];

        return ['ok' => true, 'confirmedAt' => $now->format('Y-m-d\TH:i:sP')];
    }

    public static function getEventStatsForUid(string $uid): array
    {
        try {
            $conn = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $safeUid = $sqlHelper->forSql($uid);

            $row = $conn->query(
                "SELECT COUNT(*) AS TOTAL,
                        SUM(CASE WHEN UF_CONFIRMED_AT IS NOT NULL THEN 1 ELSE 0 END) AS CONFIRMED
                 FROM x5_pwa_event_actions
                 WHERE UF_UID = '{$safeUid}'"
            )->fetch();

            return [
                'visited'   => (int)($row['TOTAL'] ?? 0),
                'confirmed' => (int)($row['CONFIRMED'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['visited' => 0, 'confirmed' => 0];
        }
    }

    // ───────── Rewards ─────────

    /**
     * Запись в RewardLog. На duplicate key бросает исключение.
     */
    public static function addRewardLog(array $entry): void
    {
        $dc = self::dataClass('X5PwaRewardLog');
        $dc::add([
            'UF_UID'         => (string)($entry['uid']        ?? ''),
            'UF_ACTION_TYPE' => (string)($entry['actionType'] ?? ''),
            'UF_ACTION_ID'   => mb_substr((string)($entry['actionId'] ?? ''), 0, 128),
            'UF_UNIQUE_KEY'  => mb_substr((string)($entry['uniqueKey'] ?? ''), 0, 32),
            'UF_POINTS'      => (int)($entry['points']        ?? 0),
            'UF_TITLE'       => mb_substr((string)($entry['title'] ?? ''), 0, 255),
            'UF_LOCAL_DATE'  => mb_substr((string)($entry['localDate'] ?? ''), 0, 10),
            'UF_CREATED_AT'  => self::now(),
        ]);
    }

    /**
     * Баланс из кеша UidBindings.UF_REWARD_BALANCE.
     * Если поле NULL (старый пользователь, до миграции) — пересчитываем и сохраняем.
     */
    public static function getRewardBalance(string $uid): int
    {
        try {
            $dc  = self::dataClass('X5PwaUidBindings');
            $row = $dc::getList([
                'select' => ['ID', 'UF_REWARD_BALANCE'],
                'filter' => ['=UF_UID' => $uid],
                'limit'  => 1,
            ])->fetch();
            if (!$row) return 0;

            if ($row['UF_REWARD_BALANCE'] === null) {
                return self::recalcRewardBalance($uid);
            }

            return (int)$row['UF_REWARD_BALANCE'];
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Атомарный инкремент кеша.
     */
    public static function incrementRewardBalance(string $uid, int $delta): void
    {
        if ($delta === 0) return;

        try {
            $conn      = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $safeUid   = $sqlHelper->forSql($uid);
            $deltaSafe = (int)$delta;

            $conn->queryExecute(
                "UPDATE x5_pwa_uid_bindings
                 SET UF_REWARD_BALANCE = COALESCE(UF_REWARD_BALANCE, 0) + {$deltaSafe}
                 WHERE UF_UID = '{$safeUid}'"
            );
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'increment failed uid=' . substr($uid, 0, 12) . '… delta=' . $delta
                . ': ' . $e->getMessage(),
                'Storage::incrementRewardBalance',
                '/x5.pwa.log'
            );
        }
    }

    /**
     * Пересчитывает баланс из лога (SUM UF_POINTS) и записывает в кеш.
     */
    public static function recalcRewardBalance(string $uid): int
    {
        try {
            $conn      = Application::getConnection();
            $sqlHelper = $conn->getSqlHelper();
            $safeUid   = $sqlHelper->forSql($uid);

            $row = $conn->query(
                "SELECT COALESCE(SUM(UF_POINTS), 0) AS T
                 FROM x5_pwa_reward_log
                 WHERE UF_UID = '{$safeUid}'"
            )->fetch();
            $total = (int)($row['T'] ?? 0);

            $conn->queryExecute(
                "UPDATE x5_pwa_uid_bindings
                 SET UF_REWARD_BALANCE = {$total}
                 WHERE UF_UID = '{$safeUid}'"
            );

            return $total;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Пагинированная история начислений.
     *
     * @return array{items:array,total:int,page:int,perPage:int,pages:int}
     */
    public static function getRewardHistory(string $uid, array $params = []): array
    {
        try {
            $dc = self::dataClass('X5PwaRewardLog');
        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 50, 'pages' => 1];
        }

        $filter = ['=UF_UID' => $uid];

        if (!empty($params['type']) && is_string($params['type'])) {
            $filter['=UF_ACTION_TYPE'] = (string)$params['type'];
        }
        if (!empty($params['date_from'])) {
            $filter['>=UF_LOCAL_DATE'] = (string)$params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filter['<=UF_LOCAL_DATE'] = (string)$params['date_to'];
        }

        $noPaging = !empty($params['noPaging']) || (int)($params['perPage'] ?? -1) === 0;
        $page     = max(1, (int)($params['page'] ?? 1));
        $perPage  = $noPaging ? 0 : max(1, min(200, (int)($params['perPage'] ?? 50)));
        $offset   = $noPaging ? 0 : ($page - 1) * $perPage;

        $total = (int)($dc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch()['CNT'] ?? 0);

        $qParams = [
            'filter' => $filter,
            'order'  => ['UF_CREATED_AT' => 'DESC', 'ID' => 'DESC'],
        ];
        if (!$noPaging) {
            $qParams['limit']  = $perPage;
            $qParams['offset'] = $offset;
        }
        $rows = $dc::getList($qParams)->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'ID'         => (int)$r['ID'],
                'TYPE'       => (string)($r['UF_ACTION_TYPE'] ?? ''),
                'TYPE_TITLE' => RewardsBuilder::typeTitle((string)($r['UF_ACTION_TYPE'] ?? '')),
                'ACTION_ID'  => (string)($r['UF_ACTION_ID'] ?? ''),
                'POINTS'     => (int)($r['UF_POINTS'] ?? 0),
                'TITLE'      => (string)($r['UF_TITLE'] ?? ''),
                'LOCAL_DATE' => (string)($r['UF_LOCAL_DATE'] ?? ''),
                'CREATED_AT' => !empty($r['UF_CREATED_AT'])
                    ? $r['UF_CREATED_AT']->format('Y-m-d\TH:i:sP')
                    : null,
            ];
        }

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $noPaging ? 1 : ($total > 0 ? (int)ceil($total / $perPage) : 1),
        ];
    }
}