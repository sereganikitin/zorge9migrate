<?php

namespace App\Entity;

use App\Repository\TextBlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Editable text block on a landing page. Identified by (pagePath, blockKey).
 * defaultValue is what is in the static HTML — never edited from admin.
 * value is the override; if null, the renderer keeps the original.
 */
#[ORM\Entity(repositoryClass: TextBlockRepository::class)]
#[ORM\Table(name: 'text_block')]
#[ORM\UniqueConstraint(name: 'text_block_key', columns: ['page_path', 'block_key'])]
class TextBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $pagePath;

    #[ORM\Column(length: 120)]
    private string $blockKey;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $defaultValue = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getPagePath(): string { return $this->pagePath; }
    public function setPagePath(string $v): self { $this->pagePath = $v; return $this; }

    public function getBlockKey(): string { return $this->blockKey; }
    public function setBlockKey(string $v): self { $this->blockKey = $v; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $v): self { $this->label = $v; return $this; }

    public function getDefaultValue(): ?string { return $this->defaultValue; }
    public function setDefaultValue(?string $v): self { $this->defaultValue = $v; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $v): self
    {
        $this->value = $v;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getEffectiveValue(): ?string
    {
        return $this->value ?? $this->defaultValue;
    }

    public function __toString(): string
    {
        return ($this->label ?: $this->blockKey) . ' (' . $this->pagePath . ')';
    }
}
