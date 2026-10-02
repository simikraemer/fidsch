<?php
// fit/Gym.php
// SPA-artige Gym-Verwaltung: Trainingspläne, Übungen und Trainingserfassung ohne Seitenreloads.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$fitconn->set_charset('utf8mb4');
$fitconn->query("USE `fit`");
date_default_timezone_set('Europe/Berlin');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['gym_csrf'])) {
    $_SESSION['gym_csrf'] = bin2hex(random_bytes(32));
}
$gymCsrf = $_SESSION['gym_csrf'];


const GYM_WEEKDAYS = [
    1 => 'Montag',
    2 => 'Dienstag',
    3 => 'Mittwoch',
    4 => 'Donnerstag',
    5 => 'Freitag',
    6 => 'Samstag',
    7 => 'Sonntag',
];

// Tracking-Heuristik für Krafttraining: 1 kcal pro protokollierter Wiederholung.
// Der Wert wird pro Satz aus den tatsächlich gespeicherten Reps berechnet.
const GYM_STRENGTH_KCAL_PER_REP = 1;

function gymJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gymError(string $message, int $status = 400, array $extra = []): never
{
    gymJson(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function gymRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function gymIniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $last = strtolower($value[strlen($value) - 1]);
    $number = (float)$value;

    return match ($last) {
        'g' => (int)round($number * 1024 * 1024 * 1024),
        'm' => (int)round($number * 1024 * 1024),
        'k' => (int)round($number * 1024),
        default => (int)round($number),
    };
}

function gymEffectiveUploadLimit(): int
{
    $limits = [8 * 1024 * 1024];

    foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
        $bytes = gymIniBytes((string)ini_get($setting));
        if ($bytes > 0) {
            $limits[] = $bytes;
        }
    }

    return min($limits);
}

function gymDetectOversizedPost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = gymIniBytes((string)ini_get('post_max_size'));

    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && empty($_POST) && empty($_FILES)) {
        gymError(
            'Der Upload ist größer als das PHP-Limit post_max_size (' . ini_get('post_max_size') . ').',
            413,
            ['code' => 'POST_TOO_LARGE']
        );
    }
}

function gymRequirePostCsrf(array $data): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        gymError('Nur POST erlaubt.', 405);
    }

    $token = (string)($data['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $expected = (string)($_SESSION['gym_csrf'] ?? '');

    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        gymError('Ungültiger CSRF-Token. Seite neu laden.', 403);
    }
}

function gymFetchAll(mysqli_stmt $stmt): array
{
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function gymGetMuscles(mysqli $conn): array
{
    $muscles = [];
    $result = $conn->query("
        SELECT id, name, sort_order
        FROM gym_muscles
        ORDER BY sort_order ASC, id ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $muscles[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'sort_order' => (int)$row['sort_order'],
            ];
        }
        $result->free();
    }

    return $muscles;
}

function gymRelativeTime(?string $timestamp): string
{
    if (!$timestamp) {
        return 'Noch nie';
    }

    try {
        $then = new DateTimeImmutable($timestamp, new DateTimeZone('Europe/Berlin'));
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    } catch (Throwable $e) {
        return 'Unbekannt';
    }

    $thenDate = $then->setTime(0, 0);
    $nowDate = $now->setTime(0, 0);

    if ($thenDate >= $nowDate) {
        return 'Heute';
    }

    $days = (int)$thenDate->diff($nowDate)->format('%a');

    if ($days < 7) {
        return 'Vor ' . $days . ' ' . ($days === 1 ? 'Tag' : 'Tagen');
    }

    if ($days < 30) {
        $weeks = max(1, intdiv($days, 7));
        return 'Vor ' . $weeks . ' ' . ($weeks === 1 ? 'Woche' : 'Wochen');
    }

    if ($days < 365) {
        $months = max(1, intdiv($days, 30));
        return 'Vor ' . $months . ' ' . ($months === 1 ? 'Monat' : 'Monaten');
    }

    $years = max(1, intdiv($days, 365));
    return 'Vor ' . $years . ' ' . ($years === 1 ? 'Jahr' : 'Jahren');
}

function gymGetExerciseMuscles(mysqli $conn, int $exerciseId): array
{
    $data = [
        'primary_muscle_ids' => [],
        'secondary_muscle_ids' => [],
        'primary_muscles' => [],
        'secondary_muscles' => [],
    ];

    $stmt = $conn->prepare("
        SELECT em.muscle_id, em.role, m.name
        FROM gym_exercise_muscles em
        JOIN gym_muscles m ON m.id = em.muscle_id
        WHERE em.exercise_id = ?
        ORDER BY m.sort_order ASC, m.id ASC
    ");
    $stmt->bind_param('i', $exerciseId);
    $stmt->execute();

    foreach (gymFetchAll($stmt) as $row) {
        $muscleId = (int)$row['muscle_id'];
        $name = (string)$row['name'];

        if ($row['role'] === 'primary') {
            $data['primary_muscle_ids'][] = $muscleId;
            $data['primary_muscles'][] = $name;
        } elseif ($row['role'] === 'secondary') {
            $data['secondary_muscle_ids'][] = $muscleId;
            $data['secondary_muscles'][] = $name;
        }
    }

    $stmt->close();
    return $data;
}

function gymAttachExerciseMuscles(mysqli $conn, array &$exercise): void
{
    $exerciseId = (int)($exercise['exercise_id'] ?? $exercise['id'] ?? 0);

    if ($exerciseId <= 0) {
        $exercise['primary_muscle_ids'] = [];
        $exercise['secondary_muscle_ids'] = [];
        $exercise['primary_muscles'] = [];
        $exercise['secondary_muscles'] = [];
        return;
    }

    $muscles = gymGetExerciseMuscles($conn, $exerciseId);
    $exercise['primary_muscle_ids'] = $muscles['primary_muscle_ids'];
    $exercise['secondary_muscle_ids'] = $muscles['secondary_muscle_ids'];
    $exercise['primary_muscles'] = $muscles['primary_muscles'];
    $exercise['secondary_muscles'] = $muscles['secondary_muscles'];
}

function gymValidateMuscleIds(mysqli $conn, mixed $value): array
{
    $raw = is_array($value) ? $value : [];
    $ids = [];

    foreach ($raw as $muscleId) {
        $muscleId = (int)$muscleId;
        if ($muscleId > 0) {
            $ids[$muscleId] = true;
        }
    }

    if (!$ids) {
        return [];
    }

    $idList = implode(',', array_map('intval', array_keys($ids)));
    $valid = [];

    $result = $conn->query("
        SELECT id
        FROM gym_muscles
        WHERE id IN ({$idList})
        ORDER BY sort_order ASC, id ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $valid[] = (int)$row['id'];
        }
        $result->free();
    }

    return $valid;
}

function gymReplaceExerciseMuscles(
    mysqli $conn,
    int $exerciseId,
    array $primaryIds,
    array $secondaryIds
): void {
    $stmt = $conn->prepare("DELETE FROM gym_exercise_muscles WHERE exercise_id = ?");
    $stmt->bind_param('i', $exerciseId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO gym_exercise_muscles (exercise_id, muscle_id, role)
        VALUES (?, ?, ?)
    ");

    foreach ($primaryIds as $muscleId) {
        $muscleId = (int)$muscleId;
        $role = 'primary';
        $stmt->bind_param('iis', $exerciseId, $muscleId, $role);
        $stmt->execute();
    }

    foreach ($secondaryIds as $muscleId) {
        $muscleId = (int)$muscleId;
        $role = 'secondary';
        $stmt->bind_param('iis', $exerciseId, $muscleId, $role);
        $stmt->execute();
    }

    $stmt->close();
}

function gymUploadImage(?array $file, ?string $oldPath = null): ?string
{
    if (!$file || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return $oldPath;
    }

    $uploadError = (int)$file['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE => 'Das Bild überschreitet upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE => 'Das Bild überschreitet das erlaubte Formular-Limit.',
            UPLOAD_ERR_PARTIAL => 'Das Bild wurde nur teilweise hochgeladen.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP hat kein temporäres Upload-Verzeichnis.',
            UPLOAD_ERR_CANT_WRITE => 'PHP konnte die Upload-Datei nicht auf die Festplatte schreiben.',
            UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload gestoppt.',
            default => 'Bild-Upload fehlgeschlagen (PHP-Uploadcode ' . $uploadError . ').',
        };
        gymError($message, 400, ['code' => 'UPLOAD_ERROR_' . $uploadError]);
    }

    $effectiveLimit = gymEffectiveUploadLimit();
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $effectiveLimit) {
        gymError(
            'Das Bild ist zu groß. Aktuelles Server-Limit: ' . round($effectiveLimit / 1024 / 1024, 1) . ' MiB.',
            413,
            ['code' => 'UPLOAD_TOO_LARGE']
        );
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        gymError('Ungültiger Bild-Upload.', 400, ['code' => 'INVALID_UPLOAD']);
    }

    $imageInfo = @getimagesize($tmp);
    if ($imageInfo === false || empty($imageInfo['mime'])) {
        gymError('Die hochgeladene Datei ist kein gültiges Bild.');
    }

    $mime = strtolower((string)$imageInfo['mime']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        gymError('Erlaubte Bildformate: JPG, PNG und WebP.');
    }

    $uploadDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/uploads/gym';
    if ($uploadDir === '/uploads/gym') {
        $uploadDir = dirname(__DIR__) . '/uploads/gym';
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        gymError('Upload-Verzeichnis konnte nicht angelegt werden.', 500);
    }

    $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        gymError('Bild konnte nicht gespeichert werden.', 500);
    }

    @chmod($destination, 0640);

    $newPath = '/uploads/gym/' . $filename;

    // Altes Bild erst löschen, nachdem das neue sicher gespeichert wurde.
    if ($oldPath && str_starts_with($oldPath, '/uploads/gym/')) {
        $oldFile = $uploadDir . '/' . basename($oldPath);
        if (is_file($oldFile) && realpath(dirname($oldFile)) === realpath($uploadDir)) {
            @unlink($oldFile);
        }
    }

    return $newPath;
}

function gymExerciseImageUrl(int $exerciseId, ?string $imagePath): ?string
{
    if (!$imagePath) {
        return null;
    }

    return '/fit/gym?api=image&exercise_id=' . $exerciseId
        . '&v=' . rawurlencode(basename($imagePath));
}

function gymPlanImageUrl(int $planId, ?string $imagePath): ?string
{
    if (!$imagePath) {
        return null;
    }

    return '/fit/gym?api=image&plan_id=' . $planId
        . '&v=' . rawurlencode(basename($imagePath));
}

function gymGetExercise(mysqli $conn, int $exerciseId, bool $includeArchived = false): ?array
{
    $sql = "
        SELECT id, name, image_path, type, cardio_mode,
               cardio_kcal_per_hour, cardio_kcal_per_km, is_archived, created_at
        FROM gym_exercises
        WHERE id = ?
    ";
    if (!$includeArchived) {
        $sql .= " AND is_archived = 0";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $exerciseId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        $row['id'] = (int)$row['id'];
        $row['cardio_mode'] = $row['type'] === 'kardio' ? (($row['cardio_mode'] ?? null) ?: 'zeit') : null;
        $row['cardio_kcal_per_hour'] = $row['cardio_kcal_per_hour'] !== null ? (int)$row['cardio_kcal_per_hour'] : null;
        $row['cardio_kcal_per_km'] = $row['cardio_kcal_per_km'] !== null ? (float)$row['cardio_kcal_per_km'] : null;
        $row['is_archived'] = (bool)$row['is_archived'];
        gymAttachExerciseMuscles($conn, $row);
        $row['image_url'] = gymExerciseImageUrl((int)$row['id'], $row['image_path']);
    }

    return $row;
}

