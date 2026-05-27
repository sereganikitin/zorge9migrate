<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пользователь')
            ->setEntityLabelInPlural('Пользователи');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('username', 'Логин');
        yield TextField::new('plainPassword', 'Новый пароль')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('required', $pageName === Crud::PAGE_NEW)
            ->onlyOnForms()
            ->setHelp('Оставьте пустым, чтобы не менять пароль');
    }

    /** @param User $entityInstance */
    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $em, $entityInstance): void
    {
        $this->hashPlainPasswordIfSet($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    /** @param User $entityInstance */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $em, $entityInstance): void
    {
        $this->hashPlainPasswordIfSet($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    private function hashPlainPasswordIfSet(User $u): void
    {
        // EasyAdmin gathers the password into a temporary virtual field. We pull it
        // from the request because the User entity itself has no `plainPassword`.
        $req = $this->container->get('request_stack')->getCurrentRequest();
        $form = $req?->request->all();
        $plain = null;
        array_walk_recursive($form, function ($v, $k) use (&$plain) {
            if ($k === 'plainPassword' && is_string($v) && $v !== '') {
                $plain = $v;
            }
        });
        if ($plain !== null) {
            $u->setPassword($this->hasher->hashPassword($u, $plain));
        }
    }
}
