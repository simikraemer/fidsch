<?php
// header.php
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>FIJI Web</title>
    <link rel="stylesheet" href="/FIJI.css">

    <!-- Preload Images -->
    <link rel="preload" href="/img/odal.png" as="image">
    <link rel="preload" href="/img/fit.png" as="image">
    <link rel="preload" href="/img/biz.png" as="image">
    <link rel="preload" href="/img/graph.png" as="image">
    <link rel="preload" href="/img/table.png" as="image">
    <link rel="preload" href="/img/upload.png" as="image">
</head>
<body>
    <nav class="navbar">
        <ul class="nav-links">
            <!-- Start -->
            <li class="nav-item">
                <a href="/"><img src="/img/odal.png" alt="Start" class="nav-icon"></a>
            </li>

            <div class="nav-divider"></div>

            <!-- Fit -->
            <li class="nav-item has-submenu">
                <a href="/fit/"><img src="/img/fit.png" alt="Fit" class="nav-icon"></a>
                <ul class="submenu">
                    <li><a href="/fit/stats"><img src="/img/graph.png" alt="Übersicht" class="nav-icon"><span class="submenu-text">Übersicht</span></a></li>
                    <li><a href="/fit/gewicht"><img src="/img/waage.png" alt="Gewicht" class="nav-icon"><span class="submenu-text">Gewicht</span></a></li>
                    <li><a href="/fit/kalorien"><img src="/img/burger.png" alt="Kalorien" class="nav-icon"><span class="submenu-text">Kalorien</span></a></li>
                    <li><a href="/fit/training"><img src="/img/cardio.png" alt="Training" class="nav-icon"><span class="submenu-text">Training</span></a></li>
                    <li><a href="/fit/pizza"><img src="/img/pizza.png" alt="Pizza" class="nav-icon"><span class="submenu-text">Pizza</span></a></li>
                </ul>
            </li>

            <div class="nav-divider"></div>

            <!-- Biz -->
            <li class="nav-item has-submenu">
                <a href="/biz/"><img src="/img/biz.png" alt="Biz" class="nav-icon"></a>
                <ul class="submenu">
                    <li><a href="/biz/stats"><img src="/img/graph.png" alt="Stats" class="nav-icon"><span class="submenu-text">Stats</span></a></li>
                    <li><a href="/biz/data"><img src="/img/table.png" alt="Data" class="nav-icon"><span class="submenu-text">Data</span></a></li>
                    <li><a href="/biz/insert"><img src="/img/upload.png" alt="Insert" class="nav-icon"><span class="submenu-text">Insert</span></a></li>
                </ul>
            </li>
        </ul>
    </nav>
</body>

</html>