function gymGetActiveSession(mysqli $conn): ?array
{
    $result = $conn->query("
        SELECT s.id, s.plan_id, s.started_at, p.name AS plan_name, p.image_path AS plan_image_path
        FROM gym_sessions s
        JOIN gym_plans p ON p.id = s.plan_id
        WHERE s.finished_at IS NULL
        ORDER BY s.started_at DESC, s.id DESC
        LIMIT 1
    ");

    if (!$result) {
        return null;
    }

    $row = $result->fetch_assoc() ?: null;
    $result->free();

    if ($row) {
        $row['id'] = (int)$row['id'];
        $row['plan_id'] = (int)$row['plan_id'];
        $row['plan_image_url'] = gymPlanImageUrl((int)$row['plan_id'], $row['plan_image_path']);
    }

    return $row;
}

function gymGetPlanDetail(mysqli $conn, int $planId, bool $includeArchived = false): ?array
{
    $sql = "SELECT id, name, image_path, is_quick, is_archived, created_at FROM gym_plans WHERE id = ?";
    if (!$includeArchived) {
        $sql .= " AND is_archived = 0";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$plan) {
        return null;
    }

    $plan['id'] = (int)$plan['id'];
    $plan['is_quick'] = !empty($plan['is_quick']);
    $plan['is_archived'] = (bool)$plan['is_archived'];
    $plan['image_url'] = gymPlanImageUrl((int)$plan['id'], $plan['image_path']);

    $stmt = $conn->prepare("SELECT weekday FROM gym_plan_days WHERE plan_id = ? ORDER BY weekday ASC");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $days = [];
    foreach (gymFetchAll($stmt) as $row) {
        $days[] = (int)$row['weekday'];
    }
    $stmt->close();
    $plan['days'] = $days;

    $stmt = $conn->prepare("
        SELECT
            pe.id AS plan_exercise_id,
            pe.exercise_id,
            pe.sort_order,
            e.name,
            e.type,
            e.cardio_mode,
            e.image_path,
            e.cardio_kcal_per_hour,
            e.cardio_kcal_per_km,
            e.is_archived AS exercise_archived
        FROM gym_plan_exercises pe
        JOIN gym_exercises e ON e.id = pe.exercise_id
        WHERE pe.plan_id = ?
          AND pe.is_archived = 0
        ORDER BY pe.sort_order ASC, pe.id ASC
    ");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $exercises = gymFetchAll($stmt);
    $stmt->close();

    foreach ($exercises as &$row) {
        $row['plan_exercise_id'] = (int)$row['plan_exercise_id'];
        $row['exercise_id'] = (int)$row['exercise_id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['cardio_mode'] = $row['type'] === 'kardio' ? (($row['cardio_mode'] ?? null) ?: 'zeit') : null;
        $row['cardio_kcal_per_hour'] = $row['cardio_kcal_per_hour'] !== null ? (int)$row['cardio_kcal_per_hour'] : null;
        $row['cardio_kcal_per_km'] = $row['cardio_kcal_per_km'] !== null ? (float)$row['cardio_kcal_per_km'] : null;
        $row['exercise_archived'] = (bool)$row['exercise_archived'];
        gymAttachExerciseMuscles($conn, $row);
        $row['image_url'] = gymExerciseImageUrl((int)$row['exercise_id'], $row['image_path']);
    }
    unset($row);

    $plan['exercises'] = $exercises;
    return $plan;
}

function gymGetSessionDetail(mysqli $conn, int $sessionId): ?array
{
    $stmt = $conn->prepare("
        SELECT s.id, s.plan_id, s.started_at, s.finished_at,
               p.name AS plan_name, p.image_path AS plan_image_path, p.is_quick AS plan_is_quick
        FROM gym_sessions s
        JOIN gym_plans p ON p.id = s.plan_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$session) {
        return null;
    }

    $session['id'] = (int)$session['id'];
    $session['plan_id'] = (int)$session['plan_id'];
    $session['is_quick'] = !empty($session['plan_is_quick']);
    unset($session['plan_is_quick']);
    $session['is_finished'] = $session['finished_at'] !== null;
    $session['plan_image_url'] = gymPlanImageUrl((int)$session['plan_id'], $session['plan_image_path']);

    $planId = (int)$session['plan_id'];

    // Laufende Trainings folgen dem aktuellen Plan. Abgeschlossene Logs zeigen dagegen
    // exakt die Übungen, für die in dieser Session tatsächlich Sätze gespeichert wurden.
    // So bleiben Logs auch nach späteren Planänderungen/Archivierungen editierbar.
    if ($session['is_finished']) {
        $stmt = $conn->prepare("
            SELECT
                pe.id AS plan_exercise_id,
                pe.exercise_id,
                pe.sort_order,
                e.name,
                e.type,
                e.cardio_mode,
                e.image_path,
                e.cardio_kcal_per_hour,
                e.cardio_kcal_per_km
            FROM gym_plan_exercises pe
            JOIN gym_exercises e ON e.id = pe.exercise_id
            WHERE pe.plan_id = ?
              AND EXISTS (
                  SELECT 1
                  FROM gym_session_sets ss
                  WHERE ss.session_id = ?
                    AND ss.plan_exercise_id = pe.id
              )
            ORDER BY pe.sort_order ASC, pe.id ASC
        ");
        $stmt->bind_param('ii', $planId, $sessionId);
    } else {
        $stmt = $conn->prepare("
            SELECT
                pe.id AS plan_exercise_id,
                pe.exercise_id,
                pe.sort_order,
                e.name,
                e.type,
                e.cardio_mode,
                e.image_path,
                e.cardio_kcal_per_hour,
                e.cardio_kcal_per_km
            FROM gym_plan_exercises pe
            JOIN gym_exercises e ON e.id = pe.exercise_id
            WHERE pe.plan_id = ?
              AND pe.is_archived = 0
            ORDER BY pe.sort_order ASC, pe.id ASC
        ");
        $stmt->bind_param('i', $planId);
    }
    $stmt->execute();
    $exerciseRows = gymFetchAll($stmt);
    $stmt->close();

    $sessionCalories = 0;
    $exercises = [];

    foreach ($exerciseRows as $exerciseRow) {
        $planExerciseId = (int)$exerciseRow['plan_exercise_id'];
        $exerciseId = (int)$exerciseRow['exercise_id'];

        $stmt = $conn->prepare("
            SELECT id, set_number, reps, weight, cardio_minutes, cardio_distance_km,
                   calories, calories_manual, created_at
            FROM gym_session_sets
            WHERE session_id = ? AND plan_exercise_id = ?
            ORDER BY set_number ASC
        ");
        $stmt->bind_param('ii', $sessionId, $planExerciseId);
        $stmt->execute();
        $currentSets = gymFetchAll($stmt);
        $stmt->close();

        $maxCurrentSet = 0;
        foreach ($currentSets as &$setRow) {
            $setRow['id'] = (int)$setRow['id'];
            $setRow['set_number'] = (int)$setRow['set_number'];
            $setRow['reps'] = $setRow['reps'] !== null ? (int)$setRow['reps'] : null;
            $setRow['weight'] = $setRow['weight'] !== null ? (float)$setRow['weight'] : null;
            $setRow['cardio_minutes'] = $setRow['cardio_minutes'] !== null ? (int)$setRow['cardio_minutes'] : null;
            $setRow['cardio_distance_km'] = $setRow['cardio_distance_km'] !== null ? (float)$setRow['cardio_distance_km'] : null;
            $setRow['calories'] = $setRow['calories'] !== null ? (int)$setRow['calories'] : null;
            $setRow['calories_manual'] = !empty($setRow['calories_manual']);
            $maxCurrentSet = max($maxCurrentSet, $setRow['set_number']);
            $sessionCalories += (int)($setRow['calories'] ?? 0);
        }
        unset($setRow);

        // Letztes vorheriges Training derselben Übung – unabhängig vom Trainingsplan.
        $stmt = $conn->prepare("
            SELECT s.id, s.started_at
            FROM gym_sessions s
            JOIN gym_session_sets ss ON ss.session_id = s.id
            JOIN gym_plan_exercises old_pe ON old_pe.id = ss.plan_exercise_id
            WHERE old_pe.exercise_id = ?
              AND s.id <> ?
              AND s.started_at < ?
            GROUP BY s.id, s.started_at
            ORDER BY s.started_at DESC, s.id DESC
            LIMIT 1
        ");
        $stmt->bind_param('iis', $exerciseId, $sessionId, $session['started_at']);
        $stmt->execute();
        $previousSession = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        $previousSets = [];
        $previousWhen = 'Noch nie';
        $previousStartedAt = null;

        if ($previousSession) {
            $previousSessionId = (int)$previousSession['id'];
            $previousStartedAt = $previousSession['started_at'];
            $previousWhen = gymRelativeTime($previousStartedAt);

            $stmt = $conn->prepare("
                SELECT ss.set_number, ss.reps, ss.weight, ss.cardio_minutes, ss.cardio_distance_km,
                       ss.calories, ss.calories_manual
                FROM gym_session_sets ss
                JOIN gym_plan_exercises old_pe ON old_pe.id = ss.plan_exercise_id
                WHERE ss.session_id = ?
                  AND old_pe.exercise_id = ?
                ORDER BY ss.set_number ASC, ss.id ASC
            ");
            $stmt->bind_param('ii', $previousSessionId, $exerciseId);
            $stmt->execute();
            $previousSets = gymFetchAll($stmt);
            $stmt->close();

            foreach ($previousSets as &$previousSet) {
                $previousSet['set_number'] = (int)$previousSet['set_number'];
                $previousSet['reps'] = $previousSet['reps'] !== null ? (int)$previousSet['reps'] : null;
                $previousSet['weight'] = $previousSet['weight'] !== null ? (float)$previousSet['weight'] : null;
                $previousSet['cardio_minutes'] = $previousSet['cardio_minutes'] !== null ? (int)$previousSet['cardio_minutes'] : null;
                $previousSet['cardio_distance_km'] = $previousSet['cardio_distance_km'] !== null ? (float)$previousSet['cardio_distance_km'] : null;
                $previousSet['calories'] = $previousSet['calories'] !== null ? (int)$previousSet['calories'] : null;
                $previousSet['calories_manual'] = !empty($previousSet['calories_manual']);
            }
            unset($previousSet);
        }

        // Die Anzahl der beim Öffnen angezeigten Sätze kommt nicht mehr aus dem
        // Trainingsplan. Für Kraft wird die Anzahl des letzten Trainings derselben
        // Übung übernommen. Ohne Historie starten Kraftübungen mit 3 Sätzen.
        // Kardio bleibt ein einzelner Eintrag.
        $baseSetCount = $exerciseRow['type'] === 'kardio'
            ? 1
            : ($previousSets ? count($previousSets) : 3);

        $exerciseMuscles = gymGetExerciseMuscles($conn, $exerciseId);

        // Bei abgeschlossenen Logs die historische Kardio-Erfassungsart aus dem
        // tatsächlich gespeicherten Satz ableiten. So bleiben alte Zeit-Logs
        // korrekt editierbar, selbst wenn die Übung später auf Strecke (oder umgekehrt)
        // umgestellt wurde.
        $resolvedCardioMode = $exerciseRow['type'] === 'kardio'
            ? (($exerciseRow['cardio_mode'] ?? null) ?: 'zeit')
            : null;
        if ($session['is_finished'] && $exerciseRow['type'] === 'kardio' && $currentSets) {
            foreach ($currentSets as $historicalSet) {
                if ($historicalSet['cardio_distance_km'] !== null) {
                    $resolvedCardioMode = 'strecke';
                    break;
                }
                if ($historicalSet['cardio_minutes'] !== null) {
                    $resolvedCardioMode = 'zeit';
                    break;
                }
            }
        }

        $exercises[] = [
            'plan_exercise_id' => $planExerciseId,
            'exercise_id' => $exerciseId,
            'sort_order' => (int)$exerciseRow['sort_order'],
            'name' => $exerciseRow['name'],
            'type' => $exerciseRow['type'],
            'cardio_mode' => $resolvedCardioMode,
            'image_url' => gymExerciseImageUrl($exerciseId, $exerciseRow['image_path']),
            'primary_muscle_ids' => $exerciseMuscles['primary_muscle_ids'],
            'secondary_muscle_ids' => $exerciseMuscles['secondary_muscle_ids'],
            'primary_muscles' => $exerciseMuscles['primary_muscles'],
            'secondary_muscles' => $exerciseMuscles['secondary_muscles'],
            'cardio_kcal_per_hour' => $exerciseRow['cardio_kcal_per_hour'] !== null ? (int)$exerciseRow['cardio_kcal_per_hour'] : null,
            'cardio_kcal_per_km' => $exerciseRow['cardio_kcal_per_km'] !== null ? (float)$exerciseRow['cardio_kcal_per_km'] : null,
            'current_sets' => $currentSets,
            'previous_sets' => $previousSets,
            'previous_started_at' => $previousStartedAt,
            'previous_when' => $previousWhen,
            'display_set_count' => max($baseSetCount, $maxCurrentSet),
        ];
    }

    $session['exercises'] = $exercises;
    $session['calories'] = $sessionCalories;
    return $session;
}

$api = isset($_GET['api']) ? trim((string)$_GET['api']) : '';

if ($api !== '') {
    try {
        gymDetectOversizedPost();

        if ($api === 'image') {
            $exerciseId = (int)($_GET['exercise_id'] ?? 0);
            $planId = (int)($_GET['plan_id'] ?? 0);
            $imagePath = null;

            if ($exerciseId > 0) {
                $exercise = gymGetExercise($fitconn, $exerciseId, true);
                $imagePath = $exercise['image_path'] ?? null;
            } elseif ($planId > 0) {
                $stmt = $fitconn->prepare("SELECT image_path FROM gym_plans WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $planImageRow = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                $imagePath = $planImageRow['image_path'] ?? null;
            }

            if (!$imagePath || !str_starts_with($imagePath, '/uploads/gym/')) {
                http_response_code(404);
                exit;
            }

            $uploadDir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/uploads/gym';
            if ($uploadDir === '/uploads/gym') {
                $uploadDir = dirname(__DIR__) . '/uploads/gym';
            }
            $file = $uploadDir . '/' . basename($imagePath);

            if (!is_file($file) || realpath(dirname($file)) !== realpath($uploadDir)) {
                http_response_code(404);
                exit;
            }

            $mime = mime_content_type($file) ?: 'application/octet-stream';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                http_response_code(415);
                exit;
            }

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }

        if ($api === 'bootstrap') {
            $todayWeekday = (int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('N');
            $muscles = gymGetMuscles($fitconn);

            $exercises = [];
            $result = $fitconn->query("
                SELECT id, name, image_path, type, cardio_mode,
                       cardio_kcal_per_hour, cardio_kcal_per_km, created_at
                FROM gym_exercises
                WHERE is_archived = 0
                ORDER BY name ASC, id ASC
            ");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $row['id'] = (int)$row['id'];
                    $row['cardio_mode'] = $row['type'] === 'kardio' ? (($row['cardio_mode'] ?? null) ?: 'zeit') : null;
                    $row['cardio_kcal_per_hour'] = $row['cardio_kcal_per_hour'] !== null ? (int)$row['cardio_kcal_per_hour'] : null;
                    $row['cardio_kcal_per_km'] = $row['cardio_kcal_per_km'] !== null ? (float)$row['cardio_kcal_per_km'] : null;
                    gymAttachExerciseMuscles($fitconn, $row);
                    $row['image_url'] = gymExerciseImageUrl((int)$row['id'], $row['image_path']);
                    $exercises[] = $row;
                }
                $result->free();
            }

            $plans = [];
            $result = $fitconn->query("
                SELECT
                    plan_rows.id,
                    plan_rows.name,
                    plan_rows.image_path,
                    plan_rows.created_at,
                    plan_rows.days,
                    plan_rows.exercise_count,
                    plan_rows.next_due_in
                FROM (
                    SELECT
                        p.id,
                        p.name,
                        p.image_path,
                        p.created_at,
                        GROUP_CONCAT(DISTINCT pd.weekday ORDER BY pd.weekday SEPARATOR ',') AS days,
                        COUNT(DISTINCT CASE WHEN pe.is_archived = 0 THEN pe.id END) AS exercise_count,
                        MIN(
                            CASE
                                WHEN pd.weekday IS NULL THEN NULL
                                ELSE MOD(pd.weekday - {$todayWeekday} + 7, 7)
                            END
                        ) AS next_due_in
                    FROM gym_plans p
                    LEFT JOIN gym_plan_days pd ON pd.plan_id = p.id
                    LEFT JOIN gym_plan_exercises pe ON pe.plan_id = p.id
                    WHERE p.is_archived = 0
                      AND p.is_quick = 0
                    GROUP BY p.id, p.name, p.image_path, p.created_at
                ) AS plan_rows
                ORDER BY
                    (plan_rows.next_due_in IS NULL) ASC,
                    plan_rows.next_due_in ASC,
                    plan_rows.name ASC,
                    plan_rows.id ASC
            ");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $days = $row['days'] !== null && $row['days'] !== ''
                        ? array_map('intval', explode(',', $row['days']))
                        : [];

                    $plans[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'image_path' => $row['image_path'],
                        'image_url' => gymPlanImageUrl((int)$row['id'], $row['image_path']),
                        'days' => $days,
                        'exercise_count' => (int)$row['exercise_count'],
                        'is_today' => in_array($todayWeekday, $days, true),
                        'next_due_in' => $row['next_due_in'] !== null ? (int)$row['next_due_in'] : null,
                        'created_at' => $row['created_at'],
                    ];
                }
                $result->free();
            }

            // Muskelgruppen der Trainingskarten werden ausschließlich aus der
            // ID-basierten Relation gym_exercise_muscles berechnet.
            // Relevanz: primär = 1, sekundär = 0,5; Anzeige ab 1,5.
            $planMuscleStats = [];
            $muscleResult = $fitconn->query("
                SELECT
                    pe.plan_id,
                    em.muscle_id,
                    m.name,
                    m.sort_order,
                    COUNT(DISTINCT CASE WHEN em.role = 'primary' THEN pe.exercise_id END) AS primary_count,
                    COUNT(DISTINCT CASE WHEN em.role = 'secondary' THEN pe.exercise_id END) AS secondary_count
                FROM gym_plan_exercises pe
                JOIN gym_plans p ON p.id = pe.plan_id
                JOIN gym_exercises e ON e.id = pe.exercise_id
                JOIN gym_exercise_muscles em ON em.exercise_id = e.id
                JOIN gym_muscles m ON m.id = em.muscle_id
                WHERE p.is_archived = 0
                  AND p.is_quick = 0
                  AND pe.is_archived = 0
                GROUP BY pe.plan_id, em.muscle_id, m.name, m.sort_order
            ");

            if ($muscleResult) {
                while ($row = $muscleResult->fetch_assoc()) {
                    $primaryCount = (int)$row['primary_count'];
                    $secondaryCount = (int)$row['secondary_count'];
                    $relevance = $primaryCount + ($secondaryCount * 0.5);

                    if ($relevance >= 1.5) {
                        $planMuscleStats[(int)$row['plan_id']][] = [
                            'id' => (int)$row['muscle_id'],
                            'name' => (string)$row['name'],
                            'sort_order' => (int)$row['sort_order'],
                            'primary_count' => $primaryCount,
                            'secondary_count' => $secondaryCount,
                            'relevance' => $relevance,
                        ];
                    }
                }
                $muscleResult->free();
            }

            foreach ($plans as &$plan) {
                $groups = $planMuscleStats[(int)$plan['id']] ?? [];

                usort($groups, static function (array $a, array $b): int {
                    $scoreCompare = ((float)$b['relevance']) <=> ((float)$a['relevance']);
                    if ($scoreCompare !== 0) {
                        return $scoreCompare;
                    }

                    $primaryCompare = ((int)$b['primary_count']) <=> ((int)$a['primary_count']);
                    if ($primaryCompare !== 0) {
                        return $primaryCompare;
                    }

                    $secondaryCompare = ((int)$b['secondary_count']) <=> ((int)$a['secondary_count']);
                    if ($secondaryCompare !== 0) {
                        return $secondaryCompare;
                    }

                    return ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
                });

                $plan['muscle_groups'] = $groups;
            }
            unset($plan);

            gymJson([
                'ok' => true,
                'csrf' => $_SESSION['gym_csrf'],
                'muscles' => $muscles,
                'weekdays' => GYM_WEEKDAYS,
                'today_weekday' => $todayWeekday,
                'upload_max_bytes' => gymEffectiveUploadLimit(),
                'upload_max_label' => round(gymEffectiveUploadLimit() / 1024 / 1024, 1) . ' MiB',
                'plans' => $plans,
                'exercises' => $exercises,
                'active_session' => gymGetActiveSession($fitconn),
            ]);
        }

        if ($api === 'logs') {
            $logs = [];
            $result = $fitconn->query("
                SELECT
                    s.id,
                    s.plan_id,
                    s.started_at,
                    s.finished_at,
                    p.name AS plan_name,
                    p.image_path AS plan_image_path,
                    p.is_quick AS plan_is_quick,
                    COALESCE(SUM(COALESCE(ss.calories, 0)), 0) AS calories,
                    COUNT(ss.id) AS set_count
                FROM gym_sessions s
                JOIN gym_plans p ON p.id = s.plan_id
                LEFT JOIN gym_session_sets ss ON ss.session_id = s.id
                LEFT JOIN gym_plan_exercises pe ON pe.id = ss.plan_exercise_id
                LEFT JOIN gym_exercises e ON e.id = pe.exercise_id
                WHERE s.finished_at IS NOT NULL
                GROUP BY
                    s.id,
                    s.plan_id,
                    s.started_at,
                    s.finished_at,
                    p.name,
                    p.image_path,
                    p.is_quick
                ORDER BY s.started_at DESC, s.id DESC
            ");

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $logs[] = [
                        'id' => (int)$row['id'],
                        'plan_id' => (int)$row['plan_id'],
                        'plan_name' => (string)$row['plan_name'],
                        'is_quick' => !empty($row['plan_is_quick']),
                        'plan_image_url' => gymPlanImageUrl((int)$row['plan_id'], $row['plan_image_path']),
                        'started_at' => $row['started_at'],
                        'finished_at' => $row['finished_at'],
                        'calories' => (int)$row['calories'],
                        'set_count' => (int)$row['set_count'],
                    ];
                }
                $result->free();
            }

            gymJson(['ok' => true, 'logs' => $logs]);
        }

        if ($api === 'plan_detail') {
            $planId = (int)($_GET['id'] ?? 0);
            $plan = $planId > 0 ? gymGetPlanDetail($fitconn, $planId) : null;
            if (!$plan) {
                gymError('Trainingsplan nicht gefunden.', 404);
            }
            gymJson(['ok' => true, 'plan' => $plan]);
        }

        if ($api === 'exercise_detail') {
            $exerciseId = (int)($_GET['id'] ?? 0);
            $exercise = $exerciseId > 0 ? gymGetExercise($fitconn, $exerciseId) : null;
            if (!$exercise) {
                gymError('Übung nicht gefunden.', 404);
            }

            $stmt = $fitconn->prepare("
                SELECT p.id, p.name
                FROM gym_plan_exercises pe
                JOIN gym_plans p ON p.id = pe.plan_id
                WHERE pe.exercise_id = ?
                  AND pe.is_archived = 0
                  AND p.is_archived = 0
                  AND p.is_quick = 0
                ORDER BY p.name ASC
            ");
            $stmt->bind_param('i', $exerciseId);
            $stmt->execute();
            $usedInPlans = gymFetchAll($stmt);
            $stmt->close();
            foreach ($usedInPlans as &$planRow) {
                $planRow['id'] = (int)$planRow['id'];
            }
            unset($planRow);

            // Letzten tatsächlich gespeicherten Satz dieser Übung laden. Dieser Wert dient
            // ausschließlich als komfortabler Vorschlag für den Direkteintrag auf der
            // Übungsdetailseite; es wird dadurch noch keine Session erzeugt.
            $stmt = $fitconn->prepare("
                SELECT
                    ss.reps,
                    ss.weight,
                    ss.cardio_minutes,
                    ss.cardio_distance_km,
                    ss.calories,
                    ss.calories_manual,
                    s.started_at
                FROM gym_session_sets ss
                JOIN gym_plan_exercises pe ON pe.id = ss.plan_exercise_id
                JOIN gym_sessions s ON s.id = ss.session_id
                WHERE pe.exercise_id = ?
                  AND s.finished_at IS NOT NULL
                ORDER BY s.started_at DESC, ss.id DESC
                LIMIT 1
            " );
            $stmt->bind_param('i', $exerciseId);
            $stmt->execute();
            $directPrevious = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            $directPreviousWhen = 'Noch nie';
            if ($directPrevious) {
                $directPrevious['reps'] = $directPrevious['reps'] !== null ? (int)$directPrevious['reps'] : null;
                $directPrevious['weight'] = $directPrevious['weight'] !== null ? (float)$directPrevious['weight'] : null;
                $directPrevious['cardio_minutes'] = $directPrevious['cardio_minutes'] !== null ? (int)$directPrevious['cardio_minutes'] : null;
                $directPrevious['cardio_distance_km'] = $directPrevious['cardio_distance_km'] !== null ? (float)$directPrevious['cardio_distance_km'] : null;
                $directPrevious['calories'] = $directPrevious['calories'] !== null ? (int)$directPrevious['calories'] : null;
                $directPrevious['calories_manual'] = !empty($directPrevious['calories_manual']);
                $directPreviousWhen = gymRelativeTime((string)$directPrevious['started_at']);
            }

            gymJson([
                'ok' => true,
                'exercise' => $exercise,
                'used_in_plans' => $usedInPlans,
                'direct_previous' => $directPrevious,
                'direct_previous_when' => $directPreviousWhen,
            ]);
        }

        if ($api === 'session_detail') {
            $sessionId = (int)($_GET['id'] ?? 0);
            $session = $sessionId > 0 ? gymGetSessionDetail($fitconn, $sessionId) : null;
            if (!$session) {
                gymError('Training nicht gefunden.', 404);
            }
            gymJson(['ok' => true, 'session' => $session]);
        }

        if ($api === 'exercise_save') {
            gymRequirePostCsrf($_POST);

            $exerciseId = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $type = trim((string)($_POST['type'] ?? ''));

            if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
                gymError('Bitte einen gültigen Übungsnamen angeben.');
            }
            if (!in_array($type, ['kraft', 'kardio'], true)) {
                gymError('Ungültiger Übungstyp.');
            }

            $existing = $exerciseId > 0 ? gymGetExercise($fitconn, $exerciseId, true) : null;
            if ($exerciseId > 0 && !$existing) {
                gymError('Übung nicht gefunden.', 404);
            }

            $primaryIds = [];
            $secondaryIds = [];
            $cardioMode = null;
            $cardioKcalPerHour = null;
            $cardioKcalPerKm = null;

            if ($type === 'kraft') {
                $primaryRaw = json_decode((string)($_POST['primary_muscle_ids'] ?? '[]'), true);
                $secondaryRaw = json_decode((string)($_POST['secondary_muscle_ids'] ?? '[]'), true);

                $primaryIds = gymValidateMuscleIds($fitconn, $primaryRaw);
                $secondaryIds = gymValidateMuscleIds($fitconn, $secondaryRaw);

                if (!$primaryIds) {
                    gymError('Mindestens eine primäre Muskelgruppe auswählen.');
                }

                // Dieselbe Muskel-ID darf nicht gleichzeitig primär und sekundär sein.
                $primaryLookup = array_fill_keys($primaryIds, true);
                $secondaryIds = array_values(array_filter(
                    $secondaryIds,
                    static fn(int $muscleId): bool => !isset($primaryLookup[$muscleId])
                ));
            } else {
                $cardioMode = trim((string)($_POST['cardio_mode'] ?? 'zeit'));
                if (!in_array($cardioMode, ['zeit', 'strecke'], true)) {
                    gymError('Ungültige Kardio-Erfassungsart.');
                }

                if ($cardioMode === 'zeit') {
                    $cardioKcalPerHour = (int)($_POST['cardio_kcal_per_hour'] ?? 0);
                    if ($cardioKcalPerHour <= 0 || $cardioKcalPerHour > 5000) {
                        gymError('Bitte einen gültigen kcal-Verbrauch pro Stunde angeben.');
                    }
                } else {
                    $kcalPerKmRaw = str_replace(',', '.', (string)($_POST['cardio_kcal_per_km'] ?? ''));
                    $cardioKcalPerKm = is_numeric($kcalPerKmRaw) ? (float)$kcalPerKmRaw : -1;
                    if ($cardioKcalPerKm <= 0 || $cardioKcalPerKm > 5000) {
                        gymError('Bitte einen gültigen kcal-Verbrauch pro Kilometer angeben.');
                    }
                }
            }

            $oldPath = $existing['image_path'] ?? null;
            $imagePath = gymUploadImage($_FILES['image'] ?? null, $oldPath);
            if ($exerciseId <= 0 && !$imagePath) {
                gymError('Bitte ein Bild für die Übung hochladen.');
            }

            $fitconn->begin_transaction();

            try {
                if ($exerciseId > 0) {
                    $stmt = $fitconn->prepare("
                        UPDATE gym_exercises
                        SET name = ?, image_path = ?, type = ?, cardio_mode = ?,
                            cardio_kcal_per_hour = ?, cardio_kcal_per_km = ?,
                            is_archived = 0
                        WHERE id = ?
                    ");
                    $stmt->bind_param(
                        'ssssidi',
                        $name,
                        $imagePath,
                        $type,
                        $cardioMode,
                        $cardioKcalPerHour,
                        $cardioKcalPerKm,
                        $exerciseId
                    );
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $fitconn->prepare("
                        INSERT INTO gym_exercises
                            (name, image_path, type, cardio_mode, cardio_kcal_per_hour, cardio_kcal_per_km)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        'ssssid',
                        $name,
                        $imagePath,
                        $type,
                        $cardioMode,
                        $cardioKcalPerHour,
                        $cardioKcalPerKm
                    );
                    $stmt->execute();
                    $exerciseId = (int)$fitconn->insert_id;
                    $stmt->close();
                }

                gymReplaceExerciseMuscles(
                    $fitconn,
                    $exerciseId,
                    $type === 'kraft' ? $primaryIds : [],
                    $type === 'kraft' ? $secondaryIds : []
                );

                $fitconn->commit();
            } catch (Throwable $e) {
                $fitconn->rollback();
                throw $e;
            }

            gymJson(['ok' => true, 'exercise' => gymGetExercise($fitconn, $exerciseId)]);
        }

        if ($api === 'exercise_delete') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);
            $exerciseId = (int)($data['id'] ?? 0);

            if ($exerciseId <= 0 || !gymGetExercise($fitconn, $exerciseId)) {
                gymError('Übung nicht gefunden.', 404);
            }

            $stmt = $fitconn->prepare("
                SELECT COUNT(*) AS c
                FROM gym_plan_exercises pe
                JOIN gym_plans p ON p.id = pe.plan_id
                WHERE pe.exercise_id = ?
                  AND pe.is_archived = 0
                  AND p.is_archived = 0
            ");
            $stmt->bind_param('i', $exerciseId);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($count > 0) {
                gymError('Die Übung ist noch in einem aktiven Trainingsplan. Entferne sie dort zuerst.', 409);
            }

            $stmt = $fitconn->prepare("UPDATE gym_exercises SET is_archived = 1 WHERE id = ?");
            $stmt->bind_param('i', $exerciseId);
            $stmt->execute();
            $stmt->close();

            gymJson(['ok' => true]);
        }

        if ($api === 'plan_save') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);

            $planId = (int)($data['id'] ?? 0);
            $name = trim((string)($data['name'] ?? ''));

            $daysValue = $data['days'] ?? [];
            if (is_string($daysValue)) {
                $decodedDays = json_decode($daysValue, true);
                $daysValue = is_array($decodedDays) ? $decodedDays : [];
            }
            $daysRaw = is_array($daysValue) ? $daysValue : [];

            $itemsValue = $data['exercises'] ?? [];
            if (is_string($itemsValue)) {
                $decodedItems = json_decode($itemsValue, true);
                $itemsValue = is_array($decodedItems) ? $decodedItems : [];
            }
            $items = is_array($itemsValue) ? $itemsValue : [];

            if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
                gymError('Bitte einen gültigen Namen für den Trainingsplan angeben.');
            }

            $days = [];
            foreach ($daysRaw as $day) {
                $day = (int)$day;
                if ($day >= 1 && $day <= 7 && !in_array($day, $days, true)) {
                    $days[] = $day;
                }
            }
            sort($days);

            if (!$days) {
                gymError('Mindestens einen Wochentag auswählen.');
            }
            if (!$items) {
                gymError('Der Trainingsplan benötigt mindestens eine Übung.');
            }
            if (count($items) > 100) {
                gymError('Zu viele Übungen im Trainingsplan.');
            }

            $validatedItems = [];
            $seenExerciseIds = [];

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    gymError('Ungültige Trainingsplan-Daten.');
                }

                $exerciseId = (int)($item['exercise_id'] ?? 0);
                if ($exerciseId <= 0 || isset($seenExerciseIds[$exerciseId])) {
                    gymError('Jede Übung darf pro Trainingsplan nur einmal vorkommen.');
                }

                $exercise = gymGetExercise($fitconn, $exerciseId);
                if (!$exercise) {
                    gymError('Eine ausgewählte Übung existiert nicht mehr.');
                }

                $seenExerciseIds[$exerciseId] = true;
                $validatedItems[] = [
                    'plan_exercise_id' => (int)($item['plan_exercise_id'] ?? 0),
                    'exercise_id' => $exerciseId,
                    'sort_order' => $index,
                ];
            }

            $existingPlan = $planId > 0 ? gymGetPlanDetail($fitconn, $planId, true) : null;
            if ($planId > 0 && !$existingPlan) {
                gymError('Trainingsplan nicht gefunden.', 404);
            }
            if ($existingPlan && !empty($existingPlan['is_quick'])) {
                gymError('Interne Einzelübungs-Trainings können nicht als Trainingsplan bearbeitet werden.', 409);
            }

            if ($planId > 0) {
                $stmt = $fitconn->prepare("SELECT COUNT(*) AS c FROM gym_sessions WHERE plan_id = ? AND finished_at IS NULL");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $activeCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                if ($activeCount > 0) {
                    gymError('Ein laufendes Training verwendet diesen Plan. Schließe oder brich es zuerst ab.', 409);
                }
            }

            $oldPlanImagePath = $existingPlan['image_path'] ?? null;
            $planImagePath = gymUploadImage($_FILES['image'] ?? null, $oldPlanImagePath);
            if ($planId <= 0 && !$planImagePath) {
                gymError('Bitte ein Bild für den Trainingsplan hochladen.');
            }

            $fitconn->begin_transaction();
            try {
                if ($planId > 0) {
                    $stmt = $fitconn->prepare("UPDATE gym_plans SET name = ?, image_path = ?, is_archived = 0 WHERE id = ?");
                    $stmt->bind_param('ssi', $name, $planImagePath, $planId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $fitconn->prepare("INSERT INTO gym_plans (name, image_path) VALUES (?, ?)");
                    $stmt->bind_param('ss', $name, $planImagePath);
                    $stmt->execute();
                    $planId = (int)$fitconn->insert_id;
                    $stmt->close();
                }

                $stmt = $fitconn->prepare("DELETE FROM gym_plan_days WHERE plan_id = ?");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $stmt->close();

                $stmtDay = $fitconn->prepare("INSERT INTO gym_plan_days (plan_id, weekday) VALUES (?, ?)");
                foreach ($days as $day) {
                    $stmtDay->bind_param('ii', $planId, $day);
                    $stmtDay->execute();
                }
                $stmtDay->close();

                // Historische plan_exercise-Zeilen werden weiterhin nur archiviert,
                // damit bereits vorhandene Session-Sets ihren Referenzanker behalten.
                $stmt = $fitconn->prepare("UPDATE gym_plan_exercises SET is_archived = 1 WHERE plan_id = ?");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $stmt->close();

                foreach ($validatedItems as $item) {
                    $planExerciseId = (int)$item['plan_exercise_id'];
                    $exerciseId = (int)$item['exercise_id'];
                    $sortOrder = (int)$item['sort_order'];

                    $updatedExisting = false;
                    if ($planExerciseId > 0) {
                        $stmt = $fitconn->prepare("
                            SELECT id
                            FROM gym_plan_exercises
                            WHERE id = ? AND plan_id = ? AND exercise_id = ?
                            LIMIT 1
                        ");
                        $stmt->bind_param('iii', $planExerciseId, $planId, $exerciseId);
                        $stmt->execute();
                        $exists = (bool)$stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($exists) {
                            $stmt = $fitconn->prepare("
                                UPDATE gym_plan_exercises
                                SET sort_order = ?, is_archived = 0
                                WHERE id = ?
                            ");
                            $stmt->bind_param('ii', $sortOrder, $planExerciseId);
                            $stmt->execute();
                            $stmt->close();
                            $updatedExisting = true;
                        }
                    }

                    if (!$updatedExisting) {
                        $stmt = $fitconn->prepare("
                            INSERT INTO gym_plan_exercises
                                (plan_id, exercise_id, sort_order, is_archived)
                            VALUES (?, ?, ?, 0)
                        ");
                        $stmt->bind_param('iii', $planId, $exerciseId, $sortOrder);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $fitconn->commit();
            } catch (Throwable $e) {
                $fitconn->rollback();
                throw $e;
            }

            gymJson(['ok' => true, 'plan' => gymGetPlanDetail($fitconn, $planId)]);
        }

        if ($api === 'plan_delete') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);
            $planId = (int)($data['id'] ?? 0);

            $planToDelete = $planId > 0 ? gymGetPlanDetail($fitconn, $planId) : null;
            if (!$planToDelete) {
                gymError('Trainingsplan nicht gefunden.', 404);
            }
            if (!empty($planToDelete['is_quick'])) {
                gymError('Interne Einzelübungs-Trainings werden automatisch verwaltet.', 409);
            }

            $stmt = $fitconn->prepare("SELECT COUNT(*) AS c FROM gym_sessions WHERE plan_id = ? AND finished_at IS NULL");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $activeCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($activeCount > 0) {
                gymError('Der Trainingsplan hat ein laufendes Training und kann gerade nicht gelöscht werden.', 409);
            }

            $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ?");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $stmt->close();

            gymJson(['ok' => true]);
        }

        if ($api === 'quick_entry_save') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);

            $exerciseId = (int)($data['exercise_id'] ?? 0);
            $exercise = $exerciseId > 0 ? gymGetExercise($fitconn, $exerciseId) : null;
            if (!$exercise) {
                gymError('Übung nicht gefunden.', 404);
            }

            // Der Direkteintrag erzeugt KEIN laufendes Training. Die Daten werden in
            // einem einzigen Transaktionsschritt als bereits abgeschlossene interne
            // Ein-Übungs-Session gespeichert. Damit bleibt das normale Session-/Log-
            // Datenmodell unverändert, ohne die Trainingsansicht zu betreten.
            $reps = null;
            $weight = null;
            $cardioMinutes = null;
            $cardioDistanceKm = null;
            $calories = 0;
            $caloriesManual = 0;

            if ($exercise['type'] === 'kardio') {
                $mode = ($exercise['cardio_mode'] ?? null) ?: 'zeit';

                if ($mode === 'strecke') {
                    $distanceRaw = str_replace(',', '.', trim((string)($data['cardio_distance_km'] ?? '')));
                    $cardioDistanceKm = is_numeric($distanceRaw) ? (float)$distanceRaw : -1;
                    if ($cardioDistanceKm <= 0 || $cardioDistanceKm > 10000) {
                        gymError('Bitte eine gültige Strecke in Kilometern angeben.');
                    }

                    $kcalPerKm = (float)($exercise['cardio_kcal_per_km'] ?? 0);
                    $calories = $kcalPerKm > 0
                        ? (int)round($cardioDistanceKm * $kcalPerKm)
                        : 0;
                } else {
                    $cardioMinutes = (int)($data['cardio_minutes'] ?? 0);
                    if ($cardioMinutes <= 0 || $cardioMinutes > 1440) {
                        gymError('Bitte eine gültige Trainingsdauer in Minuten angeben.');
                    }

                    $kcalPerHour = (int)($exercise['cardio_kcal_per_hour'] ?? 0);
                    $calories = $kcalPerHour > 0
                        ? (int)round(($cardioMinutes / 60) * $kcalPerHour)
                        : 0;
                }

                $caloriesManual = !empty($data['calories_manual']) ? 1 : 0;
                if ($caloriesManual) {
                    $manualRaw = str_replace(',', '.', trim((string)($data['manual_calories'] ?? '')));
                    if ($manualRaw === '' || !is_numeric($manualRaw)) {
                        gymError('Bitte den manuellen Kalorienverbrauch angeben.');
                    }

                    $manualCalories = (int)round((float)$manualRaw);
                    if ($manualCalories < 0 || $manualCalories > 50000) {
                        gymError('Bitte einen gültigen manuellen Kalorienverbrauch angeben.');
                    }
                    $calories = $manualCalories;
                }
            } else {
                $reps = (int)($data['reps'] ?? 0);
                $weightRaw = str_replace(',', '.', trim((string)($data['weight'] ?? '')));
                $weight = is_numeric($weightRaw) ? (float)$weightRaw : -1;

                if ($reps <= 0 || $reps > 1000) {
                    gymError('Bitte gültige Wiederholungen angeben.');
                }
                if ($weight < 0 || $weight > 9999.99) {
                    gymError('Bitte ein gültiges Gewicht angeben.');
                }

                $calories = $reps * GYM_STRENGTH_KCAL_PER_REP;
            }

            $quickName = 'Einzelübung · ' . $exercise['name'];
            $quickImagePath = $exercise['image_path'] ?? null;

            $fitconn->begin_transaction();
            try {
                $stmt = $fitconn->prepare("\n                    INSERT INTO gym_plans (name, image_path, is_quick, is_archived)\n                    VALUES (?, ?, 1, 1)\n                ");
                $stmt->bind_param('ss', $quickName, $quickImagePath);
                $stmt->execute();
                $planId = (int)$fitconn->insert_id;
                $stmt->close();

                $stmt = $fitconn->prepare("\n                    INSERT INTO gym_plan_exercises\n                        (plan_id, exercise_id, sort_order, is_archived)\n                    VALUES (?, ?, 0, 1)\n                ");
                $stmt->bind_param('ii', $planId, $exerciseId);
                $stmt->execute();
                $planExerciseId = (int)$fitconn->insert_id;
                $stmt->close();

                $stmt = $fitconn->prepare("\n                    INSERT INTO gym_sessions (plan_id, started_at, finished_at)\n                    VALUES (?, NOW(), NOW())\n                ");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $sessionId = (int)$fitconn->insert_id;
                $stmt->close();

                $setNumber = 1;
                $stmt = $fitconn->prepare("\n                    INSERT INTO gym_session_sets\n                        (session_id, plan_exercise_id, set_number, reps, weight, cardio_minutes,\n                         cardio_distance_km, calories, calories_manual)\n                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                $stmt->bind_param(
                    'iiiididii',
                    $sessionId,
                    $planExerciseId,
                    $setNumber,
                    $reps,
                    $weight,
                    $cardioMinutes,
                    $cardioDistanceKm,
                    $calories,
                    $caloriesManual
                );
                $stmt->execute();
                $stmt->close();

                $fitconn->commit();
            } catch (Throwable $e) {
                $fitconn->rollback();
                throw $e;
            }

            gymJson([
                'ok' => true,
                'session_id' => $sessionId,
                'calories' => $calories,
            ]);
        }

        if ($api === 'start_session') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);
            $planId = (int)($data['plan_id'] ?? 0);

            $plan = $planId > 0 ? gymGetPlanDetail($fitconn, $planId) : null;
            if (!$plan || !empty($plan['is_quick'])) {
                gymError('Trainingsplan nicht gefunden.', 404);
            }
            if (!$plan['exercises']) {
                gymError('Der Trainingsplan enthält keine Übungen.');
            }

            $active = gymGetActiveSession($fitconn);
            if ($active) {
                gymJson(['ok' => true, 'session' => gymGetSessionDetail($fitconn, (int)$active['id']), 'resumed' => true]);
            }

            $stmt = $fitconn->prepare("INSERT INTO gym_sessions (plan_id) VALUES (?)");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $sessionId = (int)$fitconn->insert_id;
            $stmt->close();

            gymJson(['ok' => true, 'session' => gymGetSessionDetail($fitconn, $sessionId), 'resumed' => false]);
        }

        if ($api === 'save_set') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);

            $sessionId = (int)($data['session_id'] ?? 0);
            $planExerciseId = (int)($data['plan_exercise_id'] ?? 0);
            $setNumber = (int)($data['set_number'] ?? 0);

            if ($sessionId <= 0 || $planExerciseId <= 0 || $setNumber <= 0 || $setNumber > 100) {
                gymError('Ungültige Satz-Daten.');
            }

            $stmt = $fitconn->prepare("
                SELECT
                    s.finished_at,
                    pe.id,
                    pe.plan_id,
                    e.type,
                    e.cardio_mode,
                    e.cardio_kcal_per_hour,
                    e.cardio_kcal_per_km
                FROM gym_sessions s
                JOIN gym_plan_exercises pe ON pe.id = ? AND pe.plan_id = s.plan_id
                JOIN gym_exercises e ON e.id = pe.exercise_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $planExerciseId, $sessionId);
            $stmt->execute();
            $meta = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$meta) {
                gymError('Satz gehört nicht zu diesem Training.', 404);
            }

            // Vor der Validierung laden: Bei abgeschlossenen Logs bestimmt der
            // gespeicherte Satz, ob es historisch Zeit- oder Strecken-Kardio war.
            $stmt = $fitconn->prepare("
                SELECT id, cardio_minutes, cardio_distance_km
                FROM gym_session_sets
                WHERE session_id = ? AND plan_exercise_id = ? AND set_number = ?
                LIMIT 1
            ");
            $stmt->bind_param('iii', $sessionId, $planExerciseId, $setNumber);
            $stmt->execute();
            $existingSet = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            $reps = null;
            $weight = null;
            $cardioMinutes = null;
            $cardioDistanceKm = null;
            $calories = null;
            $caloriesManual = 0;

            if ($meta['type'] === 'kardio') {
                // Kardio hat exakt einen Satz. Je nach Übung wird Zeit oder Strecke erfasst.
                $setNumber = 1;
                $cardioMode = ($meta['cardio_mode'] ?? null) ?: 'zeit';
                if ($meta['finished_at'] !== null && $existingSet) {
                    if ($existingSet['cardio_distance_km'] !== null) {
                        $cardioMode = 'strecke';
                    } elseif ($existingSet['cardio_minutes'] !== null) {
                        $cardioMode = 'zeit';
                    }
                }

                if ($cardioMode === 'strecke') {
                    $distanceRaw = str_replace(',', '.', (string)($data['cardio_distance_km'] ?? ''));
                    $cardioDistanceKm = is_numeric($distanceRaw) ? (float)$distanceRaw : -1;
                    if ($cardioDistanceKm <= 0 || $cardioDistanceKm > 10000) {
                        gymError('Bitte eine gültige Strecke in Kilometern angeben.');
                    }

                    $kcalPerKm = (float)($meta['cardio_kcal_per_km'] ?? 0);
                    $calories = $kcalPerKm > 0
                        ? (int)round($cardioDistanceKm * $kcalPerKm)
                        : 0;
                } else {
                    $cardioMinutes = (int)($data['cardio_minutes'] ?? 0);
                    if ($cardioMinutes <= 0 || $cardioMinutes > 1440) {
                        gymError('Bitte eine gültige Trainingsdauer in Minuten angeben.');
                    }

                    $kcalPerHour = (int)($meta['cardio_kcal_per_hour'] ?? 0);
                    $calories = $kcalPerHour > 0
                        ? (int)round(($cardioMinutes / 60) * $kcalPerHour)
                        : 0;
                }

                $caloriesManual = !empty($data['calories_manual']) ? 1 : 0;
                if ($caloriesManual) {
                    $manualRaw = trim((string)($data['manual_calories'] ?? ''));
                    if ($manualRaw === '' || !is_numeric(str_replace(',', '.', $manualRaw))) {
                        gymError('Bitte den manuellen Kalorienverbrauch angeben.');
                    }
                    $manualCalories = (int)round((float)str_replace(',', '.', $manualRaw));
                    if ($manualCalories < 0 || $manualCalories > 50000) {
                        gymError('Bitte einen gültigen manuellen Kalorienverbrauch angeben.');
                    }
                    $calories = $manualCalories;
                }
            } else {
                $reps = (int)($data['reps'] ?? 0);
                $weightRaw = str_replace(',', '.', (string)($data['weight'] ?? ''));
                $weight = is_numeric($weightRaw) ? (float)$weightRaw : -1;

                if ($reps <= 0 || $reps > 1000) {
                    gymError('Bitte gültige Wiederholungen angeben.');
                }
                if ($weight < 0 || $weight > 9999.99) {
                    gymError('Bitte ein gültiges Gewicht angeben.');
                }

                // Krafttraining: 1 kcal pro tatsächlich protokollierter Wiederholung.
                $calories = $reps * GYM_STRENGTH_KCAL_PER_REP;
            }

            if ($meta['finished_at'] !== null && !$existingSet) {
                gymError('In einem abgeschlossenen Log können nur vorhandene Sätze bearbeitet werden.', 409);
            }

            if ($existingSet) {
                $setId = (int)$existingSet['id'];
                $stmt = $fitconn->prepare("
                    UPDATE gym_session_sets
                    SET reps = ?, weight = ?, cardio_minutes = ?, cardio_distance_km = ?,
                        calories = ?, calories_manual = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    'ididiii',
                    $reps,
                    $weight,
                    $cardioMinutes,
                    $cardioDistanceKm,
                    $calories,
                    $caloriesManual,
                    $setId
                );
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $fitconn->prepare("
                    INSERT INTO gym_session_sets
                        (session_id, plan_exercise_id, set_number, reps, weight, cardio_minutes,
                         cardio_distance_km, calories, calories_manual)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    'iiiididii',
                    $sessionId,
                    $planExerciseId,
                    $setNumber,
                    $reps,
                    $weight,
                    $cardioMinutes,
                    $cardioDistanceKm,
                    $calories,
                    $caloriesManual
                );
                $stmt->execute();
                $stmt->close();
            }

            gymJson(['ok' => true, 'session' => gymGetSessionDetail($fitconn, $sessionId)]);
        }

        if ($api === 'set_delete') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);

            $sessionId = (int)($data['session_id'] ?? 0);
            $planExerciseId = (int)($data['plan_exercise_id'] ?? 0);
            $setNumber = (int)($data['set_number'] ?? 0);

            $stmt = $fitconn->prepare("SELECT plan_id, finished_at FROM gym_sessions WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $sessionMeta = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$sessionMeta) {
                gymError('Training nicht gefunden.', 404);
            }

            $stmt = $fitconn->prepare("
                DELETE FROM gym_session_sets
                WHERE session_id = ? AND plan_exercise_id = ? AND set_number = ?
            ");
            $stmt->bind_param('iii', $sessionId, $planExerciseId, $setNumber);
            $stmt->execute();
            $stmt->close();

            // Ein abgeschlossener Trainingslog ohne irgendeinen verbleibenden Satz
            // ist inhaltlich leer und wird vollständig entfernt.
            if ($sessionMeta['finished_at'] !== null) {
                $stmt = $fitconn->prepare("SELECT COUNT(*) AS c FROM gym_session_sets WHERE session_id = ?");
                $stmt->bind_param('i', $sessionId);
                $stmt->execute();
                $remainingSets = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                if ($remainingSets === 0) {
                    $planId = (int)$sessionMeta['plan_id'];

                    $stmt = $fitconn->prepare("DELETE FROM gym_sessions WHERE id = ?");
                    $stmt->bind_param('i', $sessionId);
                    $stmt->execute();
                    $stmt->close();

                    // Interne Schnell-Pläne bleiben grundsätzlich Soft-Delete und
                    // tauchen daher niemals in der normalen Planübersicht auf.
                    $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ? AND is_quick = 1");
                    $stmt->bind_param('i', $planId);
                    $stmt->execute();
                    $stmt->close();

                    gymJson([
                        'ok' => true,
                        'session_deleted' => true,
                        'session_id' => $sessionId,
                    ]);
                }
            }

            gymJson([
                'ok' => true,
                'session_deleted' => false,
                'session' => gymGetSessionDetail($fitconn, $sessionId),
            ]);
        }

        if ($api === 'session_exercise_delete') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);

            $sessionId = (int)($data['session_id'] ?? 0);
            $planExerciseId = (int)($data['plan_exercise_id'] ?? 0);

            if ($sessionId <= 0 || $planExerciseId <= 0) {
                gymError('Ungültige Log-Daten.');
            }

            $stmt = $fitconn->prepare("
                SELECT s.plan_id, s.finished_at
                FROM gym_sessions s
                JOIN gym_plan_exercises pe ON pe.id = ? AND pe.plan_id = s.plan_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $planExerciseId, $sessionId);
            $stmt->execute();
            $sessionMeta = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$sessionMeta) {
                gymError('Training oder Übung nicht gefunden.', 404);
            }
            if ($sessionMeta['finished_at'] === null) {
                gymError('Komplette Übungen können nur aus abgeschlossenen Logs gelöscht werden.', 409);
            }

            $stmt = $fitconn->prepare("
                DELETE FROM gym_session_sets
                WHERE session_id = ? AND plan_exercise_id = ?
            ");
            $stmt->bind_param('ii', $sessionId, $planExerciseId);
            $stmt->execute();
            $deletedSets = $stmt->affected_rows;
            $stmt->close();

            if ($deletedSets < 1) {
                gymError('Für diese Übung existiert kein Log-Eintrag mehr.', 404);
            }

            $stmt = $fitconn->prepare("SELECT COUNT(*) AS c FROM gym_session_sets WHERE session_id = ?");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $remainingSets = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($remainingSets === 0) {
                $planId = (int)$sessionMeta['plan_id'];

                $stmt = $fitconn->prepare("DELETE FROM gym_sessions WHERE id = ?");
                $stmt->bind_param('i', $sessionId);
                $stmt->execute();
                $stmt->close();

                $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ? AND is_quick = 1");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $stmt->close();

                gymJson([
                    'ok' => true,
                    'session_deleted' => true,
                    'session_id' => $sessionId,
                ]);
            }

            gymJson([
                'ok' => true,
                'session_deleted' => false,
                'session' => gymGetSessionDetail($fitconn, $sessionId),
            ]);
        }

        if ($api === 'finish_session') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);
            $sessionId = (int)($data['session_id'] ?? 0);

            $stmt = $fitconn->prepare("
                SELECT s.plan_id, p.is_quick
                FROM gym_sessions s
                JOIN gym_plans p ON p.id = s.plan_id
                WHERE s.id = ? AND s.finished_at IS NULL
                LIMIT 1
            ");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $sessionMeta = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$sessionMeta) {
                gymError('Training nicht gefunden oder bereits abgeschlossen.', 409);
            }

            $stmt = $fitconn->prepare("SELECT COUNT(*) AS c FROM gym_session_sets WHERE session_id = ?");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $setCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            $planId = (int)$sessionMeta['plan_id'];

            // Ein Training ohne protokollierte Übung/Sätze ist kein Log.
            if ($setCount === 0) {
                $stmt = $fitconn->prepare("DELETE FROM gym_sessions WHERE id = ?");
                $stmt->bind_param('i', $sessionId);
                $stmt->execute();
                $stmt->close();

                if (!empty($sessionMeta['is_quick'])) {
                    $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ?");
                    $stmt->bind_param('i', $planId);
                    $stmt->execute();
                    $stmt->close();
                }

                gymJson([
                    'ok' => true,
                    'session_deleted' => true,
                    'session_id' => $sessionId,
                ]);
            }

            $stmt = $fitconn->prepare("
                UPDATE gym_sessions
                SET finished_at = CURRENT_TIMESTAMP
                WHERE id = ? AND finished_at IS NULL
            ");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                gymError('Training nicht gefunden oder bereits abgeschlossen.', 409);
            }

            if (!empty($sessionMeta['is_quick'])) {
                // Der interne Ein-Übungs-Plan ist nach Abschluss nur noch Historienanker.
                $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ?");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $stmt->close();
            }

            gymJson([
                'ok' => true,
                'session_deleted' => false,
                'session' => gymGetSessionDetail($fitconn, $sessionId),
            ]);
        }

        if ($api === 'cancel_session') {
            $data = gymRequestData();
            gymRequirePostCsrf($data);
            $sessionId = (int)($data['session_id'] ?? 0);

            $stmt = $fitconn->prepare("
                SELECT s.plan_id, p.is_quick
                FROM gym_sessions s
                JOIN gym_plans p ON p.id = s.plan_id
                WHERE s.id = ? AND s.finished_at IS NULL
                LIMIT 1
            ");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $sessionMeta = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if (!$sessionMeta) {
                gymError('Laufendes Training nicht gefunden.', 404);
            }

            $stmt = $fitconn->prepare("DELETE FROM gym_sessions WHERE id = ? AND finished_at IS NULL");
            $stmt->bind_param('i', $sessionId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                gymError('Laufendes Training nicht gefunden.', 404);
            }

            if (!empty($sessionMeta['is_quick'])) {
                $planId = (int)$sessionMeta['plan_id'];
                $stmt = $fitconn->prepare("UPDATE gym_plans SET is_archived = 1 WHERE id = ?");
                $stmt->bind_param('i', $planId);
                $stmt->execute();
                $stmt->close();
            }

            gymJson(['ok' => true]);
        }

        gymError('Unbekannte API-Aktion.', 404);
    } catch (mysqli_sql_exception $e) {
        $errorId = bin2hex(random_bytes(4));
        error_log('Gym DB error [' . $errorId . '] action=' . $api . ': ' . $e->getMessage());
        gymError(
            'Datenbankfehler bei „' . $api . '“. Referenz: ' . $errorId . '.',
            500,
            ['code' => 'DB_ERROR', 'error_id' => $errorId]
        );
    } catch (Throwable $e) {
        $errorId = bin2hex(random_bytes(4));
        error_log('Gym error [' . $errorId . '] action=' . $api . ': ' . $e->getMessage());
        gymError(
            'Interner Fehler bei „' . $api . '“. Referenz: ' . $errorId . '.',
            500,
            ['code' => 'INTERNAL_ERROR', 'error_id' => $errorId]
        );
    }
}

