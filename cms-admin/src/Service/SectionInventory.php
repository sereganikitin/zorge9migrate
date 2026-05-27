<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Cheap snapshot of which landing sections currently have at least one
 * editable block. Used by the dashboard sidebar to hide empty sections,
 * and by the section editor to render contents.
 *
 * Cached in-memory for the lifetime of one request — admin pages always
 * trigger at most a couple of these calls per request, so the cost is
 * one (small) SQL query per request.
 */
final class SectionInventory implements ResetInterface
{
    /** @var array<string,int>|null */
    private ?array $cache = null;

    public function __construct(private readonly Connection $conn) {}

    /** @return array<string,int> Map of section ID → number of blocks in it. */
    public function counts(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
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
        $this->cache = $out;
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
