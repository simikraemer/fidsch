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
                    <option value="diavolo">Diavolo</option>
                    <option value="tonno">Tonno</option>
                    <option value="prosciutto">Prosciutto</option>
                    <option value="vegetarisch">Vegetarisch</option>
                    <option value="bbqchicken">BBQ Chicken</option>
                </select>
            </div>
            <div class="input-group">
                <label for="durchmesser" class="zeitbereich-label">
                    Durchmesser: <span id="durchmesser-wert">0 cm</span>
                </label>
                <input type="range" id="durchmesser" class="zeitbereich-slider" min="0" max="100" value="26">
            </div>
        </div>

        <div class="kalorien-output">
            <table class="food-table" id="naehrwerte-tabelle">
                <thead>
                <tr>
                    <th>Nährwert</th>
                    <th>Menge</th>
                    <th>kcal</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Gesamt</td>
                    <td></td>
                    <td><span id="gesamt-kcal">0</span></td>
                </tr>
                <tr>
                    <td>Eiweiß</td>
                    <td><span id="eiweiss-gramm">0</span> g</td>
                    <td><span id="eiweiss-kcal">0</span></td>
                </tr>
                <tr>
                    <td>Fett</td>
                    <td><span id="fett-gramm">0</span> g</td>
                    <td><span id="fett-kcal">0</span></td>
                </tr>
                <tr>
                    <td>Kohlenhydrate</td>
                    <td><span id="carbs-gramm">0</span> g</td>
                    <td><span id="carbs-kcal">0</span></td>
                </tr>
                </tbody>
            </table>

            <form method="post" action="/fit/kalorien" id="pizza-kalorien-form" style="margin-top: 15px;">
                <input type="hidden" name="beschreibung"   id="beschreibung-hidden">
                <input type="hidden" name="kalorien"       id="kalorien-hidden">
                <input type="hidden" name="eiweiss"        id="eiweiss-hidden">
                <input type="hidden" name="fett"           id="fett-hidden">
                <input type="hidden" name="kohlenhydrate"  id="carbs-hidden">
                <input type="hidden" name="alkohol"        id="alkohol-hidden" value="0">
                <input type="hidden" name="anzahl"         id="anzahl-hidden" value="1">
                <button type="submit">In Kalorien eintragen</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const pizzaSelect        = document.getElementById('pizza');
    const durchmesserSlider  = document.getElementById('durchmesser');
    const durchmesserLabel   = document.getElementById('durchmesser-wert');

    const gesamtKcalSpan     = document.getElementById('gesamt-kcal');
    const eiweissGrammSpan   = document.getElementById('eiweiss-gramm');
    const eiweissKcalSpan    = document.getElementById('eiweiss-kcal');
    const fettGrammSpan      = document.getElementById('fett-gramm');
    const fettKcalSpan       = document.getElementById('fett-kcal');
    const carbsGrammSpan     = document.getElementById('carbs-gramm');
    const carbsKcalSpan      = document.getElementById('carbs-kcal');

    const beschreibungHidden = document.getElementById('beschreibung-hidden');
    const kalorienHidden     = document.getElementById('kalorien-hidden');
    const eiweissHidden      = document.getElementById('eiweiss-hidden');
    const fettHidden         = document.getElementById('fett-hidden');
    const carbsHidden        = document.getElementById('carbs-hidden');
    const alkoholHidden      = document.getElementById('alkohol-hidden');
    const anzahlHidden       = document.getElementById('anzahl-hidden');

    // kcal pro cm²
    const basisKalorienProQuadratCm = {
        margherita: 2.0,
        salami: 2.5,
        funghi: 2.2,
        hawaii: 2.3,
        quatroformaggi: 2.8,
        diavolo: 2.6,
        tonno: 2.4,
        prosciutto: 2.4,
        vegetarisch: 2.1,
        bbqchicken: 2.7
    };

    // Makroverteilung pro Sorte (Anteil der kcal)
    const macroVerteilung = {
        margherita:     { protein: 0.14, fat: 0.32, carbs: 0.54 },
        salami:         { protein: 0.16, fat: 0.44, carbs: 0.40 },
        funghi:         { protein: 0.16, fat: 0.34, carbs: 0.50 },
        hawaii:         { protein: 0.15, fat: 0.32, carbs: 0.53 },
        quatroformaggi: { protein: 0.18, fat: 0.48, carbs: 0.34 },
        diavolo:        { protein: 0.18, fat: 0.44, carbs: 0.38 },
        tonno:          { protein: 0.22, fat: 0.34, carbs: 0.44 },
        prosciutto:     { protein: 0.18, fat: 0.36, carbs: 0.46 },
        vegetarisch:    { protein: 0.15, fat: 0.30, carbs: 0.55 },
        bbqchicken:     { protein: 0.20, fat: 0.40, carbs: 0.40 }
    };

    function berechneKalorien() {
        const sorte = pizzaSelect.value;
        const durchmesser = parseFloat(durchmesserSlider.value);
        const radius = durchmesser / 2;
        const flaeche = Math.PI * radius * radius;

        const kcalProCm2 = basisKalorienProQuadratCm[sorte] || 0;
        const kalorien = Math.round(flaeche * kcalProCm2);

        const verteilung = macroVerteilung[sorte] || { protein: 0.18, fat: 0.38, carbs: 0.44 };

        // 1g Protein/Carbs = 4 kcal, 1g Fett = 9 kcal
        const eiweissGramm = Math.round(kalorien * verteilung.protein / 4);
        const fettGramm    = Math.round(kalorien * verteilung.fat    / 9);
        const carbsGramm   = Math.round(kalorien * verteilung.carbs  / 4);

        const eiweissKcal  = Math.round(eiweissGramm * 4);
        const fettKcal     = Math.round(fettGramm * 9);
        const carbsKcal    = Math.round(carbsGramm * 4);

        durchmesserLabel.textContent = durchmesser + ' cm';

        // Tabelle updaten
        gesamtKcalSpan.textContent   = kalorien;
        eiweissGrammSpan.textContent = eiweissGramm;
        eiweissKcalSpan.textContent  = eiweissKcal;
        fettGrammSpan.textContent    = fettGramm;
        fettKcalSpan.textContent     = fettKcal;
        carbsGrammSpan.textContent   = carbsGramm;
        carbsKcalSpan.textContent    = carbsKcal;

        // Hidden-Felder für /fit/kalorien-Insert befüllen
        const selectedText = pizzaSelect.options[pizzaSelect.selectedIndex].text;
        beschreibungHidden.value = 'Pizza ' + selectedText + ' (' + durchmesser + ' cm)';
        kalorienHidden.value     = kalorien;
        eiweissHidden.value      = eiweissGramm;
        fettHidden.value         = fettGramm;
        carbsHidden.value        = carbsGramm;
        alkoholHidden.value      = 0;
        anzahlHidden.value       = 1;
    }

    pizzaSelect.addEventListener('change', berechneKalorien);
    durchmesserSlider.addEventListener('input', berechneKalorien);
    berechneKalorien(); // initial
});
</script>

</body>
</html>
