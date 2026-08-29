<?php

// sci/Kartei_v5.php
// Unterstützt klassische Single-/Multiple-Choice-Karten und den neuen Recall/Reveal-Modus.
// Strömungsmechanik wird mit question_type = 'recall' betrieben.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$sciconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const KARTEI_EMPTY_TOPIC = '__KARTEI_EMPTY_TOPIC__';
const KARTEI_IMAGE_DIR = __DIR__ . '/img/kartei';

if (empty($_SESSION['kartei_csrf'])) {
    $_SESSION['kartei_csrf'] = bin2hex(random_bytes(24));
}
$karteiCsrf = (string)$_SESSION['kartei_csrf'];

function karteiJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function karteiNormalizeMathMarkup(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Rohe < / > innerhalb von MathJax dürfen nicht zuerst von innerHTML als HTML interpretiert werden.
    $text = preg_replace_callback(
        '/\\\\\((.*?)\\\\\)|\\\\\[(.*?)\\\\\]/s',
        static function (array $matches): string {
            return str_replace(
                ['<=', '>=', '<', '>'],
                ['\\le ', '\\ge ', '\\lt ', '\\gt '],
                $matches[0]
            );
        },
        $text
    ) ?? $text;

    return $text;
}

function karteiRepairBrokenJsonBackslashes(string $json): string
{
    $out = '';
    $length = strlen($json);
    $validEscapes = '"\\/bfnrtu';

    for ($i = 0; $i < $length; $i++) {
        $char = $json[$i];

        if ($char !== '\\') {
            $out .= $char;
            continue;
        }

        $next = ($i + 1 < $length) ? $json[$i + 1] : '';
        if ($next !== '' && strpos($validEscapes, $next) !== false) {
            $out .= '\\' . $next;
            $i++;
            continue;
        }

        $out .= '\\\\';
    }

    return $out;
}

function karteiDecodeJsonArray(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $decoded = json_decode(karteiRepairBrokenJsonBackslashes($json), true);
    return is_array($decoded) ? $decoded : [];
}

function karteiNormalizeSelection(array $values): array
{
    $result = [];
    foreach ($values as $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }

        $key = trim((string)$value);
        if ($key !== '') {
            $result[] = $key;
        }
    }

    $result = array_values(array_unique($result, SORT_STRING));
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function karteiNormalizeTopicValue(?string $topic): string
{
    $topic = trim((string)$topic);
    return $topic === '' ? KARTEI_EMPTY_TOPIC : $topic;
}

function karteiTopicLabel(string $topic): string
{
    return $topic === KARTEI_EMPTY_TOPIC ? 'Ohne Topic' : $topic;
}

function karteiTopicMatches(?string $dbTopic, string $selectedTopic): bool
{
    return karteiNormalizeTopicValue($dbTopic) === $selectedTopic;
}

function karteiLoadAvailableExams(mysqli $conn): array
{
    $sql = "
        SELECT TRIM(exam) AS exam
        FROM kartei_fragen
        WHERE is_active = 1
          AND TRIM(exam) <> ''
        GROUP BY TRIM(exam)
        ORDER BY MIN(sort_order), TRIM(exam)
    ";

    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException('Klausuren konnten nicht geladen werden: ' . $conn->error);
    }

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = (string)$row['exam'];
    }
    $res->free();

    return $out;
}

function karteiLoadAvailableTopics(mysqli $conn, string $exam): array
{
    if ($exam === '') {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            CASE
                WHEN topic IS NULL OR TRIM(topic) = '' THEN ?
                ELSE TRIM(topic)
            END AS topic_value,
            MIN(sort_order) AS first_sort
        FROM kartei_fragen
        WHERE is_active = 1
          AND TRIM(exam) = ?
        GROUP BY topic_value
        ORDER BY first_sort, topic_value
    ");

    if (!$stmt) {
        throw new RuntimeException('Topics konnten nicht vorbereitet werden: ' . $conn->error);
    }

    $empty = KARTEI_EMPTY_TOPIC;
    $stmt->bind_param('ss', $empty, $exam);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = (string)$row['topic_value'];
    }
    $stmt->close();

    return $out;
}

function karteiQuestionQueryBase(): string
{
    return "
        SELECT
            q.id,
            q.question_type,
            q.question_html,
            q.answer_html,
            q.image_path,
            q.options_json,
            q.correct_answers_json,
            q.exam,
            q.topic,
            q.source_label,
            q.sort_order,
            ls.ease_factor,
            ls.interval_minutes,
            ls.repetitions,
            ls.lapses,
            ls.last_rating,
            ls.last_reviewed_at,
            ls.next_due_at
        FROM kartei_fragen q
        LEFT JOIN kartei_lernstand ls
            ON ls.question_id = q.id
    ";
}

