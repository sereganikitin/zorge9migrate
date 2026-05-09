<?php
/**
 * feed-pull.php — генератор hydra/json/data.json из Profitbase XML.
 *
 * Запускается по cron (см. /etc/cron.d/zorge9-unloading). Читает Profitbase
 * XML-фид, парсит каждый <offer> и пишет JSON в формате, ожидаемом
 * фронтендом zorge9 (apartments, buildings, floors).
 *
 * Использует "богатый" фид (token 2d1c9a6f...), в нём:
 *   - <image type="plan floor"> — ссылки на растровый поэтажный план
 *   - <image type="plan">       — план самой квартиры
 *   - <image type="house|facade|building"> — фото объекта/фасада/здания
 *   - <description>             — полное текстовое описание лота
 *   - <custom-field>            — Очередь, Высота потолка, Особенности,
 *                                 Фото из окон, 3D-планировка и т.п.
 *
 * Логика:
 *   - <offer> с property_type=Апартаменты пишутся как отдельные записи
 *     (никаких склеек /1, /2, /3 вариантов; suffix-буквы "а", "с" и т.п.
 *     теперь кодируются в `n` отдельной цифрой — больше нет коллизии
 *     295/1 ↔ 295а/1)
 *   - SOLD/EXECUTION просто отбрасываем (нет смысла показывать)
 *   - Status → st: AVAILABLE→1 (без замка), BOOKED→2 (бронь), UNAVAILABLE→0
 *
 * Поля, которые мы НЕ можем достать из публичного фида и оставляем
 * дефолтами (0/""): `kitchen, hallway, bedroom_1..4, wc_1, wc_2,
 * living_kitchen, gost, dr_room, ant`. Это разбивка площадей по комнатам —
 * у Profitbase это лежит в layout-database, недоступной через export.
 * Заполняется отдельно если когда-нибудь дойдут руки.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

const PROFITBASE_URL    = 'https://pb7828.profitbase.ru/export/profitbase_xml/2d1c9a6fdf95ba53617e48f4ceef5556?scheme=https';
const OUT_FILE          = '/var/www/old.zorge9.com/htdocs/hydra/json/data.json';
const TMP_FILE          = OUT_FILE . '.tmp';
const MAP_FILE          = '/var/www/old.zorge9.com/htdocs/hydra/svg/map.json';
const MAP_TMP_FILE      = MAP_FILE . '.tmp';
const OUTLINES_FILE     = '/var/www/old.zorge9.com/htdocs/hydra/svg/outlines.json';
const FLOOR_RASTER_DIR  = '/var/www/old.zorge9.com/htdocs/hydra/svg/floor_raster';
const HTTP_TIMEOUT      = 90;

// Имя floor-SVG-файла (без префикса `/floor/` и без `.svg`).
// Часть этажей в проекте типовые и шарят один SVG (например все этажи 10-18
// Madison'а используют b1-f10_18). Это базовое имя используется и для
// floor-SVG (`/floor/<name>.svg`) и для apartment-SVG-обводок
// (`/apartment/<name>-p{p}.svg`), они всегда лежат вместе.
function floor_svg_name(int $b, int $f): string {
    static $map = [
        1 => [
            1  => 'b1-f1',
            2  => 'b1-f2',
            3  => 'b1-f3_5', 4 => 'b1-f3_5', 5 => 'b1-f3_5',
            6  => 'b1-f6_7', 7 => 'b1-f6_7',
            8  => 'b1-f8_9', 9 => 'b1-f8_9',
            10 => 'b1-f10_18', 11 => 'b1-f10_18', 12 => 'b1-f10_18',
            13 => 'b1-f10_18', 14 => 'b1-f10_18', 15 => 'b1-f10_18',
            16 => 'b1-f10_18', 17 => 'b1-f10_18', 18 => 'b1-f10_18',
            19 => 'b1-f19_20', 20 => 'b1-f19_20',
            21 => 'b1-f21_22', 22 => 'b1-f21_22',
            23 => 'b1-f23',
        ],
        2 => [
            1  => 'b2-f1',
            2  => 'b2-f2',
            3  => 'b2-f3_5', 4 => 'b2-f3_5', 5 => 'b2-f3_5',
            6  => 'b2-f6_20', 7 => 'b2-f6_20', 8 => 'b2-f6_20',
            9  => 'b2-f6_20', 10 => 'b2-f6_20', 11 => 'b2-f6_20',
            12 => 'b2-f6_20', 13 => 'b2-f6_20', 14 => 'b2-f6_20',
            15 => 'b2-f6_20', 16 => 'b2-f6_20', 17 => 'b2-f6_20',
            18 => 'b2-f6_20', 19 => 'b2-f6_20', 20 => 'b2-f6_20',
            21 => 'b2-f21_22', 22 => 'b2-f21_22',
            23 => 'b2-f23',
        ],
        3 => [
            1  => 'b3-f1',
            2  => 'b3-f2',
            3  => 'b3-f3_20', 4 => 'b3-f3_20', 5 => 'b3-f3_20',
            6  => 'b3-f3_20', 7 => 'b3-f3_20', 8 => 'b3-f3_20',
            9  => 'b3-f3_20', 10 => 'b3-f3_20', 11 => 'b3-f3_20',
            12 => 'b3-f3_20', 13 => 'b3-f3_20', 14 => 'b3-f3_20',
            15 => 'b3-f3_20', 16 => 'b3-f3_20', 17 => 'b3-f3_20',
            18 => 'b3-f3_20', 19 => 'b3-f3_20', 20 => 'b3-f3_20',
            21 => 'b3-f21_22', 22 => 'b3-f21_22',
            23 => 'b3-f23',
        ],
    ];
    return $map[$b][$f] ?? "b{$b}-f{$f}";
}

// Для каждого здания свой генплан-SVG.
function building_svg_path(int $b): string {
    return '/building/b1_3.svg';  // у нас один общий генплан на все 3 корпуса
}

// Profitbase status → код в data.json. Фронт интерпретирует:
//   1 = свободна (без замка, кликабельно)
//   2 = бронь (с замком + иконкой брони)
//   0 = недоступна (с замком)
const STATUS_MAP = [
    'AVAILABLE'   => 1,
    'BOOKED'      => 2,
    'UNAVAILABLE' => 0,
];
const ST_AVAILABLE = 1;

const BUILDING_MAP = [
    'Madison'   => 1,
    'Manhattan' => 2,
    'Soho'      => 3,
];

function fail(string $msg): never {
    fwrite(STDERR, "[feed-pull] ERROR: $msg\n");
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
 * Кодирует apartment-номер из Profitbase в уникальный целочисленный n.
 *
 * Profitbase формат: ЗГ{b}-{f}-{section}-{base}[/variant]
 * где base может быть "256", "229" (только цифры) или "295а", "215с", "001c"
 * (цифры + одна буква-суффикс). variant — цифра 1..N или отсутствует.
 *
 * Чтобы избежать коллизий (295 ↔ 295а), кодируем как:
 *     n = base_int * 100 + suffix_code * 10 + variant
 * suffix_code: 0 если нет буквы, иначе детерминированный 1..9 от crc32(буквы)
 * variant: 0 если нет /N, иначе цифра.
 *
 * Примеры:
 *   "256"     → 256 * 100 +  0 + 0 = 25600
 *   "256/1"   → 25601
 *   "295"     → 29500
 *   "295а/1"  → 29500 + suffix(а)*10 + 1   (suffix(а) ≠ 0, уникально)
 *   "295/1"   → 29501  (≠ 295а/1)
 */
