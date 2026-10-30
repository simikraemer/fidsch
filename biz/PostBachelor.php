<?php
// biz/PostBachelor_v8.php
// Kompakte Budgetplanung für TV-L- und HiWi-Beschäftigung an der RWTH.

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
 * Sonstige Ausgaben: letzte 6 abgeschlossene Kalendermonate
 *
 * - Negative Buchungen sind Ausgaben (+ in der Ausgabensumme).
 * - Positive Buchungen mindern die Ausgabensumme (Erstattungen etc.),
 *   auch wenn sie in einer ANDEREN Kategorie verbucht wurden.
 * - Ausnahme: Positive Buchungen mit kategorie_id 1 (IT Center)
 *   oder 25 (Familie) bleiben außen vor (Gehalt/Kindergeld werden
 *   separat als Einnahmen angesetzt). Negative Buchungen aus diesen
 *   Kategorien bleiben dagegen als Ausgaben enthalten.
 * - Miete grundsätzlich ausnehmen, weil die neue Miete separat steht.
 * - TK bei HiWi einbeziehen, bei TV-L ausnehmen (bereits im Netto).
 * - Alle Kategoriesalden addieren, auch positive Salden! Kein HAVING
 *   und kein abs(): sonst würden Gutschriften wieder verloren gehen.
 * - Danach jeweils durch 6 teilen.
 * ========================================================= */
