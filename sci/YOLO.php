<?php
// sci/YOLO.php
// VERSION: YOLO-Web v6 – Bildervergleich + fehlertyp-spezifische Renderauswertung (Fxx)
// Liest ausschließlich die von yolo.py erzeugten Metadaten/CSVs aus dem Archiv
// und stellt Trainingsläufe vergleichbar dar. Keine Schreibzugriffe auf YOLO-Daten.

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

const YOLO_META_ROOT = '/storage/Dokumente/RWTH/Bachelorarbeit/Schaekel/YOLO';
const YOLO_MAX_SELECTED_RUNS = 6;

/* =========================================================
 * Allgemeine Helfer
 * ========================================================= */
function yolo_json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function yolo_read_json(?string $path): array
{
    if ($path === null || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function yolo_latest_glob(string $pattern): ?string
{
    $files = glob($pattern) ?: [];
    $files = array_values(array_filter($files, static fn(string $p): bool => is_file($p)));
    if (!$files) {
        return null;
    }

    usort($files, static function (string $a, string $b): int {
        $ma = @filemtime($a) ?: 0;
        $mb = @filemtime($b) ?: 0;
        if ($ma !== $mb) {
            return $mb <=> $ma;
        }
        return strnatcasecmp(basename($b), basename($a));
    });

    return $files[0];
}

function yolo_first_existing(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function yolo_portable_basename(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = str_replace('\\', '/', $value);
    return basename($value);
}

function yolo_nested(array $data, string $path, mixed $default = null): mixed
{
    $cur = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return $default;
        }
        $cur = $cur[$part];
    }
    return $cur;
}

function yolo_first_nonempty(mixed ...$values): mixed
{
    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        if (is_array($value) && $value === []) {
            continue;
        }
        return $value;
    }
    return null;
}

function yolo_scalar_from_yaml(string $value): mixed
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if ($lower === 'true') return true;
    if ($lower === 'false') return false;
    if (in_array($lower, ['null', 'none', '~'], true)) return null;

    $unquoted = $value;
    if (
        strlen($value) >= 2
        && (($value[0] === '"' && $value[strlen($value) - 1] === '"')
            || ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
    ) {
        $unquoted = substr($value, 1, -1);
    }

    if (is_numeric($unquoted)) {
        return str_contains($unquoted, '.') || stripos($unquoted, 'e') !== false
            ? (float)$unquoted
            : (int)$unquoted;
    }

    // Kleine Inline-Listen aus args.yaml brauchbar machen, ohne YAML-Extension.
    if (str_starts_with($unquoted, '[') && str_ends_with($unquoted, ']')) {
        $inside = trim(substr($unquoted, 1, -1));
        if ($inside === '') return [];
        return array_map(
            static fn(string $v): mixed => yolo_scalar_from_yaml(trim($v)),
            explode(',', $inside)
        );
    }

    return $unquoted;
}

function yolo_read_simple_yaml(?string $path): array
{
    if ($path === null || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $out = [];
    foreach ($lines as $line) {
        if ($line === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        // Nur Top-Level-Keys aus Ultralytics args.yaml.
        if (preg_match('/^([A-Za-z0-9_.\/-]+):\s*(.*)$/u', $line, $m)) {
            $out[$m[1]] = yolo_scalar_from_yaml($m[2]);
        }
    }
    return $out;
}

function yolo_numeric_value(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    // results.csv kommt normalerweise mit Punkt als Dezimaltrenner.
    // Komma wird nur dann als Dezimaltrenner behandelt, wenn kein Punkt vorkommt.
    if (str_contains($value, ',') && !str_contains($value, '.')) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : null;
}

function yolo_detect_csv_delimiter(string $line): string
{
    $best = ',';
    $bestCount = 0;
    foreach ([',', ';', "\t"] as $delimiter) {
        $parts = str_getcsv($line, $delimiter, '"', '');
        $count = count($parts);
        if ($count > $bestCount) {
            $best = $delimiter;
            $bestCount = $count;
        }
    }
    return $best;
}

function yolo_read_results_csv(?string $path): array
{
    $empty = [
        'headers' => [],
        'rows' => [],
        'numeric_columns' => [],
        'epoch_key' => null,
        'epoch_offset' => 0,
        'row_count' => 0,
    ];

    if ($path === null || !is_file($path) || !is_readable($path)) {
        return $empty;
    }

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return $empty;
    }

    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        return $empty;
    }

    $delimiter = yolo_detect_csv_delimiter($firstLine);
    $headers = str_getcsv(rtrim($firstLine, "\r\n"), $delimiter, '"', '');
    if (!$headers) {
        fclose($fh);
        return $empty;
    }

    $headers = array_map(static function ($header): string {
        $header = trim((string)$header);
        return preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    }, $headers);

    $rows = [];
    $stats = [];
    foreach ($headers as $header) {
        $stats[$header] = ['seen' => 0, 'numeric' => 0];
    }

    while (($values = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($values === [null] || $values === []) {
            continue;
        }

        $row = [];
        foreach ($headers as $i => $header) {
            $raw = isset($values[$i]) ? trim((string)$values[$i]) : '';
            if ($raw === '') {
                $row[$header] = null;
                continue;
            }

            $stats[$header]['seen']++;
            $number = yolo_numeric_value($raw);
            if ($number !== null) {
                $stats[$header]['numeric']++;
                $row[$header] = $number;
            } else {
                $row[$header] = $raw;
            }
        }
        $rows[] = $row;
    }
    fclose($fh);

    $epochKey = null;
    foreach ($headers as $header) {
        $normalized = strtolower(trim($header));
        if ($normalized === 'epoch' || str_ends_with($normalized, '/epoch')) {
            $epochKey = $header;
            break;
        }
    }

    $numericColumns = [];
    foreach ($headers as $header) {
        $seen = (int)$stats[$header]['seen'];
        $numeric = (int)$stats[$header]['numeric'];

        // yolo_v7.4 schreibt diese fuenf Spalten absichtlich in JEDEM
        // Semantic-Run. Fuer eine deaktivierte Klasse steht dort NaN statt 0,
        // weil "nicht trainiert" nicht "0 % IoU" bedeutet. Solche komplett
        // inaktiven Spalten wuerden die normale 80-%-Numerik-Erkennung nicht
        // bestehen; sie sollen in der Weboberflaeche trotzdem als bekannte
        // Klassenmetriken auswählbar bleiben.
        $isStableClassIou = preg_match(
            '/^metrics\/iou_(background|defect|edge|text|component)$/i',
            $header
        ) === 1;

        if ($isStableClassIou || ($seen > 0 && ($numeric / $seen) >= 0.80)) {
            $numericColumns[] = $header;
        }
    }

    $epochOffset = 0;
    if ($epochKey !== null && $rows) {
        $firstEpoch = yolo_numeric_value($rows[0][$epochKey] ?? null);
        if ($firstEpoch !== null && abs($firstEpoch) < 0.0000001) {
            $epochOffset = 1;
        }
    }

    return [
        'headers' => $headers,
        'rows' => $rows,
        'numeric_columns' => array_values(array_filter(
            $numericColumns,
            static fn(string $c): bool => $c !== $epochKey
        )),
        'epoch_key' => $epochKey,
        'epoch_offset' => $epochOffset,
        'row_count' => count($rows),
    ];
}

function yolo_metrics_from_counts(array $counts): array
{
    $intersection = isset($counts['intersection']) && is_numeric($counts['intersection'])
        ? (float)$counts['intersection']
        : null;
    $union = isset($counts['union']) && is_numeric($counts['union'])
        ? (float)$counts['union']
        : null;
    $prediction = isset($counts['prediction']) && is_numeric($counts['prediction'])
        ? (float)$counts['prediction']
        : null;
    $groundTruth = isset($counts['ground_truth']) && is_numeric($counts['ground_truth'])
        ? (float)$counts['ground_truth']
        : null;

    if ($intersection === null || $union === null || $prediction === null || $groundTruth === null) {
        return [];
    }

    $iou = null;
    if ($union > 0.0) {
        $iou = $intersection / $union;
    } elseif ($prediction == 0.0 && $groundTruth == 0.0) {
        $iou = 1.0;
    }

    $denominator = $prediction + $groundTruth;
    $dice = $denominator > 0.0 ? (2.0 * $intersection / $denominator) : 1.0;

    $out = ['dice' => $dice];
    if ($iou !== null) $out['iou'] = $iou;
    return $out;
}

function yolo_flatten_aggregate_metrics(array $report, string $prefix = ''): array
{
    $aggregate = $report['aggregate_metrics'] ?? [];
    if (!is_array($aggregate)) {
        $aggregate = [];
    }

    // Einige ältere Reports enthalten eher Zaehler als bereits berechnete
    // Metriken. Wenn Ground-Truth-Zaehler vorhanden sind, IoU/Dice daraus
    // ableiten. Bei Auswertungen ohne Ground Truth bleiben beide Strukturen
    // absichtlich leer.
    if ($aggregate === []) {
        $counts = $report['aggregate_counts'] ?? [];
        if (is_array($counts)) {
            foreach ($counts as $className => $classCounts) {
                if (!is_array($classCounts)) continue;
                $derived = yolo_metrics_from_counts($classCounts);
                if ($derived !== []) $aggregate[$className] = $derived;
            }
        }
    }

    $flat = [];
    foreach ($aggregate as $className => $metrics) {
        if (!is_array($metrics)) continue;
        foreach ($metrics as $metricName => $value) {
            if (!is_numeric($value)) continue;
            $key = trim($prefix);
            if ($key !== '') $key .= ' / ';
            $key .= strtolower((string)$className) . '.' . strtolower((string)$metricName);
            $flat[$key] = (float)$value;
        }
    }
    return $flat;
}

function yolo_read_milestones(string $trainingDir): array
{
    $snapshotRoot = $trainingDir . '/milestones/snapshots';
    if (!is_dir($snapshotRoot) || !is_readable($snapshotRoot)) {
        return ['points' => [], 'metric_keys' => []];
    }

    $pointsByEpoch = [];
    $metricKeys = [];

    $entries = scandir($snapshotRoot) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!preg_match('/epoch[_-]?(\d+)/i', $entry, $m)) continue;

        $epoch = (int)$m[1];
        $epochDir = $snapshotRoot . '/' . $entry;
        if (!is_dir($epochDir)) continue;

        $jsonFiles = [];
        $direct = $epochDir . '/auswertung.json';
        if (is_file($direct)) {
            $jsonFiles[] = $direct;
        }

        // v7.4: epoch_XXX/{real_test,render_val,render_test}/auswertung.json
        $children = scandir($epochDir) ?: [];
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') continue;
            $candidate = $epochDir . '/' . $child . '/auswertung.json';
            if (is_file($candidate)) {
                $jsonFiles[] = $candidate;
            }
        }

        if (!$jsonFiles) continue;

        $point = $pointsByEpoch[$epoch] ?? ['epoch' => $epoch, 'metrics' => []];
        foreach ($jsonFiles as $jsonPath) {
            $report = yolo_read_json($jsonPath);
            if (!$report) continue;

            $parent = basename(dirname($jsonPath));
            $source = ($parent === $entry) ? 'test' : $parent;
            $flat = yolo_flatten_aggregate_metrics($report, $source);
            foreach ($flat as $key => $value) {
                $point['metrics'][$key] = $value;
                $metricKeys[$key] = true;
            }
        }
        $pointsByEpoch[$epoch] = $point;
    }

    ksort($pointsByEpoch, SORT_NUMERIC);
    $keys = array_keys($metricKeys);
    natcasesort($keys);

    return [
        'points' => array_values($pointsByEpoch),
        'metric_keys' => array_values($keys),
    ];
}

function yolo_normalize_per_defect_type_metrics(array $report): array
{
    $raw = $report['per_defect_type_metrics'] ?? [];
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $defectId => $values) {
        $defectId = strtoupper(trim((string)$defectId));
        if (!preg_match('/^F\d{2}$/', $defectId) || !is_array($values)) {
            continue;
        }

        $entry = [];
        foreach (['iou', 'dice', 'recall'] as $metric) {
            $value = $values[$metric] ?? null;
            if (is_numeric($value)) {
                $entry[$metric] = (float)$value;
            }
        }

        $imagesWithGt = $values['images_with_gt'] ?? null;
        if (is_numeric($imagesWithGt)) {
            $entry['images_with_gt'] = (int)$imagesWithGt;
        }

        $counts = $values['counts'] ?? null;
        if (is_array($counts)) {
            $normalizedCounts = [];
            foreach (['intersection', 'union', 'prediction', 'ground_truth'] as $key) {
                if (isset($counts[$key]) && is_numeric($counts[$key])) {
                    $normalizedCounts[$key] = (int)$counts[$key];
                }
            }
            if ($normalizedCounts) {
                $entry['counts'] = $normalizedCounts;
            }
        }

        if (array_key_exists('other_defect_pixels_ignored', $values)) {
            $entry['other_defect_pixels_ignored'] = (bool)$values['other_defect_pixels_ignored'];
        }

        if ($entry) {
            $out[$defectId] = $entry;
        }
    }

    uksort($out, static function (string $a, string $b): int {
        $na = (int)substr($a, 1);
        $nb = (int)substr($b, 1);
        return $na <=> $nb;
    });

    return $out;
}

function yolo_read_final_evaluations(string $runRoot): array
{
    $sources = [
        'real_test'   => $runRoot . '/Auswertung_Test/auswertung.json',
        'render_val'  => $runRoot . '/Auswertung_Render_Val/auswertung.json',
        'render_test' => $runRoot . '/Auswertung_Render_Test/auswertung.json',
    ];

    $reports = [];
    $flat = [];
    $perDefectTypes = [];

    foreach ($sources as $source => $path) {
        $report = yolo_read_json($path);
        if (!$report) continue;

        $reports[$source] = [
            'epoch_label' => $report['epoch_label'] ?? null,
            'ground_truth_enabled' => $report['ground_truth_enabled'] ?? null,
            'successful_images' => $report['successful_images'] ?? null,
            'failed_images' => is_array($report['failed_images'] ?? null)
                ? count($report['failed_images'])
                : null,
            'active_detail_classes' => is_array($report['active_detail_classes'] ?? null)
                ? array_values($report['active_detail_classes'])
                : [],
            'created_at' => $report['created_at'] ?? null,
            'per_defect_type_metric_note' => $report['per_defect_type_metric_note'] ?? null,
        ];

        foreach (yolo_flatten_aggregate_metrics($report, $source) as $key => $value) {
            $flat[$key] = $value;
        }

        // v7.6: Fxx-Einzelmasken existieren nur als Diagnose-GT auf Renderdaten.
        // Sie bleiben im Training gemeinsam die eine Klasse "Fehler".
        if ($source === 'render_val' || $source === 'render_test') {
            $normalized = yolo_normalize_per_defect_type_metrics($report);
            if ($normalized) {
                $perDefectTypes[$source] = $normalized;
            }
        }
    }

    return [
        'reports' => $reports,
        'metrics' => $flat,
        'per_defect_types' => $perDefectTypes,
    ];
}

/* =========================================================
 * Finale reale Testbilder
 * ========================================================= */
function yolo_final_test_image_identity(string $relativePath): array
{
    $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
    $dir = dirname($relativePath);
    $stem = pathinfo($relativePath, PATHINFO_FILENAME);
    $stem = preg_replace('/_2x2$/i', '', $stem) ?? $stem;

    $id = ($dir !== '.' && $dir !== '')
        ? $dir . '/' . $stem
        : $stem;

    return [
        'id' => strtolower($id),
        'label' => $stem,
    ];
}

function yolo_final_test_images(string $runRoot, bool $includePath = false): array
{
    $base = $runRoot . '/Auswertung_Test';
    if (!is_dir($base) || !is_readable($base)) {
        return [];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    $found = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());
            if (!in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if (!preg_match('/_2x2\.(?:jpe?g|png|webp)$/i', $filename)) {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($base))), '/');
            $identity = yolo_final_test_image_identity($relative);
            $id = $identity['id'];

            // Gleiche Bild-ID nur einmal; bei Dubletten gewinnt die neuere Datei.
            $mtime = @filemtime($fullPath) ?: 0;
            if (isset($found[$id]) && (int)$found[$id]['mtime'] >= $mtime) {
                continue;
            }

            $entry = [
                'id' => $id,
                'label' => $identity['label'],
                'mtime' => $mtime,
            ];
            if ($includePath) {
                $entry['path'] = $fullPath;
            }
            $found[$id] = $entry;
        }
    } catch (Throwable $e) {
        return [];
    }

    uasort($found, static function (array $a, array $b): int {
        return strnatcasecmp((string)$a['label'], (string)$b['label']);
    });

    return array_values($found);
}

function yolo_public_final_test_images(string $runRoot): array
{
    return array_map(
        static fn(array $item): array => [
            'id' => (string)$item['id'],
            'label' => (string)$item['label'],
        ],
        yolo_final_test_images($runRoot, false)
    );
}

function yolo_find_final_test_image(string $runRoot, string $imageId): ?string
{
    $imageId = strtolower(trim($imageId));
    if ($imageId === '') {
        return null;
    }

    foreach (yolo_final_test_images($runRoot, true) as $item) {
        if (($item['id'] ?? '') === $imageId) {
            $path = $item['path'] ?? null;
            return is_string($path) && is_file($path) && is_readable($path) ? $path : null;
        }
    }
    return null;
}

function yolo_stream_test_image(string $path): void
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $contentTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    if (!isset($contentTypes[$extension])) {
        http_response_code(415);
        exit;
    }

    header('Content-Type: ' . $contentTypes[$extension]);
    header('Content-Length: ' . (string)(@filesize($path) ?: 0));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/* =========================================================
 * Run-Erkennung
 * ========================================================= */
function yolo_catalog_entry(
    string $root,
    string $runRoot,
    string $trainingDir,
    string $name,
    string $kind,
    string $layout
): array {
    $relative = ltrim(str_replace('\\', '/', substr($runRoot, strlen($root))), '/');
    $trainingRelative = ltrim(str_replace('\\', '/', substr($trainingDir, strlen($root))), '/');
    $key = substr(hash('sha256', $layout . '|' . $relative . '|' . $trainingRelative . '|' . $kind), 0, 20);

    $resultsPath = is_file($trainingDir . '/results.csv') ? $trainingDir . '/results.csv' : null;
    $argsPath = is_file($trainingDir . '/args.yaml') ? $trainingDir . '/args.yaml' : null;
    $manifestPath = yolo_latest_glob($runRoot . '/run_manifest_*.json');
    $statePath = yolo_latest_glob($trainingDir . '/training_state*.json');
    $datasetPath = yolo_latest_glob($runRoot . '/dataset_summary_*.json');
    $semanticValidationPath = yolo_first_existing([
        $trainingDir . '/semantic_validation_metrics.json',
        $trainingDir . '/validation/semantic_validation_metrics.json',
    ]);

    $mtimeCandidates = array_filter([
        $resultsPath,
        $argsPath,
        $manifestPath,
        $statePath,
        $datasetPath,
        $semanticValidationPath,
    ]);
    $mtime = @filemtime($runRoot) ?: 0;
    foreach ($mtimeCandidates as $candidate) {
        $mtime = max($mtime, @filemtime((string)$candidate) ?: 0);
    }

    return [
        'key' => $key,
        'name' => $name,
        'kind' => $kind,
        'layout' => $layout,
        'relative' => $relative,
        'training_relative' => $trainingRelative,
        'run_root' => $runRoot,
        'training_dir' => $trainingDir,
        'results_path' => $resultsPath,
        'args_path' => $argsPath,
        'manifest_path' => $manifestPath,
        'state_path' => $statePath,
        'dataset_path' => $datasetPath,
        'semantic_validation_path' => $semanticValidationPath,
        'has_results' => $resultsPath !== null,
        'has_manifest' => $manifestPath !== null,
        'has_milestones' => is_dir($trainingDir . '/milestones/snapshots'),
        'mtime' => $mtime,
    ];
}