function parse_apt_number(string $number): ?array {
    $parts = explode('-', $number);
    $tr_n  = end($parts);
    [$base, $variant] = array_pad(explode('/', $tr_n, 2), 2, '0');

    $n_base = 0; $suffix = '';
    if (preg_match('/^(\d+)([^\d\/]*)$/u', $base, $m)) {
        $n_base = (int) $m[1];
        $suffix = $m[2];
    } else {
        $n_base = (int) preg_replace('/\D/', '', $base);
    }
    $suffix_code = $suffix === '' ? 0 : (abs(crc32($suffix)) % 9 + 1);

    // variant: только цифровые. Кривые ("???", "abc" и т.п.) — null,
    // вызывающий пропустит offer (это всегда битые UNAVAILABLE-записи в
    // Profitbase, на витрину не идут).
    if ($variant === '' || $variant === '0') {
        $variant_int = 0;
    } elseif (ctype_digit($variant)) {
        $variant_int = (int) $variant;
    } else {
        return null;
    }

    $n = $n_base * 100 + $suffix_code * 10 + $variant_int;
    return [$tr_n, $n];
}

function building_id(string $house_name): ?int {
    foreach (BUILDING_MAP as $needle => $id) {
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

/** Тоже что cf(), но возвращает float (или 0 если пусто/не число). */
function cf_float(SimpleXMLElement $offer, string $name): float {
    $v = cf($offer, $name);
    return ($v === '' || !is_numeric($v)) ? 0.0 : (float) $v;
}

/** Читает outlines.json (записывается из admin-плагина). Возвращает map key→outline. */
function load_outlines(): array {
    $raw = @file_get_contents(OUTLINES_FILE);
    if ($raw === false) return [];
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['outlines']) || !is_array($d['outlines'])) return [];
    return $d['outlines'];
}

