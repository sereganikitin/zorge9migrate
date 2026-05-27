<?php

namespace App\Controller\Admin;

use App\Entity\SiteSetting;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class SiteSettingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SiteSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Настройка')
            ->setEntityLabelInPlural('Настройки сайта')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'label', 'value'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'Глобальные настройки сайта в формате <code>имя → значение</code>. '
                . 'Например, для промо-полосы в шапке нужно три настройки: '
                . '<code>promo.enabled</code> (1/0), <code>promo.text</code>, <code>promo.link</code>. '
                . 'Тексты страниц редактируются <strong>не здесь</strong>, а через «Главная → Тексты», «Локация → Тексты» и т.д.'
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Имя настройки')
            ->setHelp('Например: promo.enabled, promo.text, promo.link');
        yield TextField::new('label', 'Описание');
        yield TextareaField::new('value', 'Значение')->setNumOfRows(3);
        yield DateTimeField::new('updatedAt', 'Изменено')->hideOnForm();
    }
}
