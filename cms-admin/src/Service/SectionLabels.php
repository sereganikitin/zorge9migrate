<?php

namespace App\Service;

/**
 * Human-readable labels for the landing's logical sections (anchor-to-anchor groups).
 *
 * Ordered: section IDs in the rough order they appear on the landing page,
 * so the admin sidebar renders the same way the visitor scrolls.
 */
final class SectionLabels
{
    /** @var array<string,array{label:string, icon:string}> */
    public const SECTIONS = [
        'header'         => ['label' => 'Шапка / навигация',         'icon' => 'fa fa-bars'],
        'intro'          => ['label' => 'Главный экран',             'icon' => 'fa fa-house'],
        'about'          => ['label' => 'О проекте',                 'icon' => 'fa fa-circle-info'],
        'location'       => ['label' => 'Локация',                   'icon' => 'fa fa-location-dot'],
        'map'            => ['label' => 'Карта расположения',        'icon' => 'fa fa-map'],
        'panorama'       => ['label' => 'Панорама',                  'icon' => 'fa fa-binoculars'],
        'style'          => ['label' => 'Стиль / архитектура',       'icon' => 'fa fa-palette'],
        'gallery'        => ['label' => 'Галерея',                   'icon' => 'fa fa-images'],
        'time'           => ['label' => 'Хронология',                'icon' => 'fa fa-clock'],
        'lobby'          => ['label' => 'Лобби',                     'icon' => 'fa fa-couch'],
        'advantages'     => ['label' => 'Преимущества',              'icon' => 'fa fa-star'],
        'fitness'        => ['label' => 'Фитнес-клуб',               'icon' => 'fa fa-dumbbell'],
        'apartments'     => ['label' => 'Апартаменты — hero',        'icon' => 'fa fa-building'],
        'penthouses'     => ['label' => 'Пентхаусы — hero',          'icon' => 'fa fa-building-flag'],
        'infrastructure' => ['label' => 'Инфраструктура — hero',     'icon' => 'fa fa-city'],
        'improvement'    => ['label' => 'Благоустройство',           'icon' => 'fa fa-tree'],
        'services'       => ['label' => 'Сервисы',                   'icon' => 'fa fa-concierge-bell'],
        'parking'        => ['label' => 'Паркинг',                   'icon' => 'fa fa-square-parking'],
        'management'     => ['label' => 'Управление',                'icon' => 'fa fa-people-roof'],
        'investment'     => ['label' => 'Инвестиции',                'icon' => 'fa fa-chart-line'],
        'request'        => ['label' => 'Форма заявки',              'icon' => 'fa fa-envelope'],
        'footer'         => ['label' => 'Футер',                     'icon' => 'fa fa-shoe-prints'],
        'offers'         => ['label' => 'Акции (всплывающее)',       'icon' => 'fa fa-tag'],
        'preloader'      => ['label' => 'Прелоадер',                 'icon' => 'fa fa-spinner'],
        'cookies'        => ['label' => 'Cookie-уведомление',        'icon' => 'fa fa-cookie-bite'],
        'turn-message'   => ['label' => 'Сообщение «поверните устройство»', 'icon' => 'fa fa-mobile-screen'],
    ];

    public const FALLBACK_LABEL = 'Прочее';
    public const FALLBACK_ICON = 'fa fa-folder';

    public function humanLabel(string $section): string
    {
        if ($section === 'unknown' || $section === '') {
            return self::FALLBACK_LABEL;
        }
        return self::SECTIONS[$section]['label'] ?? $section;
    }

    public function icon(string $section): string
    {
        if ($section === 'unknown' || $section === '') {
            return self::FALLBACK_ICON;
        }
        return self::SECTIONS[$section]['icon'] ?? self::FALLBACK_ICON;
    }

    /** @return list<string> */
    public function orderedIds(): array
    {
        return array_keys(self::SECTIONS);
    }
}
