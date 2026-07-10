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
    foreach ($images as $key => $variants) {
        $html = apply_image_override($html, $key, $variants['desktop'] ?? null, $variants['mobile'] ?? null);
    }
} catch (Throwable $e) {
    error_log('[_cms-render] override apply failed: ' . $e->getMessage());
}

// Temporary floating CTA to the old-site archive at old.zorge9.com.
// Remove this block (and the OLD_SITE_CTA constant below) when no longer needed.
$html = inject_old_site_cta($html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo $html;

// --- helpers ---

/**
 * Inject the temporary "open old-site archive" CTA into the response.
 * Placed just before </body>. If no </body> is found (very unlikely for a
 * landing page), append at end.
 */
function inject_old_site_cta(string $html): string
{
    $snippet = <<<'HTML'
<a href="/main" target="_blank" rel="noopener"
   id="z9-old-site-cta">Копия старого сайта&nbsp;→</a>
<style>
#z9-old-site-cta {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 999999;
    background: #c7a55a;
    color: #111;
    text-decoration: none;
    padding: 10px 18px;
    border-radius: 4px;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-weight: 600;
    font-size: 14px;
    line-height: 1;
    white-space: nowrap;
    box-shadow: 0 2px 12px rgba(0,0,0,.4);
    transition: background .15s ease, transform .15s ease;
}
#z9-old-site-cta:hover { background: #d8b96e; color: #111; transform: translateY(-1px); }
@media (max-width: 640px) {
    #z9-old-site-cta { top: 12px; right: 12px; padding: 9px 14px; font-size: 13px; }
}
</style>
HTML;

    if (stripos($html, '</body>') !== false) {
        return (string) preg_replace('#</body>#i', $snippet . '</body>', $html, 1);
    }
    return $html . $snippet;
}

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

/**
 * @return array{0: array<string,string>, 1: array<string,array{desktop:?string,mobile:?string}>}
 */
function load_overrides(PDO $pdo): array
{
    $texts = [];
    $stmt = $pdo->query('SELECT block_key, value FROM text_block WHERE value IS NOT NULL AND value <> ""');
    foreach ($stmt as $row) {
        $texts[$row['block_key']] = $row['value'];
    }

    $images = [];
    $stmt = $pdo->query('
        SELECT ib.block_key,
               md.filename AS desktop_filename,
               mm.filename AS mobile_filename
        FROM image_block ib
        LEFT JOIN media_item md ON md.id = ib.media_id
        LEFT JOIN media_item mm ON mm.id = ib.media_mobile_id
        WHERE ib.media_id IS NOT NULL OR ib.media_mobile_id IS NOT NULL
    ');
    foreach ($stmt as $row) {
        $images[$row['block_key']] = [
            'desktop' => $row['desktop_filename'] ? '/cms-admin/uploads/media/' . $row['desktop_filename'] : null,
            'mobile'  => $row['mobile_filename']  ? '/cms-admin/uploads/media/' . $row['mobile_filename']  : null,
        ];
    }

    return [$texts, $images];
}

function apply_text_override(string $html, string $key, string $value): string
{
    $quoted = preg_quote($key, '/');
    // Match h1-h6, p, or span — span is used for annotations inside phrasing
    // content where <p> would be invalid HTML (e.g. inside <button>/<span>).
    return (string) preg_replace_callback(
        '/(<(?:h[1-6]|p|span)\b[^>]*data-cms-text-key="' . $quoted . '"[^>]*>)([\s\S]*?)(<\/(?:h[1-6]|p|span)>)/',
        static fn($m) => $m[1] . $value . $m[3],
        $html
    );
}

/**
 * Apply image overrides. Inside a <picture>, <source srcset="..."> are the
 * larger-screen variants and the inner <img src="..."> is the mobile fallback.
 *
 * - desktop only set → both <source> srcsets and inner <img> get desktop
 *   (same as the old single-image behaviour).
 * - mobile only set  → <source> srcsets are left untouched (original desktop
 *   art); only inner <img> src is replaced with mobile.
 * - both set         → <source> srcsets get desktop, inner <img> gets mobile.
 *
 * For standalone <img data-cms-img-key="..."> (no <picture> wrapper) we only
 * have one slot, so desktop wins if set, otherwise mobile.
 */
function apply_image_override(string $html, string $key, ?string $desktopSrc, ?string $mobileSrc): string
{
    if ($desktopSrc === null && $mobileSrc === null) return $html;

    $quoted = preg_quote($key, '/');
    $desktopEsc = $desktopSrc !== null ? htmlspecialchars($desktopSrc, ENT_QUOTES) : null;
    $mobileEsc  = $mobileSrc  !== null ? htmlspecialchars($mobileSrc,  ENT_QUOTES) : null;
    // For the inner <img> inside <picture> and for standalone <img>: prefer mobile / desktop respectively.
    $innerImgEsc = $mobileEsc ?? $desktopEsc;
    $standaloneEsc = $desktopEsc ?? $mobileEsc;

    // <picture data-cms-img-key="...">
    $html = (string) preg_replace_callback(
        '/(<picture\b[^>]*data-cms-img-key="' . $quoted . '"[^>]*>)([\s\S]*?)(<\/picture>)/',
        static function (array $m) use ($desktopEsc, $innerImgEsc): string {
            $inner = $m[2];
            if ($desktopEsc !== null) {
                $inner = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $desktopEsc . '"', $inner);
            }
            if ($innerImgEsc !== null) {
                $inner = (string) preg_replace('/\bsrc="[^"]*"/', 'src="' . $innerImgEsc . '"', $inner);
            }
            return $m[1] . $inner . $m[3];
        },
        $html
    );

    // Standalone <img data-cms-img-key="..."> — single slot, desktop wins.
    if ($standaloneEsc !== null) {
        $html = (string) preg_replace_callback(
            '/<img\b([^>]*data-cms-img-key="' . $quoted . '"[^>]*)>/',
            static function (array $m) use ($standaloneEsc): string {
                $attrs = (string) preg_replace('/\bsrc="[^"]*"/',    'src="'    . $standaloneEsc . '"', $m[1]);
                $attrs = (string) preg_replace('/\bsrcset="[^"]*"/', 'srcset="' . $standaloneEsc . '"', $attrs);
                return '<img' . $attrs . '>';
            },
            $html
        );
    }

    return $html;
}
