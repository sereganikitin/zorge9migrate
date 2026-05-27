<?php

namespace App\Repository;

use App\Entity\SiteSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SiteSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteSetting::class);
    }

    public function getValue(string $name, ?string $default = null): ?string
    {
        $row = $this->findOneBy(['name' => $name]);
        return $row?->getValue() ?? $default;
    }

    /** @return array<string,?string> */
    public function asMap(): array
    {
        $out = [];
        foreach ($this->findAll() as $s) {
            $out[$s->getName()] = $s->getValue();
        }
        return $out;
    }
}
