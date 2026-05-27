<?php

namespace App\Entity;

use App\Repository\TextBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One TextBlock per unique landing text. The same text may appear on several
 * landing pages (e.g. header / footer / shared sections); we store it once
 * and remember which pages it shows on via `pagePaths`. Editing the
 * `value` therefore changes the text everywhere it is used.
 */
#[ORM\Entity(repositoryClass: TextBlockRepository::class)]
#[ORM\Table(name: 'text_block')]
class TextBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Globally unique; the renderer matches this against `data-cms-text-key` in the HTML. */
    #[ORM\Column(length: 120, unique: true)]
    private string $blockKey;

    /** @var list<string> Landing pages where this block appears (e.g. "", "apartments", "location"). */
    #[ORM\Column(type: Types::JSON)]
    private array $pagePaths = [];

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

    public function getBlockKey(): string { return $this->blockKey; }
    public function setBlockKey(string $v): self { $this->blockKey = $v; return $this; }

    /** @return list<string> */
    public function getPagePaths(): array { return $this->pagePaths; }
    /** @param list<string> $v */
    public function setPagePaths(array $v): self { $this->pagePaths = array_values(array_unique($v)); return $this; }
    public function addPagePath(string $path): self
    {
        if (!in_array($path, $this->pagePaths, true)) {
            $this->pagePaths[] = $path;
        }
        return $this;
    }

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
        return $this->label ?: $this->blockKey;
    }
}
