<?php

namespace App\Controller\Admin;

use App\Entity\ImageBlock;
use App\Service\PageLabels;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ImageBlockCrudController extends AbstractCrudController
{
    public function __construct(private readonly PageLabels $pages) {}

    public static function getEntityFqcn(): string
    {
        return ImageBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $page = $this->getCurrentPageFilter();
        $titlePlural = $page === null
            ? 'Картинки лендинга'
            : 'Картинки — ' . $this->pages->humanLabel($page);

        return $crud
            ->setEntityLabelInSingular('Картинка')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['pagePath' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultSrc'])
            ->setPaginatorPageSize(30);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $page = $this->getCurrentPageFilter();
        if ($page !== null) {
            $qb->andWhere('entity.pagePath = :pf')->setParameter('pf', $page);
        }
        return $qb;
    }

    private function getCurrentPageFilter(): ?string
    {
        $req = $this->container->get('request_stack')->getCurrentRequest();
        return $req && $req->query->has('page_filter') ? (string) $req->query->get('page_filter') : null;
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            $filtered = $this->getCurrentPageFilter() !== null;
            yield ImageField::new('defaultSrc', 'Превью')
                ->setBasePath('')
                ->formatValue(function ($value, $entity) {
                    /** @var ImageBlock $entity */
                    $media = $entity->getMedia();
                    return $media && $media->getFilename()
                        ? '/cms-admin/uploads/media/' . $media->getFilename()
                        : (string) $entity->getDefaultSrc();
                });
            if (!$filtered) {
                yield TextField::new('pagePath', 'Страница')
                    ->formatValue(fn($v) => $this->pages->humanLabel((string) $v));
            }
            yield TextField::new('label', 'Что это');
            yield AssociationField::new('media', 'Заменено?')
                ->formatValue(fn($v, $entity) => $entity->getMedia() ? 'да' : '');
            return;
        }

        // Form view
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();
        yield TextField::new('pagePath', 'Страница')
            ->formatValue(fn($v) => $this->pages->humanLabel((string) $v))
            ->setFormTypeOption('disabled', true);
        yield TextField::new('label', 'Описание блока');
        yield TextField::new('blockKey', 'Внутренний ключ')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
        yield ImageField::new('defaultSrc', 'Исходная картинка')
            ->setBasePath('')
            ->setFormTypeOption('disabled', true)
            ->hideOnDetail();
        yield TextField::new('defaultSrc', 'Исходный путь')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
        yield AssociationField::new('media', 'Картинка для замены')
            ->setHelp('Выберите файл из медиа-библиотеки. Очистите — вернётся оригинальная.');
        yield TextField::new('alt', 'Alt-текст (для SEO/доступности)');
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
