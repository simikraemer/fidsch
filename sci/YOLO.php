<?php

/**
 * sci/YOLO.php – YOLO-Semantic Auswertung (v9.3 + ältere zentrale Läufe)
 * Read-only: liest Run-Metadaten, CSVs, JSON-Berichte und finale Vergleichsbilder.
 * Kein Zugriff auf frei übergebene Dateipfade: AJAX verwendet ausschließlich
 * im serverseitigen Katalog aufgelöste Run-Keys und Bildkennungen.
 */
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
const YOLO_META_ROOT = '/storage/Dokumente/RWTH/Bachelorarbeit/Schaekel/YOLO';
const YOLO_MAX_SELECTED_RUNS = 6;
const YOLO_MAX_IMAGES_PER_RUN = 300;
function yolo_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function yolo_arr(mixed $v): array {
    return is_array($v) ? $v : [];
}

function yolo_json_file(?string $path): array {
    if (!$path || !is_file($path) || !is_readable($path))
        return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '')
        return [];
    return yolo_arr(json_decode($raw, true));
}

function yolo_latest(string $pattern): ?string {
    $files = array_filter(glob($pattern) ?: [], static fn($p) => is_file($p) && is_readable($p));
    if (!$files)
        return null;
    usort($files, static function($a, $b) {
        $d = (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0);
        return $d ?: strnatcasecmp(basename($b), basename($a));
    });
    return reset($files) ?: null;
}

function yolo_first_file(array $paths): ?string {
    foreach ($paths as $p)
        if (is_string($p) && is_file($p) && is_readable($p))
            return $p;
    return null;
}

function yolo_path(array $a, string $key, mixed $default = null): mixed {
    $v = $a;
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v))
            return $default;
        $v = $v[$part];
    }
    return $v;
}

function yolo_choose(mixed ...$options): mixed {
    foreach ($options as $o) {
        if ($o === null || $o === '' || $o === [])
            continue;
        return $o;
    }
    return null;
}

function yolo_number(mixed $v): ?float {
    if (is_int($v) || is_float($v))
        return is_finite((float) $v) ? (float) $v : null;
    if (!is_string($v))
        return null;
    $v = trim($v);
    if ($v === '' || in_array(strtolower($v), ['nan', 'none', 'null', 'inf', '-inf', 'infinity'], true))
        return null;
    if (str_contains($v, ',') && !str_contains($v, '.'))
        $v = str_replace(',', '.', $v);
    return is_numeric($v) && is_finite((float) $v) ? (float) $v : null;
}

function yolo_bool(mixed $v): ?bool {
    if (is_bool($v))
        return $v;
    if (is_numeric($v))
        return (bool) $v;
    if (is_string($v)) {
        if (in_array(strtolower(trim($v)), ['true', 'yes', 'ja', '1'], true))
            return true;
        if (in_array(strtolower(trim($v)), ['false', 'no', 'nein', '0'], true))
            return false;
    }
    return null;
}

function yolo_yaml_scalar(string $raw): mixed {
    $raw = trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
    if (strlen($raw) >= 2 && (($raw[0] === "'" && str_ends_with($raw, "'")) || ($raw[0] === '"' && str_ends_with($raw, '"'))))
        $raw = substr($raw, 1, - 1);
    if (in_array(strtolower($raw), ['none', 'null', '~'], true))
        return null;
    if (in_array(strtolower($raw), ['true', 'false', 'yes', 'no', 'ja', 'nein'], true))
        return yolo_bool($raw);
    $num = yolo_number($raw);
    if ($num !== null)
        return $num;
    if (str_starts_with($raw, '[') && str_ends_with($raw, ']'))
        return array_map('trim', explode(',', trim($raw, '[]')));
    return $raw;
}

function yolo_yaml(?string $path): array {
    if (!$path || !is_file($path) || !is_readable($path))
        return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines))
        return [];
    $data = [];
    foreach ($lines as $line)
        if (preg_match('/^([A-Za-z0-9_.\/\-]+):\s*(.*)$/u', (string) $line, $m))
            $data[$m[1]] = yolo_yaml_scalar($m[2]);
    return $data;
}

function yolo_csv(?string $path): array {
    $empty = [
        'headers' => [],
        'rows' => [],
        'numeric_columns' => [],
        'epoch_key' => null,
        'epoch_offset' => 0,
        'row_count' => 0,
        'last' => []
    ];
    if (!$path || !is_file($path) || !is_readable($path))
        return $empty;
    $fh = @fopen($path, 'rb');
    if (!$fh)
        return $empty;
    $line = fgets($fh);
    if (!is_string($line)) {
        fclose($fh);
        return $empty;
    }
    $best = ',';
    $max = 0;
    foreach ([',', ';', "\t"] as $del) {
        $n = count(str_getcsv($line, $del, '"', ''));
        if ($n > $max) {
            $max = $n;
            $best = $del;
        }
    }
    $heads = array_map(
        static fn($s) => trim(
            preg_replace('/^\xEF\xBB\xBF/', '', (string) $s) ?? (string) $s
        ),
        str_getcsv(trim($line, "\r\n"), $best, '"', '')
    );
    if (!$heads) {
        fclose($fh);
        return $empty;
    }
    $rows = [];
    $numeric = [];
    $seen = [];
    $last = [];
    foreach ($heads as $h) {
        $numeric[$h] = 0;
        $seen[$h] = 0;
    }
    while (($cells = fgetcsv($fh, 0, $best, '"', '')) !== false) {
        if ($cells === [null])
            continue;
        $row = [];
        foreach ($heads as $i => $h) {
            $v = trim((string)($cells[$i] ?? ''));
            $num = yolo_number($v);
            $row[$h] = $v === '' ? null : ($num ?? $v);
            if ($v !== '') {
                $seen[$h]++;
                if ($num !== null)
                    $numeric[$h]++;
            }
        }
        $rows[] = $row;
        $last = $row;
    }
    fclose($fh);
    $epochKey = null;
    foreach ($heads as $h)
        if (strtolower($h) === 'epoch' || str_ends_with(strtolower($h), '/epoch')) {
        $epochKey = $h;
        break;
    }
    $epochOffset = 0;
    if ($epochKey !== null && $rows && yolo_number($rows[0][$epochKey] ?? null) === 0.0)
        $epochOffset = 1;
    $cols = [];
    foreach ($heads as $h) {
        if ($h === $epochKey)
            continue;
        $stable = (bool) preg_match('~^metrics/(?:iou_|dice_|precision_|recall_|f2_|miou|pixel_accuracy)~i', $h);
        if ($stable || ($seen[$h] > 0 && $numeric[$h] / $seen[$h] >= .8))
            $cols[] = $h;
    }
    return [
        'headers' => $heads,
        'rows' => $rows,
        'numeric_columns' => $cols,
        'epoch_key' => $epochKey,
        'epoch_offset' => $epochOffset,
        'row_count' => count($rows),
        'last' => $last
    ];
}

function yolo_class_key(string $key): string {
    return match (strtolower(trim($key))) {
        'defect', 'fehler', 'defects' => 'defect',
         'text', 'schrift', 'inscription' => 'text',
         'edge', 'kante' => 'edge',
         'background', 'hintergrund', 'bg' => 'background',
         'component', 'bauteil', 'part' => 'component',
         default => strtolower(trim($key))
    };
}

function yolo_counts_metrics(array $c): array {
    $tp = yolo_number(yolo_choose($c['true_positive'] ?? null, $c['intersection'] ?? null));
    $fp = yolo_number($c['false_positive'] ?? null);
    $fn = yolo_number($c['false_negative'] ?? null);
    $tn = yolo_number($c['true_negative'] ?? null);
    $pred = yolo_number($c['prediction'] ?? null);
    $gt = yolo_number($c['ground_truth'] ?? null);
    if ($tp === null)
        return [];
    if ($fp === null && $pred !== null)
        $fp = max(0., $pred - $tp);
    if ($fn === null && $gt !== null)
        $fn = max(0., $gt - $tp);
    if ($fp === null || $fn === null) {
        $union = yolo_number($c['union'] ?? null);
        if ($union !== null && $pred !== null && $gt !== null) {
            $fp = max(0., $pred - $tp);
            $fn = max(0., $gt - $tp);
        }
    }
    if ($fp === null || $fn === null)
        return [];
    $den = $tp + $fp + $fn;
    $predDen = $tp + $fp;
    $gtDen = $tp + $fn;
    $diceDen = 2 * $tp + $fp + $fn;
    $f2Den = 5 * $tp + $fp + 4 * $fn;
    $empty = ($predDen == 0. && $gtDen == 0.);
    $out = [
        'iou' => $den > 0 ? $tp / $den : ($empty ? 1. : null),
        'dice' => $diceDen > 0 ? 2 * $tp / $diceDen : ($empty ? 1. : null),
        'f1' => $diceDen > 0 ? 2 * $tp / $diceDen : ($empty ? 1. : null),
        'precision' => $predDen > 0 ? $tp / $predDen : null,
        'recall' => $gtDen > 0 ? $tp / $gtDen : null,
        'f2' => $f2Den > 0 ? 5 * $tp / $f2Den : ($empty ? 1. : null),
        'pixel_accuracy' => $tn !== null && $den + $tn > 0 ? ($tp + $tn) / ($den + $tn): null,
    ];
    return $out;
}

function yolo_normalize_metrics(array $src, array $counts = []): array {
    $keys = ['iou', 'dice', 'f1', 'precision', 'recall', 'f2', 'pixel_accuracy'];
    $calc = yolo_counts_metrics($counts);
    $out = [];
    foreach ($keys as $key)
        $out[$key] = yolo_number(yolo_choose(
            $src[$key] ?? null,
            $key === 'f2' ? ($src['f_beta'] ?? null) : null,
            $key === 'f1' ? ($src['dice'] ?? null) : null,
            $calc[$key] ?? null
        ));
    return $out;
}

function yolo_stat_normalize(array $raw): array {
    $out = [];
    foreach (['iou', 'dice', 'f1', 'precision', 'recall', 'f2', 'pixel_accuracy'] as $metric) {
        $stat = yolo_arr($raw[$metric] ?? ($metric === 'f2' ? ($raw['f_beta'] ?? []): []));
        $out[$metric] = [];
        foreach (['mean', 'median', 'std', 'min', 'max', 'n'] as $part)
            $out[$metric][$part] = yolo_number($stat[$part] ?? null);
    }
    return $out;
}

