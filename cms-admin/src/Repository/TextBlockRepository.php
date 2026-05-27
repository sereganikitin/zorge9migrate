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

    public function findOneByKey(string $blockKey): ?TextBlock
    {
        return $this->findOneBy(['blockKey' => $blockKey]);
    }

    /** @return array<string,string> map of blockKey → effective value (override or default). */
    public function effectiveAll(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.blockKey AS k', 'COALESCE(b.value, b.defaultValue) AS v')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['k']] = $r['v'];
        }
        return $out;
    }
}
