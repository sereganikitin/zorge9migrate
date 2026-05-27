<?php
/**
 * Landing render-time middleware.
 *
 * nginx rewrites lending requests like `/` or `/apartments` to this script.
 * We read the static HTML, load TextBlock + ImageBlock overrides from the
 * cms_admin database (keyed by block_key — same key applies to every page
 * the block lives on), substitute them into the annotated tags, and stream
 * the result.
 *
 * No Symfony bootstrap — every landing pageview hits this script, so we
 * keep it lean.  Any DB failure falls back to the raw HTML.
 */
declare(strict_types=1);

const LANDING_DOCROOT = '/var/www/old.zorge9.com/htdocs';
const CMS_ENV_FILE    = '/var/www/cms-admin/.env.local';

const PAGE_MAP = [
    ''               => 'index.html',
    'apartments'     => 'apartments/index.html',
    'improvement'    => 'improvement/index.html',
    'infrastructure' => 'infrastructure/index.html',
    'investment'     => 'investment/index.html',
    'location'       => 'location/index.html',
    'management'     => 'management/index.html',
    'parking'        => 'parking/index.html',
    'penthouses'     => 'penthouses/index.html',
    'privacy-policy' => 'privacy-policy/index.html',
    'request'        => 'request/index.html',
    'services'       => 'services/index.html',
    'style'          => 'style/index.html',
];

$page = $_GET['page'] ?? '';
if (!array_key_exists($page, PAGE_MAP)) {
    http_response_code(404);
    echo 'Unknown page';
    exit;
}

$file = LANDING_DOCROOT . '/' . PAGE_MAP[$page];
if (!is_file($file)) {
    http_response_code(404);
    echo 'Source not found';
    exit;
}

$html = (string) file_get_contents($file);

try {
    $pdo = open_cms_pdo();
    [$texts, $images] = load_overrides($pdo);
    foreach ($texts as $key => $value) {
        $html = apply_text_override($html, $key, $value);
    }
    foreach ($images as $key => $src) {
        $html = apply_image_override($html, $key, $src);
    }
} catch (Throwable $e) {
    error_log('[_cms-render] override apply failed: ' . $e->getMessage());
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo $html;

// --- helpers ---

function open_cms_pdo(): PDO
{
    $env = @parse_ini_file(CMS_ENV_FILE, false, INI_SCANNER_RAW);
    if (!$env || !isset($env['DATABASE_URL'])) {
        throw new RuntimeException('cms-admin .env.local not readable');
    }
    $dsn = trim((string) $env['DATABASE_URL'], "\"' ");
    if (!preg_match('#^mysql://([^:]+):([^@]+)@([^:/]+)(?::(\d+))?/([^?]+)#', $dsn, $m)) {
        throw new RuntimeException('cannot parse DATABASE_URL');
    }
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $m[3], $m[4] ?: '3306', $m[5]),
        $m[1],
        $m[2],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/** @return array{0: array<string,string>, 1: array<string,string>} */
function load_overrides(PDO $pdo): array
{
    $texts = [];
    $stmt = $pdo->query('SELECT block_key, value FROM text_block WHERE value IS NOT NULL AND value <> ""');
    foreach ($stmt as $row) {
        $texts[$row['block_key']] = $row['value'];
    }

    $images = [];
    $stmt = $pdo->query('
        SELECT ib.block_key, mi.filename
        FROM image_block ib
        INNER JOIN media_item mi ON mi.id = ib.media_id
    ');
    foreach ($stmt as $row) {
        $images[$row['block_key']] = '/cms-admin/uploads/media/' . $row['filename'];
    }

    return [$texts, $images];
}

function apply_text_override(string $html, string $key, string $value): string
{
    $quoted = preg_quote($key, '/');
    return (string) preg_replace_callback(
        '/(<(?:h[1-6]|p)\b[^>]*data-cms-text-key="' . $quoted . '"[^>]*>)([\s\S]*?)(<\/(?:h[1-6]|p)>)/',
        static fn($m) => $m[1] . $value . $m[3],
        $html
    );
}

function apply_image_override(string $html, string $key, string $newSrc): string
{
    $quoted = preg_quote($key, '/');
    $newEsc = htmlspecialchars($newSrc, ENT_QUOTES);

    // <picture data-cms-img-key="..."> — replace srcset + src inside.
    $html = (string) preg_replace_callback(
        '/(<picture\b[^>]*data-cms-img-key="' . $quoted . '"[^>]*>)([\s\S]*?)(<\/picture>)/',
        static function (array $m) use ($newEsc): string {
            $inner = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $newEsc . '"', $m[2]);
            $inner = (string) preg_replace('/\bsrc="[^"]*"/',    'src="'    . $newEsc . '"', $inner);
            return $m[1] . $inner . $m[3];
        },
        $html
    );

    // Standalone <img data-cms-img-key="...">
    $html = (string) preg_replace_callback(
        '/<img\b([^>]*data-cms-img-key="' . $quoted . '"[^>]*)>/',
        static function (array $m) use ($newEsc): string {
            $attrs = (string) preg_replace('/\bsrc="[^"]*"/',    'src="'    . $newEsc . '"', $m[1]);
            $attrs = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $newEsc . '"', $attrs);
            return '<img' . $attrs . '>';
        },
        $html
    );

    return $html;
}
