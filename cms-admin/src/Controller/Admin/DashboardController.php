<?php

namespace App\Controller\Admin;

use App\Service\PageLabels;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Symfony is mounted at /cms-admin/ in nginx; pathInfo is stripped of that
// prefix before matching, so we declare routes as if at root.
#[AdminDashboard(routePath: '/', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $urls,
        private readonly PageLabels $pages,
    ) {}

    #[Route('/', name: 'admin')]
    public function index(): Response
    {
        // Default landing in admin: list of text blocks. AdminUrlGenerator
        // needs both dashboard and CRUD controller to build the URL.
        return $this->redirect($this->urls
            ->setDashboard(self::class)
            ->setController(TextBlockCrudController::class)
            ->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Зорге 9 — CMS')
            ->setLocales(['ru'])
            ->setDefaultColorScheme('dark')
            ->renderContentMaximized();
    }

    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateFormat('dd.MM.yyyy')
            ->setDateTimeFormat('dd.MM.yyyy HH:mm')
            ->setPaginatorPageSize(50);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Страницы лендинга');

        // One submenu per landing page: тексты + картинки этого раздела.
        foreach ($this->pages->orderedPaths() as $path) {
            $label = $this->pages->humanLabel($path);
            $icon = $this->pages->icon($path);
            yield MenuItem::subMenu($label, $icon)->setSubItems([
                MenuItem::linkTo(TextBlockCrudController::class, 'Тексты', 'fa fa-pen')
                    ->setQueryParameter('page_filter', $path),
                MenuItem::linkTo(ImageBlockCrudController::class, 'Картинки', 'fa fa-image')
                    ->setQueryParameter('page_filter', $path),
            ]);
        }

        yield MenuItem::section('Общее');
        yield MenuItem::linkTo(NewsItemCrudController::class, 'Новости / акции', 'fa fa-newspaper');
        yield MenuItem::linkTo(MediaItemCrudController::class, 'Медиа-библиотека', 'fa fa-photo-film');
        yield MenuItem::linkTo(SiteSettingCrudController::class, 'Настройки сайта', 'fa fa-gear');

        yield MenuItem::section('');
        yield MenuItem::linkTo(UserCrudController::class, 'Пользователи', 'fa fa-user');
        yield MenuItem::linkToLogout('Выйти', 'fa fa-sign-out');
        yield MenuItem::linkToUrl('Открыть сайт', 'fa fa-external-link', '/')->setLinkTarget('_blank');
    }
}
