<?php
namespace X5\Pwa;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

Loader::includeModule('iblock');

class ApplesBuilder
{
    private const LOOKBACK_DAYS = 30;

    /**
     * @return array{
     *   items: array,
     *   daily_efficiency: array,
     *   total: int, page: int, perPage: int, pages: int
     * }
     */
    public static function getHistory(string $uid, int $page = 1, int $perPage = 50): array
    {
        $conn      = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid   = $sqlHelper->forSql($uid);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $items = [];

        // ─── 1. Начисления (rewarded) ───
        $rewardRows = $conn->query(
            "SELECT ID, UF_ACTION_TYPE, UF_ACTION_ID, UF_POINTS, UF_TITLE, UF_LOCAL_DATE, UF_CREATED_AT
             FROM x5_pwa_reward_log
             WHERE UF_UID = '{$safeUid}'
               AND UF_ACTION_TYPE IN ('task','lecture','lecture_general','lecture_theme','charging','habit','reflection','steps_sent','steps_approved','event')
             ORDER BY UF_CREATED_AT DESC
             LIMIT 500"
        )->fetchAll();

        $rewardedActionIds = [];
        foreach ($rewardRows as $r) {
            $rewardedActionIds[$r['UF_ACTION_TYPE'] . ':' . $r['UF_ACTION_ID']] = true;
        }

        // ─── 2. Собираем "сырые" записи для skipped (без enrichment) ───
        $skippedRaw = [];

        // 2.1. Tasks
        $taskRows = $conn->query(
            "SELECT ID, UF_TASK_ID, UF_COMPLETED_AT
             FROM x5_pwa_task_completions
             WHERE UF_UID = '{$safeUid}'
               AND UF_COMPLETED_AT >= DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_DAYS . " DAY)
             ORDER BY UF_COMPLETED_AT DESC
             LIMIT 200"
        )->fetchAll();

        foreach ($taskRows as $r) {
            $rawId     = (string)$r['UF_TASK_ID'];
            $elementId = RewardsBuilder::resolveElementIdFromAny($rawId, ['tasks']);
            if ($elementId === null) continue;

            $localDate = self::localDateFromTimestamp($r['UF_COMPLETED_AT'], $uid);
            $actionId  = $elementId . ':' . $localDate;
            if (isset($rewardedActionIds['task:' . $actionId])) continue;

            $skippedRaw[] = [
                'kind'       => 'task',
                'elementId'  => $elementId,
                'iblock_key' => 'tasks',
                'actionId'   => $actionId,
                'date'       => $localDate,
                'created_at' => self::fmtDt($r['UF_COMPLETED_AT']),
            ];
        }

        // 2.2. Lectures
        $readRows = $conn->query(
            "SELECT ID, UF_LECTURE_ID, UF_READ_AT
             FROM x5_pwa_lecture_reads
             WHERE UF_UID = '{$safeUid}'
               AND UF_READ_AT >= DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_DAYS . " DAY)
             ORDER BY UF_READ_AT DESC
             LIMIT 200"
        )->fetchAll();

        foreach ($readRows as $r) {
            $rawId     = (string)$r['UF_LECTURE_ID'];
            $elementId = RewardsBuilder::resolveElementIdFromAny(
                $rawId, ['general_lectures', 'charging', 'theme_lectures']
            );
            if ($elementId === null) continue;

            $localDate = self::localDateFromTimestamp($r['UF_READ_AT'], $uid);
            $actionId  = $elementId . ':' . $localDate;

            $types = ['lecture', 'lecture_theme', 'lecture_general', 'charging'];
            foreach ($types as $t) {
                if (isset($rewardedActionIds[$t . ':' . $actionId])) {
                    continue 2;
                }
            }

            $info = self::resolveLectureMeta($elementId);
            if ($info === null) continue;

            $skippedRaw[] = [
                'kind'       => 'lecture',
                'elementId'  => $elementId,
                'iblock_key' => $info['iblock_key'],
                'actionId'   => $actionId,
                'date'       => $localDate,
                'created_at' => self::fmtDt($r['UF_READ_AT']),
            ];
        }

        // 2.3. Steps (approved, без награды)
        $stepRows = $conn->query(
            "SELECT ID, UF_LOCAL_DATE, UF_SENT_AT
             FROM x5_pwa_steps
             WHERE UF_UID = '{$safeUid}'
               AND UF_STATUS = 'Y'
               AND UF_SENT_AT >= DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_DAYS . " DAY)
             ORDER BY UF_SENT_AT DESC
             LIMIT 100"
        )->fetchAll();

        foreach ($stepRows as $r) {
            $localDate = (string)$r['UF_LOCAL_DATE'];
            if (isset($rewardedActionIds['steps_approved:' . $localDate])) continue;

            $skippedRaw[] = [
                'kind'       => 'steps',
                'elementId'  => null,
                'iblock_key' => null,
                'actionId'   => $localDate,
                'date'       => $localDate,
                'created_at' => self::fmtDt($r['UF_SENT_AT']),
            ];
        }

        // 2.4. Events (confirmed, без награды)
        $eventRows = $conn->query(
            "SELECT ID, UF_EVENT_ID, UF_EVENT_TITLE, UF_CONFIRMED_AT
             FROM x5_pwa_event_actions
             WHERE UF_UID = '{$safeUid}'
               AND UF_CONFIRMED_AT IS NOT NULL
               AND UF_CONFIRMED_AT >= DATE_SUB(NOW(), INTERVAL " . self::LOOKBACK_DAYS . " DAY)
             ORDER BY UF_CONFIRMED_AT DESC
             LIMIT 100"
        )->fetchAll();

        foreach ($eventRows as $r) {
            $eventId = (string)$r['UF_EVENT_ID'];
            if (isset($rewardedActionIds['event:' . $eventId])) continue;

            $skippedRaw[] = [
                'kind'       => 'event',
                'elementId'  => null,
                'iblock_key' => null,
                'actionId'   => $eventId,
                'date'       => self::localDateFromTimestamp($r['UF_CONFIRMED_AT'], $uid),
                'created_at' => self::fmtDt($r['UF_CONFIRMED_AT']),
                'eventTitle' => (string)($r['UF_EVENT_TITLE'] ?? ''),
            ];
        }

        // ─── 3. Batch-резолв реальных CODE/NAME/BUCKET для rewarded ───
        $rewardedEnrich = self::enrichRewarded($rewardRows);

        // ─── 4. Batch-резолв реальных CODE/NAME/BUCKET для skipped ───
        $skippedEnrich = self::enrichSkipped($skippedRaw);

        // ─── 5. Финальная сборка ITEMS ───
        foreach ($rewardRows as $i => $r) {
            $meta = $rewardedEnrich[$i] ?? [];
            $items[] = self::buildItem([
                'id'         => (int)$r['ID'],
                'actionType' => (string)$r['UF_ACTION_TYPE'],
                'actionId'   => (string)$r['UF_ACTION_ID'],
                'date'       => (string)$r['UF_LOCAL_DATE'],
                'title'      => (string)$r['UF_TITLE'],
                'points'     => (int)$r['UF_POINTS'],
                'createdAt'  => !empty($r['UF_CREATED_AT']) ? $r['UF_CREATED_AT']->format('Y-m-d\TH:i:sP') : null,
                'status'     => 'rewarded',
                'reason'     => null,
                'meta'       => $meta,
            ]);
        }

        foreach ($skippedRaw as $i => $sr) {
            $meta = $skippedEnrich[$i] ?? [];
            $items[] = self::buildItem([
                'id'         => null,
                'actionType' => $sr['kind'],
                'actionId'   => $sr['actionId'],
                'date'       => $sr['date'],
                'title'      => $meta['title'] ?? ($sr['eventTitle'] ?? self::placeholderTitle($sr['kind'])),
                'points'     => 0,
                'createdAt'  => $sr['created_at'],
                'status'     => 'skipped',
                'reason'     => 'LIMIT_OR_NO_POINTS',
                'meta'       => $meta,
            ]);
        }

        // ─── 6. Сортировка и пагинация ───
        usort($items, function ($a, $b) {
            $da = $a['CREATED_AT'] ?? '';
            $db = $b['CREATED_AT'] ?? '';
            return strcmp($db, $da);
        });

        // ─── 6.1. DAILY_EFFICIENCY (не зависит от пагинации) ───
        $dailyEfficiency = self::buildDailyEfficiency($uid, $items);

        $total  = count($items);
        $pages  = $total > 0 ? (int)ceil($total / $perPage) : 1;
        $sliced = array_slice($items, $offset, $perPage);

        return [
            'items'            => $sliced,
            'daily_efficiency' => $dailyEfficiency,
            'total'            => $total,
            'page'             => $page,
            'perPage'          => $perPage,
            'pages'            => $pages,
        ];
    }

