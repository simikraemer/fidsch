<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$conn = null;

if (isset($conn) && $conn instanceof mysqli) {
    // ok
} elseif (isset($sciconn) && $sciconn instanceof mysqli) {
    $conn = $sciconn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $conn = $mysqli;
}

if (!$conn instanceof mysqli) {
    http_response_code(500);
    die('Keine mysqli-Verbindung gefunden. Bitte Variable in db.php prüfen.');
}

$conn->set_charset('utf8mb4');
$conn->select_db('life');

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dt(?string $date): ?DateTimeImmutable {
    if ($date === null || $date === '') {
        return null;
    }
    return new DateTimeImmutable($date);
}

function fmtDate(?DateTimeImmutable $d): string {
    return $d ? $d->format('d.m.Y') : '';
}

function outlineParts(string $outline): array {
    $parts = preg_split('/\./', $outline);
    return array_map(static fn($p) => (int)$p, $parts ?: []);
}

function compareOutline(string $a, string $b): int {
    $aa = outlineParts($a);
    $bb = outlineParts($b);
    $len = max(count($aa), count($bb));

    for ($i = 0; $i < $len; $i++) {
        $av = $aa[$i] ?? -1;
        $bv = $bb[$i] ?? -1;
        if ($av < $bv) return -1;
        if ($av > $bv) return 1;
    }
    return 0;
}

function statusLabel(?string $status): string {
    $map = [
        'BE' => 'Bestanden',
        'NB' => 'Nicht bestanden',
        'Q'  => 'Attest / keine Beurteilung',
        'X'  => 'Nicht erschienen',
        'AN' => 'Aktive Anmeldung',
    ];
    $s = strtoupper(trim((string)$status));
    return $map[$s] ?? ($s !== '' ? $s : 'Ohne Status');
}

function statusCss(?string $status): string {
    $s = strtolower(trim((string)$status));
    return preg_replace('/[^a-z0-9_-]/', '', $s) ?: 'default';
}

function rootGroupId(int $groupId, array $groupsById): int {
    $current = $groupId;
    $seen = [];

    while (isset($groupsById[$current]) && !empty($groupsById[$current]['parent_group_id'])) {
        if (isset($seen[$current])) {
            break;
        }
        $seen[$current] = true;
        $current = (int)$groupsById[$current]['parent_group_id'];
    }

    return $current;
}

function rootColorById(int $rootId): string {
    $map = [
        /* 1  => '#2563eb', */
        1  => '#333',
        47 => '#2563eb',
        3  => '#22c55e',
    ];
    return $map[$rootId] ?? '#64748b';
}

function daysInYear(DateTimeImmutable $date): int {
    return ((int)$date->format('L') === 1) ? 366 : 365;
}

function formatSemesterLabel(string $season, int $year): string {
    if ($season === 'S') {
        return sprintf('SS%02d', $year % 100);
    }

    return sprintf('WS%02d/%02d', $year % 100, ($year + 1) % 100);
}

function parseSemesterValue(string $value): ?array {
    $value = trim($value);

    if (preg_match('/^SS(\d{2})$/', $value, $m)) {
        $year = 2000 + (int)$m[1];
        $start = new DateTimeImmutable(sprintf('%04d-04-01', $year));
        $end = new DateTimeImmutable(sprintf('%04d-09-30', $year));

        return [
            'value' => $value,
            'label' => $value,
            'season' => 'S',
            'year' => $year,
            'start' => $start,
            'end' => $end,
        ];
    }

    if (preg_match('/^WS(\d{2})\/(\d{2})$/', $value, $m)) {
        $year = 2000 + (int)$m[1];
        $start = new DateTimeImmutable(sprintf('%04d-10-01', $year));
        $end = new DateTimeImmutable(sprintf('%04d-03-31', $year + 1));

        return [
            'value' => $value,
            'label' => $value,
            'season' => 'W',
            'year' => $year,
            'start' => $start,
            'end' => $end,
        ];
    }

    return null;
}

