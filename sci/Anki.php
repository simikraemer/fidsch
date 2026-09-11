<?php
// sci/Anki.php

require_once __DIR__ . '/../auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const ANKI_PYTHON = '/opt/fiji-anki/venv/bin/python';
const ANKI_BRIDGE = __DIR__ . '/anki_bridge.py';
const ANKI_STATE_DIR = '/var/lib/fiji-anki';
const ANKI_MEDIA_DIR = ANKI_STATE_DIR . '/collection.media';

if (empty($_SESSION['anki_web_csrf'])) {
    $_SESSION['anki_web_csrf'] = bin2hex(random_bytes(24));
}
$ankiCsrf = (string)$_SESSION['anki_web_csrf'];

function ankiJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function ankiServeMedia(): never
{
    $filename = rawurldecode((string)($_GET['file'] ?? ''));

    if (
        $filename === ''
        || $filename !== basename($filename)
        || str_contains($filename, "\0")
        || str_contains($filename, '/')
        || str_contains($filename, '\\')
    ) {
        http_response_code(400);
        exit('Ungültiger Medienname.');
    }

    $base = realpath(ANKI_MEDIA_DIR);
    if ($base === false) {
        http_response_code(404);
        exit('Anki-Medienverzeichnis existiert noch nicht.');
    }

    $path = realpath($base . DIRECTORY_SEPARATOR . $filename);
    if (
        $path === false
        || !is_file($path)
        || dirname($path) !== $base
    ) {
        http_response_code(404);
        exit('Medium nicht gefunden.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($path);

    $allowedPrefixes = ['image/', 'audio/', 'video/', 'font/'];
    $allowedExact = [
        'application/font-woff',
        'application/font-woff2',
        'application/vnd.ms-fontobject',
        'application/octet-stream',
    ];

    $allowed = in_array($mime, $allowedExact, true);
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($mime, $prefix)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        http_response_code(415);
        exit('Nicht unterstützter Medientyp.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    readfile($path);
    exit;
}

function ankiRunBridge(array $payload): array
{
    if (!is_file(ANKI_PYTHON) || !is_executable(ANKI_PYTHON)) {
        throw new RuntimeException('Anki-Python wurde nicht gefunden: ' . ANKI_PYTHON);
    }

    if (!is_file(ANKI_BRIDGE)) {
        throw new RuntimeException('anki_bridge.py wurde nicht gefunden.');
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        [ANKI_PYTHON, ANKI_BRIDGE],
        $descriptors,
        $pipes,
        __DIR__
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Anki-Backend konnte nicht gestartet werden.');
    }

    $input = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    fwrite($pipes[0], $input === false ? '{}' : $input);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    $data = json_decode((string)$stdout, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Ungültige Antwort vom Anki-Backend.'
            . ($stderr !== '' ? ' ' . trim($stderr) : '')
        );
    }

    if ($exitCode !== 0 || empty($data['ok'])) {
        $message = (string)($data['message'] ?? 'Anki-Backendfehler.');
        if ($stderr !== '') {
            $message .= ' ' . trim($stderr);
        }
        throw new RuntimeException($message);
    }

    return $data;
}

if (($_GET['action'] ?? '') === 'media') {
    ankiServeMedia();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw = file_get_contents('php://input');
        $request = json_decode((string)$raw, true);

        if (!is_array($request)) {
            ankiJson(['ok' => false, 'message' => 'Ungültige Anfrage.'], 400);
        }

        $csrf = (string)($request['csrf'] ?? '');
        if (!hash_equals($ankiCsrf, $csrf)) {
            ankiJson(['ok' => false, 'message' => 'Ungültiges CSRF-Token.'], 403);
        }

        unset($request['csrf']);
        ankiJson(ankiRunBridge($request));
    } catch (Throwable $e) {
        ankiJson(
            ['ok' => false, 'message' => $e->getMessage()],
            500
        );
    }
}

$page_title = 'Anki';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="anki-page">
    <aside class="anki-sidebar">
        <div class="anki-sidebar-section">
            <label class="anki-deck-select-wrap" for="ankiDeckSelect">
                <span class="anki-label">Deck</span>
                <select id="ankiDeckSelect" class="anki-deck-select" disabled>
                    <option>Lade Anki …</option>
                </select>
            </label>
        </div>

        <div class="anki-sidebar-section">
            <div class="anki-label">Lernstand</div>

            <div class="anki-toolbar-stats" aria-label="Anki-Lernstand">
                <div class="anki-stat anki-stat-new">
                    <strong id="ankiCountNew">0</strong>
                    <span>Neu</span>
                </div>

                <div class="anki-stat anki-stat-learning">
                    <strong id="ankiCountLearning">0</strong>
                    <span>Lernen</span>
                </div>

                <div class="anki-stat anki-stat-review">
                    <strong id="ankiCountReview">0</strong>
                    <span>Wiederholen</span>
                </div>

                <div class="anki-stat anki-stat-total">
                    <strong id="ankiCountDue">0</strong>
                    <span>Fällig</span>
                </div>
            </div>
        </div>

        <div class="anki-sidebar-section anki-sidebar-sync">
            <button type="button" class="anki-sync-btn" id="ankiSyncBtn">
                APKG neu einlesen
            </button>

            <div class="anki-sync-info" id="ankiSyncInfo"></div>
        </div>

        <div class="anki-sidebar-hint">
            <strong>Tastatur</strong>
            <span>Leertaste: Antwort</span>
            <span>1–4: Nochmal / Schwer / Gut / Einfach</span>
        </div>
    </aside>

    <main class="anki-main">
        <section class="anki-card" id="ankiCard">
            <header class="anki-card-head">
                <div>
                    <div class="anki-card-deck" id="ankiCardDeck"></div>
                    <div class="anki-card-meta" id="ankiCardMeta"></div>
                </div>

                <div class="anki-card-tags" id="ankiCardTags"></div>
            </header>

            <div class="anki-review-actions">
                <button type="button" class="anki-show-answer" id="ankiRevealBtn">
                    Antwort zeigen
                </button>

                <div class="anki-rating-grid hidden" id="ankiRatingGrid">
                    <button type="button" class="anki-rating anki-rating-again" data-ease="1">
                        <span>Nochmal</span>
                        <small>–</small>
                    </button>

                    <button type="button" class="anki-rating anki-rating-hard" data-ease="2">
                        <span>Schwer</span>
                        <small>–</small>
                    </button>

                    <button type="button" class="anki-rating anki-rating-good" data-ease="3">
                        <span>Gut</span>
                        <small>–</small>
                    </button>

                    <button type="button" class="anki-rating anki-rating-easy" data-ease="4">
                        <span>Einfach</span>
                        <small>–</small>
                    </button>
                </div>
            </div>

            <div class="anki-card-frame-wrap" id="ankiCardScroll">
                <iframe
                    id="ankiCardFrame"
                    class="anki-card-frame"
                    sandbox="allow-scripts allow-same-origin"
                    referrerpolicy="no-referrer"
                    title="Anki-Karte"
                ></iframe>
            </div>
        </section>

        <section class="anki-empty hidden" id="ankiEmpty">
            <strong>Für dieses Deck ist aktuell nichts fällig.</strong>
            <span>Du kannst links ein anderes Deck auswählen oder später wiederkommen.</span>
        </section>

        <section class="anki-status hidden" id="ankiStatus"></section>
    </main>
</div>

<script>
window.FIJI_ANKI = {
    csrf: <?= json_encode($ankiCsrf, JSON_UNESCAPED_SLASHES) ?>,
    apiUrl: '/sci/anki'
};
</script>
<script defer src="/sci/Anki.js"></script>
