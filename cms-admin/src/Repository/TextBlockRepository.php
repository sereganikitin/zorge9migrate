<?php

namespace App\Repository;

use App\Entity\TextBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TextBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TextBlock::class);
    }

    /** @return array<string, string> map of blockKey => effective value for a page */
    public function effectiveByPage(string $pagePath): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.blockKey AS k', 'COALESCE(b.value, b.defaultValue) AS v')
            ->andWhere('b.pagePath = :p')
            ->setParameter('p', $pagePath)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['k']] = $r['v'];
        }
        return $out;
    }
}
