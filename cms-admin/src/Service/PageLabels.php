<?php

namespace App\Service;

/**
 * Human-readable labels for landing page paths.
 * Single source of truth for the menu, list headers and filter helpers.
 */
final class PageLabels
{
    /** @var array<string,array{label:string, icon:string}> */
    public const PAGES = [
        ''               => ['label' => 'Главная',                  'icon' => 'fa fa-house'],
        'apartments'     => ['label' => 'Апартаменты',              'icon' => 'fa fa-building'],
        'penthouses'     => ['label' => 'Пентхаусы',                'icon' => 'fa fa-building-flag'],
        'parking'        => ['label' => 'Паркинг',                  'icon' => 'fa fa-square-parking'],
        'infrastructure' => ['label' => 'Инфраструктура',           'icon' => 'fa fa-city'],
        'location'       => ['label' => 'Локация',                  'icon' => 'fa fa-location-dot'],
        'improvement'    => ['label' => 'Благоустройство',          'icon' => 'fa fa-tree'],
        'style'          => ['label' => 'Стиль',                    'icon' => 'fa fa-palette'],
        'services'       => ['label' => 'Сервисы',                  'icon' => 'fa fa-concierge-bell'],
        'management'     => ['label' => 'Управление',               'icon' => 'fa fa-people-roof'],
        'investment'     => ['label' => 'Инвестиции',               'icon' => 'fa fa-chart-line'],
        'request'        => ['label' => 'Форма заявки',             'icon' => 'fa fa-envelope'],
        'privacy-policy' => ['label' => 'Политика конфиденциальности', 'icon' => 'fa fa-file-shield'],
    ];

    public function humanLabel(string $pagePath): string
    {
        return self::PAGES[$pagePath]['label'] ?? ($pagePath === '' ? 'Главная' : $pagePath);
    }

    public function icon(string $pagePath): string
    {
        return self::PAGES[$pagePath]['icon'] ?? 'fa fa-file';
    }

    /** @return list<string> */
    public function orderedPaths(): array
    {
        return array_keys(self::PAGES);
    }
}
