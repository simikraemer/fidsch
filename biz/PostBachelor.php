<?php
// biz/PostBachelor_v6.php
// Kompakte Budgetplanung für eine spätere TV-L-Stelle an der RWTH.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$bizconn->set_charset('utf8mb4');

function pbEuro(float $value): string
{
    return number_format($value, 2, ',', '.') . ' €';
}

/* =========================================================
 * Referenzzeitraum: letzte 6 abgeschlossene Kalendermonate
 * ========================================================= */
$periodEnd = new DateTimeImmutable('first day of this month 00:00:00');
$periodStart = $periodEnd->modify('-6 months');

$periodStartSql = $periodStart->format('Y-m-d H:i:s');
$periodEndSql = $periodEnd->format('Y-m-d H:i:s');

/* =========================================================
 * Sonstige Ausgaben
 *
 * Semantik wie im Finanzdashboard:
 * - je Kategorie zunächst ALLE Buchungen im Zeitraum saldieren
 * - Miete und TK ausschließen
 * - nur Kategorien mit negativer Nettosumme als Ausgaben werten
 * - anschließend auf 6 Monate mitteln
 * ========================================================= */
$otherSixMonthTotal = 0.0;
$stmtOther = $bizconn->prepare("
    SELECT
        COALESCE(k.name, 'Unkategorisiert') AS category_name,
        SUM(t.betrag) AS category_sum
    FROM transfers t
    LEFT JOIN kategorien k ON k.id = t.kategorie_id
    WHERE t.valutadatum IS NOT NULL
      AND t.valutadatum >= ?
      AND t.valutadatum < ?
      AND (k.name IS NULL OR k.name NOT IN ('Miete', 'TK'))
    GROUP BY COALESCE(k.name, 'Unkategorisiert')
    HAVING SUM(t.betrag) < 0
");

if ($stmtOther !== false) {
    $stmtOther->bind_param('ss', $periodStartSql, $periodEndSql);
    $stmtOther->execute();
    $resOther = $stmtOther->get_result();

    while ($row = $resOther->fetch_assoc()) {
        $otherSixMonthTotal += abs((float)$row['category_sum']);
    }

    $stmtOther->close();
}

$otherMonthlyAverage = $otherSixMonthTotal / 6.0;

/* =========================================================
 * TV-L Entgelttabelle ab 01.03.2027
 * Anlage B, allgemeiner Teil
 * ========================================================= */
$tvlGrossTable = [
    'E6' => [
        1 => 3250.30,
        2 => 3486.44,
        3 => 3618.14,
        4 => 3752.78,
        5 => 3843.51,
        6 => 3941.24,
    ],
    'E7' => [
        1 => 3300.55,
        2 => 3539.11,
        3 => 3718.60,
        4 => 3857.49,
        5 => 3969.19,
        6 => 4066.90,
    ],
    'E8' => [
        1 => 3487.91,
        2 => 3732.20,
        3 => 3871.43,
        4 => 4004.09,
        5 => 4150.70,
        6 => 4241.44,
    ],
    'E9a' => [
        1 => 3692.50,
        2 => 3948.23,
        3 => 4004.09,
        4 => 4115.77,
        5 => 4578.77,
        6 => 4708.08,
    ],
    'E9b' => [
        1 => 3692.50,
        2 => 3948.23,
        3 => 4115.77,
        4 => 4578.77,
        5 => 4972.60,
        6 => 5115.17,
    ],
    'E10' => [
        1 => 4119.19,
        2 => 4385.95,
        3 => 4691.40,
        4 => 5002.99,
        5 => 5595.85,
        6 => 5757.08,
    ],
    'E11' => [
        1 => 4261.92,
        2 => 4533.76,
        3 => 4843.40,
        4 => 5314.62,
        5 => 5998.64,
        6 => 6171.97,
    ],
    'E12' => [
        1 => 4397.12,
        2 => 4691.40,
        3 => 5314.62,
        4 => 5861.84,
        5 => 6568.65,
        6 => 6759.07,
    ],
];

$tvlPresets = [];
foreach ($tvlGrossTable as $group => $stages) {
    foreach ($stages as $stage => $gross) {
        $key = strtolower($group) . '_s' . $stage;
        $tvlPresets[$key] = [
            'group' => $group,
            'stage' => $stage,
            'gross' => $gross,
            'label' => 'TV-L ' . $group . ' S' . $stage,
        ];
    }
}

/* =========================================================
 * Abgabenmodell
 *
 * Für die Lohnsteuer wird clientseitig der centgenaue BMF-PAP 2026
 * verwendet. Ein amtlicher PAP 2027 liegt derzeit noch nicht vor.
 * Die Sozialversicherungswerte entsprechen deshalb ebenfalls dem
 * amtlichen Stand 2026. Sobald der PAP/Rechengrößen 2027 endgültig
 * veröffentlicht sind, können die Konstanten zentral aktualisiert werden.
 * ========================================================= */
$payrollParameters = [
    'tax_year' => 2026,
    'tax_class' => 1,
    'church_tax' => false,
    'childless_care_surcharge' => true,
    'saxony' => false,

    // TK 2026: 14,6 % allgemein + 2,69 % Zusatzbeitrag, jeweils paritätisch.
    'health_general_employee_rate' => 0.073,
    'health_additional_rate_total' => 0.0269,

    // Sozialversicherung 2026 Arbeitnehmeranteile.
    'pension_employee_rate' => 0.093,
    'unemployment_employee_rate' => 0.013,
    'care_employee_base_rate' => 0.018,
    'care_childless_surcharge_rate' => 0.006,

    // Beitragsbemessungsgrenzen 2026 / Monat.
    'bbg_health_care_monthly' => 5812.50,
    'bbg_pension_unemployment_monthly' => 8450.00,

    // VBLklassik Abrechnungsverband West: Arbeitnehmeranteil 1,81 %.
    'vbl_employee_rate' => 0.0181,

    // Übergangsbereich/Midijob 2026.
    'midijob_min' => 603.01,
    'midijob_max' => 2000.00,
    'midijob_factor_f' => 0.6619,
    'minijob_limit' => 603.00,
];

/* =========================================================
 * Pendelmodell
 * - Distanz ist die einfache Strecke Wohnung -> Arbeitsplatz.
 * - Monatsfaktor 52/12 bildet die durchschnittlichen Wochen je Monat ab.
 * - Standarddistanz Herzogenrath -> Aachen: rund 11,9 km Straße, gerundet 12 km.
 * - VW Polo Benziner als pragmatische Basis: 5,5 l/100 km.
 * - Super E10 als konservativer Planwert: 2,15 €/l.
 * ========================================================= */
$commutingParameters = [
    'default_distance_km' => 12.0,
    'default_days_per_week' => 2.5,
    'weeks_per_month' => 52.0 / 12.0,
    'polo_consumption_l_per_100km' => 5.5,
    'fuel_price_eur_per_l' => 2.15,
    'park_per_month' => 12.50,
];

$groupDescriptions = [
    'E6' => 'IT-Fachkräfte mit einschlägiger Berufsausbildung bzw. gleichwertigen Fähigkeiten; gründliche und vielseitige Fachkenntnisse.',
    'E7' => 'Tätigkeiten auf E6-Niveau, die ohne Anleitung ausgeführt werden.',
    'E8' => 'E7-Tätigkeiten mit Gestaltungsspielraum über typische Standardfälle hinaus.',
    'E9a' => 'E8-Tätigkeiten, für die zusätzliche Fachkenntnisse erforderlich sind.',
    'E9b' => 'E9a-Tätigkeiten mit umfassenden Fachkenntnissen in größerer Tiefe und Breite.',
    'E10' => 'Typisch bei einschlägigem Hochschulabschluss/Bachelor und entsprechender IT-Tätigkeit oder deutlich erweitertem Gestaltungsspielraum.',
    'E11' => 'E10-Tätigkeiten mit besonderen Leistungen, z. B. besonderen Fachkenntnissen, besonderer praktischer Erfahrung oder fachlicher Weisungsbefugnis.',
    'E12' => 'E11-Tätigkeiten mit mindestens drei Jahren Praxis und besonderer Schwierigkeit/Bedeutung bzw. Spezialaufgaben; teils auch qualifizierte IT-Gruppenleitung.',
];

$stageDescriptions = [
    1 => 'Einstieg ohne anrechenbare einschlägige Berufserfahrung.',
    2 => 'Regulär nach 1 Jahr in S1; bei Neueinstellung häufig auch mit mindestens 1 Jahr einschlägiger Erfahrung.',
    3 => 'Regulär nach 2 Jahren in S2; bei Neueinstellung kann mindestens 3-jährige einschlägige Erfahrung zu S3 führen.',
    4 => 'Regulär nach 3 Jahren in S3.',
    5 => 'Regulär nach 4 Jahren in S4.',
    6 => 'Regulär nach 5 Jahren in S5; Endstufe der hier betrachteten Entgeltgruppen.',
];

$page_title = 'Post-Bachelor Budget';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
.postbachelor-page {
    max-width: 1180px;
    margin-top: 24px;
}

.postbachelor-page .ueberschrift {
    text-align: left;
    margin: 0;
}

.pb-topbar {
    align-items: center;
    margin-bottom: 16px;
}

.pb-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.pb-card {
    min-width: 0;
    padding: 16px 18px;
    border-radius: var(--border-radius);
    background: #fff;
    box-shadow: var(--shadow);
}

.pb-card h2 {
    margin: 0 0 12px;
    font-size: 1rem;
}

.pb-card label {
    display: block;
    margin-bottom: 5px;
    font-size: .82rem;
    font-weight: 800;
}

.pb-card input,
.pb-card select {
    margin: 0;
}

.pb-inline-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.pb-inline-grid > div {
    min-width: 0;
}

.pb-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}

