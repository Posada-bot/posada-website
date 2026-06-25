<?php
/**
 * Posada Movers API — serves Top Movers + Market Pulse data.
 *
 * - Reads token list from existing tokens_cache.json
 * - Calls TapTools /token/prices/chg for multi-timeframe changes
 * - Calls TapTools /market/stats for total DEX volume + active addresses
 * - Caches 5 min in data/movers_cache.json
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('serialize_precision', 14);

$CACHE_DIR    = __DIR__ . '/../data';
$TOKEN_FILE   = "$CACHE_DIR/tokens_cache.json";
$MOVERS_FILE  = "$CACHE_DIR/movers_cache.json";
$CACHE_TTL    = 300;  // 5 min
require_once __DIR__ . '/config.php';
$TAPTOOLS_KEY = TAPTOOLS_API_KEY;

if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

// ── Return cached if fresh ──
if (is_file($MOVERS_FILE)) {
    $cached = json_decode(file_get_contents($MOVERS_FILE), true);
    if ($cached && (time() - ($cached['ts'] ?? 0)) < $CACHE_TTL) {
        echo json_encode($cached['data']);
        exit;
    }
}

// ── Load token list ──
$tokens = [];
if (is_file($TOKEN_FILE)) {
    $tc = json_decode(file_get_contents($TOKEN_FILE), true);
    $tokens = $tc['tokens'] ?? [];
}
if (empty($tokens)) {
    echo json_encode(['error' => 'no token data available']);
    exit;
}

// ── TapTools helper ──
function taptools_get(string $url, string $key): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["x-api-key: $key", 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return json_decode($body, true);
}

// ── Fetch price changes for each token ──
$movers = [];
foreach ($tokens as $ticker => $t) {
    $unit = $t['token_id'] ?? '';
    if (!$unit) continue;

    $data = taptools_get(
        "https://openapi.taptools.io/api/v1/token/prices/chg?unit=$unit&timeframes=1h,4h,24h,7d",
        $TAPTOOLS_KEY
    );
    if (!$data) continue;

    $movers[] = [
        'ticker'    => $ticker,
        'name'      => $t['name'] ?? $ticker,
        'price_ada' => $t['price_ada'] ?? null,
        'price_usd' => $t['price_usd'] ?? null,
        'chg_1h'    => $data['1h'] ?? null,
        'chg_4h'    => $data['4h'] ?? null,
        'chg_24h'   => $data['24h'] ?? null,
        'chg_7d'    => $data['7d'] ?? null,
    ];
}

// ── Sort into gainers / losers by 24h change ──
$with24h = array_filter($movers, fn($m) => $m['chg_24h'] !== null);
usort($with24h, fn($a, $b) => ($b['chg_24h'] ?? 0) <=> ($a['chg_24h'] ?? 0));

$gainers = array_values(array_filter($with24h, fn($m) => ($m['chg_24h'] ?? 0) > 0));
$losers  = array_values(array_filter($with24h, fn($m) => ($m['chg_24h'] ?? 0) < 0));
// Losers: worst first
usort($losers, fn($a, $b) => ($a['chg_24h'] ?? 0) <=> ($b['chg_24h'] ?? 0));

// ── Market stats ──
$market = ['dex_volume' => null, 'active_addresses' => null, 'ada_price' => null];
$mstats = taptools_get(
    "https://openapi.taptools.io/api/v1/market/stats?quote=USD",
    $TAPTOOLS_KEY
);
if ($mstats) {
    $market['dex_volume']       = $mstats['totalVolume'] ?? $mstats['volume'] ?? null;
    $market['active_addresses'] = $mstats['activeAddresses'] ?? null;
}

// Derive ADA price from first token with both prices
foreach ($tokens as $t) {
    if (($t['price_ada'] ?? 0) > 0 && ($t['price_usd'] ?? 0) > 0) {
        $market['ada_price'] = round($t['price_usd'] / $t['price_ada'], 4);
        break;
    }
}

// ── Top 5 by volume (from movers data, approximate from price changes) ──
$top_volume = taptools_get(
    "https://openapi.taptools.io/api/v1/token/top/volume",
    $TAPTOOLS_KEY
);
$vol_top5 = [];
if (is_array($top_volume)) {
    $count = 0;
    foreach ($top_volume as $tv) {
        $vol_top5[] = [
            'ticker' => strtoupper($tv['ticker'] ?? ''),
            'volume' => $tv['volume'] ?? 0,
        ];
        if (++$count >= 5) break;
    }
}

$result = [
    'gainers'    => array_slice($gainers, 0, 15),
    'losers'     => array_slice($losers, 0, 15),
    'all'        => $movers,
    'market'     => $market,
    'top_volume' => $vol_top5,
    'updated_at' => time(),
];

// ── Cache ──
file_put_contents($MOVERS_FILE, json_encode([
    'ts'   => time(),
    'data' => $result,
]));

echo json_encode($result, JSON_PRETTY_PRINT);
