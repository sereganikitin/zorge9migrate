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

const PROFITBASE_URL = 'https://pb7828.profitbase.ru/export/profitbase_xml/2d1c9a6fdf95ba53617e48f4ceef5556?scheme=https';
const OUT_FILE       = '/var/www/old.zorge9.com/htdocs/hydra/json/data.json';
const TMP_FILE       = OUT_FILE . '.tmp';
const HTTP_TIMEOUT   = 90;

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

// убираем временные поля _built_year/_ready_quarter
foreach ($apartments as $k => &$a) {
    unset($a['_built_year'], $a['_ready_quarter']);
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
fwrite(STDOUT, "[feed-pull] " . date('c') . " ok: $total apartments, "
    . "skipped: sold/exec=" . $counts['sold']
    . " not-apt=" . $counts['wrong_type']
    . " no-bldg=" . $counts['no_building']
    . " collisions=" . $counts['collision']
    . ", {$elapsed}s\n");