.pb-card-head h2 {
    margin: 0;
}

.pb-park-check {
    display: inline-flex !important;
    align-items: center;
    gap: 7px;
    margin: 0 !important;
    font-size: .82rem !important;
    font-weight: 800 !important;
    cursor: pointer;
}

.pb-park-check input {
    width: auto;
    margin: 0;
}

.pb-table-section {
    margin-bottom: 16px;
}

.pb-table-section h2 {
    margin: 0 0 10px;
    font-size: 1.05rem;
}

.pb-table-wrap {
    overflow: hidden;
    border-radius: var(--border-radius);
    background: #fff;
    box-shadow: var(--shadow);
}

.pb-table {
    width: 100%;
    border-collapse: collapse;
    font-variant-numeric: tabular-nums;
}

.pb-table th,
.pb-table td {
    padding: 10px 14px;
    border-bottom: 1px solid rgba(0, 0, 0, .07);
    text-align: left;
    vertical-align: middle;
}

.pb-table th {
    background: rgba(0, 0, 0, .035);
    font-size: .82rem;
}

.pb-table tr:last-child td {
    border-bottom: 0;
}

.pb-table .pb-money {
    text-align: right;
    white-space: nowrap;
}

.pb-table .pb-sign {
    width: 34px;
    text-align: center;
    color: rgba(34, 34, 34, .5);
    font-weight: 900;
}