/** [[x,y],...] (% от растра) → SVG path d-атрибут. */
function polygon_to_path(array $polygon): string {
    if (count($polygon) < 3) return '';
    $d = '';
    foreach ($polygon as $i => $pt) {
        if (!is_array($pt) || count($pt) !== 2) return '';
        $cmd = $i === 0 ? 'M' : 'L';
        $d .= $cmd . round((float)$pt[0], 3) . ' ' . round((float)$pt[1], 3) . ' ';
    }
    return rtrim($d) . ' Z';
}

/**
 * Генерирует _selection.svg для (b,f). Формат строго совместим с тем что
 * читает area2svg.js: <g id="SELECTION"> со списком <path>.
 *
 * area2svg сопоставляет path с квартирой по формуле:
 *     alt = target_flats[N - path_index].id
 * где target_flats[flat.floor_num] = flat, N = размер target_flats.
 * Чтобы это работало:
 *   1. floor_num должны быть плотным набором 1..N (не sparse Profitbase pos)
 *   2. paths идут в порядке убывания floor_num: path[0] = floor_num=N
 *
 * Принимает $phys_seq — словарь "b-f-phys_n" => 1..N (один номер на
 * физическую ячейку, варианты делят его). Для каждого порядкового
 * номера эмитим один path: либо реальный полигон обводки, либо
 * невидимую заглушку, если ни для одного варианта не нарисована.
 */
function generate_selection_svg(int $b, int $f, array $apartments, array $outlines, array $phys_seq): string {
    $fk = "$b-$f";
    $seq_to_polygon = [];   // seq => polygon (or null)
    $max_seq = 0;
    foreach ($apartments as $key => $a) {
        if ((int)$a['b'] !== $b || (int)$a['f'] !== $f) continue;
        $phys_key = $fk . '-' . (intdiv((int)$a['n'], 10) * 10);
        $seq = $phys_seq[$phys_key] ?? 0;
        if ($seq <= 0) continue;
        $max_seq = max($max_seq, $seq);
        if (!isset($seq_to_polygon[$seq]) && isset($outlines[$key]['polygon'])
            && is_array($outlines[$key]['polygon'])
            && count($outlines[$key]['polygon']) >= 3) {
            $seq_to_polygon[$seq] = $outlines[$key]['polygon'];
        }
    }
    if ($max_seq === 0) return '';

    $paths_xml = '';
    for ($seq = $max_seq; $seq >= 1; $seq--) {  // descending
        $poly = $seq_to_polygon[$seq] ?? null;
        $d = ($poly !== null) ? polygon_to_path($poly) : '';
        if ($d === '') $d = 'M-1 -1 L-1 -1 Z';
        $paths_xml .= '<path fill="none" d="' . htmlspecialchars($d, ENT_QUOTES) . '"/>';
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none">'
         . '<g id="SELECTION">' . $paths_xml . '</g></svg>';
}

/** Скачивает раз; обновляет если URL изменился (в .url marker-файле). */
function mirror_raster(string $url, string $local_path): bool {
    if ($url === '') return false;
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
        fwrite(STDERR, "[feed-pull] WARN: failed to mirror raster $url: " . curl_error($ch) . "\n");
        curl_close($ch); return false;
    }
    curl_close($ch);

    $tmp = $local_path . '.tmp';
    if (file_put_contents($tmp, $bytes) === false) return false;
    if (!rename($tmp, $local_path)) { @unlink($tmp); return false; }
    @file_put_contents($marker, $url);
    return true;
}

