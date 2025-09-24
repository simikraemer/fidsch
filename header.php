<?php
// header.php
session_start();

// Trigger Basic-Auth only when user clicks the "dots" button.
// After successful auth.php, mark session as authed and redirect to clean URL.
if (isset($_GET['login']) && $_GET['login'] === '1') {
    require_once __DIR__ . '/auth.php'; // will 401-challenge if needed
    $_SESSION['is_authed'] = true;

    // redirect back to same path without ?login=1
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    header("Location: {$uri}");
    exit;
}

$isAuthed = !empty($_SESSION['is_authed']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fidsch</title>
    <link rel="stylesheet" href="/FIJI.css">

    <!-- Harder-than-preload: gate navbar visibility until icons are loaded -->
    <style>
        /* Hide navbar until icons are ready (prevents flicker) */
        html:not(.icons-ready) .navbar { visibility: hidden; }
        html.icons-ready .navbar { visibility: visible; }
    </style>
    <script>
        // As early as possible: wait for all navbar <img> to be loaded (or timeout) before revealing
        (function () {
            // Reveal after all images loaded OR after a short safety timeout (e.g. 2s) to avoid lock-ups.
            const SAFETY_TIMEOUT_MS = 2000;

            function whenImgReady(img) {
                // Encourage sync decoding/eager loading for nav icons
                try {
                    img.decoding = "sync";
                    img.loading = "eager";
                    img.fetchPriority = "high";
                } catch (e) {}
                return new Promise((resolve) => {
                    if (img.complete && img.naturalWidth > 0) return resolve();
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true }); // fail-open on error
                });
            }

            function reveal() {
                document.documentElement.classList.add('icons-ready');
            }

            // Run ASAP (before DOMContentLoaded is fine since <nav> is in the HTML)
            const nav = document.querySelector('.navbar');
            if (!nav) { reveal(); return; }

            const imgs = Array.from(nav.querySelectorAll('img'));
            const allImgsReady = Promise.all(imgs.map(whenImgReady));
            const safety = new Promise((res) => setTimeout(res, SAFETY_TIMEOUT_MS));

            Promise.race([allImgsReady, safety]).then(reveal);
        })();
    </script>

    <!-- Preload Images (complete set incl. submenu icons) -->
    <link rel="preload" href="/img/odal.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/tool.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/mac.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/path.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/fit.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/biz.png" as="image" fetchpriority="high">
    <link rel="preload" href="/img/graph.png" as="image">
    <link rel="preload" href="/img/table.png" as="image">
    <link rel="preload" href="/img/upload.png" as="image">
    <link rel="preload" href="/img/audiobook.png" as="image">
    <link rel="preload" href="/img/vinyl.png" as="image">
    <link rel="preload" href="/img/image.png" as="image">
    <link rel="preload" href="/img/tresor.png" as="image">
    <link rel="preload" href="/img/dots.png" as="image">
    <link rel="preload" href="/img/burger.png" as="image">
    <link rel="preload" href="/img/waage.png" as="image">
    <link rel="preload" href="/img/cardio.png" as="image">
    <link rel="preload" href="/img/pizza.png" as="image">
</head>
<body>
    <nav class="navbar">
        <ul class="nav-links">
            <!-- Start -->
            <li class="nav-item">
                <a href="/"><img src="/img/odal.png" alt="Start" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"></a>
            </li>

            <!-- Tools (öffentlich) -->
            <li class="nav-item has-submenu">
                <a href="/tools/mac"><img src="/img/tool.png" alt="Tool" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"></a>
                <ul class="submenu">
                    <li><a href="/tools/mac"><img src="/img/mac.png" alt="MAC" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">MAC-Konverter</span></a></li>
                    <li><a href="/tools/path"><img src="/img/path.png" alt="Path" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Path-Konverter</span></a></li>
                    <li><a href="/tools/bit"><img src="/img/bit.png" alt="Path" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Bit-Konverter</span></a></li>
                    <li><a href="/tools/unixtime"><img src="/img/hourglass.png" alt="Path" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Unixtime-Konverter</span></a></li>
                    <li><a href="/tools/ips"><img src="/img/network.png" alt="Path" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">IPv4-Konverter</span></a></li>
                </ul>
            </li>

            <div class="nav-divider"></div>

            <?php if ($isAuthed): ?>

                <!-- Fit -->
                <li class="nav-item has-submenu">
                    <a href="/fit/start"><img src="/img/fit.png" alt="Fit" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"></a>
                    <ul class="submenu">
                        <li><a href="/fit/stats"><img src="/img/graph.png" alt="Übersicht" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Übersicht</span></a></li>
                        <li><a href="/fit/kalorien"><img src="/img/burger.png" alt="Kalorien" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Kalorien</span></a></li>
                        <li><a href="/fit/gewicht"><img src="/img/waage.png" alt="Gewicht" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Gewicht</span></a></li>
                        <li><a href="/fit/training"><img src="/img/cardio.png" alt="Training" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Training</span></a></li>
                        <li><a href="/fit/pizza"><img src="/img/pizza.png" alt="Pizza" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Pizza-Rechner</span></a></li>
                    </ul>
                </li>

                <!-- Biz -->
                <li class="nav-item has-submenu">
                    <a href="/biz/start"><img src="/img/biz.png" alt="Biz" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"></a>
                    <ul class="submenu">
                        <li><a href="/biz/stats"><img src="/img/graph.png" alt="Übersicht" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Übersicht</span></a></li>
                        <li><a href="/biz/data"><img src="/img/table.png" alt="Daten" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Daten</span></a></li>
                        <li><a href="/biz/insert"><img src="/img/upload.png" alt="Hochladen" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Hochladen</span></a></li>
                    </ul>
                </li>

                <div class="nav-divider"></div>

                <!-- Audibook -->
                <li class="nav-item">
                    <a href="https://fidsch.de/audiobookshelf/" target="_blank" rel="noopener">
                        <img src="/img/audiobook.png" alt="" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high">
                    </a>
                </li>

                <!-- Navidrome -->
                <li class="nav-item">
                    <a href="https://fidsch.de/navidrome/" target="_blank" rel="noopener">
                        <img src="/img/vinyl.png" alt="" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high">
                    </a>
                </li>

                <!-- Photoprism -->
                <li class="nav-item">
                    <a href="https://9.fidsch.de/photoprism/" target="_blank" rel="noopener">
                        <img src="/img/image.png" alt="" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high">
                    </a>
                </li>

                <div class="nav-divider"></div>

                <!-- Vault -->
                <li class="nav-item">
                    <a href="https://vault.fidsch.de" target="_blank" rel="noopener">
                        <img src="/img/tresor.png" alt="" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high">
                    </a>
                </li>
            <?php else: ?>
                <!-- Not authed: show clickable "dots" login button -->
                <li class="nav-item">
                    <a href="?login=1" title="Login">
                        <img src="/img/dots.png" alt="Login" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high">
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</body>
</html>
