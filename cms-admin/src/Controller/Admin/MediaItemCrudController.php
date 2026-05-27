<?php

namespace App\Controller\Admin;

use App\Entity\MediaItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class MediaItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MediaItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Файл')
            ->setEntityLabelInPlural('Медиа-библиотека')
            ->setDefaultSort(['uploadedAt' => 'DESC'])
            ->setSearchFields(['originalName', 'filename', 'alt']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        if ($pageName === Crud::PAGE_INDEX || $pageName === Crud::PAGE_DETAIL) {
            yield ImageField::new('filename', 'Превью')
                ->setBasePath('/cms-admin/uploads/media')
                ->onlyOnIndex();
        }
        if ($pageName === Crud::PAGE_NEW || $pageName === Crud::PAGE_EDIT) {
            yield TextField::new('file', 'Файл')
                ->setFormType(VichImageType::class)
                ->setFormTypeOptions([
                    'allow_delete' => false,
                    'download_uri' => false,
                ])
                ->onlyOnForms();
        }
        yield TextField::new('originalName', 'Имя файла')->hideOnForm();
        yield TextField::new('alt', 'Alt-текст');
        yield TextField::new('mimeType', 'Тип')->hideOnForm();
        yield NumberField::new('sizeBytes', 'Размер (байт)')->hideOnForm();
        yield DateTimeField::new('uploadedAt', 'Загружен')->hideOnForm();
    }
}