function karteiBuildStats(mysqli $conn, string $exam, string $topic): array
{
    if ($exam === '' || $topic === '') {
        return [
            'total' => 0,
            'due_now' => 0,
            'new_count' => 0,
            'review_due' => 0,
            'reviewed_today' => 0,
            'next_due_at' => null,
        ];
    }

    $base = "
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN ls.question_id IS NULL THEN 1 ELSE 0 END) AS new_count,
            SUM(
                CASE
                    WHEN ls.question_id IS NOT NULL
                     AND (ls.next_due_at IS NULL OR ls.next_due_at <= NOW())
                    THEN 1 ELSE 0
                END
            ) AS review_due,
            SUM(
                CASE
                    WHEN ls.last_reviewed_at IS NOT NULL
                     AND DATE(ls.last_reviewed_at) = CURDATE()
                    THEN 1 ELSE 0
                END
            ) AS reviewed_today,
            MIN(
                CASE
                    WHEN ls.next_due_at > NOW() THEN ls.next_due_at
                    ELSE NULL
                END
            ) AS next_due_at
        FROM kartei_fragen q
        LEFT JOIN kartei_lernstand ls
            ON ls.question_id = q.id
        WHERE q.is_active = 1
          AND TRIM(q.exam) = ?
    ";

    if ($topic === KARTEI_EMPTY_TOPIC) {
        $sql = $base . " AND (q.topic IS NULL OR TRIM(q.topic) = '')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Statistik konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('s', $exam);
    } else {
        $sql = $base . " AND TRIM(COALESCE(q.topic, '')) = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Statistik konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('ss', $exam, $topic);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $newCount = (int)($row['new_count'] ?? 0);
    $reviewDue = (int)($row['review_due'] ?? 0);

    return [
        'total' => (int)($row['total_count'] ?? 0),
        'due_now' => $newCount + $reviewDue,
        'new_count' => $newCount,
        'review_due' => $reviewDue,
        'reviewed_today' => (int)($row['reviewed_today'] ?? 0),
        'next_due_at' => $row['next_due_at'] !== null ? (string)$row['next_due_at'] : null,
    ];
}

