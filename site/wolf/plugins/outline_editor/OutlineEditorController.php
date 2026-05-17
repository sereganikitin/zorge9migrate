<?php
if (!defined('IN_CMS')) { exit(); }

class OutlineEditorController extends PluginController {

    const DATA_JSON_PATH     = '/hydra/json/data.json';
    const OUTLINES_JSON_PATH = '/hydra/svg/outlines.json';

    public function __construct() {
        $this->setLayout('backend');
    }

    public function index() {
        self::_checkPermission();
        $this->display('outline_editor/views/index', []);
    }

    public function api() {
        self::_checkPermission();

        header('Content-Type: application/json; charset=utf-8');
        $action = $_REQUEST['action'] ?? '';

        try {
            if ($action === 'load') {
                echo json_encode($this->loadState(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($action === 'save') {
                echo json_encode($this->saveOutlines(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'unknown action']);
            }
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    public static function _checkPermission(): void {
        AuthUser::load();
        if (!AuthUser::isLoggedIn()) {
            redirect(get_url('login'));
        }
    }

    private function dataPath(): string {
        return CMS_ROOT . self::DATA_JSON_PATH;
    }

    private function outlinesPath(): string {
        return CMS_ROOT . self::OUTLINES_JSON_PATH;
    }

    private function loadState(): array {
        $data_raw = @file_get_contents($this->dataPath());
        if ($data_raw === false) {
            throw new RuntimeException('data.json not found at ' . $this->dataPath());
        }
        $data = json_decode($data_raw, true);
        if (!is_array($data) || !isset($data['apartments'], $data['floors'])) {
            throw new RuntimeException('data.json malformed');
        }

        $floors = [];
        foreach ($data['apartments'] as $key => $a) {
            $b = (int)($a['b'] ?? 0);
            $f = (int)($a['f'] ?? 0);
            $floor_key = "$b-$f";
            if (!isset($floors[$floor_key])) {
                $floor_meta = $data['floors'][$floor_key] ?? [];
                $floors[$floor_key] = [
                    'b'         => $b,
                    'f'         => $f,
                    'label'     => (string)($floor_meta['label'] ?? $f),
                    'floor_img' => (string)($floor_meta['floor_img'] ?? ''),
                    'aparts'    => [],
                ];
            }
            $floors[$floor_key]['aparts'][] = [
                'key'  => $key,
                'tr_n' => (string)($a['tr_n'] ?? ''),
                'sq'   => (float)($a['sq'] ?? 0),
                'rc'   => (int)($a['rc'] ?? 0),
                'st'   => (int)($a['st'] ?? 0),
            ];
        }

        ksort($floors, SORT_NATURAL);
        foreach ($floors as &$fl) {
            usort($fl['aparts'], fn($x, $y) => strcmp($x['tr_n'], $y['tr_n']));
        }
        unset($fl);

        $outlines_raw = @file_get_contents($this->outlinesPath());
        $outlines = ['version' => 1, 'updated_at' => null, 'outlines' => (object)[]];
        if ($outlines_raw !== false) {
            $decoded = json_decode($outlines_raw, true);
            if (is_array($decoded) && isset($decoded['outlines'])) {
                $outlines = $decoded;
                if (empty($outlines['outlines'])) {
                    $outlines['outlines'] = (object)[];
                }
            }
        }

        return [
            'ok'       => true,
            'floors'   => array_values($floors),
            'outlines' => $outlines,
        ];
    }

    private function saveOutlines(): array {
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload['outlines']) || !is_array($payload['outlines'])) {
            throw new InvalidArgumentException('expected { outlines: { "<key>": { polygon, raster_url } } }');
        }

        $clean = [];
        foreach ($payload['outlines'] as $key => $o) {
            // b-f-n; для коммерции f может быть -1 (подвал), n — crc32 hash.
            if (!is_string($key) || !preg_match('/^\d+--?\d+-\d+$/', $key)) continue;
            if (!isset($o['polygon']) || !is_array($o['polygon']) || count($o['polygon']) < 3) continue;
            $poly = [];
            foreach ($o['polygon'] as $pt) {
                if (!is_array($pt) || count($pt) !== 2) continue 2;
                $x = (float)$pt[0]; $y = (float)$pt[1];
                if ($x < 0 || $x > 100 || $y < 0 || $y > 100) continue 2;
                $poly[] = [round($x, 3), round($y, 3)];
            }
            $clean[$key] = [
                'polygon'    => $poly,
                'raster_url' => isset($o['raster_url']) ? (string)$o['raster_url'] : '',
                'updated_at' => date('c'),
            ];
        }

        $out = [
            'version'    => 1,
            'updated_at' => date('c'),
            'outlines'   => empty($clean) ? (object)[] : $clean,
        ];

        $path = $this->outlinesPath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException("dir not writable: $dir");
        }
        $tmp = $path . '.tmp';
        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('write failed: ' . $tmp);
        }
        if (!rename($tmp, $path)) {
            throw new RuntimeException('rename failed: ' . $path);
        }

        $bridge = $this->refreshBridge();

        return [
            'ok'         => true,
            'count'      => count($clean),
            'updated_at' => $out['updated_at'],
            'bridge'     => $bridge,
        ];
    }

    /**
     * Запускает feed-pull.php чтобы пересоздать /hydra/svg/floor_raster/<b>-<f>.png
     * + _selection.svg + обновить map.json. Без этого нарисованные обводки не
     * увидит фронт до следующего cron-тика.
     *
     * Скрипт-CLI, не отдаёт HTTP. Гасим ошибки в массив, не валим сохранение.
     */
    private function refreshBridge(): array {
        $script = CMS_ROOT . '/scripts/feed-pull.php';
        if (!is_file($script)) {
            return ['triggered' => false, 'error' => 'feed-pull.php missing'];
        }
        $cmd = '/usr/bin/php ' . escapeshellarg($script) . ' 2>&1';
        $started = microtime(true);
        $output = []; $exit = 0;
        @exec($cmd, $output, $exit);
        $elapsed = round(microtime(true) - $started, 2);
        return [
            'triggered' => true,
            'exit'      => $exit,
            'elapsed_s' => $elapsed,
            'tail'      => array_slice($output, -2),
        ];
    }
}
