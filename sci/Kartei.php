<?php
// sci/Kartei.php

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';
$sciconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const KARTEI_EMPTY_TOPIC = '__KARTEI_EMPTY_TOPIC__';

function karteiJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function karteiRepairBrokenJsonBackslashes(string $json): string
{
    return preg_replace('/\\\\(?!["\\\\\/bfnrtu])/', '\\\\', $json) ?? $json;
}

function karteiDecodeJsonArray(?string $json): array
{
    if ($json === null) {
        return [];
    }

    $json = trim($json);
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $repaired = karteiRepairBrokenJsonBackslashes($json);
    $decoded = json_decode($repaired, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $htmlDecoded = html_entity_decode($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($htmlDecoded !== $json) {
        $decoded = json_decode($htmlDecoded, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $repairedHtml = karteiRepairBrokenJsonBackslashes($htmlDecoded);
        $decoded = json_decode($repairedHtml, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function karteiNormalizeSelection(array $values): array
{
    $normalized = [];

    foreach ($values as $value) {
        if (is_array($value) || is_object($value)) {
            continue;
        }

        $key = trim((string)$value);
        if ($key === '') {
            continue;
        }

        $normalized[] = $key;
    }

    $normalized = array_values(array_unique($normalized, SORT_STRING));
    sort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

    return $normalized;
}

function karteiNormalizeMathMarkup(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    while (strpos($text, '\\\\') !== false) {
        $text = str_replace('\\\\', '\\', $text);
    }

    return $text;
}

function karteiNormalizeTopicValue(?string $topic): string
{
    $topic = trim((string)$topic);
    return $topic === '' ? KARTEI_EMPTY_TOPIC : $topic;
}

function karteiTopicLabel(string $topicValue): string
{
    return $topicValue === KARTEI_EMPTY_TOPIC ? 'Ohne Topic' : $topicValue;
}

function karteiResolveSelectedTopic(array $availableTopics, ?string $requestedTopic): string
{
    $requestedTopic = trim((string)$requestedTopic);

    if ($requestedTopic !== '' && in_array($requestedTopic, $availableTopics, true)) {
        return $requestedTopic;
    }

    return $availableTopics[0] ?? '';
}

function karteiEmptyStats(): array
{
    return [
        'total_answers'           => 0,
        'correct_answers'         => 0,
        'wrong_answers'           => 0,
        'answered_question_count' => 0,
        'accuracy_pct'            => 0.0,
    ];
}

function karteiEmptyQuestionStats(): array
{
    return [
        'attempts'        => 0,
        'correct_answers' => 0,
        'wrong_answers'   => 0,
        'accuracy_pct'    => 0.0,
    ];
}

function karteiLoadAvailableTopics(mysqli $conn): array
{
    $sql = "
        SELECT DISTINCT topic
        FROM kartei_fragen
        ORDER BY topic ASC
    ";

    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException('Topics konnten nicht geladen werden: ' . $conn->error);
    }

    $topics = [];

    while ($row = $res->fetch_assoc()) {
        $value = karteiNormalizeTopicValue($row['topic'] ?? '');
        if (!in_array($value, $topics, true)) {
            $topics[] = $value;
        }
    }

    $res->free();
    return $topics;
}

function karteiLoadActiveQuestions(mysqli $conn, string $selectedTopic): array
{
    if ($selectedTopic === '') {
        return [];
    }

    if ($selectedTopic === KARTEI_EMPTY_TOPIC) {
        $sql = "
            SELECT
                id,
                question_type,
                question_html,
                options_json,
                correct_answers_json,
                topic,
                source_label,
                sort_order
            FROM kartei_fragen
            WHERE is_active = 1
              AND (topic IS NULL OR TRIM(topic) = '')
            ORDER BY sort_order ASC, id ASC
        ";

        $res = $conn->query($sql);
        if (!$res) {
            throw new RuntimeException('Fragen konnten nicht geladen werden: ' . $conn->error);
        }

        $questions = [];
        while ($row = $res->fetch_assoc()) {
            $questions[] = $row;
        }
        $res->free();

        return $questions;
    }

    $sql = "
        SELECT
            id,
            question_type,
            question_html,
            options_json,
            correct_answers_json,
            topic,
            source_label,
            sort_order
        FROM kartei_fragen
        WHERE is_active = 1
          AND TRIM(COALESCE(topic, '')) = ?
        ORDER BY sort_order ASC, id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Fragen konnten nicht vorbereitet werden: ' . $conn->error);
    }

    $stmt->bind_param('s', $selectedTopic);
    $stmt->execute();
    $res = $stmt->get_result();

    $questions = [];
    while ($row = $res->fetch_assoc()) {
        $questions[] = $row;
    }

    $stmt->close();
    return $questions;
}

function karteiLoadHistoryByQuestion(mysqli $conn, array $questionIds): array
{
    if (empty($questionIds)) {
        return [];
    }

    $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
    $idList = implode(',', $questionIds);

    $sql = "
        SELECT
            question_id,
            is_correct,
            answered_at
        FROM kartei_antworten
        WHERE question_id IN ($idList)
        ORDER BY question_id ASC, answered_at DESC, id DESC
    ";

    $res = $conn->query($sql);
    if (!$res) {
        throw new RuntimeException('Antwort-Historie konnte nicht geladen werden: ' . $conn->error);
    }

    $history = [];

    while ($row = $res->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        $history[$qid][] = [
            'is_correct'  => (int)$row['is_correct'],
            'answered_at' => (string)$row['answered_at'],
        ];
    }

    $res->free();
    return $history;
}

function karteiAggregateStats(mysqli $conn, string $selectedTopic): array
{
    if ($selectedTopic === '') {
        return karteiEmptyStats();
    }

    if ($selectedTopic === KARTEI_EMPTY_TOPIC) {
        $sql = "
            SELECT
                COUNT(*) AS total_answers,
                SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers,
                SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_answers,
                COUNT(DISTINCT a.question_id) AS answered_question_count
            FROM kartei_antworten a
            INNER JOIN kartei_fragen q
                ON q.id = a.question_id
            WHERE q.topic IS NULL OR TRIM(q.topic) = ''
        ";

        $res = $conn->query($sql);
        if (!$res) {
            throw new RuntimeException('Statistik konnte nicht geladen werden: ' . $conn->error);
        }

        $row = $res->fetch_assoc() ?: [];
        $res->free();
    } else {
        $sql = "
            SELECT
                COUNT(*) AS total_answers,
                SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers,
                SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) AS wrong_answers,
                COUNT(DISTINCT a.question_id) AS answered_question_count
            FROM kartei_antworten a
            INNER JOIN kartei_fragen q
                ON q.id = a.question_id
            WHERE TRIM(COALESCE(q.topic, '')) = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Statistik konnte nicht vorbereitet werden: ' . $conn->error);
        }

        $stmt->bind_param('s', $selectedTopic);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc() ?: [];
        $stmt->close();
    }

    $totalAnswers = (int)($row['total_answers'] ?? 0);
    $correctAnswers = (int)($row['correct_answers'] ?? 0);
    $wrongAnswers = (int)($row['wrong_answers'] ?? 0);

    return [
        'total_answers'           => $totalAnswers,
        'correct_answers'         => $correctAnswers,
        'wrong_answers'           => $wrongAnswers,
        'answered_question_count' => (int)($row['answered_question_count'] ?? 0),
        'accuracy_pct'            => $totalAnswers > 0 ? round(($correctAnswers / $totalAnswers) * 100, 1) : 0.0,
    ];
}

function karteiBuildQuestionStats(array $historyRows): array
{
    $attempts = count($historyRows);
    $correctAnswers = 0;

    foreach ($historyRows as $row) {
        if ((int)$row['is_correct'] === 1) {
            $correctAnswers++;
        }
    }

    $wrongAnswers = $attempts - $correctAnswers;

    return [
        'attempts'        => $attempts,
        'correct_answers' => $correctAnswers,
        'wrong_answers'   => $wrongAnswers,
        'accuracy_pct'    => $attempts > 0 ? round(($correctAnswers / $attempts) * 100, 1) : 0.0,
    ];
}

function karteiComputeQuestionMeta(array $historyRows, int $nowTs): array
{
    $attempts = count($historyRows);

    if ($attempts === 0) {
        return [
            'priority'       => 2000000000 + random_int(0, 500000),
            'due_now'        => true,
            'never_answered' => true,
        ];
    }

    $right = 0;
    foreach ($historyRows as $row) {
        if ((int)$row['is_correct'] === 1) {
            $right++;
        }
    }

    $lastTs = strtotime((string)$historyRows[0]['answered_at']) ?: $nowTs;
    $lastWasCorrect = (int)$historyRows[0]['is_correct'] === 1;

    $chain = 0;
    foreach ($historyRows as $row) {
        $currentCorrect = (int)$row['is_correct'] === 1;
        if ($currentCorrect === $lastWasCorrect) {
            $chain++;
            continue;
        }
        break;
    }

    $accuracy = $attempts > 0 ? ($right / $attempts) : 0.0;

    if (!$lastWasCorrect) {
        $retryMinutes = [5, 15, 45, 180, 720, 1440];
        $waitSeconds = $retryMinutes[min($chain - 1, count($retryMinutes) - 1)] * 60;
        $dueTs = $lastTs + $waitSeconds;
        $base = 900000000;
    } else {
        $intervalDays = [1, 2, 4, 7, 12, 20, 35, 60, 90];
        $index = min($chain - 1, count($intervalDays) - 1);
        $intervalSeconds = $intervalDays[$index] * 86400;

        if ($attempts >= 4 && $accuracy < 0.6) {
            $intervalSeconds = (int)max(86400, round($intervalSeconds * 0.5));
        }

        if ($attempts >= 8 && $accuracy > 0.9 && $index < count($intervalDays) - 1) {
            $intervalSeconds = $intervalDays[$index + 1] * 86400;
        }

        $dueTs = $lastTs + $intervalSeconds;
        $base = 500000000;
    }

    $overdue = $nowTs - $dueTs;
    $noveltyBonus = max(0, 12 - min($attempts, 12)) * 1800;
    $stalenessBonus = (int)round(max(0, $nowTs - $lastTs) * 0.08);
    $futurePenalty = $overdue >= 0 ? 0 : (int)round(abs($overdue) * 2.5);

    return [
        'priority'       => $base + (max(0, $overdue) * 4) + $noveltyBonus + $stalenessBonus - $futurePenalty + random_int(0, 1000),
        'due_now'        => $dueTs <= $nowTs,
        'never_answered' => false,
    ];
}

function karteiBuildClientOptions(array $question): array
{
    $rawOptions = karteiDecodeJsonArray((string)$question['options_json']);
    $options = [];
    $fallbackIndex = 0;

    foreach ($rawOptions as $rawKey => $option) {
        $fallbackIndex++;
        $originalKey = is_string($rawKey) && $rawKey !== ''
            ? trim($rawKey)
            : ($fallbackIndex <= 26 ? chr(64 + $fallbackIndex) : ('OPT' . $fallbackIndex));

        $html = '';

        if (is_array($option)) {
            $originalKey = trim((string)($option['key'] ?? $originalKey));
            $html = trim((string)($option['html'] ?? $option['text'] ?? $option['label'] ?? ''));
        } else {
            $html = trim((string)$option);
        }

        if ($originalKey === '' || $html === '') {
            continue;
        }

        $options[] = [
            'submit_value' => $originalKey,
            'html'         => karteiNormalizeMathMarkup($html),
        ];
    }

    shuffle($options);

    $displayLetters = range('A', 'Z');
    foreach ($options as $idx => &$option) {
        $option['display_key'] = $displayLetters[$idx] ?? ('O' . ($idx + 1));
    }
    unset($option);

    return $options;
}

function karteiFormatQuestionForClient(array $question, array $questionStats): array
{
    return [
        'id'             => (int)$question['id'],
        'question_type'  => (string)$question['question_type'],
        'question_html'  => karteiNormalizeMathMarkup((string)$question['question_html']),
        'topic'          => $question['topic'] !== null ? trim((string)$question['topic']) : '',
        'source_label'   => $question['source_label'] !== null ? (string)$question['source_label'] : '',
        'question_stats' => $questionStats,
        'options'        => karteiBuildClientOptions($question),
    ];
}

function karteiBuildDeckSnapshot(mysqli $conn, string $selectedTopic): array
{
    $questions = karteiLoadActiveQuestions($conn, $selectedTopic);
    $questionIds = array_map(static fn(array $question): int => (int)$question['id'], $questions);
    $historyByQuestion = karteiLoadHistoryByQuestion($conn, $questionIds);
    $stats = karteiAggregateStats($conn, $selectedTopic);

    $nowTs = time();
    $ranked = [];
    $newCount = 0;
    $dueCount = 0;

    foreach ($questions as $question) {
        $qid = (int)$question['id'];
        $historyRows = $historyByQuestion[$qid] ?? [];
        $meta = karteiComputeQuestionMeta($historyRows, $nowTs);
        $questionStats = karteiBuildQuestionStats($historyRows);

        if ($meta['never_answered']) {
            $newCount++;
        }
        if ($meta['due_now']) {
            $dueCount++;
        }

        $ranked[] = [
            'question'       => $question,
            'meta'           => $meta,
            'question_stats' => $questionStats,
        ];
    }

    usort($ranked, static function (array $a, array $b): int {
        $priorityCompare = $b['meta']['priority'] <=> $a['meta']['priority'];
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }

        return ((int)$a['question']['id']) <=> ((int)$b['question']['id']);
    });

    $stats['total_questions'] = count($questions);
    $stats['new_count'] = $newCount;
    $stats['due_count'] = $dueCount;
    $stats['review_due_count'] = max(0, $dueCount - $newCount);

    return [
        'ranked' => $ranked,
        'stats'  => $stats,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    try {
        $action = (string)($_POST['action'] ?? '');
        $availableTopics = karteiLoadAvailableTopics($sciconn);
        $selectedTopic = karteiResolveSelectedTopic($availableTopics, $_POST['topic'] ?? null);

        if ($action === 'next') {
            $snapshot = karteiBuildDeckSnapshot($sciconn, $selectedTopic);

            $chosenEntry = null;
            if (!empty($snapshot['ranked'])) {
                $chosenEntry = $snapshot['ranked'][0];
            }

            karteiJsonResponse([
                'ok'             => true,
                'selected_topic' => $selectedTopic,
                'question'       => $chosenEntry
                    ? karteiFormatQuestionForClient($chosenEntry['question'], $chosenEntry['question_stats'])
                    : null,
                'stats'          => $snapshot['stats'],
            ]);
        }

        if ($action === 'submit') {
            $questionId = (int)($_POST['question_id'] ?? 0);
            $selectedValuesJson = (string)($_POST['selected_values_json'] ?? '[]');
            $responseTimeMs = max(0, (int)($_POST['response_time_ms'] ?? 0));

            if ($questionId <= 0) {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Ungültige Frage.',
                ], 400);
            }

            if ($selectedTopic === '') {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Kein Topic ausgewählt.',
                ], 400);
            }

            $selectedValues = karteiNormalizeSelection(karteiDecodeJsonArray($selectedValuesJson));

            if ($selectedTopic === KARTEI_EMPTY_TOPIC) {
                $stmt = $sciconn->prepare("
                    SELECT
                        id,
                        correct_answers_json
                    FROM kartei_fragen
                    WHERE id = ?
                      AND is_active = 1
                      AND (topic IS NULL OR TRIM(topic) = '')
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $sciconn->error);
                }

                $stmt->bind_param('i', $questionId);
            } else {
                $stmt = $sciconn->prepare("
                    SELECT
                        id,
                        correct_answers_json
                    FROM kartei_fragen
                    WHERE id = ?
                      AND is_active = 1
                      AND TRIM(COALESCE(topic, '')) = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new RuntimeException('Frage konnte nicht vorbereitet werden: ' . $sciconn->error);
                }

                $stmt->bind_param('is', $questionId, $selectedTopic);
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $questionRow = $res->fetch_assoc();
            $stmt->close();

            if (!$questionRow) {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Frage nicht gefunden.',
                ], 404);
            }

            $correctValues = karteiNormalizeSelection(
                karteiDecodeJsonArray((string)$questionRow['correct_answers_json'])
            );

            $isCorrect = ($selectedValues === $correctValues) ? 1 : 0;
            $selectedValuesStoredJson = json_encode($selectedValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $insert = $sciconn->prepare("
                INSERT INTO kartei_antworten (
                    question_id,
                    selected_answers_json,
                    is_correct,
                    response_time_ms,
                    answered_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");

            if (!$insert) {
                throw new RuntimeException('Antwort konnte nicht vorbereitet werden: ' . $sciconn->error);
            }

            $insert->bind_param(
                'isii',
                $questionId,
                $selectedValuesStoredJson,
                $isCorrect,
                $responseTimeMs
            );
            $insert->execute();
            $insert->close();

            $snapshot = karteiBuildDeckSnapshot($sciconn, $selectedTopic);

            karteiJsonResponse([
                'ok'             => true,
                'selected_topic' => $selectedTopic,
                'is_correct'     => (bool)$isCorrect,
                'correct_values' => $correctValues,
                'stats'          => $snapshot['stats'],
            ]);
        }

        if ($action === 'delete') {
            $questionId = (int)($_POST['question_id'] ?? 0);

            if ($questionId <= 0) {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Ungültige Frage.',
                ], 400);
            }

            if ($selectedTopic === '') {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Kein Topic ausgewählt.',
                ], 400);
            }

            if ($selectedTopic === KARTEI_EMPTY_TOPIC) {
                $stmt = $sciconn->prepare("
                    UPDATE kartei_fragen
                    SET is_active = 0
                    WHERE id = ?
                      AND is_active = 1
                      AND (topic IS NULL OR TRIM(topic) = '')
                ");

                if (!$stmt) {
                    throw new RuntimeException('Löschen konnte nicht vorbereitet werden: ' . $sciconn->error);
                }

                $stmt->bind_param('i', $questionId);
            } else {
                $stmt = $sciconn->prepare("
                    UPDATE kartei_fragen
                    SET is_active = 0
                    WHERE id = ?
                      AND is_active = 1
                      AND TRIM(COALESCE(topic, '')) = ?
                ");

                if (!$stmt) {
                    throw new RuntimeException('Löschen konnte nicht vorbereitet werden: ' . $sciconn->error);
                }

                $stmt->bind_param('is', $questionId, $selectedTopic);
            }

            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected < 1) {
                karteiJsonResponse([
                    'ok'      => false,
                    'message' => 'Frage nicht gefunden.',
                ], 404);
            }

            $snapshot = karteiBuildDeckSnapshot($sciconn, $selectedTopic);

            $chosenEntry = null;
            if (!empty($snapshot['ranked'])) {
                $chosenEntry = $snapshot['ranked'][0];
            }

            karteiJsonResponse([
                'ok'             => true,
                'selected_topic' => $selectedTopic,
                'question'       => $chosenEntry
                    ? karteiFormatQuestionForClient($chosenEntry['question'], $chosenEntry['question_stats'])
                    : null,
                'stats'          => $snapshot['stats'],
            ]);
        }

        karteiJsonResponse([
            'ok'      => false,
            'message' => 'Unbekannte Aktion.',
        ], 400);
    } catch (Throwable $e) {
        karteiJsonResponse([
            'ok'      => false,
            'message' => 'Fehler: ' . $e->getMessage(),
        ], 500);
    }
}

