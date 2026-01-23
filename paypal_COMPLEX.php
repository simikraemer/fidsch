<?php
declare(strict_types=1);

// fidsch.de/paypal.php
// IPN Receiver + Verify bei PayPal -> forward an Odin Mitgliedskonto.php?ipn=1
// + Health endpoint: ?health=1&sb=0|1
// + Optional: relay logs to Odin Mitgliedskonto.php?relaylog=1

function load_dotenv(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
        }
    }
}

// fidsch: /etc/credentials/wehpaypal.env
load_dotenv('/etc/credentials/wehpaypal.env');

/* =========================
 * CONFIG
 * ========================= */

$PAYPAL_LOG = __DIR__ . '/paypal_ipn.log';

// Odin endpoints (per ENV empfohlen)
$ODIN_IPN_URL      = getenv('ODIN_IPN_URL')      ?: 'https://backend.weh.rwth-aachen.de/Mitgliedskonto.php?ipn=1';
$ODIN_RELAYLOG_URL = getenv('ODIN_RELAYLOG_URL') ?: 'https://backend.weh.rwth-aachen.de/Mitgliedskonto.php?relaylog=1';

// Shared secret (muss identisch zu odin sein)
$RELAY_SECRET = getenv('PAYPAL_RELAY_SECRET') ?: '';

/* =========================
 * HELPERS
 * ========================= */

