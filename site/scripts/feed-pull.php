<?php
/**
 * feed-pull.php — генератор hydra/json/data.json из Profitbase XML.
 *
 * Запускается по cron (см. /etc/cron.d/zorge9-unloading). Читает Profitbase
 * XML-фид, парсит каждый <offer> и пишет JSON в формате, ожидаемом
 * фронтендом zorge9 (/plans, /flats, /plans/search и т.д.).
 *
 * Замена для:
 *   - старого unloading-плагина (sqlite-конфиг которого мерджил варианты /1/2/3
 *     в одну запись и не различал Profitbase-статусы)
 *   - mirror-cron'а с https://old.zorge9.com/hydra/json/data.json (зависимость
 *     от старого сервера)
 *
 * Логика:
 *   - <offer> с property_type=Апартаменты пишутся как отдельные записи
 *     (никаких склеек /1, /2, /3)
 *   - SOLD/EXECUTION пропускаются (нет смысла показывать)
 *   - st: AVAILABLE→0, BOOKED→1, UNAVAILABLE→2 (как в OLD'овском поле)
 *   - n составляется как base×10 + вариант (256/1 → 2561), даёт уникальные
 *     ключи b-f-n даже для разных вариантов одной квартиры
 *
 * Поля совпадающие с Profitbase: b, f, n, rc, sq, tc, cpm, sc, img, wv, id, st.
 * Augmentation-поля OLD'а (img_left/right, feat, td, wg) проставлены defaults —
 * фронт должен корректно обрабатывать их отсутствие. Если что-то отвалится
 * визуально — добавим точечно.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

const PROFITBASE_URL = 'https://pb7828.profitbase.ru/export/profitbase_xml/2b76e70bcddca7c519166c8a9993b20b';
const OUT_FILE       = '/var/www/old.zorge9.com/htdocs/hydra/json/data.json';
const TMP_FILE       = OUT_FILE . '.tmp';
const HTTP_TIMEOUT   = 60;

// status в Profitbase → код в data.json. Фронт интерпретирует st так:
//   2 = свободна (без замка, кликабельно для покупки)
//   1 = бронь (с замком и иконкой брони)
//   0 = недоступна (с замком)
// SOLD/EXECUTION просто отбрасываем (нет смысла показывать).
const STATUS_MAP = [
    'AVAILABLE'   => 2,
    'BOOKED'      => 1,
    'UNAVAILABLE' => 0,
];

// Какой код st у "свободна" (для агрегаций at, arc и т.п.)
const ST_AVAILABLE = 2;

// корпус-номер по house-имени Profitbase
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
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fail("curl: $err");
    }
    curl_close($ch);
    return $body;
}

function parse_apt_number(string $number): array {
    // ЗГ1-22-1-242/3 → tr_n="242/3", n=2423
    // ЗГ1-3-3-016    → tr_n="016",   n=160 (×10 + 0 для "без варианта")
    // ЗГ3-22-3-295а  → tr_n="295а",  n=2950 (буквенные суффиксы кодируем 0)
    $parts = explode('-', $number);
    $tr_n  = end($parts);
    [$base, $variant] = array_pad(explode('/', $tr_n, 2), 2, '0');
    $n_base = (int) preg_replace('/\D/', '', $base);
    $variant_int = (int) preg_replace('/\D/', '', $variant);
    return [$tr_n, $n_base * 10 + $variant_int];
}

function building_id(string $house_name): ?int {
    foreach (BUILDING_MAP as $needle => $id) {
        if (stripos($house_name, $needle) !== false) return $id;
    }
    return null;
}

// --- main -----------------------------------------------------------

$started = microtime(true);

$xml_str = fetch_xml(PROFITBASE_URL);
if (strlen($xml_str) < 1000) fail('xml too small: ' . strlen($xml_str));

$xml = @simplexml_load_string($xml_str);
if ($xml === false) fail('xml parse failed');

$apartments = [];
$counts = ['skipped' => 0, 'wrong_type' => 0, 'no_building' => 0, 'no_status' => 0, 'sold' => 0];

foreach ($xml->offer as $o) {
    if ((string) $o->property_type !== 'Апартаменты') {
        $counts['wrong_type']++; continue;
    }
    $b = building_id((string) $o->house->name);
    if ($b === null) { $counts['no_building']++; continue; }

    $status = (string) $o->status;
    if (!isset(STATUS_MAP[$status])) {
        // SOLD, EXECUTION etc. — пропускаем
        $counts['sold']++;
        continue;
    }
    $st = STATUS_MAP[$status];

    [$tr_n, $n] = parse_apt_number((string) $o->number);
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

    $img = [];
    foreach ($o->image as $im) {
        $u = trim((string) $im);
        if ($u !== '') $img[] = $u;
    }

    // built-year + ready-quarter — общие на корпус, забираем из первого попавшегося offer'а
    $built_year     = (string) ($o->house->{'built-year'}     ?? '');
    $ready_quarter  = (string) ($o->house->{'ready-quarter'}  ?? '');

    $key = "$b-$f-$n";
    if (isset($apartments[$key])) {
        // защита от коллизии (теоретически не должно быть, но логирнём)
        fwrite(STDERR, "[feed-pull] WARN: key collision $key (tr_n={$apartments[$key]['tr_n']} vs $tr_n)\n");
        $counts['collision'] = ($counts['collision'] ?? 0) + 1;
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
        'wv'             => (string) ($o->{'window-view'} ?? ''),
        'img'            => $img,
        'id'             => (string) $o['internal-id'],
        'fin'            => (string) ($o->facing ?? ''),

        // legacy/augmentation поля OLD'а — defaults; добавляем для совместимости
        // фронта (он лезет за этими ключами). Если фронт что-то требует визуально —
        // дополнить отдельно.
        'lv'             => 1,
        'kitchen'        => 0,
        'hallway'        => 0,
        'wc_1'           => 0,
        'wc_2'           => 0,
        'ant'            => 0,
        'offer'          => 0,
        'profit'         => 0,
        'old_tc'         => 0,
        'living_kitchen' => 0,
        'gost'           => 0,
        'bedroom_1'      => 0,
        'bedroom_2'      => 0,
        'dr_room'        => 0,
        'ch'             => 0,
        'cw'             => 0,
        'ter'            => 0,
        'feat'           => '',
        'td'             => '',
        'wg'             => [],
        'img_left'       => '',
        'img_right'      => '',
        '_built_year'    => $built_year,    // временные поля (стираются перед output)
        '_ready_quarter' => $ready_quarter,
    ];
}

// агрегаты buildings и floors:
//   buildings[b] = { arc, at, tc:{min,max}, sq:{min,max}, maxf, by, rq }
//   floors[b-f]  = { arc, at, tc:{min,max}, sq:{min,max}, maxf:1 }
// at  — count свободных (st=0)
// arc — по комнатам (только st=0)
// tc/sq — мин/макс по свободным (для слайдеров на frontend)
// by  — год сдачи корпуса
// rq  — квартал сдачи
$buildings = [];
$floors    = [];

foreach ($apartments as $a) {
    $b = $a['b']; $f = $a['f']; $rc = $a['rc']; $st = $a['st'];

    if (!isset($buildings[$b])) {
        $buildings[$b] = [
            'arc'  => [1 => 0, 2 => 0, 3 => 0],
            'at'   => 0,
            'tc'   => ['min' => null, 'max' => null],
            'sq'   => ['min' => null, 'max' => null],
            'maxf' => 0,
            'by'   => $a['_built_year']    ?: '',
            'rq'   => $a['_ready_quarter'] ?: '',
        ];
    }
    if ($f > $buildings[$b]['maxf']) $buildings[$b]['maxf'] = $f;

    if ($st === ST_AVAILABLE) {
        $buildings[$b]['at']++;
        $rc_key = ($rc >= 1 && $rc <= 3) ? $rc : 1;
        $buildings[$b]['arc'][$rc_key]++;

        $tc = $a['tc']; $sq = $a['sq'];
        if ($buildings[$b]['tc']['min'] === null || $tc < $buildings[$b]['tc']['min']) $buildings[$b]['tc']['min'] = $tc;
        if ($buildings[$b]['tc']['max'] === null || $tc > $buildings[$b]['tc']['max']) $buildings[$b]['tc']['max'] = $tc;
        if ($buildings[$b]['sq']['min'] === null || $sq < $buildings[$b]['sq']['min']) $buildings[$b]['sq']['min'] = $sq;
        if ($buildings[$b]['sq']['max'] === null || $sq > $buildings[$b]['sq']['max']) $buildings[$b]['sq']['max'] = $sq;
    }

    $fk = "$b-$f";
    if (!isset($floors[$fk])) {
        $floors[$fk] = [
            'arc'  => [1 => 0, 2 => 0, 3 => 0],
            'at'   => 0,
            'tc'   => ['min' => null, 'max' => null],
            'sq'   => ['min' => null, 'max' => null],
            'maxf' => 1,
        ];
    }
    if ($st === ST_AVAILABLE) {
        $floors[$fk]['at']++;
        $rc_key = ($rc >= 1 && $rc <= 3) ? $rc : 1;
        $floors[$fk]['arc'][$rc_key]++;

        $tc = $a['tc']; $sq = $a['sq'];
        if ($floors[$fk]['tc']['min'] === null || $tc < $floors[$fk]['tc']['min']) $floors[$fk]['tc']['min'] = $tc;
        if ($floors[$fk]['tc']['max'] === null || $tc > $floors[$fk]['tc']['max']) $floors[$fk]['tc']['max'] = $tc;
        if ($floors[$fk]['sq']['min'] === null || $sq < $floors[$fk]['sq']['min']) $floors[$fk]['sq']['min'] = $sq;
        if ($floors[$fk]['sq']['max'] === null || $sq > $floors[$fk]['sq']['max']) $floors[$fk]['sq']['max'] = $sq;
    }
}

// убираем временные поля _built_year/_ready_quarter из выходных apartments
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
    . "skipped=" . ($counts['wrong_type'] + $counts['no_building'] + $counts['sold'])
    . " (sold/exec=" . $counts['sold']
    . " not-apt=" . $counts['wrong_type']
    . " no-bldg=" . $counts['no_building']
    . "), {$elapsed}s\n");