/** Записать файл атомарно (через .tmp и rename). */
function write_atomic(string $path, string $content): bool {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content) === false) return false;
    if (!rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

// --- main -----------------------------------------------------------

$started = microtime(true);

$xml_str = fetch_xml(PROFITBASE_URL);
if (strlen($xml_str) < 1000) fail('xml too small: ' . strlen($xml_str));

$xml = @simplexml_load_string($xml_str);
if ($xml === false) fail('xml parse failed');

$apartments = [];
$counts = ['wrong_type' => 0, 'no_building' => 0, 'no_status' => 0, 'sold' => 0, 'collision' => 0, 'malformed' => 0];

foreach ($xml->offer as $o) {
    if ((string) $o->property_type !== 'Апартаменты') {
        $counts['wrong_type']++; continue;
    }
    $b = building_id((string) $o->house->name);
    if ($b === null) { $counts['no_building']++; continue; }

    $status = (string) $o->status;
    if (!isset(STATUS_MAP[$status])) {
        $counts['sold']++; continue;
    }
    $st = STATUS_MAP[$status];

    $parsed = parse_apt_number((string) $o->number);
    if ($parsed === null) {
        $counts['malformed']++;
        continue;
    }
    [$tr_n, $n] = $parsed;
    $f  = (int) $o->floor;
    $pos_on_floor = (int) ($o->{'position-on-floor'} ?? 0);
    $rc = (int) $o->rooms;
    $sq = (float) $o->area->value;
    $tc = (int) $o->price->value;
    $cpm = isset($o->{'price-meter'}->value) ? (int) $o->{'price-meter'}->value : 0;

    $sc = 0; $cpm_sc = 0;
    if (isset($o->{'special-offers'}->{'special-offer'}->{'discount-price'})) {
        $sc = (int) round((float) $o->{'special-offers'}->{'special-offer'}->{'discount-price'});
        $cpm_sc = $sq > 0 ? (int) round($sc / $sq) : 0;
    }

    // <image> — разбираем по типам
    $img = []; $floor_img = ''; $house_img = ''; $facade_img = ''; $building_imgs = [];
    foreach ($o->image as $im) {
        $type = (string) $im['type'];
        $url = trim((string) $im);
        if ($url === '') continue;
        switch ($type) {
            case 'plan':       $img[] = $url; break;
            case 'plan floor': $floor_img = $url; break;
            case 'house':      $house_img = $url; break;
            case 'facade':     $facade_img = $url; break;
            case 'building':   $building_imgs[] = $url; break;
        }
    }

    // custom-field → наши поля
    $offer_queue   = cf($o, 'Очередь');                // "I очередь" / "II очередь"
    $td            = cf($o, '3D планировка');           // ссылка на 3D-тур
    $ch            = cf_float($o, 'Высота потолка');   // 3.25
    $cw            = cf_float($o, 'Высота окна');      // 2.75
    $feat          = cf($o, 'Особенности');            // "Высокие потолки, Кухня-гостиная"
    $old_tc_raw    = cf($o, 'Старая цена');
    $old_tc        = is_numeric($old_tc_raw) ? (int) $old_tc_raw : 0;
    $profit        = cf($o, 'Выгода');                 // % выгоды или сумма
    $promo         = cf($o, 'Акция');                  // тоже акция
    $ter_raw       = cf($o, 'Терраса');
    $ter           = is_numeric($ter_raw) ? (float) $ter_raw : ($ter_raw !== '' && $ter_raw !== 'Нет' ? 1 : 0);

    // window photos: "url1; url2; url3" → ["url1", "url2", "url3"]
    $wg_raw = cf($o, 'Фото из окон');
    $wg = [];
    if ($wg_raw !== '') {
        foreach (preg_split('/[;\s]+/', $wg_raw) as $u) {
            $u = trim($u);
            if ($u !== '' && filter_var($u, FILTER_VALIDATE_URL)) $wg[] = $u;
        }
    }

    // built-year + ready-quarter — общие на корпус (используем для агрегата)
    $built_year     = (string) ($o->house->{'built-year'}     ?? '');
    $ready_quarter  = (string) ($o->house->{'ready-quarter'}  ?? '');

    // window-view (отдельный тег, не custom-field)
    $wv = (string) ($o->{'window-view'} ?? '');

    // полное описание
    $desc = (string) ($o->description ?? '');

    $key = "$b-$f-$n";
    if (isset($apartments[$key])) {
        $counts['collision']++;
        fwrite(STDERR, "[feed-pull] WARN: key collision $key (tr_n={$apartments[$key]['tr_n']} vs $tr_n)\n");
    }

    $apartments[$key] = [
        'b'              => $b,
        'f'              => $f,
        'n'              => $n,
        'rc'             => $rc,
        'sq'             => $sq,
        'sqo'            => floor($sq),
        'st'             => $st,
        'cpm'            => $cpm,
        'cpm_sc'         => $cpm_sc,
        'tc'             => $tc,
        'sc'             => $sc,
        'tr_n'           => $tr_n,
        'wv'             => $wv,
        'img'            => $img,
        'floor_img'      => $floor_img,
        'house_img'      => $house_img,
        'facade_img'     => $facade_img,
        'building_imgs'  => $building_imgs,
        'id'             => (string) $o['internal-id'],
        'fin'            => (string) ($o->facing ?? ''),
        'desc'           => $desc,
        'offer'          => $offer_queue,
        'td'             => $td,
        'ch'             => $ch,
        'cw'             => $cw,
        'feat'           => $feat,
        'old_tc'         => $old_tc,
        'profit'         => $profit !== '' ? $profit : $promo,
        'ter'            => $ter,
        'wg'             => $wg,

        // разбивка площадей по комнатам — оставляем дефолтами (см. doc-комментарий
        // в шапке файла). Если когда-нибудь появится layout-DB или они придут
        // через "Сайт.*" custom-fields — заполним точечно.
        'lv'             => 1,
        'kitchen'        => 0,
        'hallway'        => 0,
        'wc_1'           => 0,
        'wc_2'           => 0,
        'ant'            => 0,
        'living_kitchen' => 0,
        'gost'           => 0,
        'bedroom_1'      => 0,
        'bedroom_2'      => 0,
        'dr_room'        => 0,
        'img_left'       => '',
        'img_right'      => '',

        '_built_year'    => $built_year,
        '_ready_quarter' => $ready_quarter,
        '_pos_on_floor'  => $pos_on_floor,  // нужно для генерации map.json (см. ниже), потом стираем
    ];
}

// --- агрегаты buildings и floors ----------------------------------
// buildings[b] = { arc, at, tc:{min,max}, sq:{min,max}, maxf, by, rq, floor_imgs }
// floors[b-f]  = { arc, at, tc:{min,max}, sq:{min,max}, maxf:1, floor_img }
//   floor_img — берётся из первого попавшегося apartment на этом этаже, у всех квартир
//               одного этажа Profitbase возвращает один и тот же plan-floor URL
//
$buildings = []; $floors = [];

foreach ($apartments as $a) {
    $b = $a['b']; $f = $a['f']; $rc = $a['rc']; $st = $a['st'];

    if (!isset($buildings[$b])) {
        $buildings[$b] = [
            'arc' => [1=>0, 2=>0, 3=>0],
            'at'  => 0,
            'tc'  => ['min' => null, 'max' => null],
            'sq'  => ['min' => null, 'max' => null],
            'maxf'=> 0,
            'by'  => $a['_built_year']    ?: '',
            'rq'  => $a['_ready_quarter'] ?: '',
        ];
    }
    if ($f > $buildings[$b]['maxf']) $buildings[$b]['maxf'] = $f;

    $fk = "$b-$f";
    if (!isset($floors[$fk])) {
        $floors[$fk] = [
            'arc'       => [1=>0, 2=>0, 3=>0],
            'at'        => 0,
            'tc'        => ['min' => null, 'max' => null],
            'sq'        => ['min' => null, 'max' => null],
            'maxf'      => 1,
            'floor_img' => $a['floor_img'] ?: '',
        ];
    } elseif ($floors[$fk]['floor_img'] === '' && $a['floor_img'] !== '') {
        $floors[$fk]['floor_img'] = $a['floor_img'];
    }

    if ($st === ST_AVAILABLE) {
        $rc_key = ($rc >= 1 && $rc <= 3) ? $rc : 1;

        $buildings[$b]['at']++;
        $buildings[$b]['arc'][$rc_key]++;

        $floors[$fk]['at']++;
        $floors[$fk]['arc'][$rc_key]++;

        $tc = $a['tc']; $sq = $a['sq'];
        foreach (['tc' => $tc, 'sq' => $sq] as $k => $v) {
            foreach (['buildings' => $b, 'floors' => $fk] as $bag => $idx) {
                $box = $bag === 'buildings' ? $buildings[$idx] : $floors[$idx];
                if ($box[$k]['min'] === null || $v < $box[$k]['min']) $box[$k]['min'] = $v;
                if ($box[$k]['max'] === null || $v > $box[$k]['max']) $box[$k]['max'] = $v;
                if ($bag === 'buildings') $buildings[$idx] = $box;
                else $floors[$idx] = $box;
            }
        }
    }
}

// --- генерим map.json для ApiJsonController + plans.js ---------
// Структура: { flats: { "<key>": { apartment: [<paths>], floor: [path], building: [path], p: <pos> } } }
// p — это position-on-floor из Profitbase. Используется JS для связи "клик по
// path в _selection.svg" → "какая квартира". Варианты одной квартиры (016/1,
// 016/2, 016/3) имеют ОДИНАКОВЫЙ p (это одна и та же физическая ячейка на
// этаже с разной комплектацией).
//
// Profitbase кладёт <position-on-floor> только в offer базового варианта
// (016/1), варианты /2 /3 идут с пустым полем. Поэтому собираем lookup по
// "физическому" ключу (b, f, n_base*100 + suffix_code*10) — он стабилен для
// всех вариантов одной и той же ячейки — и подставляем p, если в offer пусто.
// Profitbase position-on-floor: применяем fallback (для variants /2 /3 без поля).
$pos_lookup = [];
foreach ($apartments as $a) {
    if ($a['_pos_on_floor'] > 0) {
        $phys = "{$a['b']}-{$a['f']}-" . (intdiv($a['n'], 10) * 10);
        $pos_lookup[$phys] = $a['_pos_on_floor'];
    }
}
foreach ($apartments as $key => &$a) {
    if ($a['_pos_on_floor'] === 0) {
        $phys = "{$a['b']}-{$a['f']}-" . (intdiv($a['n'], 10) * 10);
        $a['_pos_on_floor'] = $pos_lookup[$phys] ?? 0;
    }
}
unset($a);

// Сортируем apartments так, чтобы предпочтительные варианты (AVAILABLE > BOOKED > UNAVAILABLE)
// шли ПОСЛЕДНИМИ в каждой физической ячейке. Это важно: area2svg делает
// `target_flats[flat.floor_num] = flat;` — last-write-wins. Иначе клик
// по полигону мог бы вести на UNAVAILABLE-вариант когда есть свободный.
$status_priority = function (int $st): int {
    if ($st === 0) return 0;  // UNAVAILABLE — пишется первым
    if ($st === 2) return 1;  // BOOKED
    return 2;                  // AVAILABLE — последним, перезатрёт остальных
};
uasort($apartments, function ($a, $b) use ($status_priority) {
    $cmp = $a['b'] <=> $b['b']; if ($cmp !== 0) return $cmp;
    $cmp = $a['f'] <=> $b['f']; if ($cmp !== 0) return $cmp;
    $pa = intdiv($a['n'], 10) * 10;
    $pb = intdiv($b['n'], 10) * 10;
    $cmp = $pa <=> $pb; if ($cmp !== 0) return $cmp;
    return $status_priority($a['st']) <=> $status_priority($b['st']);
});

// p_seq: плотный 1..N в каждом этаже, варианты одной ячейки делят номер.
// Используется как floor_num на динамических этажах (где area2svg требует
// плотного набора ключей в target_flats).
$phys_seq = [];           // "b-f-phys_n" => seq
$next_seq_per_floor = []; // "b-f" => next int
foreach ($apartments as $a) {
    $fk = $a['b'] . '-' . $a['f'];
    $phys_key = $fk . '-' . (intdiv($a['n'], 10) * 10);
    if (!isset($phys_seq[$phys_key])) {
        $next = ($next_seq_per_floor[$fk] ?? 0) + 1;
        $next_seq_per_floor[$fk] = $next;
        $phys_seq[$phys_key] = $next;
    }
}

// --- bridge: outlines.json → per-floor _selection.svg + mirrored raster ---
$outlines = load_outlines();
$dynamic_floors = [];  // "b-f" => true
foreach ($apartments as $key => $a) {
    if (isset($outlines[$key]) && !empty($outlines[$key]['polygon'])) {
        $dynamic_floors[$a['b'] . '-' . $a['f']] = true;
    }
}

$mirrored = 0; $sel_written = 0;
if (!empty($dynamic_floors)) {
    if (!is_dir(FLOOR_RASTER_DIR) && !@mkdir(FLOOR_RASTER_DIR, 0755, true) && !is_dir(FLOOR_RASTER_DIR)) {
        fail('cannot create ' . FLOOR_RASTER_DIR);
    }
    foreach ($dynamic_floors as $fk => $_) {
        [$b, $f] = array_map('intval', explode('-', $fk));

        $sel = generate_selection_svg($b, $f, $apartments, $outlines, $phys_seq);
        if ($sel !== '') {
            if (write_atomic(FLOOR_RASTER_DIR . "/{$fk}_selection.svg", $sel)) $sel_written++;
        }

        $raster_url = $floors[$fk]['floor_img'] ?? '';
        if ($raster_url !== '' && mirror_raster($raster_url, FLOOR_RASTER_DIR . "/{$fk}.png")) {
            $mirrored++;
        }
    }
}

$map_flats = [];
foreach ($apartments as $key => $a) {
    $b = $a['b']; $f = $a['f'];
    $fn = floor_svg_name($b, $f);
    $fk = "$b-$f";
    $is_dynamic = isset($dynamic_floors[$fk]);

    if ($is_dynamic) {
        // Динамический этаж: plотный p_seq (для area2svg), без статичной
        // SVG-обводки квартиры (её рисует админ; индивидуального плана нет).
        $phys_key = $fk . '-' . (intdiv($a['n'], 10) * 10);
        $p = $phys_seq[$phys_key] ?? 0;
        $apartment_paths = [];
        $floor_path = "/floor_raster/{$fk}.png";
    } else {
        // Статический этаж: оставляем старое поведение с Profitbase pos-on-floor.
        $p = $a['_pos_on_floor'];
        $apartment_paths = $p > 0 ? ["/apartment/{$fn}-p{$p}.svg"] : [];
        $floor_path = "/floor/{$fn}.svg";
    }

    $map_flats[$key] = [
        'apartment' => $apartment_paths,
        'floor'     => [$floor_path],
        'building'  => [building_svg_path($b)],
        'p'         => $p,
    ];
}
$map_out = ['flats' => (object) $map_flats];
$map_json = json_encode($map_out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (file_put_contents(MAP_TMP_FILE, $map_json) === false) fail('write map tmp failed');
if (!rename(MAP_TMP_FILE, MAP_FILE)) fail('rename map failed');

// убираем временные поля
foreach ($apartments as $k => &$a) {
    unset($a['_built_year'], $a['_ready_quarter'], $a['_pos_on_floor']);
}
unset($a);

$out = [
    'apartments' => (object) $apartments,
    'buildings'  => (object) $buildings,
    'floors'     => (object) $floors,
];

$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) fail('json_encode: ' . json_last_error_msg());

if (file_put_contents(TMP_FILE, $json) === false) fail('write tmp failed');
if (!rename(TMP_FILE, OUT_FILE)) fail('atomic rename failed');

$elapsed = round(microtime(true) - $started, 2);
$total = count($apartments);
$dyn_n = count($dynamic_floors);
fwrite(STDOUT, "[feed-pull] " . date('c') . " ok: $total apartments, "
    . "skipped: sold/exec=" . $counts['sold']
    . " not-apt=" . $counts['wrong_type']
    . " no-bldg=" . $counts['no_building']
    . " collisions=" . $counts['collision']
    . " | dynamic floors: $dyn_n (sel=$sel_written, raster=$mirrored)"
    . ", {$elapsed}s\n");