$page_title = 'Gym';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<main id="gymApp" class="gym-page" data-csrf="<?= htmlspecialchars($gymCsrf, ENT_QUOTES, 'UTF-8') ?>">
    <section class="gym-shell">
        <header class="gym-header">
            <div class="gym-header-copy">
                <button type="button" id="gymBackButton" class="gym-back-button" hidden aria-label="Zurück"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5M12 19l-7-7 7-7"/></svg></button>
                <div>
                    <h1 id="gymTitle" class="gym-title">Gym</h1>
                </div>
            </div>

            <div id="gymHeaderActions" class="gym-header-actions">
                <button type="button" id="gymAddExerciseButton" class="gym-button gym-button-secondary">+ Übung</button>
                <button type="button" id="gymAddPlanButton" class="gym-button">+ Training</button>
            </div>
        </header>

        <div id="gymActiveSessionBanner" class="gym-active-session-banner" hidden></div>

        <nav id="gymTabs" class="gym-tabs" aria-label="Gym-Bereiche">
            <button type="button" class="gym-tab is-active" data-tab="plans">Trainings</button>
            <button type="button" class="gym-tab" data-tab="exercises">Übungen</button>
            <button type="button" class="gym-tab" data-tab="logs">Logs</button>
        </nav>

        <section id="gymView" class="gym-view" aria-live="polite">
            <div class="gym-loading">Gym wird geladen …</div>
        </section>
    </section>
