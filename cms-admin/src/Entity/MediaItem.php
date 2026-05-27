<?php

namespace App\Entity;

use App\Repository\MediaItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: MediaItemRepository::class)]
#[ORM\Table(name: 'media_item')]
#[Vich\Uploadable]
class MediaItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $sizeBytes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[Vich\UploadableField(mapping: 'media', fileNameProperty: 'filename', size: 'sizeBytes', mimeType: 'mimeType', originalName: 'originalName')]
    private ?File $file = null;

    public function __construct() { $this->uploadedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getFilename(): ?string { return $this->filename; }
    public function setFilename(?string $v): self { $this->filename = $v; return $this; }

    public function getOriginalName(): ?string { return $this->originalName; }
    public function setOriginalName(?string $v): self { $this->originalName = $v; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $v): self { $this->mimeType = $v; return $this; }

    public function getSizeBytes(): ?int { return $this->sizeBytes; }
    public function setSizeBytes(?int $v): self { $this->sizeBytes = $v; return $this; }

    public function getAlt(): ?string { return $this->alt; }
    public function setAlt(?string $v): self { $this->alt = $v; return $this; }

    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }

    public function getFile(): ?File { return $this->file; }
    public function setFile(?File $file = null): self
    {
        $this->file = $file;
        if ($file) {
            $this->uploadedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getPublicUrl(): ?string
    {
        return $this->filename ? '/cms-admin/uploads/media/' . $this->filename : null;
    }

    public function __toString(): string
    {
        return $this->originalName ?? $this->filename ?? ('Media #' . $this->id);
    }
}
