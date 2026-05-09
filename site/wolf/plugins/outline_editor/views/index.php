<?php if (!defined('IN_CMS')) { exit(); } ?>

<link rel="stylesheet" href="/wolf/plugins/outline_editor/outline_editor.css">

<div id="oe-app" class="oe-app">
    <h1>Обводки квартир</h1>

    <div class="oe-toolbar">
        <label>
            Этаж:
            <select id="oe-floor-select"></select>
        </label>
        <button id="oe-mode-pan" class="oe-btn oe-btn--active" type="button">Просмотр</button>
        <button id="oe-mode-draw" class="oe-btn" type="button">Рисовать</button>
        <button id="oe-finish" class="oe-btn" type="button" disabled>Завершить полигон</button>
        <button id="oe-undo" class="oe-btn" type="button" disabled>Отменить точку</button>
        <button id="oe-delete" class="oe-btn oe-btn--danger" type="button" disabled>Удалить выбранную</button>
        <span class="oe-spacer"></span>
        <span id="oe-status" class="oe-status"></span>
        <button id="oe-save" class="oe-btn oe-btn--primary" type="button">Сохранить</button>
    </div>

    <div class="oe-workspace">
        <div class="oe-stage" id="oe-stage">
            <img id="oe-raster" alt="" />
            <svg id="oe-svg" xmlns="http://www.w3.org/2000/svg"></svg>
            <div id="oe-empty" class="oe-empty">Выбери этаж — растровый план появится здесь.</div>
        </div>
        <aside class="oe-sidebar">
            <div class="oe-help">
                <strong>Как пользоваться:</strong>
                <ol>
                    <li>Выбери этаж сверху.</li>
                    <li>Кликни квартиру в списке справа — она станет «активной».</li>
                    <li>Нажми «Рисовать», расставь точки на плане. ПКМ — отменить точку. «Завершить полигон» — закрыть.</li>
                    <li>«Сохранить» — все обводки этажа уйдут на сервер.</li>
                </ol>
            </div>
            <ul id="oe-apart-list" class="oe-apart-list"></ul>
        </aside>
    </div>
</div>

<script>
    window.OE_API_URL  = '/outline_editor/api';
    window.OE_DEBUG    = <?= isset($_GET['debug']) ? 'true' : 'false' ?>;
</script>
<script src="/wolf/plugins/outline_editor/outline_editor.js"></script>
