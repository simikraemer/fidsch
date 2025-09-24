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

    <!-- Preload Images -->
    <link rel="preload" href="/img/odal.png" as="image">
    <link rel="preload" href="/img/tool.png" as="image">
    <link rel="preload" href="/img/mac.png" as="image">
    <link rel="preload" href="/img/path.png" as="image">
    <link rel="preload" href="/img/fit.png" as="image">
    <link rel="preload" href="/img/biz.png" as="image">
    <link rel="preload" href="/img/graph.png" as="image">
    <link rel="preload" href="/img/table.png" as="image">
    <link rel="preload" href="/img/upload.png" as="image">
    <link rel="preload" href="/img/audiobook.png" as="image">
    <link rel="preload" href="/img/vinyl.png" as="image">
    <link rel="preload" href="/img/image.png" as="image">
    <link rel="preload" href="/img/tresor.png" as="image">
    <link rel="preload" href="/img/dots.png" as="image"> <!-- login button -->
</head>
<body>
    <nav class="navbar">
        <ul class="nav-links">
            <!-- Start -->
            <li class="nav-item">
                <a href="/"><img src="/img/odal.png" alt="Start" class="nav-icon"></a>
            </li>

            <!-- Tools (öffentlich) -->
            <li class="nav-item has-submenu">
                <a href="/tools/mac"><img src="/img/tool.png" alt="Tool" class="nav-icon"></a>
                <ul class="submenu">
                    <li><a href="/tools/mac"><img src="/img/mac.png" alt="MAC" class="nav-icon"><span class="submenu-text">MAC-Konverter</span></a></li>
                    <li><a href="/tools/path"><img src="/img/path.png" alt="Path" class="nav-icon"><span class="submenu-text">Path-Konverter</span></a></li>
                </ul>
            </li>
                
            <div class="nav-divider"></div>

            <?php if ($isAuthed): ?>

                <!-- Fit -->
                <li class="nav-item has-submenu">
                    <a href="/fit/start"><img src="/img/fit.png" alt="Fit" class="nav-icon"></a>
                    <ul class="submenu">
                        <li><a href="/fit/stats"><img src="/img/graph.png" alt="Übersicht" class="nav-icon"><span class="submenu-text">Übersicht</span></a></li>
                        <li><a href="/fit/kalorien"><img src="/img/burger.png" alt="Kalorien" class="nav-icon"><span class="submenu-text">Kalorien</span></a></li>
                        <li><a href="/fit/gewicht"><img src="/img/waage.png" alt="Gewicht" class="nav-icon"><span class="submenu-text">Gewicht</span></a></li>
                        <li><a href="/fit/training"><img src="/img/cardio.png" alt="Training" class="nav-icon"><span class="submenu-text">Training</span></a></li>
                        <li><a href="/fit/pizza"><img src="/img/pizza.png" alt="Pizza" class="nav-icon"><span class="submenu-text">Pizza-Rechner</span></a></li>
                    </ul>
                </li>

                <!-- Biz -->
                <li class="nav-item has-submenu">
                    <a href="/biz/start"><img src="/img/biz.png" alt="Biz" class="nav-icon"></a>
                    <ul class="submenu">
                        <li><a href="/biz/stats"><img src="/img/graph.png" alt="Übersicht" class="nav-icon"><span class="submenu-text">Übersicht</span></a></li>
                        <li><a href="/biz/data"><img src="/img/table.png" alt="Daten" class="nav-icon"><span class="submenu-text">Daten</span></a></li>
                        <li><a href="/biz/insert"><img src="/img/upload.png" alt="Hochladen" class="nav-icon"><span class="submenu-text">Hochladen</span></a></li>
                    </ul>
                </li>

                <div class="nav-divider"></div>

                <!-- Audibook -->
                <li class="nav-item">
                    <a href="https://fidsch.de/audiobookshelf/" target="_blank" rel="noopener">
                        <img src="/img/audiobook.png" alt="" class="nav-icon">
                    </a>
                </li>

                <!-- Navidrome -->
                <li class="nav-item">
                    <a href="https://fidsch.de/navidrome/" target="_blank" rel="noopener">
                        <img src="/img/vinyl.png" alt="" class="nav-icon">
                    </a>
                </li>

                <!-- Photoprism -->
                <li class="nav-item">
                    <a href="https://9.fidsch.de/photoprism/" target="_blank" rel="noopener">
                        <img src="/img/image.png" alt="" class="nav-icon">
                    </a>
                </li>

                <div class="nav-divider"></div>

                <!-- Vault -->
                <li class="nav-item">
                    <a href="https://vault.fidsch.de" target="_blank" rel="noopener">
                        <img src="/img/tresor.png" alt="" class="nav-icon">
                    </a>
                </li>
            <?php else: ?>
                <!-- Not authed: show clickable "dots" login button -->
                <li class="nav-item">
                    <a href="?login=1" title="Login">
                        <img src="/img/dots.png" alt="Login" class="nav-icon">
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</body>
</html>
