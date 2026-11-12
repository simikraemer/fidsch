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
        $bodyClass = trim((string)($options['body_class'] ?? ''));
        $extraCss = (string)($options['extra_css'] ?? '');

        $fullCssPath = dirname(__DIR__) . '/FIJI_FullHD.css';
        $mobileCssPath = dirname(__DIR__) . '/FIJI_Mobile.css';
        $fullCssVersion = is_file($fullCssPath) ? (string)filemtime($fullCssPath) : '';
        $mobileCssVersion = is_file($mobileCssPath) ? (string)filemtime($mobileCssPath) : '';
        ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= self::esc($title) ?></title>
    <link rel="stylesheet" href="../FIJI_FullHD.css<?= $fullCssVersion !== '' ? '?v=' . self::esc($fullCssVersion) : '' ?>">
    <link rel="stylesheet" href="../FIJI_Mobile.css<?= $mobileCssVersion !== '' ? '?v=' . self::esc($mobileCssVersion) : '' ?>">
    <?php if ($extraCss !== ''): ?>
        <style><?= $extraCss ?></style>
    <?php endif; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . self::esc($bodyClass) . '"' : '' ?><?= $bodyStyle !== '' ? ' style="' . self::esc($bodyStyle) . '"' : '' ?>>
<?php self::renderApp($view); ?>
</body>
</html>
<?php
    }

    /**
     * Nur öffentliche Root-Gruppen und deren durchgehend öffentliche Nachfahren.
     * Der Filter läuft in SQL: Private Datensätze werden für LifePublic nicht abgefragt.
     */
    private static function publicGroupsCte(): string
    {
        return "WITH RECURSIVE visible_groups AS (
            SELECT id
            FROM timeline_groups
            WHERE parent_group_id IS NULL AND visibility = 'public'
            UNION ALL
            SELECT child.id
            FROM timeline_groups child
            INNER JOIN visible_groups parent ON parent.id = child.parent_group_id
            WHERE child.visibility = 'public'
        )";
    }

    public static function buildViewData(mysqli $conn, bool $publicOnly = false): array
    {
        $minDate = null;
        $maxDate = null;

        $res = $conn->query($publicOnly
            ? self::publicGroupsCte() . "
                SELECT MIN(s.start_date) AS min_start,
                       MAX(s.end_date) AS max_end
                FROM timeline_entry_segments s
                INNER JOIN timeline_entries e ON e.id = s.entry_id
                INNER JOIN visible_groups g ON g.id = e.group_id
            "
            : "
                SELECT MIN(start_date) AS min_start,
                       MAX(end_date) AS max_end
                FROM timeline_entry_segments
            ");
        if ($res && ($row = $res->fetch_assoc())) {
            $minDate = $row['min_start'] ?: null;
            $maxDate = $row['max_end'] ?: null;
        }

        $res = $conn->query($publicOnly
            ? self::publicGroupsCte() . "
                SELECT MIN(ev.event_date) AS min_event,
                       MAX(ev.event_date) AS max_event
                FROM timeline_events ev
                INNER JOIN timeline_entries e ON e.id = ev.entry_id
                INNER JOIN visible_groups g ON g.id = e.group_id
            "
            : "
                SELECT MIN(event_date) AS min_event,
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
            $cpChart = self::buildCreditpointsChartData($conn, $mode, $currentYear, $publicOnly);
            $cpGroupOptions = self::buildCpGroupOptions($conn, $publicOnly);
            $cpView = isset($_GET['cp_ansicht']) ? trim((string)$_GET['cp_ansicht']) : 'standard';
            $cpGroupId = null;
            if (preg_match('/^group:([1-9][0-9]*)$/', $cpView, $match)) {
                $candidate = (int)$match[1];
                if (isset($cpGroupOptions[$candidate])) {
                    $cpGroupId = $candidate;
                } else {
                    $cpView = 'standard';
                }
            } elseif (!in_array($cpView, ['standard', 'module'], true)) {
                $cpView = 'standard';
            }
            $cpGroupTimeline = $cpGroupId !== null
                ? self::buildCpGroupTimeline($conn, $cpGroupId, $cpGroupOptions, $cpChart['labels'], $mode, $publicOnly)
                : null;

            return [
                'mode' => $mode,
                'cpView' => $cpView,
                'cpGroupOptions' => $cpGroupOptions,
                'cpGroupTimeline' => $cpGroupTimeline,
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

        $resG = $conn->query($publicOnly
            ? self::publicGroupsCte() . "
                SELECT g.id, g.parent_group_id, g.name, g.sort_order, g.color
                FROM timeline_groups g
                INNER JOIN visible_groups vg ON vg.id = g.id
            "
            : "
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

        $resE = $conn->query($publicOnly
            ? self::publicGroupsCte() . "
                SELECT e.id, e.group_id, e.title, e.creditpoints, e.sort_order
                FROM timeline_entries e
                INNER JOIN visible_groups vg ON vg.id = e.group_id
            "
            : "
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

        $resS = $conn->query($publicOnly
            ? self::publicGroupsCte() . "
                SELECT s.id, s.entry_id, s.start_date, s.end_date
                FROM timeline_entry_segments s
                INNER JOIN timeline_entries e ON e.id = s.entry_id
                INNER JOIN visible_groups vg ON vg.id = e.group_id
                ORDER BY s.entry_id ASC, s.start_date ASC, s.end_date ASC, s.id ASC
            "
            : "
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

        $eventSelectSql = "
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
        ";
        if ($publicOnly) {
            $eventSelectSql = self::publicGroupsCte() . $eventSelectSql
                . " INNER JOIN visible_groups vg ON vg.id = te.group_id ";
        }
        $eventSelectSql .= "
            WHERE ev.event_date >= ? AND ev.event_date < ?
            ORDER BY ev.event_date ASC, ev.id ASC
        ";
        $stmtEv = $conn->prepare($eventSelectSql);
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

        // Horizontale Mindestbreite für iOS (wird per CSS-Variable eingespeist)
        $mobileAxisCount = max(1, count($axisSegments));
        if ($mode === 'gesamt' || $mode === 'jahr_auswahl') {
            $mobileTrackWidth = max(900, min(3000, $mobileAxisCount * 450));
        } elseif ($mode === 'jahr') {
            $mobileTrackWidth = 1100;
        } else {
            $mobileTrackWidth = 850;
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
<div class="lt-page dashboard-page life-page life-page--timeline<?= $editable ? ' life-private-editable' : '' ?>" data-life-mode="<?= self::esc($mode) ?>" style="--life-mobile-track-width: <?= (int)$mobileTrackWidth ?>px; --life-axis-count: <?= max(1, count($axisSegments)) ?>;<?= $todayLeft !== null ? ' --life-today-left: ' . number_format((float)$todayLeft, 6, '.', '') . '%;' : '' ?>">
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
        let isSyncing = false;
        const syncAxis = () => {
            if (!isSyncing) {
                window.requestAnimationFrame(() => {
                    axisScroll.scrollLeft = bodyScroll.scrollLeft;
                    isSyncing = false;
                });
                isSyncing = true;
            }
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
        $cpView = $view['cpView'] ?? 'standard';
        $showModules = ($cpView === 'module');
        $cpGroupTimeline = $view['cpGroupTimeline'] ?? null;
        $showGroupTimeline = is_array($cpGroupTimeline);
        $denseModules = count($cpChart['labels']) > 14;
        $avgPeriodLabel = $mode === 'cp_jahr' ? 'Jahr' : 'Semester';

        $labelsJson = json_encode($cpChart['labels'], JSON_UNESCAPED_UNICODE);
        $valuesJson = json_encode($cpChart['values'], JSON_UNESCAPED_UNICODE);
        ?>
<div class="lt-page dashboard-page life-page life-page--cp<?= $editable ? ' life-private-editable' : '' ?>" data-life-mode="<?= self::esc($mode) ?>">
    <div class="lt-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">Studienplan <?= self::esc($titleSuffix) ?></span>
            <span class="dashboard-title-soft"><span class="life-title-sep">| </span><?= self::fmtCp((float)$cpChart['total']) ?> CP</span>
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
            <div class="life-sidewrap life-sidewrap--cp-view">
                <label for="lifeCpView" class="lt-label">Ansicht</label>
                <select id="lifeCpView" class="kategorie-select">
                    <option value="standard" <?= $cpView === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="module" <?= $cpView === 'module' ? 'selected' : '' ?>>Module</option>
                    <?php foreach (($view['cpGroupOptions'] ?? []) as $groupOption): ?>
                        <option value="group:<?= (int)$groupOption['id'] ?>" <?= $cpView === 'group:' . $groupOption['id'] ? 'selected' : '' ?>><?= self::esc($groupOption['path']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                <?php if ($showModules || $showGroupTimeline): ?>
                    <div class="life-cp-chart-scroll" id="lifeCpChartScroll">
                        <div class="dashboard-pie-wrap life-cp-chart-stack <?= $denseModules ? 'life-cp-chart-stack--dense' : '' ?><?= $showGroupTimeline ? ' life-cp-chart-stack--group' : '' ?>" style="--life-cp-periods: <?= count($cpChart['labels']) ?>;">
                            <div class="life-cp-plot">
                                <canvas id="lifeCpChart"></canvas>
                            </div>
                            <?php if ($showModules): ?>
                            <!-- Die Beschriftung gehört zur selben Diagrammachse:
                                 exakt eine Spalte je x-Achsenposition, keine zweite Übersicht. -->
                            <div class="life-cp-module-grid" id="lifeCpModules" aria-label="Bestandene Prüfungen unter dem jeweiligen <?= self::esc($avgPeriodLabel) ?>">
                                <?php foreach ($cpChart['labels'] as $index => $periodLabel): ?>
                                    <div class="life-cp-module-column" lang="de" aria-label="<?= self::esc($periodLabel) ?>">
                                        <?php if (!empty($cpChart['modules'][$index])): ?>
                                            <ul class="life-cp-module-list">
                                                <?php foreach ($cpChart['modules'][$index] as $module): ?>
                                                    <li><strong class="life-cp-module-points"><?= self::esc(self::fmtCp((float)$module['creditpoints'])) ?>CP</strong> <?= self::esc($module['title']) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($showGroupTimeline): ?>
                                <?php self::renderCpGroupTimeline($cpGroupTimeline, $editable, count($cpChart['labels'])); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="dashboard-pie-wrap">
                        <canvas id="lifeCpChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const elMode = document.getElementById('lifeMode');
    const elCpView = document.getElementById('lifeCpView');

    function navigateWithCurrentState() {
        const u = new URL(window.location.href);
        const mode = elMode ? elMode.value : 'gesamt';

        u.searchParams.set('modus', mode);
        u.searchParams.delete('jahr');
        u.searchParams.delete('startjahr');
        u.searchParams.delete('endjahr');
        u.searchParams.delete('semester');

        if (mode === 'cp_jahr' || mode === 'cp_semester') {
            u.searchParams.set('cp_ansicht', elCpView ? elCpView.value : 'standard');
        } else {
            u.searchParams.delete('cp_ansicht');
        }

        window.location.href = u.toString();
    }

    if (elMode) {
        elMode.addEventListener('change', navigateWithCurrentState);
    }
    if (elCpView) {
        elCpView.addEventListener('change', navigateWithCurrentState);
    }

    const labels = <?= $labelsJson ?>;
    const values = <?= $valuesJson ?>;
    const isSemesterMode = <?= $mode === 'cp_semester' ? 'true' : 'false' ?>;
    const isPhoneChart = !!(
        window.matchMedia
        && window.matchMedia('(max-width: 650px)').matches
    );

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

    const modules = document.getElementById('lifeCpModules');
    const groupTimeline = document.getElementById('lifeCpGroupTimeline');
    const alignToChart = modules || groupTimeline;
    const moduleChartAlign = {
        id: 'lifeCpModuleAlign',
        afterLayout(chart) {
            if (!alignToChart) return;
            // x.offset=true: Der Abstand eines Punktes zur Plotkante entspricht
            // der halben Spaltenbreite. Plotbreite und Modul-Raster sind identisch.
            const scale = chart.scales.x;
            if (!scale) return;
            const left = Math.max(0, scale.left).toFixed(2) + 'px';
            const right = Math.max(0, chart.width - scale.right).toFixed(2) + 'px';
            if (modules) {
                modules.style.setProperty('--life-cp-axis-left', left);
                modules.style.setProperty('--life-cp-axis-right', right);
            }
            if (groupTimeline) {
                groupTimeline.style.setProperty('--life-cp-axis-left', left);
                groupTimeline.style.setProperty('--life-cp-axis-right', right);
            }
        }
    };

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        plugins: [moduleChartAlign],
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
                    pointRadius: isPhoneChart ? 3 : 4,
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
            layout: {
                // Platz für die Eintragsnamen links von der gemeinsamen Jahresachse.
                padding: { left: groupTimeline ? (isPhoneChart ? 70 : 180) : 0 }
            },
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
                        autoSkip: modules ? false : (isPhoneChart ? true : (isSemesterMode ? false : (labels.length > 18))),
                        maxTicksLimit: modules ? undefined : (isPhoneChart ? 8 : (isSemesterMode ? undefined : 18)),
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

    // In der öffentlichen Ansicht dieselben Detail-Popover wie in "Gesamt".
    // Im privaten Editor übernimmt weiterhin die zentrale Bearbeitungsfunktion.
    if (groupTimeline && !<?= $editable ? 'true' : 'false' ?>) {
        const targets = Array.from(groupTimeline.querySelectorAll('.life-bar[data-life-info], .life-event[data-life-info]'));
        if (targets.length) {
            const popover = document.createElement('div');
            popover.className = 'life-info-popover';
            popover.setAttribute('role', 'dialog');
            popover.setAttribute('aria-modal', 'false');
            popover.setAttribute('aria-hidden', 'true');
            popover.innerHTML = `<div class="life-info-popover-header"><div class="life-info-popover-title"></div><button type="button" class="life-info-popover-close" aria-label="Info schließen">&times;</button></div><div class="life-info-popover-body"></div>`;
            document.body.appendChild(popover);
            const title = popover.querySelector('.life-info-popover-title');
            const body = popover.querySelector('.life-info-popover-body');
            let active = null;
            const close = () => {
                if (active) active.setAttribute('aria-expanded', 'false');
                active = null;
                popover.classList.remove('is-open');
                popover.setAttribute('aria-hidden', 'true');
            };
            const position = () => {
                if (!active) return;
                const anchor = active.getBoundingClientRect();
                const size = popover.getBoundingClientRect();
                const left = Math.max(12, Math.min(anchor.left + anchor.width / 2 - size.width / 2, window.innerWidth - size.width - 12));
                let top = anchor.bottom + 10;
                if (top + size.height > window.innerHeight - 12) top = anchor.top - size.height - 10;
                popover.style.left = `${left}px`;
                popover.style.top = `${Math.max(12, top)}px`;
            };
            const open = (target) => {
                if (active === target) { close(); return; }
                let lines = [];
                try { lines = JSON.parse(target.dataset.lifeInfo || '[]'); } catch (_) {}
                if (!Array.isArray(lines) || !lines.length) return;
                close();
                active = target;
                target.setAttribute('aria-expanded', 'true');
                title.textContent = String(lines[0] ?? '');
                body.replaceChildren();
                lines.slice(1).forEach((text) => {
                    const line = document.createElement('div');
                    line.className = 'life-info-popover-line';
                    line.textContent = String(text ?? '');
                    body.appendChild(line);
                });
                popover.classList.add('is-open');
                popover.setAttribute('aria-hidden', 'false');
                position();
            };
            targets.forEach((target) => {
                target.addEventListener('click', (e) => { e.stopPropagation(); open(target); });
                target.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(target); }
                });
            });
            popover.querySelector('.life-info-popover-close').addEventListener('click', close);
            document.addEventListener('click', (e) => {
                if (!(e.target instanceof Node) || !popover.contains(e.target)) close();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
            window.addEventListener('resize', position);
            window.addEventListener('scroll', position, { passive: true });
        }
    }
})();
</script>
<?php
    }

    /**
     * Gruppen für das CP-Ansicht-Dropdown, in derselben Hierarchie wie "Gesamt".
     * In der öffentlichen Ansicht werden private Gruppen (und deren Kinder)
     * schon in der SQL-Abfrage ausgeschlossen.
     */
    private static function buildCpGroupOptions(mysqli $conn, bool $publicOnly): array
    {
        $sql = $publicOnly
            ? self::publicGroupsCte() . "
                SELECT g.id, g.parent_group_id, g.name, g.sort_order, g.color
                FROM timeline_groups g
                INNER JOIN visible_groups vg ON vg.id = g.id
            "
            : 'SELECT id, parent_group_id, name, sort_order, color FROM timeline_groups';
        $result = $conn->query($sql);
        if (!$result) {
            throw new RuntimeException('CP-Gruppen konnten nicht geladen werden.');
        }

        $byId = [];
        $children = [];
        while ($group = $result->fetch_assoc()) {
            $group['id'] = (int)$group['id'];
            $group['parent_group_id'] = $group['parent_group_id'] !== null ? (int)$group['parent_group_id'] : null;
            $group['sort_order'] = (int)$group['sort_order'];
            $byId[$group['id']] = $group;
            $children[$group['parent_group_id'] ?? 0][] = $group['id'];
        }
        foreach ($children as &$ids) {
            usort($ids, static function ($a, $b) use ($byId) {
                return ($byId[$a]['sort_order'] <=> $byId[$b]['sort_order']) ?: ($a <=> $b);
            });
        }
        unset($ids);

        $options = [];
        $walk = function (int $id, string $prefix, int $depth) use (&$walk, &$options, $byId, $children): void {
            if (!isset($byId[$id]) || $depth > 40 || isset($options[$id])) {
                return;
            }
            $group = $byId[$id];
            $group['path'] = $prefix . $group['name'];
            $group['depth'] = $depth;
            $options[$id] = $group;
            foreach (($children[$id] ?? []) as $childId) {
                $walk($childId, $group['path'] . ' › ', $depth + 1);
            }
        };
        foreach (($children[0] ?? []) as $rootId) {
            $walk($rootId, '', 0);
        }
        return $options;
    }

    /** Ordnet einen konkreten Tag exakt der Kategorie (Jahr/Semester) der CP-Achse zu. */
    private static function cpGroupDateOffset(DateTimeImmutable $date, array $periods, float $withinDay = 0): ?float
    {
        foreach ($periods as $index => $period) {
            if ($date >= $period['start'] && $date <= $period['end']) {
                $days = max(1, (int)$period['start']->diff($period['end'])->days + 1);
                $elapsed = (int)$period['start']->diff($date)->days;
                return $index + ($elapsed + $withinDay) / $days;
            }
        }
        if ($periods && $date == end($periods)['end']->modify('+1 day')) {
            return (float)count($periods);
        }
        return null;
    }

    /**
     * Daten eines einzigen Gruppenteilbaums, einschließlich seiner Untergruppen.
     * Die Abfrage liest keine privaten Nachfahren in LifePublic.
     */
    private static function buildCpGroupTimeline(
        mysqli $conn,
        int $groupId,
        array $options,
        array $labels,
        string $mode,
        bool $publicOnly
    ): array {
        $periods = [];
        foreach ($labels as $label) {
            if ($mode === 'cp_jahr') {
                $year = (int)$label;
                $periods[] = [
                    'start' => new DateTimeImmutable(sprintf('%04d-01-01', $year)),
                    'end' => new DateTimeImmutable(sprintf('%04d-12-31', $year)),
                ];
            } else {
                $semester = self::parseSemesterValue((string)$label);
                if ($semester !== null) {
                    $periods[] = ['start' => $semester['start'], 'end' => $semester['end']];
                }
            }
        }
        if (!$periods) {
            return ['rows' => [], 'name' => $options[$groupId]['path'] ?? '', 'empty' => true];
        }
        $rangeStart = $periods[0]['start'];
        $rangeEnd = end($periods)['end'];
        $rangeStartSql = $rangeStart->format('Y-m-d');
        $rangeEndSql = $rangeEnd->format('Y-m-d');
        $id = (int)$groupId;
        $privateClauseRoot = $publicOnly ? " AND visibility = 'public'" : '';
        $privateClauseChild = $publicOnly ? " AND child.visibility = 'public'" : '';
        $sql = "WITH RECURSIVE cp_group_tree AS (
            SELECT id, parent_group_id, name, sort_order, color
            FROM timeline_groups WHERE id = $id $privateClauseRoot
            UNION ALL
            SELECT child.id, child.parent_group_id, child.name, child.sort_order, child.color
            FROM timeline_groups child
            INNER JOIN cp_group_tree tree ON tree.id = child.parent_group_id
            WHERE 1 = 1 $privateClauseChild
        ) SELECT * FROM cp_group_tree";
        $result = $conn->query($sql);
        if (!$result) {
            throw new RuntimeException('CP-Gruppenverlauf konnte nicht geladen werden.');
        }
        $byId = [];
        $children = [];
        while ($group = $result->fetch_assoc()) {
            $group['id'] = (int)$group['id'];
            $group['parent_group_id'] = $group['parent_group_id'] !== null ? (int)$group['parent_group_id'] : null;
            $group['sort_order'] = (int)$group['sort_order'];
            $byId[$group['id']] = $group;
            $children[$group['parent_group_id'] ?? 0][] = $group['id'];
        }
        if (!isset($byId[$groupId])) {
            return ['rows' => [], 'name' => '', 'empty' => true];
        }
        foreach ($children as &$ids) {
            usort($ids, static fn($a, $b) => ($byId[$a]['sort_order'] <=> $byId[$b]['sort_order']) ?: ($a <=> $b));
        }
        unset($ids);
        $ids = implode(',', array_map('intval', array_keys($byId)));

        // Direkte Gruppenzugehörigkeit, ohne Seitenbaum-Daten außerhalb der Auswahl.
        $entries = [];
        $entriesByGroup = [];
        $result = $conn->query("SELECT id, group_id, title, sort_order
            FROM timeline_entries WHERE group_id IN ($ids)");
        if (!$result) {
            throw new RuntimeException('CP-Gruppeneinträge konnten nicht geladen werden.');
        }
        while ($entry = $result->fetch_assoc()) {
            $entry['id'] = (int)$entry['id'];
            $entry['group_id'] = (int)$entry['group_id'];
            $entry['sort_order'] = (int)$entry['sort_order'];
            $entries[$entry['id']] = $entry;
            $entriesByGroup[$entry['group_id']][] = $entry['id'];
        }

        $barsByEntry = [];
        $result = $conn->query("SELECT s.id, s.entry_id, s.start_date, s.end_date
            FROM timeline_entry_segments s
            INNER JOIN timeline_entries e ON e.id = s.entry_id
            WHERE e.group_id IN ($ids)
              AND s.start_date <= '$rangeEndSql'
              AND s.end_date >= '$rangeStartSql'
            ORDER BY s.entry_id, s.start_date, s.id");
        if (!$result) {
            throw new RuntimeException('CP-Gruppenzeiträume konnten nicht geladen werden.');
        }
        $periodCount = count($periods);
        while ($segment = $result->fetch_assoc()) {
            $start = self::dt($segment['start_date']);
            $end = self::dt($segment['end_date']);
            if (!$start || !$end || $start > $end) {
                continue;
            }
            $clippedStart = max($start, $rangeStart);
            $clippedEndExclusive = min($end->modify('+1 day'), $rangeEnd->modify('+1 day'));
            $startOffset = self::cpGroupDateOffset($clippedStart, $periods);
            $endOffset = self::cpGroupDateOffset($clippedEndExclusive, $periods);
            if ($startOffset === null || $endOffset === null || $endOffset <= $startOffset) {
                continue;
            }
            $eid = (int)$segment['entry_id'];
            $barsByEntry[$eid][] = [
                'id' => (int)$segment['id'],
                'left' => $startOffset / $periodCount * 100,
                'width' => max(0.2, ($endOffset - $startOffset) / $periodCount * 100),
                'title' => ($entries[$eid]['title'] ?? '') . "\nVon: " . self::fmtDate($start) . "\nBis: " . self::fmtDate($end),
            ];
        }

        $eventsByEntry = [];
        $result = $conn->query("SELECT ev.id, ev.entry_id, ev.event_type, ev.event_date, ev.note,
                   ev.status_code, ev.color, ev.semester_code, ev.year_override, e.creditpoints,
                   COALESCE(NULLIF(ev.title_override, ''), e.title) AS title
            FROM timeline_events ev
            INNER JOIN timeline_entries e ON e.id = ev.entry_id
            WHERE e.group_id IN ($ids)
              AND ev.event_date BETWEEN '$rangeStartSql' AND '$rangeEndSql'
            ORDER BY ev.event_date, ev.id");
        if (!$result) {
            throw new RuntimeException('CP-Gruppenereignisse konnten nicht geladen werden.');
        }
        while ($event = $result->fetch_assoc()) {
            $date = self::dt($event['event_date']);
            if (!$date) {
                continue;
            }
            $offset = self::cpGroupDateOffset($date, $periods, 0.5);
            if ($offset === null) {
                continue;
            }
            $type = strtolower((string)$event['event_type']);
            $isExam = $type === 'klausur';
            $tooltip = [(string)$event['title'], 'Datum: ' . self::fmtDate($date)];
            if ((string)$event['note'] !== '') {
                $tooltip[] = ($isExam ? 'Note: ' : 'Notiz: ') . $event['note'];
            }
            if ($isExam && (string)$event['status_code'] !== '') {
                $status = self::statusLabel($event['status_code']) . ' (' . $event['status_code'] . ')';
                if (strtoupper(trim((string)$event['status_code'])) === 'BE' && $event['creditpoints'] !== null) {
                    $status .= ' | ' . self::fmtCp((float)$event['creditpoints']) . ' CP';
                }
                $tooltip[] = $status;
            }
            if ($isExam && (string)$event['semester_code'] !== '') {
                $tooltip[] = 'Semester: ' . $event['semester_code'];
            }
            if ($isExam && $event['year_override'] !== null) {
                $tooltip[] = 'CP-Jahr: ' . (int)$event['year_override'];
            }
            $eventsByEntry[(int)$event['entry_id']][] = [
                'id' => (int)$event['id'],
                'left' => $offset / $periodCount * 100,
                'title' => implode("\n", $tooltip),
                'class' => $isExam ? 'life-event--' . self::statusCss($event['status_code']) : 'life-event--default',
                'color' => !$isExam && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$event['color']) ? $event['color'] : null,
            ];
        }

        $rootId = self::rootGroupId($groupId, $options);
        $color = self::rootColorById($rootId, $options);
        $rows = [];
        $walk = function (int $gid, int $depth) use (&$walk, &$rows, $byId, $children, $entries, $entriesByGroup, $barsByEntry, $eventsByEntry, $color): void {
            if (!isset($byId[$gid]) || $depth > 40) {
                return;
            }
            $group = $byId[$gid];
            $rows[] = ['type' => 'group', 'id' => $gid, 'depth' => $depth, 'name' => $group['name']];
            $items = [];
            foreach (($children[$gid] ?? []) as $childId) {
                $items[] = ['type' => 'group', 'id' => $childId, 'sort_order' => $byId[$childId]['sort_order']];
            }
            foreach (($entriesByGroup[$gid] ?? []) as $entryId) {
                if (empty($barsByEntry[$entryId]) && empty($eventsByEntry[$entryId])) {
                    continue;
                }
                $items[] = ['type' => 'entry', 'id' => $entryId, 'sort_order' => $entries[$entryId]['sort_order']];
            }
            usort($items, static fn($a, $b) => ($a['sort_order'] <=> $b['sort_order']) ?: ($a['id'] <=> $b['id']));
            foreach ($items as $item) {
                if ($item['type'] === 'group') {
                    $walk($item['id'], $depth + 1);
                } else {
                    $entry = $entries[$item['id']];
                    $rows[] = [
                        'type' => 'entry', 'id' => $entry['id'], 'depth' => $depth + 1,
                        'name' => $entry['title'], 'bars' => $barsByEntry[$entry['id']] ?? [],
                        'events' => $eventsByEntry[$entry['id']] ?? [], 'color' => $color,
                    ];
                }
            }
        };
        $walk($groupId, 0);
        return ['rows' => $rows, 'name' => $options[$groupId]['path'], 'empty' => count($rows) <= 1];
    }

    private static function renderCpGroupTimeline(array $timeline, bool $editable, int $periodCount): void
    {
        ?>
        <div class="life-cp-group-timeline" id="lifeCpGroupTimeline"
             style="--life-cp-periods: <?= max(1, $periodCount) ?>"
             aria-label="Zeitbalkenverlauf: <?= self::esc($timeline['name']) ?>">
            <?php foreach ($timeline['rows'] as $row): ?>
                <div class="life-cp-timeline-row <?= $row['type'] === 'group' ? 'life-cp-timeline-row--group' : 'life-cp-timeline-row--entry' ?>">
                    <div class="life-cp-timeline-label" title="<?= self::esc($row['name']) ?>"
                         style="--life-depth: <?= (int)$row['depth'] ?>"
                         <?= $editable ? 'data-life-edit-kind="' . ($row['type'] === 'group' ? 'group' : 'entry') . '" data-life-edit-id="' . (int)$row['id'] . '" tabindex="0" role="button"' : '' ?>>
                        <span><?= self::esc($row['name']) ?></span>
                    </div>
                    <div class="life-cp-timeline-track">
                        <div class="life-cp-timeline-grid" aria-hidden="true">
                            <?php for ($i = 0; $i < $periodCount; $i++): ?><span></span><?php endfor; ?>
                        </div>
                        <?php foreach (($row['bars'] ?? []) as $bar): ?>
                            <div class="life-bar life-interactive"
                                 title="<?= self::esc($bar['title']) ?>"
                                 data-life-info="<?= self::infoPayloadAttr(explode("\n", $bar['title'])) ?>"
                                 <?= $editable ? 'data-life-edit-kind="segment" data-life-edit-id="' . (int)$bar['id'] . '"' : '' ?>
                                 tabindex="0" role="button"
                                 style="left: <?= number_format($bar['left'], 6, '.', '') ?>%; width: <?= number_format($bar['width'], 6, '.', '') ?>%; background-color: <?= self::esc($row['color']) ?>;"></div>
                        <?php endforeach; ?>
                        <?php foreach (($row['events'] ?? []) as $event): ?>
                            <div class="life-event <?= self::esc($event['class']) ?> life-interactive"
                                 title="<?= self::esc($event['title']) ?>"
                                 data-life-info="<?= self::infoPayloadAttr(explode("\n", $event['title'])) ?>"
                                 <?= $editable ? 'data-life-edit-kind="event" data-life-edit-id="' . (int)$event['id'] . '"' : '' ?>
                                 tabindex="0" role="button"
                                 style="left: <?= number_format($event['left'], 6, '.', '') ?>%;<?= $event['color'] !== null ? ' color: ' . self::esc($event['color']) . ';' : '' ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="life-cp-timeline-edge" aria-hidden="true"></div>
                </div>
            <?php endforeach; ?>
            <?php if (!empty($timeline['empty'])): ?>
                <div class="life-cp-timeline-empty">Im dargestellten Zeitraum keine Zeiträume oder Ereignisse vorhanden.</div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function isCpMode(string $mode): bool
    {
        return in_array($mode, ['cp_jahr', 'cp_semester'], true);
    }

    private static function buildCreditpointsChartData(mysqli $conn, string $mode, int $fallbackYear, bool $publicOnly = false): array
    {
        $events = self::fetchPassedCreditEvents($conn, $publicOnly);

        if ($mode === 'cp_semester') {
            return self::buildCpSemesterSeriesFromEvents($events, $fallbackYear);
        }

        return self::buildCpYearSeriesFromEvents($events, $fallbackYear);
    }

    private static function fetchPassedCreditEvents(mysqli $conn, bool $publicOnly = false): array
    {
        $events = [];
        $publicScope = $publicOnly
            ? self::publicGroupsCte()
            : '';
        $publicJoin = $publicOnly
            ? 'INNER JOIN visible_groups vg ON vg.id = te.group_id'
            : '';
        $res = $conn->query($publicScope . "
            SELECT
                ev.event_date,
                COALESCE(NULLIF(ev.title_override, ''), te.title) AS title,
                ev.semester_code,
                ev.year_override,
                te.creditpoints
            FROM timeline_events ev
            INNER JOIN timeline_entries te ON te.id = ev.entry_id
            $publicJoin
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
                'modules' => [[]],
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
        $modulesByYear = [];
        foreach ($events as $event) {
            $year = $event['year_override'] !== null
                ? (int)$event['year_override']
                : (int)$event['date']->format('Y');
            if (!isset($sumByYear[$year])) {
                $sumByYear[$year] = 0.0;
            }
            $sumByYear[$year] += (float)$event['creditpoints'];
            $modulesByYear[$year][] = [
                'title' => (string)$event['title'],
                'creditpoints' => (float)$event['creditpoints'],
            ];
        }

        $labels = [];
        $values = [];
        $modules = [];
        $total = 0.0;
        $maxValue = 0.0;
        $maxLabel = '';

        for ($year = $firstYear; $year <= $lastYear; $year++) {
            $value = (float)($sumByYear[$year] ?? 0.0);
            $labels[] = (string)$year;
            $values[] = $value;
            $modules[] = $modulesByYear[$year] ?? [];
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
            'modules' => $modules,
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
                'modules' => [[]],
                'total' => 0.0,
                'avg_value' => 0.0,
                'max_value' => 0.0,
                'max_label' => '',
            ];
        }

        // Semester-Overrides können außerhalb des Zeitraums der Prüfungsdaten liegen.
        // Deshalb die Grenzen anhand aller effektiv zugeordneten Semester bilden.
        $semesterStarts = [];
        $semesterEnds = [];
        foreach ($events as $event) {
            $effective = self::semesterValueFromLabel((string)$event['semester_label']);
            $semesterStarts[] = $effective['start'] ?? $event['date'];
            $semesterEnds[] = $effective['end'] ?? $event['date'];
        }
        $minDate = min($semesterStarts);
        $maxDate = max($semesterEnds);

        $semesterItems = self::buildSemesterOptions($minDate, $maxDate);
        usort($semesterItems, static function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        $sumBySemester = [];
        $modulesBySemester = [];
        foreach ($events as $event) {
            $label = (string)$event['semester_label'];
            if (!isset($sumBySemester[$label])) {
                $sumBySemester[$label] = 0.0;
            }
            $sumBySemester[$label] += (float)$event['creditpoints'];
            $modulesBySemester[$label][] = [
                'title' => (string)$event['title'],
                'creditpoints' => (float)$event['creditpoints'],
            ];
        }

        $labels = [];
        $values = [];
        $modules = [];
        $total = 0.0;
        $maxValue = 0.0;
        $maxLabel = '';

        foreach ($semesterItems as $semesterItem) {
            $label = (string)$semesterItem['label'];
            $value = (float)($sumBySemester[$label] ?? 0.0);

            $labels[] = $label;
            $values[] = $value;
            $modules[] = $modulesBySemester[$label] ?? [];
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
            'modules' => $modules,
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