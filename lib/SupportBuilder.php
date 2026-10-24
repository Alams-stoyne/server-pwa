<?php
namespace X5\Pwa;

use Bitrix\Main\Application;
use Bitrix\Main\Entity\ExpressionField;

class SupportBuilder
{
    public const MAX_IMAGES            = 5;
    public const MAX_IMAGE_BYTES       = 3 * 1024 * 1024;
    public const TICKET_PER_DAY_LIMIT  = 3;
    public const TICKET_PER_MIN_LIMIT  = 1;
    public const MESSAGES_PER_HOUR_LIMIT = 10;
    public const GUEST_TICKET_PER_IP_HOUR = 10;
    public const MESSAGE_MAX_LEN       = 5000;
    public const SUBJECT_MAX_LEN       = 255;

    public const ST_NEW      = 'new';
    public const ST_ANSWERED = 'answered';
    public const ST_CLOSED   = 'closed';

    public const ALL_STATUSES = [self::ST_NEW, self::ST_ANSWERED, self::ST_CLOSED];

    public const SRC_APP   = 'app';
    public const SRC_GUEST = 'app_unauth';

    public const AUTH_ERRORS = [
        'UID_TAKEN',
        'UID_LOCKED',
        'DEVICE_ROTATED',
        'BLOCKED',
        'RATE_LIMITED',
    ];

    /** Дефолтный URL для push-уведомления об ответе (плейсхолдер {ID} → номер тикета) */
    private const DEFAULT_PUSH_URL_TPL = '/support/tickets/{ID}';

    // ───────── Создание тикета (авторизованный) ─────────

