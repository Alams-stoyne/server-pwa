<?php
namespace X5\Pwa\UserType;

class QuizAnswer
{
    public const USER_TYPE_ID = 'x5_quiz_answer';

    private static bool $assetsPrinted = false;
    private static ?array $themesCache = null;

    public static function getUserTypeDescription(): array
    {
        return [
            'USER_TYPE_ID' => self::USER_TYPE_ID,
            'CLASS_NAME'   => __CLASS__,
            'DESCRIPTION'  => 'Ответ теста: id, текст, веса по направлениям',
            'BASE_TYPE'    => 'string',
        ];
    }

    public static function checkFields($arUserField, $value): array
    {
        return [];
    }

    /**
     * Значение из формы — уже готовая JSON-строка (собирается JS).
     * Возвращаем как есть. Если пришёл массив (на всякий случай) — нормализуем.
     */
    public static function onBeforeSave($arUserField, $value)
    {
        if (is_array($value)) {
            // На случай, если Bitrix передал массив полей
            $value = self::normalizeArray($value);
            if ($value === '') return '';
        }

        if (!is_string($value) || $value === '') return '';

        $data = json_decode($value, true);
        if (!is_array($data)) return '';

        return self::normalizeArray($data);
    }

    /**
     * Превращает массив в каноничную JSON-строку.
     */
    private static function normalizeArray(array $data): string
    {
        $id     = trim((string)($data['id']   ?? ''));
        $text   = trim((string)($data['text'] ?? ''));

        $scores = [];
        $rawScores = is_array($data['scores'] ?? null) ? $data['scores'] : [];
        foreach ($rawScores as $theme => $val) {
            $theme = trim((string)$theme);
            $val   = (int)$val;
            if ($theme === '' || $val <= 0) continue;
            $scores[$theme] = $val;
        }

        // Пустое значение — не сохраняем
        if ($id === '' && $text === '' && !$scores) return '';

        // Если id не задан — сгенерируем
        if ($id === '') $id = self::generateId();

        $payload = [
            'id'   => $id,
            'text' => $text,
        ];
        if ($scores) $payload['scores'] = $scores;

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public static function getPublicEditHTML($arUserField, $arHtmlControl): string
    {
        $name = (string)($arHtmlControl['NAME'] ?? '');
        $data = self::decode($arHtmlControl['VALUE'] ?? '');

        // Если id нет — генерируем, чтобы сразу показать пользователю
        if (empty($data['id'])) {
            $data['id'] = self::generateId();
        }

        // Преобразуем scores {theme:score} в массив пар
        $pairs = [];
        foreach (($data['scores'] ?? []) as $theme => $score) {
            $pairs[] = ['theme' => (string)$theme, 'score' => (int)$score];
        }

        $jsData = [
            'uid'    => 'x5qa_' . substr(md5($name . ':' . uniqid('', true)), 0, 10),
            'name'   => $name,
            'id'     => (string)$data['id'],
            'text'   => (string)($data['text'] ?? ''),
            'scores' => $pairs,
            'themes' => self::getThemesList(),
        ];

        $attr  = htmlspecialcharsbx(json_encode($jsData, JSON_UNESCAPED_UNICODE));
        $uidEsc = htmlspecialcharsbx($jsData['uid']);
        $idEsc  = htmlspecialcharsbx($jsData['id']);

        self::printAssetsOnce();

        // Сама вёрстка — для JS оставляем только контейнеры,
        // всё внутреннее содержимое генерирует JS.
        $html  = '<div class="x5qa-block" data-uid="' . $uidEsc . '" data-json="' . $attr . '">';
        $html .= '<div class="x5qa-header">';
        $html .= '<span class="x5qa-id-label">ID:</span>';
        $html .= '<span class="x5qa-id-value" title="ID генерируется автоматически">' . $idEsc . '</span>';
        $html .= '</div>';
        $html .= '<div class="x5qa-field">';
        $html .= '<input type="text" class="x5qa-text adm-input" placeholder="Текст ответа" value="">';
        $html .= '</div>';
        $html .= '<div class="x5qa-field">';
        $html .= '<div class="x5qa-scores-title">Вес по направлениям:</div>';
        $html .= '<div class="x5qa-scores"></div>';
        $html .= '<button type="button" class="x5qa-add-score adm-btn adm-btn-add">+ Добавить направление</button>';
        $html .= '</div>';
        $html .= '<input type="hidden" class="x5qa-hidden" name="' . htmlspecialcharsbx($name) . '" value="">';
        $html .= '</div>';

        // Автоинициализация конкретного блока
        $html .= '<script>'
            . '(function(){'
            . '  if (window.X5QuizAnswer && window.X5QuizAnswer.init) {'
            . '    window.X5QuizAnswer.init();'
            . '  }'
            . '})();'
            . '</script>';

        return $html;
    }

    public static function getPublicViewHTML($arUserField, $arHtmlControl): string
    {
        $data = self::decode($arHtmlControl['VALUE'] ?? '');
        if (!$data) return '';

        $id   = htmlspecialcharsbx((string)($data['id']   ?? ''));
        $text = htmlspecialcharsbx((string)($data['text'] ?? ''));

        $scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];
        $scoresHtml = '';
        if ($scores) {
            $parts = [];
            $themesMap = [];
            foreach (self::getThemesList() as $t) {
                $themesMap[$t['id']] = $t['name'];
            }
            foreach ($scores as $theme => $v) {
                $name = $themesMap[$theme] ?? $theme;
                $parts[] = htmlspecialcharsbx($name) . ': ' . (int)$v;
            }
            $scoresHtml = ' <small style="color:#666">(' . implode(', ', $parts) . ')</small>';
        }

        return "<span><b>{$id}</b> — {$text}{$scoresHtml}</span>";
    }

