<?php
/**
 * Generate sitemap.xml from the landing's static URLs + every published
 * Wolf CMS page.
 *
 * Usage (on the server, where Wolf's config.php is reachable):
 *   php cms-admin/bin/generate_sitemap.php https://zorge9.com > site/sitemap.xml
 *
 * Default base URL is https://zorge9.com — pass an alternative as argv[1]
 * for staging.  Wolf DB credentials are read from htdocs/config.php (DB_DSN,
 * DB_USER, DB_PASS constants).
 */
declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'https://zorge9.com', '/');
$configPath = $argv[2] ?? '/var/www/old.zorge9.com/htdocs/config.php';

if (!is_readable($configPath)) {
    fwrite(STDERR, "Wolf config.php not found at {$configPath}\n");
    exit(1);
}

// Defining $_SERVER['HTTP_HOST'] keeps Wolf's host-based switch happy when
// we include the config.
$_SERVER['HTTP_HOST'] = parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost';
require_once $configPath;

$pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $pdo->query(
    "SELECT id, parent_id, slug, updated_on FROM page WHERE status_id = 100"
)->fetchAll(PDO::FETCH_ASSOC);

$byId = [];
foreach ($rows as $r) {
    $byId[(int) $r['id']] = $r;
}

/** Climb to root, collect slugs in order. */
function wolfPath(int $id, array $byId): ?string {
    $parts = [];
    $seen = [];
    $cur = $byId[$id] ?? null;
    while ($cur && (int) $cur['parent_id'] > 0) {
        if (isset($seen[(int) $cur['id']])) return null;
        $seen[(int) $cur['id']] = true;
        if (!empty($cur['slug'])) {
            array_unshift($parts, $cur['slug']);
        }
        $cur = $byId[(int) $cur['parent_id']] ?? null;
    }
    return '/' . implode('/', $parts);
}

// Landing URLs (highest priority — render first).
$landingPaths = [
    '', 'apartments', 'improvement', 'infrastructure', 'investment',
    'location', 'management', 'parking', 'penthouses', 'privacy-policy',
    'request', 'services', 'style',
];
$landingSet = [];
foreach ($landingPaths as $p) {
    $landingSet['/' . ltrim($p, '/')] = true;
    if ($p === '') $landingSet['/'] = true;
}

// Patterns to skip from Wolf pages (technical pages, drafts, robots, …).
$skipPatterns = [
    '#\.txt$#',  '#\.xml$#',  '#^/log#',  '#^/ajax#',
    '#^/page404#', '#^/promo/#i',  // /promo/* served as static
];
$shouldSkip = function (string $path) use ($skipPatterns): bool {
    foreach ($skipPatterns as $p) {
        if (preg_match($p, $path)) return true;
    }
    return false;
};

// Build entries
$entries = [];
foreach ($landingPaths as $p) {
    $loc = $baseUrl . '/' . ltrim($p, '/');
    if ($p === '') $loc = $baseUrl . '/';
    $entries[] = ['loc' => $loc, 'lastmod' => null, 'priority' => '1.0', 'changefreq' => 'weekly'];
}
foreach ($byId as $row) {
    if ((int) $row['parent_id'] === 0) continue;
    $path = wolfPath((int) $row['id'], $byId);
    if ($path === null || $path === '/') continue;
    if (isset($landingSet[$path])) continue;
    if ($shouldSkip($path)) continue;
    $lastmod = !empty($row['updated_on']) && $row['updated_on'] !== '0000-00-00 00:00:00'
        ? date('Y-m-d', strtotime((string) $row['updated_on']))
        : null;
    $entries[] = [
        'loc' => $baseUrl . $path,
        'lastmod' => $lastmod,
        'priority' => '0.5',
        'changefreq' => 'monthly',
    ];
}

// Output XML
echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($entries as $e) {
    echo '  <url>' . PHP_EOL;
    echo '    <loc>' . htmlspecialchars($e['loc'], ENT_XML1) . '</loc>' . PHP_EOL;
    if ($e['lastmod']) {
        echo '    <lastmod>' . $e['lastmod'] . '</lastmod>' . PHP_EOL;
    }
    echo '    <changefreq>' . $e['changefreq'] . '</changefreq>' . PHP_EOL;
    echo '    <priority>' . $e['priority'] . '</priority>' . PHP_EOL;
    echo '  </url>' . PHP_EOL;
}
echo '</urlset>' . PHP_EOL;
