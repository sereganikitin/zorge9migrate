<?php

namespace App\Controller\Admin;

use App\Entity\TextBlock;
use App\Service\PageLabels;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TextBlockCrudController extends AbstractCrudController
{
    public const FILTER_SHARED = '__shared__';

    public function __construct(
        private readonly PageLabels $pages,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return TextBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $page = $this->getCurrentPageFilter();
        $titlePlural = match (true) {
            $page === self::FILTER_SHARED => 'Тексты — Общие для всех страниц',
            $page === null                => 'Тексты лендинга',
            default                       => 'Тексты — Только на: ' . $this->pages->humanLabel($page),
        };

        return $crud
            ->setEntityLabelInSingular('Текстовый блок')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultValue', 'value'])
            ->setHelp(Crud::PAGE_INDEX, $this->indexHelp($page))
            ->setPaginatorPageSize(40);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $page = $this->getCurrentPageFilter();
        if ($page === null) {
            return $qb;
        }
        $ids = $this->matchingIds($page);
        // andWhere with empty IN-list — return 0 rows safely.
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
            $sql = 'SELECT id FROM text_block WHERE JSON_LENGTH(page_paths) > 1';
            return array_map('intval', $conn->fetchFirstColumn($sql));
        }
        // Only-on-this-page: exactly one entry in page_paths and that entry == filter.
        $sql = 'SELECT id FROM text_block WHERE JSON_LENGTH(page_paths) = 1 AND JSON_CONTAINS(page_paths, JSON_QUOTE(:p))';
        return array_map('intval', $conn->fetchFirstColumn($sql, ['p' => $filter]));
    }

    private function getCurrentPageFilter(): ?string
    {
        $req = $this->container->get('request_stack')->getCurrentRequest();
        return $req && $req->query->has('page_filter') ? (string) $req->query->get('page_filter') : null;
    }

    private function indexHelp(?string $page): string
    {
        if ($page === self::FILTER_SHARED) {
            return 'Тексты, которые встречаются <strong>на нескольких страницах</strong> '
                . '(шапка, навигация, футер, общие секции). Изменение применится сразу на всех страницах.';
        }
        if ($page === null) {
            return 'Все тексты лендинга — без фильтра. Чтобы видеть только своё, выбирайте раздел в боковом меню.';
        }
        $label = $this->pages->humanLabel($page);
        return sprintf('Тексты, которые есть <strong>только</strong> на странице «%s». '
            . 'Общие блоки смотрите в разделе «Общие для всех страниц».', $label);
    }

    public function configureFields(string $pageName): iterable
    {
        $filtered = $this->getCurrentPageFilter() !== null;

        if ($pageName === Crud::PAGE_INDEX) {
            if (!$filtered) {
                yield TextField::new('pagePathsLabel', 'Где')
                    ->formatValue(fn($v, $entity) => $this->renderPagesCell($entity->getPagePaths()))
                    ->setColumns(2);
            }
            yield TextField::new('label', 'Что это')->setColumns($filtered ? 3 : 3);
            yield TextareaField::new('value', 'Текст сейчас')
                ->formatValue(function ($value, $entity) {
                    /** @var TextBlock $entity */
                    $effective = $entity->getValue() ?? $entity->getDefaultValue() ?? '';
                    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($effective)));
                    return mb_strlen($plain) > 220
                        ? mb_substr($plain, 0, 220) . '…'
                        : $plain;
                })
                ->setColumns($filtered ? 9 : 7);
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
        yield TextareaField::new('defaultValue', 'Исходный текст')
            ->setHelp('Что было в оригинальной вёрстке. Только для справки.')
            ->setFormTypeOption('disabled', true)
            ->setNumOfRows(4);
        yield TextareaField::new('value', 'Текст для отображения')
            ->setHelp('Что показывать вместо исходного. Очистите поле, чтобы вернуть оригинал.')
            ->setNumOfRows(6);
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
