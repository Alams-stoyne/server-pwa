<?php
namespace X5\Pwa\Api;

use X5\Pwa\SupportBuilder;

class Support
{
    /**
     * POST /support/ticket/create
     * Body: { ID_USER, SUBJECT, TEXT, IMAGES:[base64] }
     */
    public static function create(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];

        $subject = (string)($b['SUBJECT'] ?? '');
        $text    = (string)($b['TEXT'] ?? '');
        $images  = $b['IMAGES'] ?? [];

        if ($subject === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'SUBJECT required'];
        if ($text === '')    return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];
        if (!is_array($images)) $images = [];

        return SupportBuilder::createTicket($uid, $subject, $text, $images);
    }

    /**
     * POST /support/ticket/create_guest
     * Body: { DEVICE_ID, UID?, AUTH_ERROR?, SUBJECT?, TEXT, IMAGES?:[], META?:{} }
     */
    public static function createGuest(array $b): array
    {
        $deviceId = trim((string)($b['DEVICE_ID'] ?? ''));
        if ($deviceId === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID required'];

        $uid       = isset($b['UID'])        ? (string)$b['UID']        : '';
        $authError = isset($b['AUTH_ERROR']) ? (string)$b['AUTH_ERROR'] : '';
        $subject   = isset($b['SUBJECT'])    ? (string)$b['SUBJECT']    : '';
        $text      = (string)($b['TEXT'] ?? '');
        $images    = $b['IMAGES'] ?? [];
        $meta      = $b['META']   ?? [];

        if ($text === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];
        if (!is_array($images)) $images = [];
        if (!is_array($meta))   $meta   = [];

        return SupportBuilder::createGuestTicket(
            $deviceId,
            $uid !== '' ? $uid : null,
            $authError !== '' ? $authError : null,
            $subject !== '' ? $subject : null,
            $text,
            $images,
            $meta
        );
    }

    /**
     * POST /support/ticket/create_reset_uid
     * Body: { DEVICE_ID, UID, RESET_REASONS: [], TEXT, IMAGES?:[] }
     */
    public static function createResetUid(array $b): array
    {
        $deviceId = trim((string)($b['DEVICE_ID'] ?? ''));
        $uid      = trim((string)($b['UID'] ?? ''));
        $text     = (string)($b['TEXT'] ?? '');
        $images   = $b['IMAGES'] ?? [];
        $reasons  = $b['RESET_REASONS'] ?? [];

        if ($deviceId === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID required'];
        if ($uid === '')      return ['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'];
        if ($text === '')     return ['RESULT' => 'ERROR', 'MESSAGE' => 'TEXT required'];

        if (!is_array($images)) $images = [];
        if (!is_array($reasons)) {
            $reasons = is_string($reasons) ? explode(',', $reasons) : [];
        }

        return SupportBuilder::createResetUidTicket($deviceId, $uid, $reasons, $text, $images);
    }

    /**
     * POST /support/ticket/list
     * Body: { ID_USER, STATUS?, PAGE?, PER_PAGE? }
     */
    public static function tickets(array $b): array
    {
        $uid = trim((string)($b['ID_USER'] ?? ''));
        if ($uid === '') return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER required'];

        $status  = (string)($b['STATUS'] ?? '');
        $page    = max(1, (int)($b['PAGE'] ?? 1));
        $perPage = max(1, min(100, (int)($b['PER_PAGE'] ?? 20)));

        $res = SupportBuilder::listForUser($uid, $status ?: null, $page, $perPage);
        $res['RESULT'] = 'OK';
        return $res;
    }

    /**
     * POST /support/ticket/get
     * Body: { ID_USER, TICKET_ID }
     */
    public static function get(array $b): array
    {
        $uid      = trim((string)($b['ID_USER'] ?? ''));
        $ticketId = (int)($b['TICKET_ID'] ?? 0);
        if ($uid === '' || $ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER and TICKET_ID required'];
        }

        $res = SupportBuilder::getTicket($ticketId, $uid);
        if (!$res) return ['RESULT' => 'TICKET_NOT_FOUND'];

        return [
            'RESULT'   => 'OK',
            'TICKET'   => $res['TICKET'],
            'MESSAGES' => $res['MESSAGES'],
        ];
    }

    /**
     * POST /support/ticket/guest_get
     * Body: { DEVICE_ID, TICKET_ID }
     */
    public static function getGuest(array $b): array
    {
        $deviceId = trim((string)($b['DEVICE_ID'] ?? ''));
        $ticketId = (int)($b['TICKET_ID'] ?? 0);
        if ($deviceId === '' || $ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID and TICKET_ID required'];
        }

        $res = SupportBuilder::getGuestTicket($ticketId, $deviceId);
        if (!$res) return ['RESULT' => 'TICKET_NOT_FOUND'];

        return [
            'RESULT'   => 'OK',
            'TICKET'   => $res['TICKET'],
            'MESSAGES' => $res['MESSAGES'],
        ];
    }

    /**
     * POST /support/ticket/message
     * Body: { ID_USER, TICKET_ID, TEXT, IMAGES?:[base64] }
     */
    public static function send(array $b): array
    {
        $uid      = trim((string)($b['ID_USER'] ?? ''));
        $ticketId = (int)($b['TICKET_ID'] ?? 0);
        $text     = (string)($b['TEXT'] ?? '');
        $images   = $b['IMAGES'] ?? [];

        if ($uid === '' || $ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'ID_USER and TICKET_ID required'];
        }
        if (!is_array($images)) $images = [];

        return SupportBuilder::addUserMessage($uid, $ticketId, $text, $images);
    }

    /**
     * POST /support/ticket/guest_message
     * Body: { DEVICE_ID, TICKET_ID, TEXT, IMAGES?:[base64] }
     */
    public static function sendGuest(array $b): array
    {
        $deviceId = trim((string)($b['DEVICE_ID'] ?? ''));
        $ticketId = (int)($b['TICKET_ID'] ?? 0);
        $text     = (string)($b['TEXT'] ?? '');
        $images   = $b['IMAGES'] ?? [];

        if ($deviceId === '' || $ticketId <= 0) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'DEVICE_ID and TICKET_ID required'];
        }
        if (!is_array($images)) $images = [];

        return SupportBuilder::addGuestMessage($deviceId, $ticketId, $text, $images);
    }
}