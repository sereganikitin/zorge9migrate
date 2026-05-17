<?php
/**
 * com-pull.php — генератор hydra/json/com.json + hydra/svg/map_com.json
 * из коммерческого Profitbase XML-фида.
 *
 * Аналог feed-pull.php для коммерции. Был отдельный pipeline где com.json
 * вёлся вручную (или каким-то внешним скриптом) — мы его заменяем
 * автогенератором, чтобы новые помещения (Madison commercial floor 1
 * и т.п.) появлялись автоматически + добавлялся tenant из «Арендатор»
 * custom-field, плюс растровые поэтажки мирорились из фида.
 *
 * Конвенции com.json (НЕ совпадают с data.json — у них своя история):
 *   - Ключ apartment: "b-s-f-n" (с section)
 *   - b: 1/2/3 = Madison/Manhattan/Soho commercial
 *        11/12 = Здание 1/2 (целиком, 1 «апт» = всё здание)
 *        6/7   = С6/С7 (standalone-зданиях)
 *   - f: int floor. f=0 = подвал (display label "-1" в tr_f)
 *        Для b∈{1,2,3} f=2 → display label "1A" (хардкод в commercial-plans.js)
 *   - s: всегда 1
 *   - n: К{X}П{N} → N×10 (К1П8 → n=80); для нерегулярных (К1ОТ, К2_1А_NN)
 *        сохраняем существующие маппинги из старого com.json (по internal-id)
 *   - Статусы:
 *      st (sale): AVAILABLE→1, BOOKED→2, SOLD→0, UNAVAILABLE→4
 *      str (rent, из «Статус аренды»): "Свободно"→1, "Бронь"→2, "Закрыт"→3,
 *           "Сдан"→0, empty→0
 *
 * Существующий com.json мы ЧИТАЕМ перед записью, чтобы сохранить id→key
 * mapping (новые помещения получают свежие ключи, существующие — старые).
 * Это критично потому что часть ключей нерегулярные (К1ОТ → 1-1-2-0) и
 * автоматически не выводятся из <number>.
 *
 * Растровые поэтажки: миррорим Profitbase plan-floor URL в
 *   /hydra/svg/floor_com/b{b}-s{s}-f{f}.png
 * (заменяя старые вручную сделанные вектор-SVG того же basename — старые
 * .svg остаются на диске но в map_com.json больше не упоминаются).
 *
 * _selection.svg companion (требуется area2svg-плагином):
 *   /hydra/svg/floor_com/b{b}-s{s}-f{f}_selection.svg
 * Phase 1: dummy off-screen paths по числу apartments на этаже (чтоб
 * area2svg target_flats отработал). Phase 2: реальные полигоны из
 * outlines_com.json (когда расширим outline_editor под commercial mode).
 *
 * Запуск: cron каждые 5 мин, ПАРАЛЛЕЛЬНО с feed-pull.php (см. /etc/cron.d/).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

const COMMERCIAL_PROFITBASE_URL = 'https://pb7828.profitbase.ru/export/profitbase_xml/68c4790c8c63f9160c0049f0b0bd939a?scheme=https';
const COM_JSON_PATH      = '/var/www/old.zorge9.com/htdocs/hydra/json/com.json';
const MAP_COM_JSON_PATH  = '/var/www/old.zorge9.com/htdocs/hydra/svg/map_com.json';
const FLOOR_COM_DIR      = '/var/www/old.zorge9.com/htdocs/hydra/svg/floor_com';
const OUTLINES_COM_PATH  = '/var/www/old.zorge9.com/htdocs/hydra/svg/outlines_com.json';
const HTTP_TIMEOUT       = 90;

// Profitbase sale-status → com.json st
const SALE_STATUS_MAP = [
    'AVAILABLE'   => 1,
    'BOOKED'      => 2,
    'SOLD'        => 0,
    'UNAVAILABLE' => 4,
    // EXECUTION → skip (transient)
];

// «Статус аренды» Profitbase custom-field → com.json str
const RENT_STATUS_MAP = [
    'Свободно' => 1,
    'Бронь'    => 2,
    'Закрыт'   => 3,
    'Сдан'     => 0,
    ''         => 0,
];

// Имя дома Profitbase → com.json b. Порядок важен (первый stripos-match).
// "Коммерция. Корпус 1. Madison" → 1, "Коммерция. Здание 1" → 11, ...
const BUILDING_MAP_COM = [
    'Madison'   => 1,
    'Manhattan' => 2,
    'Soho'      => 3,
    'Здание 1'  => 11,
    'Здание 2'  => 12,
    'Здание 3'  => 13,
    'Фитнес'    => 2,  // К2ФТ исторически в Manhattan-namespace (см. 2-1-0-1 в com.json)
];

function fail(string $msg): never {
    fwrite(STDERR, "[com-pull] ERROR: $msg\n");
    exit(1);
}

function fetch_xml(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); fail("curl: $err"); }
    curl_close($ch);
    return $body;
}

/**
 * Скачивает раз; обновляет если URL изменился (в .url marker-файле).
 *
 * Если скачивание упало (timeout / 429 / Profitbase URL сменился и новый
 * не доступен), но локальный файл уже есть от прошлого run-а — возвращаем
 * true (используем stale-но-работающий PNG). Без этого fallback-а map_com.json
 * регрессирует на векторные .svg-пути для всех floor-ов где хоть один
 * download failed, и пользователь видит «растры пропали».
 */
