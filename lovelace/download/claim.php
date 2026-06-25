<?php
/**
 * Lovelace Tester License API (flat-file, no database).
 *
 * GET  ?action=status        — { total, claimed, remaining }
 * POST ?action=claim         — check IP, claim next available key, return it
 * GET  ?action=verify&key=X  — { valid, status } — backend calls this to validate a key
 */

define('LICENSE_FILE', __DIR__ . '/data/licenses.json');
define('TOTAL_LICENSES', 100);

// ── Helpers ──

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function get_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            return trim(explode(',', $_SERVER[$key])[0]);
        }
    }
    return '0.0.0.0';
}

function load_licenses(): array {
    if (!is_file(LICENSE_FILE)) {
        json_out(['error' => 'License data not found.'], 500);
    }
    $data = json_decode(file_get_contents(LICENSE_FILE), true);
    if (!is_array($data)) {
        json_out(['error' => 'Corrupt license data.'], 500);
    }
    return $data;
}

function save_licenses(array $licenses, $fh): bool {
    $json = json_encode($licenses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    ftruncate($fh, 0);
    rewind($fh);
    return fwrite($fh, $json) !== false;
}

function count_claimed(array $licenses): int {
    $c = 0;
    foreach ($licenses as $l) {
        if ($l['status'] === 'claimed') $c++;
    }
    return $c;
}

// ── Routes ──

$action = $_GET['action'] ?? '';

// Status (GET)
if ($action === 'status') {
    $licenses = load_licenses();
    $claimed = count_claimed($licenses);
    json_out([
        'total'     => TOTAL_LICENSES,
        'claimed'   => $claimed,
        'remaining' => TOTAL_LICENSES - $claimed,
    ]);
}

// Verify (GET) — backend calls this to check if a key is valid
if ($action === 'verify') {
    $key = preg_replace('/[^A-Z0-9\-]/', '', $_GET['key'] ?? '');
    if (!$key) {
        json_out(['error' => 'Missing key parameter.'], 400);
    }
    $licenses = load_licenses();
    foreach ($licenses as $l) {
        if ($l['key'] === $key) {
            json_out([
                'valid'  => true,
                'status' => $l['status'],
                'plan'   => 'lifetime_elite',
            ]);
        }
    }
    json_out(['valid' => false, 'status' => 'not_found'], 404);
}

// Claim (POST)
if ($action === 'claim' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = [];

    $ip    = get_client_ip();
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $input['email'] : null;

    // Open with exclusive lock for concurrent safety
    $fh = fopen(LICENSE_FILE, 'r+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        json_out(['error' => 'Server busy, please try again.'], 503);
    }

    $licenses = json_decode(stream_get_contents($fh), true);
    if (!is_array($licenses)) {
        flock($fh, LOCK_UN);
        fclose($fh);
        json_out(['error' => 'Corrupt license data.'], 500);
    }

    // One per IP
    foreach ($licenses as $l) {
        if ($l['status'] === 'claimed' && $l['claim_ip'] === $ip) {
            flock($fh, LOCK_UN);
            fclose($fh);
            json_out(['error' => 'You have already claimed a tester license from this IP.'], 409);
        }
    }

    // Find first available
    $claimed = count_claimed($licenses);
    if ($claimed >= TOTAL_LICENSES) {
        flock($fh, LOCK_UN);
        fclose($fh);
        json_out(['error' => 'All tester licenses have been claimed.'], 410);
    }

    $claimedKey = null;
    foreach ($licenses as &$l) {
        if ($l['status'] === 'available') {
            $l['status']     = 'claimed';
            $l['claimed_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $l['claim_ip']   = $ip;
            $l['email']      = $email;
            $claimedKey      = $l['key'];
            break;
        }
    }
    unset($l);

    if (!$claimedKey) {
        flock($fh, LOCK_UN);
        fclose($fh);
        json_out(['error' => 'All tester licenses have been claimed.'], 410);
    }

    save_licenses($licenses, $fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $remaining = TOTAL_LICENSES - $claimed - 1;
    json_out([
        'license_key' => $claimedKey,
        'plan'        => 'lifetime',
        'remaining'   => $remaining,
    ]);
}

// Fallback
json_out(['error' => 'Invalid action.'], 400);