function buildSemesterOptions(?DateTimeImmutable $minDate, ?DateTimeImmutable $maxDate): array {
    if (!$minDate || !$maxDate) {
        $now = new DateTimeImmutable('today');
        $year = (int)$now->format('Y');
        $month = (int)$now->format('n');

        if ($month >= 10) {
            return [[
                'value' => formatSemesterLabel('W', $year),
                'label' => formatSemesterLabel('W', $year),
                'season' => 'W',
                'year' => $year,
                'start' => new DateTimeImmutable(sprintf('%04d-10-01', $year)),
                'end' => new DateTimeImmutable(sprintf('%04d-03-31', $year + 1)),
            ]];
        }

        if ($month >= 4) {
            return [[
                'value' => formatSemesterLabel('S', $year),
                'label' => formatSemesterLabel('S', $year),
                'season' => 'S',
                'year' => $year,
                'start' => new DateTimeImmutable(sprintf('%04d-04-01', $year)),
                'end' => new DateTimeImmutable(sprintf('%04d-09-30', $year)),
            ]];
        }

        return [[
            'value' => formatSemesterLabel('W', $year - 1),
            'label' => formatSemesterLabel('W', $year - 1),
            'season' => 'W',
            'year' => $year - 1,
            'start' => new DateTimeImmutable(sprintf('%04d-10-01', $year - 1)),
            'end' => new DateTimeImmutable(sprintf('%04d-03-31', $year)),
        ]];
    }

    $firstYear = (int)$minDate->format('Y') - 1;
    $lastYear = (int)$maxDate->format('Y');

    $items = [];

    for ($y = $firstYear; $y <= $lastYear; $y++) {
        $ssStart = new DateTimeImmutable(sprintf('%04d-04-01', $y));
        $ssEnd   = new DateTimeImmutable(sprintf('%04d-09-30', $y));

        if ($ssEnd >= $minDate && $ssStart <= $maxDate) {
            $label = formatSemesterLabel('S', $y);
            $items[] = [
                'value' => $label,
                'label' => $label,
                'season' => 'S',
                'year' => $y,
                'start' => $ssStart,
                'end' => $ssEnd,
            ];
        }

        $wsStart = new DateTimeImmutable(sprintf('%04d-10-01', $y));
        $wsEnd   = new DateTimeImmutable(sprintf('%04d-03-31', $y + 1));

        if ($wsEnd >= $minDate && $wsStart <= $maxDate) {
            $label = formatSemesterLabel('W', $y);
            $items[] = [
                'value' => $label,
                'label' => $label,
                'season' => 'W',
                'year' => $y,
                'start' => $wsStart,
                'end' => $wsEnd,
            ];
        }
    }

    usort($items, static function ($a, $b) {
        return $b['start'] <=> $a['start'];
    });

    return $items;
}

function clipBarToScale(
    DateTimeImmutable $entryStart,
    DateTimeImmutable $entryEnd,
    string $mode,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd,
    int $firstYear,
    int $lastYear
): ?array {
    if ($entryEnd < $rangeStart || $entryStart > $rangeEnd) {
        return null;
    }

    $visibleStart = $entryStart > $rangeStart ? $entryStart : $rangeStart;
    $visibleEnd   = $entryEnd   < $rangeEnd   ? $entryEnd   : $rangeEnd;

    if ($mode === 'gesamt') {
        $totalYears = max(1, ($lastYear - $firstYear + 1));

        $startYear = (int)$visibleStart->format('Y');
        $endYear   = (int)$visibleEnd->format('Y');

        $startOffset = ($startYear - $firstYear)
            + ((int)$visibleStart->format('z') / daysInYear($visibleStart));

        $endOffset = ($endYear - $firstYear)
            + (((int)$visibleEnd->format('z') + 1) / daysInYear($visibleEnd));

        $left = ($startOffset / $totalYears) * 100;
        $width = (($endOffset - $startOffset) / $totalYears) * 100;

        return [
            'left'  => $left,
            'width' => max($width, 0.30),
            'start' => $visibleStart,
            'end'   => $visibleEnd,
        ];
    }

    $totalDays = max(1, (int)$rangeStart->diff($rangeEnd)->days + 1);
    $startOffset = (int)$rangeStart->diff($visibleStart)->days;
    $endOffset   = (int)$rangeStart->diff($visibleEnd)->days;

    $left = ($startOffset / $totalDays) * 100;
    $width = ((max(0, $endOffset - $startOffset) + 1) / $totalDays) * 100;

    return [
        'left'  => $left,
        'width' => max($width, 0.35),
        'start' => $visibleStart,
        'end'   => $visibleEnd,
    ];
}