    public static function createTicket(string $uid, string $subject, string $text, array $imagesRaw): array
    {
        $uid     = trim($uid);
        $subject = trim($subject);
        $text    = trim($text);

        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }
        if ($subject === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'SUBJECT required'];
        }
        if ($text === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX_LEN) {
            $subject = mb_substr($subject, 0, self::SUBJECT_MAX_LEN);
        }
        if (mb_strlen($text) > self::MESSAGE_MAX_LEN) {
            $text = mb_substr($text, 0, self::MESSAGE_MAX_LEN);
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }
        if (!Storage::getUidBinding($uid)) {
            return ['RESULT' => 'UID_NOT_FOUND', 'MESSAGE' => 'Пользователь не найден.'];
        }

        $err = self::checkCreateRateLimit($uid);
        if ($err !== null) return $err;

        $decoded = self::decodeImages($imagesRaw);
        if (isset($decoded['error'])) return $decoded['error'];
        $images = $decoded['images'] ?? [];

        $fileIds = [];
        foreach ($images as $idx => $d) {
            $fid = self::saveImage($uid, $d, $idx);
            if ($fid === null) {
                foreach ($fileIds as $f) \CFile::Delete($f);
                return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить фото.'];
            }
            $fileIds[] = $fid;
        }

        $now = new \Bitrix\Main\Type\DateTime();
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');

        $res = $ticketsDc::add([
            'UF_UID'            => $uid,
            'UF_DEVICE_ID'      => '',
            'UF_SOURCE'         => self::SRC_APP,
            'UF_AUTH_ERROR'     => '',
            'UF_META'           => '',
            'UF_SUBJECT'        => $subject,
            'UF_STATUS'         => self::ST_NEW,
            'UF_CREATED_AT'     => $now,
            'UF_UPDATED_AT'     => $now,
            'UF_MESSAGES_COUNT' => 1,
            'UF_IP'             => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'UF_UA'             => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        if (!$res->isSuccess()) {
            foreach ($fileIds as $f) \CFile::Delete($f);
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось создать тикет.'];
        }
        $ticketId = (int)$res->getId();

        $msgDc = Storage::dataClass('X5PwaSupportMessages');
        $msgRes = $msgDc::add([
            'UF_TICKET_ID'  => $ticketId,
            'UF_UID'        => $uid,
            'UF_IS_ADMIN'   => 'N',
            'UF_ADMIN_ID'   => 0,
            'UF_TEXT'       => $text,
            'UF_IMAGES'     => json_encode($fileIds, JSON_UNESCAPED_UNICODE),
            'UF_CREATED_AT' => $now,
        ]);
        if (!$msgRes->isSuccess()) {
            $ticketsDc::delete($ticketId);
            foreach ($fileIds as $f) \CFile::Delete($f);
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить сообщение.'];
        }

        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'TICKET_ID'       => $ticketId,
            'MESSAGE_ID'      => (int)$msgRes->getId(),
            'SERVER_DATETIME' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    // ───────── Создание тикета (гость) ─────────

    public static function createGuestTicket(
        string $deviceId,
        ?string $uid,
        ?string $authError,
        ?string $subject,
        string $text,
        array $imagesRaw,
        array $meta = []
    ): array {
        $deviceId = trim($deviceId);
        $text     = trim($text);

        if ($deviceId === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID required'];
        }
        if (mb_strlen($deviceId) > 64) $deviceId = mb_substr($deviceId, 0, 64);

        if ($text === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];
        }
        if (mb_strlen($text) > self::MESSAGE_MAX_LEN) {
            $text = mb_substr($text, 0, self::MESSAGE_MAX_LEN);
        }

        $uid = trim((string)$uid);
        if ($uid !== '' && mb_strlen($uid) > 40) {
            $uid = mb_substr($uid, 0, 40);
        }

        $authError = trim((string)$authError);
        if ($authError !== '' && mb_strlen($authError) > 32) {
            $authError = mb_substr($authError, 0, 32);
        }

        $subject = trim((string)$subject);
        if ($subject === '') {
            $subject = self::makeGuestSubject($authError);
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX_LEN) {
            $subject = mb_substr($subject, 0, self::SUBJECT_MAX_LEN);
        }

        $err = self::checkGuestRateLimit($deviceId);
        if ($err !== null) return $err;

        $decoded = self::decodeImages($imagesRaw);
        if (isset($decoded['error'])) return $decoded['error'];
        $images = $decoded['images'] ?? [];

        $fileIds = [];
        foreach ($images as $idx => $d) {
            $fid = self::saveImage($deviceId, $d, $idx);
            if ($fid === null) {
                foreach ($fileIds as $f) \CFile::Delete($f);
                return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить фото.'];
            }
            $fileIds[] = $fid;
        }

        $metaJson = '';
        if ($meta) {
            $metaJson = (string)json_encode($meta, JSON_UNESCAPED_UNICODE);
            if (mb_strlen($metaJson) > 2000) {
                $metaJson = mb_substr($metaJson, 0, 2000);
            }
        }

        $now = new \Bitrix\Main\Type\DateTime();
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');

        $res = $ticketsDc::add([
            'UF_UID'            => $uid,
            'UF_DEVICE_ID'      => $deviceId,
            'UF_SOURCE'         => self::SRC_GUEST,
            'UF_AUTH_ERROR'     => $authError,
            'UF_META'           => $metaJson,
            'UF_SUBJECT'        => $subject,
            'UF_STATUS'         => self::ST_NEW,
            'UF_CREATED_AT'     => $now,
            'UF_UPDATED_AT'     => $now,
            'UF_MESSAGES_COUNT' => 1,
            'UF_IP'             => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'UF_UA'             => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        if (!$res->isSuccess()) {
            foreach ($fileIds as $f) \CFile::Delete($f);
            \Bitrix\Main\Diag\Debug::writeToFile(
                'guest ticket add failed: ' . implode('; ', $res->getErrorMessages()),
                'SupportBuilder::createGuestTicket',
                '/x5.pwa.log'
            );
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось создать тикет.'];
        }
        $ticketId = (int)$res->getId();

        $msgDc = Storage::dataClass('X5PwaSupportMessages');
        $msgRes = $msgDc::add([
            'UF_TICKET_ID'  => $ticketId,
            'UF_UID'        => $uid,
            'UF_IS_ADMIN'   => 'N',
            'UF_ADMIN_ID'   => 0,
            'UF_TEXT'       => $text,
            'UF_IMAGES'     => json_encode($fileIds, JSON_UNESCAPED_UNICODE),
            'UF_CREATED_AT' => $now,
        ]);
        if (!$msgRes->isSuccess()) {
            $ticketsDc::delete($ticketId);
            foreach ($fileIds as $f) \CFile::Delete($f);
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить сообщение.'];
        }

        \Bitrix\Main\Diag\Debug::writeToFile(
            sprintf(
                'guest ticket #%d device=%s auth_error=%s uid=%s',
                $ticketId, $deviceId,
                $authError !== '' ? $authError : '-',
                $uid !== '' ? substr($uid, 0, 12) . '…' : '-'
            ),
            'SupportBuilder::createGuestTicket',
            '/x5.pwa.log'
        );

        return [
            'RESULT'          => 'OK',
            'TICKET_ID'       => $ticketId,
            'MESSAGE_ID'      => (int)$msgRes->getId(),
            'SERVER_DATETIME' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    private static function makeGuestSubject(string $authError): string
    {
        switch ($authError) {
            case 'UID_TAKEN':      return 'UID уже активирован на другом устройстве';
            case 'UID_LOCKED':     return 'Устройство привязано к другому UID';
            case 'DEVICE_ROTATED': return 'Смена устройства';
            case 'BLOCKED':        return 'Доступ заблокирован';
            case 'RATE_LIMITED':   return 'Превышен лимит попыток входа';
        }
        return 'Проблема при авторизации (без UID)';
    }

    // ───────── Сообщение от пользователя ─────────

    public static function addUserMessage(string $uid, int $ticketId, string $text, array $imagesRaw): array
    {
        $uid  = trim($uid);
        $text = trim($text);

        if ($uid === '' || !preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid ID_USER'];
        }
        if ($ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TICKET_ID required'];
        }
        if ($text === '' && empty($imagesRaw)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT or IMAGES required'];
        }
        if (mb_strlen($text) > self::MESSAGE_MAX_LEN) {
            $text = mb_substr($text, 0, self::MESSAGE_MAX_LEN);
        }

        if (Storage::isBlocked($uid)) {
            return ['RESULT' => 'BLOCKED', 'MESSAGE' => 'Доступ приостановлен.'];
        }

        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList([
            'filter' => ['=ID' => $ticketId, '=UF_UID' => $uid],
            'limit'  => 1,
        ])->fetch();
        if (!$ticket) {
            return ['RESULT' => 'TICKET_NOT_FOUND', 'MESSAGE' => 'Обращение не найдено.'];
        }
        if (($ticket['UF_SOURCE'] ?? self::SRC_APP) === self::SRC_GUEST) {
            return ['RESULT' => 'IS_GUEST_TICKET', 'MESSAGE' => 'Это гостевой тикет. Используйте /support/ticket/guest_message.'];
        }
        if (($ticket['UF_STATUS'] ?? '') === self::ST_CLOSED) {
            return ['RESULT' => 'TICKET_CLOSED', 'MESSAGE' => 'Обращение закрыто.'];
        }

        $err = self::checkMessageRateLimit($ticketId);
        if ($err !== null) return $err;

        $decoded = self::decodeImages($imagesRaw);
        if (isset($decoded['error'])) return $decoded['error'];
        $images = $decoded['images'] ?? [];

        $fileIds = [];
        foreach ($images as $idx => $d) {
            $fid = self::saveImage($uid, $d, $idx);
            if ($fid === null) {
                foreach ($fileIds as $f) \CFile::Delete($f);
                return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить фото.'];
            }
            $fileIds[] = $fid;
        }

        $now = new \Bitrix\Main\Type\DateTime();
        $msgDc = Storage::dataClass('X5PwaSupportMessages');

        $msgRes = $msgDc::add([
            'UF_TICKET_ID'  => $ticketId,
            'UF_UID'        => $uid,
            'UF_IS_ADMIN'   => 'N',
            'UF_ADMIN_ID'   => 0,
            'UF_TEXT'       => $text,
            'UF_IMAGES'     => json_encode($fileIds, JSON_UNESCAPED_UNICODE),
            'UF_CREATED_AT' => $now,
        ]);
        if (!$msgRes->isSuccess()) {
            foreach ($fileIds as $f) \CFile::Delete($f);
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить сообщение.'];
        }

        $ticketsDc::update($ticketId, [
            'UF_STATUS'         => self::ST_NEW,
            'UF_UPDATED_AT'     => $now,
            'UF_MESSAGES_COUNT' => (int)($ticket['UF_MESSAGES_COUNT'] ?? 0) + 1,
        ]);

        Storage::touchBindingByUid($uid);

        return [
            'RESULT'          => 'OK',
            'TICKET_ID'       => $ticketId,
            'MESSAGE_ID'      => (int)$msgRes->getId(),
            'SERVER_DATETIME' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    // ───────── Сообщение от гостя ─────────

    public static function addGuestMessage(string $deviceId, int $ticketId, string $text, array $imagesRaw): array
    {
        $deviceId = trim($deviceId);
        $text     = trim($text);

        if ($deviceId === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID required'];
        }
        if (mb_strlen($deviceId) > 64) $deviceId = mb_substr($deviceId, 0, 64);

        if ($ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TICKET_ID required'];
        }
        if ($text === '' && empty($imagesRaw)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT or IMAGES required'];
        }
        if (mb_strlen($text) > self::MESSAGE_MAX_LEN) {
            $text = mb_substr($text, 0, self::MESSAGE_MAX_LEN);
        }

        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList([
            'filter' => ['=ID' => $ticketId, '=UF_DEVICE_ID' => $deviceId],
            'limit'  => 1,
        ])->fetch();
        if (!$ticket) {
            return ['RESULT' => 'TICKET_NOT_FOUND', 'MESSAGE' => 'Обращение не найдено.'];
        }
        if (($ticket['UF_SOURCE'] ?? '') !== self::SRC_GUEST) {
            return ['RESULT' => 'NOT_GUEST_TICKET', 'MESSAGE' => 'Это не гостевой тикет.'];
        }
        if (($ticket['UF_STATUS'] ?? '') === self::ST_CLOSED) {
            return ['RESULT' => 'TICKET_CLOSED', 'MESSAGE' => 'Обращение закрыто.'];
        }

        $err = self::checkMessageRateLimit($ticketId);
        if ($err !== null) return $err;

        $decoded = self::decodeImages($imagesRaw);
        if (isset($decoded['error'])) return $decoded['error'];
        $images = $decoded['images'] ?? [];

        $fileIds = [];
        foreach ($images as $idx => $d) {
            $fid = self::saveImage($deviceId, $d, $idx);
            if ($fid === null) {
                foreach ($fileIds as $f) \CFile::Delete($f);
                return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить фото.'];
            }
            $fileIds[] = $fid;
        }

        $now = new \Bitrix\Main\Type\DateTime();
        $msgDc = Storage::dataClass('X5PwaSupportMessages');

        $msgRes = $msgDc::add([
            'UF_TICKET_ID'  => $ticketId,
            'UF_UID'        => (string)$ticket['UF_UID'],
            'UF_IS_ADMIN'   => 'N',
            'UF_ADMIN_ID'   => 0,
            'UF_TEXT'       => $text,
            'UF_IMAGES'     => json_encode($fileIds, JSON_UNESCAPED_UNICODE),
            'UF_CREATED_AT' => $now,
        ]);
        if (!$msgRes->isSuccess()) {
            foreach ($fileIds as $f) \CFile::Delete($f);
            return ['RESULT' => 'SAVE_ERROR', 'MESSAGE' => 'Не удалось сохранить сообщение.'];
        }

        $ticketsDc::update($ticketId, [
            'UF_STATUS'         => self::ST_NEW,
            'UF_UPDATED_AT'     => $now,
            'UF_MESSAGES_COUNT' => (int)($ticket['UF_MESSAGES_COUNT'] ?? 0) + 1,
        ]);

        return [
            'RESULT'          => 'OK',
            'TICKET_ID'       => $ticketId,
            'MESSAGE_ID'      => (int)$msgRes->getId(),
            'SERVER_DATETIME' => $now->format('Y-m-d\TH:i:sP'),
        ];
    }

    // ───────── Ответ администратора ─────────

    public static function addAdminMessage(int $ticketId, int $adminId, string $text): array
    {
        $text = trim($text);
        if ($ticketId <= 0) {
            return ['ok' => false, 'error' => 'TICKET_ID required'];
        }
        if ($text === '') {
            return ['ok' => false, 'error' => 'TEXT required'];
        }
        if (mb_strlen($text) > self::MESSAGE_MAX_LEN) {
            $text = mb_substr($text, 0, self::MESSAGE_MAX_LEN);
        }

        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList(['filter' => ['=ID' => $ticketId], 'limit' => 1])->fetch();
        if (!$ticket) {
            return ['ok' => false, 'error' => 'TICKET_NOT_FOUND'];
        }

        $isGuest = ($ticket['UF_SOURCE'] ?? self::SRC_APP) === self::SRC_GUEST;

        $now = new \Bitrix\Main\Type\DateTime();
        $msgDc = Storage::dataClass('X5PwaSupportMessages');

        $msgRes = $msgDc::add([
            'UF_TICKET_ID'  => $ticketId,
            'UF_UID'        => (string)$ticket['UF_UID'],
            'UF_IS_ADMIN'   => 'Y',
            'UF_ADMIN_ID'   => $adminId,
            'UF_TEXT'       => $text,
            'UF_IMAGES'     => '[]',
            'UF_CREATED_AT' => $now,
        ]);
        if (!$msgRes->isSuccess()) {
            return ['ok' => false, 'error' => 'DB_ERROR'];
        }

        $ticketsDc::update($ticketId, [
            'UF_STATUS'         => self::ST_ANSWERED,
            'UF_UPDATED_AT'     => $now,
            'UF_MESSAGES_COUNT' => (int)($ticket['UF_MESSAGES_COUNT'] ?? 0) + 1,
            'UF_CLOSED_AT'      => null,
            'UF_CLOSED_BY'      => 0,
        ]);

        // Push отправляем только для авторизованных тикетов
        if (!$isGuest && !empty($ticket['UF_UID'])) {
            self::pushUser((string)$ticket['UF_UID'], $ticketId, (string)($ticket['UF_SUBJECT'] ?? ''));
        } else {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'guest ticket #' . $ticketId . ' — push skipped (no auth)',
                'SupportBuilder::addAdminMessage',
                '/x5.pwa.log'
            );
        }

        return [
            'ok'        => true,
            'messageId' => (int)$msgRes->getId(),
            'createdAt' => $now->format('Y-m-d\TH:i:sP'),
            'isGuest'   => $isGuest,
        ];
    }

    // ───────── Закрытие / удаление ─────────

    public static function closeTicket(int $ticketId, int $adminId): bool
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList(['filter' => ['=ID' => $ticketId], 'limit' => 1])->fetch();
        if (!$ticket) return false;

        $now = new \Bitrix\Main\Type\DateTime();
        $res = $ticketsDc::update($ticketId, [
            'UF_STATUS'    => self::ST_CLOSED,
            'UF_CLOSED_AT' => $now,
            'UF_CLOSED_BY' => $adminId,
            'UF_UPDATED_AT'=> $now,
        ]);
        return $res->isSuccess();
    }

    public static function deleteTicket(int $ticketId): bool
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList(['filter' => ['=ID' => $ticketId], 'limit' => 1])->fetch();
        if (!$ticket) return false;

        $msgDc = Storage::dataClass('X5PwaSupportMessages');
        $msgs = $msgDc::getList([
            'select' => ['ID', 'UF_IMAGES'],
            'filter' => ['=UF_TICKET_ID' => $ticketId],
        ])->fetchAll();

        foreach ($msgs as $m) {
            $ids = json_decode((string)$m['UF_IMAGES'], true) ?: [];
            foreach ($ids as $fid) {
                $fid = (int)$fid;
                if ($fid > 0) \CFile::Delete($fid);
            }
            $msgDc::delete((int)$m['ID']);
        }

        $res = $ticketsDc::delete($ticketId);
        return $res->isSuccess();
    }

    // ───────── Чтение ─────────

    public static function getTicket(int $ticketId, ?string $uid = null): ?array
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $filter = ['=ID' => $ticketId];
        if ($uid !== null) $filter['=UF_UID'] = $uid;

        $ticket = $ticketsDc::getList(['filter' => $filter, 'limit' => 1])->fetch();
        if (!$ticket) return null;

        return [
            'TICKET'   => self::normalizeTicket($ticket),
            'MESSAGES' => self::getMessages($ticketId),
        ];
    }

    public static function getGuestTicket(int $ticketId, string $deviceId): ?array
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $ticket = $ticketsDc::getList([
            'filter' => [
                '=ID'           => $ticketId,
                '=UF_DEVICE_ID' => $deviceId,
                '=UF_SOURCE'    => self::SRC_GUEST,
            ],
            'limit' => 1,
        ])->fetch();
        if (!$ticket) return null;

        return [
            'TICKET'   => self::normalizeTicket($ticket),
            'MESSAGES' => self::getMessages($ticketId),
        ];
    }

    public static function getMessages(int $ticketId): array
    {
        $msgDc = Storage::dataClass('X5PwaSupportMessages');
        $rows = $msgDc::getList([
            'filter' => ['=UF_TICKET_ID' => $ticketId],
            'order'  => ['UF_CREATED_AT' => 'ASC', 'ID' => 'ASC'],
        ])->fetchAll();

        $out = [];
        foreach ($rows as $m) {
            $out[] = [
                'ID'         => (int)$m['ID'],
                'IS_ADMIN'   => ($m['UF_IS_ADMIN'] ?? 'N') === 'Y',
                'TEXT'       => (string)($m['UF_TEXT'] ?? ''),
                'IMAGES'     => self::expandImages((string)($m['UF_IMAGES'] ?? '')),
                'CREATED_AT' => self::fmtDt($m['UF_CREATED_AT'] ?? null),
            ];
        }
        return $out;
    }

    public static function normalizeTicket(array $t): array
    {
        return [
            'ID'             => (int)$t['ID'],
            'UID'            => (string)($t['UF_UID'] ?? ''),
            'DEVICE_ID'      => (string)($t['UF_DEVICE_ID'] ?? ''),
            'SOURCE'         => (string)($t['UF_SOURCE'] ?? self::SRC_APP),
            'AUTH_ERROR'     => (string)($t['UF_AUTH_ERROR'] ?? ''),
            'META'           => (string)($t['UF_META'] ?? ''),
            'SUBJECT'        => (string)($t['UF_SUBJECT'] ?? ''),
            'STATUS'         => (string)($t['UF_STATUS'] ?? ''),
            'MESSAGES_COUNT' => (int)($t['UF_MESSAGES_COUNT'] ?? 0),
            'CREATED_AT'     => self::fmtDt($t['UF_CREATED_AT'] ?? null),
            'UPDATED_AT'     => self::fmtDt($t['UF_UPDATED_AT'] ?? null),
            'CLOSED_AT'      => self::fmtDt($t['UF_CLOSED_AT'] ?? null),
            'IP'             => (string)($t['UF_IP'] ?? ''),
            'UA'             => (string)($t['UF_UA'] ?? ''),
        ];
    }

    // ───────── Списки ─────────

    public static function listForUser(string $uid, ?string $status, int $page = 1, int $perPage = 20): array
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $filter = ['=UF_UID' => $uid];
        if ($status && in_array($status, self::ALL_STATUSES, true)) {
            $filter['=UF_STATUS'] = $status;
        }

        $total = (int)($ticketsDc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch()['CNT'] ?? 0);

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $rows = $ticketsDc::getList([
            'filter' => $filter,
            'order'  => ['UF_UPDATED_AT' => 'DESC', 'ID' => 'DESC'],
            'limit'  => $perPage,
            'offset' => ($page - 1) * $perPage,
        ])->fetchAll();

        return [
            'TICKETS'  => array_map([self::class, 'normalizeTicket'], $rows),
            'TOTAL'    => $total,
            'PAGE'     => $page,
            'PER_PAGE' => $perPage,
            'PAGES'    => (int)ceil($total / $perPage),
        ];
    }

    public static function listForAdmin(array $params = []): array
    {
        $ticketsDc = Storage::dataClass('X5PwaSupportTickets');
        $filter = [];

        $status = (string)($params['status'] ?? '');
        if (in_array($status, self::ALL_STATUSES, true)) {
            $filter['=UF_STATUS'] = $status;
        }

        $source = (string)($params['source'] ?? '');
        if (in_array($source, [self::SRC_APP, self::SRC_GUEST], true)) {
            $filter['=UF_SOURCE'] = $source;
        }

        if (!empty($params['date_from'])) {
            try { $filter['>=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime((string)$params['date_from'] . ' 00:00:00'); } catch (\Throwable $e) {}
        }
        if (!empty($params['date_to'])) {
            try { $filter['<=UF_CREATED_AT'] = new \Bitrix\Main\Type\DateTime((string)$params['date_to'] . ' 23:59:59'); } catch (\Throwable $e) {}
        }
        if (!empty($params['upd_from'])) {
            try { $filter['>=UF_UPDATED_AT'] = new \Bitrix\Main\Type\DateTime((string)$params['upd_from'] . ' 00:00:00'); } catch (\Throwable $e) {}
        }
        if (!empty($params['upd_to'])) {
            try { $filter['<=UF_UPDATED_AT'] = new \Bitrix\Main\Type\DateTime((string)$params['upd_to'] . ' 23:59:59'); } catch (\Throwable $e) {}
        }

        if (!empty($params['user'])) {
            $query = (string)$params['user'];
            $uids  = self::findUidsByUserQuery($query);

            $like = '%' . $query . '%';
            $deviceMatches = $ticketsDc::getList([
                'select' => ['ID'],
                'filter' => ['%UF_DEVICE_ID' => $like],
            ])->fetchAll();
            $deviceIds = array_map('intval', array_column($deviceMatches, 'ID'));

            if (!$uids && !$deviceIds) {
                return ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 50, 'pages' => 1];
            }

            $orFilter = ['LOGIC' => 'OR'];
            if ($uids)      $orFilter['=UF_UID'] = $uids;
            if ($deviceIds) $orFilter['=ID']     = $deviceIds;
            $filter[] = $orFilter;
        }

        $sortMap = [
            'ID'            => 'ID',
            'UF_CREATED_AT' => 'UF_CREATED_AT',
            'UF_UPDATED_AT' => 'UF_UPDATED_AT',
            'UF_STATUS'     => 'UF_STATUS',
            'UF_UID'        => 'UF_UID',
        ];
        $sortKey = $sortMap[$params['sort'] ?? 'UF_UPDATED_AT'] ?? 'UF_UPDATED_AT';
        $sortDir = strtoupper((string)($params['sortDir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $order   = [$sortKey => $sortDir, 'ID' => 'DESC'];

        $noPaging = !empty($params['noPaging']) || (int)($params['perPage'] ?? -1) === 0;
        $page     = max(1, (int)($params['page'] ?? 1));
        $perPage  = $noPaging ? 0 : max(1, min(200, (int)($params['perPage'] ?? 50)));
        $offset   = $noPaging ? 0 : ($page - 1) * $perPage;

        $total = (int)($ticketsDc::getList([
            'select'  => ['CNT'],
            'filter'  => $filter,
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch()['CNT'] ?? 0);

        $qParams = ['filter' => $filter, 'order' => $order];
        if (!$noPaging) {
            $qParams['limit']  = $perPage;
            $qParams['offset'] = $offset;
        }
        $rows = $ticketsDc::getList($qParams)->fetchAll();

        $uids = array_values(array_unique(array_filter(array_column($rows, 'UF_UID'))));
        $users = [];
        if ($uids) {
            try {
                $bdc = Storage::dataClass('X5PwaUidBindings');
                $brs = $bdc::getList([
                    'select' => ['UF_UID', 'UF_NICKNAME', 'UF_FIO', 'UF_WORKPLACE'],
                    'filter' => ['=UF_UID' => $uids],
                ])->fetchAll();
                foreach ($brs as $b) $users[$b['UF_UID']] = $b;
            } catch (\Throwable $e) {}
        }

        $out = [];
        foreach ($rows as $t) {
            $uid  = (string)($t['UF_UID'] ?? '');
            $user = $users[$uid] ?? [];
            $source = (string)($t['UF_SOURCE'] ?? self::SRC_APP);
            $out[] = [
                'ID'             => (int)$t['ID'],
                'UID'            => $uid,
                'DEVICE_ID'      => (string)($t['UF_DEVICE_ID'] ?? ''),
                'SOURCE'         => $source,
                'AUTH_ERROR'     => (string)($t['UF_AUTH_ERROR'] ?? ''),
                'NICKNAME'       => (string)($user['UF_NICKNAME'] ?? ''),
                'FIO'            => (string)($user['UF_FIO'] ?? ''),
                'WORKPLACE'      => (string)($user['UF_WORKPLACE'] ?? ''),
                'SUBJECT'        => (string)($t['UF_SUBJECT'] ?? ''),
                'STATUS'         => (string)($t['UF_STATUS'] ?? ''),
                'MESSAGES_COUNT' => (int)($t['UF_MESSAGES_COUNT'] ?? 0),
                'CREATED_AT'     => !empty($t['UF_CREATED_AT']) ? $t['UF_CREATED_AT']->getTimestamp() * 1000 : 0,
                'UPDATED_AT'     => !empty($t['UF_UPDATED_AT']) ? $t['UF_UPDATED_AT']->getTimestamp() * 1000 : 0,
                'CLOSED_AT'      => !empty($t['UF_CLOSED_AT'])  ? $t['UF_CLOSED_AT']->getTimestamp()  * 1000 : 0,
            ];
        }

        return [
            'rows'    => $out,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $noPaging ? 1 : ($total > 0 ? (int)ceil($total / $perPage) : 1),
        ];
    }

    private static function findUidsByUserQuery(string $query): array
    {
        $query = trim($query);
        if ($query === '') return [];
        try {
            $bdc = Storage::dataClass('X5PwaUidBindings');
            $like = '%' . $query . '%';
            $rows = $bdc::getList([
                'select' => ['UF_UID'],
                'filter' => [
                    'LOGIC'        => 'OR',
                    '%UF_UID'      => $like,
                    '%UF_NICKNAME' => $like,
                    '%UF_FIO'      => $like,
                ],
            ])->fetchAll();
            return array_column($rows, 'UF_UID');
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ───────── Изображения ─────────

    private static function decodeImages(array $raw): array
    {
        if (!$raw) return ['images' => []];
        if (!is_array($raw)) {
            return ['error' => ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'IMAGES must be array']];
        }
        if (count($raw) > self::MAX_IMAGES) {
            return ['error' => ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Не более ' . self::MAX_IMAGES . ' фото.']];
        }

        $out = [];
        foreach ($raw as $idx => $item) {
            if (!is_string($item)) {
                return ['error' => ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Фото #' . ($idx + 1) . ' не строка.']];
            }
            $d = self::decodeImageString($item);
            if ($d === null) {
                return ['error' => ['RESULT' => 'INVALID_IMAGES', 'MESSAGE' => 'Фото #' . ($idx + 1) . ' некорректно (тип/размер).']];
            }
            $out[] = $d;
        }
        return ['images' => $out];
    }

    private static function decodeImageString(string $raw): ?array
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

    private static function saveImage(string $ownerKey, array $decoded, int $idx): ?int
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

        $tmp = tempnam(sys_get_temp_dir(), 'x5sup_');
        if ($tmp === false) return null;
        if (file_put_contents($tmp, $decoded['binary']) === false) {
            @unlink($tmp);
            return null;
        }

        $shortKey = substr(preg_replace('/[^a-z0-9]/i', '', $ownerKey) ?: 'u', 0, 12);
        $fileId = \CFile::SaveFile([
            'name'     => "sup_{$shortKey}_" . time() . "_{$idx}.{$ext}",
            'type'     => $mime,
            'tmp_name' => $tmp,
            'size'     => strlen($decoded['binary']),
            'error'    => 0,
        ], 'x5support');
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
            $thumbArr = \CFile::ResizeImageGet($id, ['width' => 200, 'height' => 200], BX_RESIZE_IMAGE_PROPORTIONAL);
            $thumb = $thumbArr && !empty($thumbArr['src']) ? $thumbArr['src'] : $url;
            $out[] = ['ID' => $id, 'URL' => $url, 'THUMB' => $thumb];
        }
        return $out;
    }

    // ───────── Rate limits ─────────

    private static function checkCreateRateLimit(string $uid): ?array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeUid = $sqlHelper->forSql($uid);

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
             WHERE UF_UID = '{$safeUid}'
               AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        )->fetch();
        if ((int)($row['C'] ?? 0) >= self::TICKET_PER_MIN_LIMIT) {
            return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Слишком часто. Попробуйте через минуту.'];
        }

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
             WHERE UF_UID = '{$safeUid}'
               AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetch();
        if ((int)($row['C'] ?? 0) >= self::TICKET_PER_DAY_LIMIT) {
            return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Не более ' . self::TICKET_PER_DAY_LIMIT . ' обращений в сутки.'];
        }
        return null;
    }

    private static function checkGuestRateLimit(string $deviceId): ?array
    {
        $conn = Application::getConnection();
        $sqlHelper = $conn->getSqlHelper();
        $safeDevice = $sqlHelper->forSql($deviceId);

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
             WHERE UF_DEVICE_ID = '{$safeDevice}'
               AND UF_SOURCE = 'app_unauth'
               AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        )->fetch();
        if ((int)($row['C'] ?? 0) >= self::TICKET_PER_MIN_LIMIT) {
            return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Слишком часто. Попробуйте через минуту.'];
        }

        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
             WHERE UF_DEVICE_ID = '{$safeDevice}'
               AND UF_SOURCE = 'app_unauth'
               AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetch();
        if ((int)($row['C'] ?? 0) >= self::TICKET_PER_DAY_LIMIT) {
            return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Не более ' . self::TICKET_PER_DAY_LIMIT . ' обращений в сутки.'];
        }

        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '') {
            $safeIp = $sqlHelper->forSql($ip);
            $row = $conn->query(
                "SELECT COUNT(*) AS C FROM x5_pwa_support_tickets
                 WHERE UF_IP = '{$safeIp}'
                   AND UF_SOURCE = 'app_unauth'
                   AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            )->fetch();
            if ((int)($row['C'] ?? 0) >= self::GUEST_TICKET_PER_IP_HOUR) {
                return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Слишком много обращений с этого IP. Попробуйте позже.'];
            }
        }

        return null;
    }

    private static function checkMessageRateLimit(int $ticketId): ?array
    {
        $conn = Application::getConnection();
        $row = $conn->query(
            "SELECT COUNT(*) AS C FROM x5_pwa_support_messages
             WHERE UF_TICKET_ID = {$ticketId}
               AND UF_IS_ADMIN = 'N'
               AND UF_CREATED_AT > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->fetch();
        if ((int)($row['C'] ?? 0) >= self::MESSAGES_PER_HOUR_LIMIT) {
            return ['RESULT' => 'RATE_LIMITED', 'MESSAGE' => 'Не более ' . self::MESSAGES_PER_HOUR_LIMIT . ' сообщений в час.'];
        }
        return null;
    }

    // ───────── Push ─────────

    /**
     * Отправка push-уведомления пользователю об ответе поддержки.
     *
     * ЕДИНСТВЕННАЯ КОПИЯ МЕТОДА. Если случайно появится вторая — PHP
     * упадёт с "Cannot redeclare". При правках — только заменять тело,
     * не добавлять копию.
     */
    private static function pushUser(string $uid, int $ticketId, string $subject): void
    {
        $url    = self::buildSupportUrl($ticketId);
        $title  = 'Ответ по обращению №' . $ticketId;
        $body   = mb_substr(
            $subject !== '' ? $subject : 'Новое сообщение от поддержки',
            0, 120
        );

        try {
            if (!class_exists('\\X5\\Pwa\\Push')) {
                self::logPushHistory($uid, $title, $body, $url, 'no_push_lib');
                return;
            }

            $sub = Storage::getSubscription($uid);
            if (!$sub) {
                self::logPushHistory($uid, $title, $body, $url, 'no_sub');
                \Bitrix\Main\Diag\Debug::writeToFile(
                    'skip: no subscription uid=' . substr($uid, 0, 12) . '… ticket=' . $ticketId,
                    'SupportBuilder::pushUser',
                    '/x5.pwa.log'
                );
                return;
            }

            $payload = [
                'title' => $title,
                'body'  => $body,
                'icon'  => '/icons/icon-192.png',
                'badge' => '/icons/icon-192.png',
                'url'   => $url,
                'tag'   => 'support-' . $ticketId,
            ];

            $r = \X5\Pwa\Push::send($uid, $sub, $payload);

            if (!empty($r['ok'])) {
                $status = (string)($r['statusCode'] ?? '201');
                self::logPushHistory($uid, $title, $body, $url, $status);

                \Bitrix\Main\Diag\Debug::writeToFile(
                    sprintf(
                        'sent uid=%s ticket=%d status=%s url=%s',
                        substr($uid, 0, 12) . '…', $ticketId, $status, $url
                    ),
                    'SupportBuilder::pushUser',
                    '/x5.pwa.log'
                );
                return;
            }

            if (!empty($r['gone'])) {
                Storage::deleteSubscription($uid);
                self::logPushHistory($uid, $title, $body, $url, 'gone');
            } else {
                self::logPushHistory($uid, $title, $body, $url, 'error');
            }

            \Bitrix\Main\Diag\Debug::writeToFile(
                sprintf(
                    'failed uid=%s ticket=%d status=%s message=%s',
                    substr($uid, 0, 12) . '…', $ticketId,
                    (string)($r['statusCode'] ?? '-'),
                    (string)($r['message'] ?? '-')
                ),
                'SupportBuilder::pushUser',
                '/x5.pwa.log'
            );
        } catch (\Throwable $e) {
            self::logPushHistory($uid, $title, $body, $url, 'error');
            \Bitrix\Main\Diag\Debug::writeToFile(
                'push exception uid=' . substr($uid, 0, 12) . '… ticket=' . $ticketId
                . ': ' . $e->getMessage(),
                'SupportBuilder::pushUser',
                '/x5.pwa.log'
            );
        }
    }

    /**
     * URL для push-уведомления. По умолчанию /support/tickets/{ID}.
     */
    private static function buildSupportUrl(int $ticketId): string
    {
        $tpl = (string)Settings::get('support_push_url_template', '');
        if ($tpl === '') $tpl = self::DEFAULT_PUSH_URL_TPL;

        return str_replace('{ID}', (string)$ticketId, $tpl);
    }

    /**
     * Записать факт отправки push в историю.
     *
     * ЕДИНСТВЕННАЯ КОПИЯ МЕТОДА. При правках — заменять тело, не копировать.
     */
    private static function logPushHistory(
        string $uid,
        string $title,
        string $body,
        string $url,
        string $status
    ): void {
        try {
            Storage::addPushHistory([
                'type'   => 'support-reply',
                'target' => $uid,
                'title'  => $title,
                'body'   => $body,
                'url'    => $url,
                'status' => mb_substr($status, 0, 32),
            ]);
        } catch (\Throwable $e) {
            \Bitrix\Main\Diag\Debug::writeToFile(
                'history log failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'SupportBuilder::logPushHistory',
                '/x5.pwa.log'
            );
        }
    }

    // ───────── Helpers ─────────

    private static function fmtDt($dt): ?string
    {
        if (!$dt) return null;
        try {
            return $dt->format('Y-m-d\TH:i:sP');
        } catch (\Throwable $e) {
            return null;
        }
    }
}