.pb-table .pb-subrow td:first-child {
    padding-left: 30px;
    color: rgba(34, 34, 34, .72);
}

.pb-table .pb-net-row td {
    border-top: 2px solid rgba(0, 0, 0, .12);
    font-weight: 800;
}

.pb-table .pb-result-row td {
    border-top: 2px solid rgba(0, 0, 0, .16);
    background: var(--bg-light);
    font-weight: 900;
}

.pb-table .pb-result-value {
    font-size: 1.18rem;
}

.pb-result-positive {
    color: #237a3b;
}

.pb-result-negative {
    color: #b42318;
}

.pb-details {
    margin-top: 16px;
    border-radius: var(--border-radius);
    background: #fff;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.pb-details > summary {
    cursor: pointer;
    padding: 13px 16px;
    font-weight: 850;
    user-select: none;
}

.pb-details[open] > summary {
    border-bottom: 1px solid rgba(0, 0, 0, .08);
}

.pb-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    padding: 16px;
}

.pb-info-block h3 {
    margin: 0 0 9px;
    font-size: .98rem;
}

.pb-info-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .86rem;
}

.pb-info-table th,
.pb-info-table td {
    padding: 8px 9px;
    border-bottom: 1px solid rgba(0, 0, 0, .07);
    text-align: left;
    vertical-align: top;
}

