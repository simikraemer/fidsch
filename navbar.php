<?php
// header.php  (nur Session/Auth + Menü; KEIN Doctype/Head/Body)

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Basic-Auth nur beim Klick auf die "dots"
if (isset($_GET['login']) && $_GET['login'] === '1') {
    require_once __DIR__ . '/auth.php'; // triggert 401-Challenge
    $_SESSION['is_authed'] = true;
    // sauberer Redirect (303 nach POST/GET)
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    header('Location: ' . $uri, true, 303);
    exit;
}

$isAuthed = !empty($_SESSION['is_authed']);
?>
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
                <li><a href="/tools/bit"><img src="/img/bit.png" alt="Bit" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Bit-Konverter</span></a></li>
                <li><a href="/tools/unixtime"><img src="/img/hourglass.png" alt="Unixtime" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">Unixtime-Konverter</span></a></li>
                <li><a href="/tools/ips"><img src="/img/network.png" alt="IPv4" class="nav-icon" loading="eager" decoding="sync" fetchpriority="high"><span class="submenu-text">IPv4-Konverter</span></a></li>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
  const navbar = document.querySelector('.navbar');
  const items = document.querySelectorAll('.nav-item.has-submenu');
  let r1, r2;

  const pulseInstantClose = () => {
    if (!navbar) return;
    navbar.classList.add('submenu-switch');
    cancelAnimationFrame(r1); cancelAnimationFrame(r2);
    r1 = requestAnimationFrame(() => {
      r2 = requestAnimationFrame(() => {
        navbar.classList.remove('submenu-switch');
      });
    });
  };

  items.forEach(item => {
    item.addEventListener('mouseenter', pulseInstantClose);
    item.addEventListener('touchstart', pulseInstantClose, { passive: true });
  });
});
</script>