function hdr(string $name): ?string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function pp_log_local(string $stage, array $ctx = []): void {
    $base = [
        'ts'     => date('c'),
        'stage'  => $stage,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        'xff'    => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'host'   => $_SERVER['HTTP_HOST'] ?? null,
        'uri'    => $_SERVER['REQUEST_URI'] ?? null,
    ];

    $line = '[PAYPAL_FWD] ' . json_encode($base + $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    $path = (string)($GLOBALS['PAYPAL_LOG'] ?? '');
    if ($path === '') return;

    if (!file_exists($path)) {
        @touch($path);
        @chmod($path, 0640);
    }

    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function relay_log_to_odin(string $stage, string $msg = '', array $ctx = []): void {
    $url = (string)($GLOBALS['ODIN_RELAYLOG_URL'] ?? '');
    $secret = (string)($GLOBALS['RELAY_SECRET'] ?? '');
    if ($url === '' || $secret === '') return;

    $payload = [
        'stage' => $stage,
        'msg'   => $msg,
        'ctx'   => json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-PayPal-Relay: fidsch',
        'X-PayPal-Relay-Secret: ' . $secret,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function pp_log(string $stage, array $ctx = []): void {
    pp_log_local($stage, $ctx);
    relay_log_to_odin($stage, '', $ctx);
}

function pp_post_subset(array $post): array {
    $keys = [
        'txn_id','txn_type','payment_status','mc_gross','mc_currency',
        'receiver_email','payer_email','custom','test_ipn','ipn_track_id','invoice'
    ];
    $out = [];
    foreach ($keys as $k) if (isset($post[$k])) $out[$k] = $post[$k];
    $out['__keys'] = array_keys($post);
    return $out;
}

/* =========================
 * PayPal verify
 * ========================= */

function paypal_verify_url(bool $sandbox): string {
    return $sandbox
        ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
        : 'https://ipnpb.paypal.com/cgi-bin/webscr';
}

function detect_sandbox(): bool {
    // sb=1 im notify_url erlaubt mode pro request
    if (isset($_GET['sb']) && (string)$_GET['sb'] === '1') return true;
    // PayPal sandbox IPN sendet test_ipn=1
    if (!empty($_POST['test_ipn']) && (string)$_POST['test_ipn'] === '1') return true;
    return false;
}

function verifyTransaction(array $data, bool $sandbox): bool {
    $paypalUrl = paypal_verify_url($sandbox);

    $req = 'cmd=_notify-validate';
    foreach ($data as $key => $value) {
        $value = urlencode(stripslashes((string)$value));
        $value = preg_replace('/(.*[^%^0^D])(%0A)(.*)/i', '${1}%0D%0A${3}', $value);
        $req .= "&$key=$value";
    }

    $ch = curl_init($paypalUrl);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: Close']);

    $res = curl_exec($ch);
    if ($res === false) {
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: [$errno] $errstr");
    }

    $info     = curl_getinfo($ch);
    $httpCode = (int)($info['http_code'] ?? 0);
    curl_close($ch);

    $resTrim = trim((string)$res);

    pp_log('VERIFY_HTTP', [
        'sandbox'    => $sandbox ? 1 : 0,
        'verify_url' => $paypalUrl,
        'http'       => $httpCode,
        'resp_head'  => substr($resTrim, 0, 64),
    ]);

    if ($httpCode !== 200) throw new RuntimeException("PayPal responded with http code $httpCode");

    return $resTrim === 'VERIFIED';
}

/* =========================
 * HEALTH ENDPOINT
 * ========================= */

function health_endpoint(): void {
    $sandbox = false;
    if (isset($_GET['sb']) && (string)$_GET['sb'] === '1') $sandbox = true;

    $paypalUrl = paypal_verify_url($sandbox);

    $t0 = microtime(true);
    $ch = curl_init($paypalUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'cmd=_notify-validate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $http = (int)($info['http_code'] ?? 0);
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // PayPal wird typischerweise "INVALID" antworten, das ist okay. Wichtig ist http 200.
    $ok = ($http === 200 && is_string($res) && $res !== '');

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $ok,
        'sandbox' => $sandbox ? 1 : 0,
        'paypal_url' => $paypalUrl,
        'paypal_http' => $http,
        'paypal_resp_head' => is_string($res) ? substr(trim($res), 0, 32) : null,
        'ms' => $ms,
        'err' => $err ?: null,
        'ts' => date('c'),
        'server' => 'fidsch',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* =========================
 * ENTRY
 * ========================= */

$GLOBALS['PAYPAL_LOG'] = $PAYPAL_LOG;
$GLOBALS['ODIN_IPN_URL'] = $ODIN_IPN_URL;
$GLOBALS['ODIN_RELAYLOG_URL'] = $ODIN_RELAYLOG_URL;
$GLOBALS['RELAY_SECRET'] = $RELAY_SECRET;

if (isset($_GET['health']) && (string)$_GET['health'] === '1') {
    health_endpoint();
    exit;
}

pp_log('HIT', ['post' => pp_post_subset($_POST)]);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST)) {
    http_response_code(200);
    echo "OK\n";
    exit;
}

$sandbox = detect_sandbox();

try {
    $verified = verifyTransaction($_POST, $sandbox);
    pp_log('VERIFY_RESULT', ['verified' => $verified ? 1 : 0]);

    if ($verified) {
        $request_data = $_POST;

        // Relay marker fields (für Odin Logs)
        $request_data['__relay'] = 'fidsch';
        $request_data['__relay_verified'] = 1;
        $request_data['__relay_ts'] = time();
        $request_data['__relay_sb'] = $sandbox ? 1 : 0;

        pp_log('FORWARD_START', [
            'to'     => $GLOBALS['ODIN_IPN_URL'],
            'sandbox'=> $sandbox ? 1 : 0,
            'custom' => $request_data['custom'] ?? null,
            'txn_id' => $request_data['txn_id'] ?? null,
            'gross'  => $request_data['mc_gross'] ?? null,
            'status' => $request_data['payment_status'] ?? null,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $GLOBALS['ODIN_IPN_URL']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 14);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-PayPal-Relay: fidsch',
            'X-PayPal-Relay-Secret: ' . $GLOBALS['RELAY_SECRET'],
        ]);

        $response_data = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($response_data === false) {
            pp_log('FORWARD_CURL_FAIL', [
                'errno' => curl_errno($ch),
                'err'   => curl_error($ch),
                'http'  => $info['http_code'] ?? null,
            ]);
        } else {
            pp_log('FORWARD_OK', [
                'http'      => $info['http_code'] ?? null,
                'resp_len'  => strlen((string)$response_data),
                'resp_head' => substr((string)$response_data, 0, 200),
            ]);
        }
        curl_close($ch);
    } else {
        pp_log('SKIP_FORWARD_NOT_VERIFIED', []);
    }
} catch (Throwable $e) {
    pp_log('EXCEPTION', ['msg' => $e->getMessage(), 'cls' => get_class($e)]);
}

http_response_code(200);
echo "OK\n";