function yolo_discover_runs(string $root): array
{
    if (!is_dir($root) || !is_readable($root)) {
        return [];
    }

    $catalog = [];
    $top = scandir($root) ?: [];

    // Neue zentrale Struktur: YOLO/v7_3_003/detail, Run-Manifest eine Ebene höher.
    foreach ($top as $name) {
        if ($name === '.' || $name === '..' || $name === 'runs_semantic') continue;
        if (!preg_match('/^v\d+(?:_\d+)+$/i', $name)) continue;

        $runRoot = $root . '/' . $name;
        if (!is_dir($runRoot)) continue;

        $detailDir = $runRoot . '/detail';
        $hasRunMetadata = yolo_latest_glob($runRoot . '/run_manifest_*.json') !== null
            || yolo_latest_glob($runRoot . '/dataset_summary_*.json') !== null;

        if (is_dir($detailDir) || $hasRunMetadata) {
            $entry = yolo_catalog_entry(
                $root,
                $runRoot,
                $detailDir,
                $name,
                'detail',
                'modern'
            );
            $catalog[$entry['key']] = $entry;
        }

        $componentDir = $runRoot . '/component';
        if (
            is_dir($componentDir)
            && (is_file($componentDir . '/results.csv')
                || is_file($componentDir . '/args.yaml')
                || yolo_latest_glob($componentDir . '/training_state*.json') !== null)
        ) {
            $entry = yolo_catalog_entry(
                $root,
                $runRoot,
                $componentDir,
                $name . ' · Bauteil',
                'component',
                'modern'
            );
            $catalog[$entry['key']] = $entry;
        }
    }

    // Legacy-Laeufe unter YOLO/runs_semantic werden absichtlich nicht mehr
    // in den Katalog aufgenommen. Die Weboberflaeche zeigt nur die zentralen
    // Run-Ordner YOLO/vX_X_XXX.

    uasort($catalog, static function (array $a, array $b): int {
        return strnatcasecmp((string)$b['name'], (string)$a['name']);
    });

    return $catalog;
}

function yolo_public_catalog(array $catalog): array
{
    $out = [];
    foreach ($catalog as $entry) {
        $out[] = [
            'key' => $entry['key'],
            'name' => $entry['name'],
            'kind' => $entry['kind'],
            'layout' => $entry['layout'],
            'has_results' => $entry['has_results'],
            'has_manifest' => $entry['has_manifest'],
            'has_milestones' => $entry['has_milestones'],
            'mtime' => $entry['mtime'],
        ];
    }
    return $out;
}

function yolo_default_keys(array $catalog, int $count = 2): array
{
    $keys = [];

    // Für den Start direkt zwei tatsächlich vergleichbare Detail-Verläufe wählen.
    foreach ($catalog as $entry) {
        if ($entry['kind'] === 'detail' && $entry['has_results']) {
            $keys[] = $entry['key'];
            if (count($keys) >= $count) return $keys;
        }
    }

    foreach ($catalog as $entry) {
        if ($entry['kind'] === 'detail' && !in_array($entry['key'], $keys, true)) {
            $keys[] = $entry['key'];
            if (count($keys) >= $count) return $keys;
        }
    }

    return $keys;
}

/* =========================================================
 * Run laden / für Browser verdichten
 * ========================================================= */
function yolo_format_classes(array $maskConfiguration, string $kind): string
{
    if ($kind === 'component') {
        return 'Hintergrund, Bauteil';
    }

    $trainingClasses = $maskConfiguration['training_detail_classes'] ?? null;
    if (is_array($trainingClasses) && $trainingClasses) {
        $values = array_values(array_map('strval', $trainingClasses));
        return implode(', ', array_values(array_unique($values)));
    }

    $classes = ['Hintergrund'];
    if (!empty($maskConfiguration['train_defect_class'])) $classes[] = 'Fehler';
    if (!empty($maskConfiguration['train_edge_class'])) $classes[] = 'Kante';
    if (!empty($maskConfiguration['train_text_class'])) $classes[] = 'Schrift';
    return count($classes) > 1 ? implode(', ', $classes) : '—';
}

function yolo_format_source_runs(mixed $value): string
{
    if (is_array($value)) {
        $parts = [];
        foreach ($value as $v) {
            if (is_scalar($v) && trim((string)$v) !== '') $parts[] = trim((string)$v);
        }
        return $parts ? implode(', ', $parts) : '—';
    }
    $text = trim((string)$value);
    return $text !== '' ? $text : '—';
}

function yolo_format_dice_configuration(array $lossConfiguration): string
{
    if (!$lossConfiguration) return '—';
    $enabled = $lossConfiguration['weighted_dice_enabled'] ?? null;
    if ($enabled === false) return 'nein';
    if ($enabled !== true) return '—';

    $weights = $lossConfiguration['dice_class_weights_local_active']
        ?? $lossConfiguration['dice_class_weights_global']
        ?? [];

    $parts = [];
    if (is_array($weights)) {
        foreach ($weights as $key => $value) {
            if (is_numeric($value)) {
                $parts[] = $key . ':' . rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
            }
        }
    }

    return 'ja' . ($parts ? ' · ' . implode(', ', $parts) : '');
}

function yolo_result_last_epoch(array $results): ?int
{
    $rowCount = (int)($results['row_count'] ?? 0);
    if ($rowCount <= 0) return null;

    $epochKey = $results['epoch_key'] ?? null;
    if (!is_string($epochKey) || $epochKey === '') {
        return $rowCount;
    }

    $max = null;
    $offset = (int)($results['epoch_offset'] ?? 0);
    foreach ($results['rows'] as $row) {
        $value = yolo_numeric_value($row[$epochKey] ?? null);
        if ($value === null) continue;
        $epoch = (int)round($value) + $offset;
        $max = $max === null ? $epoch : max($max, $epoch);
    }
    return $max ?? $rowCount;
}

function yolo_load_run(array $entry): array
{
    $manifest = yolo_read_json($entry['manifest_path']);
    $state = yolo_read_json($entry['state_path']);
    $dataset = yolo_read_json($entry['dataset_path']);
    $semanticValidation = yolo_read_json($entry['semantic_validation_path']);
    $args = yolo_read_simple_yaml($entry['args_path']);
    $results = yolo_read_results_csv($entry['results_path']);
    $milestones = yolo_read_milestones($entry['training_dir']);
    $final = $entry['kind'] === 'detail'
        ? yolo_read_final_evaluations($entry['run_root'])
        : ['reports' => [], 'metrics' => [], 'per_defect_types' => []];

    $maskConfiguration = yolo_first_nonempty(
        $manifest['mask_configuration'] ?? null,
        $dataset['mask_configuration'] ?? null,
        []
    );
    if (!is_array($maskConfiguration)) $maskConfiguration = [];

    $lossConfiguration = yolo_first_nonempty(
        $manifest['loss_configuration'] ?? null,
        $dataset['loss_configuration'] ?? null,
        []
    );
    if (!is_array($lossConfiguration)) $lossConfiguration = [];

    $realValidation = $dataset['real_validation'] ?? [];
    if (!is_array($realValidation)) $realValidation = [];

    $sourceRuns = yolo_first_nonempty(
        $manifest['source_runs'] ?? null,
        $dataset['source_runs'] ?? null,
        $state['source_run_names'] ?? null,
        null
    );

    $modelRaw = yolo_first_nonempty(
        $args['model'] ?? null,
        $manifest['base_model'] ?? null,
        null
    );
    $model = yolo_portable_basename($modelRaw);
    if ($model === '') $model = '—';

    $status = yolo_first_nonempty(
        $state['status'] ?? null,
        ($results['row_count'] ?? 0) > 0 ? 'Daten vorhanden' : null,
        $entry['has_manifest'] ? 'vorbereitet' : null,
        '—'
    );

    $epochsRequested = yolo_first_nonempty(
        $state['epochs_requested'] ?? null,
        $args['epochs'] ?? null,
        null
    );
    $imageSize = yolo_first_nonempty(
        $state['image_size'] ?? null,
        $args['imgsz'] ?? null,
        null
    );
    $batch = yolo_first_nonempty($args['batch'] ?? null, null);
    $workers = yolo_first_nonempty($args['workers'] ?? null, null);

    $scriptVersion = yolo_first_nonempty(
        $manifest['script_version'] ?? null,
        $state['script_version'] ?? null,
        $state['version'] ?? null,
        $dataset['script_version'] ?? null,
        null
    );

    $semanticMiou = yolo_first_nonempty(
        $semanticValidation['miou'] ?? null,
        yolo_nested($semanticValidation, 'results_dict.metrics/mIoU'),
        null
    );
    $semanticPixAcc = yolo_first_nonempty(
        $semanticValidation['pixel_accuracy'] ?? null,
        yolo_nested($semanticValidation, 'results_dict.metrics/pixel_accuracy'),
        null
    );

    $summary = [
        'run' => $entry['name'],
        'typ' => $entry['kind'] === 'component' ? 'Bauteil' : 'Detail',
        'struktur' => $entry['layout'] === 'modern' ? 'zentraler Run' : 'runs_semantic',
        'script' => $scriptVersion ?? '—',
        'status' => (string)$status,
        'quell_render_runs' => yolo_format_source_runs($sourceRuns),
        'klassen' => yolo_format_classes($maskConfiguration, $entry['kind']),
        'weighted_dice' => yolo_format_dice_configuration($lossConfiguration),
        'modell' => $model,
        'epochen_soll' => $epochsRequested,
        'epoche_letzte' => yolo_result_last_epoch($results),
        'bildgroesse' => $imageSize,
        'batch' => $batch,
        'worker' => $workers,
        'real_val_bilder' => $realValidation['unique_images'] ?? null,
        'real_val_gewichtete_samples' => $realValidation['weighted_samples'] ?? null,
        'real_val_anteil' => $realValidation['actual_fraction'] ?? null,
        'semantic_val_miou' => is_numeric($semanticMiou) ? (float)$semanticMiou : null,
        'semantic_val_pixacc' => is_numeric($semanticPixAcc) ? (float)$semanticPixAcc : null,
        'gestartet' => yolo_first_nonempty(
            $state['started_at'] ?? null,
            $manifest['created_at'] ?? null,
            null
        ),
        'aktualisiert' => yolo_first_nonempty(
            $state['updated_at'] ?? null,
            $manifest['last_started_at'] ?? null,
            $dataset['created_at'] ?? null,
            null
        ),
        'results_zeilen' => (int)($results['row_count'] ?? 0),
    ];

    return [
        'key' => $entry['key'],
        'name' => $entry['name'],
        'kind' => $entry['kind'],
        'layout' => $entry['layout'],
        'summary' => $summary,
        'files' => [
            'results' => $entry['results_path'] !== null,
            'args' => $entry['args_path'] !== null,
            'manifest' => $entry['manifest_path'] !== null,
            'state' => $entry['state_path'] !== null,
            'dataset_summary' => $entry['dataset_path'] !== null,
            'semantic_validation' => $entry['semantic_validation_path'] !== null,
            'milestones' => $entry['has_milestones'],
            'final_evaluation' => !empty($final['reports']),
        ],
        'results' => $results,
        'milestones' => $milestones,
        'final' => $final,
        'test_images' => $entry['kind'] === 'detail'
            ? yolo_public_final_test_images($entry['run_root'])
            : [],
    ];
}

$catalog = yolo_discover_runs(YOLO_META_ROOT);
$publicCatalog = yolo_public_catalog($catalog);
$defaultKeys = yolo_default_keys($catalog, 2);

/* =========================================================
 * AJAX: finales reales Vergleichsbild ausliefern
 * ========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'test_image') {
    $runKey = trim((string)($_GET['run'] ?? ''));
    $imageId = trim((string)($_GET['image'] ?? ''));

    if ($runKey === '' || $imageId === '' || !isset($catalog[$runKey])) {
        http_response_code(404);
        exit;
    }

    $entry = $catalog[$runKey];
    if (($entry['kind'] ?? '') !== 'detail') {
        http_response_code(404);
        exit;
    }

    $imagePath = yolo_find_final_test_image($entry['run_root'], $imageId);
    if ($imagePath === null) {
        http_response_code(404);
        exit;
    }

    yolo_stream_test_image($imagePath);
}

/* =========================================================
 * AJAX: ausgewählte Runs laden
 * ========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'run_data') {
    if (!$catalog) {
        yolo_json_out([
            'ok' => false,
            'error' => 'no_runs',
            'message' => 'Keine lesbaren YOLO-Runs gefunden.'
        ], 404);
    }

    $raw = trim((string)($_GET['runs'] ?? ''));
    $keys = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));

    if (!$keys) {
        yolo_json_out(['ok' => false, 'error' => 'no_selection', 'message' => 'Keine Runs ausgewählt.'], 400);
    }
    if (count($keys) > YOLO_MAX_SELECTED_RUNS) {
        yolo_json_out([
            'ok' => false,
            'error' => 'too_many_runs',
            'message' => 'Maximal ' . YOLO_MAX_SELECTED_RUNS . ' Runs gleichzeitig.'
        ], 400);
    }

    $selected = [];
    foreach ($keys as $key) {
        if (!isset($catalog[$key])) {
            continue; // Keine Pfade aus dem Browser akzeptieren; nur serverseitig erkannte Keys.
        }
        $selected[] = yolo_load_run($catalog[$key]);
    }

    if (!$selected) {
        yolo_json_out(['ok' => false, 'error' => 'unknown_selection', 'message' => 'Auswahl nicht mehr vorhanden.'], 404);
    }

    yolo_json_out([
        'ok' => true,
        'runs' => $selected,
        'generated_at' => date(DATE_ATOM),
    ]);
}

/* =========================================================
 * Rendering
 * ========================================================= */
