<?php
// header.php
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fitness Tracker</title>
    <link rel="stylesheet" href="FIT.css">
    <link rel="icon" type="image/x-icon" href="favicon.ico">

    <!-- Preload Icons -->
    <link rel="preload" href="img/waage.png" as="image">
    <link rel="preload" href="img/burger.png" as="image">
    <link rel="preload" href="img/cardio.png" as="image">
    <link rel="preload" href="img/graph.png" as="image">
</head>
<body>

<nav class="navbar">
    <ul class="nav-links">
        <li><a href="Start.php"><img loading="eager" src="img/graph.png" alt="Übersicht" class="nav-icon"></a></li>
        <li class="nav-divider"></li>
        <li><a href="Gewicht_New.php"><img loading="eager" src="img/waage.png" alt="Gewicht" class="nav-icon"></a></li>
        <li><a href="Kalorien_New.php"><img loading="eager" src="img/burger.png" alt="Kalorien" class="nav-icon"></a></li>
        <li><a href="Training_New.php"><img loading="eager" src="img/cardio.png" alt="Training" class="nav-icon"></a></li>
    </ul>
</nav>