function yolo_normalize_class(array $raw): array {
    $counts = yolo_arr($raw['counts'] ?? []);
    $micro = yolo_arr(yolo_choose($raw['micro_metrics'] ?? null, $raw['metrics'] ?? null, []));
    $statistics = yolo_arr($raw['per_image_statistics'] ?? []);
    return [
        'micro' => yolo_normalize_metrics($micro, $counts),
        'counts' => $counts,
        'image_statistics' => yolo_stat_normalize($statistics)
    ];
}

function yolo_mode(array $report, string $mode): array {
    $block = yolo_arr(yolo_path($report, "evaluations.$mode", []));
    $rawClasses = yolo_arr($block['classes'] ?? []);
    $legacyMetrics = yolo_arr($report[$mode === 'strict' ? 'aggregate_metrics_strict' : 'aggregate_metrics_boundary'] ?? []);
    if ($mode === 'strict' && !$legacyMetrics)
        $legacyMetrics = yolo_arr($report['aggregate_metrics'] ?? []);
    $legacyCounts = yolo_arr($report[$mode === 'strict' ? 'aggregate_counts_strict' : 'aggregate_counts_boundary'] ?? []);
    if ($mode === 'strict' && !$legacyCounts)
        $legacyCounts = yolo_arr($report['aggregate_counts'] ?? []);
    foreach (array_unique(array_merge(array_keys($legacyMetrics), array_keys($legacyCounts))) as $name) {
        if (!isset($rawClasses[$name]))
            $rawClasses[$name] = ['micro_metrics' => yolo_arr($legacyMetrics[$name] ?? []), 'counts' => yolo_arr($legacyCounts[$name] ?? [])];
    }
    $classes = [];
    foreach ($rawClasses as $name => $raw) {
        if (!is_array($raw))
            continue;
        $classes[yolo_class_key((string) $name)] = yolo_normalize_class($raw);
    }
    $miou = yolo_number(yolo_choose(
        $block['miou_micro'] ?? null,
        $block['miou'] ?? null,
        yolo_path($report, 'summary_metrics.miou_' . $mode),
        $mode === 'strict' ? yolo_path($report, 'summary_metrics.miou'): null
    ));

    // Für alte Berichte nur dann ableiten, wenn beide positiven Klassen vorhanden sind.
    if ($miou === null && isset($classes['defect'], $classes['text'])) {
        $a = $classes['defect']['micro']['iou'];
        $b = $classes['text']['micro']['iou'];
        if ($a !== null && $b !== null)
            $miou = ($a + $b) / 2.;
    }
    return ['classes' => $classes, 'miou' => $miou];
}

function yolo_report(?string $file): array {
    $raw = yolo_json_file($file);
    if (!$raw)
        return ['available' => false];
    $gt = yolo_bool($raw['ground_truth_enabled'] ?? null);
    $strict = yolo_mode($raw, 'strict');
    $boundary = yolo_mode($raw, 'boundary');
    $hasMetrics = false;
    foreach (['strict' => $strict, 'boundary' => $boundary] as $mode) {
        if ($mode['miou'] !== null)
            $hasMetrics = true;
        foreach ($mode['classes'] as $c)
            if (array_filter($c['micro'], static fn($v) => $v !== null))
                $hasMetrics = true;
    }

    // Ein Bericht ohne GT darf keine vermeintliche Gütemetrik ausgeben.
    if ($gt === false) {
        $hasMetrics = false;
        $strict = ['classes' => [], 'miou' => null];
        $boundary = ['classes' => [], 'miou' => null];
    }
    $failure = $raw['failed_images'] ?? null;
    $failureCount = is_array($failure) ? count($failure): yolo_number($failure);
    return [
        'available' => true,
        'ground_truth_enabled' => $gt,
        'has_metrics' => $hasMetrics,
        'strict' => $strict,
        'boundary' => $boundary,
        'successful_images' => yolo_number($raw['successful_images'] ?? null),
        'expected_images' => yolo_number($raw['expected_images'] ?? null),
        'failed_images' => $failureCount,
        'complete' => yolo_bool($raw['complete'] ?? null),
        'domain' => $raw['domain'] ?? null,
        'checkpoint' => isset($raw['detail_weights']) ? str_replace('\\', '/', (string) $raw['detail_weights']): null,
        'epoch_label' => $raw['epoch_label'] ?? null,
        'script_version' => $raw['script_version'] ?? null,
        'architecture_mode' => $raw['architecture_mode'] ?? null,
        'mask_encoding' => $raw['mask_encoding'] ?? null,
        'active_detail_classes' => $raw['active_detail_classes'] ?? null,
        'boundary_band' => yolo_arr($raw['boundary_band'] ?? []),
        'per_defect_type_metrics' => yolo_arr($raw['per_defect_type_metrics'] ?? []),
    ];
}

function yolo_final_reports(string $root): array {
    $spec = [
        'real_test' => ['Auswertung_Test', 'Auswertung_Real_Test', 'Auswertung_Realtest'],
        'synthetic_test' => ['Auswertung_Render_Test', 'Auswertung_Synth_Test', 'Auswertung_Synthetic_Test'],
        'real_val' => ['Auswertung_Real_Val', 'Auswertung_Real_Validation'],
        'synthetic_val' => ['Auswertung_Render_Val', 'Auswertung_Synth_Val'],
    ];
    $out = [];
    foreach ($spec as $key => $dirs) {
        $file = null;
        foreach ($dirs as $dir)
            if (is_file($root . '/' . $dir . '/auswertung.json')) {
            $file = $root . '/' . $dir . '/auswertung.json';
            break;
        }
        $out[$key] = yolo_report($file);
        $out[$key]['source_dir'] = $file ? basename(dirname($file)): null;
    }
    return $out;
}

function yolo_legacy_metric_flat(array $report, string $prefix): array {
    $flat = [];
    if (yolo_bool($report['ground_truth_enabled'] ?? null) === false)
        return $flat;
    $mode = yolo_mode($report, 'strict');
    foreach ($mode['classes'] as $class => $values) {
        foreach ($values['micro'] as $metric => $value)
            if ($value !== null)
                $flat["$prefix / $class.$metric"] = $value;
    }
    if ($mode['miou'] !== null)
        $flat["$prefix / miou"] = $mode['miou'];
    return $flat;
}

function yolo_milestones(string $dir): array {
    $base = $dir . '/milestones/snapshots';
    $out = [];
    $keys = [];
    if (!is_dir($base) || !is_readable($base))
        return ['points' => [], 'metric_keys' => []];
    foreach (scandir($base) ?: [] as $entry) {
        if (!preg_match('/epoch[_-]?(\d+)/i', $entry, $m))
            continue;
        $folder = $base . '/' . $entry;
        if (!is_dir($folder))
            continue;
        $paths = [];
        if (is_file($folder . '/auswertung.json'))
            $paths['test'] = $folder . '/auswertung.json';
        foreach (scandir($folder) ?: [] as $sub)
            if (is_file($folder . '/' . $sub . '/auswertung.json'))
                $paths[$sub] = $folder . '/' . $sub . '/auswertung.json';
        foreach ($paths as $name => $p)
            foreach (yolo_legacy_metric_flat(yolo_json_file($p), $name) as $k => $v) {
            $ep = (int) $m[1];
            $out[$ep]['epoch'] = $ep;
            $out[$ep]['metrics'][$k] = $v;
            $keys[$k] = true;
        }
    }
    ksort($out, SORT_NUMERIC);
    $labels = array_keys($keys);
    natcasesort($labels);
    return ['points' => array_values($out), 'metric_keys' => array_values($labels)];
}

function yolo_image_identity(string $relative): array {
    $relative = str_replace('\\', '/', trim($relative, '/'));
    $stem = pathinfo($relative, PATHINFO_FILENAME);
    $stem = preg_replace('/_2x2$/i', '', $stem) ?? $stem;
    $parent = dirname($relative);
    $id = ($parent === '.' ? '' : $parent . '/') . $stem;
    return ['id' => strtolower($id), 'label' => $stem];
}

/**
 * Einzelbild-Metriken der finalen Realtest-Auswertung laden.
 * v9.3 schreibt die Werte bereits in Auswertung_Test/auswertung.csv.
 * Fallback fuer alte Runs: eingebettete Datensaetze in auswertung.json.
 * Fehlende Bewertungen bleiben null und werden nie als 0 ausgegeben.
 */
