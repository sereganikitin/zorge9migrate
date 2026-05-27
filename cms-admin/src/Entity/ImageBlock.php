<?php

namespace App\Entity;

use App\Repository\ImageBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One ImageBlock per unique landing image. The same image may appear on
 * multiple pages; the override (`media`) applies everywhere.
 */
#[ORM\Entity(repositoryClass: ImageBlockRepository::class)]
#[ORM\Table(name: 'image_block')]
class ImageBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $blockKey;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $pagePaths = [];

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $defaultSrc = null;

    #[ORM\ManyToOne(targetEntity: MediaItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MediaItem $media = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

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

    public function getDefaultSrc(): ?string { return $this->defaultSrc; }
    public function setDefaultSrc(?string $v): self { $this->defaultSrc = $v; return $this; }

    public function getMedia(): ?MediaItem { return $this->media; }
    public function setMedia(?MediaItem $m): self
    {
        $this->media = $m;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getAlt(): ?string { return $this->alt; }
    public function setAlt(?string $v): self { $this->alt = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getEffectiveSrc(): ?string
    {
        return $this->media?->getPublicUrl() ?? $this->defaultSrc;
    }

    public function __toString(): string
    {
        return $this->label ?: $this->blockKey;
    }
}
