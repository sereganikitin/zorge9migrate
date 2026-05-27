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
            ->setSearchFields(['name', 'label', 'value']);
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
