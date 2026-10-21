<?php
namespace X5\Pwa;

class ReflectionBuilder
{
    private static ?array $settingsCache = null;

    /**
     * Все настройки рефлексии из IB. Сортировка по ACTIVE_FROM ASC.
     *
     * @return array<int, array{date:string, title:string, description:string}>
     */
    public static function getAllSettings(): array
    {
        if (self::$settingsCache !== null) return self::$settingsCache;

        $ibId = Settings::iblockId('reflections');
        if (!$ibId) return self::$settingsCache = [];

        $rs = \CIBlockElement::GetList(
            ['ACTIVE_FROM' => 'ASC', 'SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'NAME', 'PREVIEW_TEXT', 'ACTIVE_FROM']
        );

        $out = [];
        while ($el = $rs->Fetch()) {
            $date = null;
            if (!empty($el['ACTIVE_FROM'])) {
                $ts = \MakeTimeStamp((string)$el['ACTIVE_FROM'], \FORMAT_DATETIME);
                if ($ts <= 0) $ts = strtotime((string)$el['ACTIVE_FROM']);
                if ($ts > 0) $date = date('Y-m-d', $ts);
            }
            if ($date === null) continue;

            $out[] = [
                'date'        => $date,
                'title'       => (string)$el['NAME'],
                'description' => (string)$el['PREVIEW_TEXT'],
            ];
        }

        return self::$settingsCache = $out;
    }

    public static function getForDate(string $date): array
    {
        $all = self::getAllSettings();

        $exact = null;
        $latest = null;

        foreach ($all as $row) {
            if ($row['date'] === $date) {
                $exact = $row;
                break;
            }
            if ($row['date'] <= $date) {
                $latest = $row;
            }
        }

        if ($exact !== null) return $exact;
        if ($latest !== null) return $latest;

        return [
            'date'        => $date,
            'title'       => 'О чём вы думаете сейчас?',
            'description' => 'Расскажите о своих мыслях и ощущениях.',
        ];
    }

    public static function userLocalDate(string $uid): string
    {
        $tzOffsetMin = Storage::getUserTzOffsetMin($uid);
        $localMs     = (time() * 1000) + $tzOffsetMin * 60 * 1000;
        return gmdate('Y-m-d', (int)($localMs / 1000));
    }

    /**
     * @return array{sentToday:bool, lastSentAt:?string}
     */
    public static function getStatus(string $uid): array
    {
        $localDate = self::userLocalDate($uid);

        $dc = Storage::dataClass('X5PwaReflections');
        $rows = $dc::getList([
            'select' => ['ID', 'UF_LOCAL_DATE', 'UF_SENT_AT'],
            'filter' => ['=UF_UID' => $uid, '=UF_LOCAL_DATE' => $localDate],
            'order'  => ['UF_SENT_AT' => 'DESC'],
            'limit'  => 1,
        ])->fetchAll();

        if (!$rows) {
            return ['sentToday' => false, 'lastSentAt' => null];
        }

        $row = $rows[0];
        $sentAt = $row['UF_SENT_AT']
            ? $row['UF_SENT_AT']->format('Y-m-d\TH:i:sP')
            : null;

        return ['sentToday' => true, 'lastSentAt' => $sentAt];
    }

    /**
     * @return array{ok:bool, reason:?string, id:?int, sentAt:?string}
     */
    public static function send(string $uid, string $text, string $localDate, string $serverNowIso): array
    {
        $status = self::getStatus($uid);
        if ($status['sentToday']) {
            return ['ok' => false, 'reason' => 'ALREADY_SENT_TODAY', 'id' => null, 'sentAt' => $status['lastSentAt']];
        }

        $dc = Storage::dataClass('X5PwaReflections');

        $res = $dc::add([
            'UF_UID'        => $uid,
            'UF_TEXT'       => mb_substr($text, 0, 5000),
            'UF_LOCAL_DATE' => $localDate,
            'UF_SENT_AT'    => new \Bitrix\Main\Type\DateTime(),
            'UF_APPROVED'   => 'N',
        ]);

        if (!$res->isSuccess()) {
            return ['ok' => false, 'reason' => 'DB_ERROR', 'id' => null, 'sentAt' => null];
        }

        return ['ok' => true, 'reason' => null, 'id' => (int)$res->getId(), 'sentAt' => $serverNowIso];
    }

    /**
     * Список рефлексий для админки. Параметризованная сортировка, фильтры,
     * пагинация.
     *
     * $params:
     *   search      — LIKE по тексту
     *   uid         — LIKE по UID
     *   approved    — 'Y' | 'N' | '' (все)
     *   date_from   — 'YYYY-MM-DD' включительно
     *   date_to     — 'YYYY-MM-DD' включительно
     *   sort        — ID | UF_SENT_AT | UF_LOCAL_DATE | UF_UID | UF_APPROVED
     *   sortDir     — ASC | DESC
     *   page, perPage, noPaging
     *
     * @return array{rows:array,total:int,page:int,perPage:int,pages:int}
     */
    public static function listForAdmin(array $params = []): array
    {
        $dc = Storage::dataClass('X5PwaReflections');

        $filter = [];

        if (!empty($params['search'])) {
            $filter['%UF_TEXT'] = '%' . $params['search'] . '%';
        }

        if (!empty($params['uid'])) {
            $filter['%UF_UID'] = '%' . $params['uid'] . '%';
        }

        $approved = (string)($params['approved'] ?? '');
        if ($approved === 'Y') {
            $filter['=UF_APPROVED'] = 'Y';
        } elseif ($approved === 'N') {
            $filter['!=UF_APPROVED'] = 'Y';
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
            'UF_APPROVED'   => 'UF_APPROVED',
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

    public static function setApproved(int $id, bool $approved, int $adminUserId): bool
    {
        $dc = Storage::dataClass('X5PwaReflections');
        $row = $dc::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        if (!$row) return false;

        $res = $dc::update($id, [
            'UF_APPROVED'    => $approved ? 'Y' : 'N',
            'UF_APPROVED_AT' => $approved ? new \Bitrix\Main\Type\DateTime() : null,
            'UF_APPROVED_BY' => $approved ? $adminUserId : 0,
        ]);

        return $res->isSuccess();
    }

    public static function getById(int $id): ?array
    {
        $dc  = Storage::dataClass('X5PwaReflections');
        $row = $dc::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    public static function resetCache(): void
    {
        self::$settingsCache = null;
    }
}