<?php

namespace App\Controller\Admin;

use App\Entity\TextBlock;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

class TextBlockCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TextBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Текстовый блок')
            ->setEntityLabelInPlural('Тексты лендинга')
            ->setDefaultSort(['pagePath' => 'ASC', 'blockKey' => 'ASC'])
            ->setSearchFields(['pagePath', 'blockKey', 'label', 'defaultValue', 'value']);
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
        yield TextField::new('pagePath', 'Страница')->setHelp('Например: index, apartments, location');
        yield TextField::new('blockKey', 'Ключ')->setHelp('Уникальный идентификатор блока');
        yield TextField::new('label', 'Описание')->setHelp('Подпись для удобства редактора')->hideOnIndex();
        yield TextareaField::new('defaultValue', 'Исходный текст')
            ->setHelp('Что было в оригинальной вёрстке. Только для справки — не редактируется отсюда.')
            ->setFormTypeOption('disabled', true)
            ->hideOnIndex();
        yield TextareaField::new('value', 'Текст для отображения')
            ->setHelp('Что показывать вместо исходного. Очистите поле, чтобы вернуть оригинал.')
            ->setNumOfRows(4);
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