    /**
     * Собирает DAILY_EFFICIENCY для экрана «Эффективность по дням».
     *
     * Диапазон: [PLANNED_START .. min(PLANNED_END, today)].
     * При PERIOD_CONFIGURED === false (пустые настройки) → [].
     *
     * EARNED и ITEMS_COUNT — из $items (полного массива до пагинации),
     *   чтобы точно совпадать с блоком ITEMS.
     * MAX — из кэша X5PwaDailyApples через StatsBuilder::calculateDailyMaxForUserBatch
     *   (1-2 SQL на весь диапазон вместо N).
     * PERCENT:
     *   • null  — MAX = 0 (нет активностей в этот день);
     *   • 0.0   — MAX > 0, EARNED = 0 (были активности, но не выполнены);
     *   • round — иначе.
     *
     * Сортировка: desc (свежие сверху).
     *
     * TODO: при марафонах длиннее LOOKBACK_DAYS (=30) EARNED/ITEMS_COUNT
     *       могут оказаться обрезаны из-за лимитов в getHistory().
     *       Если марафон станет длиннее — вынести в отдельный SQL:
     *       SELECT UF_LOCAL_DATE, SUM(UF_POINTS) AS E, COUNT(*) AS C
     *       FROM x5_pwa_reward_log WHERE UF_UID = ? GROUP BY UF_LOCAL_DATE.
     */
    private static function buildDailyEfficiency(string $uid, array $items): array
    {
        $plannedStart = Settings::statsPeriodStart();
        $plannedEnd   = Settings::statsPeriodEnd();

        // PERIOD_CONFIGURED === false
        if ($plannedStart === '' || $plannedEnd === '') {
            return [];
        }

        $today = date('Y-m-d');
        $to    = min($plannedEnd, $today);

        // Марафон ещё не начался (PLANNED_START в будущем)
        if ($to < $plannedStart) {
            return [];
        }

        // Список дат [PLANNED_START .. to]
        $dates  = [];
        $cursor = strtotime($plannedStart);
        $endTs  = strtotime($to);
        if ($cursor === false || $endTs === false) return [];
        while ($cursor <= $endTs) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor  = strtotime('+1 day', $cursor);
        }
        if (!$dates) return [];