function mirror_raster(string $url, string $local_path): bool {
    if ($url === '') return file_exists($local_path);
    $marker = $local_path . '.url';
    $cached = @file_get_contents($marker);
    if (file_exists($local_path) && $cached === $url) return true;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $bytes = curl_exec($ch);
    if ($bytes === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, "[com-pull] WARN: failed to mirror $url: $err\n");
        // Fallback: если файл уже есть на диске от прошлого run-а — используем
        // его. Лучше stale-PNG чем регрессия на .svg в map_com.json.
        return file_exists($local_path);
    }
    curl_close($ch);

    $tmp = $local_path . '.tmp';
    if (file_put_contents($tmp, $bytes) === false) return file_exists($local_path);
    if (!rename($tmp, $local_path)) { @unlink($tmp); return file_exists($local_path); }
    @file_put_contents($marker, $url);
    return true;
}

function write_atomic(string $path, string $content): bool {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content) === false) return false;
    if (!rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/** Profitbase house->name → b id. Возвращает null если не маппится. */
function building_id_com(string $house_name): ?int {
    foreach (BUILDING_MAP_COM as $needle => $id) {
        if (stripos($house_name, $needle) !== false) return $id;
    }
    return null;
}

/** Извлекает custom-field по name. Возвращает '' если нет/пусто. */
function cf(SimpleXMLElement $offer, string $name): string {
    foreach ($offer->{'custom-field'} as $cf) {
        if ((string) $cf->name === $name) {
            return trim((string) $cf->value);
        }
    }
    return '';
}

function cf_float(SimpleXMLElement $offer, string $name): float {
    $v = cf($offer, $name);
    return ($v === '' || !is_numeric($v)) ? 0.0 : (float) $v;
}

function cf_int(SimpleXMLElement $offer, string $name): int {
    $v = cf($offer, $name);
    return ($v === '' || !is_numeric($v)) ? 0 : (int) $v;
}

/**
 * Парсит <number> и пытается извлечь n для нового apt-а (когда нет
 * existing mapping). Возвращает n или null если не получилось.
 *
 *   "К1П8"   → 80  (П×10)
 *   "К1П10"  → 100
 *   "К1П2-2" → 220 (variant -2 как дополнительные 100)
 *   "К1ОТ"   → null (буквы вместо номера → нужен existing mapping)
 *   "С6П1"   → 10  (П×10, b определяется отдельно)
 *   "С7П4"   → 40
 *   "К2ФТ"   → 1   (Фитнес — спецкейс, n=1 по существующему mapping)
 *   "К2_1А_01" → null (нерегулярно — нужен existing mapping)
 *   "Здание 1" → 10 (n=10 по существующему 11-1-1-10)
 */
function parse_com_number_n(string $number): ?int {
    $number = trim($number);

    // К{X}П{N}[-{V}] или С{X}П{N}
    if (preg_match('/^[КС]\d+П(\d+)(?:-(\d+))?$/u', $number, $m)) {
        $n = ((int)$m[1]) * 10;
        if (isset($m[2]) && $m[2] !== '') {
            // -2 вариант → +200 (К1П2-2 → 220 по существующему mapping)
            $n += ((int)$m[2]) * 100;
        }
        return $n;
    }

    // "Здание {X}" → 10
    if (preg_match('/^Здание\s*\d+$/u', $number)) {
        return 10;
    }

    // Плоский номер "1", "4" и т.п. → ×10 (на основе паттернов из com.json b=13-1-2-4)
    if (preg_match('/^(\d+)$/', $number, $m)) {
        return ((int)$m[1]) * 10;
    }

    return null;
}

/** Загружает существующий com.json. Возвращает [] если файла нет/невалидный. */
function load_existing_com(string $path): array {
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) return [];
    return $d;
}

