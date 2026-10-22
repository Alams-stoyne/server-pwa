<?php
namespace X5\Pwa;

use Bitrix\Main\Application;

class StepsBuilder
{
    public const GOAL = 100000;
    public const MAX_STEPS = 30000;
    public const MAX_IMAGES = 10;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    // ─────────── Утилиты ───────────

    public static function userLocalDate(string $uid): string
    {
        $tzOffsetMin = Storage::getUserTzOffsetMin($uid);
        $localMs     = (time() * 1000) + $tzOffsetMin * 60 * 1000;
        return gmdate('Y-m-d', (int)($localMs / 1000));
    }

    private static function checkAlreadySent(string $uid, string $localDate): array
    {
        $dc = Storage::dataClass('X5PwaSteps');
        $row = $dc::getList([
            'select' => ['ID', 'UF_SENT_AT'],
            'filter' => ['=UF_UID' => $uid, '=UF_LOCAL_DATE' => $localDate],
            'limit'  => 1,
        ])->fetch();

        if (!$row) return ['exists' => false, 'id' => null, 'sentAt' => null];
        return [
            'exists' => true,
            'id'     => (int)$row['ID'],
            'sentAt' => $row['UF_SENT_AT'] ? $row['UF_SENT_AT']->format('Y-m-d\TH:i:sP') : null,
        ];
    }

    private static function decodeImage(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $mime = 'image/jpeg';
        $data = $raw;

        if (preg_match('#^data:([^;]+);base64,(.*)$#s', $raw, $m)) {
            $mime = strtolower(trim($m[1]));
            $data = $m[2];
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!in_array($mime, $allowed, true)) return null;

        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') return null;
        if (strlen($binary) > self::MAX_IMAGE_BYTES) return null;

        return ['mime' => $mime, 'binary' => $binary];
    }

    private static function saveImage(string $uid, array $decoded, int $idx): ?int
    {
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
        ];
        $mime = $decoded['mime'];
        $ext  = $extMap[$mime] ?? 'jpg';

        $tmp = tempnam(sys_get_temp_dir(), 'x5step_');
        if ($tmp === false) return null;
        if (file_put_contents($tmp, $decoded['binary']) === false) {
            @unlink($tmp);
            return null;
        }

        $shortUid = substr(preg_replace('/[^a-z0-9]/i', '', $uid) ?: 'u', 0, 12);

        $fileArray = [
            'name'     => "step_{$shortUid}_" . time() . "_{$idx}.{$ext}",
            'type'     => $mime,
            'tmp_name' => $tmp,
            'size'     => strlen($decoded['binary']),
            'error'    => 0,
        ];

        $fileId = \CFile::SaveFile($fileArray, 'x5steps');
        @unlink($tmp);

