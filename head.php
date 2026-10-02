<?php
// head.php — öffnet das Dokument und liefert <head> inkl. CSS & Preloads
// Optional: $page_title vor dem Include setzen, sonst Fallback.
if (!isset($page_title) || $page_title === '') {
    $page_title = 'Fidsch';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- 0) Sofort beim Parsen: wenn schon einmal erfolgreich geladen, Navbar nicht mehr verstecken -->
    <script>
        (function () {
            try {
                if (localStorage.getItem('iconsReady') === '1') {
                    document.documentElement.classList.add('icons-ready');
                }
            } catch (e) {}
        })();
    </script>

    <?php
    $fullHdCssFile = $_SERVER['DOCUMENT_ROOT'] . '/FIJI_FullHD.css';
    $mobileCssFile = $_SERVER['DOCUMENT_ROOT'] . '/FIJI_Mobile.css';

    $fullHdCssVersion = is_file($fullHdCssFile) ? filemtime($fullHdCssFile) : time();
    $mobileCssVersion = is_file($mobileCssFile) ? filemtime($mobileCssFile) : time();
    ?>

    <link rel="stylesheet" href="/FIJI_FullHD.css?v=<?= $fullHdCssVersion ?>">
    <link rel="stylesheet" href="/FIJI_Mobile.css?v=<?= $mobileCssVersion ?>">

    <!-- 1) Standard: Navbar nur verstecken, bis wir einmalig confirmed haben -->
    <style>
        html:not(.icons-ready) .navbar { visibility: hidden; }
        html.icons-ready .navbar { visibility: visible; }
    </style>

    <!-- 2) Beim ersten erfolgreichen Icon-Load merken wir uns das persistent -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const SAFETY_TIMEOUT_MS = 2000;
            const nav = document.querySelector('.navbar');

            function whenImgReady(img) {
                try {
                    img.decoding = 'sync';
                    img.loading = 'eager';
                    img.fetchPriority = 'high';
                } catch (e) {}
                return new Promise((resolve) => {
                    if (img.complete && img.naturalWidth > 0) return resolve();
                    img.addEventListener('load', resolve, { once: true });
                    img.addEventListener('error', resolve, { once: true }); // fail-open
                });
            }

            function revealAndPersist() {
                document.documentElement.classList.add('icons-ready');
                try { localStorage.setItem('iconsReady', '1'); } catch (e) {}
            }

            if (!nav) { revealAndPersist(); return; }

            const imgs = Array.from(nav.querySelectorAll('img'));
            const allImgsReady = Promise.all(imgs.map(whenImgReady));
            const safety = new Promise((res) => setTimeout(res, SAFETY_TIMEOUT_MS));

            Promise.race([allImgsReady, safety]).then(revealAndPersist);
        });
    </script>

    <!-- Preload aller aktuell in navbar.php verwendeten Icons -->
    <link rel="preload" href="/img/odal.png" as="image">
    <link rel="preload" href="/img/diet.png" as="image">
    <link rel="preload" href="/img/graph.png" as="image">
    <link rel="preload" href="/img/burger.png" as="image">
    <link rel="preload" href="/img/hantel.png" as="image">
    <link rel="preload" href="/img/table.png" as="image">
    <link rel="preload" href="/img/waage.png" as="image">
    <link rel="preload" href="/img/db.png" as="image">
    <link rel="preload" href="/img/pizza.png" as="image">
    <link rel="preload" href="/img/biz.png" as="image">
    <link rel="preload" href="/img/upload.png" as="image">
    <link rel="preload" href="/img/bank.png" as="image">
    <link rel="preload" href="/img/plan.png" as="image">
    <link rel="preload" href="/img/uni.png" as="image">
    <link rel="preload" href="/img/kartei.png" as="image">
    <link rel="preload" href="/img/anki.png" as="image">
    <link rel="preload" href="/img/glocke.png" as="image">
    <link rel="preload" href="/img/todo.png" as="image">
    <link rel="preload" href="/img/spotify.png" as="image">
    <link rel="preload" href="/img/phan.png" as="image">
    <link rel="preload" href="/img/blog.png" as="image">
    <link rel="preload" href="/img/char.png" as="image">
    <link rel="preload" href="/img/region.png" as="image">
    <link rel="preload" href="/img/relation.png" as="image">
    <link rel="preload" href="/img/factions.png" as="image">
    <link rel="preload" href="/img/vinyl.png" as="image">
    <link rel="preload" href="/img/settings.png" as="image">
    <link rel="preload" href="/img/log.png" as="image">
    <link rel="preload" href="/img/merge.png" as="image">
    <link rel="preload" href="/img/timer.png" as="image">
    <link rel="preload" href="/img/mac.png" as="image">
    <link rel="preload" href="/img/path.png" as="image">
    <link rel="preload" href="/img/bit.png" as="image">
    <link rel="preload" href="/img/hourglass.png" as="image">
    <link rel="preload" href="/img/network.png" as="image">
    <link rel="preload" href="/img/link.png" as="image">
    <link rel="preload" href="/img/audiobook.png" as="image">
    <link rel="preload" href="/img/image.png" as="image">
    <link rel="preload" href="/img/tresor.png" as="image">
    <link rel="preload" href="/img/dots.png" as="image">
</head>
<body>
