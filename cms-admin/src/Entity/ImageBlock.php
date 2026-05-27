<?php

namespace App\Entity;

use App\Repository\ImageBlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Editable <img> on a landing page. Identified by (pagePath, blockKey).
 * defaultSrc is the original src in the static HTML.
 * media is the override (uploaded file). If null, the renderer keeps the original src.
 */
#[ORM\Entity(repositoryClass: ImageBlockRepository::class)]
#[ORM\Table(name: 'image_block')]
#[ORM\UniqueConstraint(name: 'image_block_key', columns: ['page_path', 'block_key'])]
class ImageBlock
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

    public function getPagePath(): string { return $this->pagePath; }
    public function setPagePath(string $v): self { $this->pagePath = $v; return $this; }

    public function getBlockKey(): string { return $this->blockKey; }
    public function setBlockKey(string $v): self { $this->blockKey = $v; return $this; }

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
        return ($this->label ?: $this->blockKey) . ' (' . $this->pagePath . ')';
    }
}