function yolo_image_scores(string $dir): array {
    $scores = [];
    $csvPath = $dir . '/auswertung.csv';
    if (is_file($csvPath) && is_readable($csvPath)) {
        $handle = @fopen($csvPath, 'rb');
        if ($handle) {
            $header = fgetcsv($handle, 0, ';', '"', '');
            if (is_array($header)) {
                $header = array_map(static function ($field): string {
                    $field = trim((string)$field);
                    return preg_replace('/^\\xEF\\xBB\\xBF/', '', $field) ?? $field;
                }, $header);
                $indexes = array_flip($header);
                $col = static function (array $row, string $key) use ($indexes): mixed {
                    return isset($indexes[$key]) ? ($row[$indexes[$key]] ?? null) : null;
                };
                while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
                    $file = (string)($col($row, 'file') ?? '');
                    if ($file === '')
                        $file = (string)($col($row, 'output') ?? '');
                    if ($file === '')
                        continue;
                    $stem = pathinfo(str_replace('\\', '/', $file), PATHINFO_FILENAME);
                    $stem = preg_replace('/_2x2$/i', '', $stem) ?? $stem;
                    $key = strtolower($stem);
                    $values = [
                        'miou_boundary' => yolo_number($col($row, 'miou_boundary')),
                        'miou_strict' => yolo_number($col($row, 'miou_strict')),
                        'defect_boundary' => yolo_number($col($row, 'defect_iou_boundary')),
                        'defect_strict' => yolo_number($col($row, 'defect_iou_strict') ?? $col($row, 'defect_iou')),
                        'text_boundary' => yolo_number($col($row, 'text_iou_boundary')),
                        'text_strict' => yolo_number($col($row, 'text_iou_strict') ?? $col($row, 'text_iou')),
                        'gt_defect_pixels' => yolo_number($col($row, 'defect_ground_truth_pixels')),
                        'gt_text_pixels' => yolo_number($col($row, 'text_ground_truth_pixels')),
                    ];
                    if ($values['miou_boundary'] !== null || $values['miou_strict'] !== null)
                        $scores[$key] = $values;
                }
            }
            fclose($handle);
        }
    }
    if ($scores)
        return $scores;

    // Historische v9.3-Daten koennen dieselben Ergebnisse nur in JSON enthalten.
    $report = yolo_json_file($dir . '/auswertung.json');
    foreach (yolo_arr($report['images'] ?? []) as $record) {
        if (!is_array($record))
            continue;
        $file = (string)($record['file'] ?? $record['output'] ?? '');
        if ($file === '')
            continue;
        $stem = pathinfo(str_replace('\\', '/', $file), PATHINFO_FILENAME);
        $stem = preg_replace('/_2x2$/i', '', $stem) ?? $stem;
        $key = strtolower($stem);
        $boundary = yolo_arr(yolo_path($record, 'evaluation.boundary', []));
        $strict = yolo_arr(yolo_path($record, 'evaluation.strict', []));
        $scores[$key] = [
            'miou_boundary' => yolo_number(yolo_path($record, 'miou.boundary')),
            'miou_strict' => yolo_number(yolo_path($record, 'miou.strict')),
            'defect_boundary' => yolo_number(yolo_path($boundary, 'defect.metrics.iou')),
            'defect_strict' => yolo_number(yolo_path($strict, 'defect.metrics.iou')),
            'text_boundary' => yolo_number(yolo_path($boundary, 'text.metrics.iou')),
            'text_strict' => yolo_number(yolo_path($strict, 'text.metrics.iou')),
            'gt_defect_pixels' => yolo_number(yolo_path($strict, 'defect.counts.ground_truth')),
            'gt_text_pixels' => yolo_number(yolo_path($strict, 'text.counts.ground_truth')),
        ];
    }
    return $scores;
}

function yolo_images(string $runRoot, bool $includePath = false, bool $includeScores = false): array {
    $dir = null;
    foreach (['Auswertung_Test', 'Auswertung_Real_Test', 'Auswertung_Realtest'] as $base)
        if (is_dir($runRoot . '/' . $base)) {
        $dir = $runRoot . '/' . $base;
        break;
    }
    if (!$dir || !is_readable($dir))
        return [];
    $out = [];
    $scores = $includeScores ? yolo_image_scores($dir) : [];
    try {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if (!$f instanceof SplFileInfo || !$f->isFile() || !preg_match('/_2x2\.(?:jpe?g|png|webp)$/i', $f->getFilename()))
                continue;
            $full = $f->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($full, strlen($dir))), '/');
            $ident = yolo_image_identity($rel);
            $key = $ident['id'];
            if (isset($out[$key]) && $out[$key]['mtime'] >= (@filemtime($full) ?: 0))
                continue;
            $out[$key] = ['id' => $key, 'label' => $ident['label'], 'mtime' => @filemtime($full) ?: 0];
            if ($includeScores) {
                $scoreKey = strtolower($ident['label']);
                $out[$key]['scores'] = $scores[$key] ?? $scores[$scoreKey] ?? null;
            }
            if ($includePath)
                $out[$key]['path'] = $full;
            if (count($out) >= YOLO_MAX_IMAGES_PER_RUN)
                break;
        }
    } catch (Throwable) {
        return [];
    }
    uasort($out, static fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
    return array_values($out);
}

function yolo_image_send(string $file): never {
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        http_response_code(415);
        exit;
    }
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=1800');
    if (is_file($file))
        header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}

function yolo_entry(string $root, string $runRoot, string $trainDir, string $name, string $kind, string $layout): array {
    $relative = ltrim(str_replace('\\', '/', substr($runRoot, strlen($root))), '/');
    $trainRelative = ltrim(str_replace('\\', '/', substr($trainDir, strlen($root))), '/');
    $key = substr(hash('sha256', "$layout|$relative|$trainRelative|$kind"), 0, 20);
    $files = [
        'results' => yolo_first_file([$trainDir . '/results.csv']),
        'args' => yolo_first_file([$trainDir . '/args.yaml']),
        'manifest' => yolo_latest($runRoot . '/run_manifest_*.json'),
        'state' => yolo_latest($trainDir . '/training_state*.json'),
        'dataset' => yolo_latest($runRoot . '/dataset_summary_*.json'),
        'semantic' => yolo_first_file([
            $trainDir . '/semantic_validation_metrics.json',
            $trainDir . '/validation/semantic_validation_metrics.json'
        ]),
    ];
    $stamp = @filemtime($runRoot) ?: 0;
    foreach ($files as $path)
        if ($path)
            $stamp = max($stamp, @filemtime($path) ?: 0);
    return compact('key', 'name', 'kind', 'layout', 'relative', 'runRoot', 'trainDir', 'files', 'stamp');
}

function yolo_catalog(string $root): array {
    if (!is_dir($root) || !is_readable($root))
        return [];
    $out = [];
    foreach (scandir($root) ?: [] as $name) {
        if (!preg_match('/^v\d+(?:_[A-Za-z0-9]+)+$/i', $name))
            continue;
        $folder = $root . '/' . $name;
        if (!is_dir($folder))
            continue;
        $detail = $folder . '/detail';
        $comp = $folder . '/component';
        if (is_dir($detail) || yolo_latest($folder . '/run_manifest_*.json') || yolo_latest($folder . '/dataset_summary_*.json')) {
            $entry = yolo_entry($root, $folder, is_dir($detail) ? $detail : $folder, $name, 'detail', 'central');
            $out[$entry['key']] = $entry;
        }
        if (is_dir($comp) && (is_file($comp . '/results.csv') || is_file($comp . '/args.yaml') || yolo_latest($comp . '/training_state*.json'))) {
            $entry = yolo_entry($root, $folder, $comp, $name . ' · Bauteil', 'component', 'central');
            $out[$entry['key']] = $entry;
        }
    }

    // Historische runs_semantic sind nur ergänzend enthalten; bereits bekannte

    // zentrale Runs werden nicht dupliziert.
    $legacy = $root . '/runs_semantic';
    if (is_dir($legacy) && is_readable($legacy)) {
        foreach (scandir($legacy) ?: [] as $name) {
            if ($name === '.' || $name === '..')
                continue;
            $folder = $legacy . '/' . $name;
            if (!is_dir($folder) || (!is_file($folder . '/results.csv') && !is_file($folder . '/args.yaml')))
                continue;
            $entry = yolo_entry($root, $folder, $folder, $name . ' · Legacy', 'detail', 'legacy');
            $out[$entry['key']] = $entry;
        }
    }
    uasort($out, static fn($a, $b) => ($b['stamp'] <=> $a['stamp']) ?: strnatcasecmp($b['name'], $a['name']));
    return $out;
}

function yolo_str_list(mixed $x): string {
    if (is_array($x))
        return implode(', ', array_map('strval', $x));
    return is_scalar($x) ? (string) $x : '';
}