        return $fileId ? (int)$fileId : null;
    }

    public static function expandImages(?string $jsonFileIds): array
    {
        if ($jsonFileIds === null || $jsonFileIds === '') return [];

        $ids = json_decode($jsonFileIds, true);
        if (!is_array($ids)) return [];

        $out = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;

            $url = \CFile::GetPath($id);
            if (!$url) continue;

            $thumbArr = \CFile::ResizeImageGet($id, ['width' => 120, 'height' => 120], BX_RESIZE_IMAGE_PROPORTIONAL);
            $thumb = $thumbArr && !empty($thumbArr['src']) ? $thumbArr['src'] : $url;

            $out[] = ['ID' => $id, 'URL' => $url, 'THUMB' => $thumb];
        }
        return $out;
    }

    // ─────────── Отправка ───────────

    public static function send(string $uid, $stepsRaw, $imagesRaw, $consentRaw, string $localDate): array
    {
        $consent = false;
        if ($consentRaw === true || $consentRaw === 1
            || $consentRaw === '1' || $consentRaw === 'Y' || $consentRaw === 'y') {
            $consent = true;
        }
        if (!$consent) {
            return ['RESULT' => 'CONSENT_REQUIRED', 'MESSAGE' => 'Необходимо согласие на обработку данных.'];
        }

        if (!is_numeric($stepsRaw)) {
            return ['RESULT' => 'INVALID_STEPS', 'MESSAGE' => 'STEPS должен быть числом.'];
        }
        $steps = (int)$stepsRaw;
        if ($steps < 0 || $steps > self::MAX_STEPS) {
            return [
                'RESULT'  => 'INVALID_STEPS',
                'MESSAGE' => 'STEPS должен быть от 0 до ' . number_format(self::MAX_STEPS, 0, '', ' ') . '.',
            ];
        }

        if (!is_array($imagesRaw) || count($imagesRaw) === 0) {
            return ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Нужно приложить хотя бы одно фото.'];
        }
        if (count($imagesRaw) > self::MAX_IMAGES) {
            return [
                'RESULT'  => 'INVALID_IMAGES',
                'MESSAGE' => 'Не больше ' . self::MAX_IMAGES . ' фото за отправку.',
            ];
        }

        $decoded = [];
        foreach ($imagesRaw as $idx => $raw) {
            if (!is_string($raw)) {
                return ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Фото #' . ($idx + 1) . ' не строка.'];
            }
            $d = self::decodeImage($raw);
            if ($d === null) {
                return ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Фото #' . ($idx + 1) . ' некорректно.'];
            }
            $decoded[] = $d;
        }

        $already = self::checkAlreadySent($uid, $localDate);
        if ($already['exists']) {
            return [
                'RESULT'       => 'ALREADY_SENT_TODAY',
                'MESSAGE'      => 'Сегодня вы уже отправляли шаги.',
                'LAST_SENT_AT' => $already['sentAt'],
            ];
        }

        $fileIds = [];
        foreach ($decoded as $idx => $d) {
            $fileId = self::saveImage($uid, $d, $idx);
            if ($fileId === null) {
                foreach ($fileIds as $fid) {
                    \CFile::Delete($fid);
                }
                return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить фото.'];
            }
            $fileIds[] = $fileId;
        }

        $dc = Storage::dataClass('X5PwaSteps');
        $res = $dc::add([
            'UF_UID'           => $uid,
            'UF_STEPS'         => $steps,
            'UF_LOCAL_DATE'    => $localDate,
            'UF_SENT_AT'       => new \Bitrix\Main\Type\DateTime(),
            'UF_IMAGES'        => json_encode($fileIds, JSON_UNESCAPED_UNICODE),
            'UF_CONSENT'       => 'Y',
            'UF_STATUS'        => 'N',
            'UF_REJECT_REASON' => '',
        ]);

        if (!$res->isSuccess()) {
            foreach ($fileIds as $fid) {
                \CFile::Delete($fid);
            }
            \Bitrix\Main\Diag\Debug::writeToFile(
                'DB add failed: ' . implode('; ', $res->getErrorMessages()),
                'StepsBuilder::send',
                '/x5.pwa.log'
            );
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить запись.'];
        }

        // ─── Награда за отправку шагов ───
        try {
            RewardsBuilder::awardStepsSent($uid, $localDate);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'award steps sent failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'StepsBuilder::send',
                '/x5.pwa.log'
            );
        }

        return [
            'RESULT'          => 'OK',
            'SERVER_DATETIME' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ─────────── Кеш UF_TOTAL_STEPS ───────────

    public static function recalcUserTotal(string $uid): int
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid = $sqlHelper->forSql($uid);

        $row = $conn->query(
            "SELECT COALESCE(SUM(UF_STEPS), 0) AS T
             FROM x5_pwa_steps
             WHERE UF_UID = '{$safeUid}' AND UF_STATUS = 'Y'"
        )->fetch();
        $total = (int)($row['T'] ?? 0);

        $dc = Storage::dataClass('X5PwaUidBindings');
        $b = $dc::getList([
            'select' => ['ID'],
            'filter' => ['=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if ($b) {
            $dc::update((int)$b['ID'], ['UF_TOTAL_STEPS' => $total]);
        }

        return $total;
    }

    public static function recalcTotalsForUids(array $uids): void
    {
        $uids = array_values(array_unique(array_filter(array_map('strval', $uids))));
        if (!$uids) return;

        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $safeUids = array_map([$sqlHelper, 'forSql'], $uids);
        $inList = "'" . implode("','", $safeUids) . "'";

        $rows = $conn->query(
            "SELECT UF_UID, COALESCE(SUM(UF_STEPS), 0) AS T
             FROM x5_pwa_steps
             WHERE UF_UID IN ({$inList}) AND UF_STATUS = 'Y'
             GROUP BY UF_UID"
        )->fetchAll();

        $totals = [];
        foreach ($rows as $r) {
            $totals[(string)$r['UF_UID']] = (int)$r['T'];
        }

        $dc = Storage::dataClass('X5PwaUidBindings');
        $bindings = $dc::getList([
            'select' => ['ID', 'UF_UID'],
            'filter' => ['=UF_UID' => $uids],
        ])->fetchAll();

        foreach ($bindings as $b) {
            $uid = (string)$b['UF_UID'];
            $total = $totals[$uid] ?? 0;
            $dc::update((int)$b['ID'], ['UF_TOTAL_STEPS' => $total]);
        }
    }

    public static function recalcAllTotals(): array
    {
        $start = microtime(true);
        $conn  = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $conn->queryExecute("UPDATE x5_pwa_uid_bindings SET UF_TOTAL_STEPS = 0");

        $rows = $conn->query(
            "SELECT UF_UID, SUM(UF_STEPS) AS T
             FROM x5_pwa_steps
             WHERE UF_STATUS = 'Y'
             GROUP BY UF_UID"
        )->fetchAll();

        $updated = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            if (!$chunk) continue;

            $cases    = [];
            $uidsSafe = [];
            foreach ($chunk as $r) {
                $uid  = (string)$r['UF_UID'];
                $t    = (int)$r['T'];
                $safe = $sqlHelper->forSql($uid);
                $cases[]    = "WHEN '{$safe}' THEN {$t}";
                $uidsSafe[] = "'{$safe}'";
            }

            $caseSql = 'CASE UF_UID ' . implode(' ', $cases) . ' ELSE 0 END';
            $inSql   = implode(',', $uidsSafe);

            try {
                $conn->queryExecute(
                    "UPDATE x5_pwa_uid_bindings
                     SET UF_TOTAL_STEPS = {$caseSql}
                     WHERE UF_UID IN ({$inSql})"
                );
                $updated += count($chunk);
            } catch (\Throwable $e) {
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'Batch update failed: ' . $e->getMessage(),
                    'StepsBuilder::recalcAllTotals',
                    '/x5.pwa.log'
                );
            }
        }

        $elapsed = round(microtime(true) - $start, 2);
        \Bitrix\Main\Diag\Debug::writeToFile(
            "recalcAllTotals: processed={$updated} elapsed={$elapsed}s",
            'StepsBuilder::recalcAllTotals',
            '/x5.pwa.log'
        );

        return ['processed' => $updated, 'elapsed' => $elapsed];
    }

    // ─────────── Сводка ───────────

    public static function getSummary(string $uid): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid = $sqlHelper->forSql($uid);

        $b = $conn->query(
            "SELECT UF_TOTAL_STEPS FROM x5_pwa_uid_bindings
             WHERE UF_UID = '{$safeUid}' LIMIT 1"
        )->fetch();

        if ($b && $b['UF_TOTAL_STEPS'] !== null) {
            $userTotal = (int)$b['UF_TOTAL_STEPS'];
        } else {
            $sumRow = $conn->query(
                "SELECT COALESCE(SUM(UF_STEPS), 0) AS T
                 FROM x5_pwa_steps
                 WHERE UF_UID = '{$safeUid}' AND UF_STATUS = 'Y'"
            )->fetch();
            $userTotal = (int)($sumRow['T'] ?? 0);
        }

        $totalUsersRow = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_uid_bindings
             WHERE UF_TOTAL_STEPS > 0"
        )->fetch();
        $totalUsers = (int)($totalUsersRow['C'] ?? 0);

        $rank = 0;
        if ($userTotal > 0) {
            $higherRow = $conn->query(
                "SELECT COUNT(*) AS C FROM x5_pwa_uid_bindings
                 WHERE UF_TOTAL_STEPS > {$userTotal}"
            )->fetch();
            $rank = (int)($higherRow['C'] ?? 0) + 1;
        }

        $localDate = self::userLocalDate($uid);
        $todayRow = $conn->query(
            "SELECT COALESCE(SUM(UF_STEPS), 0) AS T
             FROM x5_pwa_steps
             WHERE UF_UID = '{$safeUid}'
               AND UF_STATUS = 'Y'
               AND UF_LOCAL_DATE = '" . $sqlHelper->forSql($localDate) . "'"
        )->fetch();
        $todaySteps = (int)($todayRow['T'] ?? 0);

        $avgRow = $conn->query(
            "SELECT COUNT(DISTINCT UF_LOCAL_DATE) AS DAYS,
                    COALESCE(SUM(UF_STEPS), 0) AS TOTAL
             FROM x5_pwa_steps
             WHERE UF_UID = '{$safeUid}' AND UF_STATUS = 'Y'"
        )->fetch();
        $activeDays = (int)($avgRow['DAYS'] ?? 0);
        $dailyAvg = $activeDays > 0
            ? (int)round(((int)($avgRow['TOTAL'] ?? 0)) / $activeDays)
            : 0;

        $topPercent = 0;
        if ($totalUsers > 0 && $rank > 0) {
            $topPercent = max(1, (int)round(($rank / $totalUsers) * 100));
        }

        return [
            'TOTAL_STEPS' => $userTotal,
            'GOAL'        => self::GOAL,
            'RANK'        => $rank,
            'TOTAL_USERS' => $totalUsers,
            'TODAY_STEPS' => $todaySteps,
            'DAILY_AVG'   => $dailyAvg,
            'TOP_PERCENT' => $topPercent,
        ];
    }

    // ─────────── Рейтинг ───────────

    public static function buildLeaderboardForDate(?string $asOfDate = null, int $limit = 15): array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();

        $dateFilter = '';
        if ($asOfDate !== null) {
            $dateFilter = "AND s.UF_LOCAL_DATE <= '" . $sqlHelper->forSql($asOfDate) . "'";
        }

        $sql = "
            SELECT
                s.UF_UID,
                SUM(s.UF_STEPS) AS TOTAL_STEPS,
                b.UF_NICKNAME,
                b.UF_WORKPLACE
            FROM x5_pwa_steps s
            LEFT JOIN x5_pwa_uid_bindings b ON b.UF_UID = s.UF_UID
            WHERE s.UF_STATUS = 'Y' {$dateFilter}
            GROUP BY s.UF_UID, b.UF_NICKNAME, b.UF_WORKPLACE
            HAVING SUM(s.UF_STEPS) > 0
            ORDER BY TOTAL_STEPS DESC
        ";

        $rows = $conn->query($sql)->fetchAll();

        $all          = [];
        $byWorkplace  = [];
        $wpStats      = [];
        $totalUsers   = 0;
        $totalStepsAll = 0;

        foreach ($rows as $r) {
            $steps = (int)$r['TOTAL_STEPS'];
            if ($steps <= 0) continue;

            $uid       = (string)$r['UF_UID'];
            $nickname  = trim((string)($r['UF_NICKNAME'] ?? ''));
            $workplace = trim((string)($r['UF_WORKPLACE'] ?? ''));

            $displayName = $nickname !== ''
                ? '@' . ltrim($nickname, '@')
                : substr($uid, 0, 6) . '…';

            $totalUsers++;
            $totalStepsAll += $steps;

            $entry = [
                'nickname'  => $displayName,
                'workplace' => $workplace,
                'steps'     => $steps,
            ];

            if (count($all) < $limit) {
                $all[] = array_merge(['rank' => count($all) + 1], $entry);
            }

            if ($workplace !== '') {
                if (!isset($wpStats[$workplace])) {
                    $wpStats[$workplace] = ['participants' => 0, 'steps' => 0];
                }
                $wpStats[$workplace]['participants']++;
                $wpStats[$workplace]['steps'] += $steps;

                if (!isset($byWorkplace[$workplace])) $byWorkplace[$workplace] = [];
                if (count($byWorkplace[$workplace]) < $limit) {
                    $byWorkplace[$workplace][] = array_merge(
                        ['rank' => count($byWorkplace[$workplace]) + 1],
                        ['nickname' => $displayName, 'steps' => $steps]
                    );
                }
            }
        }

        $byWorkplaceResult = [];
        foreach ($byWorkplace as $wp => $top) {
            $byWorkplaceResult[$wp] = [
                'workplace'          => $wp,
                'TOTAL_PARTICIPANTS' => $wpStats[$wp]['participants'],
                'TOTAL_STEPS'        => $wpStats[$wp]['steps'],
                'TOP'                => $top,
            ];
        }

        return [
            'AS_OF_DATE'         => $asOfDate,
            'UPDATED_AT'         => gmdate('Y-m-d\TH:i:s\Z'),
            'TOTAL_PARTICIPANTS' => $totalUsers,
            'TOTAL_STEPS'        => $totalStepsAll,
            'ALL'                => $all,
            'BY_WORKPLACE'       => $byWorkplaceResult ?: new \stdClass(),
        ];
    }

    // ─────────── Школа ───────────

    public static function buildSchoolProgress(): array
    {
        $conn = Application::getConnection();

        $rows = $conn->query(
            "SELECT UF_UID, COALESCE(SUM(UF_STEPS), 0) AS T
             FROM x5_pwa_steps
             WHERE UF_STATUS = 'Y'
             GROUP BY UF_UID"
        )->fetchAll();

        $participants = 0;
        $totalSteps   = 0;
        $atGoal       = 0;
        $aboveHalf    = 0;

        foreach ($rows as $r) {
            $steps = (int)$r['T'];
            if ($steps <= 0) continue;

            $participants++;
            $totalSteps += $steps;

            if ($steps >= self::GOAL)            $atGoal++;
            if ($steps >= (int)(self::GOAL / 2)) $aboveHalf++;
        }

        $avgSteps   = $participants > 0 ? (int)round($totalSteps / $participants) : 0;
        $avgPercent = $participants > 0 ? round(($avgSteps / self::GOAL) * 100, 2) : 0;

        return [
            'GOAL'                 => self::GOAL,
            'TOTAL_PARTICIPANTS'   => $participants,
            'TOTAL_STEPS'          => $totalSteps,
            'AVG_STEPS'            => $avgSteps,
            'AVG_PROGRESS_PERCENT' => $avgPercent,
            'USERS_AT_GOAL'        => $atGoal,
            'USERS_ABOVE_HALF'     => $aboveHalf,
            'UPDATED_AT'           => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    // ─────────── Админка ───────────

    public static function listForAdmin(array $params = []): array
    {
        $dc = Storage::dataClass('X5PwaSteps');

        $filter = [];

        if (!empty($params['uid'])) {
            $filter['%UF_UID'] = '%' . $params['uid'] . '%';
        }

        $status = (string)($params['status'] ?? '');
        if (in_array($status, ['N', 'Y', 'R'], true)) {
            $filter['=UF_STATUS'] = $status;
        }

        if (!empty($params['date_from'])) {
            $filter['>=UF_LOCAL_DATE'] = (string)$params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filter['<=UF_LOCAL_DATE'] = (string)$params['date_to'];
        }

        $sortMap = [
            'ID'            => 'ID',
            'UF_SENT_AT'    => 'UF_SENT_AT',
            'UF_LOCAL_DATE' => 'UF_LOCAL_DATE',
            'UF_UID'        => 'UF_UID',
            'UF_STEPS'      => 'UF_STEPS',
            'UF_STATUS'     => 'UF_STATUS',
        ];
        $sortKey = $sortMap[$params['sort'] ?? 'UF_SENT_AT'] ?? 'UF_SENT_AT';
        $sortDir = strtoupper((string)($params['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $order   = [$sortKey => $sortDir, 'ID' => 'DESC'];

        $noPaging = !empty($params['noPaging']) || (int)($params['perPage'] ?? -1) === 0;
        $page     = max(1, (int)($params['page'] ?? 1));
        $perPage  = $noPaging ? 0 : max(1, min(200, (int)($params['perPage'] ?? 50)));
        $offset   = $noPaging ? 0 : ($page - 1) * $perPage;

        $totalRow = $dc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new \Bitrix\Main\Entity\ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();
        $total = (int)($totalRow['CNT'] ?? 0);

        $qParams = ['filter' => $filter, 'order' => $order];
        if (!$noPaging) {
            $qParams['limit']  = $perPage;
            $qParams['offset'] = $offset;
        }
        $rows = $dc::getList($qParams)->fetchAll();

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $noPaging ? 1 : ($total > 0 ? (int)ceil($total / $perPage) : 1),
        ];
    }

    public static function setStatus(int $id, string $status, int $adminId, string $reason = ''): bool
    {
        if (!in_array($status, ['Y', 'R', 'N'], true)) return false;

        $dc = Storage::dataClass('X5PwaSteps');
        $row = $dc::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        if (!$row) return false;

        $uid = (string)$row['UF_UID'];
        $stepsLocalDate = (string)($row['UF_LOCAL_DATE'] ?? '');

        $res = $dc::update($id, [
            'UF_STATUS'        => $status,
            'UF_MODERATED_AT'  => new \Bitrix\Main\Type\DateTime(),
            'UF_MODERATED_BY'  => $adminId,
            'UF_REJECT_REASON' => $status === 'R' ? mb_substr($reason, 0, 255) : '',
        ]);

        if ($res->isSuccess()) {
            self::recalcUserTotal($uid);

            // ─── Награда за approve ───
            if ($status === 'Y' && $stepsLocalDate !== '') {
                try {
                    RewardsBuilder::awardStepsApproved($uid, $stepsLocalDate);
                } catch (\Throwable $e) {
                    \Bitrix\Main\Diag\Debug::writeToFile(
                        'award steps approved failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                        'StepsBuilder::setStatus',
                        '/x5.pwa.log'
                    );
                }
            }
        }

        return $res->isSuccess();
    }

    public static function setStatusBatch(array $ids, string $status, int $adminId, string $reason = ''): int
    {
        if (!in_array($status, ['Y', 'R', 'N'], true)) return 0;

        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (!$ids) return 0;

        $dc = Storage::dataClass('X5PwaSteps');

        $rows = $dc::getList([
            'select' => ['ID', 'UF_UID', 'UF_LOCAL_DATE'],
            'filter' => ['=ID' => $ids],
        ])->fetchAll();

        $touchedUids = [];
        $processed   = 0;

        foreach ($rows as $r) {
            $rowId = (int)$r['ID'];
            $uid   = (string)$r['UF_UID'];
            $rowLocalDate = (string)($r['UF_LOCAL_DATE'] ?? '');
            $touchedUids[$uid] = true;

            $res = $dc::update($rowId, [
                'UF_STATUS'        => $status,
                'UF_MODERATED_AT'  => new \Bitrix\Main\Type\DateTime(),
                'UF_MODERATED_BY'  => $adminId,
                'UF_REJECT_REASON' => $status === 'R' ? mb_substr($reason, 0, 255) : '',
            ]);

            if ($res->isSuccess()) {
                $processed++;

                if ($status === 'Y' && $rowLocalDate !== '') {
                    try {
                        RewardsBuilder::awardStepsApproved($uid, $rowLocalDate);
                    } catch (\Throwable $e) {
                        \Bitrix\Main\Diag\Debug::writeToFile(
                            'award steps approved failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                            'StepsBuilder::setStatusBatch',
                            '/x5.pwa.log'
                        );
                    }
                }
            }
        }

        if ($touchedUids) {
            self::recalcTotalsForUids(array_keys($touchedUids));
        }

        return $processed;
    }

    public static function getById(int $id): ?array
    {
        $dc  = Storage::dataClass('X5PwaSteps');
        $row = $dc::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        return $row ?: null;
    }
}