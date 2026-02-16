<?php
/**
 * Posada Whale Watch API — serves large DEX trades.
 *
 * - Calls TapTools /integration/latest-block for current height
 * - Calls /integration/events for last ~50 blocks (~17 min window)
 * - Filters swaps where ADA side > 5,000 ADA (whale threshold)
 * - Caches 2 min in data/whales_cache.json
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('serialize_precision', 14);

$CACHE_DIR    = __DIR__ . '/../data';
$WHALES_FILE  = "$CACHE_DIR/whales_cache.json";
$TOKEN_FILE   = "$CACHE_DIR/tokens_cache.json";
$CACHE_TTL    = 120;  // 2 min
require_once __DIR__ . '/config.php';
$TAPTOOLS_KEY = TAPTOOLS_API_KEY;
$THRESHOLD    = 5000;  // ADA

if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

// ── Return cached if fresh ──
if (is_file($WHALES_FILE)) {
    $cached = json_decode(file_get_contents($WHALES_FILE), true);
    if ($cached && (time() - ($cached['ts'] ?? 0)) < $CACHE_TTL) {
        echo json_encode($cached['data']);
        exit;
    }
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

// ── Build ticker lookup from tokens cache ──
$ticker_map = [];
if (is_file($TOKEN_FILE)) {
    $tc = json_decode(file_get_contents($TOKEN_FILE), true);
    foreach (($tc['tokens'] ?? []) as $ticker => $t) {
        $unit = $t['token_id'] ?? '';
        if ($unit) {
            // TapTools events use the policy+hex unit
            $ticker_map[$unit] = $ticker;
        }
    }
}

// ── Get latest block ──
$block_data = taptools_get(
    "https://openapi.taptools.io/api/v1/integration/latest-block",
    $TAPTOOLS_KEY
);
if (!$block_data || !isset($block_data['blockHeight'])) {
    echo json_encode(['trades' => [], 'threshold' => $THRESHOLD, 'error' => 'could not get latest block']);
    exit;
}
$latest = (int)$block_data['blockHeight'];
$from_block = $latest - 50;

// ── Get swap events ──
$events = taptools_get(
    "https://openapi.taptools.io/api/v1/integration/events?from=$from_block&to=$latest&type=swap",
    $TAPTOOLS_KEY
);

$trades = [];
if (is_array($events)) {
    foreach ($events as $ev) {
        // Each event has an ADA amount — filter for whales
        $ada_amount = abs($ev['adaAmount'] ?? $ev['ada_amount'] ?? $ev['lovelace'] ?? 0);
        // TapTools may return lovelace (millionths) or ADA
        if ($ada_amount > 1000000) {
            $ada_amount = $ada_amount / 1000000;
        }

        if ($ada_amount < $THRESHOLD) continue;

        $unit = $ev['unit'] ?? $ev['token'] ?? '';
        $ticker = $ticker_map[$unit] ?? '';
        if (!$ticker && $unit) {
            // Try to extract ticker from unit name
            $ticker = strtoupper(substr($unit, -8));
        }

        $direction = 'BUY';
        if (isset($ev['type'])) {
            $direction = stripos($ev['type'], 'sell') !== false ? 'SELL' : 'BUY';
        } elseif (isset($ev['direction'])) {
            $direction = strtoupper($ev['direction']);
        }

        $trades[] = [
            'time'       => $ev['timestamp'] ?? $ev['time'] ?? time(),
            'tx_hash'    => $ev['txHash'] ?? $ev['tx_hash'] ?? '',
            'token'      => $ticker ?: substr($unit, 0, 12),
            'ada_amount' => round($ada_amount, 0),
            'direction'  => $direction,
            'dex'        => $ev['dex'] ?? '',
        ];
    }
}

// Sort newest first
usort($trades, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
$trades = array_slice($trades, 0, 50);

$result = [
    'trades'     => $trades,
    'threshold'  => $THRESHOLD,
    'block'      => $latest,
    'updated_at' => time(),
];

// ── Cache ──
file_put_contents($WHALES_FILE, json_encode([
    'ts'   => time(),
    'data' => $result,
]));

echo json_encode($result, JSON_PRETTY_PRINT);