function yolo_load(array $entry): array {
    $p = $entry['files'];
    $manifest = yolo_json_file($p['manifest']);
    $dataset = yolo_json_file($p['dataset']);
    $state = yolo_json_file($p['state']);
    $sem = yolo_json_file($p['semantic']);
    $args = yolo_yaml($p['args']);
    $res = yolo_csv($p['results']);
    $mask = yolo_arr(yolo_choose($manifest['mask_configuration'] ?? null, $dataset['mask_configuration'] ?? null, []));
    $loss = yolo_arr(yolo_choose($manifest['loss_configuration'] ?? null, $dataset['loss_configuration'] ?? null, []));
    $model = yolo_choose($args['model'] ?? null, $manifest['base_model'] ?? null, $state['model'] ?? null);
    if (is_string($model))
        $model = basename(str_replace('\\', '/', $model));
    $cls = yolo_choose($mask['training_detail_classes'] ?? null, $mask['active_detail_classes'] ?? null, []);
    if (!$cls && $entry['kind'] === 'detail') {
        $cls = [];
        foreach (['defect' => 'Fehler', 'edge' => 'Kante', 'text' => 'Schrift'] as $code => $label)
            if (!empty($mask['train_' . $code . '_class']))
                $cls[] = $label;
    }
    $source = yolo_choose($manifest['source_runs'] ?? null, $dataset['source_runs'] ?? null, $state['source_run_names'] ?? null);
    $epochs = null;
    if ($res['row_count'] > 0) {
        $epochs = $res['row_count'];
        if ($res['epoch_key'] !== null) {
            $n = yolo_number($res['last'][$res['epoch_key']] ?? null);
            if ($n !== null)
                $epochs = (int) round($n) + $res['epoch_offset'];
        }
    }
    $filesPresent = [];
    foreach ($p as $kind => $path)
        $filesPresent[$kind] = $path !== null;
    $filesPresent['milestones'] = is_dir($entry['trainDir'] . '/milestones/snapshots');
    $evals = $entry['kind'] === 'detail' ? yolo_final_reports($entry['runRoot']): [];
    $realVal = yolo_arr($dataset['real_validation'] ?? []);
    $summary = [
        'run' => $entry['name'],
        'typ' => $entry['kind'] === 'component' ? 'Bauteil' : 'Detail',
        'struktur' => $entry['layout'] === 'legacy' ? 'runs_semantic' : 'zentraler Run',
        'script' => yolo_choose(
            $manifest['script_version'] ?? null,
            $state['script_version'] ?? null,
            $dataset['script_version'] ?? null,
            $state['version'] ?? null
        ),
        'status' => yolo_choose($state['status'] ?? null, $res['row_count'] ? 'Trainingswerte vorhanden' : null, 'vorbereitet'),
        'quell_render_runs' => yolo_str_list($source),
        'klassen' => $entry['kind'] === 'component' ? 'Hintergrund, Bauteil' : yolo_str_list($cls),
        'weighted_dice' => yolo_choose($loss['weighted_dice_enabled'] ?? null, $loss['dice_class_weights_global'] ?? null),
        'modell' => $model,
        'epochen_soll' => yolo_choose($state['epochs_requested'] ?? null, $args['epochs'] ?? null),
        'epoche_letzte' => $epochs,
        'bildgroesse' => yolo_choose($state['image_size'] ?? null, $args['imgsz'] ?? null),
        'batch' => $args['batch'] ?? null,
        'worker' => $args['workers'] ?? null,
        'real_val_bilder' => $realVal['unique_images'] ?? null,
        'real_val_gewichtete_samples' => $realVal['weighted_samples'] ?? null,
        'real_val_anteil' => $realVal['actual_fraction'] ?? null,
        'semantic_val_miou' => yolo_number(yolo_choose($sem['miou'] ?? null, $sem['results_dict']['metrics/mIoU'] ?? null)),
        'semantic_val_pixacc' => yolo_number(yolo_choose($sem['pixel_accuracy'] ?? null, $sem['results_dict']['metrics/pixel_accuracy'] ?? null)),
        'gestartet' => yolo_choose($state['started_at'] ?? null, $manifest['created_at'] ?? null),
        'aktualisiert' => yolo_choose($state['updated_at'] ?? null, $manifest['last_started_at'] ?? null, $dataset['created_at'] ?? null),
        'results_zeilen' => $res['row_count'],
    ];
    return [
        'key' => $entry['key'],
        'name' => $entry['name'],
        'kind' => $entry['kind'],
        'layout' => $entry['layout'],
        'summary' => $summary,
        'files' => $filesPresent,
        'results' => $res,
        'milestones' => yolo_milestones($entry['trainDir']),
        'evaluations' => $evals,
        'test_images' => $entry['kind'] === 'detail'
            ? array_map(
                static fn($x) => ['id' => $x['id'], 'label' => $x['label'], 'scores' => $x['scores'] ?? null],
                yolo_images($entry['runRoot'], false, true)
            )
            : [],
        'configuration' => [
            'training_args' => $args,
            'mask_configuration' => $mask,
            'loss_configuration' => $loss,
            'dataset_real_validation' => $realVal,
            'manifest_summary' => array_intersect_key($manifest, array_flip([
                'script_version',
                'base_model',
                'source_runs',
                'created_at',
                'mask_encoding',
                'architecture_mode'
            ])),
        ],
    ];
}
$catalog = yolo_catalog(YOLO_META_ROOT);
if (isset($_GET['ajax']) && $_GET['ajax'] === 'run_data') {
    $raw = $_GET['runs'] ?? '';
    if (!is_string($raw))
        yolo_json(['ok' => false, 'message' => 'Ungültige Auswahl.'], 400);
    $keys = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    if (!$keys || count($keys) > YOLO_MAX_SELECTED_RUNS)
        yolo_json(['ok' => false, 'message' => 'Bitte zwischen 1 und ' . YOLO_MAX_SELECTED_RUNS . ' Runs auswählen.'], 400);
    $out = [];
    foreach ($keys as $key)
        if (isset($catalog[$key]))
            $out[] = yolo_load($catalog[$key]);
    if (!$out)
        yolo_json(['ok' => false, 'message' => 'Keine gültigen Runs gefunden.'], 404);
    yolo_json(['ok' => true, 'runs' => $out, 'generated_at' => date(DATE_ATOM)]);
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'test_image') {
    $key = $_GET['run'] ?? null;
    $image = $_GET['image'] ?? null;
    if (!is_string($key) || !is_string($image) || !isset($catalog[$key]) || $catalog[$key]['kind'] !== 'detail') {
        http_response_code(404);
        exit;
    }
    foreach (yolo_images($catalog[$key]['runRoot'], true) as $i) {
        if (hash_equals($i['id'], strtolower(trim($image))))
            yolo_image_send($i['path']);
    }
    http_response_code(404);
    exit;
}
$publicCatalog = [];
$defaults = [];
foreach ($catalog as $run) {
    $publicCatalog[] = [
        'key' => $run['key'],
        'name' => $run['name'],
        'kind' => $run['kind'],
        'layout' => $run['layout'],
        'has_results' => $run['files']['results'] !== null,
        'has_manifest' => $run['files']['manifest'] !== null,
        'has_milestones' => is_dir($run['trainDir'] . '/milestones/snapshots'),
        'mtime' => $run['stamp']
    ];
    if (count($defaults) < 2 && $run['kind'] === 'detail' && $run['files']['results'] !== null)
        $defaults[] = $run['key'];
}
if (!$defaults && $publicCatalog)
    $defaults[] = $publicCatalog[0]['key'];
$page_title = 'YOLO-Auswertung';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>


