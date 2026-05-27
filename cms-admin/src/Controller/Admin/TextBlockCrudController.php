<?php

namespace App\Controller\Admin;

use App\Entity\TextBlock;
use App\Service\PageLabels;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TextBlockCrudController extends AbstractCrudController
{
    public function __construct(private readonly PageLabels $pages) {}

    public static function getEntityFqcn(): string
    {
        return TextBlock::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $page = $this->getCurrentPageFilter();
        $titlePlural = $page === null
            ? 'Тексты лендинга'
            : 'Тексты — ' . $this->pages->humanLabel($page);

        return $crud
            ->setEntityLabelInSingular('Текстовый блок')
            ->setEntityLabelInPlural($titlePlural)
            ->setPageTitle(Crud::PAGE_INDEX, $titlePlural)
            ->setDefaultSort(['pagePath' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['blockKey', 'label', 'defaultValue', 'value'])
            ->setPaginatorPageSize(40);
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
        if (!$req) {
            return null;
        }
        $p = $req->query->get('page_filter');
        return $p === null ? null : (string) $p;
    }

    public function configureFields(string $pageName): iterable
    {
        if ($pageName === Crud::PAGE_INDEX) {
            $filtered = $this->getCurrentPageFilter() !== null;
            // When user is already inside a per-page section, hide the "Страница"
            // column — it would repeat the same value on every row.
            if (!$filtered) {
                yield TextField::new('pagePath', 'Страница')
                    ->formatValue(fn($v) => $this->pages->humanLabel((string) $v))
                    ->setColumns(2);
            }
            yield TextField::new('label', 'Что это')->setColumns($filtered ? 3 : 2);
            yield TextareaField::new('value', 'Текст сейчас')
                ->formatValue(function ($value, $entity) {
                    /** @var TextBlock $entity */
                    $effective = $entity->getValue() ?? $entity->getDefaultValue() ?? '';
                    $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($effective)));
                    return mb_strlen($plain) > 220
                        ? mb_substr($plain, 0, 220) . '…'
                        : $plain;
                })
                ->setColumns($filtered ? 9 : 8);
            return;
        }

        // Form (new / edit / detail) view
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();
        yield TextField::new('pagePath', 'Страница')
            ->formatValue(fn($v) => $this->pages->humanLabel((string) $v))
            ->setFormTypeOption('disabled', true);
        yield TextField::new('label', 'Описание блока')
            ->setHelp('Подпись для редактора (короткий ярлык).');
        yield TextField::new('blockKey', 'Внутренний ключ')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
        yield TextareaField::new('defaultValue', 'Исходный текст')
            ->setHelp('Что было в оригинальной вёрстке. Только для справки — не редактируется.')
            ->setFormTypeOption('disabled', true)
            ->setNumOfRows(4);
        yield TextareaField::new('value', 'Текст для отображения')
            ->setHelp('Что показывать вместо исходного. Очистите поле, чтобы вернуть оригинал.')
            ->setNumOfRows(6);
        yield DateTimeField::new('updatedAt', 'Изменён')->hideOnForm();
    }
}