        // Группируем ITEMS по дате: EARNED = сумма POINTS, COUNT = число записей
        $agg = [];
        foreach ($items as $it) {
            $d = (string)($it['DATE'] ?? '');
            if ($d === '') continue;
            if (!isset($agg[$d])) $agg[$d] = ['earned' => 0, 'count' => 0];
            $agg[$d]['earned'] += (int)($it['POINTS'] ?? 0);
            $agg[$d]['count']++;
        }

        // MAX за весь диапазон — batch, без N SQL
        $maxByDate = StatsBuilder::calculateDailyMaxForUserBatch($uid, $dates);

        $out = [];
        foreach ($dates as $d) {
            $earned = (int)($agg[$d]['earned'] ?? 0);
            $count  = (int)($agg[$d]['count']  ?? 0);
            $max    = (int)($maxByDate[$d] ?? 0);

            if ($max <= 0) {
                $percent = null;
            } elseif ($earned <= 0) {
                $percent = 0.0;
            } else {
                $percent = round($earned / $max * 100, 2);
            }

            $out[] = [
                'DATE'        => $d,
                'EARNED'      => $earned,
                'MAX'         => $max,
                'PERCENT'     => $percent,
                'ITEMS_COUNT' => $count,
            ];
        }

        // Свежие сверху
        usort($out, fn($a, $b) => strcmp($b['DATE'], $a['DATE']));

