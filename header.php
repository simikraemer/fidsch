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
        <li><a href="start"><img loading="eager" src="img/graph.png" alt="Übersicht" class="nav-icon"></a></li>
        <li class="nav-divider"></li>
        <li><a href="gewicht"><img loading="eager" src="img/waage.png" alt="Gewicht" class="nav-icon"></a></li>
        <li><a href="kalorien"><img loading="eager" src="img/burger.png" alt="Kalorien" class="nav-icon"></a></li>
        <li><a href="training"><img loading="eager" src="img/cardio.png" alt="Training" class="nav-icon"></a></li>
        <li class="nav-divider"></li>
        <li><a href="pizza"><img loading="eager" src="img/pizza.png" alt="Pizza" class="nav-icon"></a></li>
    </ul>
</nav>
