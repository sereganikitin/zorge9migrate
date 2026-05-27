<?php

namespace App\Controller\Admin;

use App\Entity\ImageBlock;
use App\Service\SectionLabels;
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
    public function __construct(
        private readonly SectionLabels $sections,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ImageBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $section = $this->getSectionFilter();
        $titlePlural = $section === null
            ? 'Картинки лендинга'
            : 'Картинки — ' . $this->sections->humanLabel($section);

        return $crud
            ->setEntityLabelInSingular('Картинка')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultSrc'])
            ->setHelp(Crud::PAGE_INDEX, $this->indexHelp($section))
            ->setPaginatorPageSize(30);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $section = $this->getSectionFilter();
        if ($section === null) {
            return $qb;
        }
        $ids = $this->matchingIds($section);
        if ($ids === []) {
            $qb->andWhere('1 = 0');
            return $qb;
        }
        $qb->andWhere('entity.id IN (:section_ids)')->setParameter('section_ids', $ids);
        return $qb;
    }

    /** @return list<int> */
    private function matchingIds(string $section): array
    {
        $conn = $this->em->getConnection();
        if ($section === 'unknown') {
            $sql = 'SELECT id FROM image_block WHERE JSON_CONTAINS(sections, \'"unknown"\') OR JSON_LENGTH(sections) = 0';
            return array_map('intval', $conn->fetchFirstColumn($sql));
        }
        $sql = 'SELECT id FROM image_block WHERE JSON_CONTAINS(sections, JSON_QUOTE(:s))';
        return array_map('intval', $conn->fetchFirstColumn($sql, ['s' => $section]));
    }

    private function getSectionFilter(): ?string
    {
        $req = $this->container->get('request_stack')->getCurrentRequest();
        return $req && $req->query->has('section_filter') ? (string) $req->query->get('section_filter') : null;
    }

    private function indexHelp(?string $section): string
    {
        if ($section === null) {
            return 'Все картинки лендинга. Выбирайте раздел в боковом меню для нужной секции.';
        }
        if ($section === 'unknown') {
            return 'Картинки, которые не удалось автоматически отнести к секции (карусели, модалки).';
        }
        return sprintf(
            'Все картинки секции «%s». В колонке «Где ещё» — другие секции, где встречается эта же картинка.',
            $this->sections->humanLabel($section)
        );
    }

    public function configureFields(string $pageName): iterable
    {
        $section = $this->getSectionFilter();

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
            yield TextField::new('label', 'Что это');
            yield AssociationField::new('media', 'Заменено?')
                ->formatValue(fn($v, $entity) => $entity->getMedia() ? 'да' : '');
            yield \EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField::new('sections', 'Где ещё')
                ->formatValue(fn($v, $entity) => $this->renderSecondarySections($entity->getSections(), $section));
            return;
        }

        // Form view
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();
        yield TextField::new('label', 'Описание блока');
        yield \EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField::new('sections', 'В каких секциях')
            ->formatValue(fn($v, $entity) => $this->renderAllSections($entity->getSections()))
            ->setFormTypeOption('disabled', true);
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

    /** @param list<string> $sections */
    private function renderSecondarySections(array $sections, ?string $current): string
    {
        $others = array_values(array_filter($sections, fn($s) => $s !== $current));
        if (!$others) return '';
        return implode(', ', array_map(fn($s) => $this->sections->humanLabel($s), $others));
    }

    /** @param list<string> $sections */
    private function renderAllSections(array $sections): string
    {
        if (!$sections) return '—';
        return implode(', ', array_map(fn($s) => $this->sections->humanLabel($s), $sections));
    }
}
