<?php
/**
 * outline_editor — admin-плагин для рисования обводок квартир поверх
 * растрового поэтажного плана из Profitbase-фида.
 *
 * Хранит координаты в hydra/svg/outlines.json (вне репо). Фронт
 * (plans.js) будет читать их через ApiJsonController-расширение и
 * рисовать кликабельные полигоны поверх <img src="floor_img">.
 */

if (!defined('IN_CMS')) { exit(); }

define('OUTLINE_EDITOR_ROOT', dirname(__FILE__));

Plugin::setInfos([
    'id'                   => 'outline_editor',
    'title'                => 'Обводки',
    'description'          => 'Редактор обводок квартир на поэтажном плане',
    'license'              => 'Unlicense',
    'website'              => '',
    'version'              => '1.0.0',
    'require_wolf_version' => '0.8.1',
    'type'                 => 'both',
]);

Plugin::addController('outline_editor', 'Обводки', false, true);

Dispatcher::addRoute([
    '/outline_editor/api' => 'plugin/outline_editor/api',
]);