.pb-info-table th {
    background: rgba(0, 0, 0, .035);
}

.pb-info-table td:first-child {
    width: 62px;
    white-space: nowrap;
    font-weight: 850;
}

.pb-tax-error {
    display: none;
    margin: 0 0 16px;
    padding: 10px 12px;
    border-left: 5px solid #b42318;
    border-radius: var(--border-radius);
    background: #fff;
    box-shadow: var(--shadow);
    color: #b42318;
    font-weight: 750;
}

.pb-tax-error.is-visible {
    display: block;
}

@media (max-width: 1050px) {
    .pb-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {
    .pb-info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .postbachelor-page {
        padding-left: 10px;
        padding-right: 10px;
    }

    .pb-grid {
        grid-template-columns: 1fr;
    }

    .pb-table-wrap {
        overflow-x: auto;
    }

    .pb-table {
        min-width: 680px;
    }
}
</style>

<div class="lt-page postbachelor-page" id="postBachelorPage">
    <div class="lt-topbar pb-topbar">
        <h1 class="ueberschrift">Post-Bachelor Budget</h1>
    </div>

    <div class="pb-grid">
        <section class="pb-card">
            <h2>TV-L Einkommen</h2>

            <label for="salaryPreset">Entgeltgruppe / Stufe</label>
            <select id="salaryPreset" class="kategorie-select">
                <?php foreach ($tvlGrossTable as $group => $stages): ?>
                    <optgroup label="TV-L <?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($stages as $stage => $gross): ?>
                            <?php $key = strtolower($group) . '_s' . $stage; ?>
                            <option
                                value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $key === 'e9b_s1' ? 'selected' : '' ?>
                            >TV-L <?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?> S<?= (int)$stage ?> (<?= htmlspecialchars(number_format((float)$gross, 0, ',', ''), ENT_QUOTES, 'UTF-8') ?>€)</option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="pb-card">
            <h2>Teilzeit</h2>

            <label for="ftePercent">Beschäftigungsumfang</label>
            <select id="ftePercent" class="kategorie-select">
                <?php foreach ([50, 60, 70, 80, 90, 100] as $fte): ?>
                    <option value="<?= (int)$fte ?>" <?= $fte === 100 ? 'selected' : '' ?>><?= (int)$fte ?> % FTE</option>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="pb-card">
            <h2>Neue Wohnung</h2>

            <label for="warmRent">Miete [Warm + Nebenkosten]</label>
            <input id="warmRent" type="number" min="0" step="10" value="850">
        </section>

        <section class="pb-card">
            <div class="pb-card-head">
                <h2>Pendeln</h2>
                <label class="pb-park-check" for="rwthParking">
                    <input id="rwthParking" type="checkbox" checked>
                    RWTH-Parkausweis
                </label>
            </div>

            <div class="pb-inline-grid">
                <div>
                    <label for="commuteDistance">Distanz [km]</label>
                    <input id="commuteDistance" type="number" min="0" step="0.5" value="<?= htmlspecialchars(number_format((float)$commutingParameters['default_distance_km'], 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label for="officeDays">Tage vor Ort</label>
                    <select id="officeDays" class="kategorie-select">
                        <?php for ($halfDays = 0; $halfDays <= 10; $halfDays++): ?>
                            <?php $days = $halfDays / 2; ?>
                            <option value="<?= htmlspecialchars(number_format($days, 1, '.', ''), ENT_QUOTES, 'UTF-8') ?>" <?= abs($days - (float)$commutingParameters['default_days_per_week']) < 0.001 ? 'selected' : '' ?>><?= htmlspecialchars(number_format($days, 1, ',', ''), ENT_QUOTES, 'UTF-8') ?> / Woche</option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </section>
    </div>

    <div class="pb-tax-error" id="taxCalcError" role="alert">
        Die Lohnsteuer-Berechnung konnte nicht geladen werden.
    </div>

    <section class="pb-table-section">
        <h2>Zukünftige Monatsrechnung</h2>
        <div class="pb-table-wrap">
            <table class="pb-table">
                <thead>
                    <tr>
                        <th>Bestandteil</th>
                        <th class="pb-sign"></th>
                        <th class="pb-money">Betrag / Monat</th>
                        <th>Grundlage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Bruttoeinkommen</td>
                        <td class="pb-sign">+</td>
                        <td class="pb-money" id="calcGrossIncome">—</td>
                        <td id="calcSalaryLabel">—</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Rentenversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcPension">—</td>
                        <td>Arbeitnehmeranteil</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Arbeitslosenversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcUnemployment">—</td>
                        <td>Arbeitnehmeranteil</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Krankenversicherung (TK)</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcHealth">—</td>
                        <td>inkl. Zusatzbeitrag</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Pflegeversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcCare">—</td>
                        <td>kinderlos, NRW</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Lohnsteuer (BMF 2026)</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcWageTax">—</td>
                        <td>Steuerklasse I</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>Solidaritätszuschlag</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcSoli">—</td>
                        <td>BMF-Berechnung</td>
                    </tr>
                    <tr class="pb-subrow">
                        <td>VBLklassik</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcVbl">—</td>
                        <td>Arbeitnehmeranteil West</td>
                    </tr>
                    <tr class="pb-net-row">
                        <td>Nettoeinkommen</td>
                        <td class="pb-sign">=</td>
                        <td class="pb-money" id="calcNetIncome">—</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td>Neue Warmmiete</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcWarmRent">—</td>
                        <td>manuell</td>
                    </tr>
                    <tr>
                        <td>Sonstige bisherige Ausgaben</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcOtherExpenses"><?= pbEuro($otherMonthlyAverage) ?></td>
                        <td>Ø der letzten 6 Monate</td>
                    </tr>
                    <tr>
                        <td>Pendelkosten</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcCommuting">—</td>
                        <td id="calcCommutingBasis">—</td>
                    </tr>
                    <tr class="pb-result-row">
                        <td>Monatlich übrig</td>
                        <td class="pb-sign">=</td>
                        <td class="pb-money pb-result-value" id="calcRemaining">—</td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <details class="pb-details">
        <summary>TV-L: Entgeltgruppen und Stufen</summary>

        <div class="pb-info-grid">
            <section class="pb-info-block">
                <h3>Entgeltgruppen – IT-Tätigkeitsmerkmale</h3>
                <table class="pb-info-table">
                    <thead>
                        <tr>
                            <th>Gruppe</th>
                            <th>Grobe Einordnung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groupDescriptions as $group => $description): ?>
                            <tr>
                                <td><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="pb-info-block">
                <h3>Stufen – Berufserfahrung / Laufzeit</h3>
                <table class="pb-info-table">
                    <thead>
                        <tr>
                            <th>Stufe</th>
                            <th>Grobe Einordnung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stageDescriptions as $stage => $description): ?>
                            <tr>
                                <td>S<?= (int)$stage ?></td>
                                <td><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </details>
