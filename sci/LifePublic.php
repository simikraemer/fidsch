<?php

require_once __DIR__ . '/Life.php';

LifeTimelinePage::startSession();
require_once __DIR__ . '/../db.php';

function lifePublicGetCredentialsConfig(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $configPath = '/work/credentials.json';
    if (!is_file($configPath)) {
        $config = [];
        return $config;
    }

    $raw = file_get_contents($configPath);
    $decoded = json_decode($raw ?: '[]', true);

    $config = is_array($decoded) ? $decoded : [];
    return $config;
}

function lifePublicGetClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function lifePublicCurrentRequestPath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/sci/LifePublic.php';
    $path = parse_url($uri, PHP_URL_PATH);

    return is_string($path) && $path !== '' ? $path : '/sci/LifePublic.php';
}

function lifePublicLogLoginEvent(?mysqli $loginconn, array $event): void
{
    if (!$loginconn instanceof mysqli) {
        return;
    }

    $sql = "INSERT INTO login_events
            (username, auth_mode, success, http_status, client_ip, x_forwarded_for, session_id, host, request_uri, referer, user_agent)
            VALUES
            (?, ?, ?, ?, INET6_ATON(?), ?, ?, ?, ?, ?, ?)";

    $stmt = $loginconn->prepare($sql);
    if (!$stmt) {
        error_log('login logger: prepare failed: ' . $loginconn->error);
        return;
    }

    $username    = $event['username'] ?? null;
    $authMode    = $event['auth_mode'] ?? 'pw';
    $success     = (int)($event['success'] ?? 0);
    $httpStatus  = isset($event['http_status']) ? (int)$event['http_status'] : null;
    $clientIp    = $event['client_ip'] ?? '0.0.0.0';
    $xff         = $event['x_forwarded_for'] ?? null;
    $sessionId   = $event['session_id'] ?? null;
    $host        = $event['host'] ?? null;
    $requestUri  = $event['request_uri'] ?? null;
    $referer     = $event['referer'] ?? null;
    $userAgent   = $event['user_agent'] ?? null;

    $stmt->bind_param(
        'ssiisssssss',
        $username,
        $authMode,
        $success,
        $httpStatus,
        $clientIp,
        $xff,
        $sessionId,
        $host,
        $requestUri,
        $referer,
        $userAgent
    );

    if (!$stmt->execute()) {
        error_log('login logger: execute failed: ' . $stmt->error);
    }

    $stmt->close();
}

function lifePublicCountRecentFailedAttempts(?mysqli $loginconn, string $clientIp, string $requestPath, int $hours = 24): int
{
    if (!$loginconn instanceof mysqli) {
        return 0;
    }

    $cutoff = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

    $sql = "SELECT COUNT(*) AS cnt
            FROM login_events
            WHERE client_ip = INET6_ATON(?)
              AND auth_mode = 'pw'
              AND success = 0
              AND http_status = 401
              AND event_time >= ?
              AND SUBSTRING_INDEX(COALESCE(request_uri, ''), '?', 1) = ?";

    $stmt = $loginconn->prepare($sql);
    if (!$stmt) {
        error_log('login logger: prepare failed in lifePublicCountRecentFailedAttempts: ' . $loginconn->error);
        return 0;
    }

    $stmt->bind_param('sss', $clientIp, $cutoff, $requestPath);

    if (!$stmt->execute()) {
        error_log('login logger: execute failed in lifePublicCountRecentFailedAttempts: ' . $stmt->error);
        $stmt->close();
        return 0;
    }

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return (int)($row['cnt'] ?? 0);
}