function pointPercentInScale(
    DateTimeImmutable $date,
    string $mode,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd,
    int $firstYear,
    int $lastYear
): float {
    if ($mode === 'gesamt') {
        $totalYears = max(1, ($lastYear - $firstYear + 1));
        $year = (int)$date->format('Y');
        $offset = ($year - $firstYear)
            + (((int)$date->format('z') + 0.5) / daysInYear($date));
        return ($offset / $totalYears) * 100;
    }

    $totalDays = max(1, (int)$rangeStart->diff($rangeEnd)->days + 1);
    $offset = (int)$rangeStart->diff($date)->days;
    return (($offset + 0.5) / $totalDays) * 100;
}

/* -------------------- Datenbereich bestimmen -------------------- */

$minDate = null;
$maxDate = null;

/* Primär aus Segmenten */
$res = $conn->query("
    SELECT
        MIN(start_date) AS min_start,
        MAX(end_date)   AS max_end
    FROM timeline_entry_segments
");
if ($res && ($row = $res->fetch_assoc())) {
    $minDate = $row['min_start'] ?: null;
    $maxDate = $row['max_end'] ?: null;
}

/* Fallback/Ergänzung aus timeline_entries */
$res = $conn->query("
    SELECT
        MIN(start_date) AS min_start,
        MAX(end_date)   AS max_end
    FROM timeline_entries
");
if ($res && ($row = $res->fetch_assoc())) {
    if (!empty($row['min_start']) && ($minDate === null || $row['min_start'] < $minDate)) {
        $minDate = $row['min_start'];
    }
    if (!empty($row['max_end']) && ($maxDate === null || $row['max_end'] > $maxDate)) {
        $maxDate = $row['max_end'];
    }
}

/* Events ergänzen */
$res = $conn->query("
    SELECT
        MIN(event_date) AS min_event,
        MAX(event_date) AS max_event
    FROM special_events
");
if ($res && ($row = $res->fetch_assoc())) {
    if (!empty($row['min_event']) && ($minDate === null || $row['min_event'] < $minDate)) {
        $minDate = $row['min_event'];
    }
    if (!empty($row['max_event']) && ($maxDate === null || $row['max_event'] > $maxDate)) {
        $maxDate = $row['max_event'];
    }
}

$currentYear = (int)date('Y');
$firstYear = $minDate ? (int)substr($minDate, 0, 4) : $currentYear;
$lastYear  = $maxDate ? (int)substr($maxDate, 0, 4) : $currentYear;

$mode = isset($_GET['modus']) ? strtolower(trim((string)$_GET['modus'])) : 'gesamt';
if (!in_array($mode, ['gesamt', 'jahr', 'semester'], true)) {
    $mode = 'gesamt';
}

$yearKeys = [];
for ($y = $lastYear; $y >= $firstYear; $y--) {
    $yearKeys[] = $y;
}

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : $lastYear;
if ($jahr < $firstYear || $jahr > $lastYear) {
    $jahr = $lastYear;
}

$semesterOptions = buildSemesterOptions(dt($minDate), dt($maxDate));
$semesterValues = array_map(static fn($item) => $item['value'], $semesterOptions);

$semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : ($semesterOptions[0]['value'] ?? '');
if (!in_array($semester, $semesterValues, true)) {
    $semester = $semesterOptions[0]['value'] ?? '';
}

$selectedSemester = parseSemesterValue($semester);
if (!$selectedSemester && !empty($semesterOptions)) {
    $selectedSemester = $semesterOptions[0];
    $semester = $selectedSemester['value'];
}

/* -------------------- Sichtbereich / Achse -------------------- */

$monthNames = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

$axisSegments = [];
$axisTemplateParts = [];

if ($mode === 'gesamt') {
    $rangeStart = new DateTimeImmutable(sprintf('%04d-01-01', $firstYear));
    $rangeEnd   = new DateTimeImmutable(sprintf('%04d-12-31', $lastYear));
    $rangeEndExclusive = new DateTimeImmutable(sprintf('%04d-01-01', $lastYear + 1));

    for ($y = $firstYear; $y <= $lastYear; $y++) {
        $axisSegments[] = [
            'label' => (string)$y,
            'template' => '1fr',
        ];
        $axisTemplateParts[] = '1fr';
    }
} elseif ($mode === 'jahr') {
    $rangeStart = new DateTimeImmutable(sprintf('%04d-01-01', $jahr));
    $rangeEnd   = new DateTimeImmutable(sprintf('%04d-12-31', $jahr));
    $rangeEndExclusive = new DateTimeImmutable(sprintf('%04d-01-01', $jahr + 1));

    for ($m = 1; $m <= 12; $m++) {
        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $jahr, $m));
        $daysInMonth = (int)$monthStart->format('t');

        $axisSegments[] = [
            'label' => $monthNames[$m],
            'template' => $daysInMonth . 'fr',
        ];
        $axisTemplateParts[] = $daysInMonth . 'fr';
    }
} else {
    if (!$selectedSemester) {
        http_response_code(500);
        die('Kein gültiges Semester verfügbar.');
    }

    $rangeStart = $selectedSemester['start'];
    $rangeEnd   = $selectedSemester['end'];
    $rangeEndExclusive = $rangeEnd->modify('+1 day');

    $cursor = $rangeStart;
    for ($i = 0; $i < 6; $i++) {
        $daysInMonth = (int)$cursor->format('t');
        $axisSegments[] = [
            'label' => $monthNames[(int)$cursor->format('n')],
            'template' => $daysInMonth . 'fr',
        ];
        $axisTemplateParts[] = $daysInMonth . 'fr';
        $cursor = $cursor->modify('first day of next month');
    }
}

$axisTemplate = implode(' ', $axisTemplateParts);

/* -------------------- Daten laden -------------------- */

$groupsById = [];
$groupChildren = [];
$entriesById = [];
$entriesByGroup = [];
$eventsByEntry = [];
$segmentsByEntry = [];

/* Gruppen */
$resG = $conn->query("
    SELECT id, parent_group_id, name, outline_no, level_no, sort_order
    FROM timeline_groups
");
if (!$resG) {
    http_response_code(500);
    die('timeline_groups konnten nicht geladen werden.');
}
while ($g = $resG->fetch_assoc()) {
    $g['id'] = (int)$g['id'];
    $g['parent_group_id'] = $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null;
    $g['level_no'] = (int)$g['level_no'];
    $g['sort_order'] = (int)$g['sort_order'];

    $groupsById[$g['id']] = $g;
    $groupChildren[$g['parent_group_id'] ?? 0][] = $g;
}

/* Entries */
$resE = $conn->query("
    SELECT id, group_id, title, start_date, end_date, outline_no, sort_order
    FROM timeline_entries
");
if (!$resE) {
    http_response_code(500);
    die('timeline_entries konnten nicht geladen werden.');
}
while ($e = $resE->fetch_assoc()) {
    $e['id'] = (int)$e['id'];
    $e['group_id'] = (int)$e['group_id'];
    $e['sort_order'] = (int)$e['sort_order'];

    $entriesById[$e['id']] = $e;
    $entriesByGroup[$e['group_id']][] = $e;
}

/* Segmente */
$resS = $conn->query("
    SELECT id, entry_id, start_date, end_date, sort_order
    FROM timeline_entry_segments
    ORDER BY entry_id ASC, sort_order ASC, start_date ASC, id ASC
");
if (!$resS) {
    http_response_code(500);
    die('timeline_entry_segments konnten nicht geladen werden.');
}
while ($s = $resS->fetch_assoc()) {
    $s['id'] = (int)$s['id'];
    $s['entry_id'] = (int)$s['entry_id'];
    $s['sort_order'] = (int)$s['sort_order'];

    $segmentsByEntry[$s['entry_id']][] = $s;
}

/* Fallback: wenn ein Entry noch gar keine Segmente hat, aus start/end eins erzeugen */
foreach ($entriesById as $entryId => $entry) {
    if (!isset($segmentsByEntry[$entryId]) || count($segmentsByEntry[$entryId]) === 0) {
        if (!empty($entry['start_date']) && !empty($entry['end_date'])) {
            $segmentsByEntry[$entryId] = [[
                'id' => 0,
                'entry_id' => (int)$entryId,
                'start_date' => $entry['start_date'],
                'end_date' => $entry['end_date'],
                'sort_order' => 1,
            ]];
        }
    }
}

/* Events im aktuellen Sichtbereich */
$stmtEv = $conn->prepare("
    SELECT id, entry_id, event_type, title, event_date, note, status_code, semester_code
    FROM special_events
    WHERE event_date >= ? AND event_date < ?
    ORDER BY event_date ASC, id ASC
");
$rangeStartSql = $rangeStart->format('Y-m-d');
$rangeEndExclusiveSql = $rangeEndExclusive->format('Y-m-d');
$stmtEv->bind_param('ss', $rangeStartSql, $rangeEndExclusiveSql);
$stmtEv->execute();
$resEv = $stmtEv->get_result();

while ($ev = $resEv->fetch_assoc()) {
    $entryId = $ev['entry_id'] !== null ? (int)$ev['entry_id'] : null;
    if ($entryId === null) {
        continue;
    }
    if (!isset($entriesById[$entryId])) {
        continue;
    }
    $eventsByEntry[$entryId][] = $ev;
}
$stmtEv->close();

/* -------------------- Sichtbarkeit bestimmen -------------------- */

$visibleEntryIds = [];
$visibleGroupIds = [];
$visibleBarsByEntry = [];

foreach ($entriesById as $entryId => $entry) {
    $bars = [];

    foreach (($segmentsByEntry[$entryId] ?? []) as $segment) {
        $segmentStart = dt($segment['start_date']);
        $segmentEnd   = dt($segment['end_date']);

        if (!$segmentStart || !$segmentEnd) {
            continue;
        }

        $bar = clipBarToScale(
            $segmentStart,
            $segmentEnd,
            $mode,
            $rangeStart,
            $rangeEnd,
            $firstYear,
            $lastYear
        );

        if ($bar !== null) {
            $bars[] = [
                'segment' => $segment,
                'bar' => $bar,
            ];
        }
    }

    if (!empty($bars) || !empty($eventsByEntry[$entryId])) {
        $visibleEntryIds[$entryId] = true;
        $visibleBarsByEntry[$entryId] = $bars;

        $gid = (int)$entry['group_id'];
        while ($gid && isset($groupsById[$gid])) {
            if (isset($visibleGroupIds[$gid])) {
                break;
            }
            $visibleGroupIds[$gid] = true;
            $gid = $groupsById[$gid]['parent_group_id'] ?? null;
        }
    }
}

/* -------------------- Zeilen flach machen -------------------- */

$rows = [];

$walk = function (int $groupId, int $depth) use (
    &$walk,
    &$rows,
    $groupsById,
    $groupChildren,
    $entriesByGroup,
    $visibleGroupIds,
    $visibleEntryIds,
    $visibleBarsByEntry,
    $entriesById,
    $eventsByEntry
) {
    if (!isset($visibleGroupIds[$groupId]) || !isset($groupsById[$groupId])) {
        return;
    }

    $group = $groupsById[$groupId];

    $rows[] = [
        'type' => 'group',
        'id' => 'group-' . $group['id'],
        'depth' => $depth,
        'label' => $group['name'],
        'group' => $group,
    ];

    $children = [];

    foreach ($groupChildren[$groupId] ?? [] as $childGroup) {
        if (isset($visibleGroupIds[(int)$childGroup['id']])) {
            $children[] = [
                'kind' => 'group',
                'outline_no' => $childGroup['outline_no'],
                'id' => (int)$childGroup['id'],
            ];
        }
    }

    foreach ($entriesByGroup[$groupId] ?? [] as $entry) {
        if (isset($visibleEntryIds[(int)$entry['id']])) {
            $children[] = [
                'kind' => 'entry',
                'outline_no' => $entry['outline_no'],
                'id' => (int)$entry['id'],
            ];
        }
    }

    usort($children, static function ($a, $b) {
        return compareOutline($a['outline_no'], $b['outline_no']);
    });

    foreach ($children as $child) {
        if ($child['kind'] === 'group') {
            $walk((int)$child['id'], $depth + 1);
            continue;
        }

        $entry = $entriesById[(int)$child['id']];

        $rows[] = [
            'type' => 'entry',
            'id' => 'entry-' . $entry['id'],
            'depth' => $depth + 1,
            'label' => $entry['title'],
            'entry' => $entry,
            'bars' => $visibleBarsByEntry[(int)$entry['id']] ?? [],
            'events' => $eventsByEntry[(int)$entry['id']] ?? [],
        ];
    }
};

$rootGroups = $groupChildren[0] ?? [];
usort($rootGroups, static function ($a, $b) {
    return compareOutline($a['outline_no'], $b['outline_no']);
});

foreach ($rootGroups as $rootGroup) {
    $walk((int)$rootGroup['id'], 0);
}

$page_title = 'Lebensplan';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';

if ($mode === 'gesamt') {
    $titleSuffix = $firstYear . '-' . $lastYear;
} elseif ($mode === 'jahr') {
    $titleSuffix = (string)$jahr;
} else {
    $titleSuffix = $selectedSemester['label'] ?? '';
}
?>

<div class="lt-page dashboard-page">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">Lebensplan <?= esc($titleSuffix) ?></span>
        </h1>

        <div class="life-controls">
            <div class="life-modewrap">
                <label for="lifeMode" class="lt-label">Modus</label>
                <select id="lifeMode" class="kategorie-select">
                    <option value="gesamt" <?= $mode === 'gesamt' ? 'selected' : '' ?>>Gesamt</option>
                    <option value="jahr" <?= $mode === 'jahr' ? 'selected' : '' ?>>Jahr</option>
                    <option value="semester" <?= $mode === 'semester' ? 'selected' : '' ?>>Semester</option>
                </select>
            </div>

            <?php if ($mode === 'jahr'): ?>
                <div class="life-sidewrap">
                    <label for="ltYear" class="lt-label">Jahr</label>
                    <select id="ltYear" class="kategorie-select">
                        <?php foreach ($yearKeys as $y): ?>
                            <option value="<?= esc($y) ?>" <?= ((int)$y === (int)$jahr) ? 'selected' : '' ?>>
                                <?= esc($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($mode === 'semester'): ?>
                <div class="life-sidewrap">
                    <label for="ltSemester" class="lt-label">Semester</label>
                    <select id="ltSemester" class="kategorie-select">
                        <?php foreach ($semesterOptions as $sem): ?>
                            <option value="<?= esc($sem['value']) ?>" <?= ($sem['value'] === $semester) ? 'selected' : '' ?>>
                                <?= esc($sem['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="lt-chart-wrap life-wrap">
        <div class="life-sticky-shell">
            <div class="life-axis-sticky">
                <div class="life-axis-scroll" id="lifeAxisScroll">
                    <div class="life-board">
                        <div class="life-axis-row">
                            <div class="life-ordinate-spacer"></div>

                            <div class="life-months" style="grid-template-columns: <?= esc($axisTemplate) ?>;">
                                <?php foreach ($axisSegments as $segment): ?>
                                    <div class="life-month-cell"><?= esc($segment['label']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="life-body-scroll" id="lifeBodyScroll">
                <div class="life-board">
                    <div class="life-rows">
                        <?php if (!$rows): ?>
                            <div class="life-empty">Für diesen Sichtbereich gibt es keine sichtbaren Einträge oder Ereignisse.</div>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php if ($row['type'] === 'group'): ?>
                                    <?php $group = $row['group']; ?>
                                    <div
                                        class="life-row life-row--group"
                                        data-row-type="group"
                                        data-group-id="<?= (int)$group['id'] ?>"
                                        data-depth="<?= (int)$row['depth'] ?>"
                                    >
                                        <div
                                            class="life-label life-label--group life-label--clickable"
                                            style="--life-depth: <?= (int)$row['depth'] ?>;"
                                        >
                                            <button
                                                type="button"
                                                class="life-group-toggle"
                                                data-group-id="<?= (int)$group['id'] ?>"
                                                aria-expanded="true"
                                                title="Gruppe ein-/ausklappen"
                                            >
                                                <span class="life-group-toggle-icon"></span>
                                            </button>

                                            <span class="life-label-text"><?= esc($row['label']) ?></span>
                                        </div>

                                        <div class="life-track">
                                            <div class="life-grid" style="grid-template-columns: <?= esc($axisTemplate) ?>;">
                                                <?php foreach ($axisSegments as $segment): ?>
                                                    <div class="life-grid-cell"></div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php
                                        $entry = $row['entry'];
                                        $groupId = (int)$entry['group_id'];
                                        $rootId = rootGroupId($groupId, $groupsById);
                                        $barColor = rootColorById($rootId);

                                        $entryTooltipParts = [
                                            $entry['title'],
                                            !empty($entry['start_date']) ? ('Von: ' . fmtDate(dt($entry['start_date']))) : null,
                                            !empty($entry['end_date']) ? ('Bis: ' . fmtDate(dt($entry['end_date']))) : null,
                                            'Gruppe: ' . ($groupsById[$groupId]['name'] ?? ''),
                                        ];

                                        $entryTooltip = implode("\n", array_filter(
                                            $entryTooltipParts,
                                            static fn($v) => $v !== null && $v !== ''
                                        ));
                                    ?>

                                    <div
                                        class="life-row life-row--entry"
                                        data-row-type="entry"
                                        data-entry-id="<?= (int)$entry['id'] ?>"
                                        data-parent-group-id="<?= (int)$groupId ?>"
                                        data-depth="<?= (int)$row['depth'] ?>"
                                    >
                                        <div
                                            class="life-label life-label--entry"
                                            style="--life-depth: <?= (int)$row['depth'] ?>;"
                                            title="<?= esc($entryTooltip) ?>"
                                        >
                                            <span class="life-label-text"><?= esc($row['label']) ?></span>
                                        </div>

                                        <div class="life-track">
                                            <div class="life-grid" style="grid-template-columns: <?= esc($axisTemplate) ?>;">
                                                <?php foreach ($axisSegments as $segment): ?>
                                                    <div class="life-grid-cell"></div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php foreach (($row['bars'] ?? []) as $barItem): ?>
                                                <?php
                                                    $bar = $barItem['bar'];
                                                    $segment = $barItem['segment'];

                                                    $segmentTooltip = implode("\n", [
                                                        $entry['title'],
                                                        'Von: ' . fmtDate(dt($segment['start_date'])),
                                                        'Bis: ' . fmtDate(dt($segment['end_date'])),
                                                        'Gruppe: ' . ($groupsById[$groupId]['name'] ?? ''),
                                                    ]);
                                                ?>
                                                <div
                                                    class="life-bar"
                                                    title="<?= esc($segmentTooltip) ?>"
                                                    style="
                                                        left: <?= number_format((float)$bar['left'], 6, '.', '') ?>%;
                                                        width: <?= number_format((float)$bar['width'], 6, '.', '') ?>%;
                                                        background-color: <?= esc($barColor) ?>;
                                                    "
                                                ></div>
                                            <?php endforeach; ?>

                                            <?php foreach ($row['events'] as $event): ?>
                                                <?php
                                                    $eventDate = dt($event['event_date']);
                                                    if (!$eventDate) {
                                                        continue;
                                                    }

                                                    $eventLeft = pointPercentInScale(
                                                        $eventDate,
                                                        $mode,
                                                        $rangeStart,
                                                        $rangeEnd,
                                                        $firstYear,
                                                        $lastYear
                                                    );

                                                    $eventTooltipParts = [
                                                        $event['title'],
                                                        'Datum: ' . fmtDate($eventDate),
                                                    ];

                                                    if ((string)$event['note'] !== '') {
                                                        $eventTooltipParts[] = 'Notiz / Note: ' . $event['note'];
                                                    }
                                                    if ((string)$event['status_code'] !== '') {
                                                        $eventTooltipParts[] = 'Status: ' . statusLabel($event['status_code']) . ' (' . $event['status_code'] . ')';
                                                    }
                                                    if ((string)$event['semester_code'] !== '') {
                                                        $eventTooltipParts[] = 'Semester: ' . $event['semester_code'];
                                                    }

                                                    $eventTooltip = implode("\n", $eventTooltipParts);
                                                    $eventClass = 'life-event life-event--' . statusCss($event['status_code']);
                                                ?>
                                                <div
                                                    class="<?= esc($eventClass) ?>"
                                                    title="<?= esc($eventTooltip) ?>"
                                                    style="left: <?= number_format($eventLeft, 6, '.', '') ?>%;"
                                                ></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const elMode = document.getElementById('lifeMode');
    const elYear = document.getElementById('ltYear');
    const elSemester = document.getElementById('ltSemester');
    const axisScroll = document.getElementById('lifeAxisScroll');
    const bodyScroll = document.getElementById('lifeBodyScroll');

    function navigateWithCurrentState() {
        const u = new URL(window.location.href);
        const mode = elMode ? elMode.value : 'gesamt';

        u.searchParams.set('modus', mode);

        if (mode === 'jahr') {
            if (elYear && elYear.value) {
                u.searchParams.set('jahr', elYear.value);
            }
            u.searchParams.delete('semester');
        } else if (mode === 'semester') {
            if (elSemester && elSemester.value) {
                u.searchParams.set('semester', elSemester.value);
            }
            u.searchParams.delete('jahr');
        } else {
            u.searchParams.delete('jahr');
            u.searchParams.delete('semester');
        }

        window.location.href = u.toString();
    }

    if (elMode) {
        elMode.addEventListener('change', navigateWithCurrentState);
    }

    if (elYear) {
        elYear.addEventListener('change', navigateWithCurrentState);
    }

    if (elSemester) {
        elSemester.addEventListener('change', navigateWithCurrentState);
    }

    if (axisScroll && bodyScroll) {
        const syncAxis = () => {
            axisScroll.scrollLeft = bodyScroll.scrollLeft;
        };

        bodyScroll.addEventListener('scroll', syncAxis, { passive: true });
        window.addEventListener('resize', syncAxis);
        syncAxis();
    }

    const storageKey = 'lifeCollapsedGroups:' + window.location.pathname;
    let collapsed = {};

    try {
        collapsed = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
    } catch (e) {
        collapsed = {};
    }

    const rows = Array.from(document.querySelectorAll('.life-row[data-depth]'));

    function saveCollapsed() {
        localStorage.setItem(storageKey, JSON.stringify(collapsed));
    }

    function setExpandedState(row, expanded) {
        const btn = row.querySelector('.life-group-toggle');
        if (btn) {
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
        row.classList.toggle('life-row--collapsed', !expanded);
    }

    function applyCollapsedState() {
        const collapseStack = [];

        rows.forEach((row) => {
            const depth = Number(row.dataset.depth || 0);

            while (collapseStack.length && depth <= collapseStack[collapseStack.length - 1]) {
                collapseStack.pop();
            }

            const hiddenByAncestor = collapseStack.length > 0;
            row.classList.toggle('life-row-hidden', hiddenByAncestor);

            if (row.dataset.rowType === 'group') {
                const gid = String(row.dataset.groupId || '');
                const isCollapsed = !!collapsed[gid];
                setExpandedState(row, !isCollapsed);

                if (!hiddenByAncestor && isCollapsed) {
                    collapseStack.push(depth);
                }
            }
        });
    }

    document.querySelectorAll('.life-label--clickable, .life-group-toggle').forEach((el) => {
        el.addEventListener('click', (ev) => {
            ev.preventDefault();
            ev.stopPropagation();

            const row = ev.currentTarget.closest('.life-row--group');
            if (!row) return;

            const gid = String(row.dataset.groupId || '');
            if (!gid) return;

            collapsed[gid] = !collapsed[gid];
            if (!collapsed[gid]) {
                delete collapsed[gid];
            }

            saveCollapsed();
            applyCollapsedState();
        });
    });

    applyCollapsedState();
})();
</script>