$availableTopics = karteiLoadAvailableTopics($sciconn);
$initialTopic = $availableTopics[0] ?? '';

$page_title = 'Karteikarten';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="content-wrap kartei-shell">
    <div class="kartei-topbar">
        <div class="kartei-header-row">
            <div class="kartei-header-topic">
                <select id="topicSelect" class="kategorie-select" <?= empty($availableTopics) ? 'disabled' : '' ?>>
                    <?php if (empty($availableTopics)): ?>
                        <option value="">Keine Topics</option>
                    <?php else: ?>
                        <?php foreach ($availableTopics as $topicValue): ?>
                            <option
                                value="<?= htmlspecialchars($topicValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                <?= $topicValue === $initialTopic ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars(karteiTopicLabel($topicValue), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="kartei-header-divider" aria-hidden="true"></div>

            <div class="kartei-header-stats">
                <div class="kartei-statsline" id="karteiStats"></div>
            </div>
        </div>
    </div>

    <div class="kartei-stage">
        <div class="kartei-feedback hidden" id="karteiFeedback" aria-live="polite"></div>

        <div class="kartei-panel" id="karteiPanel">
            <div class="kartei-panel-head kartei-header-row kartei-header-row-with-action">
                <div class="kartei-header-main">
                    <div class="kartei-header-topic">
                        <div class="kartei-panel-meta" id="questionMeta">Lade Frage …</div>
                    </div>

                    <div class="kartei-header-divider" aria-hidden="true"></div>

                    <div class="kartei-header-stats">
                        <div class="kartei-statsline kartei-statsline-compact" id="questionStats"></div>
                    </div>
                </div>

                <div class="kartei-header-actions">
                    <button type="button" class="kartei-btn-danger" id="deleteBtn">Löschen</button>
                </div>
            </div>

            <div class="kartei-question-wrap">
                <div class="kartei-question-body" id="questionBody">Lade Frage …</div>
            </div>

            <div class="kartei-options" id="answerGrid"></div>

            <div class="kartei-bottombar">
                <button type="button" class="kartei-btn kartei-btn-primary hidden" id="submitBtn">Antwort absenden</button>
            </div>
        </div>

        <div class="kartei-empty hidden" id="emptyState">
            Keine aktiven Fragen für dieses Topic vorhanden.
        </div>
    </div>

    <div class="status-msg hidden" id="globalStatus"></div>
