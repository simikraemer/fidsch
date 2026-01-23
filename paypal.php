<?php
declare(strict_types=1);

/* =========================
 * CONFIG
 * ========================= */
$ENABLE_SANDBOX = false; // muss zum Odin-$enableSandbox passen, sonst verifizierst du gegen falsches PayPal
$PAYPAL_LOG     = '/tmp/paypallog_fidsch';
$FORWARD_URL    = 'https://backend.weh.rwth-aachen.de/paypal.php'; // Odin-Ziel

/* =========================
 * LOGGING
 * ========================= */
function pp_log(string $stage, array $ctx = []): void {
    $path = (string)($GLOBALS['PAYPAL_LOG'] ?? '');
    if ($path === '') return;

    $base = [
        'ts'     => date('c'),
        'stage'  => $stage,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        'xff'    => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'host'   => $_SERVER['HTTP_HOST'] ?? null,
        'uri'    => $_SERVER['REQUEST_URI'] ?? null,
    ];

    $line = '[PAYPAL_FIDSCH] ' . json_encode($base + $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

    if (!file_exists($path)) {
        @touch($path);
        @chmod($path, 0644);
    }
    $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($ok === false) error_log('[PAYPAL_FIDSCH] WRITE_FAIL ' . $path . ' stage=' . $stage);
}

function pp_post_subset(array $post): array {
    $keys = [
        'txn_id','txn_type','payment_status','mc_gross','mc_currency',
        'receiver_email','payer_email','custom','test_ipn','ipn_track_id','invoice'
    ];
    $out = [];
    foreach ($keys as $k) {
        if (isset($post[$k])) $out[$k] = $post[$k];
    }
    $out['__keys'] = array_keys($post);
    return $out;
}

/* =========================
 * ENTRY
 * ========================= */
pp_log('HIT', ['post' => pp_post_subset($_POST)]);

// PayPal schickt IPN als POST; alles andere freundlich quittieren
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(200);
    echo "OK\n";
    exit;
}

try {
    $verified = verifyTransaction($_POST);
    pp_log('VERIFY_RESULT', ['verified' => $verified ? 1 : 0]);

    if (!$verified) {
        // Nicht VERIFIED => nicht forwarden, aber 200 zurück (PayPal hat sein Ergebnis)
        http_response_code(200);
        echo "OK\n";
        exit;
    }

    $request_data = $_POST;

    pp_log('FORWARD_START', [
        'to'     => $GLOBALS['FORWARD_URL'],
        'custom' => $request_data['custom'] ?? null,
        'txn_id' => $request_data['txn_id'] ?? null,
        'gross'  => $request_data['mc_gross'] ?? null,
        'status' => $request_data['payment_status'] ?? null,
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $GLOBALS['FORWARD_URL']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Connection: close',
    ]);

    $response_data = curl_exec($ch);

    if ($response_data === false) {
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        $info   = curl_getinfo($ch);
        pp_log('FORWARD_CURL_FAIL', [
            'errno' => $errno,
            'err'   => $errstr,
            'http'  => $info['http_code'] ?? null,
        ]);
        curl_close($ch);

        // Forwarding kaputt => 500, damit PayPal den IPN später erneut versucht
        http_response_code(500);
        echo "FORWARD_FAIL\n";
        exit;
    }

    $info = curl_getinfo($ch);
    pp_log('FORWARD_OK', [
        'http'     => $info['http_code'] ?? null,
        'resp_len' => strlen((string)$response_data),
        'resp_head'=> substr((string)$response_data, 0, 200),
    ]);
    curl_close($ch);

    http_response_code(200);
    echo "OK\n";
    exit;

} catch (Throwable $e) {
    pp_log('EXCEPTION', ['msg' => $e->getMessage(), 'cls' => get_class($e)]);
    // Verifikation/Netz kaputt => 500 für Retry
    http_response_code(500);
    echo "ERROR\n";
    exit;
}

/* =========================
 * VERIFY
 * ========================= */
function verifyTransaction(array $data): bool {
    $enableSandbox = (bool)($GLOBALS['ENABLE_SANDBOX'] ?? false);

    $paypalUrl = $enableSandbox
        ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr'
        : 'https://ipnpb.paypal.com/cgi-bin/webscr';

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
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: Close']);

    $res = curl_exec($ch);
    if ($res === false) {
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: [$errno] $errstr");
    }

    $info = curl_getinfo($ch);
    $httpCode = (int)($info['http_code'] ?? 0);
    curl_close($ch);

    $resTrim = trim((string)$res);
    pp_log('VERIFY_HTTP', [
        'sandbox'   => $enableSandbox ? 1 : 0,
        'verify_url'=> $paypalUrl,
        'http'      => $httpCode,
        'resp_head' => substr($resTrim, 0, 64),
    ]);

    if ($httpCode !== 200) {
        throw new Exception("PayPal responded with http code $httpCode");
    }

    return $resTrim === 'VERIFIED';
}