</div>

<script type="module">
import { calculate as calculateLohnsteuer } from 'https://cdn.jsdelivr.net/npm/lohnsteuerrechner/+esm';

const PB_TVL_PRESETS = <?= json_encode($tvlPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PB_PAYROLL = <?= json_encode($payrollParameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PB_OTHER_MONTHLY = <?= json_encode(round($otherMonthlyAverage, 2)) ?>;
const PB_COMMUTING = <?= json_encode($commutingParameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PB_STORAGE_KEY = 'postBachelorBudget:v6';
const PB_OLD_STORAGE_KEYS = ['postBachelorBudget:v5', 'postBachelorBudget:v4', 'postBachelorBudget:v3', 'postBachelorBudget:v2', 'postBachelorBudget:v1'];

const pbEuro = (value) => new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
}).format(Number(value || 0));

function pbRound2(value) {
    return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
}

function pbNumber(id) {
    const el = document.getElementById(id);
    if (!el) return 0;
    const value = Number(el.value);
    return Number.isFinite(value) ? value : 0;
}

function pbSelectedPreset() {
    const key = document.getElementById('salaryPreset')?.value || 'e9b_s1';
    return PB_TVL_PRESETS[key] || PB_TVL_PRESETS.e9b_s1;
}

function pbSetText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function pbSetResultClass(el, value) {
    if (!el) return;
    el.classList.toggle('pb-result-positive', value >= 0);
    el.classList.toggle('pb-result-negative', value < 0);
}

function pbSaveSettings() {
    const state = {
        preset: document.getElementById('salaryPreset')?.value || 'e9b_s1',
        ftePercent: pbNumber('ftePercent') || 100,
        warmRent: pbNumber('warmRent'),
        commuteDistance: pbNumber('commuteDistance'),
        officeDays: pbNumber('officeDays'),
        rwthParking: document.getElementById('rwthParking')?.checked !== false
    };

    try {
        localStorage.setItem(PB_STORAGE_KEY, JSON.stringify(state));
    } catch (_) {}
}

function pbLoadSettings() {
    try {
        let raw = localStorage.getItem(PB_STORAGE_KEY);

        if (!raw) {
            for (const oldKey of PB_OLD_STORAGE_KEYS) {
                raw = localStorage.getItem(oldKey);
                if (raw) break;
            }
        }

        if (!raw) return;

        const state = JSON.parse(raw);
        const preset = document.getElementById('salaryPreset');
        const fte = document.getElementById('ftePercent');
        const rent = document.getElementById('warmRent');
        const distance = document.getElementById('commuteDistance');
        const officeDays = document.getElementById('officeDays');
        const rwthParking = document.getElementById('rwthParking');

        const oldPresetMap = {
            e6: 'e6_s1',
            e7: 'e7_s1',
            e8: 'e8_s1',
            e9a: 'e9a_s1',
            e9b: 'e9b_s1',
            e10: 'e10_s1'
        };

        let presetValue = typeof state.preset === 'string' ? state.preset : 'e9b_s1';
        presetValue = oldPresetMap[presetValue] || presetValue;

        if (!PB_TVL_PRESETS[presetValue]) presetValue = 'e9b_s1';
        if (preset) preset.value = presetValue;

        const fteValue = Number(state.ftePercent);
        if (fte && [50, 60, 70, 80, 90, 100].includes(fteValue)) {
            fte.value = String(fteValue);
        }

        if (rent && Number.isFinite(Number(state.warmRent))) rent.value = Number(state.warmRent);

        // Neue Pendelparameter werden absichtlich nicht aus dem alten freien
        // Kostenfeld abgeleitet. Ohne v4-Werte bleiben die sinnvollen Defaults aktiv.
        if (distance && Number.isFinite(Number(state.commuteDistance))) {
            distance.value = Number(state.commuteDistance);
        }

        const officeDaysValue = Number(state.officeDays);
        if (officeDays && Number.isFinite(officeDaysValue) && officeDaysValue >= 0 && officeDaysValue <= 5) {
            officeDays.value = officeDaysValue.toFixed(1);
        }


        if (rwthParking && typeof state.rwthParking === 'boolean') {
            rwthParking.checked = state.rwthParking;
        }
    } catch (_) {}
}

function pbSocialContributions(gross) {
    const g = Number(gross || 0);
    const midijobMin = Number(PB_PAYROLL.midijob_min || 603.01);
    const midijobMax = Number(PB_PAYROLL.midijob_max || 2000);

    /*
     * Übergangsbereich 2026:
     * Für regelmäßiges Monatsentgelt zwischen 603,01 und 2.000 € wird der
     * Arbeitnehmeranteil nicht aus dem vollen Brutto berechnet.
     *
     * BE Gesamt = 1,1459372226 × AE − 291,8744452399
     * BE AN      = 1,43163922691 × AE − 863,2784538207
     *
     * Für RV/AV/KV/PV-Basis trägt der AN seinen halben Beitragssatz aus BE AN.
     * Der Kinderlosenzuschlag der PV wird dagegen aus BE Gesamt berechnet.
     */
    if (g >= midijobMin && g <= midijobMax) {
        const beTotal = Math.max(0, 1.1459372226 * g - 291.8744452399);
        const beEmployee = Math.max(0, 1.43163922691 * g - 863.2784538207);

        const pension = pbRound2(
            beEmployee * Number(PB_PAYROLL.pension_employee_rate)
        );

        const unemployment = pbRound2(
            beEmployee * Number(PB_PAYROLL.unemployment_employee_rate)
        );

        const healthGeneral = pbRound2(
            beEmployee * Number(PB_PAYROLL.health_general_employee_rate)
        );
        const healthAdditional = pbRound2(
            beEmployee * (Number(PB_PAYROLL.health_additional_rate_total) / 2)
        );
        const health = pbRound2(healthGeneral + healthAdditional);

        const careBase = pbRound2(
            beEmployee * Number(PB_PAYROLL.care_employee_base_rate)
        );
        const careSurcharge = PB_PAYROLL.childless_care_surcharge
            ? pbRound2(beTotal * Number(PB_PAYROLL.care_childless_surcharge_rate))
            : 0;
        const care = pbRound2(careBase + careSurcharge);

        const vbl = pbRound2(g * Number(PB_PAYROLL.vbl_employee_rate));

        return {
            pension,
            unemployment,
            health,
            care,
            vbl,
            midijob: true
        };
    }

    const healthCareBase = Math.min(g, Number(PB_PAYROLL.bbg_health_care_monthly));
    const pensionUnemploymentBase = Math.min(g, Number(PB_PAYROLL.bbg_pension_unemployment_monthly));

    const pension = pbRound2(
        pensionUnemploymentBase * Number(PB_PAYROLL.pension_employee_rate)
    );

    const unemployment = pbRound2(
        pensionUnemploymentBase * Number(PB_PAYROLL.unemployment_employee_rate)
    );

    // KV wird bewusst in allgemeinen Beitrag und Zusatzbeitrag getrennt gerundet.
    const healthGeneral = pbRound2(
        healthCareBase * Number(PB_PAYROLL.health_general_employee_rate)
    );
    const healthAdditional = pbRound2(
        healthCareBase * (Number(PB_PAYROLL.health_additional_rate_total) / 2)
    );
    const health = pbRound2(healthGeneral + healthAdditional);

    const careBase = pbRound2(
        healthCareBase * Number(PB_PAYROLL.care_employee_base_rate)
    );
    const careSurcharge = PB_PAYROLL.childless_care_surcharge
        ? pbRound2(healthCareBase * Number(PB_PAYROLL.care_childless_surcharge_rate))
        : 0;
    const care = pbRound2(careBase + careSurcharge);

    const vbl = pbRound2(g * Number(PB_PAYROLL.vbl_employee_rate));

    return {
        pension,
        unemployment,
        health,
        care,
        vbl,
        midijob: false
    };
}
function pbTaxes(gross) {
    const result = calculateLohnsteuer(Number(PB_PAYROLL.tax_year), {
        LZZ: 2, // monatlicher Lohnzahlungszeitraum
        RE4: Math.round(gross * 100),
        STKL: Number(PB_PAYROLL.tax_class),
        R: 0,
        ZKF: 0,
        PKV: 0,
        KVZ: Number(PB_PAYROLL.health_additional_rate_total) * 100,
        PVZ: PB_PAYROLL.childless_care_surcharge ? 1 : 0,
        PVS: PB_PAYROLL.saxony ? 1 : 0
    });

    return {
        wageTax: pbRound2(Number(result.LSTLZZ || 0) / 100),
        soli: pbRound2(Number(result.SOLZLZZ || 0) / 100)
    };
}

function pbCommutingCalculation() {
    const distanceOneWay = Math.max(0, pbNumber('commuteDistance'));
    const officeDaysPerWeek = Math.max(0, Math.min(5, pbNumber('officeDays')));
    const weeksPerMonth = Number(PB_COMMUTING.weeks_per_month || (52 / 12));
    const consumption = Number(PB_COMMUTING.polo_consumption_l_per_100km || 5.5);
    const fuelPrice = Number(PB_COMMUTING.fuel_price_eur_per_l || 2.2);
    const parkPerMonth = Number(PB_COMMUTING.park_per_month || 12.5);
    const parkingEnabled = document.getElementById('rwthParking')?.checked !== false;
    const parkingCost = parkingEnabled ? parkPerMonth : 0;

    const officeDaysPerMonth = officeDaysPerWeek * weeksPerMonth;
    const monthlyKm = distanceOneWay * 2 * officeDaysPerMonth;
    const liters = monthlyKm * consumption / 100;
    const fuelCost = pbRound2(liters * fuelPrice);
    const cost = pbRound2(fuelCost + parkingCost);

    return {
        distanceOneWay,
        officeDaysPerWeek,
        officeDaysPerMonth,
        monthlyKm,
        liters,
        consumption,
        fuelPrice,
        fuelCost,
        parkingEnabled,
        parkingCost,
        cost
    };
}

function pbRecalculate(save = true) {
    const preset = pbSelectedPreset();
    const fullTimeGross = Number(preset.gross || 0);
    const ftePercent = Math.max(50, Math.min(100, pbNumber('ftePercent') || 100));
    const gross = pbRound2(fullTimeGross * ftePercent / 100);
    const warmRent = pbNumber('warmRent');
    const commuting = pbCommutingCalculation();

    try {
        const social = pbSocialContributions(gross);
        const taxes = pbTaxes(gross);

        const net = pbRound2(
            gross
            - social.pension
            - social.unemployment
            - social.health
            - social.care
            - taxes.wageTax
            - taxes.soli
            - social.vbl
        );

        const remaining = pbRound2(
            net - warmRent - PB_OTHER_MONTHLY - commuting.cost
        );

        pbSetText('calcGrossIncome', pbEuro(gross));
        pbSetText(
            'calcSalaryLabel',
            `${preset.label || 'TV-L'} · ${ftePercent} % FTE`
        );
        pbSetText('calcPension', pbEuro(social.pension));
        pbSetText('calcUnemployment', pbEuro(social.unemployment));
        pbSetText('calcHealth', pbEuro(social.health));
        pbSetText('calcCare', pbEuro(social.care));
        pbSetText('calcWageTax', pbEuro(taxes.wageTax));
        pbSetText('calcSoli', pbEuro(taxes.soli));
        pbSetText('calcVbl', pbEuro(social.vbl));
        pbSetText('calcNetIncome', pbEuro(net));
        pbSetText('calcWarmRent', pbEuro(warmRent));
                pbSetText('calcCommuting', pbEuro(commuting.cost));
        pbSetText(
            'calcCommutingBasis',
            `${commuting.monthlyKm.toFixed(0)} km/Monat · VW Polo ${commuting.consumption.toFixed(1).replace('.', ',')} l/100 km · Super ${pbEuro(commuting.fuelPrice)}/l`
        );
        pbSetText('calcRemaining', pbEuro(remaining));

        const remainingEl = document.getElementById('calcRemaining');
        pbSetResultClass(remainingEl, remaining);

        document.getElementById('taxCalcError')?.classList.remove('is-visible');
    } catch (error) {
        console.error('PostBachelor payroll calculation failed:', error);
        document.getElementById('taxCalcError')?.classList.add('is-visible');
    }

    if (save) pbSaveSettings();
}

pbLoadSettings();

const preset = document.getElementById('salaryPreset');
const fte = document.getElementById('ftePercent');
const rent = document.getElementById('warmRent');
const commuteDistance = document.getElementById('commuteDistance');
const officeDays = document.getElementById('officeDays');
const rwthParking = document.getElementById('rwthParking');

if (preset) preset.addEventListener('change', () => pbRecalculate());
if (fte) fte.addEventListener('change', () => pbRecalculate());
if (rent) rent.addEventListener('input', () => pbRecalculate());
if (commuteDistance) commuteDistance.addEventListener('input', () => pbRecalculate());
if (officeDays) officeDays.addEventListener('change', () => pbRecalculate());
if (rwthParking) rwthParking.addEventListener('change', () => pbRecalculate());

pbRecalculate(false);
</script>

</body>
</html>