<?php
namespace X5\Pwa\Property;

/**
 * Тип свойства инфоблока: «Ответ теста».
 *
 * Регистрируется через событие iblock/OnIBlockPropertyBuildList.
 * Хранит JSON-строку в одном значении свойства.
 *
 * Формат:
 *   {"id":"a1","text":"Сразу зарядка","scores":{"energy":2,"body":1}}
 */
class QuizAnswer
{
    public const USER_TYPE = 'x5_quiz_answer';

    private static bool $assetsPrinted = false;
    private static ?array $themesCache = null;

    // ───────── Дескриптор для Bitrix ─────────

    public static function GetUserTypeDescription(): array
    {
        return [
            'PROPERTY_TYPE'  => 'S',
            'USER_TYPE'      => self::USER_TYPE,
            'DESCRIPTION'    => 'Ответ теста (id, текст, веса по направлениям)',
            'GetPropertyFieldHtml' => [__CLASS__, 'GetPropertyFieldHtml'],
            'GetPublicViewHTML'    => [__CLASS__, 'GetPublicViewHTML'],
            'GetAdminListViewHTML' => [__CLASS__, 'GetAdminListViewHTML'],
            'ConvertToDB'          => [__CLASS__, 'ConvertToDB'],
            'ConvertFromDB'        => [__CLASS__, 'ConvertFromDB'],
            'GetLength'            => [__CLASS__, 'GetLength'],
            'PrepareSettings'      => [__CLASS__, 'PrepareSettings'],
            'GetSettingsHTML'      => [__CLASS__, 'GetSettingsHTML'],
        ];
    }