$otherSixMonthTotalTvl = 0.0;
$otherSixMonthTotalHiwi = 0.0;
$stmtOther = $bizconn->prepare("
    SELECT
        COALESCE(k.name, 'Unkategorisiert') AS category_name,
        COALESCE(SUM(
            CASE
                WHEN t.betrag < 0 THEN -t.betrag
                WHEN t.betrag > 0
                     AND (t.kategorie_id IS NULL OR t.kategorie_id NOT IN (1, 25))
                    THEN -t.betrag
                ELSE 0
            END
        ), 0) AS net_expenses
    FROM transfers t
    LEFT JOIN kategorien k ON k.id = t.kategorie_id
    WHERE t.valutadatum IS NOT NULL
      AND t.valutadatum >= ?
      AND t.valutadatum < ?
      AND (k.name IS NULL OR k.name <> 'Miete')
    GROUP BY COALESCE(k.name, 'Unkategorisiert')
");

if ($stmtOther !== false) {
    $stmtOther->bind_param('ss', $periodStartSql, $periodEndSql);
    $stmtOther->execute();
    $resOther = $stmtOther->get_result();

    while ($row = $resOther->fetch_assoc()) {
        $categoryName = (string)$row['category_name'];
        // Nicht absolut setzen: ein Ueberschuss an Erstattungen muss
        // die Ausgabensumme anderer Kategorien ebenfalls senken.
        $categoryExpenses = (float)$row['net_expenses'];

        $otherSixMonthTotalHiwi += $categoryExpenses;
        if ($categoryName !== 'TK') {
            $otherSixMonthTotalTvl += $categoryExpenses;
        }
    }

    $stmtOther->close();
}

$otherMonthlyAverageTvl = $otherSixMonthTotalTvl / 6.0;
$otherMonthlyAverageHiwi = $otherSixMonthTotalHiwi / 6.0;

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

/* HiWi verwendet die gesamte Breite des Entgeltgruppen-Dropdowns. */
.pb-salary-controls.is-hiwi {
    grid-template-columns: minmax(0, 1fr);
}

/* Auch in vorhandenen Layouts/CSS-Regeln versteckte Blöcke ausblenden. */
.postbachelor-page [hidden] {
    display: none !important;
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

/* Smartphone-Regeln liegen zentral in FIJI_Mobile.css. */
</style>

<div class="lt-page postbachelor-page" id="postBachelorPage">
    <div class="lt-topbar pb-topbar">
        <h1 class="ueberschrift">Post-Bachelor Budget</h1>
    </div>

    <div class="pb-grid">
        <section class="pb-card">
            <h2 id="incomeCardTitle">TV-L Einkommen</h2>

            <div class="pb-inline-grid pb-salary-controls" id="salaryControls">
                <div>
                    <label for="salaryGroup">Entgeltgruppe</label>
                    <select id="salaryGroup" class="kategorie-select">
                        <option value="HiWi">HiWi</option>
                        <?php foreach ($tvlGrossTable as $group => $stages): ?>
                            <option value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>" <?= $group === 'E9b' ? 'selected' : '' ?>><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="salaryStageWrap">
                    <label for="salaryStage">Stufe</label>
                    <select id="salaryStage" class="kategorie-select">
                        <?php for ($stage = 1; $stage <= 6; $stage++): ?>
                            <option value="<?= $stage ?>" <?= $stage === 1 ? 'selected' : '' ?>>S<?= $stage ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </section>

        <section class="pb-card">
            <h2 id="incomeOptionsTitle">Teilzeit</h2>

            <div id="tvlIncomeControls">
                <label for="ftePercent">Beschäftigungsumfang</label>
                <select id="ftePercent" class="kategorie-select">
                    <?php foreach ([50, 60, 70, 80, 90, 100] as $fte): ?>
                        <option value="<?= (int)$fte ?>" <?= $fte === 100 ? 'selected' : '' ?>><?= (int)$fte ?> % FTE</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pb-inline-grid" id="hiwiIncomeControls" hidden>
                <div>
                    <label for="hiwiSalary">HiWi-Gehalt</label>
                    <input id="hiwiSalary" type="number" min="0" step="0.01" value="1099.07">
                </div>
                <div>
                    <label for="childBenefit">Kindergeld</label>
                    <input id="childBenefit" type="number" min="0" step="0.01" value="360">
                </div>
            </div>
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
                        <td id="calcIncomeHeading">Bruttoeinkommen</td>
                        <td class="pb-sign">+</td>
                        <td class="pb-money" id="calcGrossIncome">—</td>
                        <td id="calcSalaryLabel">—</td>
                    </tr>
                    <tr id="calcChildBenefitRow" hidden>
                        <td>Kindergeld</td>
                        <td class="pb-sign">+</td>
                        <td class="pb-money" id="calcChildBenefit">—</td>
                        <td>manuell</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Rentenversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcPension">—</td>
                        <td>Arbeitnehmeranteil</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Arbeitslosenversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcUnemployment">—</td>
                        <td>Arbeitnehmeranteil</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Krankenversicherung (TK)</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcHealth">—</td>
                        <td>inkl. Zusatzbeitrag</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Pflegeversicherung</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcCare">—</td>
                        <td>kinderlos, NRW</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Lohnsteuer (BMF 2026)</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcWageTax">—</td>
                        <td>Steuerklasse I</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
                        <td>Solidaritätszuschlag</td>
                        <td class="pb-sign">−</td>
                        <td class="pb-money" id="calcSoli">—</td>
                        <td>BMF-Berechnung</td>
                    </tr>
                    <tr class="pb-subrow pb-payroll-row">
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
                        <td class="pb-money" id="calcOtherExpenses"><?= pbEuro($otherMonthlyAverageTvl) ?></td>
                        <td id="calcOtherExpensesBasis" title="Gutschriften werden gegengerechnet. Positive Buchungen aus IT Center (ID 1) und Familie (ID 25) sind ausgeschlossen.">Ø 6 Monate · saldiert, ohne TK</td>
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
const PB_OTHER_MONTHLY_TVL = <?= json_encode(round($otherMonthlyAverageTvl, 2)) ?>;
const PB_OTHER_MONTHLY_HIWI = <?= json_encode(round($otherMonthlyAverageHiwi, 2)) ?>;
const PB_COMMUTING = <?= json_encode($commutingParameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const PB_STORAGE_KEY = 'postBachelorBudget:v8';
const PB_OLD_STORAGE_KEYS = ['postBachelorBudget:v7', 'postBachelorBudget:v6', 'postBachelorBudget:v5', 'postBachelorBudget:v4', 'postBachelorBudget:v3', 'postBachelorBudget:v2', 'postBachelorBudget:v1'];

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

function pbIsHiwi() {
    return document.getElementById('salaryGroup')?.value === 'HiWi';
}

function pbSelectedPreset() {
    const group = document.getElementById('salaryGroup')?.value || 'E9b';
    const stage = document.getElementById('salaryStage')?.value || '1';
    const key = `${group.toLowerCase()}_s${stage}`;
    return PB_TVL_PRESETS[key] || PB_TVL_PRESETS.e9b_s1;
}

function pbApplyIncomeMode(isHiwi) {
    document.getElementById('salaryControls')?.classList.toggle('is-hiwi', isHiwi);

    const stageWrap = document.getElementById('salaryStageWrap');
    const tvlControls = document.getElementById('tvlIncomeControls');
    const hiwiControls = document.getElementById('hiwiIncomeControls');
    const childBenefitRow = document.getElementById('calcChildBenefitRow');

    if (stageWrap) stageWrap.hidden = isHiwi;
    if (tvlControls) tvlControls.hidden = isHiwi;
    if (hiwiControls) hiwiControls.hidden = !isHiwi;
    if (childBenefitRow) childBenefitRow.hidden = !isHiwi;

    document.querySelectorAll('.pb-payroll-row').forEach(row => {
        row.hidden = isHiwi;
    });

    pbSetText('incomeCardTitle', isHiwi ? 'HiWi Einkommen' : 'TV-L Einkommen');
    pbSetText('incomeOptionsTitle', isHiwi ? 'Einnahmen' : 'Teilzeit');
    pbSetText('calcIncomeHeading', isHiwi ? 'HiWi-Gehalt (netto)' : 'Bruttoeinkommen');
    pbSetText('calcOtherExpensesBasis',
        isHiwi ? 'Ø 6 Monate · saldiert, inkl. TK' : 'Ø 6 Monate · saldiert, ohne TK');
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
    const selectedPreset = pbSelectedPreset();
    const state = {
        preset: pbIsHiwi() ? 'hiwi' : `${selectedPreset.group.toLowerCase()}_s${selectedPreset.stage}`,
        salaryStage: pbNumber('salaryStage'),
        hiwiSalary: Math.max(0, pbNumber('hiwiSalary')),
        childBenefit: Math.max(0, pbNumber('childBenefit')),
        ftePercent: pbNumber('ftePercent'),
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
        const salaryGroup = document.getElementById('salaryGroup');
        const salaryStage = document.getElementById('salaryStage');
        const fte = document.getElementById('ftePercent');
        const hiwiSalary = document.getElementById('hiwiSalary');
        const childBenefit = document.getElementById('childBenefit');
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

        const isHiwi = presetValue === 'hiwi';
        if (!isHiwi && !PB_TVL_PRESETS[presetValue]) presetValue = 'e9b_s1';
        const storedPreset = PB_TVL_PRESETS[isHiwi ? 'e9b_s1' : presetValue];
        if (salaryGroup) salaryGroup.value = isHiwi ? 'HiWi' : storedPreset.group;

        const stageValue = Number(state.salaryStage ?? storedPreset.stage);
        if (salaryStage) {
            salaryStage.value = String(Number.isInteger(stageValue) && stageValue >= 1 && stageValue <= 6
                ? stageValue
                : storedPreset.stage);
        }

        if (hiwiSalary && Number.isFinite(Number(state.hiwiSalary)) && Number(state.hiwiSalary) >= 0 && state.hiwiSalary != null) {
            hiwiSalary.value = String(state.hiwiSalary);
        }
        if (childBenefit && Number.isFinite(Number(state.childBenefit)) && Number(state.childBenefit) >= 0 && state.childBenefit != null) {
            childBenefit.value = String(state.childBenefit);
        }

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
    const isHiwi = pbIsHiwi();
    pbApplyIncomeMode(isHiwi);

    const warmRent = Math.max(0, pbNumber('warmRent'));
    const commuting = pbCommutingCalculation();
    const otherMonthly = isHiwi ? PB_OTHER_MONTHLY_HIWI : PB_OTHER_MONTHLY_TVL;

    try {
        let net;

        if (isHiwi) {
            // HiWi-Eingabe ist der bereits ausgezahlte Nettobetrag.
            // Die separat gezahlte TK wird über die historischen Ausgaben erfasst.
            const hiwiSalary = pbRound2(Math.max(0, pbNumber('hiwiSalary')));
            const childBenefit = pbRound2(Math.max(0, pbNumber('childBenefit')));
            net = pbRound2(hiwiSalary + childBenefit);

            pbSetText('calcGrossIncome', pbEuro(hiwiSalary));
            pbSetText('calcSalaryLabel', 'HiWi · ausgezahltes Nettogehalt');
            pbSetText('calcChildBenefit', pbEuro(childBenefit));
        } else {
            // Bestehende TV-L-Abgabenrechnung bleibt unverändert.
            const preset = pbSelectedPreset();
            const fullTimeGross = Number(preset.gross || 0);
            const ftePercent = Math.max(50, Math.min(100, pbNumber('ftePercent') || 100));
            const gross = pbRound2(fullTimeGross * ftePercent / 100);
            const social = pbSocialContributions(gross);
            const taxes = pbTaxes(gross);

            net = pbRound2(
                gross
                - social.pension
                - social.unemployment
                - social.health
                - social.care
                - taxes.wageTax
                - taxes.soli
                - social.vbl
            );

            pbSetText('calcGrossIncome', pbEuro(gross));
            pbSetText('calcSalaryLabel', `${preset.label || 'TV-L'} · ${ftePercent} % FTE`);
            pbSetText('calcPension', pbEuro(social.pension));
            pbSetText('calcUnemployment', pbEuro(social.unemployment));
            pbSetText('calcHealth', pbEuro(social.health));
            pbSetText('calcCare', pbEuro(social.care));
            pbSetText('calcWageTax', pbEuro(taxes.wageTax));
            pbSetText('calcSoli', pbEuro(taxes.soli));
            pbSetText('calcVbl', pbEuro(social.vbl));
        }

        const remaining = pbRound2(
            net - warmRent - otherMonthly - commuting.cost
        );

        pbSetText('calcNetIncome', pbEuro(net));
        pbSetText('calcWarmRent', pbEuro(warmRent));
        pbSetText('calcOtherExpenses', pbEuro(otherMonthly));
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
        console.error('PostBachelor income calculation failed:', error);
        document.getElementById('taxCalcError')?.classList.add('is-visible');
    }

    if (save) pbSaveSettings();
}

pbLoadSettings();

const salaryGroup = document.getElementById('salaryGroup');
const salaryStage = document.getElementById('salaryStage');
const fte = document.getElementById('ftePercent');
const hiwiSalary = document.getElementById('hiwiSalary');
const childBenefit = document.getElementById('childBenefit');
const rent = document.getElementById('warmRent');
const commuteDistance = document.getElementById('commuteDistance');
const officeDays = document.getElementById('officeDays');
const rwthParking = document.getElementById('rwthParking');

if (salaryGroup) salaryGroup.addEventListener('change', () => pbRecalculate());
if (salaryStage) salaryStage.addEventListener('change', () => pbRecalculate());
if (fte) fte.addEventListener('change', () => pbRecalculate());
if (hiwiSalary) hiwiSalary.addEventListener('input', () => pbRecalculate());
if (childBenefit) childBenefit.addEventListener('input', () => pbRecalculate());
if (rent) rent.addEventListener('input', () => pbRecalculate());
if (commuteDistance) commuteDistance.addEventListener('input', () => pbRecalculate());
if (officeDays) officeDays.addEventListener('change', () => pbRecalculate());
if (rwthParking) rwthParking.addEventListener('change', () => pbRecalculate());

pbRecalculate(false);
</script>

</body>
</html>