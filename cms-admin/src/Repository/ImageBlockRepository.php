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

    public function findOneByKey(string $blockKey): ?ImageBlock
    {
        return $this->findOneBy(['blockKey' => $blockKey]);
    }

    /** @return array<string,string> map of blockKey → effective public URL (override media or default src). */
    public function effectiveAll(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->leftJoin('b.media', 'm')
            ->select('b.blockKey AS k', 'b.defaultSrc AS ds', 'm.filename AS mf')
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
