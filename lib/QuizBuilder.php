<?php
namespace X5\Pwa;

class QuizBuilder
{
    /**
     * Вопросы теста с ответами.
     *
     * Приоритет источников ответов:
     *   1) ANSWER_LIST  — множество значений, каждое — JSON ответа
     *   2) ANSWERS      — старая единая JSON-строка (fallback)
     */
    public static function getQuestions(): array
    {
        $ibId = Settings::iblockId('quiz_questions');
        if (!$ibId) return [];

        $elements = IbReader::elements($ibId);
        if (!$elements) return [];

        $out = [];
        foreach ($elements as $el) {
            $qCode = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);

            $props = IbReader::props($ibId, $el['ID']);

            // 1) Новый источник — множественное свойство
            $answers = self::parseAnswersList($props['ANSWERS_LIST'] ?? null);

            // 2) Fallback — старое свойство
            if (!$answers) {
                $answers = self::parseLegacy($props['ANSWERS'] ?? null);
            }

            $out[] = [
                'id'      => $qCode,
                'text'    => $el['NAME'],
                'answers' => $answers,
            ];
        }
        return $out;
    }

    /**
     * @param mixed $raw Массив JSON-строк (одно значение — один ответ)
     * @return array<int, array{id:string, text:string, scores:mixed}>
     */
    private static function parseAnswersList($raw): array
    {
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $one) {
            $data = is_array($one) ? $one : json_decode((string)$one, true);
            if (!is_array($data)) continue;

            $id     = trim((string)($data['id']   ?? ''));
            $text   = trim((string)($data['text'] ?? ''));
            $scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];

            if ($id === '' || $text === '') continue;

            $out[] = [
                'id'     => $id,
                'text'   => $text,
                'scores' => empty($scores) ? new \stdClass() : $scores,
            ];
        }
        return $out;
    }

    /**
     * Старый формат: одна строка-массив.
     *
     * @param mixed $raw
     * @return array<int, array>
     */
    private static function parseLegacy($raw): array
    {
        if (!is_string($raw) || $raw === '') return [];

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $a) {
            if (!is_array($a)) continue;
            $scores = is_array($a['scores'] ?? null) ? $a['scores'] : [];
            $out[] = [
                'id'     => (string)($a['id'] ?? ''),
                'text'   => (string)($a['text'] ?? ''),
                'scores' => empty($scores) ? new \stdClass() : $scores,
            ];
        }
        return $out;
    }
}