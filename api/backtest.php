<?php
/**
 * Posada Backtest API — serves OHLCV history for a token.
 *
 * Params:
 *   ?unit=POLICY_HEX_NAME — TapTools token unit (or ?ticker=SNEK to look up)
 *   &interval=1h           — candle interval (default 1h)
 *   &periods=720           — number of candles (default 720 = 30 days of 1h)
 *
 * Calls TapTools /token/ohlcv
 * Caches 15 min per token in data/ohlcv_TICKER.json
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('serialize_precision', 14);

$CACHE_DIR    = __DIR__ . '/../data';
$TOKEN_FILE   = "$CACHE_DIR/tokens_cache.json";
$CACHE_TTL    = 900;  // 15 min
require_once __DIR__ . '/config.php';
$TAPTOOLS_KEY = TAPTOOLS_API_KEY;

if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

$unit     = $_GET['unit'] ?? '';
$ticker   = $_GET['ticker'] ?? '';
$interval = $_GET['interval'] ?? '1h';
$periods  = min((int)($_GET['periods'] ?? 720), 2000);
if ($periods <= 0) $periods = 720;

// Validate interval
$valid_intervals = ['3m','5m','15m','30m','1h','2h','4h','6h','12h','1d','3d','1w','1M'];
if (!in_array($interval, $valid_intervals)) {
    $interval = '1h';
}

// ── Load token list for unit lookup ──
$token_map = [];  // ticker → unit
$unit_map  = [];  // unit → ticker
if (is_file($TOKEN_FILE)) {
    $tc = json_decode(file_get_contents($TOKEN_FILE), true);
    foreach (($tc['tokens'] ?? []) as $tk => $t) {
        $u = $t['token_id'] ?? '';
        if ($u) {
            $token_map[strtoupper($tk)] = $u;
            $unit_map[$u] = $tk;
        }
    }
}

// Resolve ticker → unit
if (!$unit && $ticker) {
    $unit = $token_map[strtoupper($ticker)] ?? '';
}
if (!$unit) {
    echo json_encode(['error' => 'missing unit or ticker parameter']);
    exit;
}

$resolved_ticker = $unit_map[$unit] ?? $ticker;

// ── Check cache ──
$cache_key = preg_replace('/[^a-zA-Z0-9_]/', '', $resolved_ticker ?: substr($unit, 0, 20));
$cache_file = "$CACHE_DIR/ohlcv_{$cache_key}_{$interval}.json";

if (is_file($cache_file)) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if ($cached && (time() - ($cached['ts'] ?? 0)) < $CACHE_TTL) {
        echo json_encode($cached['data']);
        exit;
    }
}

// ── Fetch from TapTools ──
$url = "https://openapi.taptools.io/api/v1/token/ohlcv?unit=$unit&interval=$interval&numIntervals=$periods";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ["x-api-key: $TAPTOOLS_KEY", 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    echo json_encode(['error' => "TapTools returned HTTP $code", 'candles' => []]);
    exit;
}

$raw = json_decode($body, true);
if (!is_array($raw)) {
    echo json_encode(['error' => 'invalid response', 'candles' => []]);
    exit;
}

// Normalize candle format
$candles = [];
foreach ($raw as $c) {
    $candles[] = [
        'time'   => $c['time'] ?? $c['timestamp'] ?? $c['t'] ?? 0,
        'open'   => $c['open'] ?? $c['o'] ?? 0,
        'high'   => $c['high'] ?? $c['h'] ?? 0,
        'low'    => $c['low'] ?? $c['l'] ?? 0,
        'close'  => $c['close'] ?? $c['c'] ?? 0,
        'volume' => $c['volume'] ?? $c['v'] ?? 0,
    ];
}

// Sort oldest first
usort($candles, fn($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));

$result = [
    'ticker'     => $resolved_ticker,
    'unit'       => $unit,
    'interval'   => $interval,
    'candles'    => $candles,
    'updated_at' => time(),
];

// ── Cache ──
file_put_contents($cache_file, json_encode([
    'ts'   => time(),
    'data' => $result,
]));

echo json_encode($result, JSON_PRETTY_PRINT);
