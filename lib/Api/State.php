<?php
namespace X5\Pwa\Api;

use X5\Pwa\Storage;

class State
{
    public static function save(array $b): array
    {
        $uid = trim((string)($b['UID'] ?? $b['ID_USER'] ?? ''));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'UID required'];
        }

        // ─── Валидация UID: 40 hex-символов ───
        // Без неё любой бот может писать в HL-блок произвольные строки
        // (SQL-инъекции, мусор, ID других пользователей).
        if (!preg_match('/^[a-f0-9]{40}$/i', $uid)) {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Invalid UID format'];
        }

        try {
            Storage::saveState($uid, $b['STATE'] ?? null);
        } catch (\Throwable $e) {
            \X5\Pwa\Settings::logError(
                'state save failed uid=' . substr($uid, 0, 12) . '…: ' . $e->getMessage(),
                'State::save'
            );
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'Не удалось сохранить состояние.'];
        }

        return ['RESULT' => 'OK'];
    }
}