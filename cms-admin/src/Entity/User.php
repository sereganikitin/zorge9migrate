<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $username;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_ADMIN'];

    public function getId(): ?int { return $this->id; }

    public function getUsername(): string { return $this->username; }
    public function setUsername(string $u): self { $this->username = $u; return $this; }

    public function getUserIdentifier(): string { return $this->username; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): self { $this->password = $p; return $this; }

    public function getRoles(): array
    {
        $r = $this->roles;
        $r[] = 'ROLE_USER';
        return array_unique($r);
    }
    public function setRoles(array $r): self { $this->roles = $r; return $this; }

    public function eraseCredentials(): void {}
}