/** Загружает outlines_com.json (если есть). Возвращает ['key' => polygon, ...]. */
function load_outlines_com(): array {
    if (!file_exists(OUTLINES_COM_PATH)) return [];
    $raw = @file_get_contents(OUTLINES_COM_PATH);
    if ($raw === false) return [];
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['outlines']) || !is_array($d['outlines'])) return [];
    return $d['outlines'];
}

/**
 * polygon [[x,y],...] (0..100 %) → SVG path d-атрибут в пикселях viewBox.
 */
function polygon_to_path(array $polygon, float $w, float $h): string {
    if (count($polygon) < 3) return '';
    $d = '';
    foreach ($polygon as $i => $pt) {
        if (!is_array($pt) || count($pt) !== 2) return '';
        $cmd = $i === 0 ? 'M' : 'L';
        $x = round((float)$pt[0] / 100.0 * $w, 3);
        $y = round((float)$pt[1] / 100.0 * $h, 3);
        $d .= $cmd . $x . ' ' . $y . ' ';
    }
    return rtrim($d) . ' Z';
}

function polygon_centroid_px(array $polygon, float $w, float $h): array {
    $n = count($polygon);
    if ($n === 0) return [0.0, 0.0];
    $sx = 0.0; $sy = 0.0;
    foreach ($polygon as $pt) {
        $sx += (float)$pt[0] / 100.0 * $w;
        $sy += (float)$pt[1] / 100.0 * $h;
    }
    return [round($sx / $n, 2), round($sy / $n, 2)];
}

/**
 * Генерирует _selection.svg для (b,s,f). Формат строго совместим с тем что
 * читает area2svg.js: <g id="SELECTION"> со списком <path>.
 *
 * area2svg сопоставляет path с apartment по формуле:
 *   alt = target_flats[N - path_index].id
 * где target_flats[flat.floor_num] = flat. floor_num у нас = p из
 * map_com.json (порядок на этаже). Поэтому paths идут в DESCENDING
 * order по floor_num (p).
 *
 * Phase 1 (текущая): для каждого apt без полигона в outlines_com — dummy
 * off-screen path. Для apt с полигоном — реальный path.
 *
 * Дополнительно: для apt с tenant эмитим <text class="oe-tenant-label">
 * в центроиде, аналогично residential _selection.svg.
 */
