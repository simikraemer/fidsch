<?php

// sci/Kartei.php
// Single-/Multiple-Choice + Recall/Reveal.
// Fachauswahl per Dropdown, Topics per Buttons inklusive "Alle".
// "Alle" randomisiert alle aktuell fälligen Karten des Fachs vollständig.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$sciconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const KARTEI_EMPTY_TOPIC = '__KARTEI_EMPTY_TOPIC__';
const KARTEI_ALL_TOPICS = '__KARTEI_ALL_TOPICS__';
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
    if ($topic === KARTEI_ALL_TOPICS) {
        return 'Alle';
    }

    return $topic === KARTEI_EMPTY_TOPIC ? 'Ohne Topic' : $topic;
}

function karteiTopicMatches(?string $dbTopic, string $selectedTopic): bool
{
    if ($selectedTopic === KARTEI_ALL_TOPICS) {
        return true;
    }

    return karteiNormalizeTopicValue($dbTopic) === $selectedTopic;
}

function karteiTopicFromUrlValue(string $value): string
{
    $value = trim($value);

    if ($value === 'all') {
        return KARTEI_ALL_TOPICS;
    }

    if ($value === '__empty__') {
        return KARTEI_EMPTY_TOPIC;
    }

    return $value;
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

function karteiLoadDueCountsByTopic(mysqli $conn, string $exam): array
{
    if ($exam === '') {
        return [
            'total' => 0,
            'topics' => [],
        ];
    }

    $stmt = $conn->prepare("
        SELECT
            CASE
                WHEN q.topic IS NULL OR TRIM(q.topic) = '' THEN ?
                ELSE TRIM(q.topic)
            END AS topic_value,
            COUNT(*) AS due_count
        FROM kartei_fragen q
        LEFT JOIN kartei_lernstand ls
            ON ls.question_id = q.id
        WHERE q.is_active = 1
          AND TRIM(q.exam) = ?
          AND (
                ls.question_id IS NULL
                OR ls.next_due_at IS NULL
                OR ls.next_due_at <= NOW()
          )
        GROUP BY topic_value
    ");

    if (!$stmt) {
        throw new RuntimeException('Fällige Karten pro Topic konnten nicht vorbereitet werden: ' . $conn->error);
    }

    $empty = KARTEI_EMPTY_TOPIC;
    $stmt->bind_param('ss', $empty, $exam);
    $stmt->execute();
    $res = $stmt->get_result();

    $topics = [];
    $total = 0;

    while ($row = $res->fetch_assoc()) {
        $topicValue = (string)$row['topic_value'];
        $dueCount = max(0, (int)($row['due_count'] ?? 0));

        $topics[$topicValue] = $dueCount;
        $total += $dueCount;
    }

    $stmt->close();

    return [
        'total' => $total,
        'topics' => $topics,
    ];
}

function karteiBuildClientTopics(
    array $availableTopics,
    array $dueCounts
): array {
    $items = [
        [
            'value' => KARTEI_ALL_TOPICS,
            'label' => 'Alle',
            'due_count' => max(0, (int)($dueCounts['total'] ?? 0)),
        ],
    ];

    $topicDueCounts = is_array($dueCounts['topics'] ?? null)
        ? $dueCounts['topics']
        : [];

    foreach ($availableTopics as $topic) {
        $items[] = [
            'value' => $topic,
            'label' => karteiTopicLabel($topic),
            'due_count' => max(0, (int)($topicDueCounts[$topic] ?? 0)),
        ];
    }

    return $items;
}

function karteiNormalizeRequestedTopic(string $topic, array $availableTopics): string
{
    if ($topic === KARTEI_ALL_TOPICS) {
        return KARTEI_ALL_TOPICS;
    }

    if ($topic !== '' && in_array($topic, $availableTopics, true)) {
        return $topic;
    }

    return $availableTopics[0] ?? KARTEI_ALL_TOPICS;
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
            q.is_active,
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

function karteiApplyTopicFilterToStatement(
    mysqli $conn,
    string $baseSql,
    string $exam,
    string $topic,
    string $suffix = ''
): mysqli_stmt {
    if ($topic === KARTEI_ALL_TOPICS) {
        $stmt = $conn->prepare($baseSql . $suffix);
        if (!$stmt) {
            throw new RuntimeException('Abfrage konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('s', $exam);
        return $stmt;
    }

    if ($topic === KARTEI_EMPTY_TOPIC) {
        $stmt = $conn->prepare($baseSql . " AND (q.topic IS NULL OR TRIM(q.topic) = '')" . $suffix);
        if (!$stmt) {
            throw new RuntimeException('Abfrage konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('s', $exam);
        return $stmt;
    }

    $stmt = $conn->prepare($baseSql . " AND TRIM(COALESCE(q.topic, '')) = ?" . $suffix);
    if (!$stmt) {
        throw new RuntimeException('Abfrage konnte nicht vorbereitet werden: ' . $conn->error);
    }

    $stmt->bind_param('ss', $exam, $topic);
    return $stmt;
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
            'answer_count' => 0,
            'correct_total' => 0,
            'wrong_total' => 0,
            'correct_percent' => null,
        ];
    }

    $cardBase = "
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

    $stmt = karteiApplyTopicFilterToStatement($conn, $cardBase, $exam, $topic);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $newCount = (int)($row['new_count'] ?? 0);
    $reviewDue = (int)($row['review_due'] ?? 0);

    $answerBase = "
        SELECT
            COUNT(*) AS answer_count,
            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct_total,
            SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_total
        FROM kartei_antworten a
        INNER JOIN kartei_fragen q
            ON q.id = a.question_id
        WHERE TRIM(q.exam) = ?
    ";

    $answerStmt = karteiApplyTopicFilterToStatement($conn, $answerBase, $exam, $topic);
    $answerStmt->execute();
    $answerRow = $answerStmt->get_result()->fetch_assoc() ?: [];
    $answerStmt->close();

    $answerCount = (int)($answerRow['answer_count'] ?? 0);
    $correctTotal = (int)($answerRow['correct_total'] ?? 0);
    $wrongTotal = (int)($answerRow['wrong_total'] ?? 0);

    return [
        'total' => (int)($row['total_count'] ?? 0),
        'due_now' => $newCount + $reviewDue,
        'new_count' => $newCount,
        'review_due' => $reviewDue,
        'reviewed_today' => (int)($row['reviewed_today'] ?? 0),
        'next_due_at' => $row['next_due_at'] !== null ? (string)$row['next_due_at'] : null,
        'answer_count' => $answerCount,
        'correct_total' => $correctTotal,
        'wrong_total' => $wrongTotal,
        'correct_percent' => $answerCount > 0
            ? round(($correctTotal / $answerCount) * 100, 1)
            : null,
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

    if ($topic === KARTEI_ALL_TOPICS) {
        $stmt = $conn->prepare($base . " ORDER BY RAND() LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $conn->error);
        }
        $stmt->bind_param('s', $exam);
    } else {
        $order = "
            ORDER BY
                CASE WHEN ls.question_id IS NULL THEN 1 ELSE 0 END ASC,
                COALESCE(ls.next_due_at, '9999-12-31 23:59:59') ASC,
                q.sort_order ASC,
                q.id ASC
            LIMIT 1
        ";

        $stmt = karteiApplyTopicFilterToStatement($conn, $base, $exam, $topic, $order);
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

function karteiCalculateSchedule(array $state, int $rating): array
{
    if ($rating < 0 || $rating > 3) {
        throw new InvalidArgumentException('Ungültige Bewertung.');
    }

    $ease = isset($state['ease_factor']) && $state['ease_factor'] !== null
        ? (float)$state['ease_factor']
        : 2.30;

    $oldInterval = isset($state['interval_minutes']) && $state['interval_minutes'] !== null
        ? (int)$state['interval_minutes']
        : 0;

    $repetitions = isset($state['repetitions']) && $state['repetitions'] !== null
        ? (int)$state['repetitions']
        : 0;

    $lapses = isset($state['lapses']) && $state['lapses'] !== null
        ? (int)$state['lapses']
        : 0;

    switch ($rating) {
        case 0:
            $interval = 10;
            $repetitions = 0;
            $lapses++;
            $ease = max(1.30, $ease - 0.20);
            break;

        case 1:
            $interval = $oldInterval > 0
                ? max(720, min(2880, (int)round($oldInterval * 1.20)))
                : 720;
            $repetitions = max(0, $repetitions - 1);
            $ease = max(1.30, $ease - 0.10);
            break;

        case 2:
            if ($repetitions <= 0) {
                $interval = 1440;
            } elseif ($repetitions === 1) {
                $interval = 4320;
            } else {
                $interval = max(1440, (int)round(max(1, $oldInterval) * $ease));
            }
            $repetitions++;
            break;

        case 3:
            if ($repetitions <= 0) {
                $interval = 4320;
            } elseif ($repetitions === 1) {
                $interval = 10080;
            } else {
                $interval = max(4320, (int)round(max(1, $oldInterval) * ($ease + 0.80)));
            }
            $repetitions++;
            $ease = min(3.20, $ease + 0.15);
            break;
    }

    return [
        'rating' => $rating,
        'interval_minutes' => $interval,
        'ease_factor' => $ease,
        'repetitions' => $repetitions,
        'lapses' => $lapses,
    ];
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

    $learningState = [
        'ease_factor' => $row['ease_factor'] !== null ? (float)$row['ease_factor'] : 2.30,
        'interval_minutes' => (int)($row['interval_minutes'] ?? 0),
        'repetitions' => (int)($row['repetitions'] ?? 0),
        'lapses' => (int)($row['lapses'] ?? 0),
    ];

    $ratingIntervals = [];
    for ($rating = 0; $rating <= 3; $rating++) {
        $preview = karteiCalculateSchedule($learningState, $rating);
        $ratingIntervals[(string)$rating] = (int)$preview['interval_minutes'];
    }

    $topicValue = karteiNormalizeTopicValue($row['topic'] ?? null);

    return [
        'id' => (int)$row['id'],
        'question_type' => (string)$row['question_type'],
        'question_html' => karteiNormalizeMathMarkup((string)$row['question_html']),
        'answer_html' => karteiNormalizeMathMarkup((string)($row['answer_html'] ?? '')),
        'image_path' => trim((string)($row['image_path'] ?? '')),
        'source_label' => trim((string)($row['source_label'] ?? '')),
        'topic' => $topicValue,
        'topic_label' => karteiTopicLabel($topicValue),
        'options' => $options,
        'rating_intervals' => $ratingIntervals,
        'learning' => [
            'ease_factor' => $learningState['ease_factor'],
            'interval_minutes' => $learningState['interval_minutes'],
            'repetitions' => $learningState['repetitions'],
            'lapses' => $learningState['lapses'],
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
    if ((int)($question['id'] ?? 0) <= 0 || (int)($question['is_active'] ?? 0) === 0) {
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
    $state = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $schedule = karteiCalculateSchedule($state, $rating);

    $nextDue = (new DateTimeImmutable('now'))
        ->modify('+' . $schedule['interval_minutes'] . ' minutes')
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

    $interval = (int)$schedule['interval_minutes'];
    $ease = (float)$schedule['ease_factor'];
    $repetitions = (int)$schedule['repetitions'];
    $lapses = (int)$schedule['lapses'];

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

    $schedule['next_due_at'] = $nextDue;
    return $schedule;
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
        $topic = karteiNormalizeRequestedTopic($topic, $availableTopics);
        $clientTopics = karteiBuildClientTopics(
            $availableTopics,
            karteiLoadDueCountsByTopic($sciconn, $exam)
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
            $clientTopics = karteiBuildClientTopics(
                $availableTopics,
                karteiLoadDueCountsByTopic($sciconn, $exam)
            );

            karteiJsonResponse([
                'ok' => true,
                'schedule' => $schedule,
                'available_topics' => $clientTopics,
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
            $clientTopics = karteiBuildClientTopics(
                $availableTopics,
                karteiLoadDueCountsByTopic($sciconn, $exam)
            );

            karteiJsonResponse([
                'ok' => true,
                'is_correct' => (bool)$isCorrect,
                'correct_values' => $correct,
                'schedule' => $schedule,
                'available_topics' => $clientTopics,
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

            $availableTopics = karteiLoadAvailableTopics($sciconn, $exam);
            $topic = karteiNormalizeRequestedTopic($topic, $availableTopics);
            $snapshot = karteiSnapshot($sciconn, $exam, $topic);

            karteiJsonResponse([
                'ok' => true,
                'topic' => $topic,
                'available_topics' => karteiBuildClientTopics(
                    $availableTopics,
                    karteiLoadDueCountsByTopic($sciconn, $exam)
                ),
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

$requestedExam = trim((string)($_GET['exam'] ?? ''));

if ($requestedExam !== '' && in_array($requestedExam, $availableExams, true)) {
    $initialExam = $requestedExam;
} else {
    $initialExam = in_array('Strömungsmechanik', $availableExams, true)
        ? 'Strömungsmechanik'
        : ($availableExams[0] ?? '');
}

$availableTopics = karteiLoadAvailableTopics($sciconn, $initialExam);
$requestedTopic = karteiTopicFromUrlValue((string)($_GET['topic'] ?? ''));
$initialTopic = karteiNormalizeRequestedTopic($requestedTopic, $availableTopics);
$initialClientTopics = karteiBuildClientTopics(
    $availableTopics,
    karteiLoadDueCountsByTopic($sciconn, $initialExam)
);

$page_title = 'Karteikarten';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';

?>

<div class="sci-review-shell">

    <section class="sci-deckbar" aria-label="Karteikarten-Auswahl und Lernstatistik">

        <div class="sci-deckbar-main">
            <label class="sci-deckbar-exam" for="examSelect">
                <span class="sci-deckbar-label">Fach</span>
                <select id="examSelect" class="sci-deckbar-select" aria-label="Fach">
                    <?php foreach ($availableExams as $exam): ?>
                        <option
                            value="<?= htmlspecialchars($exam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            <?= $exam === $initialExam ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($exam, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="sci-deckbar-current">
                <div class="sci-deckbar-label">Aktueller Lernstand</div>
                <div class="sci-deckbar-kpis" id="deckStats"></div>
            </div>
        </div>

        <div class="sci-deckbar-topicrow">
            <div class="sci-deckbar-label">Thema</div>
            <div
                class="sci-deckbar-topiclist"
                id="topicButtons"
                role="group"
                aria-label="Thema"
            >
                <?php foreach ($initialClientTopics as $topicItem): ?>
                    <button
                        type="button"
                        class="sci-deckbar-topic<?= $topicItem['value'] === $initialTopic ? ' is-active' : '' ?>"
                        data-topic="<?= htmlspecialchars($topicItem['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        aria-pressed="<?= $topicItem['value'] === $initialTopic ? 'true' : 'false' ?>"
                    >
                        <span class="sci-deckbar-topic-text">
                            <?= htmlspecialchars($topicItem['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </span>
                        <?php if ((int)($topicItem['due_count'] ?? 0) > 0): ?>
                            <span
                                class="sci-deckbar-topic-count"
                                title="<?= (int)$topicItem['due_count'] ?> aktuell fällig"
                                aria-label="<?= (int)$topicItem['due_count'] ?> aktuell fällig"
                            >
                                <?= (int)$topicItem['due_count'] ?>
                            </span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sci-deckbar-history">
            <div class="sci-deckbar-history-head">
                <span class="sci-deckbar-label">Gesamtverlauf</span>
                <span class="sci-deckbar-history-hint">für die aktuelle Auswahl</span>
            </div>
            <div class="sci-deckbar-history-list" id="answerStats"></div>
        </div>

    </section>

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
                        <small>10 Minuten</small>
                    </button>

                    <button type="button" class="sci-rating sci-rating-hard" data-rating="1">
                        <span>Unsicher</span>
                        <small>–</small>
                    </button>

                    <button type="button" class="sci-rating sci-rating-good" data-rating="2">
                        <span>Gewusst</span>
                        <small>–</small>
                    </button>

                    <button type="button" class="sci-rating sci-rating-easy" data-rating="3">
                        <span>Sicher gewusst</span>
                        <small>–</small>
                    </button>
                </div>

                <div class="sci-review-shortcuts">
                    Tastatur: 1–4 bewerten · Leertaste Antwort aufdecken
                </div>
            </section>

            <div class="sci-review-status hidden" id="statusMessage"></div>

        </section>

        <section class="sci-review-empty hidden" id="emptyState">
            <div class="sci-review-empty-title">Für diese Auswahl ist aktuell alles erledigt.</div>
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
    const ALL_TOPIC = <?= json_encode(KARTEI_ALL_TOPICS, JSON_UNESCAPED_SLASHES) ?>;
    const EMPTY_TOPIC = <?= json_encode(KARTEI_EMPTY_TOPIC, JSON_UNESCAPED_SLASHES) ?>;

    const els = {
        exam: document.getElementById('examSelect'),
        topicButtons: document.getElementById('topicButtons'),
        stats: document.getElementById('deckStats'),
        answerStats: document.getElementById('answerStats'),
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
        exam: <?= json_encode($initialExam, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        topic: <?= json_encode($initialTopic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        question: null,
        selected: new Set(),
        revealed: false,
        locked: false,
        choiceReview: false,
        pendingChoiceNext: null,
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

    function formatIntervalCompact(minutes) {
        minutes = Number(minutes || 0);

        if (minutes <= 0) return 'neu';
        if (minutes < 60) return `${minutes} min`;

        if (minutes < 1440) {
            const hours = Math.floor(minutes / 60);
            const restMinutes = minutes % 60;
            return restMinutes > 0 ? `${hours} h ${restMinutes} min` : `${hours} h`;
        }

        const days = Math.floor(minutes / 1440);
        const rest = minutes % 1440;
        const hours = Math.floor(rest / 60);
        const restMinutes = rest % 60;
        const parts = [`${days} d`];

        if (hours > 0) parts.push(`${hours} h`);
        if (restMinutes > 0) parts.push(`${restMinutes} min`);

        return parts.join(' ');
    }

    function formatIntervalExact(minutes) {
        minutes = Math.max(0, Number(minutes || 0));

        if (minutes < 60) {
            return `${minutes} ${minutes === 1 ? 'Minute' : 'Minuten'}`;
        }

        const days = Math.floor(minutes / 1440);
        let remainder = minutes % 1440;
        const hours = Math.floor(remainder / 60);
        const restMinutes = remainder % 60;
        const parts = [];

        if (days > 0) {
            parts.push(`${days} ${days === 1 ? 'Tag' : 'Tage'}`);
        }

        if (hours > 0) {
            parts.push(`${hours} ${hours === 1 ? 'Stunde' : 'Stunden'}`);
        }

        if (restMinutes > 0) {
            parts.push(`${restMinutes} ${restMinutes === 1 ? 'Minute' : 'Minuten'}`);
        }

        return parts.join(' ');
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

    function formatPercent(value) {
        const number = Number(value);

        if (!Number.isFinite(number)) {
            return '–';
        }

        return new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 1
        }).format(number) + ' %';
    }

    function renderStats(stats) {
        const s = stats || {};

        els.stats.innerHTML = [
            ['Fällig', s.due_now ?? 0, 'is-due'],
            ['Neu', s.new_count ?? 0, 'is-new'],
            ['Heute', s.reviewed_today ?? 0, 'is-today'],
            ['Gesamt', s.total ?? 0, 'is-total']
        ].map(([label, value, modifier]) =>
            `<div class="sci-deckbar-kpi ${modifier}">
                <strong>${escapeHtml(value)}</strong>
                <span>${escapeHtml(label)}</span>
            </div>`
        ).join('');

        els.answerStats.innerHTML = [
            ['Richtig', s.correct_total ?? 0, 'is-correct'],
            ['Falsch', s.wrong_total ?? 0, 'is-wrong'],
            ['Trefferquote', formatPercent(s.correct_percent), 'is-rate'],
            ['Antworten insgesamt', s.answer_count ?? 0, 'is-total']
        ].map(([label, value, modifier]) =>
            `<div class="sci-deckbar-history-stat ${modifier}">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value)}</strong>
            </div>`
        ).join('');
    }

    function renderTopics(items, selectedValue) {
        const selected = String(selectedValue || '');
        els.topicButtons.innerHTML = '';

        (items || []).forEach(item => {
            const button = document.createElement('button');
            const value = String(item.value || '');

            button.type = 'button';
            button.className = 'sci-deckbar-topic';
            button.dataset.topic = value;

            const label = document.createElement('span');
            label.className = 'sci-deckbar-topic-text';
            label.textContent = String(item.label || item.value || '');
            button.appendChild(label);

            const dueCount = Math.max(0, Number(item.due_count || 0));

            if (dueCount > 0) {
                const badge = document.createElement('span');
                badge.className = 'sci-deckbar-topic-count';
                badge.textContent = String(dueCount);
                badge.title = `${dueCount} aktuell fällig`;
                badge.setAttribute('aria-label', `${dueCount} aktuell fällig`);
                button.appendChild(badge);
            }

            const active = value === selected;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');

            button.addEventListener('click', () => {
                if (state.locked || state.topic === value) {
                    return;
                }

                state.topic = value;
                renderTopicActiveState();
                syncUrl();
                loadDeck();
            });

            els.topicButtons.appendChild(button);
        });

        state.topic = selected;
        renderTopicActiveState();
    }

    function renderTopicActiveState() {
        els.topicButtons.querySelectorAll('[data-topic]').forEach(button => {
            const active = String(button.dataset.topic || '') === state.topic;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function topicToUrlValue(topic) {
        if (topic === ALL_TOPIC) return 'all';
        if (topic === EMPTY_TOPIC) return '__empty__';
        return topic;
    }

    function syncUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('exam', state.exam);

        if (state.topic) {
            url.searchParams.set('topic', topicToUrlValue(state.topic));
        } else {
            url.searchParams.delete('topic');
        }

        history.replaceState(null, '', `${url.pathname}?${url.searchParams.toString()}${url.hash}`);
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

    function renderRatingIntervals(question) {
        const intervals = question?.rating_intervals || {};

        els.ratingButtons.forEach(button => {
            const rating = String(button.dataset.rating || '');
            const small = button.querySelector('small');

            if (!small) return;

            const interval = Number(intervals[rating]);
            small.textContent = Number.isFinite(interval)
                ? formatIntervalExact(interval)
                : '–';
        });
    }

    function renderQuestion(question, stats) {
        renderStats(stats);

        state.question = question;
        state.revealed = false;
        state.locked = false;
        state.choiceReview = false;
        state.pendingChoiceNext = null;
        state.startedAt = performance.now();

        els.status.classList.add('hidden');
        els.answerSection.classList.add('hidden');
        els.ratingSection.classList.add('hidden');
        els.reveal.classList.add('hidden');
        els.submitChoice.classList.add('hidden');
        els.submitChoice.disabled = false;
        els.submitChoice.textContent = 'Antwort absenden';
        els.choiceGrid.classList.add('hidden');

        clearChoiceState();

        if (!question) {
            els.card.classList.add('hidden');
            els.empty.classList.remove('hidden');

            if (stats && stats.next_due_at) {
                els.emptyNextDue.textContent =
                    `Nächste fällige Karte: ${formatDateTime(stats.next_due_at)}`;
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

        const metaParts = [typeLabel];

        if (state.topic === ALL_TOPIC && question.topic_label) {
            metaParts.push(question.topic_label);
        }

        if (question.source_label) {
            metaParts.push(question.source_label);
        }

        els.meta.textContent = metaParts.join(' · ');

        const l = question.learning || {};
        els.learningMeta.textContent =
            `Intervall: ${formatIntervalCompact(l.interval_minutes)} · ` +
            `Wiederholungen: ${l.repetitions || 0} · ` +
            `Fehler: ${l.lapses || 0}`;

        els.question.innerHTML = question.question_html || '';
        els.answer.innerHTML = question.answer_html || '';

        if (question.image_path) {
            els.image.src = `${API_URL}?action=image&file=${encodeURIComponent(question.image_path)}`;
            els.imageWrap.classList.remove('hidden');
        } else {
            els.image.removeAttribute('src');
            els.imageWrap.classList.add('hidden');
        }

        renderRatingIntervals(question);

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

        if (kind) {
            els.status.classList.add(`is-${kind}`);
        }
    }

    async function loadDeck() {
        state.exam = String(els.exam.value || state.exam || '');

        try {
            const data = await post({
                action: 'load',
                exam: state.exam,
                topic: state.topic
            });

            state.exam = data.exam || state.exam;
            state.topic = data.topic || state.topic;

            if (els.exam.value !== state.exam) {
                els.exam.value = state.exam;
            }

            if (Array.isArray(data.available_topics)) {
                renderTopics(data.available_topics, state.topic);
            }

            syncUrl();
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

            if (Array.isArray(data.available_topics)) {
                renderTopics(data.available_topics, state.topic);
            }

            renderQuestion(data.question, data.stats);
        } catch (error) {
            state.locked = false;
            els.ratingButtons.forEach(btn => btn.disabled = false);
            showStatus(error.message, 'error');
        }
    }

    function showChoiceCorrection(correctValues) {
        const correct = new Set((correctValues || []).map(value => String(value)));

        els.choiceGrid.querySelectorAll('.sci-review-choice').forEach(button => {
            const key = String(button.dataset.key || '');
            const wasSelected = state.selected.has(key);
            const isCorrect = correct.has(key);

            button.classList.remove('is-selected');
            button.classList.toggle('is-review-correct', isCorrect);
            button.classList.toggle('is-review-wrong', wasSelected && !isCorrect);
            button.setAttribute('aria-disabled', 'true');
        });
    }

    function continueAfterWrongChoice() {
        if (!state.choiceReview || !state.pendingChoiceNext) {
            return;
        }

        const next = state.pendingChoiceNext;

        state.choiceReview = false;
        state.pendingChoiceNext = null;

        renderQuestion(next.question, next.stats);
    }

    async function submitChoice() {
        if (state.choiceReview) {
            continueAfterWrongChoice();
            return;
        }

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

            if (Array.isArray(data.available_topics)) {
                renderTopics(data.available_topics, state.topic);
            }

            if (data.is_correct) {
                showStatus('Richtig.', 'good');

                window.setTimeout(() => {
                    els.submitChoice.disabled = false;
                    renderQuestion(data.question, data.stats);
                }, 850);

                return;
            }

            // Falsche Choice-Antworten bleiben sichtbar, damit die Korrektur
            // in Ruhe geprüft werden kann. Erst "Weiter" lädt die nächste Karte.
            renderStats(data.stats);
            showChoiceCorrection(data.correct_values);
            showStatus('Falsch. Die richtige Antwort ist grün markiert.', 'bad');

            state.choiceReview = true;
            state.pendingChoiceNext = {
                question: data.question,
                stats: data.stats
            };

            els.submitChoice.textContent = 'Weiter';
            els.submitChoice.disabled = false;
            els.submitChoice.classList.remove('hidden');
        } catch (error) {
            state.locked = false;
            state.choiceReview = false;
            state.pendingChoiceNext = null;
            els.submitChoice.textContent = 'Antwort absenden';
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

            if (data.topic) {
                state.topic = data.topic;
            }

            if (Array.isArray(data.available_topics)) {
                renderTopics(data.available_topics, state.topic);
            }

            syncUrl();
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
        state.topic = ALL_TOPIC;
        syncUrl();
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

    renderTopicActiveState();
    syncUrl();
    loadDeck();
})();
</script>