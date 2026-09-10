<?php

final class LifeTimelinePage
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function resolveMysqliConnection(array $names): ?mysqli
    {
        foreach ($names as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli) {
                return $GLOBALS[$name];
            }
        }

        return null;
    }

    public static function prepareLifeConnection(mysqli $conn): mysqli
    {
        $conn->set_charset('utf8mb4');
        $conn->select_db('life');

        return $conn;
    }

    public static function requireLifeConnection(array $names = ['sciconn', 'conn', 'mysqli']): mysqli
    {
        $conn = self::resolveMysqliConnection($names);

        if (!$conn instanceof mysqli) {
            http_response_code(500);
            die('Keine mysqli-Verbindung gefunden. Bitte Variable in db.php prüfen.');
        }

        return self::prepareLifeConnection($conn);
    }

    public static function runPrivatePage(): void
    {
        self::startSession();

        require_once __DIR__ . '/../auth.php';
        require_once __DIR__ . '/../db.php';

        $resolvedConn = null;

        if (isset($sciconn) && $sciconn instanceof mysqli) {
            $resolvedConn = $sciconn;
        } elseif (isset($conn) && $conn instanceof mysqli) {
            $resolvedConn = $conn;
        } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
            $resolvedConn = $mysqli;
        } else {
            $resolvedConn = self::resolveMysqliConnection(['sciconn', 'conn', 'mysqli']);
        }

        if (!$resolvedConn instanceof mysqli) {
            http_response_code(500);
            die('Keine mysqli-Verbindung gefunden. Bitte Variable in db.php prüfen.');
        }

        $conn = self::prepareLifeConnection($resolvedConn);
        $view = self::buildViewData($conn);

        $page_title = 'Studienplan';
        require_once __DIR__ . '/../head.php';
        require_once __DIR__ . '/../navbar.php';

        self::renderApp($view);
    }

    public static function renderStandaloneDocument(array $view, array $options = []): void
    {
        $title = (string)($options['title'] ?? 'Studienplan');
        $bodyStyle = (string)($options['body_style'] ?? '');
        $extraCss = (string)($options['extra_css'] ?? '');
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= self::esc($title) ?></title>
    <link rel="stylesheet" href="../FIJI.css">
    <?php if ($extraCss !== ''): ?>
        <style><?= $extraCss ?></style>
    <?php endif; ?>
</head>
<body<?= $bodyStyle !== '' ? ' style="' . self::esc($bodyStyle) . '"' : '' ?>>
<?php self::renderApp($view); ?>
</body>
</html>
<?php
    }

    public static function buildViewData(mysqli $conn): array
    {
        $minDate = null;
        $maxDate = null;

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

        $res = $conn->query("
            SELECT
                MIN(event_date) AS min_event,
                MAX(event_date) AS max_event
            FROM timeline_events
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
        if (!in_array($mode, ['gesamt', 'jahr', 'jahr_auswahl', 'semester', 'cp_jahr', 'cp_semester'], true)) {
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

        $defaultStartYear = max($firstYear, min($currentYear, $lastYear));
        $startjahr = isset($_GET['startjahr']) ? (int)$_GET['startjahr'] : $defaultStartYear;
        $endjahr = isset($_GET['endjahr']) ? (int)$_GET['endjahr'] : $lastYear;

        $startjahr = max($firstYear, min($startjahr, $lastYear));
        $endjahr = max($firstYear, min($endjahr, $lastYear));

        if ($startjahr > $endjahr) {
            [$startjahr, $endjahr] = [$endjahr, $startjahr];
        }

        $semesterOptions = self::buildSemesterOptions(self::dt($minDate), self::dt($maxDate));
        $semesterValues = array_map(static fn($item) => $item['value'], $semesterOptions);

        $semester = isset($_GET['semester']) ? trim((string)$_GET['semester']) : ($semesterOptions[0]['value'] ?? '');
        if (!in_array($semester, $semesterValues, true)) {
            $semester = $semesterOptions[0]['value'] ?? '';
        }

        $selectedSemester = self::parseSemesterValue($semester);
        if (!$selectedSemester && !empty($semesterOptions)) {
            $selectedSemester = $semesterOptions[0];
            $semester = $selectedSemester['value'];
        }

        if (self::isCpMode($mode)) {
            $cpChart = self::buildCreditpointsChartData($conn, $mode, $currentYear);

            return [
                'mode' => $mode,
                'jahr' => $jahr,
                'startjahr' => $startjahr,
                'endjahr' => $endjahr,
                'semester' => $semester,
                'yearKeys' => $yearKeys,
                'semesterOptions' => $semesterOptions,
                'titleSuffix' => $mode === 'cp_jahr' ? 'CP pro Jahr' : 'CP pro Semester',
                'cpChart' => $cpChart,
            ];
        }

        $monthNames = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

        $axisSegments = [];
        $axisTemplateParts = [];
        $scaleFirstYear = $firstYear;
        $scaleLastYear = $lastYear;

        if ($mode === 'gesamt' || $mode === 'jahr_auswahl') {
            if ($mode === 'jahr_auswahl') {
                $scaleFirstYear = $startjahr;
                $scaleLastYear = $endjahr;
            }

            $rangeStart = new DateTimeImmutable(sprintf('%04d-01-01', $scaleFirstYear));
            $rangeEnd   = new DateTimeImmutable(sprintf('%04d-12-31', $scaleLastYear));
            $rangeEndExclusive = new DateTimeImmutable(sprintf('%04d-01-01', $scaleLastYear + 1));

            for ($y = $scaleFirstYear; $y <= $scaleLastYear; $y++) {
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

        $groupsById = [];
        $groupChildren = [];
        $entriesById = [];
        $entriesByGroup = [];
        $eventsByEntry = [];
        $orphanEvents = [];
        $segmentsByEntry = [];

        $resG = $conn->query("
            SELECT id, parent_group_id, name, sort_order, color
            FROM timeline_groups
        ");
        if (!$resG) {
            http_response_code(500);
            die('timeline_groups konnten nicht geladen werden.');
        }
        while ($g = $resG->fetch_assoc()) {
            $g['id'] = (int)$g['id'];
            $g['parent_group_id'] = $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null;
            $g['sort_order'] = (int)$g['sort_order'];

            $groupsById[$g['id']] = $g;
            $groupChildren[$g['parent_group_id'] ?? 0][] = $g;
        }

        $resE = $conn->query("
            SELECT id, group_id, title, creditpoints, sort_order
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
            $e['creditpoints'] = ($e['creditpoints'] !== null && $e['creditpoints'] !== '') ? (float)$e['creditpoints'] : null;

            $entriesById[$e['id']] = $e;
            $entriesByGroup[$e['group_id']][] = $e;
        }

        $resS = $conn->query("
            SELECT id, entry_id, start_date, end_date
            FROM timeline_entry_segments
            ORDER BY entry_id ASC, start_date ASC, end_date ASC, id ASC
        ");
        if (!$resS) {
            http_response_code(500);
            die('timeline_entry_segments konnten nicht geladen werden.');
        }
        while ($s = $resS->fetch_assoc()) {
            $s['id'] = (int)$s['id'];
            $s['entry_id'] = (int)$s['entry_id'];
            $segmentsByEntry[$s['entry_id']][] = $s;
        }

        $stmtEv = $conn->prepare("
            SELECT
                ev.id,
                ev.entry_id,
                ev.event_type,
                COALESCE(NULLIF(ev.title_override, ''), te.title, 'Ereignis') AS title,
                ev.title_override,
                ev.event_date,
                ev.note,
                ev.status_code,
                ev.semester_code,
                ev.year_override,
                ev.color,
                te.creditpoints
            FROM timeline_events ev
            INNER JOIN timeline_entries te ON te.id = ev.entry_id
            WHERE ev.event_date >= ? AND ev.event_date < ?
            ORDER BY ev.event_date ASC, ev.id ASC
        ");
        $rangeStartSql = $rangeStart->format('Y-m-d');
        $rangeEndExclusiveSql = $rangeEndExclusive->format('Y-m-d');
        $stmtEv->bind_param('ss', $rangeStartSql, $rangeEndExclusiveSql);
        $stmtEv->execute();
        $resEv = $stmtEv->get_result();

        while ($ev = $resEv->fetch_assoc()) {
            $ev['id'] = (int)$ev['id'];
            $ev['event_type'] = strtolower(trim((string)$ev['event_type']));
            $entryId = $ev['entry_id'] !== null ? (int)$ev['entry_id'] : null;
            $ev['entry_id'] = $entryId;

            if ($ev['creditpoints'] !== null && $ev['creditpoints'] !== '') {
                $ev['creditpoints'] = (float)$ev['creditpoints'];
            }

            if ($entryId === null || !isset($entriesById[$entryId])) {
                $orphanEvents[] = $ev;
                continue;
            }

            $eventsByEntry[$entryId][] = $ev;
        }
        $stmtEv->close();

        $visibleEntryIds = [];
        $visibleGroupIds = [];
        $visibleBarsByEntry = [];

        foreach ($entriesById as $entryId => $entry) {
            $bars = [];

            foreach (($segmentsByEntry[$entryId] ?? []) as $segment) {
                $segmentStart = self::dt($segment['start_date']);
                $segmentEnd   = self::dt($segment['end_date']);

                if (!$segmentStart || !$segmentEnd) {
                    continue;
                }

                $bar = self::clipBarToScale(
                    $segmentStart,
                    $segmentEnd,
                    $mode,
                    $rangeStart,
                    $rangeEnd,
                    $scaleFirstYear,
                    $scaleLastYear
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

        $rows = [];

        // Nur für Altbestand ohne entry_id: bleibt sichtbar, damit er im privaten Modus
        // angeklickt und einem Eintrag zugeordnet werden kann. Sobald zugeordnet, verschwindet die Zeile.
        if (!empty($orphanEvents)) {
            $rows[] = [
                'type' => 'orphan_events',
                'id' => 'orphan-events',
                'depth' => 0,
                'label' => 'Unzugeordnete Ereignisse',
                'events' => $orphanEvents,
            ];
        }

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
                        'sort_order' => (int)$childGroup['sort_order'],
                        'id' => (int)$childGroup['id'],
                    ];
                }
            }

            foreach ($entriesByGroup[$groupId] ?? [] as $entry) {
                if (isset($visibleEntryIds[(int)$entry['id']])) {
                    $children[] = [
                        'kind' => 'entry',
                        'sort_order' => (int)$entry['sort_order'],
                        'id' => (int)$entry['id'],
                    ];
                }
            }

            usort($children, static function ($a, $b) {
                $cmp = ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
                return $cmp !== 0 ? $cmp : ((int)$a['id']) <=> ((int)$b['id']);
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
            $cmp = ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
            return $cmp !== 0 ? $cmp : ((int)$a['id']) <=> ((int)$b['id']);
        });

        foreach ($rootGroups as $rootGroup) {
            $walk((int)$rootGroup['id'], 0);
        }

        if ($mode === 'gesamt') {
            $titleSuffix = $firstYear . '-' . $lastYear;
        } elseif ($mode === 'jahr_auswahl') {
            $titleSuffix = $startjahr === $endjahr ? (string)$startjahr : ($startjahr . '-' . $endjahr);
        } elseif ($mode === 'jahr') {
            $titleSuffix = (string)$jahr;
        } else {
            $titleSuffix = $selectedSemester['label'] ?? '';
        }

        return [
            'mode' => $mode,
            'jahr' => $jahr,
            'startjahr' => $startjahr,
            'endjahr' => $endjahr,
            'semester' => $semester,
            'selectedSemester' => $selectedSemester,
            'yearKeys' => $yearKeys,
            'semesterOptions' => $semesterOptions,
            'axisSegments' => $axisSegments,
            'axisTemplate' => $axisTemplate,
            'rows' => $rows,
            'groupsById' => $groupsById,
            'titleSuffix' => $titleSuffix,
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'firstYear' => $scaleFirstYear,
            'lastYear' => $scaleLastYear,
        ];
    }

    public static function renderApp(array $view, array $options = []): void
    {
        $editable = !empty($options['editable']);
        $mode = $view['mode'];

        if (self::isCpMode($mode)) {
            self::renderCreditpointsApp($view, $editable);
            return;
        }

        $jahr = $view['jahr'];
        $startjahr = $view['startjahr'];
        $endjahr = $view['endjahr'];
        $semester = $view['semester'];
        $semesterOptions = $view['semesterOptions'];
        $yearKeys = $view['yearKeys'];
        $axisSegments = $view['axisSegments'];
        $axisTemplate = $view['axisTemplate'];
        $rows = $view['rows'];
        $groupsById = $view['groupsById'];
        $titleSuffix = $view['titleSuffix'];
        $rangeStart = $view['rangeStart'];
        $rangeEnd = $view['rangeEnd'];
        $firstYear = $view['firstYear'];
        $lastYear = $view['lastYear'];

        $today = new DateTimeImmutable('today');
        $todayLeft = null;
        if ($today >= $rangeStart && $today <= $rangeEnd) {
            $todayLeft = self::pointPercentInScale(
                $today,
                $mode,
                $rangeStart,
                $rangeEnd,
                $firstYear,
                $lastYear
            );
        }
        ?>
<style>
.life-current-day-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    transform: translateX(-1px);
    background: #dc2626;
    z-index: 1;
    pointer-events: none;
}
</style>
<div class="lt-page dashboard-page<?= $editable ? ' life-private-editable' : '' ?>">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">Studienplan <?= self::esc($titleSuffix) ?></span>
        </h1>

        <?php if ($editable): ?>
            <div class="life-edit-inline-actions" aria-label="Neues Objekt anlegen">
                <button type="button" class="life-edit-new" data-life-new-kind="group">+ Gruppe</button>
                <button type="button" class="life-edit-new" data-life-new-kind="entry">+ Eintrag</button>
                <button type="button" class="life-edit-new" data-life-new-kind="segment">+ Zeitraum</button>
                <button type="button" class="life-edit-new" data-life-new-kind="event">+ Ereignis</button>
            </div>
        <?php endif; ?>

        <div class="life-controls">
            <?php if ($mode === 'jahr'): ?>
                <div class="life-sidewrap">
                    <label for="ltYear" class="lt-label">Jahr</label>
                    <select id="ltYear" class="kategorie-select">
                        <?php foreach ($yearKeys as $y): ?>
                            <option value="<?= self::esc($y) ?>" <?= ((int)$y === (int)$jahr) ? 'selected' : '' ?>>
                                <?= self::esc($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($mode === 'jahr_auswahl'): ?>
                <div class="life-sidewrap life-sidewrap--year-range">
                    <label for="ltStartYear" class="lt-label">Startjahr</label>
                    <select id="ltStartYear" class="kategorie-select">
                        <?php foreach ($yearKeys as $y): ?>
                            <option value="<?= self::esc($y) ?>" <?= ((int)$y === (int)$startjahr) ? 'selected' : '' ?>>
                                <?= self::esc($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="life-sidewrap life-sidewrap--year-range">
                    <label for="ltEndYear" class="lt-label">Endjahr</label>
                    <select id="ltEndYear" class="kategorie-select">
                        <?php foreach ($yearKeys as $y): ?>
                            <option value="<?= self::esc($y) ?>" <?= ((int)$y === (int)$endjahr) ? 'selected' : '' ?>>
                                <?= self::esc($y) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php elseif ($mode === 'semester'): ?>
                <div class="life-sidewrap">
                    <label for="ltSemester" class="lt-label">Semester</label>
                    <select id="ltSemester" class="kategorie-select">
                        <?php foreach ($semesterOptions as $sem): ?>
                            <option value="<?= self::esc($sem['value']) ?>" <?= ($sem['value'] === $semester) ? 'selected' : '' ?>>
                                <?= self::esc($sem['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="life-modewrap">
                <label for="lifeMode" class="lt-label">Modus</label>
                <select id="lifeMode" class="kategorie-select">
                    <option value="gesamt" <?= $mode === 'gesamt' ? 'selected' : '' ?>>Gesamt</option>
                    <option value="jahr" <?= $mode === 'jahr' ? 'selected' : '' ?>>Jahr</option>
                    <option value="jahr_auswahl" <?= $mode === 'jahr_auswahl' ? 'selected' : '' ?>>Jahr (Auswahl)</option>
                    <option value="semester" <?= $mode === 'semester' ? 'selected' : '' ?>>Semester</option>
                    <option value="cp_jahr" <?= $mode === 'cp_jahr' ? 'selected' : '' ?>>CP pro Jahr</option>
                    <option value="cp_semester" <?= $mode === 'cp_semester' ? 'selected' : '' ?>>CP pro Semester</option>
                </select>
            </div>
        </div>
    </div>

    <div class="lt-chart-wrap life-wrap">
        <div class="life-sticky-shell">
            <div class="life-axis-sticky">
                <div class="life-axis-scroll" id="lifeAxisScroll">
                    <div class="life-board">
                        <div class="life-axis-row">
                            <div class="life-ordinate-spacer"></div>

                            <div class="life-months" style="position: relative; grid-template-columns: <?= self::esc($axisTemplate) ?>;">
                                <?php foreach ($axisSegments as $segment): ?>
                                    <div class="life-month-cell"><?= self::esc($segment['label']) ?></div>
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
                                            <?= $editable ? 'data-life-edit-kind="group" data-life-edit-id="' . (int)$group['id'] . '" tabindex="0" role="button"' : '' ?>
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

                                            <span class="life-label-text"><?= self::esc($row['label']) ?></span>
                                        </div>

                                        <div class="life-track">
                                            <?php if ($todayLeft !== null): ?>
                                                <div
                                                    class="life-current-day-line"
                                                    aria-hidden="true"
                                                    style="left: <?= number_format((float)$todayLeft, 6, '.', '') ?>%;"
                                                ></div>
                                            <?php endif; ?>
                                            <div class="life-grid" style="grid-template-columns: <?= self::esc($axisTemplate) ?>;">
                                                <?php foreach ($axisSegments as $segment): ?>
                                                    <div class="life-grid-cell"></div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($row['type'] === 'orphan_events'): ?>
                                    <div
                                        class="life-row life-row--entry life-row--events"
                                        data-row-type="orphan-events"
                                        data-depth="0"
                                    >
                                        <div class="life-label life-label--entry" style="--life-depth: 0;">
                                            <span class="life-label-text"><?= self::esc($row['label']) ?></span>
                                        </div>

                                        <div class="life-track">
                                            <?php if ($todayLeft !== null): ?>
                                                <div
                                                    class="life-current-day-line"
                                                    aria-hidden="true"
                                                    style="left: <?= number_format((float)$todayLeft, 6, '.', '') ?>%;"
                                                ></div>
                                            <?php endif; ?>
                                            <div class="life-grid" style="grid-template-columns: <?= self::esc($axisTemplate) ?>;">
                                                <?php foreach ($axisSegments as $segment): ?>
                                                    <div class="life-grid-cell"></div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php foreach (($row['events'] ?? []) as $event): ?>
                                                <?php self::renderTimelineEvent($event, $mode, $rangeStart, $rangeEnd, $firstYear, $lastYear, $editable); ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php
                                        $entry = $row['entry'];
                                        $groupId = (int)$entry['group_id'];
                                        $rootId = self::rootGroupId($groupId, $groupsById);
                                        $barColor = self::rootColorById($rootId, $groupsById);

                                        $entryTooltipLines = self::normalizeInfoLines([
                                            $entry['title'],
                                            /* 'Gruppe: ' . ($groupsById[$groupId]['name'] ?? ''), */
                                        ]);
                                        $entryTooltip = implode("\n", $entryTooltipLines);
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
                                            title="<?= self::esc($entryTooltip) ?>"
                                            <?= $editable ? 'data-life-edit-kind="entry" data-life-edit-id="' . (int)$entry['id'] . '" tabindex="0" role="button"' : '' ?>
                                        >
                                            <span class="life-label-text"><?= self::esc($row['label']) ?></span>
                                        </div>

                                        <div class="life-track">
                                            <?php if ($todayLeft !== null): ?>
                                                <div
                                                    class="life-current-day-line"
                                                    aria-hidden="true"
                                                    style="left: <?= number_format((float)$todayLeft, 6, '.', '') ?>%;"
                                                ></div>
                                            <?php endif; ?>
                                            <div class="life-grid" style="grid-template-columns: <?= self::esc($axisTemplate) ?>;">
                                                <?php foreach ($axisSegments as $segment): ?>
                                                    <div class="life-grid-cell"></div>
                                                <?php endforeach; ?>
                                            </div>

                                            <?php foreach (($row['bars'] ?? []) as $barItem): ?>
                                                <?php
                                                    $bar = $barItem['bar'];
                                                    $segment = $barItem['segment'];

                                                    $segmentTooltipLines = self::normalizeInfoLines([
                                                        $entry['title'],
                                                        'Von: ' . self::fmtDate(self::dt($segment['start_date'])),
                                                        'Bis: ' . self::fmtDate(self::dt($segment['end_date'])),
                                                        /* 'Gruppe: ' . ($groupsById[$groupId]['name'] ?? ''), */
                                                    ]);
                                                    $segmentTooltip = implode("\n", $segmentTooltipLines);
                                                ?>
                                                <div
                                                    class="life-bar life-interactive"
                                                    title="<?= self::esc($segmentTooltip) ?>"
                                                    data-life-info="<?= self::infoPayloadAttr($segmentTooltipLines) ?>"
                                                    <?= $editable ? 'data-life-edit-kind="segment" data-life-edit-id="' . (int)$segment['id'] . '"' : '' ?>
                                                    tabindex="0"
                                                    role="button"
                                                    aria-label="<?= self::esc('Details zu ' . $entry['title']) ?>"
                                                    style="
                                                        left: <?= number_format((float)$bar['left'], 6, '.', '') ?>%;
                                                        width: <?= number_format((float)$bar['width'], 6, '.', '') ?>%;
                                                        background-color: <?= self::esc($barColor) ?>;
                                                    "
                                                ></div>
                                            <?php endforeach; ?>

                                            <?php foreach ($row['events'] as $event): ?>
                                                <?php self::renderTimelineEvent($event, $mode, $rangeStart, $rangeEnd, $firstYear, $lastYear, $editable); ?>
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
    const elStartYear = document.getElementById('ltStartYear');
    const elEndYear = document.getElementById('ltEndYear');
    const elSemester = document.getElementById('ltSemester');
    const axisScroll = document.getElementById('lifeAxisScroll');
    const bodyScroll = document.getElementById('lifeBodyScroll');
    const lifeEditable = <?= $editable ? 'true' : 'false' ?>;

    function navigateWithCurrentState() {
        const u = new URL(window.location.href);
        const mode = elMode ? elMode.value : 'gesamt';

        u.searchParams.set('modus', mode);

        if (mode === 'jahr') {
            if (elYear && elYear.value) {
                u.searchParams.set('jahr', elYear.value);
            }
            u.searchParams.delete('startjahr');
            u.searchParams.delete('endjahr');
            u.searchParams.delete('semester');
        } else if (mode === 'jahr_auswahl') {
            if (elStartYear && elStartYear.value) {
                u.searchParams.set('startjahr', elStartYear.value);
            }
            if (elEndYear && elEndYear.value) {
                u.searchParams.set('endjahr', elEndYear.value);
            }
            u.searchParams.delete('jahr');
            u.searchParams.delete('semester');
        } else if (mode === 'semester') {
            if (elSemester && elSemester.value) {
                u.searchParams.set('semester', elSemester.value);
            }
            u.searchParams.delete('jahr');
            u.searchParams.delete('startjahr');
            u.searchParams.delete('endjahr');
        } else {
            u.searchParams.delete('jahr');
            u.searchParams.delete('startjahr');
            u.searchParams.delete('endjahr');
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

    if (elStartYear) {
        elStartYear.addEventListener('change', () => {
            if (elEndYear && Number(elStartYear.value) > Number(elEndYear.value)) {
                elEndYear.value = elStartYear.value;
            }
            navigateWithCurrentState();
        });
    }

    if (elEndYear) {
        elEndYear.addEventListener('change', () => {
            if (elStartYear && Number(elEndYear.value) < Number(elStartYear.value)) {
                elStartYear.value = elEndYear.value;
            }
            navigateWithCurrentState();
        });
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

    document.querySelectorAll(lifeEditable ? '.life-group-toggle' : '.life-label--clickable, .life-group-toggle').forEach((el) => {
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

    // In der privaten Ansicht übernimmt LifePrivate die Klicks und öffnet Edit-Modals.
    if (lifeEditable) {
        return;
    }

    const infoTargets = Array.from(document.querySelectorAll('.life-bar[data-life-info], .life-event[data-life-info]'));
    if (!infoTargets.length) {
        return;
    }

    const popover = document.createElement('div');
    popover.className = 'life-info-popover';
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-modal', 'false');
    popover.setAttribute('aria-hidden', 'true');
    popover.innerHTML = `
        <div class="life-info-popover-header">
            <div class="life-info-popover-title"></div>
            <button type="button" class="life-info-popover-close" aria-label="Info schließen">&times;</button>
        </div>
        <div class="life-info-popover-body"></div>
    `;
    document.body.appendChild(popover);

    const popoverTitle = popover.querySelector('.life-info-popover-title');
    const popoverBody = popover.querySelector('.life-info-popover-body');
    const popoverClose = popover.querySelector('.life-info-popover-close');

    let activeTrigger = null;

    function parseInfoLines(el) {
        const raw = el.dataset.lifeInfo || '[]';

        try {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                return parsed
                    .map((line) => String(line ?? '').trim())
                    .filter((line) => line !== '');
            }
        } catch (e) {
        }

        return [];
    }

    function closeInfoPopover() {
        if (activeTrigger) {
            activeTrigger.setAttribute('aria-expanded', 'false');
        }

        activeTrigger = null;
        popover.classList.remove('is-open');
        popover.setAttribute('aria-hidden', 'true');
        popover.style.left = '-9999px';
        popover.style.top = '-9999px';
        popoverTitle.textContent = '';
        popoverBody.innerHTML = '';
    }

    function positionInfoPopover(trigger) {
        const gap = 10;
        const rect = trigger.getBoundingClientRect();

        popover.style.left = '0px';
        popover.style.top = '0px';
        popover.classList.add('is-open');
        popover.setAttribute('aria-hidden', 'false');

        const popRect = popover.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        let left = rect.left + (rect.width / 2) - (popRect.width / 2);
        left = Math.max(12, Math.min(left, viewportWidth - popRect.width - 12));

        let top = rect.bottom + gap;
        if (top + popRect.height > viewportHeight - 12) {
            top = rect.top - popRect.height - gap;
        }
        if (top < 12) {
            top = Math.max(12, viewportHeight - popRect.height - 12);
        }

        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
    }

    function openInfoPopover(trigger) {
        const lines = parseInfoLines(trigger);
        if (!lines.length) {
            closeInfoPopover();
            return;
        }

        if (activeTrigger === trigger && popover.classList.contains('is-open')) {
            closeInfoPopover();
            return;
        }

        activeTrigger = trigger;

        infoTargets.forEach((el) => {
            el.setAttribute('aria-expanded', el === trigger ? 'true' : 'false');
        });

        popoverTitle.textContent = lines[0] || '';
        popoverBody.innerHTML = '';

        lines.slice(1).forEach((line) => {
            const div = document.createElement('div');
            div.className = 'life-info-popover-line';
            div.textContent = line;
            popoverBody.appendChild(div);
        });

        positionInfoPopover(trigger);
    }

    infoTargets.forEach((el) => {
        el.setAttribute('aria-expanded', 'false');

        el.addEventListener('click', (ev) => {
            ev.preventDefault();
            ev.stopPropagation();
            openInfoPopover(el);
        });

        el.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                openInfoPopover(el);
            }
        });
    });

    popoverClose.addEventListener('click', (ev) => {
        ev.preventDefault();
        closeInfoPopover();
    });

    document.addEventListener('click', (ev) => {
        const target = ev.target;

        if (target instanceof Node && (popover.contains(target) || (activeTrigger && activeTrigger.contains(target)))) {
            return;
        }

        closeInfoPopover();
    });

    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') {
            closeInfoPopover();
        }
    });

    window.addEventListener('resize', () => {
        if (activeTrigger && popover.classList.contains('is-open')) {
            positionInfoPopover(activeTrigger);
        }
    });

    window.addEventListener('scroll', () => {
        if (activeTrigger && popover.classList.contains('is-open')) {
            positionInfoPopover(activeTrigger);
        }
    }, { passive: true });

    if (bodyScroll) {
        bodyScroll.addEventListener('scroll', () => {
            if (activeTrigger && popover.classList.contains('is-open')) {
                positionInfoPopover(activeTrigger);
            }
        }, { passive: true });
    }

    if (axisScroll) {
        axisScroll.addEventListener('scroll', () => {
            if (activeTrigger && popover.classList.contains('is-open')) {
                positionInfoPopover(activeTrigger);
            }
        }, { passive: true });
    }
})();
</script>
<?php
    }

    private static function renderTimelineEvent(
        array $event,
        string $mode,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        int $firstYear,
        int $lastYear,
        bool $editable
    ): void {
        $eventDate = self::dt($event['event_date'] ?? null);
        if (!$eventDate) {
            return;
        }

        $eventType = strtolower(trim((string)($event['event_type'] ?? 'klausur')));
        $isExam = $eventType === 'klausur';

        $eventLeft = self::pointPercentInScale(
            $eventDate,
            $mode,
            $rangeStart,
            $rangeEnd,
            $firstYear,
            $lastYear
        );

        if ($isExam) {
            $eventTooltipLines = [
                $event['title'] ?? '',
                'Datum: ' . self::fmtDate($eventDate),
            ];

            if ((string)($event['note'] ?? '') !== '') {
                $eventTooltipLines[] = 'Note: ' . $event['note'];
            }
            if ((string)($event['status_code'] ?? '') !== '') {
                $statusText = self::statusLabel($event['status_code']) . ' (' . $event['status_code'] . ')';

                if (
                    strtoupper(trim((string)$event['status_code'])) === 'BE'
                    && ($event['creditpoints'] ?? null) !== null
                    && $event['creditpoints'] !== ''
                ) {
                    $statusText .= ' | ' . self::fmtCp((float)$event['creditpoints']) . ' CP';
                }

                $eventTooltipLines[] = $statusText;
            }
            if ((string)($event['semester_code'] ?? '') !== '') {
                $eventTooltipLines[] = 'Semester: ' . $event['semester_code'];
            }
            if (($event['year_override'] ?? null) !== null && $event['year_override'] !== '') {
                $eventTooltipLines[] = 'CP-Jahr: ' . (int)$event['year_override'];
            }

            $eventClass = 'life-event life-event--' . self::statusCss($event['status_code'] ?? null);
        } else {
            $eventTooltipLines = [$event['title'] ?? 'Ereignis'];
            if ((string)($event['note'] ?? '') !== '') {
                $eventTooltipLines[] = 'Notiz: ' . $event['note'];
            }
            $eventClass = 'life-event life-event--default';
        }

        $eventColor = null;
        if (!$isExam) {
            $candidateColor = trim((string)($event['color'] ?? ''));
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $candidateColor)) {
                $eventColor = $candidateColor;
            }
        }

        $eventTooltipLines = self::normalizeInfoLines($eventTooltipLines);
        $eventTooltip = implode("\n", $eventTooltipLines);
        ?>
        <div
            class="<?= self::esc($eventClass) ?> life-interactive"
            title="<?= self::esc($eventTooltip) ?>"
            data-life-info="<?= self::infoPayloadAttr($eventTooltipLines) ?>"
            <?= $editable ? 'data-life-edit-kind="event" data-life-edit-id="' . (int)$event['id'] . '"' : '' ?>
            tabindex="0"
            role="button"
            aria-label="<?= self::esc(($isExam ? 'Klausurdetails zu ' : 'Ereignisdetails zu ') . ($event['title'] ?? '')) ?>"
            style="left: <?= number_format($eventLeft, 6, '.', '') ?>%;<?= $eventColor !== null ? ' color: ' . self::esc($eventColor) . ';' : '' ?>"
        ></div>
        <?php
    }

    private static function renderCreditpointsApp(array $view, bool $editable = false): void
    {
        $mode = $view['mode'];
        $titleSuffix = $view['titleSuffix'];
        $cpChart = $view['cpChart'];
        $avgPeriodLabel = $mode === 'cp_jahr' ? 'Jahr' : 'Semester';

        $labelsJson = json_encode($cpChart['labels'], JSON_UNESCAPED_UNICODE);
        $valuesJson = json_encode($cpChart['values'], JSON_UNESCAPED_UNICODE);
        ?>
<div class="lt-page dashboard-page<?= $editable ? ' life-private-editable' : '' ?>">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">Studienplan <?= self::esc($titleSuffix) ?></span>
            <span class="dashboard-title-soft">| <?= self::fmtCp((float)$cpChart['total']) ?> CP</span>
        </h1>

        <?php if ($editable): ?>
            <div class="life-edit-inline-actions" aria-label="Neues Objekt anlegen">
                <button type="button" class="life-edit-new" data-life-new-kind="group">+ Gruppe</button>
                <button type="button" class="life-edit-new" data-life-new-kind="entry">+ Eintrag</button>
                <button type="button" class="life-edit-new" data-life-new-kind="segment">+ Zeitraum</button>
                <button type="button" class="life-edit-new" data-life-new-kind="event">+ Ereignis</button>
            </div>
        <?php endif; ?>

        <div class="life-controls">
            <div class="life-modewrap">
                <label for="lifeMode" class="lt-label">Modus</label>
                <select id="lifeMode" class="kategorie-select">
                    <option value="gesamt" <?= $mode === 'gesamt' ? 'selected' : '' ?>>Gesamt</option>
                    <option value="jahr" <?= $mode === 'jahr' ? 'selected' : '' ?>>Jahr</option>
                    <option value="jahr_auswahl" <?= $mode === 'jahr_auswahl' ? 'selected' : '' ?>>Jahr (Auswahl)</option>
                    <option value="semester" <?= $mode === 'semester' ? 'selected' : '' ?>>Semester</option>
                    <option value="cp_jahr" <?= $mode === 'cp_jahr' ? 'selected' : '' ?>>CP pro Jahr</option>
                    <option value="cp_semester" <?= $mode === 'cp_semester' ? 'selected' : '' ?>>CP pro Semester</option>
                </select>
            </div>
        </div>
    </div>

    <div class="ernährungsdiablock">
        <div class="dashboard-pies dashboard-pies--single">
            <div class="dashboard-pie-card dashboard-pie-card--full">
                <div class="dashboard-pie-kpi">
                    <span class="dashboard-pie-kpi-label">Creditpoints</span>
                    <span class="dashboard-pie-kpi-value">
                        Ø <?= self::fmtCp((float)$cpChart['avg_value']) ?> CP pro <?= self::esc($avgPeriodLabel) ?>
                    </span>
                </div>
                <div class="dashboard-pie-wrap">
                    <canvas id="lifeCpChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const elMode = document.getElementById('lifeMode');

    function navigateWithCurrentState() {
        const u = new URL(window.location.href);
        const mode = elMode ? elMode.value : 'gesamt';

        u.searchParams.set('modus', mode);
        u.searchParams.delete('jahr');
        u.searchParams.delete('startjahr');
        u.searchParams.delete('endjahr');
        u.searchParams.delete('semester');

        window.location.href = u.toString();
    }

    if (elMode) {
        elMode.addEventListener('change', navigateWithCurrentState);
    }

    const labels = <?= $labelsJson ?>;
    const values = <?= $valuesJson ?>;
    const isSemesterMode = <?= $mode === 'cp_semester' ? 'true' : 'false' ?>;

    const canvas = document.getElementById('lifeCpChart');
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    function fmtCp(value) {
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return '0';
        }

        if (Math.abs(num - Math.round(num)) < 0.00001) {
            return String(Math.round(num));
        }

        return String(num.toFixed(2)).replace(/\.?0+$/, '').replace('.', ',');
    }

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Creditpoints',
                    data: values,
                    fill: false,
                    tension: 0.25,
                    borderWidth: 3,
                    borderColor: '#111',
                    pointRadius: 4,
                    pointStyle: 'circle',
                    pointBorderColor: 'rgba(0,0,0,0.45)',
                    pointBorderWidth: 2,
                    pointBackgroundColor: '#fff',
                    spanGaps: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label(context) {
                            const value = (context.parsed && typeof context.parsed.y === 'number')
                                ? context.parsed.y
                                : 0;
                            return fmtCp(value) + ' CP';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'category',
                    offset: true,
                    grid: {
                        offset: true
                    },
                    ticks: {
                        autoSkip: isSemesterMode ? false : (labels.length > 18),
                        maxTicksLimit: isSemesterMode ? undefined : 18,
                        maxRotation: 0,
                        minRotation: 0,
                        padding: 8,
                        callback(value) {
                            const label = this.getLabelForValue(value);

                            if (!isSemesterMode) {
                                return label;
                            }

                            if (typeof label === 'string' && label.startsWith('SS')) {
                                return ['', label];
                            }

                            return [label, ''];
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 5,
                        callback(value) {
                            return fmtCp(value) + ' CP';
                        }
                    }
                }
            }
        }
    });
})();
</script>
<?php
    }

    private static function isCpMode(string $mode): bool
    {
        return in_array($mode, ['cp_jahr', 'cp_semester'], true);
    }

    private static function buildCreditpointsChartData(mysqli $conn, string $mode, int $fallbackYear): array
    {
        $events = self::fetchPassedCreditEvents($conn);

        if ($mode === 'cp_semester') {
            return self::buildCpSemesterSeriesFromEvents($events, $fallbackYear);
        }

        return self::buildCpYearSeriesFromEvents($events, $fallbackYear);
    }

    private static function fetchPassedCreditEvents(mysqli $conn): array
    {
        $events = [];
        $res = $conn->query("
            SELECT
                ev.event_date,
                COALESCE(NULLIF(ev.title_override, ''), te.title) AS title,
                ev.semester_code,
                ev.year_override,
                te.creditpoints
            FROM timeline_events ev
            INNER JOIN timeline_entries te ON te.id = ev.entry_id
            WHERE te.creditpoints IS NOT NULL
              AND ev.event_type = 'klausur'
              AND UPPER(TRIM(COALESCE(ev.status_code, ''))) = 'BE'
            ORDER BY ev.event_date ASC, ev.id ASC
        ");

        if (!$res) {
            return $events;
        }

        while ($row = $res->fetch_assoc()) {
            $date = self::dt($row['event_date']);
            if (!$date) {
                continue;
            }

            $events[] = [
                'date' => $date,
                'title' => (string)($row['title'] ?? ''),
                'semester_code' => (string)($row['semester_code'] ?? ''),
                'year_override' => ($row['year_override'] !== null && $row['year_override'] !== '') ? (int)$row['year_override'] : null,
                'semester_label' => self::resolveSemesterLabelForCreditEvent(
                    $date,
                    (string)($row['title'] ?? ''),
                    (string)($row['semester_code'] ?? '')
                ),
                'creditpoints' => (float)$row['creditpoints'],
            ];
        }

        return $events;
    }

    private static function buildCpYearSeriesFromEvents(array $events, int $fallbackYear): array
    {
        if (count($events) === 0) {
            return [
                'labels' => [(string)$fallbackYear],
                'values' => [0.0],
                'total' => 0.0,
                'avg_value' => 0.0,
                'max_value' => 0.0,
                'max_label' => '',
            ];
        }

        $effectiveYears = array_map(
            static fn(array $event): int => $event['year_override'] !== null
                ? (int)$event['year_override']
                : (int)$event['date']->format('Y'),
            $events
        );
        $firstYear = min($effectiveYears);
        $lastYear  = max($effectiveYears);

        $sumByYear = [];
        foreach ($events as $event) {
            $year = $event['year_override'] !== null
                ? (int)$event['year_override']
                : (int)$event['date']->format('Y');
            if (!isset($sumByYear[$year])) {
                $sumByYear[$year] = 0.0;
            }
            $sumByYear[$year] += (float)$event['creditpoints'];
        }

        $labels = [];
        $values = [];
        $total = 0.0;
        $maxValue = 0.0;
        $maxLabel = '';

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $value = (float)($sumByYear[$year] ?? 0.0);
            $labels[] = (string)$year;
            $values[] = $value;
            $total += $value;

            if ($value > $maxValue) {
                $maxValue = $value;
                $maxLabel = (string)$year;
            }
        }

        $avgValue = count($values) > 0 ? ($total / count($values)) : 0.0;

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
            'avg_value' => $avgValue,
            'max_value' => $maxValue,
            'max_label' => $maxLabel,
        ];
    }

    private static function buildCpSemesterSeriesFromEvents(array $events, int $fallbackYear): array
    {
        if (count($events) === 0) {
            $fallbackDate = new DateTimeImmutable(sprintf('%04d-01-01', $fallbackYear));
            $fallbackLabel = self::semesterLabelForDate($fallbackDate);

            return [
                'labels' => [$fallbackLabel],
                'values' => [0.0],
                'total' => 0.0,
                'avg_value' => 0.0,
                'max_value' => 0.0,
                'max_label' => '',
            ];
        }

        $firstSemesterValue = self::semesterValueFromLabel((string)$events[0]['semester_label']);
        $lastSemesterValue  = self::semesterValueFromLabel((string)$events[count($events) - 1]['semester_label']);

        $minDate = $firstSemesterValue['start'] ?? $events[0]['date'];
        $maxDate = $lastSemesterValue['end'] ?? $events[count($events) - 1]['date'];

        $semesterItems = self::buildSemesterOptions($minDate, $maxDate);
        usort($semesterItems, static function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $sumBySemester = [];
        foreach ($events as $event) {
            $label = (string)$event['semester_label'];
            if (!isset($sumBySemester[$label])) {
                $sumBySemester[$label] = 0.0;
            }
            $sumBySemester[$label] += (float)$event['creditpoints'];
        }

        $labels = [];
        $values = [];
        $total = 0.0;
        $maxValue = 0.0;
        $maxLabel = '';

        foreach ($semesterItems as $semesterItem) {
            $label = (string)$semesterItem['label'];
            $value = (float)($sumBySemester[$label] ?? 0.0);

            $labels[] = $label;
            $values[] = $value;
            $total += $value;

            if ($value > $maxValue) {
                $maxValue = $value;
                $maxLabel = $label;
            }
        }

        $avgValue = count($values) > 0 ? ($total / count($values)) : 0.0;

        return [
            'labels' => $labels,
            'values' => $values,
            'total' => $total,
            'avg_value' => $avgValue,
            'max_value' => $maxValue,
            'max_label' => $maxLabel,
        ];
    }

    private static function resolveSemesterLabelForCreditEvent(
        DateTimeImmutable $date,
        string $title = '',
        string $semesterCode = ''
    ): string {
        $normalizedSemesterCode = self::normalizeSemesterCode($semesterCode);
        if ($normalizedSemesterCode !== null) {
            return $normalizedSemesterCode;
        }

        if (self::isCoronaOverrideHoema3Ws2021($date, $title)) {
            return 'WS20/21';
        }

        return self::semesterLabelForDate($date);
    }

    private static function normalizeSemesterCode(string $semesterCode): ?string
    {
        $semesterCode = strtoupper(trim($semesterCode));
        $semesterCode = preg_replace('/\s+/', '', $semesterCode);

        if ($semesterCode === null || $semesterCode === '') {
            return null;
        }

        if (preg_match('/^SS(\d{2})$/', $semesterCode, $m)) {
            return self::formatSemesterLabel('S', 2000 + (int)$m[1]);
        }

        if (preg_match('/^WS(\d{2})\/(\d{2})$/', $semesterCode, $m)) {
            return self::formatSemesterLabel('W', 2000 + (int)$m[1]);
        }

        if (preg_match('/^WS(\d{2})$/', $semesterCode, $m)) {
            return self::formatSemesterLabel('W', 2000 + (int)$m[1]);
        }

        return null;
    }

    private static function isCoronaOverrideHoema3Ws2021(DateTimeImmutable $date, string $title): bool
    {
        if ($date->format('Y-m-d') !== '2021-04-22') {
            return false;
        }

        return preg_match('/h(?:ö|oe|o)ma\s*iii/iu', $title) === 1;
    }

    private static function semesterValueFromLabel(string $label): ?array
    {
        return self::parseSemesterValue(trim($label));
    }

    private static function semesterLabelForDate(DateTimeImmutable $date): string
    {
        if ($date->format('Y-m-d') === '2021-04-22') {
            return 'WS20/21';
        }

        $month = (int)$date->format('n');
        $year = (int)$date->format('Y');

        if ($month >= 4 && $month <= 9) {
            return self::formatSemesterLabel('S', $year);
        }

        if ($month >= 10) {
            return self::formatSemesterLabel('W', $year);
        }

        return self::formatSemesterLabel('W', $year - 1);
    }

    private static function dt(?string $date): ?DateTimeImmutable
    {
        if ($date === null || $date === '') {
            return null;
        }

        return new DateTimeImmutable($date);
    }

    private static function fmtDate(?DateTimeImmutable $d): string
    {
        return $d ? $d->format('d.m.Y') : '';
    }

    private static function fmtCp(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return (string)(int)round($value);
        }

        return str_replace('.', ',', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
    }

    private static function normalizeInfoLines(array $parts): array
    {
        $lines = [];

        foreach ($parts as $part) {
            if ($part === null) {
                continue;
            }

            $line = trim((string)$part);
            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return array_values($lines);
    }

    private static function infoPayloadAttr(array $parts): string
    {
        return self::esc((string)json_encode(
            self::normalizeInfoLines($parts),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private static function outlineParts(string $outline): array
    {
        $parts = preg_split('/\./', $outline);

        return array_map(static fn($p) => (int)$p, $parts ?: []);
    }

    private static function compareOutline(string $a, string $b): int
    {
        $aa = self::outlineParts($a);
        $bb = self::outlineParts($b);
        $len = max(count($aa), count($bb));

        for ($i = 0; $i < $len; $i++) {
            $av = $aa[$i] ?? -1;
            $bv = $bb[$i] ?? -1;

            if ($av < $bv) {
                return -1;
            }
            if ($av > $bv) {
                return 1;
            }
        }

        return 0;
    }

    private static function statusLabel(?string $status): string
    {
        $map = [
            'BE' => 'Bestanden',
            'NB' => 'Nicht bestanden',
            'Q'  => 'Attest / keine Beurteilung',
            'X'  => 'Nicht erschienen',
            'AN' => 'Angemeldet',
        ];
        $s = strtoupper(trim((string)$status));

        return $map[$s] ?? ($s !== '' ? $s : 'Ohne Status');
    }

    private static function statusCss(?string $status): string
    {
        $s = strtolower(trim((string)$status));

        return preg_replace('/[^a-z0-9_-]/', '', $s) ?: 'default';
    }

    private static function rootGroupId(int $groupId, array $groupsById): int
    {
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

    private static function rootColorById(int $rootId, array $groupsById): string
    {
        $color = trim((string)($groupsById[$rootId]['color'] ?? ''));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return $color;
        }

        return '#64748b';
    }

    private static function daysInYear(DateTimeImmutable $date): int
    {
        return ((int)$date->format('L') === 1) ? 366 : 365;
    }

    private static function formatSemesterLabel(string $season, int $year): string
    {
        if ($season === 'S') {
            return sprintf('SS%02d', $year % 100);
        }

        return sprintf('WS%02d/%02d', $year % 100, ($year + 1) % 100);
    }

    private static function parseSemesterValue(string $value): ?array
    {
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

    private static function buildSemesterOptions(?DateTimeImmutable $minDate, ?DateTimeImmutable $maxDate): array
    {
        if (!$minDate || !$maxDate) {
            $now = new DateTimeImmutable('today');
            $year = (int)$now->format('Y');
            $month = (int)$now->format('n');

            if ($month >= 10) {
                return [[
                    'value' => self::formatSemesterLabel('W', $year),
                    'label' => self::formatSemesterLabel('W', $year),
                    'season' => 'W',
                    'year' => $year,
                    'start' => new DateTimeImmutable(sprintf('%04d-10-01', $year)),
                    'end' => new DateTimeImmutable(sprintf('%04d-03-31', $year + 1)),
                ]];
            }

            if ($month >= 4) {
                return [[
                    'value' => self::formatSemesterLabel('S', $year),
                    'label' => self::formatSemesterLabel('S', $year),
                    'season' => 'S',
                    'year' => $year,
                    'start' => new DateTimeImmutable(sprintf('%04d-04-01', $year)),
                    'end' => new DateTimeImmutable(sprintf('%04d-09-30', $year)),
                ]];
            }

            return [[
                'value' => self::formatSemesterLabel('W', $year - 1),
                'label' => self::formatSemesterLabel('W', $year - 1),
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
                $label = self::formatSemesterLabel('S', $y);
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
                $label = self::formatSemesterLabel('W', $y);
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

    private static function clipBarToScale(
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

        if ($mode === 'gesamt' || $mode === 'jahr_auswahl') {
            $totalYears = max(1, ($lastYear - $firstYear + 1));

            $startYear = (int)$visibleStart->format('Y');
            $endYear   = (int)$visibleEnd->format('Y');

            $startOffset = ($startYear - $firstYear)
                + ((int)$visibleStart->format('z') / self::daysInYear($visibleStart));

            $endOffset = ($endYear - $firstYear)
                + (((int)$visibleEnd->format('z') + 1) / self::daysInYear($visibleEnd));

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

    private static function pointPercentInScale(
        DateTimeImmutable $date,
        string $mode,
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        int $firstYear,
        int $lastYear
    ): float {
        if ($mode === 'gesamt' || $mode === 'jahr_auswahl') {
            $totalYears = max(1, ($lastYear - $firstYear + 1));
            $year = (int)$date->format('Y');
            $offset = ($year - $firstYear)
                + (((int)$date->format('z') + 0.5) / self::daysInYear($date));

            return ($offset / $totalYears) * 100;
        }

        $totalDays = max(1, (int)$rangeStart->diff($rangeEnd)->days + 1);
        $offset = (int)$rangeStart->diff($date)->days;

        return (($offset + 0.5) / $totalDays) * 100;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    LifeTimelinePage::runPrivatePage();
}