<div id="yoloPage" class="lt-page dashboard-page yolo-page">
    <div class="yolo-head">
        <h1 class="ueberschrift dashboard-title">
            YOLO-Auswertung
            <small><span id="yoloSelectedCount">0</span> Runs ausgewählt</small>
        </h1>
    </div>

    <?php if (!$catalog): ?>
        <div class="yolo-notice error">
            Keine lesbaren YOLO-Läufe unter
            <code><?= htmlspecialchars(YOLO_META_ROOT, ENT_QUOTES, 'UTF-8') ?></code> gefunden.
        </div>
    <?php else: ?>
        <nav class="yolo-tabs" role="tablist" aria-label="YOLO-Auswertung">
            <button type="button" class="yolo-tab is-active" data-tab="runs" role="tab" aria-selected="true">
                1. Runs
            </button>
            <button type="button" class="yolo-tab" data-tab="overview" role="tab" aria-selected="false">
                2. Übersicht &amp; Konfiguration
            </button>
            <button type="button" class="yolo-tab" data-tab="ba" role="tab" aria-selected="false">
                3. BA-Ergebnisse
            </button>
            <button type="button" class="yolo-tab" data-tab="graphs" role="tab" aria-selected="false">
                4. Graphen
            </button>
            <button type="button" class="yolo-tab" data-tab="images" role="tab" aria-selected="false">
                5. Bilder
            </button>
            <button type="button" class="yolo-tab" data-tab="glossary" role="tab" aria-selected="false">
                6. Begriffe
            </button>
        </nav>

        <div id="yoloLoading" class="yolo-notice" hidden>
            Ausgewählte Run-Daten werden geladen …
        </div>
        <div id="yoloError" class="yolo-notice error" hidden></div>

        <section data-panel="runs" role="tabpanel">
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Runs auswählen</h2>
                        <p>
                            Bis zu <?= YOLO_MAX_SELECTED_RUNS ?> Läufe gleichzeitig.
                            Ältere zentrale und historische Läufe werden nicht ausgeblendet.
                        </p>
                    </div>
                    <div class="yolo-flex">
                        <input
                            id="yoloRunSearch"
                            class="yolo-search"
                            type="search"
                            placeholder="Lauf suchen …"
                            aria-label="Run suchen"
                        >
                        <select id="yoloKindFilter" class="yolo-select" aria-label="Run-Typ">
                            <option value="detail">Detail</option>
                            <option value="component">Bauteil</option>
                            <option value="all">Alle</option>
                        </select>
                    </div>
                </div>
                <div id="yoloRunList" class="yolo-run-grid"></div>
                <p
                    id="yoloSelectionMessage"
                    class="yolo-muted yolo-selection-feedback"
                    aria-live="polite"
                ></p>
            </div>
        </section>

        <section data-panel="overview" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Übersicht und Konfiguration</h2>
                        <p>
                            Trainingsläufe, Datensatzquellen, Hyperparameter
                            und verfügbare Ergebnisdateien.
                        </p>
                    </div>
                </div>
                <div id="yoloSummaryKpis"></div>
                <div class="yolo-table-scroll">
                    <table id="yoloOverviewTable"></table>
                </div>
            </div>

            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Erweiterte Konfiguration</h2>
                        <p>
                            Originalwerte aus args.yaml, Masken- und Loss-Konfiguration,
                            soweit im jeweiligen Run vorhanden.
                        </p>
                    </div>
                </div>
                <div id="yoloConfigDetails"></div>
            </div>
        </section>

        <section data-panel="ba" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>BA-Ergebnisse – Real- und Synthetiktest</h2>
                        <p>
                            Je Modell stehen Real-Test und synthetischer Test wertweise
                            nebeneinander. Micro-Metriken entstehen aus aggregierten
                            Pixelzahlen. Strict ist die unveränderte Maskenauswertung,
                            Boundary die separat dokumentierte Randtoleranz.
                        </p>
                    </div>
                </div>
                <div class="yolo-notice warn">
                    Ein Test ohne Ground Truth liefert keine verlässlichen IoU-,
                    Dice-, Precision- oder Recall-Werte. Fehlende Daten werden
                    mit „—“ gekennzeichnet; reale Validierung wird nicht als
                    realer Test ausgegeben.
                </div>
                <div id="yoloBaCards"></div>
            </div>
        </section>

        <section data-panel="graphs" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Trainingsverlauf</h2>
                        <p>
                            Metrikspalten aus results.csv; fehlende Messwerte
                            erzeugen keine künstlichen Nullpunkte.
                        </p>
                    </div>
                </div>
                <div id="yoloTrainMetrics" class="yolo-metric-list"></div>
                <div id="yoloTrainCharts" class="yolo-chart-grid"></div>
            </div>

            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Milestone-Auswertungen</h2>
                        <p>
                            Gespeicherte Zwischenprüfungen getrennt nach
                            Datendomäne und Klasse.
                        </p>
                    </div>
                </div>
                <div id="yoloMilestoneMetrics" class="yolo-metric-list"></div>
                <div id="yoloMilestoneCharts" class="yolo-chart-grid"></div>
            </div>
        </section>

        <section data-panel="images" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Reale Testbilder vergleichen</h2>
                        <p>
                            Gleiche 2×2-Testbilder aus den finalen Auswertungsordnern
                            werden runübergreifend nebeneinander angezeigt.
                            Klick öffnet das Bild in Originalgröße.
                        </p>
                    </div>
                </div>
                <div class="yolo-image-controls" role="group" aria-label="Sortierung der Testbilder">
                    <div class="yolo-image-control">
                        <label for="yoloImageSort">Sortieren nach</label>
                        <select id="yoloImageSort" class="yolo-select">
                            <option value="miou_boundary">Boundary-mIoU: beste zuerst</option>
                            <option value="miou_strict">Strict-mIoU: beste zuerst</option>
                            <option value="defect_boundary">Boundary-Fehler-IoU: beste zuerst</option>
                            <option value="text_boundary">Boundary-Schrift-IoU: beste zuerst</option>
                            <option value="filename">Dateiname (A–Z)</option>
                        </select>
                    </div>
                    <div class="yolo-image-control">
                        <label for="yoloImageReference">Referenzlauf</label>
                        <select id="yoloImageReference" class="yolo-select" aria-label="Referenzlauf für die Bildsortierung"></select>
                    </div>
                </div>
                <div id="yoloImages"></div>
            </div>
        </section>

        <section data-panel="glossary" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Begriffe und Metriken</h2>
                        <p>Kurze Erläuterungen für die Interpretation in Kapitel 5.</p>
                    </div>
                    <input
                        id="yoloGlossarySearch"
                        class="yolo-search"
                        type="search"
                        placeholder="Begriff suchen …"
                    >
                </div>
                <div id="yoloGlossary"></div>
            </div>
        </section>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    'use strict';
    const CATALOG = <?= json_encode(
        $publicCatalog,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ) ?>;
    const DEFAULT_KEYS = <?= json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const MAX_RUNS = <?= YOLO_MAX_SELECTED_RUNS ?>;
    if (!Array.isArray(CATALOG) || !CATALOG.length)
        return;
    const root = document.getElementById('yoloPage');
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));
    const escapeAttr = esc;
    const isNum = v => typeof v === 'number' && Number.isFinite(v);
    const nice = (v, ratio = false) => {
        if (v === null || v === undefined || v === '' || (typeof v === 'number' && !Number.isFinite(v)))
            return '—';
        if (typeof v === 'boolean')
            return v ? 'Ja' : 'Nein';
        if (Array.isArray(v))
            return v.length ? v.map(x => nice(x)).join(', ') : '—';
        if (typeof v === 'object')
            return JSON.stringify(v);
        if (typeof v === 'number')
            return ratio
                ? (v * 100).toLocaleString('de-DE', {
                    maximumFractionDigits: 3,
                    minimumFractionDigits: 1
                }) + ' %'
                : v.toLocaleString('de-DE', {
                    maximumFractionDigits: Number.isInteger(v) ? 0 : 6
                });
        return String(v) || '—';
    };
    // Die sechs Graph- und Run-Farben kommen ausschließlich aus der Desktop-CSS.
    // Primärfarbe und Akzentfarbe folgen dadurch dem FIJI-Farbschema.
    const pageStyles = getComputedStyle(root);
    const colors = Array.from({ length: 6 }, (_, index) => {
        if (index === 0) {
            return pageStyles.getPropertyValue('--primary').trim() || '#ff6b00';
        }
        return pageStyles.getPropertyValue(`--y-series-${index}`).trim() || '#ff6b00';
    });
    const state = {
        selected: new Set(DEFAULT_KEYS),
        loaded: [],
        request: 0,
        trainMetrics: new Set(),
        milestoneMetrics: new Set(),
        charts: new Map()
    };
    const metaLabels = {
        run: 'Run',
        typ: 'Modelltyp',
        struktur: 'Ordnerstruktur',
        script: 'Pipeline-/Skriptversion',
        status: 'Status',
        quell_render_runs: 'Renderer-Quellläufe',
        klassen: 'Trainingsklassen / Ausgänge',
        weighted_dice: 'Gewichteter Dice-Loss',
        modell: 'Basismodell',
        epochen_soll: 'Epochen (Soll)',
        epoche_letzte: 'Letzte protokollierte Epoche',
        bildgroesse: 'Eingangsauflösung',
        batch: 'Batchgröße',
        worker: 'DataLoader-Worker',
        real_val_bilder: 'Reale Val-Bilder (einzigartig)',
        real_val_gewichtete_samples: 'Reale Val-Samples (gewichtet)',
        real_val_anteil: 'Anteil reale Samples an Val',
        semantic_val_miou: 'mIoU (Training-Validierung)',
        semantic_val_pixacc: 'Pixel Accuracy (Training-Validierung)',
        gestartet: 'Trainingsbeginn',
        aktualisiert: 'Zuletzt aktualisiert',
        results_zeilen: 'results.csv – Zeilen'
    };
    const ratioMeta = new Set(['real_val_anteil', 'semantic_val_miou', 'semantic_val_pixacc']);
    const metrics = [
        ['iou', 'IoU'],
        ['dice', 'Dice / F1'],
        ['precision', 'Precision'],
        ['recall', 'Recall'],
        ['f2', 'F₂-Score'],
        ['pixel_accuracy', 'Pixel Accuracy']
    ];
    const metricNames = {
        iou: 'IoU',
        dice: 'Dice',
        f1: 'F1',
        precision: 'Precision',
        recall: 'Recall',
        f2: 'F2',
        pixel_accuracy: 'Pixel Accuracy'
    };
    const classNames = {
        defect: 'Fehler',
        text: 'Schrift',
        edge: 'Kante',
        background: 'Hintergrund',
        component: 'Bauteil'
    };
    const classLabel = key => classNames[key] || key;
    const sourceLabel = src => src?.source_dir || 'Keine Berichtdatei';
    const baEval = (run, kind) => run.evaluations?.[kind] || { available: false };
    const metric = (r, mode, cls, key) => r?.[mode]?.classes?.[cls]?.micro?.[key] ?? null;
    const classKeys = (run) => {
        const r = baEval(run, 'real_test'), s = baEval(run, 'synthetic_test');
        const set = new Set(['defect', 'text']);
        for (const rep of [r, s])
            for (const mode of ['strict', 'boundary'])
                Object.keys(rep?.[mode]?.classes || {}).forEach(k => set.add(k));
        return [...set];
    };
    function currentKeys() {
        return [...state.selected];
    }
    function renderRunList() {
        const kind = $('yoloKindFilter').value, query = $('yoloRunSearch').value.toLowerCase().trim();
        const matches = CATALOG.filter(r => (kind === 'all' || r.kind === kind) && r.name.toLowerCase().includes(query));
        $('yoloRunList').innerHTML = matches.map(r => {
            const checked = state.selected.has(r.key);
            const kindLabel = r.kind === 'component'
                ? 'Bauteilsegmentierung'
                : 'Detailsegmentierung';
            const layoutLabel = r.layout === 'legacy'
                ? 'Legacy'
                : 'Zentraler Run';
            const badges = [
                r.has_results ? '<span class="yolo-tag">results.csv</span>' : '',
                r.has_manifest ? '<span class="yolo-tag">Manifest</span>' : '',
                r.has_milestones ? '<span class="yolo-tag">Milestones</span>' : ''
            ].join('');

            return `
                <label class="yolo-run-item ${checked ? 'is-selected' : ''}">
                    <input
                        type="checkbox"
                        data-run-key="${escapeAttr(r.key)}"
                        ${checked ? 'checked' : ''}
                    >
                    <span>
                        <strong>${esc(r.name)}</strong>
                        <small>${kindLabel} · ${layoutLabel}</small>
                        ${badges}
                    </span>
                </label>
            `;
        }).join('') || '<div class="yolo-empty">Keine Runs entsprechen dem Filter.</div>';
        $('yoloRunList').querySelectorAll('[data-run-key]').forEach(input => input.addEventListener('change', () => {
            const key = input.dataset.runKey;
            if (input.checked && state.selected.size >= MAX_RUNS) {
                input.checked = false;
                $('yoloSelectionMessage').textContent = `Maximal ${MAX_RUNS} Runs gleichzeitig.`;
                return;
            }
            if (input.checked)
                state.selected.add(key);
            else
                state.selected.delete(key);
            renderRunList();
            selectionChanged();
        }));
    }
    function renderError(text) { $('yoloError').hidden = !text; $('yoloError').textContent = text || ''; }
    async function selectionChanged() {
        const keys = currentKeys();
        $('yoloSelectedCount').textContent = String(keys.length);
        $('yoloSelectionMessage').textContent = `${keys.length} von maximal ${MAX_RUNS} Runs ausgewählt.`;
        const request = ++state.request;
        if (!keys.length) {
            state.loaded = [];
            $('yoloLoading').hidden = true;
            renderAll();
            return;
        }
        $('yoloLoading').hidden = false;
        renderError('');
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'run_data');
            url.searchParams.set('runs', keys.join(','));
            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (request !== state.request)
                return;
            if (!response.ok || !data.ok || !Array.isArray(data.runs))
                throw new Error(data.message || `HTTP ${response.status}`);
            const byKey = new Map(data.runs.map(run => [run.key, run]));
            state.loaded = keys.map(key => byKey.get(key)).filter(Boolean);
            renderAll();
        }
        catch (err) {
            if (request !== state.request)
                return;
            state.loaded = [];
            renderAll();
            renderError('Fehler beim Laden: ' + (err?.message || String(err)));
        }
        finally {
            if (request === state.request)
                $('yoloLoading').hidden = true;
        }
    }
    function renderAll() {
        renderOverview();
        renderBa();
        rebuildGraphs();
        renderImages();
    }
    function renderOverview() {
        const runs = state.loaded;

        $('yoloSummaryKpis').innerHTML = runs.map((run, index) => {
            const real = baEval(run, 'real_test');
            const synthetic = baEval(run, 'synthetic_test');

            return `
                <article class="yolo-model-card yolo-summary-kpi-card">
                    <strong class="yolo-series-${index % colors.length}">
                        ${esc(run.name)}
                    </strong>
                    <div class="yolo-kpis yolo-summary-kpi-grid">
                        <div class="yolo-kpi">
                            <small>Basismodell</small>
                            <strong>${esc(nice(run.summary?.modell))}</strong>
                        </div>
                        <div class="yolo-kpi">
                            <small>Trainierte Epochen</small>
                            <strong>${esc(nice(run.summary?.epoche_letzte))}</strong>
                        </div>
                        <div class="yolo-kpi">
                            <small>Real Test – mIoU Strict</small>
                            <strong>${esc(nice(real.strict?.miou, true))}</strong>
                        </div>
                        <div class="yolo-kpi">
                            <small>Synthetisch Test – mIoU Strict</small>
                            <strong>${esc(nice(synthetic.strict?.miou, true))}</strong>
                        </div>
                    </div>
                </article>
            `;
        }).join('') || '<div class="yolo-empty">Bitte zunächst Runs auswählen.</div>';

        const headers = `
            <tr>
                <th class="yolo-field">Parameter</th>
                ${runs.map(run => `<th>${esc(run.name)}</th>`).join('')}
            </tr>
        `;

        const rows = Object.entries(metaLabels).map(([key, label]) => {
            const values = runs.map(run => {
                return `<td>${esc(nice(run.summary?.[key], ratioMeta.has(key)))}</td>`;
            }).join('');
            return `<tr><td class="yolo-field">${esc(label)}</td>${values}</tr>`;
        }).join('');

        const fileKeys = [
            'results',
            'args',
            'manifest',
            'state',
            'dataset',
            'semantic',
            'milestones'
        ];

        const fileRows = fileKeys.map(key => {
            const cells = runs.map(run => {
                return `<td>${run.files?.[key] ? 'Vorhanden' : '—'}</td>`;
            }).join('');
            return `<tr><td class="yolo-field">Datei: ${esc(key)}</td>${cells}</tr>`;
        }).join('');

        $('yoloOverviewTable').innerHTML = headers + (
            runs.length
                ? rows + fileRows
                : '<tr><td>Keine Runs ausgewählt.</td></tr>'
        );

        $('yoloConfigDetails').innerHTML = runs.map(run => {
            const configuration = run.configuration || {};
            const tables = Object.entries(configuration).map(([name, fields]) => {
                const entries = Object.entries(fields || {});
                const valueRows = entries.length
                    ? entries.map(([key, value]) => `
                        <tr>
                            <td class="yolo-config-key">${esc(key)}</td>
                            <td>${esc(nice(value))}</td>
                        </tr>
                    `).join('')
                    : '<tr><td>Keine dokumentierten Werte.</td></tr>';

                return `
                    <h3>${esc(name)}</h3>
                    <div class="yolo-table-scroll">
                        <table><tbody>${valueRows}</tbody></table>
                    </div>
                `;
            }).join('');

            return `
                <details class="yolo-detail">
                    <summary>${esc(run.name)} – Originalkonfiguration</summary>
                    <div class="yolo-detail-inner">${tables}</div>
                </details>
            `;
        }).join('') || '<div class="yolo-empty">Keine Runs ausgewählt.</div>';
    }

    function getBaGroups(run) {
        const r = baEval(run, 'real_test'), s = baEval(run, 'synthetic_test');
        const rows = [];
        const put = (section, label, rr, ss, kind = 'value') => rows.push({
            section,
            label,
            real: rr ?? null,
            synth: ss ?? null,
            kind
        });
        put('Datengrundlage', 'Bericht verfügbar', r.available === true, s.available === true, 'bool');
        put('Datengrundlage', 'Quellordner', r.source_dir, s.source_dir);
        put('Datengrundlage', 'Ground Truth vorhanden', r.ground_truth_enabled, s.ground_truth_enabled, 'bool');
        put('Datengrundlage', 'Auswertung vollständig', r.complete, s.complete, 'bool');
        put('Datengrundlage', 'Erfolgreich ausgewertete Bilder', r.successful_images, s.successful_images, 'count');
        put('Datengrundlage', 'Erwartete Bilder', r.expected_images, s.expected_images, 'count');
        put('Datengrundlage', 'Fehlgeschlagene Bilder', r.failed_images, s.failed_images, 'count');
        put(
            'Datengrundlage',
            'Boundary-Randbreite (Originalpixel)',
            r.boundary_band?.total_width_original_px,
            s.boundary_band?.total_width_original_px,
            'count'
        );
        for (const mode of ['strict', 'boundary']) {
            const group = mode === 'strict' ? 'Strict – Micro (Pixel aggregiert)' : 'Boundary – Micro (Randtoleranz)';
            put(group, 'mIoU (Fehler/Schrift)', r[mode]?.miou, s[mode]?.miou, 'ratio');
            for (const cls of classKeys(run)) {
                for (const [key, label] of metrics)
                    put(group, `${classLabel(cls)} – ${label}`, metric(r, mode, cls, key), metric(s, mode, cls, key), 'ratio');
            }
        }
        for (const mode of ['strict', 'boundary']) {
            const section = `${mode === 'strict' ? 'Strict' : 'Boundary'} – Konfusionszahlen (Micro)`;
            for (const cls of classKeys(run)) {
                for (const [key, label] of [
                    ['true_positive', 'TP'],
                    ['false_positive', 'FP'],
                    ['false_negative', 'FN'],
                    ['true_negative', 'TN'],
                    ['ground_truth', 'GT-Pixel'],
                    ['prediction', 'Vorhersagepixel'],
                    ['total', 'Gesamtpixel'],
                    ['ignored_pixels', 'Ignorierte Pixel']
                ]) {
                    const rr = r[mode]?.classes?.[cls]?.counts || {}, ss = s[mode]?.classes?.[cls]?.counts || {};
                    const x = key === 'true_positive' ? (rr[key] ?? rr.intersection) : rr[key];
                    const y = key === 'true_positive' ? (ss[key] ?? ss.intersection) : ss[key];
                    put(section, `${classLabel(cls)} – ${label}`, x, y, 'count');
                }
            }
        }
        for (const mode of ['strict', 'boundary']) {
            const section = `${mode === 'strict' ? 'Strict' : 'Boundary'} – Statistik je Einzelbild`;
            for (const cls of classKeys(run)) {
                for (const key of [
                    'iou',
                    'dice',
                    'precision',
                    'recall',
                    'f2',
                    'pixel_accuracy'
                ]) {
                    for (const stat of [
                        'mean',
                        'median',
                        'std',
                        'min',
                        'max',
                        'n'
                    ]) {
                        const x = r[mode]?.classes?.[cls]?.image_statistics?.[key]?.[stat];
                        const y = s[mode]?.classes?.[cls]?.image_statistics?.[key]?.[stat];
                        put(section, `${classLabel(cls)} – ${metricNames[key]} ${stat}`, x, y, stat === 'n' ? 'count' : 'ratio');
                    }
                }
            }
        }
        return rows;
    }
    function cell(v, kind) {
        const valid = v !== null && v !== undefined;
        const txt = nice(v, kind === 'ratio');
        return `<td class="${valid ? '' : 'yolo-na'}">${esc(txt)}</td>`;
    }
    function displayGroup(rows, section) {
        const items = rows.filter(row => row.section === section);
        const dataRows = items.map(row => `
            <tr>
                <td>${esc(row.label)}</td>
                ${cell(row.real, row.kind)}
                ${cell(row.synth, row.kind)}
            </tr>
        `).join('');

        return `
            <div class="yolo-table-scroll">
                <table class="yolo-ba-table">
                    <thead>
                        <tr>
                            <th>Kennzahl</th>
                            <th>Real-Test</th>
                            <th>Synthetischer Test</th>
                        </tr>
                    </thead>
                    <tbody>${dataRows}</tbody>
                </table>
            </div>
        `;
    }
    function exportBaText(run, withJson = true) {
        const r = baEval(run, 'real_test'), s = baEval(run, 'synthetic_test');
        const lines = [
            'YOLO – Bachelorarbeit Kapitel 5 / vollständiger Modellbericht',
            `Run: ${run.name}`,
            `Modell: ${nice(run.summary?.modell)}`,
            `Pipeline: ${nice(run.summary?.script)}`,
            `Epochen: ${nice(run.summary?.epoche_letzte)} / ${nice(run.summary?.epochen_soll)}`,
            `Training: ${nice(run.summary?.bildgroesse)} Pixel; Batch ${nice(run.summary?.batch)}`,
            `Klassen: ${nice(run.summary?.klassen)}`,
            `Renderer-Quellen: ${nice(run.summary?.quell_render_runs)}`,
            'Micro = aus aufsummierten Pixelzählwerten; bildweise Statistik = ungewichtete Einzelbildwerte.',
            'Strict = normale GT-Pixelbewertung; Boundary = Auswertung mit dokumentierter Randtoleranz.',
            'Fehlende Kennzahl = nicht berichtet/nicht berechenbar (keine künstliche Null).',
            `Quellen: Real=${sourceLabel(r)}; Synthetisch=${sourceLabel(s)}`,
            '',
            'Kennzahl\tReal-Test\tSynthetischer Test'
        ];
        let prev = '';
        for (const row of getBaGroups(run)) {
            if (row.section !== prev) {
                lines.push('', `[${row.section}]`);
                prev = row.section;
            }
            lines.push(`${row.label}\t${nice(row.real, row.kind === 'ratio')}\t${nice(row.synth, row.kind === 'ratio')}`);
        }
        if (withJson) {
            lines.push('', 'MASCHINENLESBARE ORIGINALWERTE (NORMALISIERT)', JSON.stringify({
                run: run.name,
                summary: run.summary,
                configuration: run.configuration,
                evaluations: {
                    real_test: r,
                    synthetic_test: s,
                    real_val: baEval(run, 'real_val'),
                    synthetic_val: baEval(run, 'synthetic_val')
                },
                results_last_epoch: run.results?.last,
                available_files: run.files
            }, null, 2));
        }
        return lines.join('\n');
    }
    async function copyText(value) {
        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(value);
                return;
            }
            catch (_) { /* HTTP-Kontext-Fallback */ }
        }
        const box = document.createElement('textarea');
        box.value = value;
        box.className = 'yolo-clipboard-fallback';
        root.appendChild(box);
        box.select();
        const success = document.execCommand('copy');
        box.remove();
        if (!success)
            throw new Error('Zwischenablage wurde vom Browser blockiert.');
    }
    function renderBa() {
        const runs = state.loaded;

        $('yoloBaCards').innerHTML = runs.map((run, index) => {
            const real = baEval(run, 'real_test');
            const synthetic = baEval(run, 'synthetic_test');
            const rows = getBaGroups(run);
            const sections = [...new Set(rows.map(row => row.section))];

            const groupTables = sections.map((section, groupIndex) => {
                const groupRows = rows.filter(row => row.section === section);
                const collapsed = groupIndex > 1;
                const arrow = collapsed ? '▸' : '▾';
                const dataRows = groupRows.map(row => `
                    <tr>
                        <td>${esc(row.label)}</td>
                        ${cell(row.real, row.kind)}
                        ${cell(row.synth, row.kind)}
                    </tr>
                `).join('');

                return `
                    <tbody class="yolo-ba-tbody ${collapsed ? 'is-collapsed' : ''}">
                        <tr class="yolo-ba-group">
                            <td colspan="3">
                                <button
                                    type="button"
                                    class="yolo-ba-toggle"
                                    aria-expanded="${String(!collapsed)}"
                                >${arrow} ${esc(section)}</button>
                            </td>
                        </tr>
                        ${dataRows}
                    </tbody>
                `;
            }).join('');

            const hasCompleteReports = [real, synthetic].every(report => {
                return report.available
                    && report.ground_truth_enabled !== false
                    && report.has_metrics;
            });

            const warning = hasCompleteReports ? '' : `
                <div class="yolo-notice warn">
                    ${!real.available ? 'Kein realer Testbericht. ' : ''}
                    ${!synthetic.available ? 'Kein synthetischer Testbericht. ' : ''}
                    Nicht alle Metriken sind für beide Testdomänen vorhanden;
                    vorhandene Daten bleiben sichtbar.
                </div>
            `;

            return `
                <article class="yolo-model-card" data-ba-key="${escapeAttr(run.key)}">
                    <div class="yolo-model-head">
                        <div>
                            <h3>
                                <span class="yolo-series-${index % colors.length}">●</span>
                                ${esc(run.name)}
                            </h3>
                            <p>
                                Modell: ${esc(nice(run.summary?.modell))} ·
                                Skript: ${esc(nice(run.summary?.script))} ·
                                letzter Epoch: ${esc(nice(run.summary?.epoche_letzte))}
                            </p>
                        </div>
                        <div class="yolo-flex">
                            <button type="button" class="yolo-button" data-ba-expand>
                                Alle Kennzahlen zeigen
                            </button>
                            <button
                                type="button"
                                class="yolo-button primary"
                                data-ba-copy="${escapeAttr(run.key)}"
                            >Alle BA-Werte kopieren</button>
                            <button
                                type="button"
                                class="yolo-button"
                                data-ba-copy-json="${escapeAttr(run.key)}"
                            >Nur JSON</button>
                        </div>
                    </div>
                    <div class="yolo-ba-core">
                        ${warning}
                        <div class="yolo-table-scroll">
                            <table class="yolo-ba-table">
                                <thead>
                                    <tr>
                                        <th>Kennzahl</th>
                                        <th>Real-Test</th>
                                        <th>Synthetischer Test</th>
                                    </tr>
                                </thead>
                                ${groupTables}
                            </table>
                        </div>
                    </div>
                </article>
            `;
        }).join('') || '<div class="yolo-empty">Bitte mindestens einen Detail-Run auswählen.</div>';

        $('yoloBaCards').querySelectorAll('.yolo-ba-toggle').forEach(btn => btn.addEventListener('click', () => {
            const section = btn.closest('.yolo-ba-tbody');
            section.classList.toggle('is-collapsed');
            const expanded = !section.classList.contains('is-collapsed');
            btn.setAttribute('aria-expanded', String(expanded));
            btn.textContent = (expanded ? '▾' : '▸') + ' ' + btn.textContent.slice(2);
        }));
        $('yoloBaCards').querySelectorAll('[data-ba-expand]').forEach(btn => btn.addEventListener('click', () => {
            const groups = btn.closest('.yolo-model-card').querySelectorAll('.yolo-ba-tbody');
            const allOpen = [...groups].every(g => !g.classList.contains('is-collapsed'));
            groups.forEach(group => {
                group.classList.toggle('is-collapsed', allOpen);
                const toggle = group.querySelector('.yolo-ba-toggle');
                toggle.textContent = (allOpen ? '▸' : '▾') + ' ' + toggle.textContent.slice(2);
                toggle.setAttribute('aria-expanded', String(!allOpen));
            });
            btn.textContent = allOpen ? 'Alle Kennzahlen zeigen' : 'Details einklappen';
        }));
        $('yoloBaCards').querySelectorAll('[data-ba-copy],[data-ba-copy-json]').forEach(btn => btn.addEventListener('click', async () => {
            const key = btn.dataset.baCopy || btn.dataset.baCopyJson;
            const run = state.loaded.find(x => x.key === key);
            if (!run)
                return;
            const jsonOnly = !!btn.dataset.baCopyJson;
            const content = jsonOnly ? JSON.stringify({
                run: run.name,
                summary: run.summary,
                configuration: run.configuration,
                evaluations: run.evaluations,
                results_last_epoch: run.results?.last
            }, null, 2) : exportBaText(run);
            const before = btn.textContent;
            try {
                await copyText(content);
                btn.textContent = 'Kopiert';
            }
            catch (err) {
                btn.textContent = 'Kopieren fehlgeschlagen';
                renderError(String(err.message || err));
            }
            window.setTimeout(() => { btn.textContent = before; }, 1700);
        }));
    }
    function destroyCharts(prefix = '') {
        for (const [key, chart] of state.charts)
            if (!prefix || key.startsWith(prefix)) {
                chart.destroy();
                state.charts.delete(key);
            }
    }
    function allKeys(type) {
        const keys = new Set();
        for (const r of state.loaded) {
            const items = type === 'train' ? r.results?.numeric_columns : r.milestones?.metric_keys;
            (items || []).forEach(v => keys.add(v));
        }
        return [...keys].sort((a, b) => a.localeCompare(b, 'de', { numeric: true }));
    }
    function defaultsFor(type, keys) {
        const first = type === 'train'
            ? [
                /metrics\/.*miou/i,
                /metrics\/.*iou.*defect/i,
                /metrics\/.*iou.*text/i,
                /train\/.*loss/i,
                /val\/.*loss/i
            ]
            : [/render_test.*defect.*iou/i, /render_test.*text.*iou/i, /real_test.*defect.*iou/i, /real_test.*text.*iou/i];
        const result = [];
        for (const re of first) {
            const key = keys.find(k => re.test(k) && !result.includes(k));
            if (key)
                result.push(key);
        }
        return (result.length ? result : keys.slice(0, 3)).slice(0, 5);
    }
    function rebuildGraphs() {
        for (const type of ['train', 'milestone']) {
            const keys = allKeys(type);
            const set = type === 'train' ? state.trainMetrics : state.milestoneMetrics;
            for (const k of [...set])
                if (!keys.includes(k))
                    set.delete(k);
            if (!set.size)
                defaultsFor(type, keys).forEach(k => set.add(k));
            const holder = $(type === 'train' ? 'yoloTrainMetrics' : 'yoloMilestoneMetrics');
            holder.innerHTML = keys.map(key => `
                <label class="yolo-metric-label">
                    <input
                        type="checkbox"
                        data-metric="${escapeAttr(key)}"
                        ${set.has(key) ? 'checked' : ''}
                    >
                    <span>${esc(key)}</span>
                </label>
            `).join('') || `
                <div class="yolo-empty">
                    Keine Metriken für die ausgewählten Runs vorhanden.
                </div>
            `;
            holder.querySelectorAll('input').forEach(input => input.addEventListener('change', () => {
                if (input.checked)
                    set.add(input.dataset.metric);
                else
                    set.delete(input.dataset.metric);
                drawCharts(type);
            }));
            drawCharts(type);
        }
    }
    function points(run, type, key) {
        if (type === 'milestone')
            return (run.milestones?.points || []).flatMap(item => {
                const y = item.metrics?.[key];
                return isNum(y) ? [{ x: Number(item.epoch), y }] : [];
            }).filter(p => isNum(p.x));
        const res = run.results || {}, rows = res.rows || [], epochKey = res.epoch_key;
        return rows.flatMap((row, i) => {
            const y = row[key], x = epochKey ? (row[epochKey] ?? null) : i + 1;
            if (!isNum(y) || !isNum(x))
                return [];
            return [{ x: Number(x) + Number(res.epoch_offset || 0), y }];
        });
    }
    function isRatioMetric(name) {
        return /(?:iou|dice|f1|f2|precision|recall|pix(?:el)?[_/ ]?acc|accuracy)/i.test(name)
            && !/loss|learning.rate|lr[0-9]/i.test(name);
    }
    function drawCharts(type) {
        destroyCharts(type + ':');
        const set = type === 'train' ? state.trainMetrics : state.milestoneMetrics;
        const holder = $(type === 'train' ? 'yoloTrainCharts' : 'yoloMilestoneCharts');
        if (!set.size) {
            holder.innerHTML = '<div class="yolo-empty">Keine Metriken ausgewählt.</div>';
            return;
        }
        if (typeof Chart === 'undefined') {
            holder.innerHTML = `
                <div class="yolo-notice warn">
                    Chart.js konnte nicht geladen werden.
                    Die tabellarischen Ergebnisse sind unabhängig davon verfügbar.
                </div>
            `;
            return;
        }
        holder.innerHTML = '';
        for (const key of set) {
            const datasets = state.loaded.map((r, i) => ({
                label: r.name,
                data: points(r, type, key),
                borderColor: colors[i % colors.length],
                backgroundColor: colors[i % colors.length],
                borderWidth: 2,
                pointRadius: type === 'train' ? 0 : 3,
                pointHoverRadius: 4,
                tension: .08,
                spanGaps: false
            })).filter(ds => ds.data.length);
            const article = document.createElement('article');
            article.className = 'yolo-chart-card';
            const title = document.createElement('h3');
            title.textContent = key;
            article.appendChild(title);
            if (!datasets.length) {
                article.insertAdjacentHTML('beforeend', '<div class="yolo-empty">Für diese Auswahl keine Messwerte.</div>');
                holder.appendChild(article);
                continue;
            }
            const wrap = document.createElement('div');
            wrap.className = 'yolo-canvas-wrap';
            const canvas = document.createElement('canvas');
            wrap.appendChild(canvas);
            article.appendChild(wrap);
            holder.appendChild(article);
            const ratio = isRatioMetric(key);
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
                    plugins: { legend: { position: 'top' }, tooltip: { callbacks: { label: c => `${c.dataset.label}: ${nice(c.parsed.y, ratio)}` } } },
                    scales: {
                        x: { type: 'linear', title: { display: true, text: 'Epoche' }, ticks: { precision: 0 } },
                        y: { beginAtZero: false, ticks: ratio ? { callback: x => `${Math.round(Number(x) * 100)} %` } : {} }
                    }
                }
            });
            state.charts.set(type + ':' + key, chart);
        }
    }
    function imageUrl(key, id) {
        const url = new URL(window.location.href);
        url.searchParams.set('ajax', 'test_image');
        url.searchParams.set('run', key);
        url.searchParams.set('image', id);
        return url.toString();
    }
    function renderImages() {
        const runs = state.loaded.filter(run => run.kind === 'detail');
        const sortKey = $('yoloImageSort').value;
        const referenceSelect = $('yoloImageReference');
        const previousReference = referenceSelect.value;
        referenceSelect.innerHTML = runs.map(run => `
            <option value="${escapeAttr(run.key)}">${esc(run.name)}</option>
        `).join('');
        referenceSelect.value = runs.some(run => run.key === previousReference)
            ? previousReference : (runs[0]?.key ?? '');
        referenceSelect.disabled = runs.length < 2;
        const referenceRun = runs.find(run => run.key === referenceSelect.value) || runs[0] || null;
        const byRun = new Map(runs.map(run => [run.key,
            new Map((run.test_images || []).map(image => [image.id, image]))
        ]));
        const imagesById = new Map();
        runs.forEach(run => {
            (run.test_images || []).forEach(image => {
                if (!imagesById.has(image.id))
                    imagesById.set(image.id, image.label);
            });
        });
        const referenceImages = referenceRun ? byRun.get(referenceRun.key) : null;
        const referenceScore = id => referenceImages?.get(id)?.scores?.[sortKey] ?? null;
        const hasScores = sortKey !== 'filename'
            && [...imagesById.keys()].some(id => isNum(referenceScore(id)));
        const byName = (a, b) => a[1].localeCompare(b[1], 'de', { numeric: true });
        const groups = [...imagesById.entries()].sort((a, b) => {
            if (!hasScores)
                return byName(a, b);
            const left = referenceScore(a[0]), right = referenceScore(b[0]);
            if (isNum(left) && isNum(right) && left !== right)
                return right - left;
            if (isNum(left) !== isNum(right))
                return isNum(left) ? -1 : 1;
            return byName(a, b);
        });
        const fallbackNotice = sortKey !== 'filename' && groups.length && !hasScores
            ? `<div class="yolo-notice warn">Für den Referenzlauf ${esc(referenceRun?.name ?? '')} liegen keine Einzelbild-Metriken für diese Sortierung vor. Die Bilder werden nach Dateiname angezeigt.</div>`
            : '';
        const formatScore = value => isNum(value) ? nice(value, true) : '—';

        $('yoloImages').innerHTML = fallbackNotice + groups.map(([imageId, label], index) => {
            const imageCells = runs.map(run => {
                const image = byRun.get(run.key)?.get(imageId);
                const content = image ? `
                    <a
                        href="${escapeAttr(imageUrl(run.key, imageId))}"
                        target="_blank"
                        rel="noopener"
                        title="${escapeAttr(run.name)}"
                    >
                        <img
                            loading="lazy"
                            src="${escapeAttr(imageUrl(run.key, imageId))}"
                            alt="${escapeAttr(label + ' – ' + run.name)}"
                        >
                    </a>
                ` : '<div class="yolo-empty">Keine entsprechende Ausgabe vorhanden.</div>';
                return `<div class="yolo-image-item">${content}</div>`;
            }).join('');

            // Alle angezeigten Kennzahlen beziehen sich auf den gewählten Referenzlauf.
            // Die Sortierreihenfolge kann unabhängig davon auf Strict/Einzelklassen beruhen.
            const refScores = referenceImages?.get(imageId)?.scores;
            const rank = hasScores ? `${index + 1}. ` : '';
            const boundary = isNum(refScores?.miou_boundary)
                ? ` · ${esc(formatScore(refScores.miou_boundary))} (${esc(referenceRun?.name ?? '')})`
                : '';
            const classIoUs = [
                isNum(refScores?.defect_boundary) ? `Fehler ${esc(formatScore(refScores.defect_boundary))}` : '',
                isNum(refScores?.text_boundary) ? `Schrift ${esc(formatScore(refScores.text_boundary))}` : ''
            ].filter(Boolean).join(' · ');
            return `
                <article class="yolo-image-group">
                    <div class="yolo-image-group-head">
                        <span class="yolo-image-head-main">${rank}${esc(label)}${boundary}</span>
                        ${classIoUs ? `<span class="yolo-image-head-classes">${classIoUs}</span>` : ''}
                    </div>
                    <div class="yolo-image-grid yolo-image-cols-${Math.min(6, runs.length)}">
                        ${imageCells}
                    </div>
                </article>
            `;
        }).join('') || `
            <div class="yolo-empty">
                Keine finalen 2×2-Testbilder in den ausgewählten Runs gefunden.
            </div>
        `;
    }

    const GLOSSARY = [
        ['Ground Truth (GT)', 'Manuell oder regelbasiert festgelegte Referenzmaske. Ohne GT sind Qualitätsmetriken gegen eine Referenz nicht definiert.'],
        ['IoU', 'Intersection over Union: TP / (TP + FP + FN). Bewertet die Pixelüberlappung von Vorhersage und Referenz.'],
        ['mIoU', 'Mittelwert der IoU über die in der Auswertung verwendeten Klassen. Bei v9.3: Fehler und Schrift, ohne eigenen Hintergrund-Ausgang.'],
        ['Dice / F1', '2TP / (2TP + FP + FN). Für binäre Masken sind Dice und F1 identisch.'],
        ['Precision', 'TP / (TP + FP). Anteil der korrekt positiven Pixel unter allen positiv vorhergesagten Pixeln.'],
        ['Recall', 'TP / (TP + FN). Anteil der tatsächlich positiven Pixel, die erkannt wurden.'],
        ['F₂-Score', '5TP / (5TP + FP + 4FN). Gewichtung des Recall stärker als der Precision.'],
        ['Pixel Accuracy', '(TP + TN) / (TP + FP + FN + TN). Bei kleinen Fehlerregionen vom Hintergrundanteil dominiert.'],
        ['Micro-Auswertung', 'TP, FP, FN und TN werden zuerst über alle Bilder aggregiert. Die Metriken entstehen danach aus diesen Summen.'],
        [
            'Bildweise Statistik / Macro',
            'Metriken jedes Einzelbilds werden getrennt berechnet. Deren Mittelwert, Median und Streuung unterscheiden sich von Micro-Werten.'
        ],
        ['Strict', 'Pixelgenaue Auswertung ohne Randtoleranz. Maßgebliche Referenz für den direkten Klassenvergleich.'],
        [
            'Boundary',
            'Zusätzliche Auswertung mit definierter GT-Randzone. In der v9.3-Pipeline wird eine Randabweichung bis zur ausgewiesenen Breite gutgeschrieben.'
        ],
        ['Fehler / Schrift', 'Zwei unabhängige binäre Sigmoid-Ausgänge. Beide Klassen dürfen dieselben Pixel belegen. Hintergrund ist implizit.'],
        [
            'Sigmoid und Bitmaske',
            'Die beiden Ausgänge sind nicht durch Softmax gegenseitig exklusiv; die Bitmaske kann auch gleichzeitig Fehler und Schrift markieren.'
        ],
        ['Realer Test', 'Reservierte, nicht zum Training oder zur Checkpoint-Selektion genutzte Realaufnahmen. Für den Sim-to-Real-Vergleich maßgeblich.'],
        ['Synthetischer Test', 'Reservierte Renderer-Testbilder, getrennt von Trainings- und Validierungsdaten.'],
        ['Reale Validierung', 'Wird bei der Modellauswahl verwendet. Deshalb nicht als unabhängiger Real-Test ausgeben.'],
        ['results.csv', 'Epochenweise Trainings- und Validierungsmetriken aus Ultralytics. Die Graphen übernehmen Spalten und Messwerte aus der Datei.'],
        ['best.pt', 'Checkpoint, der gemäß definiertem Validierungskriterium ausgewählt wurde; nicht zwangsläufig der letzte Trainingszustand.'],
        ['Milestones', 'Zwischenprüfungen gesicherter Trainingszustände zur Untersuchung des Verlaufes der Generalisierung.'],
        ['Sim-to-Real-Gap', 'Abstand der Modellleistung zwischen synthetischen und realen Testdaten unter vergleichbarer Auswertungsmethodik.'],
        ['Leere Masken', 'Für GT und Prediction beide leer sind IoU/Dice/F2 im aktuellen v9.3-Bericht als 1 definiert; Precision/Recall ohne Nenner als null.'],
        [
            'Ältere Runs',
            'Ältere Berichte können weniger Klassen oder Metriken besitzen und keine getrennte Boundary-Auswertung anbieten. Fehlendes bleibt als fehlend markiert.'
        ]
    ];
    function renderGlossary() {
        const query = $('yoloGlossarySearch').value.toLowerCase().trim();
        const matching = GLOSSARY.filter(([name, text]) => {
            return (name + ' ' + text).toLowerCase().includes(query);
        });
        $('yoloGlossary').innerHTML = matching.map(([name, text]) => `
            <div class="yolo-glossary-row">
                <strong>${esc(name)}</strong>
                <p>${esc(text)}</p>
            </div>
        `).join('') || '<div class="yolo-empty">Keine Begriffe gefunden.</div>';
    }
    root.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click', () => {
        const active = button.dataset.tab;
        root.querySelectorAll('[data-tab]').forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        root.querySelectorAll('[data-panel]').forEach(panel => panel.hidden = panel.dataset.panel !== active);
        if (active === 'graphs')
            for (const chart of state.charts.values())
                chart.resize?.();
    }));
    $('yoloKindFilter').addEventListener('change', renderRunList);
    $('yoloRunSearch').addEventListener('input', renderRunList);
    $('yoloGlossarySearch').addEventListener('input', renderGlossary);
    $('yoloImageSort').addEventListener('change', renderImages);
    $('yoloImageReference').addEventListener('change', renderImages);
    renderRunList();
    renderGlossary();
    selectionChanged();
})();
</script>
</body>
</html>
