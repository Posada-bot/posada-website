<?php
/**
 * Posada Token API — serves enriched token data to the website.
 *
 * - Fetches top tokens from Minswap aggregator (prices)
 * - Caches price snapshots hourly → calculates 24h change
 * - Returns JSON: ticker, price_ada, price_usd, change_24h, volume, description
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
ini_set('serialize_precision', 14);

$CACHE_DIR  = __DIR__ . '/../data';
$TOKEN_FILE = "$CACHE_DIR/tokens_cache.json";   // current token list + prices
$HIST_FILE  = "$CACHE_DIR/price_history.json";   // hourly snapshots for 24h calc
$VOL_FILE   = "$CACHE_DIR/volume_cache.json";   // TapTools 24h volume
$CACHE_TTL  = 300;  // refresh token list every 5 min
$HIST_TTL   = 3600; // snapshot prices every hour
require_once __DIR__ . '/config.php';
$TAPTOOLS_KEY = TAPTOOLS_API_KEY;

$STABLES = ['USDM','USDA','DJED','IUSD','SHEN','OADA','USDC','USDT','DAI'];

$DESCRIPTIONS = [
    'ADA'    => 'Cardano native token — proof-of-stake layer-1 blockchain',
    'SNEK'   => 'Community memecoin on Cardano — largest by market cap',
    'MIN'    => 'Minswap DEX governance token — largest Cardano DEX',
    'IAG'    => 'Iagon decentralised storage and compute marketplace',
    'NIGHT'  => 'Night DEX token — concentrated liquidity on Cardano',
    'STRIKE' => 'Strike Finance lending and borrowing protocol',
    'SUNDAE' => 'SundaeSwap DEX governance token',
    'WMTX'   => 'World Mobile token — decentralised mobile network',
    'INDY'   => 'Indigo Protocol — synthetic assets on Cardano',
    'FLDT'   => 'Fluid Tokens — NFT liquidity and lending',
    'MITHR'  => 'Mithril — stake-based threshold multi-signatures',
    'RISE'   => 'Infinity Rising — community-driven Cardano project',
    'HOSKY'  => 'Hosky Token — the original Cardano memecoin',
    'NTX'    => 'NuNet — decentralised computing framework',
    'IBTC'   => 'Indigo synthetic Bitcoin on Cardano',
    'SURF'   => 'Surf Finance — yield aggregator on Cardano',
    'RSERG'  => 'RealSerg — Cardano community token',
    'LQ'     => 'Liqwid Finance — DeFi lending protocol',
    'STUFF'  => 'Stuff token — Cardano ecosystem utility',
    'WMT'    => 'World Mobile — telecom on blockchain',
    'NVL'    => 'Nuvola — decentralised cloud computing',
    'PALM'   => 'Palm NFT ecosystem token',
    'SPLASH' => 'Splash Protocol — Cardano DEX token',
    'BTN'    => 'Butane — Cardano DeFi utility token',
    'SURGE'  => 'Surge Cardano — DeFi yield protocol',
    'HUNT'   => 'Hunt token — Cardano gaming and rewards',
    'CSWAP'  => 'CardSwap DEX — automated market maker',
    'VYFI'   => 'VyFinance — AI-powered DeFi on Cardano',
    'WRT'    => 'WingRiders — Cardano DEX governance token',
    'FET'    => 'Fetch.ai — AI and autonomous agents (bridged)',
    'AGIX'   => 'SingularityNET — decentralised AI marketplace (bridged)',
];

// ── Ensure data dir exists ──
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

// ── Fetch from Minswap (paginated) ──
function fetch_minswap_tokens(array $stables, int $limit = 31): array {
    $result = [];
    $search_after = null;

    for ($page = 0; $page < 3; $page++) {
        $payload = ['query' => '', 'only_verified' => true, 'limit' => 20];
        if ($search_after) $payload['search_after'] = $search_after;

        $ch = curl_init('https://agg-api.minswap.org/aggregator/tokens');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 429) {
            sleep(5);
            // retry once
            $ch = curl_init('https://agg-api.minswap.org/aggregator/tokens');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
        }

        $data = json_decode($body, true);
        $tokens = $data['tokens'] ?? [];
        if (empty($tokens)) break;

        foreach ($tokens as $t) {
            $ticker = strtoupper($t['ticker'] ?? '');
            if (!$ticker || in_array($ticker, $stables)) continue;
            if (isset($result[$ticker])) continue;

            $result[$ticker] = [
                'ticker'    => $ticker,
                'name'      => $t['project_name'] ?? $ticker,
                'price_ada' => $t['price_by_ada'] ?? null,
                'price_usd' => $t['price_by_usd'] ?? null,
                'token_id'  => $t['token_id'] ?? '',
                'logo'      => $t['logo'] ?? '',
                'decimals'  => $t['decimals'] ?? 0,
            ];

            if (count($result) >= $limit) break 2;
        }

        $search_after = $data['search_after'] ?? null;
        if (!$search_after) break;
    }

    return $result;
}

// ── Load or refresh token cache ──
function get_tokens(string $cache_file, int $ttl, array $stables): array {
    if (is_file($cache_file)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached && (time() - ($cached['ts'] ?? 0)) < $ttl) {
            return $cached['tokens'];
        }
    }

    $tokens = fetch_minswap_tokens($stables);
    if (!empty($tokens)) {
        file_put_contents($cache_file, json_encode([
            'ts'     => time(),
            'tokens' => $tokens,
        ]));
    }

    return $tokens;
}

// ── Price history (hourly snapshots for 24h change) ──
function update_history(string $hist_file, int $hist_ttl, array $tokens): array {
    $history = [];
    if (is_file($hist_file)) {
        $history = json_decode(file_get_contents($hist_file), true) ?: [];
    }

    $now = time();
    $last_snap = $history['last_snapshot'] ?? 0;

    // Take a new snapshot every hour
    if ($now - $last_snap >= $hist_ttl) {
        $snap = ['ts' => $now, 'prices' => []];
        // Store ADA USD price (derived from first token with both prices)
        $snap_ada_usd = null;
        foreach ($tokens as $ticker => $t) {
            $snap['prices'][$ticker] = $t['price_ada'];
            if ($snap_ada_usd === null && ($t['price_ada'] ?? 0) > 0 && ($t['price_usd'] ?? 0) > 0) {
                $snap_ada_usd = $t['price_usd'] / $t['price_ada'];
            }
        }
        if ($snap_ada_usd) {
            $snap['prices']['ADA_USD'] = $snap_ada_usd;
        }
        $history['snapshots'] = $history['snapshots'] ?? [];
        $history['snapshots'][] = $snap;
        $history['last_snapshot'] = $now;

        // Keep only last 25 hours of snapshots
        $cutoff = $now - 90000;
        $history['snapshots'] = array_values(array_filter(
            $history['snapshots'],
            fn($s) => $s['ts'] >= $cutoff
        ));

        file_put_contents($hist_file, json_encode($history));
    }

    return $history;
}

function calc_24h_change(array $history, string $ticker, float $current_price): ?float {
    $snapshots = $history['snapshots'] ?? [];
    if (empty($snapshots) || $current_price <= 0) return null;

    $now = time();
    $target = $now - 86400; // 24h ago

    // Find snapshot closest to 24h ago
    $closest = null;
    $closest_diff = PHP_INT_MAX;
    foreach ($snapshots as $snap) {
        $diff = abs($snap['ts'] - $target);
        if ($diff < $closest_diff && isset($snap['prices'][$ticker])) {
            $closest = $snap['prices'][$ticker];
            $closest_diff = $diff;
        }
    }

    if ($closest === null || $closest <= 0) return null;
    // Only use if we have data within 2 hours of the 24h mark
    if ($closest_diff > 7200) return null;

    return (($current_price - $closest) / $closest) * 100;
}

// ── TapTools 24h volume (cached) ──
function get_volume(string $vol_file, int $ttl, string $api_key): array {
    if (is_file($vol_file)) {
        $cached = json_decode(file_get_contents($vol_file), true);
        if ($cached && (time() - ($cached['ts'] ?? 0)) < $ttl) {
            return $cached['volumes'];
        }
    }

    $ch = curl_init('https://openapi.taptools.io/api/v1/token/top/volume');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["x-api-key: $api_key", 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return [];

    $data = json_decode($body, true);
    if (!is_array($data)) return [];

    // Index by uppercase ticker → volume in ADA
    $volumes = [];
    foreach ($data as $t) {
        $ticker = strtoupper($t['ticker'] ?? '');
        if ($ticker && isset($t['volume'])) {
            $volumes[$ticker] = round($t['volume'], 2);
        }
    }

    if (!empty($volumes)) {
        file_put_contents($vol_file, json_encode([
            'ts'      => time(),
            'volumes' => $volumes,
        ]));
    }

    return $volumes;
}

// ── Main ──
$tokens  = get_tokens($TOKEN_FILE, $CACHE_TTL, $STABLES);
$history = update_history($HIST_FILE, $HIST_TTL, $tokens);
$volumes = get_volume($VOL_FILE, $CACHE_TTL, $TAPTOOLS_KEY);

// Derive ADA/USD from first token with both prices
$ada_usd = null;
foreach ($tokens as $t) {
    if ($ada_usd === null && ($t['price_ada'] ?? 0) > 0 && ($t['price_usd'] ?? 0) > 0) {
        $ada_usd = $t['price_usd'] / $t['price_ada'];
        break;
    }
}

// ADA 24h change = USD price change (tracked as ADA_USD in history)
$ada_change = null;
if ($ada_usd !== null) {
    $ada_change = calc_24h_change($history, 'ADA_USD', $ada_usd);
}

// ADA volume = sum of all token volumes (every DEX trade has ADA on one side)
$ada_volume = 0;
foreach ($volumes as $v) {
    $ada_volume += $v;
}

// Build response — add ADA as #1
$out = [];
$out[] = [
    'rank'       => 1,
    'ticker'     => 'ADA',
    'name'       => 'Cardano',
    'price_ada'  => 1.0,
    'price_usd'  => $ada_usd !== null ? round($ada_usd, 4) : null,
    'change_24h' => $ada_change !== null ? round($ada_change, 2) : null,
    'volume'     => $ada_volume > 0 ? round($ada_volume, 2) : null,
    'description'=> $DESCRIPTIONS['ADA'] ?? '',
    'logo'       => '',
];

$rank = 2;
foreach ($tokens as $ticker => $t) {
    $price_ada = $t['price_ada'];
    $change = calc_24h_change($history, $ticker, $price_ada ?? 0);

    $out[] = [
        'rank'        => $rank++,
        'ticker'      => $ticker,
        'name'        => $t['name'],
        'price_ada'   => $price_ada,
        'price_usd'   => $t['price_usd'],
        'change_24h'  => $change !== null ? round($change, 2) : null,
        'volume'      => $volumes[$ticker] ?? null,
        'description' => $DESCRIPTIONS[$ticker] ?? $t['name'],
        'logo'        => $t['logo'],
    ];
}

echo json_encode([
    'tokens'     => $out,
    'updated_at' => time(),
    'source'     => 'minswap',
], JSON_PRETTY_PRINT);
