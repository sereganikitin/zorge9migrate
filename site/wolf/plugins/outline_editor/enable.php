<?php
if (!defined('IN_CMS')) { exit(); }

$path = CMS_ROOT . '/hydra/svg/outlines.json';
if (!file_exists($path)) {
    @file_put_contents(
        $path,
        json_encode(
            ['version' => 1, 'updated_at' => date('c'), 'outlines' => (object)[]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        )
    );
}

Flash::set('success', 'Outline Editor — плагин инициализирован');
