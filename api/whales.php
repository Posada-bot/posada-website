<?php
/**
 * Posada Whale Watch API — serves large DEX trades.
 *
 * - Calls TapTools /integration/latest-block for current height
 * - Calls /integration/events for last ~50 blocks (~17 min window)
 * - Looks up /integration/pair to identify ADA side of each pool
 * - Filters swaps where ADA side > 5,000 ADA (whale threshold)
 * - Caches 2 min in data/whales_cache.json, pairs cached 24h
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('serialize_precision', 14);

$CACHE_DIR    = __DIR__ . '/../data';
$WHALES_FILE  = "$CACHE_DIR/whales_cache.json";
$PAIRS_FILE   = "$CACHE_DIR/pairs_cache.json";
$TOKEN_FILE   = "$CACHE_DIR/tokens_cache.json";
$CACHE_TTL    = 120;  // 2 min
$PAIRS_TTL    = 86400; // 24h for pair lookups
require_once __DIR__ . '/config.php';
$TAPTOOLS_KEY = TAPTOOLS_API_KEY;
$THRESHOLD    = 5000;  // ADA

// ADA identifier used by TapTools
$ADA_UNIT = '000000000000000000000000000000000000000000000000000000006c6f76656c616365';

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

// ── Pair cache: pairId → { ada_index: 0|1, token_unit: "..." } ──
$pairs_cache = [];
if (is_file($PAIRS_FILE)) {
    $pc = json_decode(file_get_contents($PAIRS_FILE), true);
    if ($pc && (time() - ($pc['ts'] ?? 0)) < $PAIRS_TTL) {
        $pairs_cache = $pc['pairs'] ?? [];
    }
}

function resolve_pair(string $pairId): ?array {
    global $pairs_cache, $TAPTOOLS_KEY, $ADA_UNIT;
    if (isset($pairs_cache[$pairId])) return $pairs_cache[$pairId];

    $data = taptools_get(
        "https://openapi.taptools.io/api/v1/integration/pair?id=" . urlencode($pairId),
        $TAPTOOLS_KEY
    );
    if (!$data || !isset($data['pair'])) return null;

    $pair = $data['pair'];
    $a0 = $pair['asset0Id'] ?? '';
    $a1 = $pair['asset1Id'] ?? '';

    if ($a1 === $ADA_UNIT || $a1 === 'lovelace' || $a1 === '') {
        $info = ['ada_index' => 1, 'token_unit' => $a0];
    } elseif ($a0 === $ADA_UNIT || $a0 === 'lovelace' || $a0 === '') {
        $info = ['ada_index' => 0, 'token_unit' => $a1];
    } else {
        // Neither side is ADA — skip this pair
        $info = null;
    }

    $pairs_cache[$pairId] = $info;
    return $info;
}

// ── Build ticker lookup from tokens cache ──
$ticker_map = [];
if (is_file($TOKEN_FILE)) {
    $tc = json_decode(file_get_contents($TOKEN_FILE), true);
    foreach (($tc['tokens'] ?? []) as $ticker => $t) {
        $unit = $t['token_id'] ?? '';
        if ($unit) $ticker_map[$unit] = $ticker;
    }
}

// ── Get latest block ──
$block_data = taptools_get(
    "https://openapi.taptools.io/api/v1/integration/latest-block",
    $TAPTOOLS_KEY
);
$block = $block_data['block'] ?? $block_data ?? null;
$latest = (int)($block['blockNumber'] ?? $block['blockHeight'] ?? 0);
if (!$latest) {
    echo json_encode(['trades' => [], 'threshold' => $THRESHOLD, 'error' => 'could not get latest block']);
    exit;
}
$from_block = $latest - 50;

// ── Get swap events ──
$events_data = taptools_get(
    "https://openapi.taptools.io/api/v1/integration/events?fromBlock=$from_block&toBlock=$latest&type=swap",
    $TAPTOOLS_KEY
);
$events = $events_data['events'] ?? $events_data ?? [];

$trades = [];
if (is_array($events)) {
    foreach ($events as $ev) {
        $pairId = $ev['pairId'] ?? '';
        if (!$pairId) continue;

        // Resolve which side is ADA
        $pair_info = resolve_pair($pairId);
        if (!$pair_info) continue; // not an ADA pair

        $ada_idx = $pair_info['ada_index'];
        $token_unit = $pair_info['token_unit'];

        // Calculate ADA amount from the swap
        $ada_in  = (float)($ev["asset{$ada_idx}In"] ?? 0);
        $ada_out = (float)($ev["asset{$ada_idx}Out"] ?? 0);
        $ada_amount = max($ada_in, $ada_out);

        if ($ada_amount < $THRESHOLD) continue;

        // Direction: if user sent ADA (ada_in > 0), they're buying the token
        $direction = $ada_in > 0 ? 'BUY' : 'SELL';

        // Resolve ticker
        $ticker = $ticker_map[$token_unit] ?? '';
        if (!$ticker && $token_unit) {
            // Try hex-decoding the asset name portion (after policy ID)
            if (strlen($token_unit) > 56) {
                $hex_name = substr($token_unit, 56);
                $decoded = @hex2bin($hex_name);
                if ($decoded && ctype_print($decoded)) {
                    $ticker = strtoupper($decoded);
                }
            }
            if (!$ticker) {
                $ticker = substr($token_unit, 0, 12) . '...';
            }
        }

        // DEX from factory address (best effort)
        $dex = $ev['factoryAddress'] ?? $ev['dex'] ?? '';

        $trades[] = [
            'time'       => $ev['block']['blockTimestamp'] ?? time(),
            'tx_hash'    => $ev['txnId'] ?? '',
            'token'      => $ticker,
            'ada_amount' => round($ada_amount, 0),
            'direction'  => $direction,
            'dex'        => $dex,
        ];
    }
}

// Save pairs cache
file_put_contents($PAIRS_FILE, json_encode([
    'ts'    => time(),
    'pairs' => $pairs_cache,
]));

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
