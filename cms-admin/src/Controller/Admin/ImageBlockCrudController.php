<?php

namespace App\Controller\Admin;

use App\Entity\ImageBlock;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class ImageBlockCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ImageBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Картинка')
            ->setEntityLabelInPlural('Картинки лендинга')
            ->setDefaultSort(['pagePath' => 'ASC', 'blockKey' => 'ASC'])
            ->setSearchFields(['pagePath', 'blockKey', 'label']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('pagePath', 'Страница'))
            ->add(TextFilter::new('blockKey', 'Ключ блока'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('pagePath', 'Страница');
        yield TextField::new('blockKey', 'Ключ');
        yield TextField::new('label', 'Описание')->hideOnIndex();
        yield TextField::new('defaultSrc', 'Исходный путь')
            ->setHelp('Что было в оригинальной вёрстке. Только для справки.')
            ->setFormTypeOption('disabled', true)
            ->hideOnIndex();
        yield AssociationField::new('media', 'Картинка для замены')
            ->setHelp('Выберите файл из медиа-библиотеки. Очистите, чтобы вернуть исходную.');
        yield TextField::new('alt', 'Alt-текст')->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
