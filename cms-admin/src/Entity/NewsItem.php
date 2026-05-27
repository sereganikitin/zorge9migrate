<?php

namespace App\Entity;

use App\Repository\NewsItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsItemRepository::class)]
#[ORM\Table(name: 'news_item')]
class NewsItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\ManyToOne(targetEntity: MediaItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MediaItem $coverImage = null;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->publishedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): self { $this->slug = $v; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): self { $this->title = $v; return $this; }

    public function getExcerpt(): ?string { return $this->excerpt; }
    public function setExcerpt(?string $v): self { $this->excerpt = $v; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $v): self { $this->body = $v; return $this; }

    public function getCoverImage(): ?MediaItem { return $this->coverImage; }
    public function setCoverImage(?MediaItem $m): self { $this->coverImage = $m; return $this; }

    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $d): self { $this->publishedAt = $d; return $this; }

    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $v): self { $this->published = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touchUpdatedAt(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function __toString(): string { return $this->title; }
}
