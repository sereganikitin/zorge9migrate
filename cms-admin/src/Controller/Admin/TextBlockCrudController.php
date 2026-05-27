<?php

namespace App\Controller\Admin;

use App\Entity\TextBlock;
use App\Service\SectionLabels;
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
    public function __construct(
        private readonly SectionLabels $sections,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getEntityFqcn(): string
    {
        return TextBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $section = $this->getSectionFilter();
        $titlePlural = $section === null
            ? 'Тексты лендинга'
            : 'Тексты — ' . $this->sections->humanLabel($section);

        return $crud
            ->setEntityLabelInSingular('Текстовый блок')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultValue', 'value'])
            ->setHelp(Crud::PAGE_INDEX, $this->indexHelp($section))
            ->setPaginatorPageSize(40);
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
            // Either explicitly marked unknown OR no section recorded.
            $sql = 'SELECT id FROM text_block WHERE JSON_CONTAINS(sections, \'"unknown"\') OR JSON_LENGTH(sections) = 0';
            return array_map('intval', $conn->fetchFirstColumn($sql));
        }
        $sql = 'SELECT id FROM text_block WHERE JSON_CONTAINS(sections, JSON_QUOTE(:s))';
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
            return 'Все тексты лендинга. Выбирайте раздел в боковом меню чтобы видеть только нужную секцию.';
        }
        if ($section === 'unknown') {
            return 'Тексты, которые не удалось автоматически отнести к одной из известных секций — '
                . 'это всякие модалки, всплывающие подсказки и подобные элементы.';
        }
        return sprintf(
            'Все тексты секции «%s». В колонке «Где ещё» показано, если этот же текст '
            . 'встречается в других секциях — правка применится во всех местах сразу.',
            $this->sections->humanLabel($section)
        );
    }

    public function configureFields(string $pageName): iterable
    {
        $section = $this->getSectionFilter();
        $filtered = $section !== null;

        if ($pageName === Crud::PAGE_INDEX) {
            yield TextField::new('label', 'Что это')->setColumns(4);
            yield TextareaField::new('value', 'Текст сейчас')
                ->formatValue(function ($value, $entity) {
                    /** @var TextBlock $entity */
                    $effective = $entity->getValue() ?? $entity->getDefaultValue() ?? '';
                    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($effective)));
                    return mb_strlen($plain) > 220
                        ? mb_substr($plain, 0, 220) . '…'
                        : $plain;
                })
                ->setColumns(7);
            yield \EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField::new('sections', 'Где ещё')
                ->formatValue(fn($v, $entity) => $this->renderSecondarySections($entity->getSections(), $section))
                ->setColumns(1);
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
        yield TextareaField::new('defaultValue', 'Исходный текст')
            ->setHelp('Что было в оригинальной вёрстке. Только для справки.')
            ->setFormTypeOption('disabled', true)
            ->setNumOfRows(4);
        yield TextareaField::new('value', 'Текст для отображения')
            ->setHelp('Что показывать вместо исходного. Очистите поле, чтобы вернуть оригинал.')
            ->setNumOfRows(6);
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }

    /** @param list<string> $sections */
    private function renderSecondarySections(array $sections, ?string $current): string
    {
        $others = array_values(array_filter($sections, fn($s) => $s !== $current));
        if (!$others) {
            return '';
        }
        $labels = array_map(fn($s) => $this->sections->humanLabel($s), $others);
        return implode(', ', $labels);
    }

    /** @param list<string> $sections */
    private function renderAllSections(array $sections): string
    {
        if (!$sections) {
            return '—';
        }
        $labels = array_map(fn($s) => $this->sections->humanLabel($s), $sections);
        return implode(', ', $labels);
    }
}