function generate_com_selection_svg(int $b, int $s, int $f, array $apts_on_floor, array $outlines, int $w, int $h): string {
    // apts_on_floor: list of [key, p, tenant, has_outline_polygon_or_null]
    // Сортируем по p ASC, потом эмитим в порядке убывания (descending)
    usort($apts_on_floor, fn($a, $b) => $a['p'] <=> $b['p']);

    if (empty($apts_on_floor)) return '';

    $max_p = max(array_column($apts_on_floor, 'p'));
    $by_p = [];
    foreach ($apts_on_floor as $a) {
        $by_p[$a['p']] = $a;
    }

    $paths_xml = '';
    for ($p = $max_p; $p >= 1; $p--) {
        $a = $by_p[$p] ?? null;
        $poly = $a['polygon'] ?? null;
        if (is_array($poly) && count($poly) >= 3) {
            $d = polygon_to_path($poly, (float)$w, (float)$h);
            if ($d !== '') {
                // class="oe-active" — CSS в commercial-plans.phtml подхватит
                // и нарисует золотой контур на TOP-слое (площадка над растром).
                // Без класса полигон останется в BOTTOM-слое (под растром) и
                // будет невидим.
                $paths_xml .= '<path class="oe-active" d="' . htmlspecialchars($d, ENT_QUOTES) . '"/>';
                continue;
            }
        }
        // Dummy — невидимый, далеко за viewBox. Класс НЕ ставим, чтобы
        // CSS .oe-active не делал его «видимой» рамкой за пределами viewBox.
        $dummy = 'M-100000 -100000 L-100000 -99999 L-99999 -100000 Z';
        $paths_xml .= '<path fill="none" d="' . htmlspecialchars($dummy, ENT_QUOTES) . '"/>';
    }

    // Tenant-лейблы (только если есть полигон, чтобы знать координаты).
    $labels_xml = '';
    $fs = max(12, (int) round(min((float)$w, (float)$h) * 0.025));
    foreach ($apts_on_floor as $a) {
        if (empty($a['tenant'])) continue;
        $poly = $a['polygon'] ?? null;
        if (!is_array($poly) || count($poly) < 3) continue;
        [$cx, $cy] = polygon_centroid_px($poly, (float)$w, (float)$h);
        $labels_xml .= '<text class="oe-tenant-label" x="' . $cx . '" y="' . $cy
                     . '" text-anchor="middle" dominant-baseline="middle" font-size="' . $fs . '">'
                     . htmlspecialchars((string)$a['tenant'], ENT_QUOTES) . '</text>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '">'
         . '<g id="SELECTION">' . $paths_xml . '</g>'
         . ($labels_xml !== '' ? '<g class="oe-tenants">' . $labels_xml . '</g>' : '')
         . '</svg>';
}

// --- main -----------------------------------------------------------

$started = microtime(true);

$xml_str = fetch_xml(COMMERCIAL_PROFITBASE_URL);
if (strlen($xml_str) < 100) fail('commercial xml too small: ' . strlen($xml_str));

$xml = @simplexml_load_string($xml_str);
if ($xml === false) fail('commercial xml parse failed');

// Существующий com.json — для id→key mapping
$existing = load_existing_com(COM_JSON_PATH);
$existing_apts = $existing['apartments'] ?? [];

// id (Profitbase internal-id) → key (b-s-f-n)
$id_to_key = [];
foreach ($existing_apts as $key => $apt) {
    $aid = (string)($apt['id'] ?? '');
    if ($aid !== '') {
        $id_to_key[$aid] = $key;
    }
}

$outlines_com = load_outlines_com();

$apartments = [];
$floor_extras = [];  // (b-s-f) => raster URL (берётся из ЛЮБОГО offer-а на этаже)
$counts = ['skipped_status' => 0, 'skipped_building' => 0, 'skipped_n' => 0, 'collision' => 0, 'new' => 0, 'preserved' => 0];

foreach ($xml->offer as $o) {
    $status = (string) $o->status;
    if (!isset(SALE_STATUS_MAP[$status])) {
        // EXECUTION или другие неизвестные статусы — скипаем
        $counts['skipped_status']++;
        continue;
    }
    $st = SALE_STATUS_MAP[$status];

    $house_name = (string) $o->house->name;
    $b = building_id_com($house_name);
    if ($b === null) { $counts['skipped_building']++; continue; }

    $s = 1;  // section всегда 1
    $f_raw = (int) $o->floor;
    $f = $f_raw === -1 ? 0 : $f_raw;  // подвал -1 → 0

    $internal_id = (string) $o['internal-id'];

    // Определяем ключ apt-а
    $key = $id_to_key[$internal_id] ?? null;
    if ($key === null) {
        $n = parse_com_number_n((string) $o->number);
        if ($n === null) {
            fwrite(STDERR, "[com-pull] WARN: cannot derive n for new offer "
                . "id=$internal_id number=" . (string)$o->number . " — skipping\n");
            $counts['skipped_n']++;
            continue;
        }
        $key = "$b-$s-$f-$n";
        $counts['new']++;
    } else {
        $counts['preserved']++;
        // Re-derive b/s/f/n из existing key (чтобы не разъезжалось если
        // Profitbase сдвинул floor — оставляем как было в com.json)
        $parts = explode('-', $key);
        if (count($parts) === 4) {
            $b = (int)$parts[0];
            $s = (int)$parts[1];
            $f = (int)$parts[2];
            $n = (int)$parts[3];
        }
    }

    if (isset($apartments[$key])) {
        $counts['collision']++;
        fwrite(STDERR, "[com-pull] WARN: key collision $key (id=$internal_id)\n");
    }

    // Цены
    $tc = (int) ($o->price->value ?? 0);
    $sq = (float) ($o->area->value ?? 0);
    $cpm = $sq > 0 ? (int) round($tc / $sq) : 0;
    $tcr = cf_int($o, 'Стоимость аренды, руб./мес.');
    $cpm_year = cf_int($o, 'Стоимость аренды за кв.м., руб./год');

    // Rent status
    $rent_raw = cf($o, 'Статус аренды');
    $str = RENT_STATUS_MAP[$rent_raw] ?? 0;

    // Custom-fields
    $ceil = cf_float($o, 'Высота потолка');
    $pw = cf_float($o, 'Подводимая мощность, кВт');
    $apt_type = cf($o, 'Назначение помещения');
    if ($apt_type === '') $apt_type = 'Помещение';
    $tenant = cf($o, 'Арендатор');
    $linked = cf($o, 'linked');
    $level_raw = cf($o, 'level');
    $lvl = is_numeric($level_raw) ? (int)$level_raw : 1;

    // Images
    $img = [];
    foreach ($o->image as $im) {
        $type = (string) $im['type'];
        $url = trim((string) $im);
        if ($url === '') continue;
        if ($type === 'plan') $img[] = $url;
    }

    // Plan-floor URL (для миррора растров — берём первый встретившийся для (b,s,f))
    $fkey = "$b-$s-$f";
    if (!isset($floor_extras[$fkey])) {
        foreach ($o->image as $im) {
            if ((string)$im['type'] === 'plan floor') {
                $url = trim((string)$im);
                if ($url !== '') { $floor_extras[$fkey] = $url; break; }
            }
        }
    }

    // tr_f: display label для floor (-1 для подвала, 1A для f=2 в b∈{1,2,3})
    if ($f === 0) {
        $tr_f = '-1';
    } elseif ($f === 2 && in_array($b, [1, 2, 3], true)) {
        $tr_f = '1A';
    } else {
        $tr_f = (string)$f;
    }

    $apt = [
        'b'        => $b,
        's'        => $s,
        'f'        => $f,
        'n'        => $n,
        'tr_f'     => $tr_f,
        'tr_n'    => (string) $o->number,
        'rc'      => 0,
        'sq'      => $sq,
        'st'      => $st,
        'str'     => $str,
        'tc'      => $tc,
        'tcr'     => $tcr,
        'cpm'     => $cpm,
        'cpm_year'=> $cpm_year,
        'ceil'    => $ceil,
        'pw'      => $pw,
        'apt'     => $apt_type,
        'img'     => $img,
        'id'      => $internal_id,
        'lnk'     => $linked !== '' ? $linked : false,
        'lvl'     => $lvl,
    ];
    if ($tenant !== '') $apt['tenant'] = $tenant;

    $apartments[$key] = $apt;
}

// --- агрегаты floors и buildings ---
$floors = [];     // "b-f" => { at, atr, arc:{0:N}, maxf:1 }
$buildings = [];  // b => { at, atr, arc:{0:N}, maxf }

foreach ($apartments as $a) {
    $b = $a['b']; $f = $a['f'];
    $fk = "$b-$f";

    if (!isset($floors[$fk])) {
        $floors[$fk] = ['at' => 0, 'atr' => 0, 'arc' => [0 => 0], 'maxf' => 1];
    }
    if (!isset($buildings[$b])) {
        $buildings[$b] = ['at' => 0, 'atr' => 0, 'arc' => [0 => 0], 'maxf' => 0];
    }
    if ($f > $buildings[$b]['maxf']) $buildings[$b]['maxf'] = $f;

    if ((int)$a['st'] === 1) {
        $floors[$fk]['at']++;
        $floors[$fk]['arc'][0]++;
        $buildings[$b]['at']++;
        $buildings[$b]['arc'][0]++;
    }
    if ((int)$a['str'] === 1) {
        $floors[$fk]['atr']++;
        $buildings[$b]['atr']++;
    }
}
ksort($floors, SORT_NATURAL);
ksort($buildings);

// --- map_com.json + растровый mirror + _selection.svg ---
// Структура map_com.json: { flats: { "b-s-f-n": { apartment: [path], floor: [path], p: N } } }
// floor — на растровый PNG. apartment — пока на тот же файл (нет per-apt SVG для растров)

if (!is_dir(FLOOR_COM_DIR) && !@mkdir(FLOOR_COM_DIR, 0755, true) && !is_dir(FLOOR_COM_DIR)) {
    fail('cannot create ' . FLOOR_COM_DIR);
}

// Сортируем apartments по (b, s, f, n) для стабильного порядка p
$sorted_keys = array_keys($apartments);
usort($sorted_keys, function ($x, $y) use ($apartments) {
    $a = $apartments[$x]; $bb = $apartments[$y];
    return ($a['b'] <=> $bb['b']) ?: ($a['s'] <=> $bb['s']) ?: ($a['f'] <=> $bb['f']) ?: ($a['n'] <=> $bb['n']);
});

// Assign p (floor_num) на каждом (b-s-f) — порядок 1..N
$p_per_floor = [];
$apt_p = [];
foreach ($sorted_keys as $key) {
    $a = $apartments[$key];
    $fk = "{$a['b']}-{$a['s']}-{$a['f']}";
    $p_per_floor[$fk] = ($p_per_floor[$fk] ?? 0) + 1;
    $apt_p[$key] = $p_per_floor[$fk];
}

// Зеркалим растры (один на (b,s,f), не на apt)
$rasters_mirrored = 0;
$rasters_skipped = 0;
$floors_with_raster = [];
foreach ($floor_extras as $fkey => $url) {
    [$b, $s, $f] = array_map('intval', explode('-', $fkey));
    $raster_path = FLOOR_COM_DIR . "/b{$b}-s{$s}-f{$f}.png";
    if (mirror_raster($url, $raster_path)) {
        $rasters_mirrored++;
        $floors_with_raster["$b-$f"] = $raster_path;
    } else {
        $rasters_skipped++;
    }
}

// Генерим _selection.svg для каждого (b-s-f) с apartments — нужен для area2svg.
// Размер viewBox = размер растра (если зеркалирован) или 1920x1080 default.
$selections_written = 0;
$apts_by_floor = [];
foreach ($sorted_keys as $key) {
    $a = $apartments[$key];
    $fkey = "{$a['b']}-{$a['s']}-{$a['f']}";
    if (!isset($apts_by_floor[$fkey])) $apts_by_floor[$fkey] = [];
    $apts_by_floor[$fkey][] = [
        'key'      => $key,
        'p'        => $apt_p[$key],
        'tenant'   => $a['tenant'] ?? '',
        'polygon'  => $outlines_com[$key]['polygon'] ?? null,
    ];
}
foreach ($apts_by_floor as $fkey => $apts) {
    [$b, $s, $f] = array_map('intval', explode('-', $fkey));
    $raster_path = FLOOR_COM_DIR . "/b{$b}-s{$s}-f{$f}.png";
    $w = 1920; $h = 1080;
    if (is_file($raster_path)) {
        $sz = @getimagesize($raster_path);
        if ($sz && $sz[0] > 0 && $sz[1] > 0) {
            $w = (int)$sz[0]; $h = (int)$sz[1];
        }
    }
    $sel = generate_com_selection_svg($b, $s, $f, $apts, $outlines_com, $w, $h);
    if ($sel !== '') {
        $sel_path = FLOOR_COM_DIR . "/b{$b}-s{$s}-f{$f}_selection.svg";
        if (write_atomic($sel_path, $sel)) $selections_written++;
    }
}

// Строим map_com.json. Путь к floor: PNG если зеркалирован, иначе старый .svg
// (легаси — если в фиде нет plan-floor, держим вектор как был).
$map_flats = [];
foreach ($sorted_keys as $key) {
    $a = $apartments[$key];
    $fkey = "{$a['b']}-{$a['f']}";
    $base = "b{$a['b']}-s{$a['s']}-f{$a['f']}";
    if (isset($floors_with_raster[$fkey])) {
        $floor_path = "/floor_com/{$base}.png";
    } else {
        // Legacy: вектор-SVG если есть, иначе тот же PNG-путь (даже если ещё не зеркалирован)
        $floor_path = "/floor_com/{$base}.svg";
    }
    // apartment-обводки per-apt SVG у нас нет (вектор был сделан вручную и тоже устарел).
    // Используем тот же floor-путь как fallback — JS будет показывать раст как раст.
    $map_flats[$key] = [
        'apartment' => [$floor_path],
        'floor'     => [$floor_path],
        'p'         => $apt_p[$key],
    ];
}

$map_out = ['flats' => (object) $map_flats];
$map_json = json_encode($map_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!write_atomic(MAP_COM_JSON_PATH, $map_json)) fail('write map_com.json failed');

// Записываем com.json
$out = [
    'apartments' => (object) $apartments,
    'floors'     => (object) $floors,
    'buildings'  => (object) $buildings,
];
$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) fail('com.json encode: ' . json_last_error_msg());
if (!write_atomic(COM_JSON_PATH, $json)) fail('write com.json failed');

$elapsed = round(microtime(true) - $started, 2);
$total = count($apartments);
fwrite(STDOUT, "[com-pull] " . date('c') . " ok: $total apts ({$counts['preserved']} preserved, {$counts['new']} new), "
    . "skipped: status={$counts['skipped_status']} building={$counts['skipped_building']} no-n={$counts['skipped_n']} "
    . "collisions={$counts['collision']} | rasters: {$rasters_mirrored} mirrored, {$rasters_skipped} failed | "
    . "selections written: $selections_written, {$elapsed}s\n");