function karteiLoadNextDueQuestion(mysqli $conn, string $exam, string $topic): ?array
{
    if ($exam === '' || $topic === '') {
        return null;
    }

    $base = karteiQuestionQueryBase() . "
        WHERE q.is_active = 1
          AND TRIM(q.exam) = ?
          AND (
                ls.question_id IS NULL
                OR ls.next_due_at IS NULL
                OR ls.next_due_at <= NOW()
          )
    ";

    $order = "
        ORDER BY
            CASE WHEN ls.question_id IS NULL THEN 1 ELSE 0 END ASC,
            COALESCE(ls.next_due_at, '9999-12-31 23:59:59') ASC,
            q.sort_order ASC,
            q.id ASC
        LIMIT 1
    ";

    if ($topic === KARTEI_EMPTY_TOPIC) {
        $sql = $base . " AND (q.topic IS NULL OR TRIM(q.topic) = '') " . $order;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('s', $exam);
    } else {
        $sql = $base . " AND TRIM(COALESCE(q.topic, '')) = ? " . $order;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('ss', $exam, $topic);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function karteiLoadQuestionById(mysqli $conn, int $questionId): ?array
{
    $stmt = $conn->prepare(karteiQuestionQueryBase() . " WHERE q.id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $conn->error);
    }

    $stmt->bind_param('i', $questionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function karteiFormatQuestionForClient(array $row): array
{
    $options = [];
    foreach (karteiDecodeJsonArray((string)($row['options_json'] ?? '[]')) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $key = trim((string)($option['key'] ?? ''));
        if ($key === '') {
            continue;
        }

        $options[] = [
            'key' => $key,
            'html' => karteiNormalizeMathMarkup((string)($option['html'] ?? '')),
        ];
    }

    return [
        'id' => (int)$row['id'],
        'question_type' => (string)$row['question_type'],
        'question_html' => karteiNormalizeMathMarkup((string)$row['question_html']),
        'answer_html' => karteiNormalizeMathMarkup((string)($row['answer_html'] ?? '')),
        'image_path' => trim((string)($row['image_path'] ?? '')),
        'source_label' => trim((string)($row['source_label'] ?? '')),
        'options' => $options,
        'learning' => [
            'ease_factor' => $row['ease_factor'] !== null ? (float)$row['ease_factor'] : 2.30,
            'interval_minutes' => (int)($row['interval_minutes'] ?? 0),
            'repetitions' => (int)($row['repetitions'] ?? 0),
            'lapses' => (int)($row['lapses'] ?? 0),
            'last_rating' => $row['last_rating'] !== null ? (int)$row['last_rating'] : null,
            'last_reviewed_at' => $row['last_reviewed_at'] !== null ? (string)$row['last_reviewed_at'] : null,
            'next_due_at' => $row['next_due_at'] !== null ? (string)$row['next_due_at'] : null,
        ],
    ];
}

function karteiSnapshot(mysqli $conn, string $exam, string $topic): array
{
    $stats = karteiBuildStats($conn, $exam, $topic);
    $question = karteiLoadNextDueQuestion($conn, $exam, $topic);

    return [
        'question' => $question ? karteiFormatQuestionForClient($question) : null,
        'stats' => $stats,
    ];
}

function karteiVerifyQuestionSelection(array $question, string $exam, string $topic): void
{
    if ((int)($question['id'] ?? 0) <= 0 || (int)($question['is_active'] ?? 1) === 0) {
        throw new RuntimeException('Frage ist nicht aktiv.');
    }

    if (trim((string)$question['exam']) !== $exam) {
        throw new RuntimeException('Frage gehört nicht zur ausgewählten Klausur.');
    }

    if (!karteiTopicMatches($question['topic'] ?? null, $topic)) {
        throw new RuntimeException('Frage gehört nicht zum ausgewählten Topic.');
    }
}

function karteiApplySchedule(mysqli $conn, int $questionId, int $rating): array
{
    if ($rating < 0 || $rating > 3) {
        throw new InvalidArgumentException('Ungültige Bewertung.');
    }

    $stmt = $conn->prepare("
        SELECT ease_factor, interval_minutes, repetitions, lapses
        FROM kartei_lernstand
        WHERE question_id = ?
        FOR UPDATE
    ");
    if (!$stmt) {
        throw new RuntimeException('Lernstand konnte nicht vorbereitet werden: ' . $conn->error);
    }

    $stmt->bind_param('i', $questionId);
    $stmt->execute();
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ease = $state ? (float)$state['ease_factor'] : 2.30;
    $oldInterval = $state ? (int)$state['interval_minutes'] : 0;
    $repetitions = $state ? (int)$state['repetitions'] : 0;
    $lapses = $state ? (int)$state['lapses'] : 0;

    switch ($rating) {
        case 0: // Nicht gewusst
            $interval = 10;
            $repetitions = 0;
            $lapses++;
            $ease = max(1.30, $ease - 0.20);
            break;

        case 1: // Unsicher / teilweise
            $interval = $oldInterval > 0
                ? max(720, min(2880, (int)round($oldInterval * 1.20)))
                : 720;
            $repetitions = max(0, $repetitions - 1);
            $ease = max(1.30, $ease - 0.10);
            break;

        case 2: // Gewusst
            if ($repetitions <= 0) {
                $interval = 1440;       // 1 Tag
            } elseif ($repetitions === 1) {
                $interval = 4320;       // 3 Tage
            } else {
                $interval = max(1440, (int)round(max(1, $oldInterval) * $ease));
            }
            $repetitions++;
            break;

        case 3: // Sicher gewusst
            if ($repetitions <= 0) {
                $interval = 4320;       // 3 Tage
            } elseif ($repetitions === 1) {
                $interval = 10080;      // 7 Tage
            } else {
                $interval = max(4320, (int)round(max(1, $oldInterval) * ($ease + 0.80)));
            }
            $repetitions++;
            $ease = min(3.20, $ease + 0.15);
            break;
    }

    $nextDue = (new DateTimeImmutable('now'))
        ->modify('+' . $interval . ' minutes')
        ->format('Y-m-d H:i:s');

    $upsert = $conn->prepare("
        INSERT INTO kartei_lernstand (
            question_id,
            ease_factor,
            interval_minutes,
            repetitions,
            lapses,
            last_rating,
            last_reviewed_at,
            next_due_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE
            ease_factor = VALUES(ease_factor),
            interval_minutes = VALUES(interval_minutes),
            repetitions = VALUES(repetitions),
            lapses = VALUES(lapses),
            last_rating = VALUES(last_rating),
            last_reviewed_at = NOW(),
            next_due_at = VALUES(next_due_at)
    ");

    if (!$upsert) {
        throw new RuntimeException('Lernstand konnte nicht gespeichert werden: ' . $conn->error);
    }

    $upsert->bind_param(
        'idiiiis',
        $questionId,
        $ease,
        $interval,
        $repetitions,
        $lapses,
        $rating,
        $nextDue
    );
    $upsert->execute();
    $upsert->close();

    return [
        'rating' => $rating,
        'interval_minutes' => $interval,
        'next_due_at' => $nextDue,
        'ease_factor' => $ease,
        'repetitions' => $repetitions,
        'lapses' => $lapses,
    ];
}

function karteiInsertReview(
    mysqli $conn,
    int $questionId,
    array $selectedAnswers,
    int $isCorrect,
    int $rating,
    int $confidence,
    int $responseTimeMs
): void {
    $selectedJson = json_encode(
        karteiNormalizeSelection($selectedAnswers),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $stmt = $conn->prepare("
        INSERT INTO kartei_antworten (
            question_id,
            selected_answers_json,
            is_correct,
            review_rating,
            confidence,
            response_time_ms,
            answered_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        throw new RuntimeException('Antwort konnte nicht vorbereitet werden: ' . $conn->error);
    }

    $stmt->bind_param(
        'isiiii',
        $questionId,
        $selectedJson,
        $isCorrect,
        $rating,
        $confidence,
        $responseTimeMs
    );
    $stmt->execute();
    $stmt->close();
}

function karteiServeProtectedImage(): void
{
    $relative = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));

    if (
        $relative === ''
        || str_contains($relative, '..')
        || preg_match('#^[A-Za-z0-9._/-]+$#', $relative) !== 1
    ) {
        http_response_code(400);
        exit('Ungültiger Bildpfad.');
    }

    $base = realpath(KARTEI_IMAGE_DIR);
    if ($base === false) {
        http_response_code(404);
        exit('Bildverzeichnis nicht gefunden.');
    }

    $path = realpath($base . DIRECTORY_SEPARATOR . $relative);
    $prefix = $base . DIRECTORY_SEPARATOR;

    if (
        $path === false
        || !is_file($path)
        || !str_starts_with($path, $prefix)
    ) {
        http_response_code(404);
        exit('Bild nicht gefunden.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($path);
    $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    if (!in_array($mime, $allowed, true)) {
        http_response_code(415);
        exit('Nicht unterstützter Bildtyp.');
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (($_GET['action'] ?? '') === 'image') {
    karteiServeProtectedImage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (!hash_equals((string)$_SESSION['kartei_csrf'], $csrf)) {
            karteiJsonResponse(['ok' => false, 'message' => 'Ungültiges CSRF-Token.'], 403);
        }

        $action = trim((string)($_POST['action'] ?? ''));
        $exam = trim((string)($_POST['exam'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? ''));

        $availableExams = karteiLoadAvailableExams($sciconn);
        if ($exam === '' || !in_array($exam, $availableExams, true)) {
            $exam = $availableExams[0] ?? '';
        }

        $availableTopics = karteiLoadAvailableTopics($sciconn, $exam);
        if ($topic === '' || !in_array($topic, $availableTopics, true)) {
            $topic = $availableTopics[0] ?? '';
        }

        $clientTopics = array_map(
            static fn(string $value): array => [
                'value' => $value,
                'label' => karteiTopicLabel($value),
            ],
            $availableTopics
        );

        if ($action === 'load') {
            $snapshot = karteiSnapshot($sciconn, $exam, $topic);
            karteiJsonResponse([
                'ok' => true,
                'exam' => $exam,
                'topic' => $topic,
                'available_topics' => $clientTopics,
                'question' => $snapshot['question'],
                'stats' => $snapshot['stats'],
            ]);
        }

        if ($action === 'rate') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            $rating = (int)($_POST['rating'] ?? -1);
            $responseTimeMs = max(0, min(3600000, (int)($_POST['response_time_ms'] ?? 0)));

            if ($questionId <= 0 || $rating < 0 || $rating > 3) {
                karteiJsonResponse(['ok' => false, 'message' => 'Ungültige Bewertung.'], 400);
            }

            $sciconn->begin_transaction();
            try {
                $question = karteiLoadQuestionById($sciconn, $questionId);
                if (!$question) {
                    throw new RuntimeException('Frage nicht gefunden.');
                }

                karteiVerifyQuestionSelection($question, $exam, $topic);

                if ((string)$question['question_type'] !== 'recall') {
                    throw new RuntimeException('Diese Frage ist keine Recall-Karte.');
                }

                $isCorrect = $rating >= 2 ? 1 : 0;
                $confidence = $rating;

                karteiInsertReview(
                    $sciconn,
                    $questionId,
                    [],
                    $isCorrect,
                    $rating,
                    $confidence,
                    $responseTimeMs
                );

                $schedule = karteiApplySchedule($sciconn, $questionId, $rating);
                $sciconn->commit();
            } catch (Throwable $inner) {
                $sciconn->rollback();
                throw $inner;
            }

            $snapshot = karteiSnapshot($sciconn, $exam, $topic);
            karteiJsonResponse([
                'ok' => true,
                'schedule' => $schedule,
                'question' => $snapshot['question'],
                'stats' => $snapshot['stats'],
            ]);
        }

        if ($action === 'answer_choice') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            $responseTimeMs = max(0, min(3600000, (int)($_POST['response_time_ms'] ?? 0)));
            $selected = karteiDecodeJsonArray((string)($_POST['selected_answers_json'] ?? '[]'));
            $selected = karteiNormalizeSelection($selected);

            if ($questionId <= 0) {
                karteiJsonResponse(['ok' => false, 'message' => 'Ungültige Frage.'], 400);
            }

            $sciconn->begin_transaction();
            try {
                $question = karteiLoadQuestionById($sciconn, $questionId);
                if (!$question) {
                    throw new RuntimeException('Frage nicht gefunden.');
                }

                karteiVerifyQuestionSelection($question, $exam, $topic);

                if (!in_array((string)$question['question_type'], ['single', 'multiple'], true)) {
                    throw new RuntimeException('Diese Frage ist keine Auswahlfrage.');
                }

                $correct = karteiNormalizeSelection(
                    karteiDecodeJsonArray((string)$question['correct_answers_json'])
                );

                $isCorrect = $selected === $correct ? 1 : 0;
                $rating = $isCorrect ? 2 : 0;
                $confidence = $isCorrect ? 2 : 0;

                karteiInsertReview(
                    $sciconn,
                    $questionId,
                    $selected,
                    $isCorrect,
                    $rating,
                    $confidence,
                    $responseTimeMs
                );

                $schedule = karteiApplySchedule($sciconn, $questionId, $rating);
                $sciconn->commit();
            } catch (Throwable $inner) {
                $sciconn->rollback();
                throw $inner;
            }

            $snapshot = karteiSnapshot($sciconn, $exam, $topic);
            karteiJsonResponse([
                'ok' => true,
                'is_correct' => (bool)$isCorrect,
                'correct_values' => $correct,
                'schedule' => $schedule,
                'question' => $snapshot['question'],
                'stats' => $snapshot['stats'],
            ]);
        }

        if ($action === 'delete') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            if ($questionId <= 0) {
                karteiJsonResponse(['ok' => false, 'message' => 'Ungültige Frage.'], 400);
            }

            $question = karteiLoadQuestionById($sciconn, $questionId);
            if (!$question) {
                karteiJsonResponse(['ok' => false, 'message' => 'Frage nicht gefunden.'], 404);
            }
            karteiVerifyQuestionSelection($question, $exam, $topic);

            $stmt = $sciconn->prepare("UPDATE kartei_fragen SET is_active = 0 WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Löschen konnte nicht vorbereitet werden: ' . $sciconn->error);
            }
            $stmt->bind_param('i', $questionId);
            $stmt->execute();
            $stmt->close();

            $snapshot = karteiSnapshot($sciconn, $exam, $topic);
            karteiJsonResponse([
                'ok' => true,
                'question' => $snapshot['question'],
                'stats' => $snapshot['stats'],
            ]);
        }

        karteiJsonResponse(['ok' => false, 'message' => 'Unbekannte Aktion.'], 400);
    } catch (Throwable $e) {
        karteiJsonResponse(['ok' => false, 'message' => 'Fehler: ' . $e->getMessage()], 500);
    }
}

$availableExams = karteiLoadAvailableExams($sciconn);
$initialExam = in_array('Strömungsmechanik', $availableExams, true)
    ? 'Strömungsmechanik'
    : ($availableExams[0] ?? '');

$availableTopics = karteiLoadAvailableTopics($sciconn, $initialExam);
$initialTopic = $availableTopics[0] ?? '';

$page_title = 'Karteikarten';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';

$cssFile = __DIR__ . '/Kartei_v5.css';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : (string)time();
?>

<link rel="stylesheet" href="Kartei_v5.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">

<div class="sci-review-shell">
    <div class="sci-review-topbar">
        <div class="sci-review-filters">
            <select id="examSelect" class="kategorie-select" aria-label="Klausur">
                <?php foreach ($availableExams as $exam): ?>
                    <option
                        value="<?= htmlspecialchars($exam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        <?= $exam === $initialExam ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($exam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="topicSelect" class="kategorie-select" aria-label="Thema">
                <?php foreach ($availableTopics as $topic): ?>
                    <option
                        value="<?= htmlspecialchars($topic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        <?= $topic === $initialTopic ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars(karteiTopicLabel($topic), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sci-review-stats" id="deckStats"></div>
    </div>

    <main class="sci-review-stage">
        <section class="sci-review-card" id="reviewCard">
            <header class="sci-review-card-head">
                <div>
                    <div class="sci-review-meta" id="cardMeta">Lade Karte …</div>
                    <div class="sci-review-learning-meta" id="learningMeta"></div>
                </div>

                <button type="button" class="sci-review-delete" id="deleteBtn">Löschen</button>
            </header>

            <div class="sci-review-question" id="questionBody">Lade Karte …</div>

            <div class="sci-review-image-wrap hidden" id="imageWrap">
                <img id="questionImage" class="sci-review-image" alt="Skizze zur Karte">
            </div>

            <div class="sci-review-choice-grid hidden" id="choiceGrid"></div>

            <div class="sci-review-actions" id="primaryActions">
                <button type="button" class="sci-review-primary" id="revealBtn">Antwort aufdecken</button>
                <button type="button" class="sci-review-primary hidden" id="submitChoiceBtn">Antwort absenden</button>
            </div>

            <section class="sci-review-answer hidden" id="answerSection">
                <div class="sci-review-answer-label">Antwort</div>
                <div class="sci-review-answer-body" id="answerBody"></div>
            </section>

            <section class="sci-review-rating hidden" id="ratingSection">
                <div class="sci-review-rating-title">Wie gut hattest du es?</div>
                <div class="sci-review-rating-grid">
                    <button type="button" class="sci-rating sci-rating-again" data-rating="0">
                        <span>Nicht gewusst</span>
                        <small>wieder sehr bald</small>
                    </button>
                    <button type="button" class="sci-rating sci-rating-hard" data-rating="1">
                        <span>Unsicher</span>
                        <small>kurzes Intervall</small>
                    </button>
                    <button type="button" class="sci-rating sci-rating-good" data-rating="2">
                        <span>Gewusst</span>
                        <small>normal</small>
                    </button>
                    <button type="button" class="sci-rating sci-rating-easy" data-rating="3">
                        <span>Sicher gewusst</span>
                        <small>deutlich später</small>
                    </button>
                </div>
                <div class="sci-review-shortcuts">Tastatur: 1–4 bewerten · Leertaste Antwort aufdecken</div>
            </section>

            <div class="sci-review-status hidden" id="statusMessage"></div>
        </section>

        <section class="sci-review-empty hidden" id="emptyState">
            <div class="sci-review-empty-title">Für dieses Thema ist aktuell alles erledigt.</div>
            <div id="emptyNextDue"></div>
        </section>
    </main>
</div>

<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)']],
        displayMath: [['\\[', '\\]']],
        processEscapes: true
    },
    svg: {
        fontCache: 'global'
    }
};
</script>
<script defer src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"></script>

<script>
(() => {
    const API_URL = window.location.pathname;
    const CSRF = <?= json_encode($karteiCsrf, JSON_UNESCAPED_SLASHES) ?>;

    const els = {
        exam: document.getElementById('examSelect'),
        topic: document.getElementById('topicSelect'),
        stats: document.getElementById('deckStats'),
        card: document.getElementById('reviewCard'),
        meta: document.getElementById('cardMeta'),
        learningMeta: document.getElementById('learningMeta'),
        question: document.getElementById('questionBody'),
        imageWrap: document.getElementById('imageWrap'),
        image: document.getElementById('questionImage'),
        choiceGrid: document.getElementById('choiceGrid'),
        reveal: document.getElementById('revealBtn'),
        submitChoice: document.getElementById('submitChoiceBtn'),
        answerSection: document.getElementById('answerSection'),
        answer: document.getElementById('answerBody'),
        ratingSection: document.getElementById('ratingSection'),
        ratingButtons: Array.from(document.querySelectorAll('[data-rating]')),
        deleteBtn: document.getElementById('deleteBtn'),
        status: document.getElementById('statusMessage'),
        empty: document.getElementById('emptyState'),
        emptyNextDue: document.getElementById('emptyNextDue')
    };

    const state = {
        exam: els.exam ? String(els.exam.value || '') : '',
        topic: els.topic ? String(els.topic.value || '') : '',
        question: null,
        selected: new Set(),
        revealed: false,
        locked: false,
        startedAt: performance.now()
    };

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    async function post(payload) {
        const body = new URLSearchParams();
        body.set('csrf', CSRF);

        Object.entries(payload).forEach(([key, value]) => {
            body.set(key, value == null ? '' : String(value));
        });

        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body,
            credentials: 'same-origin',
            cache: 'no-store'
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Unbekannter Fehler.');
        }

        return data;
    }

    async function typeset() {
        if (!window.MathJax || !window.MathJax.typesetPromise) {
            return;
        }

        try {
            await window.MathJax.typesetPromise([els.card]);
        } catch (error) {
            console.error(error);
        }
    }

    function formatInterval(minutes) {
        minutes = Number(minutes || 0);
        if (minutes <= 0) return 'neu';
        if (minutes < 60) return `${minutes} min`;
        if (minutes < 1440) return `${Math.round(minutes / 60)} h`;
        const days = minutes / 1440;
        if (days < 14) return `${Math.round(days * 10) / 10} d`;
        return `${Math.round(days / 7 * 10) / 10} Wo`;
    }

    function formatDateTime(mysqlDate) {
        if (!mysqlDate) return '';
        const normalized = String(mysqlDate).replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return String(mysqlDate);

        return new Intl.DateTimeFormat('de-DE', {
            weekday: 'short',
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }

    function renderStats(stats) {
        const s = stats || {};
        els.stats.innerHTML = [
            ['Fällig', s.due_now ?? 0],
            ['Neu', s.new_count ?? 0],
            ['Heute', s.reviewed_today ?? 0],
            ['Gesamt', s.total ?? 0]
        ].map(([label, value]) =>
            `<div class="sci-review-stat"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`
        ).join('');
    }

    function setTopics(items, selectedValue) {
        const current = String(selectedValue || '');
        els.topic.innerHTML = '';

        (items || []).forEach(item => {
            const option = document.createElement('option');
            option.value = String(item.value || '');
            option.textContent = String(item.label || item.value || '');
            if (option.value === current) {
                option.selected = true;
            }
            els.topic.appendChild(option);
        });

        els.topic.disabled = els.topic.options.length === 0;
        state.topic = String(els.topic.value || '');
    }

    function clearChoiceState() {
        state.selected = new Set();
        els.choiceGrid.innerHTML = '';
    }

    function renderChoices(question) {
        clearChoiceState();

        const multiple = question.question_type === 'multiple';
        els.choiceGrid.classList.remove('hidden');

        question.options.forEach(option => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sci-review-choice';
            button.dataset.key = option.key;
            button.innerHTML = `
                <span class="sci-review-choice-key">${escapeHtml(option.key)}</span>
                <span class="sci-review-choice-content">${option.html}</span>
            `;

            button.addEventListener('click', () => {
                if (state.locked) return;

                if (multiple) {
                    if (state.selected.has(option.key)) {
                        state.selected.delete(option.key);
                        button.classList.remove('is-selected');
                    } else {
                        state.selected.add(option.key);
                        button.classList.add('is-selected');
                    }
                } else {
                    state.selected = new Set([option.key]);
                    els.choiceGrid.querySelectorAll('.sci-review-choice').forEach(el => {
                        el.classList.toggle('is-selected', el.dataset.key === option.key);
                    });
                }
            });

            els.choiceGrid.appendChild(button);
        });
    }

    function renderQuestion(question, stats) {
        renderStats(stats);
        state.question = question;
        state.revealed = false;
        state.locked = false;
        state.startedAt = performance.now();

        els.status.classList.add('hidden');
        els.answerSection.classList.add('hidden');
        els.ratingSection.classList.add('hidden');
        els.reveal.classList.add('hidden');
        els.submitChoice.classList.add('hidden');
        els.choiceGrid.classList.add('hidden');
        clearChoiceState();

        if (!question) {
            els.card.classList.add('hidden');
            els.empty.classList.remove('hidden');

            if (stats && stats.next_due_at) {
                els.emptyNextDue.textContent = `Nächste fällige Karte: ${formatDateTime(stats.next_due_at)}`;
            } else {
                els.emptyNextDue.textContent = 'Es gibt momentan keine weiteren aktiven Karten.';
            }
            return;
        }

        els.empty.classList.add('hidden');
        els.card.classList.remove('hidden');

        const typeLabel = question.question_type === 'recall'
            ? 'Recall'
            : (question.question_type === 'multiple' ? 'Multiple Choice' : 'Single Choice');

        const source = question.source_label ? ` · ${question.source_label}` : '';
        els.meta.textContent = `${typeLabel}${source}`;

        const l = question.learning || {};
        els.learningMeta.textContent =
            `Intervall: ${formatInterval(l.interval_minutes)} · Wiederholungen: ${l.repetitions || 0} · Fehler: ${l.lapses || 0}`;

        els.question.innerHTML = question.question_html || '';
        els.answer.innerHTML = question.answer_html || '';

        if (question.image_path) {
            els.image.src = `${API_URL}?action=image&file=${encodeURIComponent(question.image_path)}`;
            els.imageWrap.classList.remove('hidden');
        } else {
            els.image.removeAttribute('src');
            els.imageWrap.classList.add('hidden');
        }

        if (question.question_type === 'recall') {
            els.reveal.classList.remove('hidden');
        } else {
            renderChoices(question);
            els.submitChoice.classList.remove('hidden');
        }

        typeset();
    }

    function showStatus(message, kind = '') {
        els.status.textContent = message;
        els.status.className = 'sci-review-status';
        if (kind) els.status.classList.add(`is-${kind}`);
    }

    async function loadDeck() {
        state.exam = String(els.exam.value || '');
        state.topic = String(els.topic.value || '');

        try {
            const data = await post({
                action: 'load',
                exam: state.exam,
                topic: state.topic
            });

            state.exam = data.exam || state.exam;
            state.topic = data.topic || state.topic;

            if (Array.isArray(data.available_topics)) {
                setTopics(data.available_topics, state.topic);
            }

            renderQuestion(data.question, data.stats);
        } catch (error) {
            showStatus(error.message, 'error');
        }
    }

    function revealAnswer() {
        if (!state.question || state.question.question_type !== 'recall' || state.revealed) {
            return;
        }

        state.revealed = true;
        els.reveal.classList.add('hidden');
        els.answerSection.classList.remove('hidden');
        els.ratingSection.classList.remove('hidden');
        typeset();
    }

    async function rateCurrent(rating) {
        if (!state.question || state.locked || !state.revealed) return;

        state.locked = true;
        els.ratingButtons.forEach(btn => btn.disabled = true);

        const responseTimeMs = Math.round(performance.now() - state.startedAt);

        try {
            const data = await post({
                action: 'rate',
                exam: state.exam,
                topic: state.topic,
                question_id: state.question.id,
                rating,
                response_time_ms: responseTimeMs
            });

            els.ratingButtons.forEach(btn => btn.disabled = false);
            renderQuestion(data.question, data.stats);
        } catch (error) {
            state.locked = false;
            els.ratingButtons.forEach(btn => btn.disabled = false);
            showStatus(error.message, 'error');
        }
    }

    async function submitChoice() {
        if (!state.question || state.locked || state.selected.size === 0) {
            return;
        }

        state.locked = true;
        els.submitChoice.disabled = true;

        try {
            const data = await post({
                action: 'answer_choice',
                exam: state.exam,
                topic: state.topic,
                question_id: state.question.id,
                selected_answers_json: JSON.stringify(Array.from(state.selected)),
                response_time_ms: Math.round(performance.now() - state.startedAt)
            });

            showStatus(data.is_correct ? 'Richtig.' : 'Falsch.', data.is_correct ? 'good' : 'bad');

            window.setTimeout(() => {
                els.submitChoice.disabled = false;
                renderQuestion(data.question, data.stats);
            }, 850);
        } catch (error) {
            state.locked = false;
            els.submitChoice.disabled = false;
            showStatus(error.message, 'error');
        }
    }

    async function deleteCurrent() {
        if (!state.question || state.locked) return;
        if (!window.confirm('Diese Karte wirklich deaktivieren?')) return;

        state.locked = true;

        try {
            const data = await post({
                action: 'delete',
                exam: state.exam,
                topic: state.topic,
                question_id: state.question.id
            });

            renderQuestion(data.question, data.stats);
        } catch (error) {
            state.locked = false;
            showStatus(error.message, 'error');
        }
    }

    els.reveal.addEventListener('click', revealAnswer);
    els.submitChoice.addEventListener('click', submitChoice);
    els.deleteBtn.addEventListener('click', deleteCurrent);

    els.ratingButtons.forEach(button => {
        button.addEventListener('click', () => rateCurrent(Number(button.dataset.rating)));
    });

    els.exam.addEventListener('change', () => {
        state.exam = String(els.exam.value || '');
        state.topic = '';
        loadDeck();
    });

    els.topic.addEventListener('change', () => {
        state.topic = String(els.topic.value || '');
        loadDeck();
    });

    document.addEventListener('keydown', event => {
        if (!state.question || state.locked) return;

        const tag = String(document.activeElement?.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag)) return;

        if (state.question.question_type === 'recall') {
            if ((event.code === 'Space' || event.code === 'Enter') && !state.revealed) {
                event.preventDefault();
                revealAnswer();
                return;
            }

            if (state.revealed && ['Digit1', 'Digit2', 'Digit3', 'Digit4'].includes(event.code)) {
                event.preventDefault();
                rateCurrent(Number(event.code.slice(-1)) - 1);
            }
        }
    });

    loadDeck();
})();
</script>