</div>

<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)'], ['$', '$']],
        displayMath: [['\\[', '\\]'], ['$$', '$$']]
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
    const AUTO_NEXT_DELAY_MS = 2000;

    const els = {
        topicSelect: document.getElementById('topicSelect'),
        stats: document.getElementById('karteiStats'),
        panel: document.getElementById('karteiPanel'),
        feedback: document.getElementById('karteiFeedback'),
        questionMeta: document.getElementById('questionMeta'),
        questionStats: document.getElementById('questionStats'),
        questionBody: document.getElementById('questionBody'),
        answerGrid: document.getElementById('answerGrid'),
        submitBtn: document.getElementById('submitBtn'),
        deleteBtn: document.getElementById('deleteBtn'),
        emptyState: document.getElementById('emptyState'),
        globalStatus: document.getElementById('globalStatus')
    };

    const state = {
        topic: els.topicSelect ? String(els.topicSelect.value || '') : '',
        question: null,
        selected: new Set(),
        correctValues: new Set(),
        locked: false,
        reviewMode: false,
        startedAt: 0
    };

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatPercent(value) {
        return Number(value || 0).toFixed(1).replace('.', ',') + ' %';
    }

    function showStatus(message, isError = false) {
        els.globalStatus.textContent = message;
        els.globalStatus.classList.remove('hidden');
        els.globalStatus.style.borderLeftColor = isError ? '#c0392b' : 'var(--primary)';
    }

    function hideStatus() {
        els.globalStatus.classList.add('hidden');
        els.globalStatus.textContent = '';
    }

    async function api(action, payload = {}) {
        const params = new URLSearchParams();
        params.set('ajax', '1');
        params.set('action', action);

        Object.entries(payload).forEach(([key, value]) => {
            if (value === undefined || value === null) {
                return;
            }
            params.set(key, String(value));
        });

        const response = await fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        });

        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            data = {};
        }

        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Serverfehler.');
        }

        return data;
    }

    function typesetMath(scope, tries = 0) {
        if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
            if (typeof window.MathJax.typesetClear === 'function') {
                window.MathJax.typesetClear([scope]);
            }
            window.MathJax.typesetPromise(scope ? [scope] : undefined).catch(() => {});
            return;
        }

        if (tries < 30) {
            window.setTimeout(() => typesetMath(scope, tries + 1), 150);
        }
    }

    function syncSelectedTopic(selectedTopic) {
        const normalized = String(selectedTopic || '');
        state.topic = normalized;

        if (els.topicSelect && els.topicSelect.value !== normalized) {
            els.topicSelect.value = normalized;
        }
    }

    function renderStats(stats) {
        if (!stats) {
            els.stats.innerHTML = '';
            return;
        }

        els.stats.innerHTML = `
            <div class="kartei-stat"><span class="kartei-stat-label">Fragen</span><span class="kartei-stat-value">${stats.total_questions}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Neu</span><span class="kartei-stat-value">${stats.new_count}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Fällig</span><span class="kartei-stat-value">${stats.due_count}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Richtig</span><span class="kartei-stat-value">${stats.correct_answers}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Falsch</span><span class="kartei-stat-value">${stats.wrong_answers}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Quote</span><span class="kartei-stat-value">${formatPercent(stats.accuracy_pct)}</span></div>
        `;
    }

    function renderQuestionStats(stats) {
        const questionStats = stats || {
            attempts: 0,
            correct_answers: 0,
            wrong_answers: 0,
            accuracy_pct: 0
        };

        els.questionStats.innerHTML = `
            <div class="kartei-stat"><span class="kartei-stat-label">Versuche</span><span class="kartei-stat-value">${questionStats.attempts}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Richtig</span><span class="kartei-stat-value">${questionStats.correct_answers}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Falsch</span><span class="kartei-stat-value">${questionStats.wrong_answers}</span></div>
            <div class="kartei-stat"><span class="kartei-stat-label">Quote</span><span class="kartei-stat-value">${formatPercent(questionStats.accuracy_pct)}</span></div>
        `;
    }

    function updateActionButtons() {
        const hasQuestion = Boolean(state.question);

        if (!hasQuestion) {
            els.submitBtn.textContent = 'Antwort absenden';
            els.submitBtn.classList.add('hidden');
            els.submitBtn.disabled = true;
            els.deleteBtn.disabled = true;
            return;
        }

        if (state.reviewMode) {
            els.submitBtn.textContent = 'Weiter';
            els.submitBtn.classList.remove('hidden');
            els.submitBtn.disabled = false;
            els.deleteBtn.disabled = true;
            return;
        }

        const isMultiple = state.question.question_type === 'multiple';
        els.submitBtn.textContent = 'Antwort absenden';
        els.submitBtn.classList.toggle('hidden', !isMultiple);
        els.submitBtn.disabled = !isMultiple || state.locked || state.selected.size === 0;
        els.deleteBtn.disabled = state.locked;
    }

    function applyReviewClasses(button) {
        const submitValue = button.dataset.submitValue || '';
        const isSelected = state.selected.has(submitValue);
        const isCorrect = state.correctValues.has(submitValue);

        button.classList.remove(
            'is-answer-correct',
            'is-answer-correct-selected',
            'is-answer-wrong-selected'
        );

        if (!state.reviewMode) {
            return;
        }

        if (isCorrect && isSelected) {
            button.classList.add('is-answer-correct-selected');
            return;
        }

        if (isCorrect) {
            button.classList.add('is-answer-correct');
            return;
        }

        if (isSelected) {
            button.classList.add('is-answer-wrong-selected');
        }
    }

    function updateSelectionUI() {
        const buttons = els.answerGrid.querySelectorAll('.kartei-option');

        buttons.forEach((button) => {
            const submitValue = button.dataset.submitValue || '';
            const isSelected = state.selected.has(submitValue);

            button.classList.toggle('is-selected', isSelected && !state.reviewMode);
            button.classList.toggle('is-locked', state.locked);
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            button.disabled = state.locked;
            applyReviewClasses(button);
        });

        updateActionButtons();
    }

    function showFeedback(isCorrect) {
        els.feedback.textContent = isCorrect ? '✓' : '✗';
        els.feedback.classList.remove('hidden');
        els.feedback.classList.toggle('is-correct', isCorrect);
        els.feedback.classList.toggle('is-wrong', !isCorrect);
    }

    function hideFeedback() {
        els.feedback.classList.add('hidden');
        els.feedback.classList.remove('is-correct', 'is-wrong');
        els.feedback.textContent = '';
    }

    function resetQuestionState() {
        state.selected = new Set();
        state.correctValues = new Set();
        state.locked = false;
        state.reviewMode = false;
        state.startedAt = Date.now();
    }

    function renderQuestion(question) {
        state.question = question;
        resetQuestionState();

        hideFeedback();

        if (!question) {
            els.panel.classList.add('hidden');
            els.emptyState.classList.remove('hidden');
            els.questionMeta.textContent = '';
            renderQuestionStats(null);
            updateActionButtons();
            return;
        }

        els.panel.classList.remove('hidden');
        els.emptyState.classList.add('hidden');

        const metaParts = [
            question.question_type === 'multiple' ? 'Multiple Choice' : 'Single Choice'
        ];

        if (question.topic) {
            metaParts.push(question.topic);
        }

        metaParts.push('#' + question.id);

        els.questionMeta.textContent = metaParts.join(' · ');
        renderQuestionStats(question.question_stats || null);

        els.questionBody.innerHTML = question.question_html;
        els.answerGrid.innerHTML = '';
        els.answerGrid.className = 'kartei-options';
        els.answerGrid.style.gridTemplateColumns = '';

        if (!Array.isArray(question.options) || question.options.length === 0) {
            els.answerGrid.innerHTML = '<div class="kartei-no-options">Keine Antwortoptionen gefunden.</div>';
            typesetMath(els.panel);
            updateActionButtons();
            return;
        }

        question.options.forEach((option) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'kartei-option';
            button.dataset.submitValue = option.submit_value;
            button.setAttribute('aria-pressed', 'false');
            button.innerHTML = `
                <span class="kartei-option-letter">${escapeHtml(option.display_key)}</span>
                <span class="kartei-option-content">${option.html}</span>
            `;
            button.addEventListener('click', () => handleOptionClick(option.submit_value));
            els.answerGrid.appendChild(button);
        });

        updateSelectionUI();
        typesetMath(els.panel);
    }

    function handleOptionClick(submitValue) {
        if (!state.question || state.locked || state.reviewMode) {
            return;
        }

        const isMultiple = state.question.question_type === 'multiple';

        if (isMultiple) {
            if (state.selected.has(submitValue)) {
                state.selected.delete(submitValue);
            } else {
                state.selected.add(submitValue);
            }

            updateSelectionUI();
            return;
        }

        state.selected = new Set([submitValue]);
        updateSelectionUI();

        window.setTimeout(() => {
            submitCurrentSelection();
        }, 100);
    }

    async function loadNextQuestion() {
        hideStatus();
        hideFeedback();

        try {
            const data = await api('next', {
                topic: state.topic
            });

            syncSelectedTopic(data.selected_topic);
            renderStats(data.stats);
            renderQuestion(data.question);
        } catch (error) {
            showStatus(error.message, true);
        }
    }

    async function submitCurrentSelection() {
        if (!state.question || state.locked || state.reviewMode || state.selected.size === 0) {
            return;
        }

        state.locked = true;
        updateSelectionUI();
        hideStatus();

        try {
            const data = await api('submit', {
                topic: state.topic,
                question_id: state.question.id,
                selected_values_json: JSON.stringify(Array.from(state.selected)),
                response_time_ms: Math.max(0, Date.now() - state.startedAt)
            });

            syncSelectedTopic(data.selected_topic);
            renderStats(data.stats);

            if (Boolean(data.is_correct)) {
                showFeedback(true);

                window.setTimeout(() => {
                    loadNextQuestion();
                }, AUTO_NEXT_DELAY_MS);

                return;
            }

            state.correctValues = new Set(Array.isArray(data.correct_values) ? data.correct_values : []);
            state.reviewMode = true;
            showFeedback(false);
            updateSelectionUI();
        } catch (error) {
            state.locked = false;
            updateSelectionUI();
            showStatus(error.message, true);
        }
    }

    async function deleteCurrentQuestion() {
        if (!state.question || state.locked || state.reviewMode) {
            return;
        }

        const confirmed = window.confirm('Frage wirklich löschen?');
        if (!confirmed) {
            return;
        }

        state.locked = true;
        updateSelectionUI();
        hideStatus();
        hideFeedback();

        try {
            const data = await api('delete', {
                topic: state.topic,
                question_id: state.question.id
            });

            syncSelectedTopic(data.selected_topic);
            renderStats(data.stats);
            renderQuestion(data.question || null);
            showStatus('Frage gelöscht.');
        } catch (error) {
            state.locked = false;
            updateSelectionUI();
            showStatus(error.message, true);
        }
    }

    function handlePrimaryButtonClick() {
        if (state.reviewMode) {
            loadNextQuestion();
            return;
        }

        submitCurrentSelection();
    }

    els.submitBtn.addEventListener('click', handlePrimaryButtonClick);
    els.deleteBtn.addEventListener('click', deleteCurrentQuestion);

    if (els.topicSelect) {
        els.topicSelect.addEventListener('change', () => {
            state.topic = String(els.topicSelect.value || '');
            state.question = null;
            state.selected = new Set();
            state.correctValues = new Set();
            state.locked = false;
            state.reviewMode = false;
            hideFeedback();
            hideStatus();
            loadNextQuestion();
        });
    }

    window.addEventListener('load', () => {
        renderQuestionStats(null);
        updateActionButtons();
        loadNextQuestion();
    });
})();
</script>