$page_title = 'YOLO-Auswertung';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div id="yoloPage" class="lt-page dashboard-page yolo-page">
    <div class="lt-topbar yolo-topbar">
        <h1 class="ueberschrift dashboard-title">
            <span class="dashboard-title-main">YOLO-Auswertung</span>
            <span class="dashboard-title-soft">| <span id="yoloSelectedCount">0</span> Runs im Vergleich</span>
        </h1>
    </div>

    <?php if (!$catalog): ?>
        <div class="yolo-notice yolo-notice--error">
            Keine lesbaren YOLO-Runs unter <code><?= htmlspecialchars(YOLO_META_ROOT, ENT_QUOTES, 'UTF-8') ?></code> gefunden.
        </div>
    <?php else: ?>
        <nav class="yolo-tabs" role="tablist" aria-label="YOLO-Auswertung">
            <button type="button" class="yolo-tab is-active" role="tab" aria-selected="true" data-yolo-tab="runs">1. Runs</button>
            <button type="button" class="yolo-tab" role="tab" aria-selected="false" data-yolo-tab="summary">2. Übersicht</button>
            <button type="button" class="yolo-tab" role="tab" aria-selected="false" data-yolo-tab="config">3. Konfiguration</button>
            <button type="button" class="yolo-tab" role="tab" aria-selected="false" data-yolo-tab="graphs">4. Graphen</button>
            <button type="button" class="yolo-tab" role="tab" aria-selected="false" data-yolo-tab="images">5. Bilder</button>
            <button type="button" class="yolo-tab" role="tab" aria-selected="false" data-yolo-tab="glossary">6. Begriffe</button>
        </nav>

        <div id="yoloLoading" class="yolo-notice" hidden>Run-Daten werden gelesen …</div>
        <div id="yoloError" class="yolo-notice yolo-notice--error" hidden></div>

        <div class="yolo-tab-panels">
            <section class="yolo-tab-panel is-active" role="tabpanel" data-yolo-panel="runs">
                <div class="yolo-section yolo-selector-card" aria-labelledby="yoloRunSelectTitle">
                    <div class="yolo-section-head">
                        <div>
                            <h2 id="yoloRunSelectTitle">Runs auswählen</h2>
                            <p>Bis zu <?= YOLO_MAX_SELECTED_RUNS ?> Detail- oder Bauteilläufe gleichzeitig.</p>
                        </div>

                        <div class="yolo-selector-tools">
                            <select id="yoloKindFilter" class="kategorie-select" aria-label="Run-Typ filtern">
                                <option value="detail">Detail</option>
                                <option value="component">Bauteil</option>
                                <option value="all">Alle</option>
                            </select>
                        </div>
                    </div>

                    <div id="yoloRunList" class="yolo-run-list" role="group" aria-label="YOLO-Runs"></div>
                    <div id="yoloSelectionMessage" class="yolo-selection-message" aria-live="polite"></div>
                </div>
            </section>

            <section class="yolo-tab-panel" role="tabpanel" data-yolo-panel="summary" hidden>
                <div class="yolo-section">
                    <div class="yolo-section-head">
                        <div>
                            <h2>Run-Übersicht</h2>
                            <p>Wichtige Laufdaten und finale Auswertungswerte der ausgewählten Runs nebeneinander.</p>
                        </div>
                    </div>
                    <div id="yoloSummaryGrid" class="yolo-summary-grid"></div>
                    <div id="yoloSummaryEmpty" class="yolo-tab-empty" hidden>Wähle im ersten Tab mindestens einen Run aus.</div>

                    <div id="yoloDefectTypeSection" hidden>
                        <div class="yolo-final-title">Fehlertypen auf Renderdaten</div>
                        <p style="margin:0 0 12px; opacity:.72; font-size:.9rem; line-height:1.45">
                            Fehlertyp-spezifische Diagnose aus den separaten Fxx-Masken von v7.6.
                            Alle Fxx werden weiterhin gemeinsam als <strong>eine Trainingsklasse „Fehler“</strong> gelernt;
                            die Einzelmasken dienen nur der Auswertung. Andere gleichzeitig sichtbare Fehlertypen werden
                            bei der jeweiligen Fxx-Metrik ignoriert. Die Werte gelten ausschließlich für Renderdaten
                            und messen daher nicht den Sim-to-Real-Gap.
                        </p>
                        <div id="yoloDefectTypeTables"></div>
                    </div>
                </div>
            </section>

            <section class="yolo-tab-panel" role="tabpanel" data-yolo-panel="config" hidden>
                <div class="yolo-section">
                    <div class="yolo-section-head">
                        <div>
                            <h2>Konfiguration im Vergleich</h2>
                            <p>Manifest, Training-State, Dataset-Summary und <code>args.yaml</code> werden zusammengeführt. Das Fragezeichen erklärt den jeweiligen Parameter.</p>
                        </div>
                    </div>
                    <div id="yoloMetaTableWrap" class="yolo-meta-table-wrap">
                        <table class="yolo-meta-table" id="yoloMetaTable"></table>
                    </div>
                    <div id="yoloMetaEmpty" class="yolo-tab-empty" hidden>Wähle im ersten Tab mindestens einen Run aus.</div>
                </div>
            </section>

            <section class="yolo-tab-panel" role="tabpanel" data-yolo-panel="graphs" hidden>
                <div id="yoloGraphsEmpty" class="yolo-notice">Wähle im ersten Tab mindestens einen Run aus.</div>

                <div class="yolo-section" id="yoloTrainingSection" hidden>
                    <div class="yolo-section-head yolo-section-head--metrics">
                        <div>
                            <h2>Trainingsverlauf</h2>
                            <p>Direkt aus <code>results.csv</code>.</p>
                        </div>
                        <div id="yoloTrainingMetricPicker" class="yolo-metric-picker"></div>
                    </div>
                    <div id="yoloTrainingCharts" class="yolo-chart-grid"></div>
                </div>

                <div class="yolo-section" id="yoloMilestoneSection" hidden>
                    <div class="yolo-section-head yolo-section-head--metrics">
                        <div>
                            <h2>Milestones</h2>
                            <p>IoU, Dice, Recall und weitere Metriken aus den vorhandenen <code>auswertung.json</code>-Snapshots.</p>
                        </div>
                        <div id="yoloMilestoneMetricPicker" class="yolo-metric-picker"></div>
                    </div>
                    <div id="yoloMilestoneCharts" class="yolo-chart-grid"></div>
                </div>
            </section>

            <section class="yolo-tab-panel" role="tabpanel" data-yolo-panel="images" hidden>
                <div class="yolo-section yolo-images-section">
                    <div class="yolo-section-head">
                        <div>
                            <h2>Reale Testbilder im Vergleich</h2>
                            <p>Die 2x2-Ausgaben aus <code>Auswertung_Test</code> werden bildweise gruppiert. Gleiche Testbilder der ausgewählten Runs stehen jeweils nebeneinander.</p>
                        </div>
                    </div>
                    <div id="yoloImagesEmpty" class="yolo-tab-empty">Wähle im ersten Tab mindestens einen Run aus.</div>
                    <div id="yoloImagesComparison" class="yolo-image-comparison" hidden></div>
                </div>
            </section>

            <section class="yolo-tab-panel" role="tabpanel" data-yolo-panel="glossary" hidden>
                <div class="yolo-section yolo-glossary-section">
                    <div class="yolo-section-head yolo-glossary-head">
                        <div>
                            <h2>Begriffe und Metriken</h2>
                            <p>Nachschlagewerk für Konfigurationsparameter, Trainingsmetriken, Validierung und Milestone-Auswertungen.</p>
                        </div>
                        <div class="yolo-glossary-search-wrap">
                            <label for="yoloGlossarySearch">Begriff suchen</label>
                            <input
                                type="search"
                                id="yoloGlossarySearch"
                                class="search-input yolo-glossary-search"
                                placeholder="z. B. Dice, Batch, Recall …"
                                autocomplete="off"
                            >
                        </div>
                    </div>
                    <div class="yolo-glossary-meta"><span id="yoloGlossaryCount">0</span> Einträge</div>
                    <div id="yoloGlossaryList" class="yolo-glossary-list"></div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<div id="yoloHelpModal" class="modal hidden yolo-help-modal" aria-hidden="true">
    <div class="modal-content yolo-help-modal-content" role="dialog" aria-modal="true" aria-labelledby="yoloHelpTitle">
        <span class="close-button" id="yoloHelpClose" role="button" tabindex="0" aria-label="Erklärung schließen">&times;</span>
        <div id="yoloHelpCategory" class="yolo-help-category"></div>
        <h2 id="yoloHelpTitle" class="yolo-help-title"></h2>
        <div id="yoloHelpRaw" class="yolo-help-raw" hidden></div>
        <div id="yoloHelpBody" class="yolo-help-body"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    'use strict';

    const CATALOG = <?= json_encode($publicCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const DEFAULT_KEYS = <?= json_encode($defaultKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const MAX_RUNS = <?= YOLO_MAX_SELECTED_RUNS ?>;

    if (!Array.isArray(CATALOG) || CATALOG.length === 0) return;

    const byKey = new Map(CATALOG.map(r => [r.key, r]));
    const elRunList = document.getElementById('yoloRunList');
    const elKindFilter = document.getElementById('yoloKindFilter');
    const elSelectedCount = document.getElementById('yoloSelectedCount');
    const elSelectionMessage = document.getElementById('yoloSelectionMessage');
    const elLoading = document.getElementById('yoloLoading');
    const elError = document.getElementById('yoloError');

    const elSummaryGrid = document.getElementById('yoloSummaryGrid');
    const elSummaryEmpty = document.getElementById('yoloSummaryEmpty');
    const elDefectTypeSection = document.getElementById('yoloDefectTypeSection');
    const elDefectTypeTables = document.getElementById('yoloDefectTypeTables');
    const elMetaTableWrap = document.getElementById('yoloMetaTableWrap');
    const elMetaTable = document.getElementById('yoloMetaTable');
    const elMetaEmpty = document.getElementById('yoloMetaEmpty');
    const elGraphsEmpty = document.getElementById('yoloGraphsEmpty');
    const elTrainingSection = document.getElementById('yoloTrainingSection');
    const elMilestoneSection = document.getElementById('yoloMilestoneSection');
    const elTrainingMetricPicker = document.getElementById('yoloTrainingMetricPicker');
    const elMilestoneMetricPicker = document.getElementById('yoloMilestoneMetricPicker');
    const elTrainingCharts = document.getElementById('yoloTrainingCharts');
    const elMilestoneCharts = document.getElementById('yoloMilestoneCharts');

    const elImagesEmpty = document.getElementById('yoloImagesEmpty');
    const elImagesComparison = document.getElementById('yoloImagesComparison');

    const elGlossarySearch = document.getElementById('yoloGlossarySearch');
    const elGlossaryList = document.getElementById('yoloGlossaryList');
    const elGlossaryCount = document.getElementById('yoloGlossaryCount');

    const elHelpModal = document.getElementById('yoloHelpModal');
    const elHelpClose = document.getElementById('yoloHelpClose');
    const elHelpCategory = document.getElementById('yoloHelpCategory');
    const elHelpTitle = document.getElementById('yoloHelpTitle');
    const elHelpRaw = document.getElementById('yoloHelpRaw');
    const elHelpBody = document.getElementById('yoloHelpBody');

    const tabButtons = [...document.querySelectorAll('[data-yolo-tab]')];
    const tabPanels = [...document.querySelectorAll('[data-yolo-panel]')];

    const chartInstances = new Map();
    let selected = new Set();
    let loadedRuns = [];
    let trainingMetricsSelected = new Set();
    let milestoneMetricsSelected = new Set();
    let loadSerial = 0;

    const colors = [
        '#ff6b00', '#2563eb', '#16a34a', '#9333ea', '#dc2626', '#0891b2'
    ];

    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const fmtNumber = (value, digits = 4) => {
        const n = Number(value);
        if (!Number.isFinite(n)) return '—';
        return new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits }).format(n);
    };

    const fmtPercent = (value, digits = 1) => {
        const n = Number(value);
        if (!Number.isFinite(n)) return '—';
        return new Intl.NumberFormat('de-DE', {
            style: 'percent',
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        }).format(n);
    };

    const fmtDate = (value) => {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('de-DE', {
            dateStyle: 'short',
            timeStyle: 'short'
        }).format(date);
    };

    function prettyMetric(key) {
        const replacements = {
            'metrics/mIoU': 'mIoU',
            'metrics/pixel_accuracy': 'Pixel Accuracy',
            'metrics/pixel_acc': 'Pixel Accuracy',
            'metrics/iou_background': 'IoU Hintergrund',
            'metrics/iou_defect': 'IoU Fehler',
            'metrics/iou_edge': 'IoU Kante',
            'metrics/iou_text': 'IoU Schrift',
            'metrics/iou_component': 'IoU Bauteil',
            'train/ce_loss': 'Train CE-Loss',
            'train/dice_loss': 'Train Dice-Loss',
            'train/aux_loss': 'Train Aux-Loss',
            'val/ce_loss': 'Val CE-Loss',
            'val/dice_loss': 'Val Dice-Loss',
            'val/aux_loss': 'Val Aux-Loss',
            'ce_loss': 'CE-Loss',
            'dice_loss': 'Dice-Loss',
            'aux_loss': 'Aux-Loss'
        };
        if (replacements[key]) return replacements[key];

        return String(key)
            .replaceAll('_', ' ')
            .replaceAll('.', ' · ')
            .replaceAll('/', ' / ')
            .replace(/\biou\b/gi, 'IoU')
            .replace(/\bmiou\b/gi, 'mIoU')
            .replace(/\bdice\b/gi, 'Dice')
            .replace(/\bpixacc\b/gi, 'PixAcc')
            .replace(/\brecall\b/gi, 'Recall')
            .replace(/\bprecision\b/gi, 'Precision');
    }

    function metricLooksLikeRatio(key) {
        const k = String(key).toLowerCase();
        if (k.includes('loss')) return false;
        return /(iou|dice|recall|precision|accuracy|pixacc|pixel_accuracy)/.test(k);
    }

    /* =====================================================
     * Begriffslexikon
     * ===================================================== */
    const GLOSSARY_BASE = [
        {
            id: 'cfg:typ', category: 'Konfiguration', title: 'Typ', aliases: 'Detail Bauteil Component',
            body: [
                'Kennzeichnet, welches Segmentierungsmodell der Lauf trainiert. „Detail“ bezeichnet die mehrklassige Detailsegmentierung, etwa Hintergrund, Fehler, Kante und Schrift. „Bauteil“ bezeichnet das separate Component-Modell mit Hintergrund und Bauteil.',
                'Für direkte Vergleiche sollten möglichst Läufe desselben Typs verwendet werden, weil Klassenraum und Aufgabe unterschiedlich sind.'
            ]
        },
        {
            id: 'cfg:struktur', category: 'Konfiguration', title: 'Struktur', aliases: 'zentraler Run runs_semantic legacy',
            body: [
                'Beschreibt nur die Ordnerstruktur, in der der Lauf gefunden wurde. „zentraler Run“ ist die neuere Struktur YOLO/<RUN_VERSION>/detail bzw. /component. „runs_semantic“ bezeichnet ältere Ultralytics-Ausgaben unter YOLO/runs_semantic/.',
                'Der Wert ist organisatorisch und keine Qualitätsmetrik des Modells.'
            ]
        },
        {
            id: 'cfg:script', category: 'Konfiguration', title: 'Script-Version', aliases: 'yolo.py version pipeline',
            body: [
                'Version der yolo.py-Pipeline, mit der der Lauf erzeugt wurde. Sie ist wichtig, weil sich zwischen Versionen Datenaufbereitung, Klassen, Validierung, Loss-Funktionen, Milestones oder Dateistruktur ändern können.',
                'Zwei Modelle mit identischen Trainingsparametern können sich daher trotzdem unterscheiden, wenn sie mit unterschiedlichen Script-Versionen erzeugt wurden.'
            ]
        },
        {
            id: 'cfg:status', category: 'Konfiguration', title: 'Status', aliases: 'training state abgeschlossen laufend vorbereitet',
            body: [
                'Status aus dem Training-State. Er zeigt, ob der Lauf beispielsweise vorbereitet, laufend, unterbrochen oder abgeschlossen ist. Falls kein expliziter Status vorliegt, leitet die Seite nur grob ab, ob bereits Trainingsdaten vorhanden sind.',
                'Der Status sagt nichts über die Modellgüte aus.'
            ]
        },
        {
            id: 'cfg:source_runs', category: 'Datensatz', title: 'Render-Run(s)', aliases: 'Quelle source runs renderer output',
            body: [
                'Renderer-Output(s), aus denen die synthetischen Trainingsdaten dieses YOLO-Laufs aufgebaut wurden. Damit lässt sich nachvollziehen, welche Blender-Datengeneration einem Modell zugrunde liegt.',
                'Beim Vergleich verschiedener Modelle ist dieser Wert besonders relevant: Änderungen am Renderer verändern die Trainingsdomäne unabhängig von den eigentlichen YOLO-Hyperparametern.'
            ]
        },
        {
            id: 'cfg:classes', category: 'Datensatz', title: 'Trainingsklassen', aliases: 'Hintergrund Fehler Kante Schrift Bauteil Klassen',
            body: [
                'Die Klassen, die das Segmentierungsmodell während des Trainings unterscheiden soll. Hintergrund ist die Nicht-Zielklasse; weitere Klassen können Fehler, Kante, Schrift oder Bauteil sein.',
                'Wenn eine zusätzliche Klasse aktiviert wird, ändert sich die Optimierungsaufgabe: Modellkapazität und Loss werden auf mehr konkurrierende Klassen verteilt. Ergebnisse zwischen unterschiedlicher Klassenbelegung sind deshalb nicht vollständig 1:1 vergleichbar.'
            ]
        },
        {
            id: 'cfg:weighted_dice', category: 'Loss', title: 'Weighted Dice', aliases: 'gewichteter Dice Loss class weights',
            body: [
                'Weighted Dice bedeutet, dass die klassenweisen Dice-Loss-Anteile nicht alle mit demselben Gewicht in den Gesamt-Dice-Loss eingehen. Dadurch können wichtige oder kleine Klassen stärker und dominante Klassen wie Hintergrund schwächer gewichtet werden.',
                'Der angezeigte Wert nennt die verwendeten Gewichte, sofern sie in den Metadaten gespeichert wurden. Ein höheres Gewicht erhöht den relativen Einfluss dieser Klasse auf den Dice-Anteil des Gradienten; es garantiert aber nicht automatisch eine bessere IoU.',
                'Das ist von der Cross-Entropy zu unterscheiden: Weighted Dice betrifft hier den Dice-Anteil der Loss-Kombination.'
            ]
        },
        {
            id: 'cfg:model', category: 'Modell', title: 'Basismodell', aliases: 'YOLO26n YOLO26s pretrained model',
            body: [
                'Ausgangsmodell bzw. Checkpoint, von dem das Training gestartet wurde, beispielsweise ein YOLO26n- oder YOLO26s-Semantic-Modell.',
                'Größere Modellvarianten besitzen in der Regel mehr Kapazität und Rechenaufwand. Ein anderes Basismodell ist deshalb eine wesentliche Änderung der Versuchsbedingungen.'
            ]
        },
        {
            id: 'cfg:epochs_requested', category: 'Training', title: 'Epochen Soll', aliases: 'epochs requested',
            body: [
                'Geplante maximale Zahl vollständiger Durchläufe durch den Trainingsdatensatz. Eine Epoche entspricht grundsätzlich einem vollständigen Trainingsdurchlauf über die für diese Epoche vorgesehenen Samples.',
                'Early Stopping, Abbruch oder Resume können dazu führen, dass die tatsächlich erreichte letzte Epoche davon abweicht.'
            ]
        },
        {
            id: 'cfg:last_epoch', category: 'Training', title: 'Letzte Epoche', aliases: 'epoch final last',
            body: [
                'Höchste in results.csv erkannte Epoche des Laufs. Sie zeigt, wie weit das Training tatsächlich protokolliert wurde.',
                'Sie ist nicht automatisch die Epoche des besten Checkpoints. best.pt kann aus einer früheren Epoche stammen, während last.pt den letzten Trainingszustand repräsentiert.'
            ]
        },
        {
            id: 'cfg:image_size', category: 'Training', title: 'Bildgröße', aliases: 'imgsz resolution 1024',
            body: [
                'Eingangsauflösung, auf die Bilder für das Training verarbeitet werden, typischerweise als Kantenlänge bei quadratischer Eingabe. Bei 1024 werden beispielsweise 1024×1024 Pixel verarbeitet.',
                'Höhere Auflösung kann kleine Strukturen besser erhalten, benötigt aber deutlich mehr GPU-Speicher und Rechenzeit.'
            ]
        },
        {
            id: 'cfg:batch', category: 'Training', title: 'Batch', aliases: 'batch size mini batch',
            body: [
                'Anzahl der Trainingsbilder, die gemeinsam verarbeitet werden, bevor ein Optimizer-Schritt ausgeführt wird. Batch 16 bedeutet also bis zu 16 Samples pro Mini-Batch.',
                'Die Batchgröße beeinflusst GPU-Speicherbedarf und die Statistik des Gradienten. Ein Vergleich sollte berücksichtigen, wenn sie zwischen Runs geändert wurde.'
            ]
        },
        {
            id: 'cfg:workers', category: 'Training', title: 'Worker', aliases: 'dataloader workers cpu',
            body: [
                'Anzahl paralleler DataLoader-Worker, die Trainingsdaten auf CPU-Seite laden und vorbereiten. Mehr Worker können die GPU besser mit Daten versorgen, erhöhen aber CPU-, RAM- und I/O-Last.',
                'Der Wert verändert normalerweise nicht direkt die Zielmetrik, kann aber Trainingsgeschwindigkeit und Stabilität der Datenpipeline beeinflussen.'
            ]
        },
        {
            id: 'cfg:real_val_images', category: 'Validierung', title: 'Real-Val Bilder', aliases: 'real validation unique images echte Bilder',
            body: [
                'Zahl unterschiedlicher realer Validierungsbilder. Diese Bilder stammen nicht aus dem synthetischen Renderer und dienen dazu, die Übertragbarkeit auf reale Aufnahmen während der Validierung abzubilden.',
                '„Unique“ ist wichtig: Wiederholungen derselben Bilder erhöhen ihr Gewicht, aber nicht die Zahl unabhängiger Bildinhalte.'
            ]
        },
        {
            id: 'cfg:real_val_weighted', category: 'Validierung', title: 'Real-Val gewichtet', aliases: 'weighted samples repeat repeats',
            body: [
                'Effektive Zahl der Real-Val-Samples nach Wiederholung bzw. Gewichtung. Dieselben wenigen Realbilder können mehrfach in der kombinierten Validierung vorkommen, damit ihr Einfluss gegenüber synthetischen Validierungsbildern steigt.',
                'Dieser Wert darf nicht mit der Zahl unabhängiger Realbilder verwechselt werden.'
            ]
        },
        {
            id: 'cfg:real_val_fraction', category: 'Validierung', title: 'Real-Val Anteil', aliases: 'actual fraction fraction real validation',
            body: [
                'Tatsächlicher Anteil der gewichteten Real-Val-Samples an der kombinierten Validierungsmenge. 0,5 entspricht etwa 50 %.',
                'Je höher der Anteil, desto stärker beeinflusst die Leistung auf den realen Bildern die kombinierte Validierungsbewertung und damit gegebenenfalls die Auswahl von best.pt.'
            ]
        },
        {
            id: 'cfg:semantic_miou', category: 'Validierung', title: 'Finale interne Val mIoU', aliases: 'semantic validation mIoU mean intersection over union',
            body: [
                'Finale mIoU der internen semantischen Validierung. mIoU ist der Mittelwert der IoU-Werte über die berücksichtigten Klassen.',
                'Ein höherer Wert ist besser. Der Wert ist nur sinnvoll vergleichbar, wenn Klassenbelegung und Validierungsdatensatz gleich bzw. ausreichend ähnlich sind.'
            ]
        },
        {
            id: 'cfg:semantic_pixacc', category: 'Validierung', title: 'Finale interne Val PixAcc', aliases: 'pixel accuracy pixelgenauigkeit',
            body: [
                'Anteil korrekt klassifizierter Pixel in der internen Validierung. Ein höherer Wert ist besser.',
                'Bei starkem Klassenungleichgewicht kann Pixel Accuracy täuschen: Viele korrekt erkannte Hintergrundpixel können einen hohen Wert erzeugen, obwohl eine kleine Zielklasse schlecht erkannt wird. Deshalb zusammen mit klassenbezogenen IoU-, Dice-, Recall- und Precision-Werten betrachten.'
            ]
        },
        {
            id: 'cfg:started', category: 'Lauf', title: 'Gestartet', aliases: 'started at timestamp',
            body: [
                'Zeitpunkt, zu dem der Lauf laut gespeicherten Metadaten gestartet bzw. angelegt wurde. Er dient der Nachvollziehbarkeit und Sortierung, nicht der Qualitätsbewertung.'
            ]
        },
        {
            id: 'cfg:updated', category: 'Lauf', title: 'Aktualisiert', aliases: 'updated at last started',
            body: [
                'Letzter in den verfügbaren Metadaten erkennbarer Aktualisierungs- oder Startzeitpunkt des Runs. Bei Resume-Läufen kann dieser Zeitpunkt deutlich nach der ursprünglichen Erstellung liegen.'
            ]
        },
        {
            id: 'cfg:results_rows', category: 'Dateien', title: 'results.csv Zeilen', aliases: 'results csv rows',
            body: [
                'Anzahl der eingelesenen Datenzeilen aus results.csv. Typischerweise entspricht eine Zeile einer protokollierten Epoche.',
                'Bei abgebrochenen, fortgesetzten oder älteren Runs kann die genaue Zuordnung abweichen; die Seite verwendet deshalb zusätzlich den erkannten Epochenwert.'
            ]
        },
        {
            id: 'concept:results_csv', category: 'Dateien', title: 'results.csv', aliases: 'Ultralytics Training Verlauf CSV',
            body: [
                'CSV-Datei mit dem epochenweisen Trainingsverlauf. Die Seite erkennt alle numerischen Spalten dynamisch und bietet sie im Graph-Tab als auswählbare Zeitreihen an.',
                'Die Werte werden für die Darstellung nicht neu berechnet, sondern direkt aus der Datei gelesen.'
            ]
        },
        {
            id: 'concept:args_yaml', category: 'Dateien', title: 'args.yaml', aliases: 'Ultralytics arguments hyperparameters',
            body: [
                'Von Ultralytics gespeicherte Trainingsargumente. Daraus liest die Seite unter anderem Basismodell, Epochenzahl, Bildgröße, Batchgröße und Worker, sofern vorhanden.',
                'Die Datei dokumentiert die tatsächlich an den Trainer übergebenen Argumente und ist deshalb für die Reproduzierbarkeit wichtig.'
            ]
        },
        {
            id: 'concept:manifest', category: 'Dateien', title: 'run_manifest', aliases: 'manifest json run manifest',
            body: [
                'Run-weite Metadatendatei deiner yolo.py-Pipeline. Sie beschreibt unter anderem Script-Version, Renderer-Quellen, Masken-/Klassenkonfiguration und weitere runbezogene Einstellungen.',
                'Die Seite verwendet jeweils die neueste passende run_manifest_*.json eines Runs.'
            ]
        },
        {
            id: 'concept:training_state', category: 'Dateien', title: 'training_state', aliases: 'state json resume status',
            body: [
                'Zustandsdatei des Trainings. Sie dient unter anderem der Fortschritts- und Resume-Verwaltung und enthält je nach Script-Version Status, Epochen, Zeitstempel und weitere Laufdaten.',
                'Die Seite verwendet die neueste training_state*.json im jeweiligen Trainingsordner.'
            ]
        },
        {
            id: 'concept:dataset_summary', category: 'Dateien', title: 'dataset_summary', aliases: 'dataset summary json real validation masks',
            body: [
                'Zusammenfassung des für den Run aufgebauten Datensatzes. Sie enthält je nach Script-Version beispielsweise Klassen-/Maskenkonfiguration, Renderer-Quellen, Real-Val-Gewichtung und Loss-Konfiguration.',
                'Die Datei ist besonders hilfreich, wenn Arbeitsdaten nach erfolgreichem Training wieder gelöscht wurden.'
            ]
        },
        {
            id: 'concept:milestone', category: 'Auswertung', title: 'Milestone', aliases: 'Snapshot epoch Zwischenstand',
            body: [
                'Zwischenauswertung eines Modells an einer festgelegten Epoche. Milestones zeigen, wie sich die tatsächliche Segmentierungsqualität während des Trainings entwickelt und nicht nur, wie sich der Loss verändert.',
                'Damit lässt sich beispielsweise erkennen, ob ein Modell nach einer guten Zwischenepoche später wieder schlechter wird.'
            ]
        },
        {
            id: 'concept:iou', category: 'Metrik', title: 'IoU', aliases: 'Intersection over Union Jaccard',
            body: [
                'Intersection over Union misst die Überlappung zwischen vorhergesagter und wahrer Maske einer Klasse: Schnittmenge geteilt durch Vereinigungsmenge.',
                '1 bzw. 100 % bedeutet perfekte Überlappung, 0 keine Überlappung. Falsch-positive und falsch-negative Pixel verschlechtern den Wert. IoU ist deshalb eine zentrale Segmentierungsmetrik.'
            ]
        },
        {
            id: 'concept:miou', category: 'Metrik', title: 'mIoU', aliases: 'mean IoU mean intersection over union',
            body: [
                'Mean IoU ist der Mittelwert mehrerer klassenbezogener IoU-Werte. Dadurch werden mehrere Klassen in einer einzigen Kennzahl zusammengefasst.',
                'Welche Klassen in den Mittelwert eingehen, hängt von der jeweiligen Auswertung ab. Deshalb kann derselbe mIoU-Wert bei anderer Klassenbelegung eine andere Aussage haben.'
            ]
        },
        {
            id: 'concept:dice', category: 'Metrik', title: 'Dice', aliases: 'Dice coefficient F1 segmentation',
            body: [
                'Dice misst die Maskenüberlappung als 2 × Schnittmenge geteilt durch die Summe der Pixelmengen von Vorhersage und Ground Truth.',
                '1 bzw. 100 % ist perfekt. Dice und IoU beschreiben ähnliche Aspekte, haben aber unterschiedliche Skalierung. Der Dice-Loss ist typischerweise 1 minus Dice bzw. eine geglättete Variante davon.'
            ]
        },
        {
            id: 'concept:recall', category: 'Metrik', title: 'Recall', aliases: 'Sensitivity Trefferquote true positive rate',
            body: [
                'Recall beantwortet: Wie viel der tatsächlich vorhandenen Zielklasse wurde gefunden? Vereinfacht: True Positives geteilt durch True Positives plus False Negatives.',
                'Hoher Recall bedeutet wenige übersehene Zielpixel. Er sagt allein aber nicht, wie viele zusätzliche falsche Pixel markiert wurden.'
            ]
        },
        {
            id: 'concept:precision', category: 'Metrik', title: 'Precision', aliases: 'positive predictive value false positives',
            body: [
                'Precision beantwortet: Wie viel von dem, was das Modell als Zielklasse markiert, ist tatsächlich korrekt? Vereinfacht: True Positives geteilt durch True Positives plus False Positives.',
                'Hohe Precision bedeutet wenige Fehlalarme. Sie sollte zusammen mit Recall betrachtet werden.'
            ]
        },
        {
            id: 'concept:pixel_accuracy', category: 'Metrik', title: 'Pixel Accuracy / PixAcc', aliases: 'accuracy pixel accuracy',
            body: [
                'Anteil aller Pixel, deren Klasse korrekt vorhergesagt wurde.',
                'Bei Segmentierung mit viel Hintergrund kann der Wert hoch sein, obwohl kleine Fehler- oder Schriftklassen schlecht sind. Deshalb ist Pixel Accuracy allein für deine Zielklassen weniger aussagekräftig als klassenbezogene IoU-, Dice-, Recall- und Precision-Werte.'
            ]
        },
        {
            id: 'concept:ce_loss', category: 'Loss', title: 'Cross-Entropy-Loss / CE-Loss', aliases: 'cross entropy ce loss',
            body: [
                'Pixelweiser Klassifikations-Loss. Er bestraft, wenn die vorhergesagte Klassenwahrscheinlichkeit nicht zur Ground-Truth-Klasse passt.',
                'Niedriger ist besser. Der absolute Wert ist primär innerhalb derselben Loss-Konfiguration vergleichbar; ein niedrigerer CE-Loss garantiert nicht automatisch eine höhere IoU auf Realbildern.'
            ]
        },
        {
            id: 'concept:dice_loss', category: 'Loss', title: 'Dice-Loss', aliases: 'dice loss overlap',
            body: [
                'Loss-Komponente, die direkt die Überlappung zwischen vorhergesagter und wahrer Segmentierungsmaske optimiert. Sie ist besonders für unausgeglichene Segmentierungsklassen nützlich.',
                'Niedriger ist besser. Bei Weighted Dice werden die Beiträge der Klassen zusätzlich unterschiedlich gewichtet.'
            ]
        },
        {
            id: 'concept:aux_loss', category: 'Loss', title: 'Aux-Loss', aliases: 'auxiliary loss auxiliary head',
            body: [
                'Zusätzlicher Hilfs-Loss der Trainingsarchitektur. Er unterstützt die Optimierung über einen zusätzlichen bzw. internen Vorhersagepfad und ist nicht selbst die finale Qualitätsmetrik.',
                'Niedriger ist grundsätzlich besser, sollte aber nur innerhalb vergleichbarer Modell- und Loss-Konfigurationen interpretiert werden.'
            ]
        },
        {
            id: 'concept:train', category: 'Training', title: 'Train', aliases: 'training trainingsdaten',
            body: [
                'Werte mit Präfix „train/“ werden auf den Trainingsbatches berechnet. Sie zeigen, wie gut die Optimierung auf den Daten funktioniert, die das Modell direkt zum Lernen sieht.',
                'Ein weiter sinkender Train-Loss bei gleichzeitig schlechter werdender Validierungsleistung ist ein typisches Warnsignal für Overfitting oder eine ungünstige Verschiebung der Optimierung.'
            ]
        },
        {
            id: 'concept:val', category: 'Validierung', title: 'Val', aliases: 'validation validierung',
            body: [
                'Werte mit Präfix „val/“ werden auf Validierungsdaten berechnet, die nicht für Gradientenupdates verwendet werden. Sie sollen die Generalisierung während des Trainings beurteilen.',
                'In deiner Pipeline kann diese Validierung synthetische und gewichtete reale Samples kombinieren; deshalb ist die genaue Datensatzkonfiguration für die Interpretation wichtig.'
            ]
        },
        {
            id: 'concept:real_test', category: 'Auswertung', title: 'real_test', aliases: 'real test echte Testdaten',
            body: [
                'Auswertung auf den realen Testbildern. Diese Daten sollen vom Training und von der eigentlichen Validierungssteuerung getrennt bleiben und dienen als möglichst unabhängiger Realitätscheck.',
                'Für die Beurteilung des Sim-to-Real-Transfers ist diese Quelle besonders wichtig.'
            ]
        },
        {
            id: 'concept:render_val', category: 'Auswertung', title: 'render_val', aliases: 'render validation synthetic monitor',
            body: [
                'Auswertung auf einem synthetischen Renderer-Validierungssplit. Sie misst die Qualität innerhalb der synthetischen Domäne auf Bildern, die für diesen Monitor-Split vorgesehen sind.',
                'Gute Werte hier beweisen nicht automatisch gute Realbildleistung; ein großer Abstand zu real_test weist auf einen Sim-to-Real-Gap hin.'
            ]
        },
        {
            id: 'concept:render_test', category: 'Auswertung', title: 'render_test', aliases: 'render test synthetic test monitor',
            body: [
                'Auswertung auf dem synthetischen Renderer-Testsplit. Er ist als zusätzlicher, vom Trainingssplit getrennter Monitor für die synthetische Domäne gedacht.',
                'Der Vergleich zwischen render_test und real_test hilft einzuschätzen, ob ein Problem bereits synthetisch besteht oder hauptsächlich beim Transfer auf reale Bilder auftritt.'
            ]
        },
        {
            id: 'concept:epoch', category: 'Training', title: 'Epoche', aliases: 'epoch',
            body: [
                'Eine Epoche bezeichnet einen vollständigen Trainingsdurchlauf über die für das Training definierte Datenmenge. Auf der x-Achse der Trainings- und Milestone-Graphen wird die Epoche verwendet.',
                'Mehr Epochen bedeuten nicht automatisch ein besseres Modell; nach einem Optimum kann sich die Generalisierung wieder verschlechtern.'
            ]
        }
    ];

    const GLOSSARY_EXTRA = {
        "cfg:typ": [
                "Praktisch bedeutet der Typ: Welche Aufgabe bekommt das Netz überhaupt gestellt? Ein Detailmodell soll mehrere feine Pixelklassen auseinanderhalten; ein Bauteilmodell beantwortet dagegen nur die gröbere Frage, ob ein Pixel zum Schäkel gehört oder nicht.",
                "Wenn zwei Runs verschiedene Typen haben, sind gleiche Zahlen nicht direkt gegeneinander aussagekräftig. Ein Bauteilmodell kann beispielsweise eine sehr hohe Pixelgenauigkeit erreichen, obwohl es niemals Schrift oder Fehler unterscheiden musste."
        ],
        "cfg:struktur": [
                "Dieser Eintrag ist vor allem für die technische Herkunft der Dateien gedacht. In dieser Webversion werden nur noch die zentralen Run-Ordner der Form YOLO/vX_X_XXX angezeigt; die alten runs_semantic-Läufe werden bereits beim Einlesen ausgefiltert.",
                "Für die fachliche Bewertung kannst du diesen Wert meist ignorieren. Er hilft nur, falls du später nachvollziehen musst, aus welcher Generation deiner Dateistruktur ein Lauf stammt."
        ],
        "cfg:script": [
                "Die Script-Version ist in deiner Bachelorarbeit fast so wichtig wie ein Hyperparameter, weil yolo.py nicht nur YOLO startet, sondern auch Datensatzbau, Klassenmapping, Real-Val-Gewichtung, Loss-Patches und Auswertungen steuert.",
                "Wenn sich beispielsweise von v7.3 auf v7.4 die Dice-Gewichtung ändert, ist das eine echte Änderung des Lernproblems. Deshalb sollte bei auffälligen Ergebnisunterschieden immer zuerst geprüft werden, ob die Script-Version gleich geblieben ist."
        ],
        "cfg:status": [
                "Ein abgeschlossener Lauf hat normalerweise alle vorgesehenen Trainingsschritte beendet und seine Abschlussdateien geschrieben. Ein laufender oder unterbrochener Run kann dagegen eine unvollständige results.csv besitzen und noch keinen finalen Checkpoint oder Endtest haben.",
                "Für Vergleiche bedeutet das: Bei unfertigen Runs können die Kurven bereits sinnvoll sein, aber Endwerte dürfen nicht so behandelt werden, als sei das Experiment vollständig abgeschlossen."
        ],
        "cfg:source_runs": [
                "Diese Kennung verbindet ein trainiertes Modell mit genau den Blender-Renderdaten, aus denen seine synthetischen Samples stammen. Das ist wichtig, weil Änderungen an Kamera, Licht, Defekten, Schrift, Texturen oder Sampling die Datenverteilung ändern.",
                "Wenn zwei YOLO-Runs andere Render-Runs verwenden, vergleichst du nicht nur zwei Trainingskonfigurationen, sondern gleichzeitig zwei verschiedene Datensätze. Für saubere Ablationen sollte möglichst nur eine dieser Ebenen verändert werden."
        ],
        "cfg:classes": [
                "Jede aktive Klasse entspricht einem möglichen Ausgabekanal des Segmentierungsmodells. Für jedes Pixel muss das Modell entscheiden, welcher dieser Klassen es zugeordnet wird.",
                "Eine zusätzliche Klasse ist deshalb nicht nur „eine weitere Anzeige“. Sie kann mit bestehenden Klassen um dieselben Pixel konkurrieren und verändert sowohl die Fehlerfälle als auch die Loss-Berechnung. Genau deshalb kann etwa das Hinzufügen der Fehlerklasse die Schriftleistung beeinflussen."
        ],
        "cfg:weighted_dice": [
                "Ohne Gewichtung würden die klassenweisen Dice-Anteile gleich behandelt. Mit Gewichten kannst du beispielsweise verhindern, dass der riesige Hintergrund im Vergleich zu einer kleinen Schrift- oder Fehlerfläche zu viel Einfluss bekommt.",
                "Wichtig ist die Interpretation relativ zueinander: Gewicht 1,0 gegenüber 0,25 bedeutet, dass der betreffende Klassenanteil im Dice-Teil viermal so stark berücksichtigt wird. Das ist kein Multiplikator für die spätere IoU und auch keine Garantie, dass die stärker gewichtete Klasse tatsächlich besser wird."
        ],
        "cfg:model": [
                "Das Basismodell bestimmt Architektur, Parameterzahl und den Startzustand der Gewichte. Ein „s“-Modell ist typischerweise größer als ein „n“-Modell und kann komplexere Muster darstellen, benötigt dafür aber mehr Rechenzeit und Speicher.",
                "Ein Modellwechsel ist daher keine kleine Einstellung. Wenn ein s-Modell besser oder schlechter abschneidet, kann das sowohl an seiner Kapazität als auch an unterschiedlichem Overfitting oder am Sim-to-Real-Verhalten liegen."
        ],
        "cfg:epochs_requested": [
                "Bei 200 Epochen sieht das Modell den Trainingsdatensatz nicht nur einmal, sondern wird über viele wiederholte Durchläufe optimiert. Innerhalb jeder Epoche werden die Daten in Mini-Batches zerlegt und die Modellgewichte schrittweise angepasst.",
                "Der Sollwert ist lediglich die Obergrenze. Entscheidend für die Modellwahl ist nicht „je mehr, desto besser“, sondern an welcher Epoche die Validierungs- und Testleistung ihr bestes Niveau erreicht."
        ],
        "cfg:last_epoch": [
                "Diese Zahl beantwortet nur, bis wohin tatsächlich protokolliert wurde. Sie sagt nicht, dass das Modell dieser Epoche das beste Modell des Runs ist.",
                "Gerade bei deinem Fall kann Epoche 200 visuell besser aussehen als ein separat ausgewählter best.pt-Checkpoint. Deshalb sollte man immer unterscheiden zwischen letzter Epoche, bestem Validierungscheckpoint und final ausgewertetem Gewicht."
        ],
        "cfg:image_size": [
                "Die Eingangsauflösung legt fest, wie viele Bilddetails dem Netz überhaupt zur Verfügung stehen. Sehr kleine Schrift oder dünne Risse können beim Herunterskalieren verschwinden oder nur noch wenige Pixel breit sein.",
                "Eine Verdopplung der Kantenlänge vervierfacht grob die Pixelzahl. Dadurch steigen Rechenaufwand und VRAM stark, weshalb Bildgröße immer gemeinsam mit Batchgröße und verfügbarer GPU betrachtet werden muss."
        ],
        "cfg:batch": [
                "Ein Mini-Batch ist die Gruppe von Bildern, aus der ein einzelner Optimierungsschritt berechnet wird. Das Modell sammelt also den Fehler über diese Bilder und aktualisiert danach seine Gewichte.",
                "Größere Batches liefern meist gleichmäßigere, weniger zufällige Updates, verbrauchen aber mehr VRAM. Kleinere Batches sind speichersparender, können aber stärker schwankende Gradienten erzeugen. Der Wert ist deshalb eher eine Trainingsbedingung als eine direkte Gütemetrik."
        ],
        "cfg:workers": [
                "Die Worker arbeiten nicht am neuronalen Netz selbst, sondern stellen Bilder und Masken für die GPU bereit. Wenn zu wenige Worker oder ein langsames Laufwerk verwendet werden, kann die GPU auf Daten warten.",
                "Mehr Worker machen ein Modell nicht automatisch genauer. Sie können aber die Trainingszeit verkürzen, solange CPU, RAM und Datenträger die zusätzliche Parallelität verkraften."
        ],
        "cfg:real_val_images": [
                "Diese Zahl beschreibt echte unabhängige Motive und nicht die künstlich erzeugte Gewichtung. Acht verschiedene Bilder bleiben also acht verschiedene Bildinhalte, auch wenn jedes davon später sehr oft in der Validation wiederholt wird.",
                "Für die Aussagekraft ist die Zahl der unterschiedlichen Bilder wichtig: Viele Wiederholungen erhöhen den Einfluss auf die Modellselektion, liefern aber keine zusätzliche Vielfalt und ersetzen keinen größeren realen Datensatz."
        ],
        "cfg:real_val_weighted": [
                "Hier wird sichtbar, wie oft die vorhandenen Realbilder effektiv in der kombinierten Validation vertreten sind. Beispiel: 8 echte Bilder können durch Wiederholung rechnerisch zu mehreren hundert Real-Val-Samples werden.",
                "Das erhöht bewusst ihren Einfluss auf die Validierungsmetrik und damit auf best.pt. Es erhöht aber nicht die statistische Unabhängigkeit der Daten; dieselben acht Motive bleiben dieselben acht Motive."
        ],
        "cfg:real_val_fraction": [
                "Ein Anteil von ungefähr 0,5 bedeutet, dass reale und synthetische Samples in der kombinierten Validation ungefähr gleich stark vertreten sind. Dadurch kann ein Modell mit guter Renderleistung, aber schlechter Realbildleistung bei der best.pt-Auswahl stärker bestraft werden.",
                "Der Wert ist daher eine Designentscheidung der Modellselektion. Er sagt nicht, dass das Training zu 50 % aus Realbildern besteht; deine Realbilder bleiben Validation und werden nicht zum Lernen der Gewichte benutzt."
        ],
        "cfg:semantic_miou": [
                "Der Wert fasst mehrere Klassen zu einer einzigen Zahl zusammen. Wenn beispielsweise Schrift sehr gut und Fehler sehr schlecht ist, kann der Mittelwert beide Effekte verdecken.",
                "Für die Bachelorarbeit ist mIoU gut als schnelle Gesamtübersicht, aber für die Fehleranalyse solltest du immer zusätzlich die IoU der einzelnen relevanten Klassen ansehen. Besonders wichtig ist außerdem, auf welchem Validierungsdatensatz der Wert berechnet wurde."
        ],
        "cfg:semantic_pixacc": [
                "Ein einfaches Beispiel: Wenn 95 % aller Pixel Hintergrund sind, kann ein Modell schon durch sehr viel korrekt erkannten Hintergrund eine hohe Pixel Accuracy bekommen. Eine kleine Schriftklasse kann gleichzeitig fast vollständig verfehlt werden.",
                "Deshalb eignet sich PixAcc eher als grobe Plausibilitätskontrolle. Für kleine Zielregionen sind klassenbezogene Überlappungsmetriken wie IoU oder Dice deutlich aussagekräftiger."
        ],
        "cfg:started": [
                "Der Zeitstempel hilft dir, Experimente chronologisch einzuordnen und mit Änderungen an Renderer oder yolo.py zu verbinden. Er ist besonders bei vielen ähnlich benannten Runs praktisch.",
                "Fachlich beeinflusst der Zeitpunkt natürlich nicht die Modellqualität."
        ],
        "cfg:updated": [
                "Bei einem Resume kann derselbe Run an einem späteren Tag weitertrainiert werden. Der Aktualisierungszeitpunkt kann deshalb zeigen, dass zwischen ursprünglichem Start und letztem Training eine Unterbrechung lag.",
                "Er ist ein Organisationswert und sollte nicht als Trainingsdauer interpretiert werden."
        ],
        "cfg:results_rows": [
                "Wenn jede Epoche genau einmal protokolliert wurde, entsprechen 200 Zeilen ungefähr 200 Epochen. Bei Resume, Abbruch oder älteren Ultralytics-Versionen kann diese einfache Beziehung jedoch abweichen.",
                "Die Seite benutzt deshalb den eigentlichen Epoch-Schlüssel für die x-Achse und zeigt die Zeilenzahl nur als zusätzliche Vollständigkeitskontrolle."
        ],
        "concept:results_csv": [
                "Du kannst dir results.csv als Logbuch des Trainings vorstellen: Jede Zeile beschreibt den Zustand nach einer Epoche, jede Spalte eine gemessene Größe wie Loss, Learning Rate oder Validierungsmetrik.",
                "Die Graphen dieser Webseite sind deshalb keine neu berechneten Ergebnisse, sondern lediglich eine Visualisierung dieses vorhandenen Logbuchs. Wenn eine Spalte in results.csv nicht existiert, kann die Webseite sie auch nicht anzeigen."
        ],
        "concept:args_yaml": [
                "Die Datei ist nützlich, um nachträglich zu prüfen, was Ultralytics tatsächlich erhalten hat. Das ist zuverlässiger als sich nur auf Kommentare in einer später veränderten yolo.py-Version zu verlassen.",
                "Nicht jede projektweite Einstellung steht dort: Dinge wie dein eigener Datensatzbau oder Weighted-Dice-Patch können zusätzlich im Manifest oder dataset_summary dokumentiert sein."
        ],
        "concept:manifest": [
                "Das Manifest ist die projektweite „Versuchsbeschreibung“ deines Runs. Es ergänzt args.yaml um Einstellungen, die außerhalb des eigentlichen Ultralytics-Trainers liegen.",
                "Für reproduzierbare Vergleiche ist diese Datei besonders wertvoll, weil sie die Verbindung zwischen Trainingslauf, Rendererquelle und eigener Pipeline herstellt."
        ],
        "concept:training_state": [
                "Diese Datei ist eher Betriebszustand als wissenschaftliches Ergebnis. Sie hilft der Pipeline zu wissen, was bereits erledigt wurde und von wo ein abgebrochener Lauf fortgesetzt werden kann.",
                "Wenn ein Run ungewöhnlich aussieht, kann der State Hinweise auf Resume, letzte Epoche oder Status geben. Die eigentlichen Verlaufsmesswerte kommen aber weiterhin aus results.csv."
        ],
        "concept:dataset_summary": [
                "Die dataset_summary beschreibt, was aus den Quelldaten tatsächlich als Trainings- und Validierungsdatensatz aufgebaut wurde. Damit kannst du Soll-Konfiguration und real erzeugten Datensatz gegeneinander prüfen.",
                "Besonders bei Real-Val-Wiederholung ist das wichtig, weil dort die tatsächliche Zahl gewichteter Samples und der resultierende Realanteil festgehalten werden können."
        ],
        "concept:milestone": [
                "Ein Milestone friert die Modellleistung zu einem bestimmten Trainingszeitpunkt ein und wertet genau diesen Zwischenstand separat aus. Dadurch kannst du sehen, ob sich die echte Segmentierungsqualität parallel zum Loss verbessert.",
                "Das ist für Overfitting sehr hilfreich: Wenn Loss weiter sinkt, die Milestone-IoU aber nach Epoche 125 schlechter wird, lernt das Modell zwar weiter seine Trainingsaufgabe, generalisiert aber nicht mehr besser."
        ],
        "concept:iou": [
                "Anschaulich zählt nur die Fläche, bei der Vorhersage und Ground Truth übereinstimmen, positiv. Alles, was nur in einer der beiden Masken liegt, vergrößert den Nenner und senkt die IoU.",
                "Beispiel: Markiert das Modell die echte Schrift vollständig, aber zusätzlich noch viel Metall daneben, bleibt der Recall hoch, die IoU sinkt jedoch wegen der zusätzlichen False Positives. Dadurch ist IoU strenger als eine reine Trefferquote."
        ],
        "concept:miou": [
                "„Mean“ bedeutet hier schlicht Mittelwert. Bei zwei ausgewerteten Klassen mit IoU 0,8 und 0,2 ergibt sich beispielsweise mIoU 0,5, obwohl keine der beiden Klassen tatsächlich 0,5 erreicht.",
                "Deshalb kann mIoU Unterschiede zwischen Klassen verstecken. Nutze ihn für den Gesamttrend und die einzelnen Klassen-IoUs für die Diagnose, welche Klasse den Lauf wirklich verbessert oder verschlechtert."
        ],
        "concept:dice": [
                "Dice gewichtet die gemeinsame Fläche etwas anders als IoU und fällt deshalb bei derselben Überlappung numerisch meist höher aus. Beide Werte werden 1 bei perfekter Übereinstimmung und 0 bei völlig fehlender Überlappung.",
                "Dice ist besonders eng mit dem Dice-Loss verbunden, aber Messmetrik und Trainings-Loss sind trotzdem nicht identisch: Der Loss kann mit Wahrscheinlichkeiten und Glättung arbeiten, während eine Auswertung meist fertige Klassenmasken vergleicht."
        ],
        "concept:recall": [
                "False Negatives sind echte Zielpixel, die das Modell übersehen hat. Genau diese Fehler drücken den Recall. Wenn dir wichtig ist, möglichst keinen Riss oder keine Schrift zu verpassen, ist Recall daher sehr relevant.",
                "Ein Modell kann Recall künstlich hoch bekommen, indem es sehr großzügig Zielpixel markiert. Dann entstehen jedoch viele False Positives; deshalb muss Recall zusammen mit Precision oder IoU gelesen werden."
        ],
        "concept:precision": [
                "False Positives sind Pixel, die das Modell fälschlicherweise als Zielklasse markiert. Viele solche Fehlalarme senken die Precision.",
                "Ein extrem vorsichtiges Modell kann hohe Precision erreichen, indem es nur sehr sichere kleine Bereiche markiert, dabei aber viel echte Zielklasse verpasst. Dann wäre der Recall niedrig. Beide Werte beschreiben daher zwei verschiedene Fehlerarten."
        ],
        "concept:pixel_accuracy": [
                "Der Zähler enthält alle korrekt klassifizierten Pixel aller berücksichtigten Klassen. Damit bekommt ein häufig vorkommender Hintergrund automatisch sehr viel Gewicht.",
                "Für deine Anwendung mit kleinen Schrift- und Fehlerflächen ist ein hoher PixAcc-Wert daher kein ausreichender Erfolgsnachweis. Er sollte höchstens ergänzend zu den klassenbezogenen Metriken betrachtet werden."
        ],
        "concept:ce_loss": [
                "Für jedes Pixel gibt das Modell zunächst Wahrscheinlichkeiten für die möglichen Klassen aus. Cross Entropy wird groß, wenn die richtige Klasse eine zu geringe Wahrscheinlichkeit bekommt, und klein, wenn das Modell der richtigen Klasse viel Wahrscheinlichkeit gibt.",
                "Der CE-Loss bewertet damit die Sicherheit der Klassenzuordnung und nicht direkt die geometrische Überlappung einer ganzen Maske. Deshalb kann CE-Loss sinken, während eine IoU auf Realbildern stagniert oder sogar schlechter wird."
        ],
        "concept:dice_loss": [
                "Der Dice-Loss betrachtet stärker die Fläche einer Klasse als Ganzes. Vereinfacht soll er Vorhersage- und Ground-Truth-Maske möglichst stark zur Deckung bringen und ist deshalb bei kleinen Klassen oft hilfreicher als eine rein pixelweise Betrachtung.",
                "Ein Dice-Loss von 0 wäre ideal; größere Werte bedeuten schlechtere Überlappung im Loss-Sinn. Der konkrete Zahlenwert hängt jedoch von Implementierung, aktiven Klassen, Glättung und gegebenenfalls Klassenweights ab und sollte daher vor allem zwischen vergleichbaren Runs betrachtet werden."
        ],
        "concept:aux_loss": [
                "„Auxiliary“ bedeutet Hilfsverlust. Manche Netze erzeugen während des Trainings zusätzliche interne Vorhersagen, damit auch frühere Netzbereiche ein direktes Lernsignal bekommen.",
                "Dieser Wert ist nützlich, um zu sehen, ob der Trainingsprozess stabil läuft, aber er ist kein Ergebnis, das du fachlich als Segmentierungsqualität berichten würdest. Entscheidend bleiben die Metriken auf unabhängigen Daten."
        ],
        "concept:train": [
                "Die Trainingsdaten beeinflussen die Gewichte direkt. Deshalb ist es normal, dass das Modell auf ihnen mit der Zeit immer besser wird; genau darauf wird es optimiert.",
                "Wenn nur Train-Werte gut werden, sagt das noch wenig über reale Bilder aus. Relevant wird der Vergleich mit Val/Test: Große Unterschiede zwischen Train und unabhängigen Daten sprechen häufig für Overfitting oder einen Domain-Gap."
        ],
        "concept:val": [
                "Die Validation wird zwar während des Trainings regelmäßig angesehen, ihre Bilder werden aber nicht für die eigentliche Gewichtsaktualisierung benutzt. Sie dient unter anderem dazu, Trainingsfortschritt und best.pt zu beurteilen.",
                "Weil du reale Bilder bewusst stark in diese Validation gewichtest, ist sie kein neutraler Testdatensatz. Der endgültige Test sollte davon getrennt bleiben, wenn du eine möglichst unabhängige Aussage brauchst."
        ],
        "concept:real_test": [
                "Hier interessiert vor allem die Frage: Funktioniert das auf echten Fotos, obwohl ein großer Teil der Lerndaten synthetisch erzeugt wurde? Diese Auswertung kommt deiner späteren praktischen Anwendung am nächsten.",
                "Falls der Run mit Ground-Truth-Masken ausgewertet wird, sind klassenbezogene IoU/Dice besonders aussagekräftig. Wird nur eine visuelle Inferenz ohne Ground Truth erzeugt, kann die Webseite keine objektiven Qualitätsmetriken daraus berechnen."
        ],
        "concept:render_val": [
                "Der Render-Val-Split zeigt, ob das Modell die synthetische Welt grundsätzlich gelernt hat. Schlechte Werte hier deuten eher auf ein Trainings-, Label- oder Modellproblem innerhalb der Renderdomäne hin.",
                "Sind Render-Werte gut und Real-Werte deutlich schlechter, spricht das dagegen stärker für einen Domain-Gap: Das Modell kann die synthetischen Muster, überträgt sie aber nicht ausreichend auf echte Fotos."
        ],
        "concept:render_test": [
                "Dieser Split ist ein zweiter synthetischer Kontrollpunkt, der nicht mit dem eigentlichen Trainingssplit identisch sein sollte. Er hilft zu prüfen, ob gute Render-Val-Werte nur an einer besonderen Auswahl von Renderbildern hängen.",
                "Auch ein sehr guter render_test ersetzt keinen real_test. Er zeigt Generalisierung innerhalb der synthetischen Domäne, nicht automatisch auf reale Beleuchtung, Materialtextur und Kameracharakteristik."
        ],
        "concept:epoch": [
                "Innerhalb einer Epoche sieht das Modell viele Mini-Batches und führt entsprechend viele Optimizer-Schritte aus. Die Epochenzahl ist deshalb nur eine grobe Zeitskala; wie viele einzelne Updates darin stecken, hängt von Datensatzgröße und Batchgröße ab.",
                "Beim Vergleich zweier Runs sollte nicht automatisch angenommen werden, dass gleiche Epochennummern exakt gleich viel Lerninformation bedeuten, wenn Datensatzgröße oder Sampling verändert wurden."
        ]
};

    const GLOSSARY_FOUNDATIONS = [
        {
                "id": "concept:per_class_iou",
                "category": "Metrik",
                "title": "IoU pro Klasse",
                "aliases": "Klassen-IoU per class IoU Hintergrund Fehler Kante Schrift Bauteil NaN",
                "body": [
                        "Die klassenweise IoU berechnet die Intersection over Union nicht als Mittelwert, sondern separat für genau eine Klasse. Dadurch kannst du sehen, ob sich zum Beispiel die Fehlererkennung verbessert, während die Schrift gleichzeitig schlechter wird. Genau diese Information kann eine einzelne mIoU-Zahl verdecken.",
                        "Für jede Klasse werden ihre vorhergesagten Pixel gegen die Ground-Truth-Pixel derselben Klasse verglichen. Falsch-positive Pixel vergrößern die Vereinigungsmenge, falsch-negative Pixel fehlen in der Schnittmenge; beide Fehlerarten senken deshalb die IoU. 1 bzw. 100 % wäre eine perfekte Überlappung.",
                        "yolo_v7.4 legt stabile Spalten für Hintergrund, Fehler, Kante, Schrift und Bauteil an. Ist eine Klasse in einem bestimmten Modell deaktiviert und besitzt daher gar keinen Outputkanal, wird bewusst NaN bzw. kein Messwert gespeichert. Das ist fachlich etwas völlig anderes als 0 % IoU: 0 % würde bedeuten, dass die Klasse bewertet wurde und komplett versagt hat; NaN bedeutet, dass sie in diesem Lauf nicht bewertet werden konnte.",
                        "Für die Interpretation sind besonders die Zielklassen wichtig. Eine sehr hohe Hintergrund-IoU kann wegen der großen Hintergrundfläche leicht gut aussehen, obwohl eine kleine Fehler- oder Schriftklasse noch schlecht ist. Deshalb solltest du die einzelnen Klassen-IoUs neben mIoU betrachten."
                ]
        },
        {
                "id": "concept:ground_truth",
                "category": "Grundlagen",
                "title": "Ground Truth",
                "aliases": "Ground Truth GT Referenzmaske Sollmaske",
                "body": [
                        "Ground Truth ist die als korrekt angenommene Referenz, gegen die eine Modellvorhersage verglichen wird. Bei deiner Segmentierung ist das typischerweise eine manuell oder aus dem Renderer erzeugte Maske, in der für jedes Pixel die richtige Klasse festgelegt ist.",
                        "Ohne Ground Truth kann man zwar anzeigen, was das Modell vorhergesagt hat, aber keine objektive IoU-, Dice-, Recall- oder Precision-Metrik berechnen. Man weiß dann nicht pixelgenau, welche Vorhersagen richtig oder falsch waren.",
                        "Genau das betrifft die v7.3-Abschlussauswertung: Dort wurde die Testbild-Inferenz ausdrücklich mit use_ground_truth=False erzeugt. Die JSON-Datei existiert deshalb, aber ihr Feld aggregate_metrics bleibt leer."
                ]
        },
        {
                "id": "concept:tp_fp_fn",
                "category": "Grundlagen",
                "title": "True Positive / False Positive / False Negative",
                "aliases": "TP FP FN richtig positiv falsch positiv falsch negativ",
                "body": [
                        "True Positive (TP) bedeutet: Ein Zielpixel war wirklich vorhanden und wurde vom Modell auch als Ziel erkannt. False Positive (FP) bedeutet: Das Modell markiert ein Pixel als Ziel, obwohl es laut Ground Truth keines ist. False Negative (FN) bedeutet: Ein echtes Zielpixel wurde übersehen.",
                        "Diese drei Fälle stecken hinter vielen Metriken. Recall wird vor allem durch False Negatives verschlechtert, Precision vor allem durch False Positives, und IoU berücksichtigt beide Fehlerarten gleichzeitig.",
                        "Bei Schrift wäre ein Riss, der fälschlich als Schrift markiert wird, ein False Positive für die Schriftklasse. Ein echter Buchstabe, den das Modell nicht markiert, wäre ein False Negative."
                ]
        },
        {
                "id": "concept:loss",
                "category": "Grundlagen",
                "title": "Loss / Verlustfunktion",
                "aliases": "Loss Verlust Fehlerfunktion Optimierungsziel",
                "body": [
                        "Der Loss ist die Zahl, die das Training direkt zu minimieren versucht. Er wird aus Modellvorhersage und Ground Truth berechnet und sagt dem Optimizer, in welche Richtung die Gewichte verändert werden sollen.",
                        "Ein Loss ist nicht dasselbe wie eine Qualitätsmetrik. Er kann sinken, obwohl eine für dich wichtige Realbild-IoU schlechter wird, weil der Loss auf einer anderen Datenmenge, mit anderen Gewichtungen und teilweise mit weichen Wahrscheinlichkeiten berechnet wird.",
                        "In deiner Pipeline setzt sich das Lernsignal unter anderem aus Cross-Entropy-, Dice- und gegebenenfalls Aux-Loss zusammen."
                ]
        },
        {
                "id": "concept:learning_rate",
                "category": "Training",
                "title": "Learning Rate / Lernrate",
                "aliases": "learning rate lr lernrate schrittweite",
                "body": [
                        "Die Learning Rate bestimmt, wie groß ein einzelner Optimierungsschritt ungefähr ausfallen darf. Vereinfacht: Das Training berechnet, in welche Richtung die Gewichte geändert werden sollten, und die Lernrate bestimmt, wie weit man in diese Richtung geht.",
                        "Ist sie zu groß, kann das Training an guten Lösungen vorbeispringen oder instabil werden. Ist sie zu klein, verändert sich das Modell nur sehr langsam. Deshalb wird die Lernrate oft über die Epochen nach einem Schedule verändert.",
                        "Die lr/pg*-Graphen zeigen diesen Schedule für verschiedene Parametergruppen. Sie messen keine Modellqualität."
                ]
        },
        {
                "id": "concept:param_group",
                "category": "Training",
                "title": "Optimizer-Parametergruppe (pg0, pg1, …)",
                "aliases": "pg parameter group optimizer parametergruppe",
                "body": [
                        "Ein neuronales Netz enthält verschiedene Arten lernbarer Parameter. Der Optimizer kann diese in Gruppen aufteilen und für die Gruppen unterschiedliche Einstellungen verwenden, zum Beispiel unterschiedliche Learning Rates oder Weight Decay.",
                        "Die Bezeichnungen pg0, pg1 usw. sind lediglich laufende Nummern dieser Gruppen. Aus der Nummer allein folgt keine Qualitätsaussage und je nach Ultralytics-Version kann die genaue Zuordnung variieren.",
                        "Wenn mehrere lr/pg*-Kurven ähnlich aussehen, bedeutet das nur, dass diese Parametergruppen einem ähnlichen Lernratenverlauf folgen."
                ]
        },
        {
                "id": "concept:optimizer",
                "category": "Training",
                "title": "Optimizer",
                "aliases": "optimizer gewichte gradient update",
                "body": [
                        "Der Optimizer ist der Algorithmus, der aus dem berechneten Loss die Modellgewichte aktualisiert. Er bekommt ein Richtungssignal aus den Ableitungen des Loss und wendet daraus einen kontrollierten Schritt auf die Parameter an.",
                        "Learning Rate, Momentum und Weight Decay sind typische Optimizer-Einstellungen. Für die Webauswertung ist wichtig zu wissen: Der Optimizer erzeugt den Lernprozess, aber seine internen Größen sind nicht automatisch Qualitätsmetriken.",
                        "Du beurteilst das Ergebnis deshalb weiterhin über unabhängige Val-/Testmetriken und nicht darüber, ob ein bestimmter Optimizerwert „gut“ aussieht."
                ]
        },
        {
                "id": "concept:best_last",
                "category": "Training",
                "title": "best.pt und last.pt",
                "aliases": "best pt last pt checkpoint gewichte",
                "body": [
                        "last.pt enthält den Modellzustand am zuletzt gespeicherten Trainingsstand. best.pt enthält dagegen den Zustand der Epoche, die nach der vom Trainer verwendeten Validierungs-/Fitnesslogik als beste bewertet wurde.",
                        "Beide Dateien können deshalb deutlich unterschiedliche Vorhersagen erzeugen. Wenn Epoche 200 visuell besser aussieht als der Endtest, muss geprüft werden, ob der Endtest best.pt verwendet, während der Milestone Epoche 200 den damaligen last-Checkpoint verwendet hat.",
                        "„Best“ bedeutet also nur: bestes Modell nach der hinterlegten Auswahlmetrik. Es bedeutet nicht automatisch bestes Modell nach deiner später betrachteten Schrift- oder Fehler-IoU."
                ]
        },
        {
                "id": "concept:overfitting",
                "category": "Grundlagen",
                "title": "Overfitting",
                "aliases": "overfitting überanpassung generalisierung",
                "body": [
                        "Overfitting bedeutet, dass das Modell die Trainingsdaten immer genauer beherrscht, dabei aber Eigenschaften lernt, die auf unabhängigen Bildern nicht genauso gelten. Die Trainingswerte verbessern sich dann weiter, während Validation oder Test stagnieren oder schlechter werden.",
                        "Bei synthetischen Daten kann sich das auch als starke Anpassung an Renderer-Eigenheiten zeigen. Das Modell wird dann auf Renderbildern besser, ohne auf echten Fotos besser zu werden.",
                        "Ein typisches Signal ist ein weiter sinkender Train-Loss bei gleichzeitig fallender Real-Test- oder Milestone-IoU."
                ]
        },
        {
                "id": "concept:generalization",
                "category": "Grundlagen",
                "title": "Generalisierung",
                "aliases": "generalization generalisierung unbekannte daten",
                "body": [
                        "Generalisierung bezeichnet die Fähigkeit eines Modells, auf Daten gut zu funktionieren, die es nicht direkt zum Lernen gesehen hat. Genau das ist für die spätere Anwendung wichtiger als eine perfekte Leistung auf Trainingsbildern.",
                        "Validation und Test sind Versuche, diese Fähigkeit zu messen. Je unabhängiger die Daten vom Training sind, desto stärker ist die Aussage darüber, wie das Modell außerhalb seines Trainingsdatensatzes funktioniert.",
                        "Für deine Bachelorarbeit ist zusätzlich wichtig, dass reale Bilder nicht nur unbekannt sind, sondern aus einer anderen Domäne als die synthetischen Renderbilder stammen."
                ]
        },
        {
                "id": "concept:sim2real",
                "category": "Grundlagen",
                "title": "Sim-to-Real-Gap",
                "aliases": "sim to real gap domain gap synthetisch real",
                "body": [
                        "Der Sim-to-Real-Gap ist der Leistungsunterschied zwischen synthetischen Renderdaten und echten Fotos. Ein Modell kann im Renderer sehr gute Masken erzeugen und auf Realbildern trotzdem scheitern, wenn es sich auf Merkmale verlässt, die nur synthetisch vorkommen.",
                        "Ursachen können Beleuchtung, Material, Textur, Kamera, Rauschen, Perspektive oder die konkrete Darstellung der Defekte sein. Domain Randomization versucht, diese Lücke zu verkleinern, indem die synthetische Welt absichtlich vielfältiger gemacht wird.",
                        "Ein großer Abstand zwischen render_test/render_val und real_test ist ein direktes Indiz dafür, dass der Transfer in die reale Domäne problematisch ist."
                ]
        },
        {
                "id": "concept:checkpoint",
                "category": "Training",
                "title": "Checkpoint",
                "aliases": "checkpoint weights pt snapshot modellstand",
                "body": [
                        "Ein Checkpoint ist eine gespeicherte Momentaufnahme der trainierten Modellgewichte und gegebenenfalls zusätzlicher Trainingszustände. Damit kann ein bestimmter Trainingsstand später wieder geladen oder ausgewertet werden.",
                        "Milestone-Checkpoints erlauben beispielsweise den direkten Vergleich von Epoche 100, 125 und 200. Ohne gespeicherten Checkpoint kann eine alte Epoche oft nur anhand bereits erzeugter Auswertungsdateien betrachtet werden.",
                        "best.pt und last.pt sind ebenfalls Checkpoints, nur mit besonderer Bedeutung für „beste Auswahl“ beziehungsweise „letzter Stand“."
                ]
        }
];

    const GLOSSARY_STATIC = [
        ...GLOSSARY_BASE.map(entry => ({
            ...entry,
            body: [...(entry.body || []), ...(GLOSSARY_EXTRA[entry.id] || [])]
        })),
        ...GLOSSARY_FOUNDATIONS
    ];

    const glossaryStaticMap = new Map(GLOSSARY_STATIC.map(entry => [entry.id, entry]));

    function metricBaseExplanation(metricName) {
        const name = String(metricName).toLowerCase();
        if (name.includes('miou')) return glossaryStaticMap.get('concept:miou').body;
        if (/(^|[_./])iou($|[_./])/.test(name) || name === 'iou') return glossaryStaticMap.get('concept:iou').body;
        if (name.includes('dice_loss')) return glossaryStaticMap.get('concept:dice_loss').body;
        if (/(^|[_./])dice($|[_./])/.test(name) || name === 'dice') return glossaryStaticMap.get('concept:dice').body;
        if (name.includes('ce_loss') || name.includes('cross_entropy')) return glossaryStaticMap.get('concept:ce_loss').body;
        if (name.includes('aux_loss')) return glossaryStaticMap.get('concept:aux_loss').body;
        if (name.includes('recall')) return glossaryStaticMap.get('concept:recall').body;
        if (name.includes('precision')) return glossaryStaticMap.get('concept:precision').body;
        if (name.includes('pixel_accuracy') || name.includes('pixel acc') || name.includes('pixacc') || name === 'accuracy') {
            return glossaryStaticMap.get('concept:pixel_accuracy').body;
        }
        if (/^lr\s*\/\s*pg\d+$/i.test(String(metricName).trim())) {
            return [
                ...glossaryStaticMap.get('concept:learning_rate').body,
                ...glossaryStaticMap.get('concept:param_group').body,
                'Für diesen Graphen gilt deshalb: Der Verlauf soll dir zeigen, wie aggressiv oder vorsichtig der Optimizer zu jeder Epoche aktualisiert hat. Ein höherer oder niedrigerer lr/pg-Wert ist für sich genommen weder „besser“ noch „schlechter“. Interessant wird er vor allem, wenn du Trainingsinstabilität oder Unterschiede im Learning-Rate-Schedule zwischen Runs untersuchen willst.'
            ];
        }
        if (name === 'time' || name.endsWith('/time')) {
            return [
                'Diese Spalte ist eine Zeitmessung aus results.csv. Je nach Ultralytics-Version beschreibt sie typischerweise die seit Trainingsbeginn vergangene beziehungsweise kumulierte Trainingszeit.',
                'Der Graph hilft dir nur bei Performance-Fragen: Welcher Run brauchte wie lange und änderte sich die Geschwindigkeit im Verlauf? Er sagt nichts darüber aus, ob das Modell fachlich besser segmentiert.',
                'Vergleiche Laufzeiten nur unter ähnlichen Hardware- und Pipelinebedingungen. Andere Bildgröße, Batchgröße, Worker, zusätzliche Milestone-Auswertungen oder ein anderes Modell können die Zeit stark verändern.'
            ];
        }
        if (name.includes('gpu_mem')) {
            return [
                'GPU Memory bezeichnet den belegten Grafikspeicher (VRAM) während des Trainings. Darin liegen unter anderem Modellparameter, Zwischenaktivierungen und Daten des aktuellen Mini-Batches.',
                'Der Wert ist vor allem eine technische Grenze: Wird der VRAM voll, kann ein CUDA-Out-of-Memory-Fehler auftreten. Größere Bildgrößen und Batches erhöhen den Verbrauch meistens deutlich.',
                'Mehr GPU-Speicherverbrauch bedeutet nicht bessere Qualität. Er zeigt nur, wie ressourcenintensiv die aktuelle Konfiguration ist.'
            ];
        }
        return [
            'Diese Zahl wird unverändert aus einer von yolo.py beziehungsweise Ultralytics gespeicherten Datei übernommen. Die Webseite zeichnet sie lediglich gegen die Epoche und berechnet daraus keine neue Modellmetrik.',
            'Der Rohschlüssel ist wichtig, weil er häufig gleichzeitig Quelle, Klasse und Messgröße kodiert. Beispiel: „real_test / text.iou“ bedeutet reale Testauswertung, Schriftklasse, Intersection over Union.',
            'Wenn die Bedeutung eines unbekannten Schlüssels nicht aus seinem Namen ableitbar ist, sollte er nicht allein fachlich interpretiert werden. Dann ist die Script-Version beziehungsweise die erzeugende Auswertungsfunktion die maßgebliche Quelle.'
        ];
    }

    function explainMetric(key) {
        const raw = String(key);
        const lower = raw.toLowerCase();
        let category = 'Graph / Metrik';
        const context = [];

        if (lower.startsWith('train/')) {
            category = 'Graph / Training';
            context.push('Quelle: Trainingsdaten. Dieser Wert entsteht auf genau den Bildern, die das Modell zum Lernen benutzt. Er kann deshalb sehr gut werden, ohne dass unbekannte Realbilder automatisch ebenso gut funktionieren.');
        } else if (lower.startsWith('val/')) {
            category = 'Graph / Validierung';
            context.push('Quelle: interne Validierung. Diese Bilder werden zur Kontrolle ausgewertet, aber ihre Fehler werden nicht direkt benutzt, um die Gewichte in diesem Schritt zu verändern. Der Wert soll deshalb besser zeigen, wie das Modell auf nicht trainierten Beispielen funktioniert.');
        }

        const sourceDefs = [
            ['real_test', 'Quelle: reale Testdaten. Dieser Verlauf ist der wichtigste direkte Realitätscheck und sollte getrennt von Trainings- und Val-Daten interpretiert werden.'],
            ['render_val', 'Quelle: synthetischer Renderer-Validierungssplit. Er misst die Leistung innerhalb der synthetischen Domäne.'],
            ['render_test', 'Quelle: synthetischer Renderer-Testsplit. Er dient als zusätzlicher synthetischer Monitor außerhalb des eigentlichen Trainingssplits.'],
            ['real_val', 'Quelle: reale Validierungsdaten bzw. deren Auswertung. Diese können in deiner Pipeline gewichtet in die Validierung eingehen.'],
            ['test', 'Quelle: Testauswertung des jeweiligen älteren Auswertungsformats. Für die genaue Bedeutung die Script-Version des Runs mit betrachten.']
        ];
        for (const [token, text] of sourceDefs) {
            if (lower.includes(token)) {
                category = 'Graph / Milestone';
                context.push(text);
                break;
            }
        }

        const isStablePerClassIou = /^metrics\/iou_(background|defect|edge|text|component)$/i.test(raw);
        if (isStablePerClassIou) {
            context.push(
                'Diese Klassen-IoU wird von yolo_v7.4 pro Epoche aus derselben internen semantischen Validierung berechnet, aus der auch mIoU entsteht. Dadurch ist der Verlauf direkt mit der jeweiligen Epoche verknüpft und du siehst, welche Klasse einen guten oder schlechten Gesamtmittelwert verursacht.'
            );
            context.push(
                'Ist diese Klasse im betreffenden Modell deaktiviert, steht in results.csv bewusst NaN statt 0. Ein fehlender Graphpunkt bedeutet hier also „Klasse in diesem Run nicht bewertet“ und nicht „IoU = 0 %“.'
            );
        }

        const classDefs = [
            ['background', 'Klasse Hintergrund: Bewertet, wie sauber alle als Hintergrund geltenden Pixel abgegrenzt werden. Dieser Wert ist wegen der meist sehr großen Hintergrundfläche oft hoch und darf kleine Zielklassen nicht überstrahlen.'],
            ['defect', 'Klasse Fehler: Bewertet ausschließlich die Überlappung der vorhergesagten Fehlerfläche mit der Ground-Truth-Fehlerfläche. Sowohl übersehene Fehlerpixel als auch fälschlich als Fehler markierte Pixel verschlechtern diesen Wert.'],
            ['text', 'Klasse Schrift: Bewertet ausschließlich die Schriftmaske. Ein niedriger Wert kann bedeuten, dass Buchstaben ausgelassen werden, dass zu viel Umgebung als Schrift markiert wird oder beides gleichzeitig.'],
            ['edge', 'Klasse Kante: Bewertet die Kantenmaske. Weil Kanten sehr dünne Strukturen sind, können schon Verschiebungen um wenige Pixel die IoU stark reduzieren; deshalb ist die absolute Zahl strenger zu lesen als bei großen Flächen.'],
            ['component', 'Klasse Bauteil: Bewertet die grobe Segmentierung des Schäkels gegenüber dem Hintergrund. Sie gehört zum separaten Component-Modell und ist nicht automatisch in einem Detailmodell aktiv.'],
            ['all', 'Aggregation: Gesamt-/Klassenaggregation des jeweiligen Auswertungsformats. Hier werden mehrere Klassen zusammengefasst; deshalb für Ursachen immer zusätzlich die Einzelklassen ansehen.']
        ];
        for (const [token, text] of classDefs) {
            if (new RegExp(`(^|[._/])${token}($|[._/])`, 'i').test(raw)) {
                context.push(text);
                break;
            }
        }

        const base = isStablePerClassIou
            ? [
                ...glossaryStaticMap.get('concept:per_class_iou').body,
                ...glossaryStaticMap.get('concept:iou').body
              ]
            : metricBaseExplanation(raw);
        return {
            id: `metric:${raw}`,
            category,
            title: prettyMetric(raw),
            aliases: raw,
            raw,
            body: [...context, ...base]
        };
    }

    function getGlossaryEntry(id) {
        if (glossaryStaticMap.has(id)) return glossaryStaticMap.get(id);
        if (String(id).startsWith('metric:')) return explainMetric(String(id).slice(7));
        return null;
    }

    function helpButtonHtml(id, label = 'Begriff erklären') {
        return `<button type="button" class="yolo-help-btn" data-yolo-help="${esc(id)}" aria-label="${esc(label)}" title="${esc(label)}">?</button>`;
    }

    function openHelp(id) {
        const entry = getGlossaryEntry(id);
        if (!entry) return;

        elHelpCategory.textContent = entry.category || 'Begriff';
        elHelpTitle.textContent = entry.title || id;
        if (entry.raw) {
            elHelpRaw.hidden = false;
            elHelpRaw.textContent = `Rohschlüssel: ${entry.raw}`;
        } else {
            elHelpRaw.hidden = true;
            elHelpRaw.textContent = '';
        }

        elHelpBody.innerHTML = '';
        (entry.body || []).forEach(text => {
            const p = document.createElement('p');
            p.textContent = text;
            elHelpBody.appendChild(p);
        });

        elHelpModal.classList.remove('hidden');
        elHelpModal.setAttribute('aria-hidden', 'false');
    }

    function closeHelp() {
        elHelpModal.classList.add('hidden');
        elHelpModal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('[data-yolo-help]');
        if (!btn) return;
        event.preventDefault();
        event.stopPropagation();
        openHelp(btn.dataset.yoloHelp || '');
    });

    elHelpClose.addEventListener('click', closeHelp);
    elHelpClose.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') closeHelp();
    });
    elHelpModal.addEventListener('click', (event) => {
        if (event.target === elHelpModal) closeHelp();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !elHelpModal.classList.contains('hidden')) closeHelp();
    });

    function dynamicGlossaryEntries() {
        const metricKeys = new Set();
        loadedRuns.forEach(run => {
            (run.results?.numeric_columns || []).forEach(key => metricKeys.add(key));
            (run.milestones?.metric_keys || []).forEach(key => metricKeys.add(key));
            Object.keys(run.final?.metrics || {}).forEach(key => metricKeys.add(key));
        });
        return [...metricKeys]
            .sort((a, b) => a.localeCompare(b, 'de', { numeric: true }))
            .map(explainMetric);
    }

    function glossaryEntries() {
        const merged = [...GLOSSARY_STATIC, ...dynamicGlossaryEntries()];
        const seen = new Set();
        return merged.filter(entry => {
            const signature = `${entry.id}|${entry.title}`;
            if (seen.has(signature)) return false;
            seen.add(signature);
            return true;
        });
    }

    function renderGlossary() {
        const query = (elGlossarySearch.value || '').trim().toLowerCase();
        const entries = glossaryEntries().filter(entry => {
            if (!query) return true;
            const haystack = [
                entry.title,
                entry.category,
                entry.aliases,
                entry.raw,
                ...(entry.body || [])
            ].join(' ').toLowerCase();
            return haystack.includes(query);
        });

        elGlossaryCount.textContent = String(entries.length);
        elGlossaryList.innerHTML = '';

        if (!entries.length) {
            elGlossaryList.innerHTML = '<div class="yolo-glossary-empty">Kein passender Begriff gefunden.</div>';
            return;
        }

        entries.forEach(entry => {
            const article = document.createElement('article');
            article.className = 'yolo-glossary-card';

            const head = document.createElement('div');
            head.className = 'yolo-glossary-card-head';

            const titleWrap = document.createElement('div');
            const category = document.createElement('div');
            category.className = 'yolo-glossary-category';
            category.textContent = entry.category || 'Begriff';
            const title = document.createElement('h3');
            title.textContent = entry.title;
            titleWrap.append(category, title);
            head.appendChild(titleWrap);

            if (entry.raw) {
                const raw = document.createElement('code');
                raw.className = 'yolo-glossary-raw';
                raw.textContent = entry.raw;
                head.appendChild(raw);
            }

            article.appendChild(head);
            (entry.body || []).forEach(text => {
                const p = document.createElement('p');
                p.textContent = text;
                article.appendChild(p);
            });
            elGlossaryList.appendChild(article);
        });
    }

    elGlossarySearch.addEventListener('input', renderGlossary);

    /* =====================================================
     * Tabs
     * ===================================================== */
    function activateTab(name, persist = true) {
        const valid = tabButtons.some(btn => btn.dataset.yoloTab === name);
        if (!valid) name = 'runs';

        tabButtons.forEach(btn => {
            const active = btn.dataset.yoloTab === name;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        tabPanels.forEach(panel => {
            const active = panel.dataset.yoloPanel === name;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });

        if (persist) {
            try { localStorage.setItem('yolo_active_tab', name); } catch (_) {}
        }

        if (name === 'graphs') {
            requestAnimationFrame(() => {
                chartInstances.forEach(chart => {
                    try { chart.resize(); } catch (_) {}
                });
            });
        }
    }

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.yoloTab || 'runs'));
    });

    let initialTab = 'runs';
    try { initialTab = localStorage.getItem('yolo_active_tab') || 'runs'; } catch (_) {}
    activateTab(initialTab, false);

    /* =====================================================
     * Run-Auswahl
     * ===================================================== */
    function parseInitialSelection() {
        const params = new URL(location.href).searchParams;
        const fromUrl = (params.get('runs') || '')
            .split(',')
            .map(s => s.trim())
            .filter(k => byKey.has(k));

        const keys = fromUrl.length ? fromUrl : DEFAULT_KEYS;
        selected = new Set(keys.slice(0, MAX_RUNS).filter(k => byKey.has(k)));
    }

    function updateUrl() {
        const url = new URL(location.href);
        url.searchParams.delete('ajax');
        if (selected.size) {
            url.searchParams.set('runs', [...selected].join(','));
        } else {
            url.searchParams.delete('runs');
        }
        history.replaceState(null, '', url.toString());
    }

    function renderRunList() {
        const kind = elKindFilter.value || 'detail';
        elRunList.innerHTML = '';

        let visible = 0;
        CATALOG.forEach(run => {
            if (kind !== 'all' && run.kind !== kind) return;
            visible++;

            const label = document.createElement('label');
            label.className = 'yolo-run-option' + (selected.has(run.key) ? ' is-selected' : '');
            label.dataset.key = run.key;

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = selected.has(run.key);
            input.value = run.key;

            const body = document.createElement('span');
            body.className = 'yolo-run-option-body';

            const title = document.createElement('span');
            title.className = 'yolo-run-option-title';
            title.textContent = run.name;

            const badges = document.createElement('span');
            badges.className = 'yolo-run-option-badges';
            badges.innerHTML = [
                `<span class="yolo-mini-badge">${run.kind === 'component' ? 'Bauteil' : 'Detail'}</span>`,
                `<span class="yolo-mini-badge yolo-mini-badge--muted">${run.layout === 'modern' ? 'Run' : 'Legacy'}</span>`,
                run.has_results ? '<span class="yolo-mini-badge yolo-mini-badge--ok">CSV</span>' : '<span class="yolo-mini-badge yolo-mini-badge--warn">ohne CSV</span>',
                run.has_milestones ? '<span class="yolo-mini-badge yolo-mini-badge--ok">Milestones</span>' : ''
            ].join('');

            body.append(title, badges);
            label.append(input, body);

            input.addEventListener('change', () => {
                if (input.checked) {
                    if (selected.size >= MAX_RUNS) {
                        input.checked = false;
                        elSelectionMessage.textContent = `Maximal ${MAX_RUNS} Runs gleichzeitig.`;
                        return;
                    }
                    selected.add(run.key);
                } else {
                    selected.delete(run.key);
                }
                elSelectionMessage.textContent = '';
                renderRunList();
                selectionChanged();
            });

            elRunList.appendChild(label);
        });

        if (!visible) {
            elRunList.innerHTML = '<div class="yolo-run-list-empty">Keine passenden Runs.</div>';
        }
    }

    function setDataVisible(hasRuns) {
        elSummaryGrid.hidden = !hasRuns;
        elSummaryEmpty.hidden = hasRuns;
        elMetaTableWrap.hidden = !hasRuns;
        elMetaEmpty.hidden = hasRuns;
        elGraphsEmpty.hidden = hasRuns;
        if (!hasRuns) {
            elTrainingSection.hidden = true;
            elMilestoneSection.hidden = true;
            elImagesComparison.innerHTML = '';
            elImagesComparison.hidden = true;
            elImagesEmpty.hidden = false;
            elImagesEmpty.textContent = 'Wähle im ersten Tab mindestens einen Run aus.';
        }
    }

    function destroyCharts(groupPrefix = '') {
        for (const [key, chart] of chartInstances.entries()) {
            if (!groupPrefix || key.startsWith(groupPrefix)) {
                chart.destroy();
                chartInstances.delete(key);
            }
        }
    }

    async function loadSelectedRuns() {
        const serial = ++loadSerial;
        const keys = [...selected];
        elSelectedCount.textContent = String(keys.length);

        if (!keys.length) {
            loadedRuns = [];
            destroyCharts();
            elSummaryGrid.innerHTML = '';
            elDefectTypeTables.innerHTML = '';
            elDefectTypeSection.hidden = true;
            elMetaTable.innerHTML = '';
            elTrainingCharts.innerHTML = '';
            elMilestoneCharts.innerHTML = '';
            elImagesComparison.innerHTML = '';
            setDataVisible(false);
            renderGlossary();
            return;
        }

        setDataVisible(true);
        elLoading.hidden = false;
        elError.hidden = true;

        try {
            const url = new URL(location.href);
            url.search = '';
            url.searchParams.set('ajax', 'run_data');
            url.searchParams.set('runs', keys.join(','));

            const response = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'Run-Daten konnten nicht geladen werden.');
            }
            if (serial !== loadSerial) return;

            loadedRuns = Array.isArray(data.runs) ? data.runs : [];
            renderSummary();
            renderDefectTypeMetrics();
            renderMetaTable();
            rebuildTrainingMetrics();
            rebuildMilestoneMetrics();
            renderImages();
            renderGlossary();
        } catch (error) {
            if (serial !== loadSerial) return;
            elError.textContent = error instanceof Error ? error.message : String(error);
            elError.hidden = false;
        } finally {
            if (serial === loadSerial) elLoading.hidden = true;
        }
    }

    function selectionChanged() {
        updateUrl();
        elSelectedCount.textContent = String(selected.size);
        loadSelectedRuns();
    }

    /* =====================================================
     * Bildervergleich
     * ===================================================== */
    function finalTestImageUrl(runKey, imageId) {
        const url = new URL(location.href);
        url.search = '';
        url.searchParams.set('ajax', 'test_image');
        url.searchParams.set('run', runKey);
        url.searchParams.set('image', imageId);
        return url.toString();
    }

    function renderImages() {
        elImagesComparison.innerHTML = '';

        if (!loadedRuns.length) {
            elImagesComparison.hidden = true;
            elImagesEmpty.hidden = false;
            elImagesEmpty.textContent = 'Wähle im ersten Tab mindestens einen Run aus.';
            return;
        }

        const imageCatalog = new Map();
        const runImageMaps = loadedRuns.map(run => {
            const map = new Map();
            (run.test_images || []).forEach(item => {
                if (!item || !item.id) return;
                const id = String(item.id).toLowerCase();
                map.set(id, item);
                if (!imageCatalog.has(id)) {
                    imageCatalog.set(id, String(item.label || item.id));
                }
            });
            return map;
        });

        const imageIds = [...imageCatalog.keys()].sort((a, b) =>
            String(imageCatalog.get(a)).localeCompare(
                String(imageCatalog.get(b)),
                'de',
                { numeric: true, sensitivity: 'base' }
            )
        );

        if (!imageIds.length) {
            elImagesComparison.hidden = true;
            elImagesEmpty.hidden = false;
            elImagesEmpty.innerHTML =
                'Für die ausgewählten Runs wurden im Serverarchiv keine <code>Auswertung_Test/*_2x2</code>-Bilder gefunden.';
            return;
        }

        elImagesEmpty.hidden = true;
        elImagesComparison.hidden = false;

        elImagesComparison.innerHTML = imageIds.map(imageId => {
            const label = imageCatalog.get(imageId) || imageId;
            const cards = loadedRuns.map((run, runIndex) => {
                const image = runImageMaps[runIndex].get(imageId);
                const color = colors[runIndex % colors.length];

                if (!image) {
                    return `
                        <article class="yolo-image-card is-missing" style="--run-color:${color}">
                            <div class="yolo-image-card-head">
                                <span class="yolo-run-color" aria-hidden="true"></span>
                                <strong>${esc(run.name)}</strong>
                            </div>
                            <div class="yolo-image-missing">Bild in diesem Run nicht vorhanden.</div>
                        </article>
                    `;
                }

                const src = finalTestImageUrl(run.key, image.id);
                return `
                    <article class="yolo-image-card" style="--run-color:${color}">
                        <div class="yolo-image-card-head">
                            <span class="yolo-run-color" aria-hidden="true"></span>
                            <strong>${esc(run.name)}</strong>
                        </div>
                        <a class="yolo-image-link" href="${esc(src)}" target="_blank" rel="noopener">
                            <img
                                class="yolo-comparison-image"
                                src="${esc(src)}"
                                alt="${esc(label)} – ${esc(run.name)}"
                                loading="lazy"
                                decoding="async"
                            >
                        </a>
                    </article>
                `;
            }).join('');

            return `
                <section class="yolo-image-row">
                    <div class="yolo-image-row-head">
                        <h3>${esc(label)}</h3>
                        <span>${loadedRuns.length} Run${loadedRuns.length === 1 ? '' : 's'}</span>
                    </div>
                    <div
                        class="yolo-image-run-grid"
                        style="--yolo-image-cols:${Math.max(1, loadedRuns.length)}"
                    >
                        ${cards}
                    </div>
                </section>
            `;
        }).join('');
    }

    /* =====================================================
     * Run-Übersicht
     * ===================================================== */
    function renderSummary() {
        elSummaryGrid.innerHTML = loadedRuns.map((run, index) => {
            const s = run.summary || {};
            const fileBadges = Object.entries(run.files || {})
                .filter(([, present]) => present)
                .map(([name]) => `<span class="yolo-mini-badge yolo-mini-badge--ok">${esc(name.replaceAll('_', ' '))}</span>`)
                .join('');

            const finalMetrics = Object.entries(run.final?.metrics || {});
            const finalReports = Object.entries(run.final?.reports || {});

            const reportLines = finalReports.map(([source, report]) => {
                const withGroundTruth = report?.ground_truth_enabled === true;
                const gtKnown = report?.ground_truth_enabled === true || report?.ground_truth_enabled === false;
                const images = Number.isFinite(Number(report?.successful_images))
                    ? `${Number(report.successful_images)} Bilder`
                    : 'Bildanzahl unbekannt';
                const failed = Number(report?.failed_images || 0);
                const failText = failed > 0 ? ` · ${failed} fehlgeschlagen` : '';
                const stateClass = withGroundTruth ? 'has-gt' : 'no-gt';
                const stateText = withGroundTruth
                    ? 'mit Ground Truth'
                    : (gtKnown ? 'ohne Ground Truth' : 'Ground Truth unbekannt');
                return `
                    <div class="yolo-final-report-row">
                        <strong>${esc(prettyMetric(source))}</strong>
                        <span>${esc(images + failText)}</span>
                        <span class="yolo-final-report-state ${stateClass}">${esc(stateText)}</span>
                    </div>
                `;
            }).join('');

            const reportsWithoutGroundTruth = finalReports.filter(([, report]) => report?.ground_truth_enabled === false);
            const reportInfo = finalReports.length
                ? `
                    <div class="yolo-final-report-info">
                        ${reportLines}
                        ${reportsWithoutGroundTruth.length ? `
                            <div class="yolo-final-report-note">
                                Mindestens eine Abschlussauswertung wurde ohne Ground-Truth-Masken erzeugt. Für diese Quelle kann es deshalb keine objektiven IoU-/Dice-Werte geben; die gespeicherte Auswertung enthält dort nur Vorhersagen bzw. Vergleichsbilder.
                                ${helpButtonHtml('concept:ground_truth', 'Ground Truth und fehlende finale Metriken erklären')}
                            </div>
                        ` : ''}
                    </div>
                `
                : '';

            let finalKpis = '';
            if (finalMetrics.length) {
                const metricCards = finalMetrics.map(([key, value]) => `
                    <div class="yolo-kpi">
                        <div class="yolo-kpi-labelrow">
                            <span class="yolo-kpi-label">${esc(prettyMetric(key))}</span>
                            ${helpButtonHtml(`metric:${key}`, `${prettyMetric(key)} erklären`)}
                        </div>
                        <strong>${metricLooksLikeRatio(key) ? fmtPercent(value) : fmtNumber(value)}</strong>
                    </div>
                `).join('');

                finalKpis = `${metricCards}${reportInfo}`;
            } else if (finalReports.length) {
                const allWithoutGroundTruth = finalReports.every(([, report]) => report?.ground_truth_enabled === false);
                const noMetricReason = allWithoutGroundTruth
                    ? 'Es gibt absichtlich keine finale Qualitätsmetrik: Alle gefundenen Abschlussauswertungen wurden ohne Ground Truth erzeugt.'
                    : 'Die Abschlussauswertung ist vorhanden, enthält aber keine auslesbaren aggregierten Qualitätsmetriken.';
                finalKpis = `
                    ${reportInfo}
                    <div class="yolo-final-no-metrics">${esc(noMetricReason)}</div>
                `;
            } else {
                finalKpis = '<div class="yolo-no-final">Für diesen Run wurde keine finale Auswertung gefunden.</div>';
            }

            const color = colors[index % colors.length];
            return `
                <article class="yolo-summary-card" style="--run-color:${color}">
                    <div class="yolo-summary-card-head">
                        <div>
                            <div class="yolo-summary-run">${esc(run.name)}</div>
                            <div class="yolo-summary-sub">${esc(s.script || '—')} · ${esc(s.status || '—')}</div>
                        </div>
                        <span class="yolo-run-color" aria-hidden="true"></span>
                    </div>

                    <dl class="yolo-summary-dl">
                        <div><dt>Klassen</dt><dd>${esc(s.klassen || '—')}</dd></div>
                        <div><dt>Renderquelle</dt><dd>${esc(s.quell_render_runs || '—')}</dd></div>
                        <div><dt>Modell</dt><dd>${esc(s.modell || '—')}</dd></div>
                        <div><dt>Epoche</dt><dd>${esc(s.epoche_letzte ?? '—')} / ${esc(s.epochen_soll ?? '—')}</dd></div>
                        <div><dt>Weighted Dice</dt><dd>${esc(s.weighted_dice || '—')}</dd></div>
                    </dl>

                    <div class="yolo-final-title">Finale Auswertung</div>
                    <div class="yolo-kpi-grid yolo-final-content">${finalKpis}</div>
                    <div class="yolo-file-badges">${fileBadges}</div>
                </article>
            `;
        }).join('');
    }

    /* =====================================================
     * Fehlertyp-spezifische Renderauswertung (v7.6+)
     * ===================================================== */
    function defectTypeMetricCell(values) {
        if (!values || typeof values !== 'object') {
            return '<span style="opacity:.55">—</span>';
        }

        const iou = Number(values.iou);
        const dice = Number(values.dice);
        const recall = Number(values.recall);
        const images = Number(values.images_with_gt);

        const lines = [];
        if (Number.isFinite(iou)) {
            lines.push(`<strong>IoU ${fmtPercent(iou, 1)}</strong>`);
        }

        const secondary = [];
        if (Number.isFinite(dice)) secondary.push(`Dice ${fmtPercent(dice, 1)}`);
        if (Number.isFinite(recall)) secondary.push(`Recall ${fmtPercent(recall, 1)}`);
        if (secondary.length) {
            lines.push(`<span style="font-size:.78rem; opacity:.78">${secondary.join(' · ')}</span>`);
        }

        if (Number.isFinite(images)) {
            lines.push(`<span style="font-size:.72rem; opacity:.58">${images} Bild${images === 1 ? '' : 'er'} mit GT</span>`);
        }

        return lines.length
            ? `<div style="display:grid; gap:3px">${lines.join('')}</div>`
            : '<span style="opacity:.55">—</span>';
    }

    function defectTypeIdsForSource(source) {
        const ids = new Set();
        loadedRuns.forEach(run => {
            const values = run.final?.per_defect_types?.[source] || {};
            Object.keys(values).forEach(id => ids.add(String(id).toUpperCase()));
        });

        return [...ids].sort((a, b) => {
            const na = Number(String(a).replace(/\D/g, ''));
            const nb = Number(String(b).replace(/\D/g, ''));
            if (Number.isFinite(na) && Number.isFinite(nb) && na !== nb) return na - nb;
            return String(a).localeCompare(String(b), 'de', { numeric: true });
        });
    }

    function defectTypeSourceTable(source, title) {
        const defectIds = defectTypeIdsForSource(source);
        if (!defectIds.length) return '';

        const head = `
            <thead><tr>
                <th>Fehlertyp</th>
                ${loadedRuns.map((run, i) => `
                    <th>
                        <span class="yolo-table-run-dot" style="--run-color:${colors[i % colors.length]}"></span>
                        ${esc(run.name)}
                    </th>
                `).join('')}
            </tr></thead>`;

        const body = defectIds.map(defectId => `
            <tr>
                <th scope="row">${esc(defectId)}</th>
                ${loadedRuns.map(run => {
                    const values = run.final?.per_defect_types?.[source]?.[defectId] || null;
                    return `<td>${defectTypeMetricCell(values)}</td>`;
                }).join('')}
            </tr>
        `).join('');

        return `
            <div style="margin-top:14px">
                <h3 style="margin:0 0 8px; font-size:1rem">${esc(title)}</h3>
                <div class="yolo-meta-table-wrap">
                    <table class="yolo-meta-table">
                        ${head}
                        <tbody>${body}</tbody>
                    </table>
                </div>
            </div>
        `;
    }

    function renderDefectTypeMetrics() {
        const renderVal = defectTypeSourceTable('render_val', 'Render-Val · pro Fehlertyp');
        const renderTest = defectTypeSourceTable('render_test', 'Render-Test · pro Fehlertyp');
        const html = renderVal + renderTest;

        if (!html) {
            elDefectTypeTables.innerHTML = '';
            elDefectTypeSection.hidden = true;
            return;
        }

        elDefectTypeTables.innerHTML = html;
        elDefectTypeSection.hidden = false;
    }

    /* =====================================================
     * Konfiguration
     * ===================================================== */
    const META_ROWS = [
        ['typ', 'Typ', 'text', 'cfg:typ'],
        ['struktur', 'Struktur', 'text', 'cfg:struktur'],
        ['script', 'Script-Version', 'text', 'cfg:script'],
        ['status', 'Status', 'text', 'cfg:status'],
        ['quell_render_runs', 'Render-Run(s)', 'text', 'cfg:source_runs'],
        ['klassen', 'Trainingsklassen', 'text', 'cfg:classes'],
        ['weighted_dice', 'Weighted Dice', 'text', 'cfg:weighted_dice'],
        ['modell', 'Basismodell', 'text', 'cfg:model'],
        ['epochen_soll', 'Epochen Soll', 'number', 'cfg:epochs_requested'],
        ['epoche_letzte', 'Letzte Epoche', 'number', 'cfg:last_epoch'],
        ['bildgroesse', 'Bildgröße', 'number', 'cfg:image_size'],
        ['batch', 'Batch', 'number', 'cfg:batch'],
        ['worker', 'Worker', 'number', 'cfg:workers'],
        ['real_val_bilder', 'Real-Val Bilder', 'number', 'cfg:real_val_images'],
        ['real_val_gewichtete_samples', 'Real-Val gewichtet', 'number', 'cfg:real_val_weighted'],
        ['real_val_anteil', 'Real-Val Anteil', 'percent', 'cfg:real_val_fraction'],
        ['semantic_val_miou', 'Finale interne Val mIoU', 'percent', 'cfg:semantic_miou'],
        ['semantic_val_pixacc', 'Finale interne Val PixAcc', 'percent', 'cfg:semantic_pixacc'],
        ['gestartet', 'Gestartet', 'date', 'cfg:started'],
        ['aktualisiert', 'Aktualisiert', 'date', 'cfg:updated'],
        ['results_zeilen', 'results.csv Zeilen', 'number', 'cfg:results_rows']
    ];

    function formatMeta(value, type) {
        if (value === null || value === undefined || value === '') return '—';
        if (type === 'percent') return fmtPercent(value);
        if (type === 'date') return fmtDate(value);
        if (type === 'number') return fmtNumber(value, 2);
        return String(value);
    }

    function renderMetaTable() {
        const head = `
            <thead><tr>
                <th>Parameter</th>
                ${loadedRuns.map((run, i) => `<th><span class="yolo-table-run-dot" style="--run-color:${colors[i % colors.length]}"></span>${esc(run.name)}</th>`).join('')}
            </tr></thead>`;

        const body = META_ROWS.map(([key, label, type, helpId]) => `
            <tr>
                <th scope="row">
                    <span class="yolo-meta-label">${esc(label)}</span>
                    ${helpButtonHtml(helpId, `${label} erklären`)}
                </th>
                ${loadedRuns.map(run => `<td>${esc(formatMeta(run.summary?.[key], type))}</td>`).join('')}
            </tr>
        `).join('');

        elMetaTable.innerHTML = head + `<tbody>${body}</tbody>`;
    }

    /* =====================================================
     * Graphen
     * ===================================================== */
    function allTrainingMetricKeys() {
        const set = new Set();
        loadedRuns.forEach(run => {
            (run.results?.numeric_columns || []).forEach(key => set.add(key));
        });
        return [...set].sort((a, b) => a.localeCompare(b, 'de', { numeric: true }));
    }

    function trainingMetricHasFiniteData(key) {
        return loadedRuns.some(run =>
            (run.results?.rows || []).some(row => {
                const value = Number(row?.[key]);
                return Number.isFinite(value);
            })
        );
    }

    function defaultTrainingMetrics(keys) {
        // Fuer neue v7.4-Runs zuerst die fachlich interessanten Einzelklassen-IoUs.
        // Deaktivierte Klassen besitzen zwar absichtlich eine stabile Spalte, dort
        // steht aber NaN. Solche Spalten werden nicht automatisch angehakt.
        const priorities = [
            /^metrics\/iou_defect$/i,
            /^metrics\/iou_text$/i,
            /^metrics\/iou_edge$/i,
            /^metrics\/iou_background$/i,
            /^metrics\/iou_component$/i,
            /metrics\/mIoU/i,
            /pixel_accuracy|pixel_acc|pixacc/i,
            /^train\/dice_loss$/i,
            /^train\/ce_loss$/i,
            /^val\/dice_loss$/i,
            /^val\/ce_loss$/i,
            /dice_loss/i,
            /ce_loss/i
        ];
        const chosen = [];
        priorities.forEach(re => {
            const hit = keys.find(k =>
                re.test(k)
                && !chosen.includes(k)
                && trainingMetricHasFiniteData(k)
            );
            if (hit && chosen.length < 4) chosen.push(hit);
        });

        if (!chosen.length) {
            chosen.push(
                ...keys
                    .filter(trainingMetricHasFiniteData)
                    .slice(0, 4)
            );
        }
        return chosen;
    }

    function buildMetricPicker(container, keys, selectedSet, onChange) {
        container.innerHTML = '';
        keys.forEach(key => {
            const wrap = document.createElement('div');
            wrap.className = 'yolo-metric-chip-wrap';

            const label = document.createElement('label');
            label.className = 'yolo-metric-chip' + (selectedSet.has(key) ? ' is-active' : '');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = selectedSet.has(key);
            const text = document.createElement('span');
            text.textContent = prettyMetric(key);
            label.append(input, text);

            const help = document.createElement('button');
            help.type = 'button';
            help.className = 'yolo-help-btn yolo-help-btn--chip';
            help.dataset.yoloHelp = `metric:${key}`;
            help.textContent = '?';
            help.title = `${prettyMetric(key)} erklären`;
            help.setAttribute('aria-label', help.title);

            input.addEventListener('change', () => {
                if (input.checked) selectedSet.add(key);
                else selectedSet.delete(key);
                label.classList.toggle('is-active', input.checked);
                onChange();
            });

            wrap.append(label, help);
            container.appendChild(wrap);
        });
    }

    function trainingSeries(run, metric) {
        const rows = run.results?.rows || [];
        const epochKey = run.results?.epoch_key;
        const epochOffset = Number(run.results?.epoch_offset || 0);

        return rows.map((row, index) => {
            const rawX = epochKey ? Number(row[epochKey]) : NaN;
            const x = Number.isFinite(rawX) ? rawX + epochOffset : index + 1;
            const y = Number(row[metric]);
            return Number.isFinite(y) ? { x, y } : null;
        }).filter(Boolean);
    }

    function createLineChart(canvas, metric, datasets, ratio, groupKey) {
        const chart = new Chart(canvas, {
            type: 'line',
            data: { datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                parsing: false,
                animation: false,
                normalized: true,
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const value = Number(ctx.parsed.y);
                                return `${ctx.dataset.label}: ${ratio ? fmtPercent(value, 2) : fmtNumber(value, 6)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'linear',
                        title: { display: true, text: 'Epoche' },
                        ticks: { precision: 0 }
                    },
                    y: {
                        beginAtZero: false,
                        title: { display: true, text: prettyMetric(metric) },
                        ticks: ratio ? {
                            callback: (v) => `${Math.round(Number(v) * 100)} %`
                        } : {}
                    }
                }
            }
        });
        chartInstances.set(groupKey, chart);
    }

    function renderTrainingCharts() {
        destroyCharts('training:');
        elTrainingCharts.innerHTML = '';

        const selectedMetrics = [...trainingMetricsSelected];
        if (!selectedMetrics.length) {
            elTrainingCharts.innerHTML = '<div class="yolo-chart-empty">Keine Trainingsmetrik ausgewählt.</div>';
            return;
        }

        selectedMetrics.forEach(metric => {
            const card = document.createElement('article');
            card.className = 'yolo-chart-card';

            const head = document.createElement('div');
            head.className = 'yolo-chart-card-head';
            const title = document.createElement('h3');
            title.textContent = prettyMetric(metric);
            const help = document.createElement('button');
            help.type = 'button';
            help.className = 'yolo-help-btn';
            help.dataset.yoloHelp = `metric:${metric}`;
            help.textContent = '?';
            help.title = `${prettyMetric(metric)} erklären`;
            help.setAttribute('aria-label', help.title);
            head.append(title, help);

            const wrap = document.createElement('div');
            wrap.className = 'yolo-chart-canvas';
            const canvas = document.createElement('canvas');
            wrap.appendChild(canvas);
            card.append(head, wrap);
            elTrainingCharts.appendChild(card);

            const datasets = loadedRuns.map((run, index) => ({
                label: run.name,
                data: trainingSeries(run, metric),
                borderColor: colors[index % colors.length],
                backgroundColor: colors[index % colors.length],
                borderWidth: 2.2,
                pointRadius: 0,
                pointHoverRadius: 4,
                spanGaps: false,
                tension: 0.08
            })).filter(ds => ds.data.length);

            if (!datasets.length) {
                card.classList.add('is-empty');
                wrap.innerHTML = '<div class="yolo-chart-empty">Für diese Auswahl keine Werte.</div>';
                return;
            }

            createLineChart(canvas, metric, datasets, metricLooksLikeRatio(metric), `training:${metric}`);
        });
    }

    function rebuildTrainingMetrics() {
        const keys = allTrainingMetricKeys();
        const stillValid = new Set([...trainingMetricsSelected].filter(k => keys.includes(k)));
        trainingMetricsSelected = stillValid.size ? stillValid : new Set(defaultTrainingMetrics(keys));

        buildMetricPicker(elTrainingMetricPicker, keys, trainingMetricsSelected, renderTrainingCharts);
        elTrainingSection.hidden = keys.length === 0;
        if (keys.length) renderTrainingCharts();
        else {
            destroyCharts('training:');
            elTrainingCharts.innerHTML = '';
        }
        refreshGraphEmpty();
    }

    function allMilestoneMetricKeys() {
        const set = new Set();
        loadedRuns.forEach(run => {
            (run.milestones?.metric_keys || []).forEach(key => set.add(key));
        });
        return [...set].sort((a, b) => a.localeCompare(b, 'de', { numeric: true }));
    }

    function defaultMilestoneMetrics(keys) {
        const preferred = keys.filter(k => /render_test.*(text|defect).*iou/i.test(k));
        const second = keys.filter(k => /render_val.*(text|defect).*iou/i.test(k));
        const old = keys.filter(k => /test.*(text|defect).*iou/i.test(k));
        const pool = [...preferred, ...second, ...old, ...keys];
        return [...new Set(pool)].slice(0, 4);
    }

    function milestoneSeries(run, metric) {
        return (run.milestones?.points || []).map(point => {
            const x = Number(point.epoch);
            const y = Number(point.metrics?.[metric]);
            return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
        }).filter(Boolean);
    }

    function renderMilestoneCharts() {
        destroyCharts('milestone:');
        elMilestoneCharts.innerHTML = '';

        const selectedMetrics = [...milestoneMetricsSelected];
        if (!selectedMetrics.length) {
            elMilestoneCharts.innerHTML = '<div class="yolo-chart-empty">Keine Milestone-Metrik ausgewählt.</div>';
            return;
        }

        selectedMetrics.forEach(metric => {
            const card = document.createElement('article');
            card.className = 'yolo-chart-card';

            const head = document.createElement('div');
            head.className = 'yolo-chart-card-head';
            const title = document.createElement('h3');
            title.textContent = prettyMetric(metric);
            const help = document.createElement('button');
            help.type = 'button';
            help.className = 'yolo-help-btn';
            help.dataset.yoloHelp = `metric:${metric}`;
            help.textContent = '?';
            help.title = `${prettyMetric(metric)} erklären`;
            help.setAttribute('aria-label', help.title);
            head.append(title, help);

            const wrap = document.createElement('div');
            wrap.className = 'yolo-chart-canvas';
            const canvas = document.createElement('canvas');
            wrap.appendChild(canvas);
            card.append(head, wrap);
            elMilestoneCharts.appendChild(card);

            const datasets = loadedRuns.map((run, index) => ({
                label: run.name,
                data: milestoneSeries(run, metric),
                borderColor: colors[index % colors.length],
                backgroundColor: colors[index % colors.length],
                borderWidth: 2.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                spanGaps: false,
                tension: 0
            })).filter(ds => ds.data.length);

            if (!datasets.length) {
                card.classList.add('is-empty');
                wrap.innerHTML = '<div class="yolo-chart-empty">Für diese Auswahl keine Werte.</div>';
                return;
            }

            createLineChart(canvas, metric, datasets, metricLooksLikeRatio(metric), `milestone:${metric}`);
        });
    }

    function refreshGraphEmpty() {
        if (!loadedRuns.length) {
            elGraphsEmpty.hidden = false;
            elGraphsEmpty.textContent = 'Wähle im ersten Tab mindestens einen Run aus.';
            return;
        }
        const hasAny = !elTrainingSection.hidden || !elMilestoneSection.hidden;
        elGraphsEmpty.hidden = hasAny;
        if (!hasAny) elGraphsEmpty.textContent = 'Für die ausgewählten Runs wurden keine graphisch darstellbaren Metriken gefunden.';
    }

    function rebuildMilestoneMetrics() {
        const keys = allMilestoneMetricKeys();
        const stillValid = new Set([...milestoneMetricsSelected].filter(k => keys.includes(k)));
        milestoneMetricsSelected = stillValid.size ? stillValid : new Set(defaultMilestoneMetrics(keys));

        buildMetricPicker(elMilestoneMetricPicker, keys, milestoneMetricsSelected, renderMilestoneCharts);
        elMilestoneSection.hidden = keys.length === 0;
        if (keys.length) renderMilestoneCharts();
        else {
            destroyCharts('milestone:');
            elMilestoneCharts.innerHTML = '';
        }
        refreshGraphEmpty();
    }

    elKindFilter.addEventListener('change', renderRunList);

    parseInitialSelection();
    renderRunList();
    renderGlossary();
    selectionChanged();
})();
</script>

</body>
</html>