</main>

<div id="gymModal" class="gym-modal hidden" role="dialog" aria-modal="true" aria-labelledby="gymModalTitle">
    <div class="gym-modal-panel">
        <button type="button" id="gymModalClose" class="gym-modal-close" aria-label="Schließen">×</button>
        <div id="gymModalBody"></div>
    </div>
</div>

<div id="gymToast" class="gym-toast" hidden></div>

<script>
(() => {
    'use strict';

    const app = document.getElementById('gymApp');
    const view = document.getElementById('gymView');
    const title = document.getElementById('gymTitle');
    const tabs = document.getElementById('gymTabs');
    const backButton = document.getElementById('gymBackButton');
    const headerActions = document.getElementById('gymHeaderActions');
    const addExerciseButton = document.getElementById('gymAddExerciseButton');
    const addPlanButton = document.getElementById('gymAddPlanButton');
    const activeSessionBanner = document.getElementById('gymActiveSessionBanner');
    const modal = document.getElementById('gymModal');
    const modalBody = document.getElementById('gymModalBody');
    const modalClose = document.getElementById('gymModalClose');
    const toast = document.getElementById('gymToast');

    const state = {
        csrf: app.dataset.csrf || '',
        bootstrap: null,
        logs: null,
        route: { name: 'overview', tab: 'plans' },
        history: [],
        currentPlan: null,
        currentExercise: null,
        currentSession: null,
        currentSessionExerciseId: null,
        extraSetCounts: new Map(),
        planDraft: null,
        planDragIndex: null,
    };

    const weekdayShort = {
        1: 'Mo', 2: 'Di', 3: 'Mi', 4: 'Do', 5: 'Fr', 6: 'Sa', 7: 'So'
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[char]));
    }

    function parseNumber(value, fallback = 0) {
        const normalized = String(value ?? '').trim().replace(',', '.');
        const num = Number(normalized);
        return Number.isFinite(num) ? num : fallback;
    }

    function formatNumber(value, maxDecimals = 2) {
        const num = Number(value);
        if (!Number.isFinite(num)) return '—';
        return new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: maxDecimals
        }).format(num);
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }

    function cardioMode(item) {
        return item?.cardio_mode === 'strecke' ? 'strecke' : 'zeit';
    }


    function formatSet(set, exercise) {
        if (!set) return '—';
        if (exercise?.type === 'kardio') {
            const base = cardioMode(exercise) === 'strecke'
                ? `${formatNumber(set.cardio_distance_km)} km`
                : `${formatNumber(set.cardio_minutes, 0)} min`;
            const kcal = set.calories !== null && set.calories !== undefined
                ? ` · ${formatNumber(set.calories, 0)} kcal${set.calories_manual ? ' manuell' : ''}`
                : '';
            return `${base}${kcal}`;
        }
        return `${formatNumber(set.reps, 0)} × ${formatNumber(set.weight)} kg`;
    }

    function cardioRateText(exercise) {
        return cardioMode(exercise) === 'strecke'
            ? `${formatNumber(exercise.cardio_kcal_per_km)} kcal/km`
            : `${formatNumber(exercise.cardio_kcal_per_hour, 0)} kcal/h`;
    }

    function muscleText(exercise) {
        if (exercise.type === 'kardio') {
            return `${cardioMode(exercise) === 'strecke' ? 'Strecke' : 'Zeit'} · ${cardioRateText(exercise)}`;
        }
        const primary = Array.isArray(exercise.primary_muscles) ? exercise.primary_muscles.join(', ') : '';
        const secondary = Array.isArray(exercise.secondary_muscles) ? exercise.secondary_muscles.join(', ') : '';
        if (secondary) return `${primary} · sekundär: ${secondary}`;
        return primary || 'Keine Muskelgruppen';
    }

    function imageMarkup(exercise, className = 'gym-card-image') {
        if (exercise?.image_url) {
            return `<img src="${escapeHtml(exercise.image_url)}" alt="${escapeHtml(exercise.name)}" class="${className}">`;
        }
        return `<div class="${className} gym-image-placeholder" aria-hidden="true">${exercise?.type === 'kardio' ? '♥' : '●'}</div>`;
    }

    function planImageMarkup(plan, className = 'gym-plan-card-image') {
        if (plan?.image_url) {
            return `<img src="${escapeHtml(plan.image_url)}" alt="${escapeHtml(plan.name)}" class="${className}">`;
        }
        return `<div class="${className} gym-image-placeholder gym-plan-image-placeholder" aria-hidden="true">◆</div>`;
    }

    function showToast(message, type = 'info', duration = null) {
        // Rückwärtskompatibel zu bisherigen showToast(text, true/false)-Aufrufen.
        if (type === true) type = 'error';
        if (type === false || !['info', 'success', 'error'].includes(type)) type = 'info';

        toast.textContent = String(message || 'Unbekannte Rückmeldung');
        toast.classList.remove('is-info', 'is-success', 'is-error');
        toast.classList.add(`is-${type}`);
        toast.hidden = false;

        clearTimeout(showToast.timer);
        const timeout = duration === null
            ? (type === 'error' ? 8000 : type === 'success' ? 4000 : 5000)
            : Number(duration);

        if (timeout > 0) {
            showToast.timer = setTimeout(() => {
                toast.hidden = true;
            }, timeout);
        }
    }

    function describeJsError(error) {
        if (error instanceof Error) return error.message || error.name || 'JavaScript-Fehler';
        if (typeof error === 'string') return error;
        try {
            return JSON.stringify(error);
        } catch (_) {
            return String(error);
        }
    }

    window.addEventListener('error', event => {
        const message = event?.error
            ? describeJsError(event.error)
            : String(event?.message || 'Unbekannter JavaScript-Fehler');
        console.error('Gym JavaScript error:', event?.error || event);
        showToast(`JavaScript-Fehler: ${message}`, 'error', 12000);
    });

    window.addEventListener('unhandledrejection', event => {
        const message = describeJsError(event?.reason);
        console.error('Gym unhandled promise rejection:', event?.reason);
        showToast(`JavaScript-Promise fehlgeschlagen: ${message}`, 'error', 12000);
    });

    async function parseApiResponse(response, action) {
        const raw = await response.text();
        let data = {};

        if (raw !== '') {
            try {
                data = JSON.parse(raw);
            } catch (error) {
                const preview = raw.replace(/\s+/g, ' ').trim().slice(0, 280);
                const suffix = preview ? ` Serverantwort: ${preview}` : '';
                throw new Error(`API „${action}“ lieferte keine gültige JSON-Antwort (HTTP ${response.status}).${suffix}`);
            }
        }

        if (!response.ok || data.ok === false) {
            if (response.status === 413 && !data.error) {
                throw new Error('Upload zu groß: Webserver oder PHP haben die Anfrage mit HTTP 413 abgelehnt.');
            }
            throw new Error(data.error || `API „${action}“ fehlgeschlagen (HTTP ${response.status}).`);
        }

        return data;
    }

    async function apiGet(action, params = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('api', action);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));

        let response;
        try {
            response = await fetch(url.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
        } catch (error) {
            throw new Error(`Netzwerkfehler bei API „${action}“: ${describeJsError(error)}`);
        }

        return parseApiResponse(response, action);
    }

    async function apiPost(action, payload = {}, options = {}) {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('api', action);

        let body;
        const headers = { 'X-Requested-With': 'XMLHttpRequest' };

        if (options.formData) {
            body = payload;
            if (!body.has('csrf')) body.append('csrf', state.csrf);
        } else {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = state.csrf;
            body = JSON.stringify({ ...payload, csrf: state.csrf });
        }

        let response;
        try {
            response = await fetch(url.toString(), {
                method: 'POST',
                headers,
                body
            });
        } catch (error) {
            throw new Error(`Netzwerkfehler bei API „${action}“: ${describeJsError(error)}`);
        }

        return parseApiResponse(response, action);
    }

    async function refreshBootstrap() {
        const data = await apiGet('bootstrap');
        state.bootstrap = data;
        if (data.csrf) state.csrf = data.csrf;
        renderActiveSessionBanner();
        return data;
    }

    async function loadLogs(force = false) {
        if (!force && Array.isArray(state.logs)) {
            return state.logs;
        }
        const data = await apiGet('logs');
        state.logs = Array.isArray(data.logs) ? data.logs : [];
        return state.logs;
    }

    function routeKey(route) {
        return JSON.stringify(route);
    }

    async function navigate(route, { replace = false, noHistory = false } = {}) {
        if (!noHistory && state.route && routeKey(state.route) !== routeKey(route)) {
            if (!replace) state.history.push({ ...state.route });
        }
        state.route = { ...route };
        await renderRoute();
    }

    async function goBack() {
        const previous = state.history.pop();
        if (previous) {
            state.route = previous;
            await renderRoute();
            return;
        }
        await navigate({ name: 'overview', tab: state.route.tab || 'plans' }, { noHistory: true });
    }

    function setHeader(mainTitle, subTitle = '', showBack = false, showActions = false) {
        title.textContent = mainTitle;
        backButton.hidden = !showBack;
        headerActions.hidden = !showActions;
        tabs.hidden = showBack;
    }

    function setLoading(text = 'Laden …') {
        view.innerHTML = `<div class="gym-loading">${escapeHtml(text)}</div>`;
    }

    function renderActiveSessionBanner() {
        const active = state.bootstrap?.active_session;
        if (!active || state.route?.name === 'session' || state.route?.name === 'session_exercise') {
            activeSessionBanner.hidden = true;
            activeSessionBanner.innerHTML = '';
            return;
        }

        activeSessionBanner.hidden = false;
        activeSessionBanner.innerHTML = `
            <div>
                <strong>Laufendes Training:</strong>
                <span>${escapeHtml(active.plan_name)}</span>
            </div>
            <button type="button" class="gym-button gym-button-small" data-action="resume-active">Fortsetzen</button>
        `;
    }

    async function renderOverview() {
        const allowedTabs = ['plans', 'exercises', 'logs'];
        const tab = allowedTabs.includes(state.route.tab) ? state.route.tab : 'plans';
        state.route.tab = tab;

        setHeader('Gym', '', false, true);
        renderActiveSessionBanner();

        tabs.querySelectorAll('.gym-tab').forEach(button => {
            button.classList.toggle('is-active', button.dataset.tab === tab);
        });

        if (tab === 'plans') {
            const plans = state.bootstrap?.plans || [];

            let planGridLayoutClass = '';
            if (plans.length === 1) {
                planGridLayoutClass = 'gym-plan-grid--one';
            } else if (plans.length % 3 === 1) {
                planGridLayoutClass = 'gym-plan-grid--tail-four';
            } else if (plans.length % 3 === 2) {
                planGridLayoutClass = 'gym-plan-grid--tail-two';
            }

            view.innerHTML = plans.length ? `
                <div class="gym-overview-head">
                    <div>
                        <h2>Trainingspläne</h2>
                    </div>
                </div>
                <div class="gym-card-grid gym-plan-grid ${planGridLayoutClass}">
                    ${plans.map(plan => `
                        <article class="gym-card gym-plan-card" data-plan-id="${plan.id}">
                            ${planImageMarkup(plan)}
                            <div class="gym-plan-card-info">
                                <div class="gym-plan-card-main">
                                    <h3 class="gym-plan-card-title">${escapeHtml(plan.name)}</h3>
                                    <div class="gym-weekday-row" aria-label="Trainingstage">
                                        ${(plan.days || []).map(day => `<span class="gym-weekday-chip ${Number(day) === Number(state.bootstrap.today_weekday) ? 'is-today' : ''}">${weekdayShort[day] || day}</span>`).join('')}
                                    </div>
                                </div>
                                ${(plan.muscle_groups || []).length ? `
                                    <div class="gym-plan-muscles" aria-label="Trainierte Muskelgruppen">
                                        ${(plan.muscle_groups || []).map(group => `
                                            <span
                                                class="gym-plan-muscle-chip"
                                                title="${escapeHtml(`${group.primary_count}× primär · ${group.secondary_count}× sekundär · Relevanz ${Number(group.relevance || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })}`)}"
                                            >${escapeHtml(group.name)}</span>
                                        `).join('')}
                                    </div>
                                ` : '<div class="gym-plan-muscles gym-plan-muscles--empty" aria-hidden="true"></div>'}
                            </div>
                        </article>
                    `).join('')}
                </div>
            ` : `
                <div class="gym-empty-state">
                    <h2>Noch kein Trainingsplan</h2>
                    <p>Lege zuerst Übungen an und stelle danach dein Training zusammen.</p>
                    <div class="gym-empty-actions">
                        <button type="button" class="gym-button gym-button-secondary" data-action="new-exercise">+ Übung</button>
                        <button type="button" class="gym-button" data-action="new-plan">+ Training</button>
                    </div>
                </div>
            `;
            return;
        }

        if (tab === 'logs') {
            setLoading('Trainingslogs werden geladen …');
            const logs = await loadLogs();
            view.innerHTML = logs.length ? `
                <div class="gym-overview-head">
                    <div>
                        <h2>Logs</h2>
                    </div>
                </div>
                <div class="gym-log-list">
                    ${logs.map(log => `
                        <article class="gym-log-card" data-log-session-id="${log.id}">
                            ${log.plan_image_url
                                ? `<img src="${escapeHtml(log.plan_image_url)}" alt="${escapeHtml(log.plan_name)}" class="gym-log-image">`
                                : '<div class="gym-log-image gym-image-placeholder gym-plan-image-placeholder" aria-hidden="true">◆</div>'}
                            <div class="gym-log-copy">
                                <div class="gym-log-date">${escapeHtml(formatDateTime(log.started_at))}</div>
                                <h3>${escapeHtml(log.plan_name)}</h3>
                                <div class="gym-log-meta">
                                    <span>${formatNumber(log.set_count, 0)} ${Number(log.set_count) === 1 ? 'Eintrag' : 'Einträge'}</span>
                                    <span>${formatNumber(log.calories, 0)} kcal</span>
                                </div>
                            </div>
                            <div class="gym-card-arrow" aria-hidden="true">›</div>
                        </article>
                    `).join('')}
                </div>
            ` : `
                <div class="gym-empty-state">
                    <h2>Noch keine Trainingslogs</h2>
                    <p>Sobald du ein Training abschließt, erscheint es hier.</p>
                </div>
            `;
            return;
        }

        const exercises = state.bootstrap?.exercises || [];
        view.innerHTML = exercises.length ? `
            <div class="gym-overview-head">
                <div>
                    <h2>Übungen</h2>
                </div>
            </div>
            <div class="gym-card-grid gym-exercise-grid">
                ${exercises.map(exercise => `
                    <article class="gym-card gym-exercise-card" data-exercise-id="${exercise.id}">
                        ${imageMarkup(exercise)}
                        <div class="gym-card-body">
                            <div class="gym-card-title-row">
                                <h3>${escapeHtml(exercise.name)}</h3>
                            </div>
                        </div>
                    </article>
                `).join('')}
            </div>
        ` : `
            <div class="gym-empty-state">
                <h2>Noch keine Übung</h2>
                <p>Lege deine erste Kraft- oder Kardioübung an.</p>
                <button type="button" class="gym-button" data-action="new-exercise">+ Übung</button>
            </div>
        `;
    }

    async function renderPlanDetail(planId) {
        setLoading('Trainingsplan wird geladen …');
        const data = await apiGet('plan_detail', { id: planId });
        state.currentPlan = data.plan;
        const plan = data.plan;
        const active = state.bootstrap?.active_session;
        const activeForPlan = active && Number(active.plan_id) === Number(plan.id);

        setHeader(plan.name, (plan.days || []).map(day => state.bootstrap.weekdays[day] || weekdayShort[day]).join(' · '), true, false);
        renderActiveSessionBanner();

        view.innerHTML = `
            <section class="gym-detail-card gym-plan-detail">
                <div class="gym-plan-hero">
                    ${planImageMarkup(plan, 'gym-plan-hero-image')}
                    <div class="gym-plan-hero-copy">
                        <div class="gym-detail-actions">
                            <button type="button" class="gym-button" data-action="${activeForPlan ? 'resume-active' : 'start-plan'}" data-plan-id="${plan.id}">
                                ${activeForPlan ? 'Training fortsetzen' : 'Training starten'}
                            </button>
                            <button type="button" class="gym-button gym-button-secondary" data-action="edit-plan" data-plan-id="${plan.id}" ${activeForPlan ? 'disabled title="Laufendes Training zuerst abschließen oder abbrechen"' : ''}>Bearbeiten</button>
                            <button type="button" class="gym-button gym-button-danger-outline" data-action="delete-plan" data-plan-id="${plan.id}">Löschen</button>
                        </div>
                        <div class="gym-plan-hero-meta">
                            <span>${plan.exercises.length} ${plan.exercises.length === 1 ? 'Übung' : 'Übungen'}</span>
                            <span>${(plan.days || []).map(day => weekdayShort[day] || day).join(' · ')}</span>
                        </div>
                    </div>
                </div>

                <div class="gym-detail-section">
                    <h2>Übungen</h2>
                    ${plan.exercises.length ? `
                        <div class="gym-plan-exercise-list">
                            ${plan.exercises.map((exercise, index) => `
                                <div class="gym-plan-exercise-row">
                                    <div class="gym-plan-exercise-index">${index + 1}</div>
                                    ${imageMarkup(exercise, 'gym-plan-exercise-image')}
                                    <div class="gym-plan-exercise-main">
                                        <strong>${escapeHtml(exercise.name)}</strong>
                                        <span>${exercise.type === 'kardio' ? `Kardio · ${cardioMode(exercise) === 'strecke' ? 'Strecke' : 'Zeit'}` : 'Krafttraining'}</span>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : '<div class="gym-inline-empty">Keine Übungen im Trainingsplan.</div>'}
                </div>
            </section>
        `;
    }

    async function renderExerciseDetail(exerciseId) {
        setLoading('Übung wird geladen …');
        const data = await apiGet('exercise_detail', { id: exerciseId });
        state.currentExercise = data.exercise;
        const exercise = data.exercise;
        const previous = data.direct_previous || null;
        const previousWhen = data.direct_previous_when || 'Noch nie';

        setHeader(exercise.name, exercise.type === 'kardio' ? 'Kardio' : 'Krafttraining', true, false);
        renderActiveSessionBanner();

        const directCardioEntry = exercise.type === 'kardio'
            ? (cardioMode(exercise) === 'strecke' ? `
                <label class="gym-field">
                    <span>Strecke</span>
                    <div class="gym-input-suffix">
                        <input type="number" id="gymQuickCardioDistanceKm" min="0.01" max="10000" step="0.01" inputmode="decimal" required value="${previous?.cardio_distance_km ?? ''}">
                        <span>km</span>
                    </div>
                </label>
            ` : `
                <label class="gym-field">
                    <span>Zeit</span>
                    <div class="gym-input-suffix">
                        <input type="number" id="gymQuickCardioMinutes" min="1" max="1440" step="1" inputmode="numeric" required value="${previous?.cardio_minutes ?? ''}">
                        <span>min</span>
                    </div>
                </label>
            `)
            : '';

        view.innerHTML = `
            <section class="gym-detail-card gym-exercise-detail">
                <div class="gym-exercise-entry-layout">
                    ${imageMarkup(exercise, 'gym-exercise-hero-image')}

                    <div class="gym-exercise-entry-panel">
                        <div class="gym-detail-actions gym-exercise-detail-actions">
                            <button type="button" class="gym-button gym-button-secondary" data-action="edit-exercise" data-exercise-id="${exercise.id}">Bearbeiten</button>
                            <button type="button" class="gym-button gym-button-danger-outline" data-action="delete-exercise" data-exercise-id="${exercise.id}">Löschen</button>
                        </div>

                        <div class="gym-direct-entry-section">
                            <form id="gymQuickEntryForm" class="gym-form gym-direct-entry-form" data-exercise-id="${exercise.id}" novalidate>
                                ${exercise.type === 'kardio' ? `
                                    ${directCardioEntry}

                                    <div class="gym-cardio-kcal-control">
                                        <label class="gym-manual-kcal-toggle">
                                            <input type="checkbox" id="gymQuickManualKcalToggle">
                                            <span>Kalorien manuell setzen</span>
                                        </label>

                                        <label id="gymQuickManualKcalField" class="gym-field gym-manual-kcal-field" hidden>
                                            <span>Verbrauchte Kalorien</span>
                                            <div class="gym-input-suffix">
                                                <input type="number" id="gymQuickManualKcal" min="0" max="50000" step="1" inputmode="numeric" value="">
                                                <span>kcal</span>
                                            </div>
                                        </label>

                                        <div id="gymQuickCardioKcal" class="gym-cardio-kcal-preview"></div>
                                    </div>
                                ` : `
                                    <div class="gym-set-entry-grid">
                                        <label class="gym-field">
                                            <span>Reps</span>
                                            <input type="number" id="gymQuickReps" min="1" max="1000" step="1" inputmode="numeric" required value="${previous?.reps ?? ''}">
                                        </label>
                                        <span class="gym-set-entry-times">×</span>
                                        <label class="gym-field">
                                            <span>Gewicht</span>
                                            <div class="gym-input-suffix">
                                                <input type="number" id="gymQuickWeight" min="0" max="9999.99" step="0.25" inputmode="decimal" required value="${previous?.weight ?? ''}">
                                                <span>kg</span>
                                            </div>
                                        </label>
                                    </div>
                                `}

                                <button type="submit" class="gym-button gym-direct-entry-submit">Übung eintragen</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="gym-detail-section">
                    <h2>Verwendet in Trainingsplänen</h2>
                    ${data.used_in_plans.length
                        ? `<div class="gym-chip-list">${data.used_in_plans.map(plan => `<button type="button" class="gym-link-chip" data-plan-id="${plan.id}">${escapeHtml(plan.name)}</button>`).join('')}</div>`
                        : '<div class="gym-inline-empty">Aktuell in keinem Trainingsplan.</div>'}
                </div>
            </section>
        `;

        if (exercise.type === 'kardio') {
            updateQuickCardioManualUi(exercise);
            updateQuickCardioPreview(exercise);
        }

        // Wie im Satz-Modal: auf dem Smartphone ersetzt die erste Eingabe den
        // kompletten vorgeschlagenen Altwert, statt den Cursor nur hineinzusetzen.
        if (window.matchMedia('(max-width: 650px)').matches) {
            ['gymQuickCardioMinutes', 'gymQuickCardioDistanceKm', 'gymQuickManualKcal', 'gymQuickReps', 'gymQuickWeight'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (!input) return;
                input.type = 'text';
                const selectAll = () => window.setTimeout(() => {
                    try { input.select(); } catch (_) {}
                }, 0);
                input.addEventListener('focus', selectAll);
                input.addEventListener('click', selectAll);
            });
        }
    }

    function sessionExerciseProgress(exercise) {
        const completed = (exercise.current_sets || []).length;
        if (completed <= 0) return 'Offen';
        if (exercise.type === 'kardio') return 'Erfasst';
        return completed === 1 ? '1 Satz' : `${completed} Sätze`;
    }

    async function loadSession(sessionId) {
        const data = await apiGet('session_detail', { id: sessionId });
        state.currentSession = data.session;
        if (state.bootstrap && !data.session.is_finished) {
            state.bootstrap.active_session = {
                id: data.session.id,
                plan_id: data.session.plan_id,
                plan_name: data.session.plan_name,
                started_at: data.session.started_at
            };
        }
        return data.session;
    }

    async function renderSession(sessionId) {
        setLoading('Training wird geladen …');
        const session = await loadSession(sessionId);
        setHeader(
            session.plan_name,
            session.is_finished ? `${formatDateTime(session.started_at)} · Trainingslog` : 'Laufendes Training',
            true,
            false
        );
        activeSessionBanner.hidden = true;

        const doneCount = session.exercises.filter(exercise => (exercise.current_sets || []).length > 0).length;

        view.innerHTML = `
            <section class="gym-session-head-card">
                ${session.plan_image_url ? `<img src="${escapeHtml(session.plan_image_url)}" alt="${escapeHtml(session.plan_name)}" class="gym-session-plan-image">` : ''}
                <div class="gym-session-head-copy">
                    <span class="gym-session-kicker">${session.is_finished ? 'Trainingslog' : 'Übersicht Übungen'}</span>
                    <strong>${session.is_finished
                        ? `${session.exercises.length} ${session.exercises.length === 1 ? 'Übung protokolliert' : 'Übungen protokolliert'}`
                        : `${doneCount}/${session.exercises.length} Übungen begonnen`}
                    </strong>
                </div>
                <div class="gym-session-calories">
                    <span>Verbrauch</span>
                    <strong>${formatNumber(session.calories, 0)} kcal</strong>
                </div>
            </section>

            <div class="gym-workout-exercise-list">
                ${session.exercises.map((exercise, index) => `
                    <article class="gym-workout-exercise-card ${exercise.current_sets.length ? 'has-data' : ''}" data-session-exercise-id="${exercise.plan_exercise_id}">
                        <div class="gym-workout-order">${index + 1}</div>
                        ${imageMarkup(exercise, 'gym-workout-exercise-image')}
                        <div class="gym-workout-exercise-copy">
                            <h2>${escapeHtml(exercise.name)}</h2>
                            <div class="gym-workout-last">Letztes Mal: ${escapeHtml(exercise.previous_when)}</div>
                        </div>
                        <div class="gym-workout-progress">
                            <strong>${escapeHtml(sessionExerciseProgress(exercise))}</strong>
                            <span>›</span>
                        </div>
                    </article>
                `).join('')}
            </div>

            ${session.is_finished ? `
                <div class="gym-session-bottom-actions">
                    <button type="button" class="gym-button" data-action="session-to-logs">Zurück zu Logs</button>
                </div>
            ` : `
                <div class="gym-session-bottom-actions">
                    <button type="button" class="gym-button gym-button-danger-outline" data-action="cancel-session">Training abbrechen</button>
                    <button type="button" class="gym-button" data-action="finish-session">Training abschließen</button>
                </div>
            `}
        `;
    }

    function findSessionExercise(planExerciseId) {
        return state.currentSession?.exercises?.find(exercise => Number(exercise.plan_exercise_id) === Number(planExerciseId)) || null;
    }

    function currentSetByNumber(exercise, number) {
        return (exercise.current_sets || []).find(set => Number(set.set_number) === Number(number)) || null;
    }

    function previousSetByNumber(exercise, number) {
        return (exercise.previous_sets || []).find(set => Number(set.set_number) === Number(number)) || null;
    }

    function prefillSetByNumber(exercise, number) {
        const previous = previousSetByNumber(exercise, number);
        if (previous) {
            return { set: previous, source: `Letztes Mal · ${exercise.previous_when}` };
        }

        if (exercise.type === 'kraft' && !(exercise.previous_sets || []).length) {
            return {
                set: {
                    set_number: Number(number),
                    reps: 8,
                    weight: 0,
                    cardio_minutes: null,
                    cardio_distance_km: null,
                    calories: null,
                    calories_manual: false
                },
                source: 'Startwert'
            };
        }

        return { set: null, source: exercise.previous_when || 'Noch nie' };
    }

    async function renderSessionExercise(planExerciseId) {
        if (!state.currentSession) {
            const sessionId = state.route.sessionId;
            if (!sessionId) return navigate({ name: 'overview', tab: 'plans' }, { noHistory: true });
            await loadSession(sessionId);
        }

        const exercise = findSessionExercise(planExerciseId);
        if (!exercise) {
            showToast('Übung im Training nicht gefunden.', true);
            return navigate({ name: 'session', sessionId: state.currentSession.id }, { noHistory: true });
        }

        state.currentSessionExerciseId = Number(planExerciseId);
        setHeader(exercise.name, `Übersicht Sets · ${exercise.previous_when}`, true, false);
        activeSessionBanner.hidden = true;

        const extra = state.extraSetCounts.get(Number(planExerciseId)) || 0;
        const finishedSetNumbers = (exercise.current_sets || [])
            .map(set => Number(set.set_number))
            .filter(number => Number.isFinite(number) && number > 0)
            .sort((a, b) => a - b);

        const setNumbers = state.currentSession.is_finished
            ? finishedSetNumbers
            : Array.from(
                {
                    length: exercise.type === 'kardio'
                        ? 1
                        : Math.max(1, Number(exercise.display_set_count || 3) + extra)
                },
                (_, index) => index + 1
            );

        const cards = [];
        for (const setNumber of setNumbers) {
            const current = currentSetByNumber(exercise, setNumber);
            const prefill = prefillSetByNumber(exercise, setNumber);
            const previous = prefill.set;
            const baseSetCount = Number(exercise.display_set_count || (exercise.type === 'kardio' ? 1 : 3));
            const isExtra = exercise.type !== 'kardio' && setNumber > baseSetCount;

            cards.push(`
                <button type="button" class="gym-set-card ${current ? 'is-complete' : ''} ${isExtra ? 'is-extra' : ''}" data-set-number="${setNumber}">
                    <div class="gym-set-card-head">
                        <span>Satz ${setNumber}${isExtra ? ' · extra' : ''}</span>
                        ${current ? '<span class="gym-set-check">✓</span>' : '<span class="gym-set-open">›</span>'}
                    </div>
                    <div class="gym-set-columns">
                        <div>
                            <span>${escapeHtml(prefill.source)}</span>
                            <strong>${escapeHtml(formatSet(previous, exercise))}</strong>
                        </div>
                        <div>
                            <span>${state.currentSession.is_finished ? 'Log' : 'Heute'}</span>
                            <strong>${escapeHtml(formatSet(current, exercise))}</strong>
                        </div>
                    </div>
                </button>
            `);
        }

        view.innerHTML = `
            <section class="gym-session-exercise-summary">
                ${imageMarkup(exercise, 'gym-session-exercise-image')}
                <div>
                    <span class="gym-badge">${exercise.type === 'kardio' ? `Kardio · ${cardioMode(exercise) === 'strecke' ? 'Strecke' : 'Zeit'}` : 'Krafttraining'}</span>
                    <strong>${exercise.previous_sets?.length
                        ? `Geladen von ${escapeHtml(exercise.previous_when)}`
                        : (exercise.type === 'kraft' ? 'Start mit 3 × 8 bei 0 kg' : 'Noch kein vorheriger Eintrag')}</strong>
                    ${exercise.type === 'kardio'
                        ? `<span>${escapeHtml(cardioRateText(exercise))}</span>`
                        : ''}
                </div>
            </section>

            <div class="gym-set-list">
                ${cards.length ? cards.join('') : '<div class="gym-inline-empty">Keine protokollierten Sätze in diesem Log.</div>'}
            </div>

            <div class="gym-session-bottom-actions">
                ${exercise.type === 'kraft' && !state.currentSession.is_finished
                    ? '<button type="button" class="gym-button gym-button-secondary" data-action="add-set">+ Satz hinzufügen</button>'
                    : ''}

                ${state.currentSession.is_finished
                    ? '<button type="button" class="gym-button gym-button-danger-outline" data-action="delete-log-exercise">Übung aus Log löschen</button>'
                    : ''}

                ${!state.currentSession.is_finished && state.currentSession.is_quick
                    ? '<button type="button" class="gym-button" data-action="finish-session">Eintrag abschließen</button>'
                    : `<button type="button" class="gym-button" data-action="back-to-session">${state.currentSession.is_finished ? 'Zurück zum Log' : 'Zur Übungsübersicht'}</button>`}
            </div>
        `;
    }

    async function renderRoute() {
        renderActiveSessionBanner();
        try {
            if (!state.bootstrap) {
                setLoading('Gym wird geladen …');
                await refreshBootstrap();
            }

            switch (state.route.name) {
                case 'plan':
                    await renderPlanDetail(state.route.id);
                    break;
                case 'exercise':
                    await renderExerciseDetail(state.route.id);
                    break;
                case 'session':
                    await renderSession(state.route.sessionId);
                    break;
                case 'session_exercise':
                    if (!state.currentSession || Number(state.currentSession.id) !== Number(state.route.sessionId)) {
                        await loadSession(state.route.sessionId);
                    }
                    await renderSessionExercise(state.route.planExerciseId);
                    break;
                case 'overview':
                default:
                    await renderOverview();
                    break;
            }
        } catch (error) {
            console.error(error);
            view.innerHTML = `
                <div class="gym-empty-state gym-error-state">
                    <h2>Gym konnte nicht geladen werden</h2>
                    <p>${escapeHtml(error.message || 'Unbekannter Fehler')}</p>
                    <button type="button" class="gym-button" data-action="retry">Erneut laden</button>
                </div>
            `;
        }
    }

    function openModal(html, options = {}) {
        modalBody.innerHTML = html;
        modal.classList.toggle('gym-set-modal-active', options.variant === 'set');
        modal.classList.remove('hidden');
        document.body.classList.add('gym-modal-open');

        if (options.autoFocus !== false) {
            const focusable = modalBody.querySelector('input, select, button, textarea');
            if (focusable) setTimeout(() => focusable.focus(), 0);
        }
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('gym-set-modal-active');
        modalBody.innerHTML = '';
        document.body.classList.remove('gym-modal-open');
        state.planDraft = null;
        state.planDragIndex = null;
    }

    function muscleCheckboxes(selectedIds = [], prefix = 'primary') {
        const muscles = Array.isArray(state.bootstrap?.muscles) ? state.bootstrap.muscles : [];
        const selected = new Set((selectedIds || []).map(Number));

        return muscles.map((muscle, index) => `
            <label class="gym-check-chip">
                <input type="checkbox" name="${prefix}" value="${Number(muscle.id)}" ${selected.has(Number(muscle.id)) ? 'checked' : ''}>
                <span><span class="gym-check-chip-order">${index + 1}</span>${escapeHtml(muscle.name)}</span>
            </label>
        `).join('');
    }

    function updateExerciseCardioModeFields() {
        const mode = document.getElementById('gymExerciseCardioMode')?.value || 'zeit';
        const timeFields = document.getElementById('gymExerciseCardioTimeFields');
        const distanceFields = document.getElementById('gymExerciseCardioDistanceFields');
        if (timeFields) timeFields.hidden = mode !== 'zeit';
        if (distanceFields) distanceFields.hidden = mode !== 'strecke';
    }

    function updateExerciseTypeFields() {
        const typeInput = document.getElementById('gymExerciseType');
        const cardioModeInput = document.getElementById('gymExerciseCardioMode');
        const type = typeInput?.value || 'kraft';
        const cardioMode = cardioModeInput?.value || 'zeit';
        const kraft = document.getElementById('gymExerciseKraftFields');
        const cardio = document.getElementById('gymExerciseCardioFields');

        if (kraft) kraft.hidden = type !== 'kraft';
        if (cardio) cardio.hidden = type !== 'kardio';
        if (type === 'kardio') updateExerciseCardioModeFields();

        const activeVariant = type === 'kraft'
            ? 'kraft'
            : (cardioMode === 'strecke' ? 'kardio-strecke' : 'kardio-zeit');

        document.querySelectorAll('[data-exercise-type-choice]').forEach(button => {
            const isActive = button.dataset.exerciseTypeChoice === activeVariant;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    function setExerciseTypeVariant(variant) {
        const typeInput = document.getElementById('gymExerciseType');
        const cardioModeInput = document.getElementById('gymExerciseCardioMode');
        if (!typeInput || !cardioModeInput) return;

        if (variant === 'kraft') {
            typeInput.value = 'kraft';
            cardioModeInput.value = '';
        } else if (variant === 'kardio-strecke') {
            typeInput.value = 'kardio';
            cardioModeInput.value = 'strecke';
        } else {
            typeInput.value = 'kardio';
            cardioModeInput.value = 'zeit';
        }

        updateExerciseTypeFields();
    }

    async function openExerciseModal(exercise = null) {
        const edit = Boolean(exercise);
        openModal(`
            <form id="gymExerciseForm" class="gym-form" enctype="multipart/form-data" novalidate>
                <h2 id="gymModalTitle">${edit ? 'Übung bearbeiten' : 'Übung erstellen'}</h2>
                <input type="hidden" name="id" value="${edit ? exercise.id : ''}">

                <label class="gym-field">
                    <span>Name</span>
                    <input type="text" name="name" maxlength="255" required value="${edit ? escapeHtml(exercise.name) : ''}" placeholder="z. B. Bankdrücken">
                </label>

                <label class="gym-field">
                    <span>Bild ${edit ? '<small>optional ersetzen</small>' : ''}</span>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" ${edit ? '' : 'required'}>
                </label>

                <div class="gym-field">
                    <span>Typ</span>
                    <input type="hidden" name="type" id="gymExerciseType" value="${edit ? escapeHtml(exercise.type) : 'kraft'}">
                    <input type="hidden" name="cardio_mode" id="gymExerciseCardioMode" value="${edit && exercise.type === 'kardio' ? escapeHtml(exercise.cardio_mode || 'zeit') : ''}">
                    <div class="gym-tabs gym-exercise-type-tabs" role="tablist" aria-label="Übungstyp auswählen">
                        <button type="button" class="gym-tab" data-exercise-type-choice="kraft" role="tab">Krafttraining</button>
                        <button type="button" class="gym-tab" data-exercise-type-choice="kardio-zeit" role="tab">Kardio (Zeit)</button>
                        <button type="button" class="gym-tab" data-exercise-type-choice="kardio-strecke" role="tab">Kardio (Strecke)</button>
                    </div>
                </div>

                <div id="gymExerciseKraftFields" class="gym-dynamic-fields" ${edit && exercise.type === 'kraft' ? '' : 'hidden'}>
                    <fieldset class="gym-fieldset">
                        <legend>1. Primäre Muskelgruppen</legend>
                        <div class="gym-check-grid">${muscleCheckboxes(exercise?.primary_muscle_ids || [], 'primary')}</div>
                    </fieldset>

                    <fieldset class="gym-fieldset">
                        <legend>2. Sekundäre Muskelgruppen</legend>
                        <div class="gym-check-grid">${muscleCheckboxes(exercise?.secondary_muscle_ids || [], 'secondary')}</div>
                    </fieldset>
                </div>

                <div id="gymExerciseCardioFields" class="gym-dynamic-fields" ${edit && exercise.type === 'kardio' ? '' : 'hidden'}>
                    <div id="gymExerciseCardioTimeFields" ${edit && exercise.type === 'kardio' && exercise.cardio_mode === 'strecke' ? 'hidden' : ''}>
                        <label class="gym-field">
                            <span>Kalorienverbrauch pro Stunde</span>
                            <div class="gym-input-suffix">
                                <input type="number" name="cardio_kcal_per_hour" min="1" max="5000" step="1" value="${edit && exercise.cardio_kcal_per_hour ? exercise.cardio_kcal_per_hour : ''}">
                                <span>kcal/h</span>
                            </div>
                        </label>
                    </div>

                    <div id="gymExerciseCardioDistanceFields" ${edit && exercise.type === 'kardio' && exercise.cardio_mode === 'strecke' ? '' : 'hidden'}>
                        <label class="gym-field">
                            <span>Kalorienverbrauch pro Kilometer</span>
                            <div class="gym-input-suffix">
                                <input type="number" name="cardio_kcal_per_km" min="0.01" max="5000" step="0.01" inputmode="decimal" value="${edit && exercise.cardio_kcal_per_km ? exercise.cardio_kcal_per_km : ''}">
                                <span>kcal/km</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="gym-modal-actions">
                    <button type="button" class="gym-button gym-button-secondary" data-action="close-modal">Abbrechen</button>
                    <button type="submit" id="gymExerciseSaveButton" class="gym-button" data-action="save-exercise">${edit ? 'Speichern' : 'Übung erstellen'}</button>
                </div>
            </form>
        `);
        updateExerciseTypeFields();
        updateExerciseCardioModeFields();

        // Primärer Save-Pfad direkt am Button. Damit sind wir nicht davon abhängig,
        // dass der Browser beim dynamisch erzeugten Modal zuverlässig ein submit-Event erzeugt.
        const saveButton = document.getElementById('gymExerciseSaveButton');
        const form = document.getElementById('gymExerciseForm');

        if (!saveButton || !form) {
            showToast('JavaScript-Fehler: Übungsformular oder Speichern-Button wurde nicht gefunden.', 'error', 12000);
            console.error('Gym exercise modal binding failed', { saveButton, form });
            return;
        }

        form.querySelectorAll('[data-exercise-type-choice]').forEach(button => {
            button.addEventListener('click', () => {
                setExerciseTypeVariant(button.dataset.exerciseTypeChoice || 'kraft');
            });
        });

        saveButton.addEventListener('click', async event => {
            event.preventDefault();
            event.stopPropagation();
            showToast('Speichere Übung …', 'info', 0);

            try {
                await saveExerciseForm(form);
            } catch (error) {
                console.error('Gym exercise save failed:', error);
                showToast(`Übung konnte nicht gespeichert werden: ${describeJsError(error)}`, 'error', 12000);
            }
        });
    }

    function exerciseById(id) {
        return (state.bootstrap?.exercises || []).find(exercise => Number(exercise.id) === Number(id)) || null;
    }

    function newPlanDraft(plan = null) {
        return {
            id: plan?.id || null,
            name: plan?.name || '',
            image_url: plan?.image_url || null,
            days: plan?.days ? [...plan.days] : [],
            exercises: plan?.exercises ? plan.exercises.map(item => ({
                plan_exercise_id: item.plan_exercise_id,
                exercise_id: item.exercise_id,
            })) : []
        };
    }

    function syncPlanDraftFromDom() {
        if (!state.planDraft) return;

        const nameInput = document.getElementById('gymPlanName');
        if (nameInput) state.planDraft.name = nameInput.value;

        state.planDraft.days = Array.from(document.querySelectorAll('input[name="gym_plan_day"]:checked'))
            .map(input => Number(input.value));
    }

    function renderPlanBuilderRows() {
        const container = document.getElementById('gymPlanBuilderRows');
        if (!container || !state.planDraft) return;

        if (!state.planDraft.exercises.length) {
            container.innerHTML = '<div class="gym-plan-builder-empty">Noch keine Übung hinzugefügt.</div>';
            return;
        }

        container.innerHTML = state.planDraft.exercises.map((item, index) => {
            const exercise = exerciseById(item.exercise_id);
            if (!exercise) return '';

            return `
                <div class="gym-plan-builder-row" data-index="${index}">
                    <button type="button" class="gym-drag-handle" draggable="true" title="Ziehen zum Verschieben" aria-label="Übung verschieben">⋮⋮</button>
                    ${imageMarkup(exercise, 'gym-plan-builder-image')}
                    <div class="gym-plan-builder-copy">
                        <strong>${escapeHtml(exercise.name)}</strong>
                    </div>
                    <div class="gym-plan-builder-move">
                        <button type="button" class="gym-icon-button" data-action="plan-move-up" data-index="${index}" ${index === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" class="gym-icon-button" data-action="plan-move-down" data-index="${index}" ${index === state.planDraft.exercises.length - 1 ? 'disabled' : ''}>↓</button>
                        <button type="button" class="gym-icon-button gym-icon-button-danger" data-action="plan-remove-exercise" data-index="${index}" aria-label="Entfernen">×</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    function availablePlanExerciseOptions() {
        const selected = new Set((state.planDraft?.exercises || []).map(item => Number(item.exercise_id)));
        const muscles = Array.isArray(state.bootstrap?.muscles) ? state.bootstrap.muscles : [];
        const muscleById = new Map(muscles.map((muscle, index) => [
            Number(muscle.id),
            { ...muscle, index },
        ]));

        return (state.bootstrap?.exercises || [])
            .filter(exercise => !selected.has(Number(exercise.id)))
            .map(exercise => {
                const isCardio = exercise.type === 'kardio';
                const primaryId = Array.isArray(exercise.primary_muscle_ids) && exercise.primary_muscle_ids.length
                    ? Number(exercise.primary_muscle_ids[0])
                    : 0;
                const primaryMuscle = muscleById.get(primaryId);
                const group = isCardio ? 'Kardio' : (primaryMuscle?.name || 'Krafttraining');

                return {
                    exercise,
                    group,
                    sortGroup: isCardio
                        ? muscles.length + 1
                        : (primaryMuscle?.index ?? muscles.length),
                };
            })
            .sort((a, b) => {
                if (a.sortGroup !== b.sortGroup) return a.sortGroup - b.sortGroup;
                return String(a.exercise.name || '').localeCompare(String(b.exercise.name || ''), 'de', { sensitivity: 'base' });
            })
            .map(({ exercise, group }) =>
                `<option value="${exercise.id}">${escapeHtml(group)} - ${escapeHtml(exercise.name)}</option>`
            )
            .join('');
    }

    function refreshPlanExerciseSelect() {
        const select = document.getElementById('gymPlanExerciseSelect');
        if (!select) return;
        const options = availablePlanExerciseOptions();
        select.innerHTML = `<option value="" selected disabled>Übung hinzufügen …</option>${options}`;
        select.disabled = !options;
    }

    function addExerciseToPlanDraft(exerciseId) {
        syncPlanDraftFromDom();
        const exercise = exerciseById(Number(exerciseId));
        if (!exercise || !state.planDraft) return false;

        state.planDraft.exercises.push({
            plan_exercise_id: null,
            exercise_id: Number(exercise.id),
        });

        renderPlanBuilderRows();
        refreshPlanExerciseSelect();
        return true;
    }

    async function openPlanModal(plan = null) {
        if (!(state.bootstrap?.exercises || []).length) {
            showToast('Lege zuerst mindestens eine Übung an.', true);
            await openExerciseModal();
            return;
        }

        state.planDraft = newPlanDraft(plan);

        openModal(`
            <form id="gymPlanForm" class="gym-form gym-plan-form" enctype="multipart/form-data" novalidate>
                <h2 id="gymModalTitle">${plan ? 'Training bearbeiten' : 'Training erstellen'}</h2>

                <label class="gym-field">
                    <span>Name</span>
                    <input type="text" id="gymPlanName" maxlength="255" required value="${escapeHtml(state.planDraft.name)}" placeholder="z. B. Push">
                </label>

                <label class="gym-field">
                    <span>Bild ${plan ? '<small>optional ersetzen</small>' : ''}</span>
                    ${plan?.image_url ? `<img src="${escapeHtml(plan.image_url)}" alt="${escapeHtml(plan.name)}" class="gym-plan-edit-image">` : ''}
                    <input type="file" id="gymPlanImage" name="image" accept="image/jpeg,image/png,image/webp" ${plan ? '' : 'required'}>
                </label>

                <fieldset class="gym-fieldset">
                    <legend>Wochentage</legend>
                    <div class="gym-weekday-selector">
                        ${Object.entries(state.bootstrap.weekdays).map(([day, label]) => `
                            <label>
                                <input type="checkbox" name="gym_plan_day" value="${day}" ${state.planDraft.days.includes(Number(day)) ? 'checked' : ''}>
                                <span>${weekdayShort[day]}<small>${escapeHtml(label)}</small></span>
                            </label>
                        `).join('')}
                    </div>
                </fieldset>

                <div class="gym-plan-add-row">
                    <select id="gymPlanExerciseSelect" aria-label="Übung zum Training hinzufügen"></select>
                </div>

                <div id="gymPlanBuilderRows" class="gym-plan-builder"></div>

                <div class="gym-modal-actions">
                    <button type="button" class="gym-button gym-button-secondary" data-action="close-modal">Abbrechen</button>
                    <button type="submit" class="gym-button">${plan ? 'Speichern' : 'Training erstellen'}</button>
                </div>
            </form>
        `);

        refreshPlanExerciseSelect();
        renderPlanBuilderRows();
    }

    function openSetModal(exercise, setNumber) {
        const current = currentSetByNumber(exercise, setNumber);
        const prefill = prefillSetByNumber(exercise, setNumber);
        const previous = prefill.set;
        const editingLog = Boolean(state.currentSession?.is_finished);
        const manualCaloriesEnabled = Boolean(current?.calories_manual);

        const cardioEntry = exercise.type === 'kardio'
            ? (cardioMode(exercise) === 'strecke' ? `
                <label class="gym-field">
                    <span>Strecke</span>
                    <div class="gym-input-suffix">
                        <input type="number" id="gymSetCardioDistanceKm" min="0.01" max="10000" step="0.01" inputmode="decimal" required value="${current?.cardio_distance_km ?? previous?.cardio_distance_km ?? ''}">
                        <span>km</span>
                    </div>
                </label>
            ` : `
                <label class="gym-field">
                    <span>Zeit</span>
                    <div class="gym-input-suffix">
                        <input type="number" id="gymSetCardioMinutes" min="1" max="1440" step="1" inputmode="numeric" required value="${current?.cardio_minutes ?? previous?.cardio_minutes ?? ''}">
                        <span>min</span>
                    </div>
                </label>
            `)
            : '';

        openModal(`
            <form id="gymSetForm" class="gym-form gym-set-form">
                <h2 id="gymModalTitle">${escapeHtml(exercise.name)} · Satz ${setNumber}</h2>

                <div class="gym-set-modal-context">
                    <div>
                        <span>${escapeHtml(prefill.source)}</span>
                        <strong>${escapeHtml(formatSet(previous, exercise))}</strong>
                    </div>
                </div>

                ${exercise.type === 'kardio' ? `
                    ${cardioEntry}

                    <div class="gym-cardio-kcal-control">
                        <label class="gym-manual-kcal-toggle">
                            <input type="checkbox" id="gymSetManualKcalToggle" ${manualCaloriesEnabled ? 'checked' : ''}>
                            <span>Kalorien manuell setzen</span>
                        </label>

                        <label id="gymSetManualKcalField" class="gym-field gym-manual-kcal-field" ${manualCaloriesEnabled ? '' : 'hidden'}>
                            <span>Verbrauchte Kalorien</span>
                            <div class="gym-input-suffix">
                                <input type="number" id="gymSetManualKcal" min="0" max="50000" step="1" inputmode="numeric" value="${manualCaloriesEnabled ? (current?.calories ?? '') : ''}">
                                <span>kcal</span>
                            </div>
                        </label>

                        <div id="gymSetCardioKcal" class="gym-cardio-kcal-preview"></div>
                    </div>
                ` : `
                    <div class="gym-set-entry-grid">
                        <label class="gym-field">
                            <span>Reps</span>
                            <input type="number" id="gymSetReps" min="1" max="1000" step="1" inputmode="numeric" required value="${current?.reps ?? previous?.reps ?? 8}">
                        </label>
                        <span class="gym-set-entry-times">×</span>
                        <label class="gym-field">
                            <span>Gewicht</span>
                            <div class="gym-input-suffix">
                                <input type="number" id="gymSetWeight" min="0" max="9999.99" step="0.25" inputmode="decimal" required value="${current?.weight ?? previous?.weight ?? 0}">
                                <span>kg</span>
                            </div>
                        </label>
                    </div>
                `}


                <div class="gym-modal-actions gym-set-modal-actions ${current ? 'has-delete' : ''}">
                    <button type="button" class="gym-button gym-button-secondary gym-set-cancel-button" data-action="close-modal">${editingLog ? 'Schließen' : 'Abbrechen'}</button>
                    ${current ? '<button type="button" class="gym-button gym-button-danger-outline" data-action="delete-set">Satz löschen</button>' : ''}
                    <button type="submit" class="gym-button">${editingLog ? 'Log speichern' : 'Speichern'}</button>
                </div>

                <input type="hidden" id="gymSetPlanExerciseId" value="${exercise.plan_exercise_id}">
                <input type="hidden" id="gymSetNumber" value="${setNumber}">
            </form>
        `, { autoFocus: !window.matchMedia('(max-width: 650px)').matches, variant: 'set' });

        if (exercise.type === 'kardio') {
            updateCardioManualUi(exercise);
            updateCardioPreview(exercise);
        }

        // Auf dem Smartphone soll ein Tap den vorbefüllten/aktuellen Wert komplett
        // markieren. Die erste Zahl ersetzt damit sofort den gesamten Feldinhalt.
        if (window.matchMedia('(max-width: 650px)').matches) {
            ['gymSetCardioMinutes', 'gymSetCardioDistanceKm', 'gymSetManualKcal', 'gymSetReps', 'gymSetWeight'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (!input) return;

                // type=number lässt in einigen Mobile-Browsern keine zuverlässige Textauswahl zu.
                // inputmode hält trotzdem die passende Zahlentastatur offen.
                input.type = 'text';

                const selectAll = () => {
                    window.setTimeout(() => {
                        try { input.select(); } catch (_) {}
                    }, 0);
                };

                input.addEventListener('focus', selectAll);
                input.addEventListener('click', selectAll);
            });
        }
    }

    function automaticCardioCalories(exercise) {
        if (cardioMode(exercise) === 'strecke') {
            const distance = parseNumber(document.getElementById('gymSetCardioDistanceKm')?.value, 0);
            return distance > 0
                ? Math.round(distance * Number(exercise.cardio_kcal_per_km || 0))
                : 0;
        }

        const minutes = parseNumber(document.getElementById('gymSetCardioMinutes')?.value, 0);
        return minutes > 0
            ? Math.round((minutes / 60) * Number(exercise.cardio_kcal_per_hour || 0))
            : 0;
    }

    function updateCardioManualUi(exercise) {
        const toggle = document.getElementById('gymSetManualKcalToggle');
        const field = document.getElementById('gymSetManualKcalField');
        const input = document.getElementById('gymSetManualKcal');
        if (!toggle || !field) return;

        field.hidden = !toggle.checked;

        if (toggle.checked && input && String(input.value || '').trim() === '') {
            const automatic = automaticCardioCalories(exercise);
            input.value = automatic > 0 ? String(automatic) : '';
        }
    }

    function updateCardioPreview(exercise) {
        const preview = document.getElementById('gymSetCardioKcal');
        if (!preview) return;

        const manualToggle = document.getElementById('gymSetManualKcalToggle');
        if (manualToggle?.checked) {
            preview.hidden = true;
            preview.textContent = '';
            return;
        }

        const automatic = automaticCardioCalories(exercise);
        preview.hidden = false;
        preview.textContent = automatic > 0 ? `≈ ${formatNumber(automatic, 0)} kcal` : '—';
    }

    async function saveExerciseForm(form) {
        const id = Number(form.querySelector('[name="id"]')?.value || 0);
        const nameInput = form.querySelector('[name="name"]');
        const imageInput = form.querySelector('[name="image"]');
        const typeInput = form.querySelector('[name="type"]');
        const cardioModeInput = form.querySelector('[name="cardio_mode"]');
        const cardioInput = form.querySelector('[name="cardio_kcal_per_hour"]');
        const cardioDistanceInput = form.querySelector('[name="cardio_kcal_per_km"]');

        const name = String(nameInput?.value || '').trim();
        const type = String(typeInput?.value || '');
        const primary = Array.from(form.querySelectorAll('input[name="primary"]:checked')).map(input => Number(input.value));
        const secondary = Array.from(form.querySelectorAll('input[name="secondary"]:checked')).map(input => Number(input.value));
        const imageFile = imageInput?.files?.[0] || null;

        // Eigene Validierung statt stiller Browser-Blockade durch required-Felder.
        // So bekommt man auch im Modal und auf Android immer eine sichtbare Meldung.
        if (!name) {
            showToast('Bitte einen Namen für die Übung eingeben.', true);
            nameInput?.focus();
            return;
        }

        if (name.length > 255) {
            showToast('Der Übungsname darf maximal 255 Zeichen lang sein.', true);
            nameInput?.focus();
            return;
        }

        if (!['kraft', 'kardio'].includes(type)) {
            showToast('Bitte Krafttraining oder Kardio auswählen.', true);
            typeInput?.focus();
            return;
        }

        if (id <= 0 && !imageFile) {
            showToast('Bitte ein Bild für die Übung auswählen.', true);
            imageInput?.focus();
            return;
        }

        const serverUploadLimit = Number(state.bootstrap?.upload_max_bytes || (8 * 1024 * 1024));
        if (imageFile && imageFile.size > serverUploadLimit) {
            const label = state.bootstrap?.upload_max_label || `${Math.round(serverUploadLimit / 1024 / 1024 * 10) / 10} MiB`;
            showToast(`Das Bild ist zu groß. Aktuelles Server-Limit: ${label}.`, 'error', 10000);
            imageInput?.focus();
            return;
        }

        if (type === 'kraft' && primary.length === 0) {
            showToast('Bitte mindestens eine primäre Muskelgruppe auswählen.', true);
            form.querySelector('input[name="primary"]')?.focus();
            return;
        }


        if (type === 'kardio') {
            const mode = String(cardioModeInput?.value || 'zeit');

            if (!['zeit', 'strecke'].includes(mode)) {
                showToast('Bitte Zeit oder Strecke als Kardio-Erfassung auswählen.', true);
                cardioModeInput?.focus();
                return;
            }

            if (mode === 'strecke') {
                const kcalPerKm = parseNumber(cardioDistanceInput?.value, 0);
                if (kcalPerKm <= 0 || kcalPerKm > 5000) {
                    showToast('Bitte einen gültigen kcal-Verbrauch pro Kilometer angeben.', true);
                    cardioDistanceInput?.focus();
                    return;
                }
            } else {
                const kcal = parseNumber(cardioInput?.value, 0);
                if (kcal <= 0 || kcal > 5000) {
                    showToast('Bitte einen gültigen kcal-Verbrauch pro Stunde angeben.', true);
                    cardioInput?.focus();
                    return;
                }
            }
        }

        const submitButton = form.querySelector('button[type="submit"]');
        const oldSubmitText = submitButton?.textContent || '';

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Speichert …';
        }

        try {
            const data = new FormData(form);
            data.set('name', name);
            data.set('primary_muscle_ids', JSON.stringify(primary));
            data.set('secondary_muscle_ids', JSON.stringify(secondary));
            if (type === 'kraft') {
                data.set('cardio_mode', '');
                data.set('cardio_kcal_per_hour', '');
                data.set('cardio_kcal_per_km', '');
            } else {
                if (String(cardioModeInput?.value || 'zeit') === 'strecke') {
                    data.set('cardio_kcal_per_hour', '');
                } else {
                    data.set('cardio_kcal_per_km', '');
                }
            }

            showToast('Sende Übung und Bild an den Server …', 'info', 0);
            const result = await apiPost('exercise_save', data, { formData: true });

            // Ab hier hat die API gespeichert. Ein späterer Refresh-Fehler wird separat gemeldet.
            showToast(
                id > 0 ? 'Übung erfolgreich gespeichert.' : 'Übung erfolgreich erstellt.',
                'success',
                5000
            );
            closeModal();

            try {
                await refreshBootstrap();
                if (state.route.name === 'exercise') {
                    await navigate({ name: 'overview', tab: 'exercises' }, { noHistory: true });
                } else {
                    await renderRoute();
                }
            } catch (refreshError) {
                console.error('Exercise saved, but UI refresh failed:', refreshError, result);
                showToast(
                    `Übung wurde gespeichert, aber die Ansicht konnte nicht aktualisiert werden: ${describeJsError(refreshError)}`,
                    'error',
                    12000
                );
            }
        } finally {
            // Falls der Request fehlschlägt, bleibt das Modal offen und der Button wird wieder nutzbar.
            if (submitButton && document.body.contains(submitButton)) {
                submitButton.disabled = false;
                submitButton.textContent = oldSubmitText;
            }
        }
    }

    async function savePlanForm(form) {
        syncPlanDraftFromDom();
        const draft = state.planDraft;
        if (!draft || !form) return;

        const name = String(draft.name || '').trim();
        const imageInput = document.getElementById('gymPlanImage');
        const imageFile = imageInput?.files?.[0] || null;

        if (!name) {
            showToast('Bitte einen Namen für das Training eingeben.', 'error', 8000);
            document.getElementById('gymPlanName')?.focus();
            return;
        }
        if (!draft.days.length) {
            showToast('Bitte mindestens einen Wochentag auswählen.', 'error', 8000);
            return;
        }
        if (!draft.exercises.length) {
            showToast('Bitte mindestens eine Übung zum Training hinzufügen.', 'error', 8000);
            return;
        }
        if (!draft.id && !imageFile) {
            showToast('Bitte ein Bild für das Training auswählen.', 'error', 8000);
            imageInput?.focus();
            return;
        }
        if (imageFile && state.bootstrap?.upload_max_bytes && imageFile.size > Number(state.bootstrap.upload_max_bytes)) {
            showToast(`Das Bild ist zu groß. Server-Limit: ${state.bootstrap.upload_max_label}.`, 'error', 10000);
            imageInput?.focus();
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        const oldSubmitText = submitButton?.textContent || 'Speichern';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Speichert …';
        }

        try {
            const payload = new FormData();
            payload.set('id', draft.id || '');
            payload.set('name', name);
            payload.set('days', JSON.stringify(draft.days));
            payload.set('exercises', JSON.stringify(draft.exercises));
            if (imageFile) payload.set('image', imageFile);

            showToast('Speichere Training …', 'info', 0);
            const data = await apiPost('plan_save', payload, { formData: true });
            state.logs = null;
            closeModal();
            await refreshBootstrap();
            showToast(draft.id ? 'Training erfolgreich gespeichert.' : 'Training erfolgreich erstellt.', 'success');
            await navigate({ name: 'plan', id: data.plan.id }, { noHistory: true });
        } finally {
            if (submitButton && document.body.contains(submitButton)) {
                submitButton.disabled = false;
                submitButton.textContent = oldSubmitText;
            }
        }
    }

    async function startPlan(planId) {
        showToast('Starte Training …', 'info', 0);
        const data = await apiPost('start_session', { plan_id: planId });
        state.currentSession = data.session;
        if (state.bootstrap) {
            state.bootstrap.active_session = {
                id: data.session.id,
                plan_id: data.session.plan_id,
                plan_name: data.session.plan_name,
                started_at: data.session.started_at
            };
        }

        const firstExercise = data.session?.exercises?.[0] || null;
        if (!data.resumed && firstExercise) {
            // Neues Training: den ohnehin unmittelbar folgenden Klick auf die erste Übung sparen.
            // Der Zurück-Pfeil führt trotzdem sauber zur Übungsübersicht des laufenden Trainings.
            state.history = [{ name: 'session', sessionId: data.session.id }];
            await navigate({
                name: 'session_exercise',
                sessionId: data.session.id,
                planExerciseId: firstExercise.plan_exercise_id
            }, { noHistory: true });
            showToast('Training gestartet · erste Übung geöffnet.', 'success');
            return;
        }

        state.history = [];
        await navigate({ name: 'session', sessionId: data.session.id }, { noHistory: true });
        showToast(data.resumed ? 'Laufendes Training fortgesetzt.' : 'Training gestartet.', 'success');
    }

    function automaticQuickCardioCalories(exercise) {
        if (cardioMode(exercise) === 'strecke') {
            const distance = parseNumber(document.getElementById('gymQuickCardioDistanceKm')?.value, 0);
            return distance > 0
                ? Math.round(distance * Number(exercise.cardio_kcal_per_km || 0))
                : 0;
        }

        const minutes = parseNumber(document.getElementById('gymQuickCardioMinutes')?.value, 0);
        return minutes > 0
            ? Math.round((minutes / 60) * Number(exercise.cardio_kcal_per_hour || 0))
            : 0;
    }

    function updateQuickCardioManualUi(exercise) {
        const toggle = document.getElementById('gymQuickManualKcalToggle');
        const field = document.getElementById('gymQuickManualKcalField');
        const input = document.getElementById('gymQuickManualKcal');
        if (!toggle || !field) return;

        field.hidden = !toggle.checked;
        if (toggle.checked && input && String(input.value || '').trim() === '') {
            const automatic = automaticQuickCardioCalories(exercise);
            input.value = automatic > 0 ? String(automatic) : '';
        }
    }

    function updateQuickCardioPreview(exercise) {
        const preview = document.getElementById('gymQuickCardioKcal');
        if (!preview) return;

        const manualToggle = document.getElementById('gymQuickManualKcalToggle');
        if (manualToggle?.checked) {
            preview.hidden = true;
            preview.textContent = '';
            return;
        }

        const automatic = automaticQuickCardioCalories(exercise);
        preview.hidden = false;
        preview.textContent = automatic > 0 ? `≈ ${formatNumber(automatic, 0)} kcal` : '—';
    }

    async function saveQuickEntryForm(form) {
        const exercise = state.currentExercise;
        const exerciseId = Number(form?.dataset.exerciseId || exercise?.id || 0);
        if (!exercise || !exerciseId) {
            throw new Error('Übung für den Direkteintrag nicht gefunden.');
        }

        const payload = { exercise_id: exerciseId };

        if (exercise.type === 'kardio') {
            if (cardioMode(exercise) === 'strecke') {
                payload.cardio_distance_km = parseNumber(document.getElementById('gymQuickCardioDistanceKm')?.value, 0);
                if (!(payload.cardio_distance_km > 0)) {
                    showToast('Bitte eine gültige Strecke eingeben.', 'error', 8000);
                    document.getElementById('gymQuickCardioDistanceKm')?.focus();
                    return;
                }
            } else {
                payload.cardio_minutes = parseNumber(document.getElementById('gymQuickCardioMinutes')?.value, 0);
                if (!(payload.cardio_minutes > 0)) {
                    showToast('Bitte eine gültige Zeit eingeben.', 'error', 8000);
                    document.getElementById('gymQuickCardioMinutes')?.focus();
                    return;
                }
            }

            const manualToggle = document.getElementById('gymQuickManualKcalToggle');
            payload.calories_manual = manualToggle?.checked ? 1 : 0;
            if (manualToggle?.checked) {
                payload.manual_calories = parseNumber(document.getElementById('gymQuickManualKcal')?.value, NaN);
                if (!Number.isFinite(payload.manual_calories) || payload.manual_calories < 0) {
                    showToast('Bitte einen gültigen manuellen Kalorienverbrauch angeben.', 'error', 8000);
                    document.getElementById('gymQuickManualKcal')?.focus();
                    return;
                }
            }
        } else {
            payload.reps = parseNumber(document.getElementById('gymQuickReps')?.value, 0);
            payload.weight = parseNumber(document.getElementById('gymQuickWeight')?.value, -1);

            if (!(payload.reps > 0)) {
                showToast('Bitte gültige Wiederholungen eingeben.', 'error', 8000);
                document.getElementById('gymQuickReps')?.focus();
                return;
            }
            if (!(payload.weight >= 0)) {
                showToast('Bitte ein gültiges Gewicht eingeben.', 'error', 8000);
                document.getElementById('gymQuickWeight')?.focus();
                return;
            }
        }

        const submitButton = form.querySelector('button[type="submit"]');
        const oldText = submitButton?.textContent || 'Übung eintragen';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Speichert …';
        }

        try {
            showToast('Speichere Einzelübung …', 'info', 0);
            const data = await apiPost('quick_entry_save', payload);
            state.logs = null;
            await renderExerciseDetail(exerciseId);
            showToast(`Übung eingetragen${Number(data.calories) > 0 ? ` · ${formatNumber(data.calories, 0)} kcal` : ''}.`, 'success');
        } finally {
            if (submitButton && document.body.contains(submitButton)) {
                submitButton.disabled = false;
                submitButton.textContent = oldText;
            }
        }
    }

    async function resumeActive() {
        const active = state.bootstrap?.active_session;
        if (!active) return;
        state.history = [];
        await navigate({ name: 'session', sessionId: active.id }, { noHistory: true });
    }

    async function saveSetForm(form) {
        const planExerciseId = Number(document.getElementById('gymSetPlanExerciseId')?.value || 0);
        const setNumber = Number(document.getElementById('gymSetNumber')?.value || 0);
        const exercise = findSessionExercise(planExerciseId);
        if (!exercise || !state.currentSession) return;

        const payload = {
            session_id: state.currentSession.id,
            plan_exercise_id: planExerciseId,
            set_number: setNumber,
        };

        if (exercise.type === 'kardio') {
            if (cardioMode(exercise) === 'strecke') {
                payload.cardio_distance_km = parseNumber(document.getElementById('gymSetCardioDistanceKm')?.value, 0);
            } else {
                payload.cardio_minutes = parseNumber(document.getElementById('gymSetCardioMinutes')?.value, 0);
            }

            const manualToggle = document.getElementById('gymSetManualKcalToggle');
            payload.calories_manual = manualToggle?.checked ? 1 : 0;
            if (manualToggle?.checked) {
                payload.manual_calories = parseNumber(document.getElementById('gymSetManualKcal')?.value, NaN);
                if (!Number.isFinite(payload.manual_calories) || payload.manual_calories < 0) {
                    showToast('Bitte einen gültigen manuellen Kalorienverbrauch angeben.', 'error', 8000);
                    document.getElementById('gymSetManualKcal')?.focus();
                    return;
                }
            }
        } else {
            payload.reps = parseNumber(document.getElementById('gymSetReps')?.value, 0);
            payload.weight = parseNumber(document.getElementById('gymSetWeight')?.value, 0);
        }

        const wasFinishedLog = Boolean(state.currentSession.is_finished);
        const data = await apiPost('save_set', payload);
        state.currentSession = data.session;
        if (wasFinishedLog) state.logs = null;
        state.extraSetCounts.set(planExerciseId, 0);
        closeModal();
        await renderSessionExercise(planExerciseId);
        showToast(wasFinishedLog ? 'Log erfolgreich gespeichert.' : 'Satz erfolgreich gespeichert.', 'success');
    }

    async function deleteCurrentSet() {
        const planExerciseId = Number(document.getElementById('gymSetPlanExerciseId')?.value || 0);
        const setNumber = Number(document.getElementById('gymSetNumber')?.value || 0);
        if (!planExerciseId || !setNumber || !state.currentSession) return;

        const wasFinishedLog = Boolean(state.currentSession.is_finished);
        const data = await apiPost('set_delete', {
            session_id: state.currentSession.id,
            plan_exercise_id: planExerciseId,
            set_number: setNumber,
        });

        if (wasFinishedLog) state.logs = null;
        closeModal();

        if (data.session_deleted) {
            state.currentSession = null;
            state.currentSessionExerciseId = null;
            state.extraSetCounts.clear();
            await refreshBootstrap();
            state.history = [];
            await navigate({ name: 'overview', tab: 'logs' }, { noHistory: true });
            showToast('Training gelöscht, da keine Übungen mehr enthalten waren.', 'success');
            return;
        }

        state.currentSession = data.session;
        const stillExists = findSessionExercise(planExerciseId);
        if (stillExists) {
            await renderSessionExercise(planExerciseId);
        } else {
            await navigate({ name: 'session', sessionId: state.currentSession.id }, { noHistory: true });
        }
        showToast(wasFinishedLog ? 'Log-Eintrag gelöscht.' : 'Satz gelöscht.', 'success');
    }

    async function deleteLogExercise() {
        if (!state.currentSession?.is_finished || !state.currentSessionExerciseId) return;
        if (!confirm('Diese Übung vollständig aus dem Trainingslog löschen?')) return;

        const data = await apiPost('session_exercise_delete', {
            session_id: state.currentSession.id,
            plan_exercise_id: state.currentSessionExerciseId,
        });

        state.logs = null;

        if (data.session_deleted) {
            state.currentSession = null;
            state.currentSessionExerciseId = null;
            state.extraSetCounts.clear();
            await refreshBootstrap();
            state.history = [];
            await navigate({ name: 'overview', tab: 'logs' }, { noHistory: true });
            showToast('Training gelöscht, da keine Übungen mehr enthalten waren.', 'success');
            return;
        }

        state.currentSession = data.session;
        state.currentSessionExerciseId = null;
        state.history = [];
        await navigate({ name: 'session', sessionId: data.session.id, tab: 'logs' }, { noHistory: true });
        showToast('Übung aus dem Log gelöscht.', 'success');
    }

    async function finishSession() {
        if (!state.currentSession) return;
        const wasQuick = Boolean(state.currentSession.is_quick);
        if (!confirm(wasQuick ? 'Einzelübung jetzt abschließen?' : 'Training jetzt abschließen?')) return;

        const data = await apiPost('finish_session', { session_id: state.currentSession.id });
        state.logs = null;
        await refreshBootstrap();

        if (data.session_deleted) {
            state.currentSession = null;
            state.currentSessionExerciseId = null;
            state.extraSetCounts.clear();
            state.history = [];
            await navigate({ name: 'overview', tab: wasQuick ? 'exercises' : 'plans' }, { noHistory: true });
            showToast('Leeres Training verworfen.', 'success');
            return;
        }

        state.currentSession = data.session;
        showToast(state.currentSession.is_quick ? 'Einzelübung gespeichert.' : 'Training erfolgreich abgeschlossen.', 'success');
        state.history = [];
        await navigate({ name: 'session', sessionId: data.session.id, tab: 'logs' }, { noHistory: true });
    }

    async function cancelSession() {
        if (!state.currentSession) return;
        const wasQuick = Boolean(state.currentSession.is_quick);
        if (!confirm(wasQuick
            ? 'Einzelübung wirklich abbrechen? Alle erfassten Sätze werden gelöscht.'
            : 'Training wirklich abbrechen? Alle heute erfassten Sätze dieses Trainings werden gelöscht.')) return;

        await apiPost('cancel_session', { session_id: state.currentSession.id });
        state.currentSession = null;
        state.currentSessionExerciseId = null;
        state.extraSetCounts.clear();
        await refreshBootstrap();
        state.history = [];
        await navigate({ name: 'overview', tab: wasQuick ? 'exercises' : 'plans' }, { noHistory: true });
        showToast(wasQuick ? 'Einzelübung abgebrochen.' : 'Training abgebrochen.', 'success');
    }

    async function deletePlan(planId) {
        if (!confirm('Trainingsplan wirklich löschen? Alte Trainingsdaten bleiben erhalten.')) return;
        await apiPost('plan_delete', { id: planId });
        await refreshBootstrap();
        state.history = [];
        await navigate({ name: 'overview', tab: 'plans' }, { noHistory: true });
        showToast('Trainingsplan gelöscht.', 'success');
    }

    async function deleteExercise(exerciseId) {
        if (!confirm('Übung wirklich löschen? Alte Trainingsdaten und das Bild bleiben für die Historie erhalten.')) return;
        await apiPost('exercise_delete', { id: exerciseId });
        await refreshBootstrap();
        state.history = [];
        await navigate({ name: 'overview', tab: 'exercises' }, { noHistory: true });
        showToast('Übung gelöscht.', 'success');
    }

    tabs.addEventListener('click', async event => {
        const button = event.target.closest('.gym-tab');
        if (!button) return;
        await navigate({ name: 'overview', tab: button.dataset.tab }, { noHistory: true });
    });

    backButton.addEventListener('click', goBack);
    addExerciseButton.addEventListener('click', () => openExerciseModal());
    addPlanButton.addEventListener('click', () => openPlanModal());
    modalClose.addEventListener('click', closeModal);

    modal.addEventListener('click', event => {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeModal();
        }
    });

    activeSessionBanner.addEventListener('click', event => {
        if (event.target.closest('[data-action="resume-active"]')) resumeActive();
    });

    modalBody.addEventListener('change', event => {
        if (event.target.id === 'gymPlanExerciseSelect') {
            const exerciseId = Number(event.target.value || 0);
            if (exerciseId > 0) addExerciseToPlanDraft(exerciseId);
            return;
        }

        if (['gymSetCardioMinutes', 'gymSetCardioDistanceKm', 'gymSetManualKcalToggle'].includes(event.target.id)) {
            const exercise = findSessionExercise(Number(document.getElementById('gymSetPlanExerciseId')?.value || 0));
            if (exercise) {
                if (event.target.id === 'gymSetManualKcalToggle') updateCardioManualUi(exercise);
                updateCardioPreview(exercise);
            }
        }
    });

    modalBody.addEventListener('input', event => {
        if (['gymSetCardioMinutes', 'gymSetCardioDistanceKm', 'gymSetManualKcal'].includes(event.target.id)) {
            const exercise = findSessionExercise(Number(document.getElementById('gymSetPlanExerciseId')?.value || 0));
            if (exercise) updateCardioPreview(exercise);
        }
    });

    modalBody.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.target;
        try {
            if (form.id === 'gymExerciseForm') {
                // Fallback für Enter/virtuelle Tastaturen. Der sichtbare Button besitzt zusätzlich
                // einen direkten Click-Handler, weil genau dieser Submit-Pfad im Browser ausgefallen ist.
                showToast('Speichere Übung …', 'info', 0);
                await saveExerciseForm(form);
            } else if (form.id === 'gymPlanForm') {
                await savePlanForm(form);
            } else if (form.id === 'gymSetForm') {
                await saveSetForm(form);
            }
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Speichern fehlgeschlagen.', true);
        }
    });

    modalBody.addEventListener('click', async event => {
        const actionEl = event.target.closest('[data-action]');
        if (!actionEl) return;
        const action = actionEl.dataset.action;

        try {
            if (action === 'close-modal') {
                closeModal();
                return;
            }

            if (action === 'save-exercise') {
                // Direkt in openExerciseModal gebunden; hier nicht ein zweites Mal ausführen.
                return;
            }

            if (action === 'delete-set') {
                await deleteCurrentSet();
                return;
            }

            if (!state.planDraft) return;

            if (action === 'plan-remove-exercise') {
                syncPlanDraftFromDom();
                const index = Number(actionEl.dataset.index);
                state.planDraft.exercises.splice(index, 1);
                renderPlanBuilderRows();
                refreshPlanExerciseSelect();
                return;
            }

            if (action === 'plan-move-up' || action === 'plan-move-down') {
                syncPlanDraftFromDom();
                const index = Number(actionEl.dataset.index);
                const target = action === 'plan-move-up' ? index - 1 : index + 1;
                if (target < 0 || target >= state.planDraft.exercises.length) return;
                const [item] = state.planDraft.exercises.splice(index, 1);
                state.planDraft.exercises.splice(target, 0, item);
                renderPlanBuilderRows();
                return;
            }
        } catch (error) {
            showToast(error.message || 'Aktion fehlgeschlagen.', true);
        }
    });

    modalBody.addEventListener('dragstart', event => {
        const handle = event.target.closest('.gym-drag-handle');
        if (!handle || !state.planDraft) {
            event.preventDefault();
            return;
        }

        const row = handle.closest('.gym-plan-builder-row');
        if (!row) {
            event.preventDefault();
            return;
        }

        syncPlanDraftFromDom();
        state.planDragIndex = Number(row.dataset.index);
        row.classList.add('is-dragging');

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(state.planDragIndex));
        }
    });

    modalBody.addEventListener('dragover', event => {
        const row = event.target.closest('.gym-plan-builder-row');
        if (!row || state.planDragIndex === null) return;
        event.preventDefault();
        row.classList.add('is-drag-over');
    });

    modalBody.addEventListener('dragleave', event => {
        event.target.closest('.gym-plan-builder-row')?.classList.remove('is-drag-over');
    });

    modalBody.addEventListener('drop', event => {
        const row = event.target.closest('.gym-plan-builder-row');
        if (!row || state.planDragIndex === null || !state.planDraft) return;
        event.preventDefault();
        const targetIndex = Number(row.dataset.index);
        const sourceIndex = state.planDragIndex;
        if (sourceIndex !== targetIndex) {
            const [item] = state.planDraft.exercises.splice(sourceIndex, 1);
            state.planDraft.exercises.splice(targetIndex, 0, item);
        }
        state.planDragIndex = null;
        renderPlanBuilderRows();
    });

    modalBody.addEventListener('dragend', () => {
        state.planDragIndex = null;
        modalBody.querySelectorAll('.gym-plan-builder-row').forEach(row => {
            row.classList.remove('is-dragging', 'is-drag-over');
        });
    });

    view.addEventListener('change', event => {
        if (!state.currentExercise || state.route?.name !== 'exercise') return;
        if (['gymQuickCardioMinutes', 'gymQuickCardioDistanceKm', 'gymQuickManualKcalToggle'].includes(event.target.id)) {
            if (event.target.id === 'gymQuickManualKcalToggle') {
                updateQuickCardioManualUi(state.currentExercise);
            }
            updateQuickCardioPreview(state.currentExercise);
        }
    });

    view.addEventListener('input', event => {
        if (!state.currentExercise || state.route?.name !== 'exercise') return;
        if (['gymQuickCardioMinutes', 'gymQuickCardioDistanceKm', 'gymQuickManualKcal'].includes(event.target.id)) {
            updateQuickCardioPreview(state.currentExercise);
        }
    });

    view.addEventListener('submit', async event => {
        const form = event.target.closest('#gymQuickEntryForm');
        if (!form) return;
        event.preventDefault();

        try {
            await saveQuickEntryForm(form);
        } catch (error) {
            console.error('Gym quick entry failed:', error);
            showToast(error.message || 'Einzelübung konnte nicht gespeichert werden.', 'error', 12000);
        }
    });

    view.addEventListener('click', async event => {
        const planCard = event.target.closest('[data-plan-id]');
        const logCard = event.target.closest('[data-log-session-id]');
        const exerciseCard = event.target.closest('[data-exercise-id]');
        const sessionExerciseCard = event.target.closest('[data-session-exercise-id]');
        const setCard = event.target.closest('[data-set-number]');
        const actionEl = event.target.closest('[data-action]');

        try {
            if (actionEl) {
                const action = actionEl.dataset.action;

                if (action === 'new-exercise') return openExerciseModal();
                if (action === 'new-plan') return openPlanModal();
                if (action === 'retry') {
                    state.bootstrap = null;
                    return renderRoute();
                }
                if (action === 'resume-active') return resumeActive();
                if (action === 'start-plan') return startPlan(Number(actionEl.dataset.planId));
                if (action === 'edit-plan') {
                    const planId = Number(actionEl.dataset.planId);
                    const data = await apiGet('plan_detail', { id: planId });
                    return openPlanModal(data.plan);
                }
                if (action === 'delete-plan') return deletePlan(Number(actionEl.dataset.planId));
                if (action === 'edit-exercise') {
                    const exerciseId = Number(actionEl.dataset.exerciseId);
                    const data = await apiGet('exercise_detail', { id: exerciseId });
                    return openExerciseModal(data.exercise);
                }
                if (action === 'delete-exercise') return deleteExercise(Number(actionEl.dataset.exerciseId));
                if (action === 'add-set') {
                    const id = Number(state.currentSessionExerciseId);
                    const current = state.extraSetCounts.get(id) || 0;
                    state.extraSetCounts.set(id, current + 1);
                    return renderSessionExercise(id);
                }
                if (action === 'back-to-session') {
                    return navigate({ name: 'session', sessionId: state.currentSession.id });
                }
                if (action === 'finish-session') return finishSession();
                if (action === 'delete-log-exercise') return deleteLogExercise();
                if (action === 'cancel-session') return cancelSession();
                if (action === 'session-to-logs') {
                    state.history = [];
                    return navigate({ name: 'overview', tab: 'logs' }, { noHistory: true });
                }
            }

            if (setCard && state.currentSessionExerciseId) {
                const exercise = findSessionExercise(state.currentSessionExerciseId);
                if (exercise) openSetModal(exercise, Number(setCard.dataset.setNumber));
                return;
            }

            if (sessionExerciseCard && state.currentSession) {
                const planExerciseId = Number(sessionExerciseCard.dataset.sessionExerciseId);
                return navigate({
                    name: 'session_exercise',
                    sessionId: state.currentSession.id,
                    planExerciseId
                });
            }

            if (logCard && state.route.name === 'overview') {
                return navigate({
                    name: 'session',
                    sessionId: Number(logCard.dataset.logSessionId),
                    tab: 'logs'
                });
            }

            if (exerciseCard && state.route.name === 'overview') {
                return navigate({ name: 'exercise', id: Number(exerciseCard.dataset.exerciseId), tab: 'exercises' });
            }

            if (planCard) {
                return navigate({ name: 'plan', id: Number(planCard.dataset.planId), tab: 'plans' });
            }
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Aktion fehlgeschlagen.', true);
        }
    });

    renderRoute();
})();
</script>

</body>
</html>
