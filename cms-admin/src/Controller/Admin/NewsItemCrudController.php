<?php

namespace App\Controller\Admin;

use App\Entity\NewsItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class NewsItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NewsItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Новость / акция')
            ->setEntityLabelInPlural('Новости / акции')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setSearchFields(['slug', 'title', 'excerpt', 'body']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Заголовок');
        yield TextField::new('slug', 'URL-слаг')
            ->setHelp('Латиницей, без пробелов. Будет открываться по /news/{слаг}');
        yield BooleanField::new('published', 'Опубликовано');
        yield DateTimeField::new('publishedAt', 'Дата публикации')->hideOnIndex();
        yield AssociationField::new('coverImage', 'Обложка');
        yield TextareaField::new('excerpt', 'Краткое описание')->setNumOfRows(3)->hideOnIndex();
        yield TextEditorField::new('body', 'Текст')->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Изменено')->hideOnForm()->hideOnIndex();
    }
}
