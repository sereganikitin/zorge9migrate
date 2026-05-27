<?php
/**
 * Render-time CMS override for landing pages.
 *
 * nginx rewrites requests like `/` or `/apartments` to this script.
 * It reads the static HTML from the landing's docroot, applies any
 * TextBlock / ImageBlock overrides from the cms_admin database, and
 * streams the result back. Static files (CSS/JS/images) keep being
 * served directly by nginx — only HTML pages go through here.
 *
 * No Symfony bootstrap on purpose — every landing pageview goes
 * through this script, so we keep it lean.
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

$html = file_get_contents($file);

// --- Try to load + apply overrides; if anything fails, serve the static HTML as-is. ---
try {
    $pdo = open_cms_pdo();
    [$texts, $images] = load_overrides($pdo, $page);
    if ($texts) {
        foreach ($texts as $key => $value) {
            $html = apply_text_override($html, $key, $value);
        }
    }
    if ($images) {
        foreach ($images as $key => $src) {
            $html = apply_image_override($html, $key, $src);
        }
    }
} catch (Throwable $e) {
    error_log('[_cms-render] override apply failed: ' . $e->getMessage());
    // Fall through, output the un-overridden HTML so users still see the page.
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
    $dsn = trim($env['DATABASE_URL'], "\"' ");
    if (!preg_match('#^mysql://([^:]+):([^@]+)@([^:/]+)(?::(\d+))?/([^?]+)#', $dsn, $m)) {
        throw new RuntimeException('cannot parse DATABASE_URL');
    }
    [$_, $user, $pass, $host, $port, $dbName] = $m;
    $port = $port ?: 3306;
    return new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/** @return array{0: array<string,string>, 1: array<string,string>} */
function load_overrides(PDO $pdo, string $page): array
{
    $texts = [];
    $stmt = $pdo->prepare('SELECT block_key, value FROM text_block WHERE page_path = ? AND value IS NOT NULL AND value <> ""');
    $stmt->execute([$page]);
    foreach ($stmt as $row) {
        $texts[$row['block_key']] = $row['value'];
    }

    $images = [];
    $stmt = $pdo->prepare('
        SELECT ib.block_key, mi.filename
        FROM image_block ib
        INNER JOIN media_item mi ON mi.id = ib.media_id
        WHERE ib.page_path = ?
    ');
    $stmt->execute([$page]);
    foreach ($stmt as $row) {
        $images[$row['block_key']] = '/cms-admin/uploads/media/' . $row['filename'];
    }

    return [$texts, $images];
}

function apply_text_override(string $html, string $key, string $value): string
{
    $quotedKey = preg_quote($key, '/');
    return (string) preg_replace_callback(
        '/(<(?:h[1-6]|p)\b[^>]*data-cms-text-key="' . $quotedKey . '"[^>]*>)([\s\S]*?)(<\/(?:h[1-6]|p)>)/',
        static fn($m) => $m[1] . $value . $m[3],
        $html,
        1
    );
}

function apply_image_override(string $html, string $key, string $newSrc): string
{
    $quotedKey = preg_quote($key, '/');
    $newSrcEsc = htmlspecialchars($newSrc, ENT_QUOTES);

    // 1) <picture> block — replace all srcset/src inside.
    $html = (string) preg_replace_callback(
        '/(<picture\b[^>]*data-cms-img-key="' . $quotedKey . '"[^>]*>)([\s\S]*?)(<\/picture>)/',
        static function (array $m) use ($newSrcEsc): string {
            $inner = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $newSrcEsc . '"', $m[2]);
            $inner = (string) preg_replace('/\bsrc="[^"]*"/', 'src="' . $newSrcEsc . '"', $inner);
            return $m[1] . $inner . $m[3];
        },
        $html,
        1
    );

    // 2) Standalone <img> — replace src/srcset.
    $html = (string) preg_replace_callback(
        '/<img\b([^>]*data-cms-img-key="' . $quotedKey . '"[^>]*)>/',
        static function (array $m) use ($newSrcEsc): string {
            $attrs = (string) preg_replace('/\bsrc="[^"]*"/', 'src="' . $newSrcEsc . '"', $m[1]);
            $attrs = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $newSrcEsc . '"', $attrs);
            return '<img' . $attrs . '>';
        },
        $html,
        1
    );

    return $html;
}
