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
    /** Смещение таймзоны по умолчанию (MSK = +180 мин от UTC). */
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

        $sources = [
            'X5PwaLectureReads'    => 'UF_UID',
            'X5PwaTaskCompletions' => 'UF_UID',
            'X5PwaHabitLogs'       => 'UF_UID',
            'X5PwaNotifLog'        => 'UF_UID',
            'X5PwaPushSubs'        => 'UF_UID',
            'X5PwaUserStates'      => 'UF_UID',
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
        ];

        foreach ($sources as [$table, $col]) {
            $tableSafe = $sqlHelper->forSql($table);
            $colSafe   = $sqlHelper->forSql($col);

            $sql = "SELECT UF_UID, MAX(`{$colSafe}`) AS MAXDT
                    FROM `{$tableSafe}`
                    WHERE UF_UID IN ({$inList})
                    GROUP BY UF_UID";

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
            } catch (\Throwable $e) { /* ignore bad date */ }
        }
        if (!empty($params['active_to'])) {
            try {
                $filter['<=UF_LAST_SEEN_AT'] = new DateTime((string)$params['active_to']);
            } catch (\Throwable $e) { /* ignore bad date */ }
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
        $perPage  = $noPaging
            ? 0
            : max(1, min(200, (int)($params['perPage'] ?? 50)));
        $offset   = $noPaging ? 0 : ($page - 1) * $perPage;

        $totalRow = $dc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        $total = (int)($totalRow['CNT'] ?? 0);

        $queryParams = [
            'filter' => $filter,
            'order'  => $order,
        ];
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
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
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
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid], 'limit' => 1,
        ])->fetch();
        if (!$row) return null;
        $s = $row['UF_STATE'] ?? null;
        return $s ? (json_decode($s, true) ?: null) : null;
    }

    public static function saveState(string $uid, $state): void
    {
        $dc = self::dataClass('X5PwaUserStates');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid], 'limit' => 1,
        ])->fetch();
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

    // ───────── Reads ─────────

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

    // ───────── Tasks ─────────

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

    // ───────── Habits ─────────

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
        $row = $dc::getList([
            'filter' => ['=UF_UID' => $uid], 'limit' => 1,
        ])->fetch();
        if (!$row) return null;
        return [
            'endpoint' => $row['UF_ENDPOINT'],
            'keys'     => ['p256dh' => $row['UF_P256DH'], 'auth' => $row['UF_AUTH']],
        ];
    }

    public static function saveSubscription(string $uid, array $subscription): void
    {
        $dc = self::dataClass('X5PwaPushSubs');
        $existing = $dc::getList([
            'filter' => ['=UF_UID' => $uid], 'limit' => 1,
        ])->fetch();
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
        $rows = $dc->getList()->fetchAll();
        foreach ($rows as $r) $dc->delete($r['ID']);
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
}