    public static function ConvertToDB($arProperty, $value)
    {
        $raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;

        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $normalized = self::normalizeArray($data);
                return ['VALUE' => $normalized];
            }
        }

        if (is_array($raw)) {
            return ['VALUE' => self::normalizeArray($raw)];
        }

        return ['VALUE' => ''];
    }

    public static function ConvertFromDB($arProperty, $value)
    {
        return $value;
    }

    public static function GetLength($arProperty, $value): int
    {
        $raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;
        return is_string($raw) ? strlen($raw) : 0;
    }

    // ───────── Рендер формы редактирования ─────────

    public static function GetPropertyFieldHtml($arProperty, $value, $strHTMLControlName): string
    {
        $raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;
        $data = self::decode($raw);

        $currentId = (string)($data['id'] ?? '');

        $pairs = [];
        foreach (($data['scores'] ?? []) as $theme => $score) {
            $pairs[] = ['theme' => (string)$theme, 'score' => (int)$score];
        }

        $name = (string)($strHTMLControlName['VALUE'] ?? '');

        $jsData = [
            'uid'    => 'x5qa_' . substr(md5($name . ':' . uniqid('', true)), 0, 10),
            'name'   => $name,
            'id'     => $currentId,
            'text'   => (string)($data['text'] ?? ''),
            'scores' => $pairs,
            'themes' => self::getThemesList(),
        ];

        $attr    = htmlspecialcharsbx(json_encode($jsData, JSON_UNESCAPED_UNICODE));
        $uidEsc  = htmlspecialcharsbx($jsData['uid']);
        $idEsc   = $currentId !== '' ? htmlspecialcharsbx($currentId) : '<span class="x5qa-id-tbd">—</span>';
        $nameEsc = htmlspecialcharsbx($name);

        self::printAssetsOnce();

        $html  = '<div class="x5qa-block" data-uid="' . $uidEsc . '" data-json="' . $attr . '">';
        $html .= '<div class="x5qa-header">';
        $html .= '<span class="x5qa-id-label">ID:</span>';
        $html .= '<span class="x5qa-id-value" title="ID генерируется автоматически">' . $idEsc . '</span>';
        $html .= '<a href="#" class="x5qa-delete" title="Удалить этот ответ">× Удалить</a>';
        $html .= '</div>';
        $html .= '<div class="x5qa-field">';
        $html .= '<input type="text" class="x5qa-text adm-input" placeholder="Текст ответа" value="">';
        $html .= '</div>';
        $html .= '<div class="x5qa-field">';
        $html .= '<div class="x5qa-scores-title">Вес по направлениям:</div>';
        $html .= '<div class="x5qa-scores"></div>';
        $html .= '<button type="button" class="x5qa-add-score adm-btn adm-btn-add">+ Добавить направление</button>';
        $html .= '</div>';
        $html .= '<input type="hidden" class="x5qa-hidden" name="' . $nameEsc . '" value="">';
        $html .= '</div>';

        $html .= '<script>'
            . '(function(){'
            . '  if (window.X5QuizAnswer && window.X5QuizAnswer.init) {'
            . '    window.X5QuizAnswer.init();'
            . '  }'
            . '})();'
            . '</script>';

        return $html;
    }

    public static function GetPublicViewHTML($arProperty, $value, $strHTMLControlName): string
    {
        $raw = is_array($value) ? ($value['VALUE'] ?? '') : $value;
        $data = self::decode($raw);
        if (!$data) return '';

        return self::renderReadonly($data);
    }

    public static function GetAdminListViewHTML($arProperty, $value, $strHTMLControlName): string
    {
        return self::GetPublicViewHTML($arProperty, $value, $strHTMLControlName);
    }

    public static function PrepareSettings($arProperty): array
    {
        return [];
    }

    public static function GetSettingsHTML($arProperty, $strHTMLControlName, &$arPropertyFields): string
    {
        return '';
    }

    // ───────── Internals ─────────

    private static function renderReadonly(array $data): string
    {
        $id   = htmlspecialcharsbx((string)($data['id']   ?? ''));
        $text = htmlspecialcharsbx((string)($data['text'] ?? ''));

        $themesMap = [];
        foreach (self::getThemesList() as $t) {
            $themesMap[$t['id']] = $t['name'];
        }

        $scores = is_array($data['scores'] ?? null) ? $data['scores'] : [];
        $scoresHtml = '';
        if ($scores) {
            $parts = [];
            foreach ($scores as $theme => $v) {
                $name = $themesMap[$theme] ?? $theme;
                $parts[] = htmlspecialcharsbx($name) . ': ' . (int)$v;
            }
            $scoresHtml = ' <small style="color:#666">(' . implode(', ', $parts) . ')</small>';
        }

        return "<span><b>{$id}</b> — {$text}{$scoresHtml}</span>";
    }

    private static function normalizeArray(array $data): string
    {
        $id   = trim((string)($data['id']   ?? ''));
        $text = trim((string)($data['text'] ?? ''));

        $scores = [];
        $rawScores = is_array($data['scores'] ?? null) ? $data['scores'] : [];
        foreach ($rawScores as $theme => $val) {
            $theme = trim((string)$theme);
            $val   = (int)$val;
            if ($theme === '' || $val <= 0) continue;
            $scores[$theme] = $val;
        }

        if ($id === '' && $text === '' && !$scores) return '';

        if ($id === '') $id = 'a' . random_int(1, 9999);

        $payload = ['id' => $id, 'text' => $text];
        if ($scores) $payload['scores'] = $scores;

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private static function decode($raw): array
    {
        if (is_array($raw)) return $raw;
        $raw = (string)$raw;
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

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
            $out[] = ['id' => (string)$id, 'name' => (string)$el['NAME']];
        }

        return self::$themesCache = $out;
    }

    private static function printAssetsOnce(): void
    {
        if (self::$assetsPrinted) return;
        self::$assetsPrinted = true;

        $themesJson = json_encode(self::getThemesList(), JSON_UNESCAPED_UNICODE);

        echo '<style>
            .x5qa-block {
                background: #f8f9fb;
                border: 1px solid #e2e5e9;
                border-radius: 4px;
                padding: 12px 14px;
                margin-bottom: 10px;
            }
            .x5qa-block.x5qa-hidden-block {
                display: none !important;
            }
            .x5qa-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
                font-size: 12px;
            }
            .x5qa-id-label { color: #888; }
            .x5qa-id-value {
                font-family: monospace;
                background: #eef1f4;
                padding: 2px 8px;
                border-radius: 3px;
                color: #555;
                cursor: help;
            }
            .x5qa-id-tbd {
                font-family: monospace;
                color: #aaa;
                padding: 2px 8px;
            }
            .x5qa-delete {
                margin-left: auto;
                color: #c00;
                text-decoration: none;
                font-size: 12px;
                padding: 2px 8px;
                border-radius: 3px;
                transition: background .15s;
            }
            .x5qa-delete:hover {
                background: #ffe5e5;
            }
            .x5qa-field { margin-bottom: 10px; }
            .x5qa-text { width: 100%; max-width: 560px; }
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
            .x5qa-score-theme { font-weight: 600; }
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

            function idNum(id) {
                var m = String(id || "").match(/^a(\d+)$/);
                return m ? parseInt(m[1], 10) : 0;
            }

            function parseData(block) {
                var raw = block.getAttribute("data-json") || "{}";
                try { return JSON.parse(raw); }
                catch (e) { return {}; }
            }

            function writeData(block, data) {
                block.setAttribute("data-json", JSON.stringify(data));
            }

            function getState(block) {
                var uid = block.getAttribute("data-uid");
                if (!uid) return null;

                if (!block.__x5qaState) {
                    var data = parseData(block);
                    block.__x5qaState = {
                        uid: uid,
                        name: data.name || "",
                        id: data.id || "",
                        text: data.text || "",
                        scores: Array.isArray(data.scores) ? data.scores.slice() : []
                    };
                }
                return block.__x5qaState;
            }

            function resetBlockToEmpty(block, newId) {
                var data = parseData(block);
                data.id = newId;
                data.text = "";
                data.scores = [];
                if (!data.uid) data.uid = block.getAttribute("data-uid") || "";
                writeData(block, data);

                delete block.__x5qaState;
                delete block.__x5qaReady;

                var textInput = block.querySelector(".x5qa-text");
                if (textInput) textInput.value = "";

                var idValue = block.querySelector(".x5qa-id-value");
                if (idValue) idValue.textContent = newId;
            }

            function initBlock(block) {
                if (block.classList.contains("x5qa-hidden-block")) return;
                if (block.style.display === "none") return;

                var state = getState(block);
                if (!state) return;

                var textInput = block.querySelector(".x5qa-text");
                var scoresBox = block.querySelector(".x5qa-scores");
                var addBtn    = block.querySelector(".x5qa-add-score");
                var delBtn    = block.querySelector(".x5qa-delete");
                var hidden    = block.querySelector(".x5qa-hidden");

                if (!textInput || !scoresBox || !hidden) return;
                if (block.__x5qaReady) return;
                block.__x5qaReady = true;

                var idValue = block.querySelector(".x5qa-id-value");
                if (idValue && state.id) idValue.textContent = state.id;

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

                if (delBtn) {
                    delBtn.addEventListener("click", function (e) {
                        e.preventDefault();
                        if (!confirm("Удалить этот ответ?")) return;
                        removeBlock(block);
                    });
                }

                renderScores(block);
                syncHidden(block);
            }

            function removeBlock(block) {
                var hidden = block.querySelector(".x5qa-hidden");
                if (hidden) hidden.value = "";
                block.classList.add("x5qa-hidden-block");
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
                var current = isEdit ? state.scores[editIdx] : { theme: "", score: 1 };

                var used = {};
                state.scores.forEach(function (item, idx) {
                    if (idx !== editIdx) used[item.theme] = true;
                });

                var optionsHtml = \'<option value="">— выберите направление —</option>\';
                THEMES.forEach(function (t) {
                    if (used[t.id] && t.id !== current.theme) return;
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
                            events: { click: function () { popup.destroy(); } }
                        })
                    ]
                });

                popup.show();
            }

            function reassignIds() {
                var blocks = document.querySelectorAll(".x5qa-block[data-uid]");
                var seen = {};
                var maxNum = 0;
                var entries = [];

                blocks.forEach(function (b) {
                    if (b.classList.contains("x5qa-hidden-block")) return;
                    if (b.style.display === "none") return;

                    var data = parseData(b);
                    var id = String(data.id || "").trim();
                    var n = idNum(id);
                    if (n > maxNum) maxNum = n;

                    entries.push({ block: b, data: data, id: id });
                });

                entries.forEach(function (info) {
                    var b = info.block;
                    var id = info.id;

                    if (id === "" || seen[id]) {
                        maxNum++;
                        var newId = "a" + maxNum;
                        seen[newId] = true;
                        resetBlockToEmpty(b, newId);
                    } else {
                        seen[id] = true;
                    }
                });
            }

            function init() {
                reassignIds();

                var blocks = document.querySelectorAll(".x5qa-block[data-uid]");
                blocks.forEach(function (block) {
                    initBlock(block);
                });
            }

            function boot() {
                init();
                setInterval(init, 500);
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