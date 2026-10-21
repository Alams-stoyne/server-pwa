<?php
namespace X5\Pwa\Api;

use X5\Pwa\HabitsBuilder;
use X5\Pwa\QuizBuilder;
use X5\Pwa\ThemesBuilder;
use X5\Pwa\TutorialBuilder;

class Config
{
    public static function themes(): array
    {
        return [
            'RESULT' => 'OK',
            'THEMES' => ThemesBuilder::getAll(),
        ];
    }

    public static function quizTheme(): array
    {
        return [
            'RESULT'    => 'OK',
            'QUESTIONS' => QuizBuilder::getQuestions(),
        ];
    }

    /**
     * Привычки для uid с прогрессом.
     * Привычки глобальные — не привязаны к направлению.
     * Возвращается плоский массив.
     */
    public static function habits(array $input): array
    {
        $uid = trim((string)(
            $input['uid']
            ?? $input['UID']
            ?? $input['ID_USER']
            ?? ''
        ));
        if ($uid === '') {
            return ['RESULT' => 'ERROR', 'MESSAGE' => 'uid required'];
        }

        return [
            'RESULT' => 'OK',
            'HABITS' => HabitsBuilder::getForUser($uid),
        ];
    }

    public static function tutorial(): array
    {
        return [
            'RESULT' => 'OK',
            'SLIDES' => TutorialBuilder::getSlides(),
        ];
    }
}