        return $out;
    }

    /**
     * Обогащение rewarded-записей: SOURCE_ID, BUCKET, CODE.
     * Только для типов, где это осмысленно (task/lecture/lecture_theme/lecture_general/charging).
     */
    private static function enrichRewarded(array $rewardRows): array
    {
        // 1. Собираем (iblock_key, elementId) для всех rewarded
        $byIblock = []; // [iblock_key => [elementId => index_in_rewardRows]]
        foreach ($rewardRows as $i => $r) {
            $type     = (string)$r['UF_ACTION_TYPE'];
            $actionId = (string)$r['UF_ACTION_ID'];

            $iblockKey = match ($type) {
                'task'            => 'tasks',
                'lecture_general' => 'general_lectures',
                'charging'        => 'charging',
                'lecture',
                'lecture_theme'   => 'theme_lectures',
                default           => null,
            };
            if ($iblockKey === null) continue;

            if (!preg_match('/^(\d+):(\d{4}-\d{2}-\d{2})$/', $actionId, $m)) continue;
            $elementId = (int)$m[1];

            $byIblock[$iblockKey][$elementId] = $i;
        }

        if (!$byIblock) return [];

        // 2. По одному запросу на ИБ
        $elementsById = []; // [elementId => ['CODE'=>..,'NAME'=>..,'IBLOCK_KEY'=>..,'BUCKET'=>..]]
        foreach ($byIblock as $iblockKey => $elementIds) {
            $iblockId = (int)Settings::iblockId($iblockKey);
            if ($iblockId <= 0) continue;

            $rs = \CIBlockElement::GetList(
                [],
                ['ID' => array_keys($elementIds), 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                false,
                ['ID', 'CODE', 'NAME', 'PROPERTY_THEME']
            );
            while ($el = $rs->Fetch()) {
                $elId = (int)$el['ID'];
                $code = (string)($el['CODE'] ?: $el['ID']);

                $bucket = null;
                if ($iblockKey === 'tasks' || $iblockKey === 'theme_lectures') {
                    $themeRaw = trim((string)($el['PROPERTY_THEME_VALUE'] ?? ''));
                    $bucket   = $themeRaw !== '' ? (ThemesResolver::resolve($themeRaw) ?: 'unknown') : 'unknown';
                } elseif ($iblockKey === 'general_lectures') {
                    $bucket = 'general';
                } elseif ($iblockKey === 'charging') {
                    $bucket = 'charging';
                }

                $elementsById[$elId] = [
                    'CODE'       => $code,
                    'NAME'       => (string)$el['NAME'],
                    'IBLOCK_KEY' => $iblockKey,
                    'BUCKET'     => $bucket,
                ];
            }
        }

        // 3. Возвращаем метаданные в разрезе индексов rewardRows
        $out = [];
        foreach ($byIblock as $iblockKey => $elementIds) {
            foreach ($elementIds as $elementId => $rewardIdx) {
                if (isset($elementsById[$elementId])) {
                    $out[$rewardIdx] = $elementsById[$elementId];
                }
            }
        }
        return $out;
    }

    /**
     * Обогащение skipped-записей: SOURCE_ID, BUCKET, CODE, реальный TITLE.
     */
    private static function enrichSkipped(array $skippedRaw): array
    {
        // Собираем elementId по ИБ
        $byIblock = []; // [iblock_key => [elementId => [indexes_in_skippedRaw, ...]]]
        foreach ($skippedRaw as $i => $sr) {
            if ($sr['elementId'] === null || $sr['iblock_key'] === null) continue;
            $byIblock[$sr['iblock_key']][$sr['elementId']][] = $i;
        }

        if (!$byIblock) return [];

        $elementsById = [];
        foreach ($byIblock as $iblockKey => $elementIds) {
            $iblockId = (int)Settings::iblockId($iblockKey);
            if ($iblockId <= 0) continue;

            $rs = \CIBlockElement::GetList(
                [],
                ['ID' => array_keys($elementIds), 'IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                false,
                ['ID', 'CODE', 'NAME', 'PROPERTY_THEME']
            );
            while ($el = $rs->Fetch()) {
                $elId = (int)$el['ID'];
                $code = (string)($el['CODE'] ?: $el['ID']);

                $bucket = null;
                if ($iblockKey === 'tasks' || $iblockKey === 'theme_lectures') {
                    $themeRaw = trim((string)($el['PROPERTY_THEME_VALUE'] ?? ''));
                    $bucket   = $themeRaw !== '' ? (ThemesResolver::resolve($themeRaw) ?: 'unknown') : 'unknown';
                } elseif ($iblockKey === 'general_lectures') {
                    $bucket = 'general';
                } elseif ($iblockKey === 'charging') {
                    $bucket = 'charging';
                }

                $elementsById[$elId] = [
                    'CODE'       => $code,
                    'NAME'       => (string)$el['NAME'],
                    'IBLOCK_KEY' => $iblockKey,
                    'BUCKET'     => $bucket,
                ];
            }
        }

        $out = [];
        foreach ($byIblock as $iblockKey => $elementIds) {
            foreach ($elementIds as $elementId => $skippedIndexes) {
                if (!isset($elementsById[$elementId])) continue;
                $meta = $elementsById[$elementId];
                foreach ($skippedIndexes as $idx) {
                    $out[$idx] = $meta;
                }
            }
        }
        return $out;
    }

    /**
     * Собирает финальный ITEM.
     */
    private static function buildItem(array $p): array
    {
        $meta       = $p['meta'] ?? [];
        $actionType = $p['actionType'];
        $actionId   = $p['actionId'];
        $date       = $p['date'];

        $code   = $meta['CODE']       ?? null;
        $bucket = $meta['BUCKET']     ?? null;
        $title  = $meta['NAME']       ?? $p['title'];

        $sourceId = null;
        switch ($actionType) {
            case 'task':
            case 'lecture':
            case 'lecture_theme':
            case 'lecture_general':
            case 'charging':
                if ($code !== null && $date !== '') {
                    $sourceId = $code . '-' . $date;
                }
                break;

            case 'habit':
                // actionId = "{habitId}:{localDate}"
                $sourceId = $actionId;
                break;

            case 'event':
                $sourceId = $actionId;
                break;

            case 'steps':
            case 'steps_sent':
            case 'steps_approved':
            case 'reflection':
                $sourceId = $date;
                break;
        }

        return [
            'ID'         => $p['id'],
            'ACTION_ID'  => $actionId,      // внутренний (для дедупа/сопоставления)
            'SOURCE_ID'  => $sourceId,      // для клиента: ID_LECTURE/ID_TASK из CDN или ключ из конфига
            'BUCKET'     => $bucket,        // general / charging / {themeCode} / null
            'CODE'       => $code,          // CODE элемента ИБ / habitId / eventId / null
            'DATE'       => $date,
            'TYPE'       => $actionType,
            'TITLE'      => $title,
            'POINTS'     => $p['points'],
            'STATUS'     => $p['status'],
            'REASON'     => $p['reason'],
            'CREATED_AT' => $p['createdAt'],
        ];
    }

    private static function placeholderTitle(string $kind): string
    {
        return match ($kind) {
            'task'       => 'Задание выполнено (без награды)',
            'lecture'    => 'Лекция прочитана (без награды)',
            'steps'      => 'Шаги подтверждены (без награды)',
            'event'      => 'Мероприятие (без награды)',
            default      => 'Действие (без награды)',
        };
    }

    /**
     * Возвращает iblock_key для lecture-элемента (для enrichSkipped).
     */
    private static function resolveLectureMeta(int $elementId): ?array
    {
        $ibGeneral  = (int)Settings::iblockId('general_lectures');
        $ibCharging = (int)Settings::iblockId('charging');
        $ibTheme    = (int)Settings::iblockId('theme_lectures');

        $ibIds = array_values(array_filter([$ibGeneral, $ibCharging, $ibTheme]));
        if (!$ibIds) return null;

        $rs = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $elementId, 'IBLOCK_ID' => $ibIds, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_ID']
        );
        if (!$el = $rs->Fetch()) return null;

        $ibId = (int)$el['IBLOCK_ID'];
        $iblockKey = match ($ibId) {
            $ibGeneral  => 'general_lectures',
            $ibCharging => 'charging',
            $ibTheme    => 'theme_lectures',
            default     => null,
        };
        return $iblockKey ? ['iblock_key' => $iblockKey] : null;
    }

    private static function localDateFromTimestamp($dt, string $uid): string
    {
        $tz = Storage::getUserTzOffsetMin($uid);
        $ts = ($dt instanceof \Bitrix\Main\Type\DateTime)
            ? $dt->getTimestamp()
            : (is_string($dt) ? strtotime($dt) : 0);
        if ($ts <= 0) return '';
        $localMs = ($ts * 1000) + $tz * 60 * 1000;
        return gmdate('Y-m-d', (int)($localMs / 1000));
    }

    private static function fmtDt($dt): string
    {
        if ($dt instanceof \Bitrix\Main\Type\DateTime) return $dt->format('Y-m-d\TH:i:sP');
        if (is_string($dt)) return date('c', strtotime($dt));
        return '';
    }
}