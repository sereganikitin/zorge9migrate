<?php

namespace App\Repository;

use App\Entity\ImageBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ImageBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImageBlock::class);
    }

    /** @return array<string, string> map of blockKey => effective src for a page */
    public function effectiveByPage(string $pagePath): array
    {
        $rows = $this->createQueryBuilder('b')
            ->leftJoin('b.media', 'm')
            ->select('b.blockKey AS k', 'b.defaultSrc AS ds', 'm.filename AS mf')
            ->andWhere('b.pagePath = :p')
            ->setParameter('p', $pagePath)
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['k']] = $r['mf']
                ? '/cms-admin/uploads/media/' . $r['mf']
                : $r['ds'];
        }
        return $out;
    }
}