    public static function getAdminListViewHTML($arUserField, $arHtmlControl): string
    {
        return self::getPublicViewHTML($arUserField, $arHtmlControl);
    }

    public static function getAdminListEditHTML($arUserField, $arHtmlControl): string
    {
        return self::getPublicEditHTML($arUserField, $arHtmlControl);
    }

    // ───────── Internals ─────────

    private static function decode($raw): array
    {
        if (is_array($raw)) return $raw;
        $raw = (string)$raw;
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function generateId(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = 'a';
        for ($i = 0; $i < 6; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /**
     * Список направлений из IB x5pwa_themes.
     * Кэшируется в пределах запроса.
     *
     * @return array<int, array{id:string, name:string}>
     */
    private static function getThemesList(): array
    {
        if (self::$themesCache !== null) return self::$themesCache;

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return self::$themesCache = [];
        }

        $ibId = \X5\Pwa\Settings::iblockId('themes');
        if (!$ibId) return self::$themesCache = [];

        $rs = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => $ibId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'XML_ID']
        );

        $out = [];
        while ($el = $rs->Fetch()) {
            $id = $el['CODE'] ?: ($el['XML_ID'] ?: (string)$el['ID']);
            $out[] = [
                'id'   => (string)$id,
                'name' => (string)$el['NAME'],
            ];
        }

        return self::$themesCache = $out;
    }