function lifePublicRenderLogin(?string $error = null, bool $blocked = false): void
{
    $self = $_SERVER['REQUEST_URI'] ?? '/sci/LifePublic.php';

    if ($blocked) {
        http_response_code(429);
        header('Retry-After: 86400');
    } else {
        http_response_code($error ? 401 : 200);
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Studienplan</title>
    <link rel="stylesheet" href="../FIJI.css">
</head>
<body style="margin-top:0;">
    <div class="content-wrap" style="padding-top:48px; padding-bottom:48px;">
        <div class="container" style="max-width:460px;">
            <h1 class="ueberschrift" style="margin-top:0;">Studienplan</h1>

            <?php if ($error !== null && $error !== ''): ?>
                <div style="margin-bottom:16px; padding:12px 14px; border-radius:var(--border-radius); background:#ffe8e8; color:#a40000; box-shadow:var(--shadow); font-weight:700;">
                    <?= LifeTimelinePage::esc($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$blocked): ?>
                <form method="post" action="<?= LifeTimelinePage::esc($self) ?>" class="form-block">
                    <div>
                        <label for="life_public_password" class="lt-label">Passwort</label>
                        <input
                            type="password"
                            id="life_public_password"
                            name="life_public_password"
                            autocomplete="current-password"
                            required
                            autofocus
                        >
                    </div>

                    <button type="submit">Seite freischalten</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

$configAll = lifePublicGetCredentialsConfig();
$lifePublicConfig = $configAll['lifepublic'] ?? [];

$lifePublicPassword = (string)($lifePublicConfig['password'] ?? '');
$lifePublicLogUser  = (string)($lifePublicConfig['log_username'] ?? 'life_public');

$loginconn = LifeTimelinePage::resolveMysqliConnection(['loginconn']);

if ($lifePublicPassword === '') {
    http_response_code(500);
    exit('lifepublic.password fehlt in /work/credentials.json');
}

if (empty($_SESSION['life_public_authed'])) {
    $clientIp = lifePublicGetClientIp();
    $requestPath = lifePublicCurrentRequestPath();
    $failedAttemptsLast24h = lifePublicCountRecentFailedAttempts($loginconn, $clientIp, $requestPath, 24);
    $isBlocked = $failedAttemptsLast24h >= 5;

    if ($isBlocked) {
        lifePublicRenderLogin('Passwort zu oft falsch eingegeben, du Trottel!', true);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $submittedPassword = (string)($_POST['life_public_password'] ?? '');

        if (hash_equals($lifePublicPassword, $submittedPassword)) {
            session_regenerate_id(true);

            $_SESSION['life_public_authed'] = true;

            if (empty($_SESSION['life_public_login_logged'])) {
                lifePublicLogLoginEvent($loginconn, [
                    'username'        => $lifePublicLogUser,
                    'auth_mode'       => 'pw',
                    'success'         => 1,
                    'http_status'     => 200,
                    'client_ip'       => $clientIp,
                    'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
                    'session_id'      => session_id(),
                    'host'            => $_SERVER['HTTP_HOST'] ?? null,
                    'request_uri'     => $_SERVER['REQUEST_URI'] ?? null,
                    'referer'         => $_SERVER['HTTP_REFERER'] ?? null,
                    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
                $_SESSION['life_public_login_logged'] = true;
            }

            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/sci/LifePublic.php'));
            exit;
        }

        lifePublicLogLoginEvent($loginconn, [
            'username'        => $lifePublicLogUser,
            'auth_mode'       => 'pw',
            'success'         => 0,
            'http_status'     => 401,
            'client_ip'       => $clientIp,
            'x_forwarded_for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'session_id'      => session_id(),
            'host'            => $_SERVER['HTTP_HOST'] ?? null,
            'request_uri'     => $_SERVER['REQUEST_URI'] ?? null,
            'referer'         => $_SERVER['HTTP_REFERER'] ?? null,
            'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        $failedAttemptsLast24h++;

        if ($failedAttemptsLast24h >= 5) {
            lifePublicRenderLogin('Zu oft Passwort falsch eingegeben. Versuchen Sie es in 24 h erneut.', true);
        }

        lifePublicRenderLogin('Falsches Passwort.');
    }

    lifePublicRenderLogin();
}

$conn = LifeTimelinePage::requireLifeConnection(['sciconn', 'conn', 'mysqli']);
$view = LifeTimelinePage::buildViewData($conn);

LifeTimelinePage::renderStandaloneDocument($view, [
    'title' => 'Studienplan',
    'body_style' => 'margin-top:0;',
    'extra_css' => '
        .lt-page { margin-top: 18px; }
        .life-axis-sticky { top: 0; }
    ',
]);