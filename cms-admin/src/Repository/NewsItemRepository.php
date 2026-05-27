<?php

namespace App\Repository;

use App\Entity\NewsItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NewsItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsItem::class);
    }

    public function findPublished(int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.published = true')
            ->orderBy('n.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?NewsItem
    {
        return $this->findOneBy(['slug' => $slug, 'published' => true]);
    }
}
