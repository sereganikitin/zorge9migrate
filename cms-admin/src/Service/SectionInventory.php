<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Cheap snapshot of which landing sections currently have at least one
 * editable block. Used by the dashboard sidebar to hide empty sections,
 * and by the section editor to render contents.
 *
 * The query unpacks the JSON `sections` column of every text/image block,
 * so it is a full scan of both tables. Section membership only changes when
 * app:seed-from-manifest runs, so the result is kept in cache.app for a few
 * minutes (cache:clear after seeding drops it) plus in-memory per request.
 */
final class SectionInventory implements ResetInterface
{
    /** @var array<string,int>|null */
    private ?array $cache = null;

    private const CACHE_KEY = 'section_inventory_counts';
    private const CACHE_TTL = 600;

    public function __construct(
        private readonly Connection $conn,
        private readonly CacheInterface $appCache,
    ) {}

    /** @return array<string,int> Map of section ID → number of blocks in it. */
    public function counts(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        return $this->cache = $this->appCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);
            return $this->query();
        });
    }

    /** @return array<string,int> */
    private function query(): array
    {
        $sql = <<<'SQL'
            SELECT s.section, COUNT(*) AS n
            FROM (
                SELECT JSON_UNQUOTE(JSON_EXTRACT(sections, CONCAT('$[', t.n, ']'))) AS section
                FROM text_block CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) t
                WHERE JSON_LENGTH(sections) > t.n
                UNION ALL
                SELECT JSON_UNQUOTE(JSON_EXTRACT(sections, CONCAT('$[', t.n, ']')))
                FROM image_block CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4) t
                WHERE JSON_LENGTH(sections) > t.n
            ) s
            WHERE s.section IS NOT NULL
            GROUP BY s.section
        SQL;
        $rows = $this->conn->fetchAllAssociative($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['section']] = (int) $row['n'];
        }
        return $out;
    }

    /** @return list<string> Ordered subset of `$preferredOrder` that actually has blocks. */
    public function nonEmptyOrdered(array $preferredOrder): array
    {
        $counts = $this->counts();
        $out = [];
        foreach ($preferredOrder as $id) {
            if (isset($counts[$id]) && $counts[$id] > 0) {
                $out[] = $id;
            }
        }
        return $out;
    }

    public function hasUnknown(): bool
    {
        return ($this->counts()['unknown'] ?? 0) > 0;
    }

    public function reset(): void
    {
        $this->cache = null;
    }
}
