<?php
// fit/Pizza.php

// Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// Keine DB / kein POST nötig

// Rendering starten
$page_title = 'Pizza Kalorienrechner';
require_once __DIR__ . '/../head.php';    // <!DOCTYPE html> … <body>
require_once __DIR__ . '/../navbar.php';  // nur die Navbar
?>

<div class="container">
    <div class="form-block">
        <div class="input-row">
            <div class="input-group">
                <label for="pizza" class="zeitbereich-label">Pizzasorte</label>
                <select id="pizza">
                    <option value="margherita">Margherita</option>
                    <option value="salami">Salami</option>
                    <option value="funghi">Funghi</option>
                    <option value="hawaii">Hawaii</option>
                    <option value="quatroformaggi">Quattro Formaggi</option>
                </select>
            </div>
            <div class="input-group">
                <label for="durchmesser" class="zeitbereich-label">Durchmesser: <span id="durchmesser-wert">0 cm</span></label>
                <input type="range" id="durchmesser" class="zeitbereich-slider" min="0" max="100" value="26">
            </div>
        </div>
        <div id="kalorien" class="kalorien-output">0 kcal</div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pizzaSelect = document.getElementById('pizza');
    const durchmesserSlider = document.getElementById('durchmesser');
    const durchmesserLabel = document.getElementById('durchmesser-wert');
    const kalorienOutput = document.getElementById('kalorien');

    const basisKalorienProQuadratCm = {
        margherita: 2.0,
        salami: 2.5,
        funghi: 2.2,
        hawaii: 2.3,
        quatroformaggi: 2.8
    };

    function berechneKalorien() {
        const sorte = pizzaSelect.value;
        const durchmesser = parseFloat(durchmesserSlider.value);
        const radius = durchmesser / 2;
        const flaeche = Math.PI * radius * radius;
        const kalorien = Math.round(flaeche * (basisKalorienProQuadratCm[sorte] || 0));

        durchmesserLabel.textContent = durchmesser + ' cm';
        kalorienOutput.textContent = kalorien + ' kcal';
    }

    pizzaSelect.addEventListener('change', berechneKalorien);
    durchmesserSlider.addEventListener('input', berechneKalorien);
    berechneKalorien(); // initial
});
</script>

</body>
</html>
