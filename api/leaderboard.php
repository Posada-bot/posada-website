<?php
/**
 * Posada Leaderboard API
 *
 * GET  — returns leaderboard + recent trades
 * POST — receives trade reports from bots (opt-in)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
ini_set('serialize_precision', 14);

$DATA_DIR   = __DIR__ . '/../data';
$TRADES_FILE = "$DATA_DIR/trades.json";
$USERS_FILE  = "$DATA_DIR/users.json";

if (!is_dir($DATA_DIR)) mkdir($DATA_DIR, 0755, true);

// ── Load data ──
function load_json(string $file): array {
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function save_json(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// ── POST: receive trade from bot ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['customer_id']) || empty($input['event'])) {
        http_response_code(400);
        echo json_encode(['error' => 'missing customer_id or event']);
        exit;
    }

    $cid    = $input['customer_id'];
    $event  = $input['event'];

    // Update user info if provided
    if (!empty($input['username']) || !empty($input['opt_in'])) {
        $users = load_json($USERS_FILE);
        if (!isset($users[$cid])) {
            $users[$cid] = ['username' => '', 'opt_in' => false, 'joined' => time()];
        }
        if (!empty($input['username'])) {
            $users[$cid]['username'] = substr($input['username'], 0, 20);
        }
        if (isset($input['opt_in'])) {
            $users[$cid]['opt_in'] = (bool)$input['opt_in'];
        }
        save_json($USERS_FILE, $users);
    }

    // Only record if user is opted in
    $users = load_json($USERS_FILE);
    if (empty($users[$cid]['opt_in'])) {
        echo json_encode(['ok' => true, 'recorded' => false, 'reason' => 'not opted in']);
        exit;
    }

    // Record trade
    $trade = [
        'ts'        => $input['ts'] ?? time(),
        'cid'       => $cid,
        'event'     => $event,
        'symbol'    => $input['symbol'] ?? '',
        'strategy'  => $input['strategy'] ?? '',
        'cost'      => $input['cost'] ?? null,
        'pnl'       => $input['pnl'] ?? null,
        'reason'    => $input['reason'] ?? '',
    ];

    $trades = load_json($TRADES_FILE);
    $trades[] = $trade;

    // Keep last 500 trades
    if (count($trades) > 500) {
        $trades = array_slice($trades, -500);
    }

    save_json($TRADES_FILE, $trades);
    echo json_encode(['ok' => true, 'recorded' => true]);
    exit;
}

// ── GET: return leaderboard ──
$trades = load_json($TRADES_FILE);
$users  = load_json($USERS_FILE);
$view   = $_GET['view'] ?? '';

// Build per-user stats
$stats = [];
// Also build per-strategy stats
$strat_stats = [];

foreach ($trades as $t) {
    $cid = $t['cid'];
    if (!isset($stats[$cid])) {
        $stats[$cid] = [
            'trades' => 0, 'wins' => 0, 'losses' => 0,
            'total_pnl' => 0.0, 'strategies' => [],
        ];
    }

    $s = &$stats[$cid];
    $ev = $t['event'];
    $strat = strtoupper($t['strategy'] ?? '');

    if (in_array($ev, ['buy', 'add'])) {
        $s['trades']++;
    }

    if (in_array($ev, ['sell', 'sell_half'])) {
        $s['trades']++;
        $pnl = $t['pnl'] ?? 0;
        $s['total_pnl'] += $pnl;
        if ($pnl > 0) $s['wins']++;
        elseif ($pnl < 0) $s['losses']++;

        // Per-strategy aggregates
        if ($strat) {
            if (!isset($strat_stats[$strat])) {
                $strat_stats[$strat] = [
                    'name' => $strat, 'trades' => 0, 'wins' => 0, 'losses' => 0,
                    'total_pnl' => 0.0, 'pnl_list' => [], 'tokens' => [],
                ];
            }
            $ss = &$strat_stats[$strat];
            $ss['trades']++;
            $ss['total_pnl'] += $pnl;
            $ss['pnl_list'][] = $pnl;
            if ($pnl > 0) $ss['wins']++;
            elseif ($pnl < 0) $ss['losses']++;
            $sym = $t['symbol'] ?? '';
            if ($sym) $ss['tokens'][$sym] = ($ss['tokens'][$sym] ?? 0) + 1;
        }
    }

    if (!empty($t['strategy'])) {
        $s['strategies'][$t['strategy']] = ($s['strategies'][$t['strategy']] ?? 0) + 1;
    }
}

// ── Strategies view ──
if ($view === 'strategies') {
    $strategies_out = [];
    foreach ($strat_stats as $strat => $ss) {
        $total = $ss['wins'] + $ss['losses'];
        // Find most traded token
        $top_token = '';
        if (!empty($ss['tokens'])) {
            arsort($ss['tokens']);
            $top_token = array_key_first($ss['tokens']);
        }
        $strategies_out[] = [
            'name'       => $ss['name'],
            'trades'     => $ss['trades'],
            'wins'       => $ss['wins'],
            'losses'     => $ss['losses'],
            'win_rate'   => $total > 0 ? round(($ss['wins'] / $total) * 100, 1) : 0,
            'avg_pnl'    => $ss['trades'] > 0 ? round($ss['total_pnl'] / $ss['trades'], 2) : 0,
            'total_pnl'  => round($ss['total_pnl'], 2),
            'top_token'  => $top_token,
        ];
    }
    usort($strategies_out, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

    echo json_encode([
        'strategies'  => $strategies_out,
        'updated_at'  => time(),
    ]);
    exit;
}

// ── Default: leaderboard view ──

// Build leaderboard sorted by total P&L
$board = [];
foreach ($stats as $cid => $s) {
    $user = $users[$cid] ?? [];
    $username = $user['username'] ?? '';
    if (!$username) {
        // Generate "Posada XXX" from customer ID
        $hash = strtoupper(substr(md5($cid), 0, 3));
        $username = "Posada $hash";
    }

    // Find favorite strategy
    $fav = '';
    if (!empty($s['strategies'])) {
        arsort($s['strategies']);
        $fav = array_key_first($s['strategies']);
    }

    $total = $s['wins'] + $s['losses'];
    $board[] = [
        'username'    => $username,
        'total_pnl'   => round($s['total_pnl'], 2),
        'trades'      => $s['trades'],
        'wins'        => $s['wins'],
        'losses'      => $s['losses'],
        'win_rate'    => $total > 0 ? round(($s['wins'] / $total) * 100, 1) : 0,
        'fav_strategy' => strtoupper($fav),
    ];
}

usort($board, fn($a, $b) => $b['total_pnl'] <=> $a['total_pnl']);

// Recent trades (last 20, newest first)
$recent = array_reverse(array_slice($trades, -20));
$recent_out = [];
foreach ($recent as $t) {
    $cid = $t['cid'];
    $user = $users[$cid] ?? [];
    $username = $user['username'] ?? '';
    if (!$username) {
        $hash = strtoupper(substr(md5($cid), 0, 3));
        $username = "Posada $hash";
    }
    $recent_out[] = [
        'ts'       => $t['ts'],
        'username' => $username,
        'event'    => $t['event'],
        'symbol'   => $t['symbol'],
        'strategy' => strtoupper($t['strategy'] ?? ''),
        'pnl'      => $t['pnl'],
        'reason'   => $t['reason'] ?? '',
    ];
}

echo json_encode([
    'leaderboard' => array_slice($board, 0, 20),
    'recent'      => $recent_out,
    'updated_at'  => time(),
]);
