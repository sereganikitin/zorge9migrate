<?php

namespace App\Controller\Admin;

use App\Entity\ImageBlock;
use App\Service\PageLabels;
use Doctrine\ORM\EntityManagerInterface;
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
    public const FILTER_SHARED = '__shared__';

    public function __construct(
        private readonly PageLabels $pages,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ImageBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $page = $this->getCurrentPageFilter();
        $titlePlural = match (true) {
            $page === self::FILTER_SHARED => 'Картинки — Общие для всех страниц',
            $page === null                => 'Картинки лендинга',
            default                       => 'Картинки — Только на: ' . $this->pages->humanLabel($page),
        };

        return $crud
            ->setEntityLabelInSingular('Картинка')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultSrc'])
            ->setHelp(Crud::PAGE_INDEX, $this->indexHelp($page))
            ->setPaginatorPageSize(30);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $page = $this->getCurrentPageFilter();
        if ($page === null) {
            return $qb;
        }
        $ids = $this->matchingIds($page);
        if ($ids === []) {
            $qb->andWhere('1 = 0');
            return $qb;
        }
        $qb->andWhere('entity.id IN (:pf_ids)')->setParameter('pf_ids', $ids);
        return $qb;
    }

    /** @return list<int> */
    private function matchingIds(string $filter): array
    {
        $conn = $this->em->getConnection();
        if ($filter === self::FILTER_SHARED) {
            return array_map('intval', $conn->fetchFirstColumn(
                'SELECT id FROM image_block WHERE JSON_LENGTH(page_paths) > 1'
            ));
        }
        return array_map('intval', $conn->fetchFirstColumn(
            'SELECT id FROM image_block WHERE JSON_LENGTH(page_paths) = 1 AND JSON_CONTAINS(page_paths, JSON_QUOTE(:p))',
            ['p' => $filter]
        ));
    }

    private function getCurrentPageFilter(): ?string
    {
        $req = $this->container->get('request_stack')->getCurrentRequest();
        return $req && $req->query->has('page_filter') ? (string) $req->query->get('page_filter') : null;
    }

    private function indexHelp(?string $page): string
    {
        if ($page === self::FILTER_SHARED) {
            return 'Картинки, которые видны <strong>на нескольких страницах</strong> '
                . '(логотипы, иконки, общие блоки). Замена применяется ко всем страницам сразу.';
        }
        if ($page === null) {
            return 'Все картинки лендинга. Чтобы видеть только своё, выбирайте раздел в боковом меню.';
        }
        $label = $this->pages->humanLabel($page);
        return sprintf('Картинки <strong>только</strong> со страницы «%s». Общие смотрите в «Общие для всех страниц».', $label);
    }

    public function configureFields(string $pageName): iterable
    {
        $filtered = $this->getCurrentPageFilter() !== null;

        if ($pageName === Crud::PAGE_INDEX) {
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
                yield TextField::new('pagePathsLabel', 'Где')
                    ->formatValue(fn($v, $entity) => $this->renderPagesCell($entity->getPagePaths()));
            }
            yield TextField::new('label', 'Что это');
            yield AssociationField::new('media', 'Заменено?')
                ->formatValue(fn($v, $entity) => $entity->getMedia() ? 'да' : '');
            return;
        }

        // Form view
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();
        yield TextField::new('pagePathsLabel', 'Где встречается')
            ->formatValue(fn($v, $entity) => $this->renderPagesCell($entity->getPagePaths()))
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

    /** @param list<string> $paths */
    private function renderPagesCell(array $paths): string
    {
        if (count($paths) > 1) {
            return 'Общий (' . count($paths) . ' стр.)';
        }
        return $this->pages->humanLabel($paths[0] ?? '');
    }
}
