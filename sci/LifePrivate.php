<?php

require_once __DIR__ . '/Life.php';


final class LifePrivateEditor
{
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['life_editor_csrf'])) {
            $_SESSION['life_editor_csrf'] = bin2hex(random_bytes(24));
        }

        return (string)$_SESSION['life_editor_csrf'];
    }

    private static function esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function nullableString($value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private static function requirePositiveId($value, string $label): int
    {
        $id = (int)$value;
        if ($id <= 0) {
            throw new RuntimeException($label . ' fehlt oder ist ungültig.');
        }
        return $id;
    }

    private static function requireDate($value, string $label): string
    {
        $value = trim((string)$value);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$d || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $d->format('Y-m-d') !== $value) {
            throw new RuntimeException($label . ' ist kein gültiges Datum.');
        }
        return $value;
    }

    private static function exists(mysqli $conn, string $table, int $id): bool
    {
        $allowed = ['timeline_groups', 'timeline_entries', 'timeline_entry_segments', 'timeline_events'];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Ungültige Tabelle.');
        }

        $stmt = $conn->prepare("SELECT 1 FROM `$table` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $found;
    }

    private static function assertNoGroupCycle(mysqli $conn, int $groupId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId === $groupId) {
            throw new RuntimeException('Eine Gruppe kann nicht ihr eigener Parent sein.');
        }

        $seen = [];
        $current = $parentId;
        while ($current !== null) {
            if ($current === $groupId) {
                throw new RuntimeException('Diese Verschiebung würde einen Hierarchie-Zyklus erzeugen.');
            }
            if (isset($seen[$current])) {
                throw new RuntimeException('Die vorhandene Gruppenhierarchie enthält bereits einen Zyklus.');
            }
            $seen[$current] = true;

            $stmt = $conn->prepare('SELECT parent_group_id FROM timeline_groups WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $current);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                throw new RuntimeException('Gewählte Parent-Gruppe existiert nicht.');
            }
            $current = $row['parent_group_id'] !== null ? (int)$row['parent_group_id'] : null;
        }
    }

    private static function normalizeSemesterOverride(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^(SS\d{2}|WS\d{2}\/\d{2})$/', $value)) {
            throw new RuntimeException('Semester-Override muss z. B. SS26 oder WS26/27 sein.');
        }
        return $value;
    }

    public static function handlePost(mysqli $conn): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_POST['life_editor_action'])) {
            return;
        }

        $csrf = (string)($_POST['life_editor_csrf'] ?? '');
        if (!hash_equals(self::csrfToken(), $csrf)) {
            self::setFlash('error', 'CSRF-Prüfung fehlgeschlagen. Seite neu laden und erneut versuchen.');
            self::redirectBack();
        }

        $action = (string)$_POST['life_editor_action'];

        try {
            $conn->begin_transaction();

            switch ($action) {
                case 'save_group':
                    self::saveGroup($conn);
                    break;
                case 'delete_group':
                    self::deleteGroup($conn);
                    break;
                case 'save_entry':
                    self::saveEntry($conn);
                    break;
                case 'delete_entry':
                    self::deleteEntry($conn);
                    break;
                case 'save_segment':
                    self::saveSegment($conn);
                    break;
                case 'delete_segment':
                    self::deleteSegment($conn);
                    break;
                case 'save_event':
                    self::saveEvent($conn);
                    break;
                case 'delete_event':
                    self::deleteEvent($conn);
                    break;
                default:
                    throw new RuntimeException('Unbekannte Editor-Aktion.');
            }

            $conn->commit();
            self::setFlash('success', 'Änderung gespeichert.');
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
            self::setFlash('error', $e->getMessage());
        }

        self::redirectBack();
    }

    private static function saveGroup(mysqli $conn): void
    {
        $id = (int)($_POST['group_id'] ?? 0);
        $name = trim((string)($_POST['group_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Gruppenname darf nicht leer sein.');
        }

        $parentRaw = (int)($_POST['group_parent_id'] ?? 0);
        $parentId = $parentRaw > 0 ? $parentRaw : null;
        $sortOrder = max(0, (int)($_POST['group_sort_order'] ?? 0));
        $color = self::nullableString($_POST['group_color'] ?? null);

        if ($color !== null && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new RuntimeException('Farbe muss im Format #RRGGBB vorliegen.');
        }
        if ($parentId !== null) {
            $color = null; // Farbe ist ausschließlich eine Eigenschaft von Root-Gruppen.
        } elseif ($color === null) {
            $color = '#64748B';
        }

        if ($parentId !== null && !self::exists($conn, 'timeline_groups', $parentId)) {
            throw new RuntimeException('Gewählte Parent-Gruppe existiert nicht.');
        }

        if ($id > 0) {
            if (!self::exists($conn, 'timeline_groups', $id)) {
                throw new RuntimeException('Zu bearbeitende Gruppe existiert nicht.');
            }
            self::assertNoGroupCycle($conn, $id, $parentId);

            $stmt = $conn->prepare('UPDATE timeline_groups SET parent_group_id = ?, name = ?, sort_order = ?, color = ? WHERE id = ?');
            $stmt->bind_param('isisi', $parentId, $name, $sortOrder, $color, $id);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('INSERT INTO timeline_groups (parent_group_id, name, sort_order, color) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isis', $parentId, $name, $sortOrder, $color);
        $stmt->execute();
        $stmt->close();
    }

    private static function deleteGroup(mysqli $conn): void
    {
        $id = self::requirePositiveId($_POST['group_id'] ?? 0, 'Gruppe');

        $stmt = $conn->prepare('SELECT (SELECT COUNT(*) FROM timeline_groups WHERE parent_group_id = ?) AS child_groups, (SELECT COUNT(*) FROM timeline_entries WHERE group_id = ?) AS entries_count');
        $stmt->bind_param('ii', $id, $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if ((int)($row['child_groups'] ?? 0) > 0 || (int)($row['entries_count'] ?? 0) > 0) {
            throw new RuntimeException('Gruppe ist nicht leer. Untergruppen/Einträge zuerst verschieben oder löschen.');
        }

        $stmt = $conn->prepare('DELETE FROM timeline_groups WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private static function saveEntry(mysqli $conn): void
    {
        $id = (int)($_POST['entry_id'] ?? 0);
        $groupId = self::requirePositiveId($_POST['entry_group_id'] ?? 0, 'Gruppe');
        if (!self::exists($conn, 'timeline_groups', $groupId)) {
            throw new RuntimeException('Gewählte Gruppe existiert nicht.');
        }

        $title = trim((string)($_POST['entry_title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Eintragstitel darf nicht leer sein.');
        }

        $cpRaw = trim((string)($_POST['entry_creditpoints'] ?? ''));
        $creditpoints = $cpRaw === '' ? null : (float)str_replace(',', '.', $cpRaw);
        if ($creditpoints !== null && $creditpoints < 0) {
            throw new RuntimeException('Creditpoints dürfen nicht negativ sein.');
        }
        $sortOrder = max(0, (int)($_POST['entry_sort_order'] ?? 0));

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE timeline_entries SET group_id = ?, title = ?, creditpoints = ?, sort_order = ? WHERE id = ?');
            $stmt->bind_param('isdii', $groupId, $title, $creditpoints, $sortOrder, $id);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('INSERT INTO timeline_entries (group_id, title, creditpoints, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isdi', $groupId, $title, $creditpoints, $sortOrder);
        $stmt->execute();
        $stmt->close();
    }

    private static function deleteEntry(mysqli $conn): void
    {
        $id = self::requirePositiveId($_POST['entry_id'] ?? 0, 'Eintrag');
        $stmt = $conn->prepare('DELETE FROM timeline_entries WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private static function saveSegment(mysqli $conn): void
    {
        $id = (int)($_POST['segment_id'] ?? 0);
        $entryId = self::requirePositiveId($_POST['segment_entry_id'] ?? 0, 'Eintrag');
        if (!self::exists($conn, 'timeline_entries', $entryId)) {
            throw new RuntimeException('Gewählter Eintrag existiert nicht.');
        }

        $start = self::requireDate($_POST['segment_start_date'] ?? '', 'Startdatum');
        $end = self::requireDate($_POST['segment_end_date'] ?? '', 'Enddatum');
        if ($end < $start) {
            throw new RuntimeException('Enddatum darf nicht vor dem Startdatum liegen.');
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE timeline_entry_segments SET entry_id = ?, start_date = ?, end_date = ? WHERE id = ?');
            $stmt->bind_param('issi', $entryId, $start, $end, $id);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('INSERT INTO timeline_entry_segments (entry_id, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $entryId, $start, $end);
        $stmt->execute();
        $stmt->close();
    }

    private static function deleteSegment(mysqli $conn): void
    {
        $id = self::requirePositiveId($_POST['segment_id'] ?? 0, 'Zeitraum');
        $stmt = $conn->prepare('DELETE FROM timeline_entry_segments WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private static function saveEvent(mysqli $conn): void
    {
        $id = (int)($_POST['event_id'] ?? 0);
        $eventType = strtolower(trim((string)($_POST['event_type'] ?? 'klausur')));
        if (!in_array($eventType, ['klausur', 'ereignis'], true)) {
            throw new RuntimeException('Ungültiger Ereignistyp.');
        }

        $eventDate = self::requireDate($_POST['event_date'] ?? '', 'Ereignisdatum');
        $entryId = self::requirePositiveId($_POST['event_entry_id'] ?? 0, 'Eintrag');
        if (!self::exists($conn, 'timeline_entries', $entryId)) {
            throw new RuntimeException('Gewählter Eintrag existiert nicht.');
        }

        if ($eventType === 'klausur') {
            $titleOverride = self::nullableString($_POST['event_title_override_exam'] ?? null);
            $note = self::nullableString($_POST['event_grade'] ?? null);
            $status = self::nullableString($_POST['event_status_code'] ?? null);
            if ($status !== null) {
                $status = strtoupper($status);
                if (!in_array($status, ['BE', 'NB', 'Q', 'X', 'AN'], true)) {
                    throw new RuntimeException('Ungültiger Statuscode.');
                }
            }
            $semester = self::normalizeSemesterOverride(self::nullableString($_POST['event_semester_code'] ?? null));

            $yearOverrideRaw = trim((string)($_POST['event_year_override'] ?? ''));
            $yearOverride = null;
            if ($yearOverrideRaw !== '') {
                if (!preg_match('/^\d{4}$/', $yearOverrideRaw)) {
                    throw new RuntimeException('Jahr-Override muss vierstellig sein, z. B. 2021.');
                }
                $yearOverride = (int)$yearOverrideRaw;
                if ($yearOverride < 1900 || $yearOverride > 2200) {
                    throw new RuntimeException('Jahr-Override ist ungültig.');
                }
            }

            $color = null;
        } else {
            $titleOverride = self::nullableString($_POST['event_title'] ?? null);
            if ($titleOverride === null) {
                throw new RuntimeException('Titel des Ereignisses darf nicht leer sein.');
            }
            $note = self::nullableString($_POST['event_note_text'] ?? null);
            $status = null;
            $semester = null;
            $yearOverride = null;
            $color = trim((string)($_POST['event_color'] ?? ''));
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new RuntimeException('Farbe muss im Format #RRGGBB vorliegen.');
            }
        }

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE timeline_events SET entry_id = ?, event_type = ?, title_override = ?, event_date = ?, note = ?, status_code = ?, semester_code = ?, year_override = ?, color = ? WHERE id = ?');
            $stmt->bind_param('issssssisi', $entryId, $eventType, $titleOverride, $eventDate, $note, $status, $semester, $yearOverride, $color, $id);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $stmt = $conn->prepare('INSERT INTO timeline_events (entry_id, event_type, title_override, event_date, note, status_code, semester_code, year_override, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssssiss', $entryId, $eventType, $titleOverride, $eventDate, $note, $status, $semester, $yearOverride, $color);
        $stmt->execute();
        $stmt->close();
    }

    private static function deleteEvent(mysqli $conn): void
    {
        $id = self::requirePositiveId($_POST['event_id'] ?? 0, 'Ereignis');
        $stmt = $conn->prepare('DELETE FROM timeline_events WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private static function setFlash(string $type, string $message): void
    {
        $_SESSION['life_editor_flash'] = ['type' => $type, 'message' => $message];
    }

    private static function redirectBack(): void
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/sci/plan');
        header('Location: ' . $uri, true, 303);
        exit;
    }

    private static function fetchAll(mysqli $conn, string $sql): array
    {
        $res = $conn->query($sql);
        if (!$res) {
            throw new RuntimeException('Editor-Daten konnten nicht geladen werden: ' . $conn->error);
        }
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    private static function groupPaths(array $groups): array
    {
        $byId = [];
        foreach ($groups as $g) {
            $g['id'] = (int)$g['id'];
            $g['parent_group_id'] = $g['parent_group_id'] !== null ? (int)$g['parent_group_id'] : null;
            $byId[$g['id']] = $g;
        }

        $memo = [];
        $pathFor = function (int $id) use (&$pathFor, &$memo, $byId): string {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            if (!isset($byId[$id])) {
                return '#' . $id;
            }
            $g = $byId[$id];
            $name = (string)$g['name'];
            if ($g['parent_group_id'] === null) {
                return $memo[$id] = $name;
            }
            return $memo[$id] = $pathFor((int)$g['parent_group_id']) . ' › ' . $name;
        };

        $paths = [];
        foreach (array_keys($byId) as $id) {
            $paths[$id] = $pathFor((int)$id);
        }
        return $paths;
    }

    public static function fetchEditorData(mysqli $conn): array
    {
        $groups = self::fetchAll($conn, 'SELECT id, parent_group_id, name, sort_order, color FROM timeline_groups ORDER BY sort_order, id');
        $paths = self::groupPaths($groups);

        $entries = self::fetchAll($conn, 'SELECT id, group_id, title, creditpoints, sort_order FROM timeline_entries ORDER BY group_id, sort_order, id');
        foreach ($entries as &$entry) {
            $entry['group_path'] = $paths[(int)$entry['group_id']] ?? ('#' . $entry['group_id']);
        }
        unset($entry);

        $segments = self::fetchAll($conn, 'SELECT s.id, s.entry_id, s.start_date, s.end_date, e.title AS entry_title FROM timeline_entry_segments s INNER JOIN timeline_entries e ON e.id = s.entry_id ORDER BY e.title, s.start_date, s.end_date, s.id');

        $events = self::fetchAll($conn, "SELECT ev.id, ev.entry_id, ev.event_type, ev.title_override, ev.event_date, ev.note, ev.status_code, ev.semester_code, ev.year_override, ev.color, e.title AS entry_title FROM timeline_events ev LEFT JOIN timeline_entries e ON e.id = ev.entry_id ORDER BY ev.event_date DESC, ev.id DESC");

        return compact('groups', 'paths', 'entries', 'segments', 'events');
    }

    public static function renderInterface(mysqli $conn): void
    {
        $data = self::fetchEditorData($conn);
        $csrf = self::csrfToken();
        $flash = $_SESSION['life_editor_flash'] ?? null;
        unset($_SESSION['life_editor_flash']);

        $groupsById = [];
        foreach ($data['groups'] as $g) {
            $groupsById[(int)$g['id']] = $g;
        }

        $entriesById = [];
        foreach ($data['entries'] as $e) {
            $entriesById[(int)$e['id']] = $e;
        }

        $segmentsById = [];
        foreach ($data['segments'] as $s) {
            $segmentsById[(int)$s['id']] = $s;
        }

        $eventsById = [];
        foreach ($data['events'] as $ev) {
            $eventsById[(int)$ev['id']] = $ev;
        }

        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $groupsJson = json_encode($groupsById, $jsonFlags) ?: '{}';
        $entriesJson = json_encode($entriesById, $jsonFlags) ?: '{}';
        $segmentsJson = json_encode($segmentsById, $jsonFlags) ?: '{}';
        $eventsJson = json_encode($eventsById, $jsonFlags) ?: '{}';
        ?>
<link rel="stylesheet" href="/FIJI_LifePrivate.css">

<?php if (is_array($flash)): ?>
    <div class="life-edit-toast life-edit-toast--<?= self::esc($flash['type'] ?? 'info') ?>" id="lifeEditToast">
        <?= self::esc($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="life-edit-modal" id="lifeEditModal" hidden>
    <div class="life-edit-backdrop" data-life-modal-close></div>
    <section class="life-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="lifeEditModalTitle">
        <header class="life-edit-dialog-head">
            <h2 id="lifeEditModalTitle">Bearbeiten</h2>
            <button type="button" class="life-edit-modal-close" data-life-modal-close aria-label="Fenster schließen">&times;</button>
        </header>

        <div class="life-edit-dialog-body">
            <form method="post" class="life-edit-form" data-life-form="group" hidden>
                <input type="hidden" name="life_editor_csrf" value="<?= self::esc($csrf) ?>">
                <input type="hidden" name="life_editor_action" value="save_group">
                <input type="hidden" name="group_id" data-field="group_id">

                <div class="life-edit-field life-edit-field--wide">
                    <label>Name</label>
                    <input name="group_name" data-field="group_name" maxlength="255" required>
                </div>

                <div class="life-edit-field">
                    <label>Übergeordnete Gruppe</label>
                    <select name="group_parent_id" data-field="group_parent_id">
                        <option value="0">— Root-Gruppe —</option>
                        <?php foreach ($data['groups'] as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= self::esc($data['paths'][(int)$g['id']] ?? $g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="life-edit-field">
                    <label>Position</label>
                    <input type="number" min="0" step="1" name="group_sort_order" data-field="group_sort_order" required>
                </div>

                <div class="life-edit-field">
                    <label>Farbe der Root-Gruppe</label>
                    <input type="color" name="group_color" data-field="group_color" value="#64748b">
                    <small>Nur Root-Gruppen besitzen eine eigene Farbe.</small>
                </div>

                <div class="life-edit-actions life-edit-field--wide">
                    <button type="submit">Speichern</button>
                    <button type="submit" class="life-edit-danger" name="life_editor_action" value="delete_group" data-delete-button>Gruppe löschen</button>
                </div>
            </form>

            <form method="post" class="life-edit-form" data-life-form="entry" hidden>
                <input type="hidden" name="life_editor_csrf" value="<?= self::esc($csrf) ?>">
                <input type="hidden" name="life_editor_action" value="save_entry">
                <input type="hidden" name="entry_id" data-field="entry_id">

                <div class="life-edit-field life-edit-field--wide">
                    <label>Titel</label>
                    <input name="entry_title" data-field="entry_title" maxlength="255" required>
                </div>

                <div class="life-edit-field life-edit-field--wide">
                    <label>Gruppe</label>
                    <select name="entry_group_id" data-field="entry_group_id" required>
                        <option value="">— auswählen —</option>
                        <?php foreach ($data['groups'] as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"><?= self::esc($data['paths'][(int)$g['id']] ?? $g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="life-edit-field">
                    <label>Creditpoints</label>
                    <input name="entry_creditpoints" data-field="entry_creditpoints" inputmode="decimal" placeholder="z. B. 7 oder 5,5">
                </div>

                <div class="life-edit-field">
                    <label>Position</label>
                    <input type="number" min="0" step="1" name="entry_sort_order" data-field="entry_sort_order" required>
                </div>

                <div class="life-edit-actions life-edit-field--wide">
                    <button type="submit">Speichern</button>
                    <button type="submit" class="life-edit-danger" name="life_editor_action" value="delete_entry" data-delete-button>Eintrag löschen</button>
                </div>
            </form>

            <form method="post" class="life-edit-form" data-life-form="segment" hidden>
                <input type="hidden" name="life_editor_csrf" value="<?= self::esc($csrf) ?>">
                <input type="hidden" name="life_editor_action" value="save_segment">
                <input type="hidden" name="segment_id" data-field="segment_id">

                <div class="life-edit-field life-edit-field--wide">
                    <label>Eintrag</label>
                    <select name="segment_entry_id" data-field="segment_entry_id" required>
                        <option value="">— auswählen —</option>
                        <?php foreach ($data['entries'] as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= self::esc($e['group_path'] . ' › ' . $e['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="life-edit-field">
                    <label>Von</label>
                    <input type="date" name="segment_start_date" data-field="segment_start_date" required>
                </div>

                <div class="life-edit-field">
                    <label>Bis</label>
                    <input type="date" name="segment_end_date" data-field="segment_end_date" required>
                </div>

                <div class="life-edit-actions life-edit-field--wide">
                    <button type="submit">Speichern</button>
                    <button type="submit" class="life-edit-danger" name="life_editor_action" value="delete_segment" data-delete-button>Zeitraum löschen</button>
                </div>
            </form>

            <form method="post" class="life-edit-form" data-life-form="event" hidden>
                <input type="hidden" name="life_editor_csrf" value="<?= self::esc($csrf) ?>">
                <input type="hidden" name="life_editor_action" value="save_event">
                <input type="hidden" name="event_id" data-field="event_id">

                <div class="life-edit-field">
                    <label>Typ</label>
                    <select name="event_type" data-field="event_type" required>
                        <option value="klausur">Klausur</option>
                        <option value="ereignis">Ereignis</option>
                    </select>
                </div>

                <div class="life-edit-field">
                    <label>Datum</label>
                    <input type="date" name="event_date" data-field="event_date" required>
                </div>

                <div class="life-edit-field life-edit-field--wide">
                    <label>Eintrag</label>
                    <select name="event_entry_id" data-field="event_entry_id">
                        <option value="">— auswählen —</option>
                        <?php foreach ($data['entries'] as $e): ?>
                            <option value="<?= (int)$e['id'] ?>"><?= self::esc($e['group_path'] . ' › ' . $e['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="life-edit-field life-edit-field--wide" data-event-fields="klausur">
                    <label>Abweichender Titel</label>
                    <input name="event_title_override_exam" data-field="event_title_override_exam" maxlength="255" placeholder="leer = Titel des Eintrags">
                </div>

                <div class="life-edit-field" data-event-fields="klausur">
                    <label>Status</label>
                    <select name="event_status_code" data-field="event_status_code">
                        <option value="">— keiner —</option>
                        <option value="AN">AN · Angemeldet</option>
                        <option value="BE">BE · Bestanden</option>
                        <option value="NB">NB · Nicht bestanden</option>
                        <option value="Q">Q · Attest / keine Beurteilung</option>
                        <option value="X">X · Nicht erschienen</option>
                    </select>
                </div>

                <div class="life-edit-field" data-event-fields="klausur">
                    <label>Note</label>
                    <input name="event_grade" data-field="event_grade" maxlength="50" placeholder="z. B. 2,3 oder B">
                </div>

                <div class="life-edit-field" data-event-fields="klausur">
                    <label>Semester-Override</label>
                    <input name="event_semester_code" data-field="event_semester_code" maxlength="10" placeholder="normal leer; z. B. WS20/21">
                    <small>Für CP pro Semester; normal aus dem Datum.</small>
                </div>

                <div class="life-edit-field" data-event-fields="klausur">
                    <label>Jahr-Override</label>
                    <input type="number" name="event_year_override" data-field="event_year_override" min="1900" max="2200" step="1" placeholder="normal leer; z. B. 2021">
                    <small>Für CP pro Jahr; normal aus dem Datum.</small>
                </div>

                <div class="life-edit-field life-edit-field--wide" data-event-fields="ereignis" hidden>
                    <label>Titel</label>
                    <input name="event_title" data-field="event_title" maxlength="255">
                </div>

                <div class="life-edit-field life-edit-field--wide" data-event-fields="ereignis" hidden>
                    <label>Notiz</label>
                    <textarea name="event_note_text" data-field="event_note_text" maxlength="500" rows="4"></textarea>
                </div>

                <div class="life-edit-field" data-event-fields="ereignis" hidden>
                    <label>Farbe</label>
                    <input type="color" name="event_color" data-field="event_color" value="#64748b">
                </div>

                <div class="life-edit-actions life-edit-field--wide">
                    <button type="submit">Speichern</button>
                    <button type="submit" class="life-edit-danger" name="life_editor_action" value="delete_event" data-delete-button>Ereignis löschen</button>
                </div>
            </form>
        </div>
    </section>
</div>

<script>
(() => {
    const data = {
        group: <?= $groupsJson ?>,
        entry: <?= $entriesJson ?>,
        segment: <?= $segmentsJson ?>,
        event: <?= $eventsJson ?>
    };

    const titles = {
        group: ['Neue Gruppe', 'Gruppe bearbeiten'],
        entry: ['Neuer Eintrag', 'Eintrag bearbeiten'],
        segment: ['Neuer Zeitraum', 'Zeitraum bearbeiten'],
        event: ['Neue Klausur / neues Ereignis', 'Klausur / Ereignis bearbeiten']
    };

    const modal = document.getElementById('lifeEditModal');
    const modalTitle = document.getElementById('lifeEditModalTitle');
    if (!modal || !modalTitle) return;

    const forms = Object.fromEntries(
        Array.from(document.querySelectorAll('[data-life-form]')).map(form => [form.dataset.lifeForm, form])
    );

    function field(form, name) {
        return form.querySelector(`[data-field="${name}"]`);
    }

    function setValue(form, name, value) {
        const el = field(form, name);
        if (!el) return;
        el.value = value == null ? '' : String(value);
    }

    function resetForm(kind) {
        const form = forms[kind];
        if (!form) return;
        form.reset();

        if (kind === 'group') {
            setValue(form, 'group_id', '');
            setValue(form, 'group_parent_id', '0');
            setValue(form, 'group_sort_order', '9999');
            setValue(form, 'group_color', '#64748b');
        } else if (kind === 'entry') {
            setValue(form, 'entry_id', '');
            setValue(form, 'entry_sort_order', '9999');
        } else if (kind === 'segment') {
            setValue(form, 'segment_id', '');
        } else if (kind === 'event') {
            setValue(form, 'event_id', '');
            setValue(form, 'event_type', 'klausur');
            setValue(form, 'event_year_override', '');
            setValue(form, 'event_color', '#64748b');
        }
    }

    function fillForm(kind, record) {
        const form = forms[kind];
        if (!form || !record) return;

        if (kind === 'group') {
            setValue(form, 'group_id', record.id);
            setValue(form, 'group_name', record.name);
            setValue(form, 'group_parent_id', record.parent_group_id ?? '0');
            setValue(form, 'group_sort_order', record.sort_order ?? 0);
            setValue(form, 'group_color', record.color || '#64748b');
        } else if (kind === 'entry') {
            setValue(form, 'entry_id', record.id);
            setValue(form, 'entry_title', record.title);
            setValue(form, 'entry_group_id', record.group_id);
            setValue(form, 'entry_creditpoints', record.creditpoints ?? '');
            setValue(form, 'entry_sort_order', record.sort_order ?? 0);
        } else if (kind === 'segment') {
            setValue(form, 'segment_id', record.id);
            setValue(form, 'segment_entry_id', record.entry_id);
            setValue(form, 'segment_start_date', record.start_date);
            setValue(form, 'segment_end_date', record.end_date);
        } else if (kind === 'event') {
            const type = record.event_type || 'klausur';
            setValue(form, 'event_id', record.id);
            setValue(form, 'event_type', type);
            setValue(form, 'event_date', record.event_date);
            setValue(form, 'event_entry_id', record.entry_id ?? '');
            setValue(form, 'event_title_override_exam', type === 'klausur' ? (record.title_override ?? '') : '');
            setValue(form, 'event_status_code', type === 'klausur' ? (record.status_code ?? '') : '');
            setValue(form, 'event_grade', type === 'klausur' ? (record.note ?? '') : '');
            setValue(form, 'event_semester_code', type === 'klausur' ? (record.semester_code ?? '') : '');
            setValue(form, 'event_year_override', type === 'klausur' ? (record.year_override ?? '') : '');
            setValue(form, 'event_title', type === 'ereignis' ? (record.title_override ?? '') : '');
            setValue(form, 'event_note_text', type === 'ereignis' ? (record.note ?? '') : '');
            setValue(form, 'event_color', type === 'ereignis' ? (record.color || '#64748b') : '#64748b');
        }
    }

    function syncGroupForm() {
        const form = forms.group;
        if (!form) return;
        const parent = field(form, 'group_parent_id');
        const color = field(form, 'group_color');
        const id = field(form, 'group_id')?.value || '';
        if (color && parent) {
            color.disabled = parent.value !== '0';
        }
        if (parent) {
            Array.from(parent.options).forEach(option => {
                option.disabled = !!id && option.value === id;
            });
        }
    }

    function syncEventForm() {
        const form = forms.event;
        if (!form) return;

        const type = field(form, 'event_type')?.value || 'klausur';
        form.querySelectorAll('[data-event-fields]').forEach(group => {
            group.hidden = group.dataset.eventFields !== type;
        });

        const entry = field(form, 'event_entry_id');
        const eventTitle = field(form, 'event_title');
        const eventColor = field(form, 'event_color');
        if (entry) entry.required = true;
        if (eventTitle) eventTitle.required = type === 'ereignis';
        if (eventColor) eventColor.required = type === 'ereignis';
    }

    function openModal(kind, id = null, defaults = {}) {
        const form = forms[kind];
        if (!form) return;

        Object.entries(forms).forEach(([formKind, current]) => {
            current.hidden = formKind !== kind;
        });

        resetForm(kind);
        const record = id != null ? data[kind]?.[String(id)] : null;
        if (record) fillForm(kind, record);

        Object.entries(defaults).forEach(([name, value]) => setValue(form, name, value));

        const existing = !!record;
        const deleteButton = form.querySelector('[data-delete-button]');
        if (deleteButton) deleteButton.hidden = !existing;

        if (kind === 'group') syncGroupForm();
        if (kind === 'event') syncEventForm();

        modalTitle.textContent = titles[kind]?.[existing ? 1 : 0] || 'Bearbeiten';
        modal.hidden = false;
        document.body.classList.add('life-edit-modal-open');

        requestAnimationFrame(() => {
            const autofocus = form.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled)');
            autofocus?.focus();
        });
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('life-edit-modal-open');
    }

    // Die Neu-Buttons liegen in Life.php und werden nach diesem Script gerendert.
    // Deshalb Event-Delegation statt direkter Listener beim Seitenaufbau.
    document.addEventListener('click', event => {
        const clicked = event.target instanceof Element ? event.target : null;
        if (!clicked) return;

        const newButton = clicked.closest('[data-life-new-kind]');
        if (newButton) {
            const kind = newButton.dataset.lifeNewKind;
            if (!kind) return;
            event.preventDefault();
            openModal(kind);
            return;
        }

        // Gruppe / Eintrag / Balken / Ereignis direkt in der Timeline anklicken.
        const target = clicked.closest('[data-life-edit-kind]');
        if (!target) return;
        if (clicked.closest('.life-group-toggle')) return;

        const kind = target.dataset.lifeEditKind;
        const id = target.dataset.lifeEditId;
        if (!kind || !id) return;

        event.preventDefault();
        event.stopPropagation();
        openModal(kind, id);
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
            return;
        }

        if ((event.key === 'Enter' || event.key === ' ') && event.target instanceof Element) {
            const target = event.target.closest('[data-life-edit-kind]');
            if (!target || event.target.closest('.life-group-toggle')) return;
            event.preventDefault();
            openModal(target.dataset.lifeEditKind, target.dataset.lifeEditId);
        }
    });

    modal.querySelectorAll('[data-life-modal-close]').forEach(el => el.addEventListener('click', closeModal));

    field(forms.group, 'group_parent_id')?.addEventListener('change', syncGroupForm);
    field(forms.event, 'event_type')?.addEventListener('change', syncEventForm);

    // Scrollposition über den POST/Redirect hinweg pro Browser-Tab erhalten.
    const scrollStateKey = 'lifeEditScroll:' + window.location.pathname + window.location.search;

    function saveScrollState() {
        const bodyScroll = document.getElementById('lifeBodyScroll');
        sessionStorage.setItem(scrollStateKey, JSON.stringify({
            y: window.scrollY,
            bodyX: bodyScroll ? bodyScroll.scrollLeft : 0,
            savedAt: Date.now()
        }));
    }

    function restoreScrollState() {
        const raw = sessionStorage.getItem(scrollStateKey);
        if (!raw) return;

        sessionStorage.removeItem(scrollStateKey);

        try {
            const state = JSON.parse(raw);
            if (!state || Date.now() - Number(state.savedAt || 0) > 30000) return;

            const y = Math.max(0, Number(state.y) || 0);
            const bodyX = Math.max(0, Number(state.bodyX) || 0);

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    window.scrollTo({ top: y, left: 0, behavior: 'auto' });

                    const bodyScroll = document.getElementById('lifeBodyScroll');
                    if (bodyScroll) bodyScroll.scrollLeft = bodyX;
                });
            });
        } catch (e) {
            // Ungültigen alten SessionStorage-Eintrag einfach ignorieren.
        }
    }

    window.addEventListener('load', restoreScrollState, { once: true });

    Object.values(forms).forEach(form => {
        form.addEventListener('submit', event => {
            const submitter = event.submitter;
            if (submitter && submitter.matches('[data-delete-button]')) {
                const kind = form.dataset.lifeForm;
                const labels = {
                    group: 'Diese Gruppe wirklich löschen?',
                    entry: 'Diesen Eintrag wirklich löschen? Zeiträume und Ereignisse werden ebenfalls gelöscht.',
                    segment: 'Diesen Zeitraum wirklich löschen?',
                    event: 'Dieses Ereignis wirklich löschen?'
                };
                if (!window.confirm(labels[kind] || 'Wirklich löschen?')) {
                    event.preventDefault();
                    return;
                }
            }

            saveScrollState();
        });
    });

    const toast = document.getElementById('lifeEditToast');
    if (toast) {
        setTimeout(() => toast.classList.add('is-visible'), 20);
        setTimeout(() => toast.classList.remove('is-visible'), 3200);
    }
})();
</script>
<?php
    }
}

LifeTimelinePage::startSession();
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$conn = LifeTimelinePage::requireLifeConnection(['sciconn', 'conn', 'mysqli']);
LifePrivateEditor::handlePost($conn);

$view = LifeTimelinePage::buildViewData($conn);

$page_title = 'Studienplan';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';

LifePrivateEditor::renderInterface($conn);
LifeTimelinePage::renderApp($view, ['editable' => true]);
