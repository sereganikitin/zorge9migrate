<?php

namespace App\Entity;

use App\Repository\SiteSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton-ish global settings keyed by name.
 * Used for things like the promo banner strip in the header (text, link, on/off).
 *
 * Conventions:
 *   - promo.enabled    (boolean as "1" / "0")
 *   - promo.text       (display text)
 *   - promo.link       (target href)
 */
#[ORM\Entity(repositoryClass: SiteSettingRepository::class)]
#[ORM\Table(name: 'site_setting')]
class SiteSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $v): self
    {
        $this->value = $v;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string { return $this->label ?: $this->name; }
}