    /**
     * Печатает CSS и JS один раз на страницу.
     */
    private static function printAssetsOnce(): void
    {
        if (self::$assetsPrinted) return;
        self::$assetsPrinted = true;

        // Определяем ID инфоблока для передачи в JS (не пригодится, но пусть будет)
        $themes = self::getThemesList();
        $themesJson = json_encode($themes, JSON_UNESCAPED_UNICODE);

        // CSS
        echo '<style>
            .x5qa-block {
                background: #f8f9fb;
                border: 1px solid #e2e5e9;
                border-radius: 4px;
                padding: 12px 14px;
                margin-bottom: 10px;
            }
            .x5qa-header {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                font-size: 12px;
            }
            .x5qa-id-label {
                color: #888;
            }
            .x5qa-id-value {
                font-family: monospace;
                background: #eef1f4;
                padding: 2px 8px;
                border-radius: 3px;
                color: #555;
                cursor: help;
            }
            .x5qa-field {
                margin-bottom: 10px;
            }
            .x5qa-text {
                width: 100%;
                max-width: 560px;
            }
            .x5qa-scores-title {
                font-size: 12px;
                color: #666;
                margin-bottom: 6px;
            }
            .x5qa-scores {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 8px;
                min-height: 26px;
            }
            .x5qa-scores-empty {
                color: #aaa;
                font-size: 12px;
                font-style: italic;
            }
            .x5qa-score {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #e0efff;
                border: 1px solid #b8d4f5;
                border-radius: 14px;
                padding: 3px 10px;
                font-size: 12px;
                color: #1e6bcc;
            }
            .x5qa-score-theme {
                font-weight: 600;
            }
            .x5qa-score-value {
                background: #fff;
                padding: 1px 7px;
                border-radius: 8px;
                font-weight: 600;
            }
            .x5qa-score-edit,
            .x5qa-score-del {
                text-decoration: none;
                color: #555;
                font-size: 13px;
                line-height: 1;
                padding: 0 3px;
            }
            .x5qa-score-edit:hover { color: #1e6bcc; }
            .x5qa-score-del:hover  { color: #c00; }

            .x5qa-dialog { padding: 16px 20px; min-width: 360px; }
            .x5qa-dialog-row {
                display: grid;
                grid-template-columns: 130px 1fr;
                gap: 12px;
                align-items: center;
                margin-bottom: 14px;
            }
            .x5qa-dialog-row label { color: #333; }
            .x5qa-dialog-theme,
            .x5qa-dialog-score { width: 100%; }
        </style>';

        // JS
        echo '<script>
        window.X5QuizAnswer = (function () {
            var THEMES = ' . $themesJson . ';

            function escapeHtml(s) {
                return String(s).replace(/[&<>"\']/g, function (c) {
                    return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\\"": "&quot;", "\'": "&#39;" }[c];
                });
            }

            function themeName(id) {
                for (var i = 0; i < THEMES.length; i++) {
                    if (THEMES[i].id === id) return THEMES[i].name;
                }
                return id;
            }

            function getState(block) {
                var uid = block.getAttribute("data-uid");
                if (!uid) return null;

                if (!block.__x5qaState) {
                    var data;
                    try {
                        data = JSON.parse(block.getAttribute("data-json") || "{}");
                    } catch (e) {
                        data = {};
                    }
                    block.__x5qaState = {
                        uid: uid,
                        name: data.name || "",
                        id: data.id || "",
                        text: data.text || "",
                        scores: Array.isArray(data.scores) ? data.scores.slice() : []
                    };
                    block.__x5qaReady = false;
                }
                return block.__x5qaState;
            }

            function initBlock(block) {
                var state = getState(block);
                if (!state) return;

                var textInput  = block.querySelector(".x5qa-text");
                var scoresBox  = block.querySelector(".x5qa-scores");
                var addBtn     = block.querySelector(".x5qa-add-score");
                var hidden     = block.querySelector(".x5qa-hidden");

                if (!textInput || !scoresBox || !hidden) return;

                if (block.__x5qaReady) return;
                block.__x5qaReady = true;

                // Начальные значения
                textInput.value = state.text;

                textInput.addEventListener("input", function () {
                    state.text = textInput.value;
                    syncHidden(block);
                });

                if (addBtn) {
                    addBtn.addEventListener("click", function (e) {
                        e.preventDefault();
                        openScoreDialog(block, -1);
                    });
                }

                renderScores(block);
                syncHidden(block);
            }

            function renderScores(block) {
                var state = getState(block);
                if (!state) return;

                var box = block.querySelector(".x5qa-scores");
                if (!box) return;

                if (!state.scores.length) {
                    box.innerHTML = \'<div class="x5qa-scores-empty">Направления не выбраны</div>\';
                    return;
                }

                var html = "";
                state.scores.forEach(function (item, idx) {
                    html += \'<div class="x5qa-score">\';
                    html += \'<span class="x5qa-score-theme">\' + escapeHtml(themeName(item.theme)) + \'</span>\';
                    html += \'<span class="x5qa-score-value">\' + item.score + \'</span>\';
                    html += \'<a class="x5qa-score-edit" href="#" data-idx="\' + idx + \'" title="Изменить">&#9998;</a>\';
                    html += \'<a class="x5qa-score-del"  href="#" data-idx="\' + idx + \'" title="Удалить">&#215;</a>\';
                    html += \'</div>\';
                });
                box.innerHTML = html;

                box.querySelectorAll(".x5qa-score-edit").forEach(function (el) {
                    el.addEventListener("click", function (e) {
                        e.preventDefault();
                        openScoreDialog(block, parseInt(el.getAttribute("data-idx"), 10));
                    });
                });
                box.querySelectorAll(".x5qa-score-del").forEach(function (el) {
                    el.addEventListener("click", function (e) {
                        e.preventDefault();
                        var idx = parseInt(el.getAttribute("data-idx"), 10);
                        state.scores.splice(idx, 1);
                        renderScores(block);
                        syncHidden(block);
                    });
                });
            }

            function syncHidden(block) {
                var state = getState(block);
                if (!state) return;

                var hidden = block.querySelector(".x5qa-hidden");
                if (!hidden) return;

                var scoresFlat = {};
                state.scores.forEach(function (item) {
                    if (item.theme && item.score > 0) {
                        scoresFlat[item.theme] = item.score;
                    }
                });

                var hasScores = Object.keys(scoresFlat).length > 0;
                var text = (state.text || "").trim();

                // Пусто — очищаем значение
                if (!text && !hasScores) {
                    hidden.value = "";
                    return;
                }

                var payload = { id: state.id, text: state.text };
                if (hasScores) payload.scores = scoresFlat;

                hidden.value = JSON.stringify(payload);
            }

            function openScoreDialog(block, editIdx) {
                if (typeof BX === "undefined" || !BX.PopupWindow) {
                    alert("Не удалось открыть окно: BX.PopupWindow не загружен");
                    return;
                }

                var state = getState(block);
                if (!state) return;

                var isEdit = editIdx >= 0;
                var current = isEdit
                    ? state.scores[editIdx]
                    : { theme: "", score: 1 };

                // Уже использованные направления
                var used = {};
                state.scores.forEach(function (item, idx) {
                    if (idx !== editIdx) used[item.theme] = true;
                });

                var optionsHtml = \'<option value="">— выберите направление —</option>\';
                THEMES.forEach(function (t) {
                    if (used[t.id]) return;
                    var sel = (t.id === current.theme) ? " selected" : "";
                    optionsHtml += \'<option value="\' + escapeHtml(t.id) + \'"\' + sel + \'>\' + escapeHtml(t.name) + \'</option>\';
                });

                var contentHtml = \'\'
                    + \'<div class="x5qa-dialog">\'
                    +   \'<div class="x5qa-dialog-row">\'
                    +     \'<label>Направление:</label>\'
                    +     \'<select class="x5qa-dialog-theme adm-select">\' + optionsHtml + \'</select>\'
                    +   \'</div>\'
                    +   \'<div class="x5qa-dialog-row">\'
                    +     \'<label>Вес:</label>\'
                    +     \'<input type="number" min="1" max="99" class="x5qa-dialog-score adm-input" value="\' + (current.score || 1) + \'">\'
                    +   \'</div>\'
                    + \'</div>\';

                var popupId = "x5qa_popup_" + state.uid + "_" + Date.now();
                var popup = new BX.PopupWindow(popupId, null, {
                    content: contentHtml,
                    titleBar: isEdit ? "Изменить вес" : "Добавить направление",
                    closeIcon: { right: "12px", top: "10px" },
                    autoHide: true,
                    zIndex: 3000,
                    buttons: [
                        new BX.PopupWindowButton({
                            text: "Сохранить",
                            className: "popup-window-button-accept",
                            events: {
                                click: function () {
                                    var sel   = popup.contentContainer.querySelector(".x5qa-dialog-theme");
                                    var input = popup.contentContainer.querySelector(".x5qa-dialog-score");
                                    var theme = sel ? sel.value : "";
                                    var score = input ? parseInt(input.value, 10) || 0 : 0;

                                    if (!theme) { alert("Выберите направление"); return; }
                                    if (score <= 0) { alert("Введите положительное число"); return; }

                                    if (isEdit) {
                                        state.scores[editIdx] = { theme: theme, score: score };
                                    } else {
                                        state.scores.push({ theme: theme, score: score });
                                    }
                                    renderScores(block);
                                    syncHidden(block);
                                    popup.destroy();
                                }
                            }
                        }),
                        new BX.PopupWindowButton({
                            text: "Отмена",
                            events: {
                                click: function () { popup.destroy(); }
                            }
                        })
                    ]
                });

                popup.show();
            }

            function init() {
                var blocks = document.querySelectorAll(".x5qa-block[data-uid]");
                blocks.forEach(function (block) {
                    initBlock(block);
                });
            }

            // Автозапуск
            function boot() {
                init();
                // Bitrix динамически подменяет DOM при клике «Добавить значение» —
                // отслеживаем новые блоки.
                setInterval(init, 800);
            }

            if (typeof BX !== "undefined" && BX.ready) {
                BX.ready(boot);
            } else if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", boot);
            } else {
                boot();
            }

            return { init: init, initBlock: initBlock };
        })();
        </script>';
    }
}