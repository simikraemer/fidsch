<?php

/**
 * sci/YOLO.php – YOLO-Semantic Auswertung (v10.3 + historische Läufe)
 * Read-only: liest Run-Metadaten, CSVs, JSON-Berichte und finale Vergleichsbilder.
 * Kein Zugriff auf frei übergebene Dateipfade: AJAX verwendet ausschließlich
 * im serverseitigen Katalog aufgelöste Run-Keys und Bildkennungen.
 */
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
const YOLO_META_ROOT = '/storage/Dokumente/RWTH/Bachelorarbeit/Schaekel/YOLO';
const YOLO_MAX_SELECTED_RUNS = 6;
const YOLO_MAX_IMAGES_PER_RUN = 300;
const YOLO_IMAGE_PAGE_SIZE = 12;
const YOLO_GT_ROOT = '/storage/Dokumente/RWTH/Bachelorarbeit/Schaekel/Realaufnahmen_Nachbearbeitet/_Pruefung/Kontrollansichten';
const YOLO_V10_VARIANTS = ['val_synth', 'val_mixed'];
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

/** v10: Beide Selektionsvarianten sind eigene Modelle, nicht eigene Trainingsläufe. */
function yolo_dual_info(string $root): array {
    $data = yolo_json_file($root . '/validation_dual_v10_0.json');
    $models = [];
    foreach (YOLO_V10_VARIANTS as $variant) {
        $raw = yolo_arr(yolo_path($data, 'models.' . $variant, []));
        $models[$variant] = [
            'available' => isset($raw['best_epoch']),
            'best_epoch' => yolo_number($raw['best_epoch'] ?? null),
            'best_metric' => yolo_number($raw['best_metric'] ?? null),
            'updates' => yolo_number($raw['updates'] ?? null),
            'validation_metric_key' => $raw['validation_metric_key'] ?? null,
            'last_updated' => $raw['updated_at'] ?? null,
        ];
    }
    $epochs = [];
    foreach (yolo_arr($data['epochs'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $ep = yolo_number($row['epoch'] ?? null);
        if ($ep === null) continue;
        $epochs[] = [
            'epoch' => (int)$ep,
            'val_synth_miou' => yolo_number($row['val_synth_miou'] ?? null),
            'val_mixed_miou' => yolo_number($row['val_mixed_miou'] ?? null),
            'val_synth_new_best' => yolo_bool($row['val_synth_new_best'] ?? null),
            'val_mixed_new_best' => yolo_bool($row['val_mixed_new_best'] ?? null),
            'val_synth_best_epoch_after' => yolo_number($row['val_synth_best_epoch_after'] ?? null),
            'val_mixed_best_epoch_after' => yolo_number($row['val_mixed_best_epoch_after'] ?? null),
        ];
    }
    $variantReports = [];
    foreach (YOLO_V10_VARIANTS as $variant) {
        $modelRoot = $root . '/Modelle/' . $variant;
        $variantReports[$variant] = yolo_final_reports($modelRoot);
        $variantReports[$variant]['selection'] = $models[$variant];
        $variantReports[$variant]['model_report'] = yolo_json_file($modelRoot . '/modellbericht_v10_0.json');
        $common = yolo_json_file($modelRoot . '/realtest_gemeinsam_alle_v10_3.json');
        if (!$common) // Historische v10.0–v10.2-Dateien weiterhin lesen.
            $common = yolo_json_file($modelRoot . '/realtest_gemeinsame_50_v10_0.json');
        $variantReports[$variant]['common_real'] = [
            'available' => (bool)$common,
            'image_count' => yolo_number($common['image_count'] ?? null),
            'miou_strict' => yolo_number(yolo_path($common, 'modes.strict.miou_micro')),
            'miou_boundary' => yolo_number(yolo_path($common, 'modes.boundary.miou_micro')),
        ];
    }
    return [
        'available' => (bool)$epochs || (bool)array_filter($models, static fn($m) => $m['available']),
        'models' => $models,
        'epochs' => $epochs,
        'variants' => $variantReports,
        'selection_policy' => $data['selection_policy'] ?? null,
        'training_data' => $data['training_data'] ?? null,
        'mixed_validation' => $data['mixed_validation'] ?? null,
        'real_val_unique_images' => yolo_number($data['real_val_unique_images'] ?? null),
        'mixed_real_test_images' => yolo_number($data['mixed_real_test_images'] ?? null),
        'synth_only_real_test_images' => yolo_number($data['synth_only_real_test_images'] ?? null),
        'same_real_images_for_validation_and_test' => yolo_bool($data['same_real_images_for_validation_and_test'] ?? null),
        'real_test_independent' => yolo_bool($data['real_test_independent'] ?? null),
        'last_epoch' => yolo_number($data['last_epoch'] ?? null),
    ];
}

/**
 * Read-only, server-controlled image catalog. A file basename identifies a
 * single image; there are no user-supplied file paths, paths are fixed by run.
 */
function yolo_variant_test_dir(array $entry, string $variant): ?string {
    if (!in_array($variant, YOLO_V10_VARIANTS, true)) return null;
    $newDir = $entry['runRoot'] . '/Modelle/' . $variant . '/Auswertung_Test';
    if (is_dir($newDir)) return $newDir;
    if ($variant !== 'val_synth') return null;
    foreach (['Auswertung_Test', 'Auswertung_Real_Test', 'Auswertung_Realtest'] as $old) {
        if (is_dir($entry['runRoot'] . '/' . $old)) return $entry['runRoot'] . '/' . $old;
    }
    return null;
}
function yolo_gallery_catalog(array $entry): array {
    $map = [];
    foreach (YOLO_V10_VARIANTS as $variant) {
        $dir = yolo_variant_test_dir($entry, $variant);
        if ($dir === null) continue;
        $scores = yolo_image_scores($dir);
        foreach (scandir($dir) ?: [] as $filename) {
            if (!preg_match('/^(.+)_2x2\.(?:jpe?g|png|webp)$/i', $filename, $m)) continue;
            $full = $dir . '/' . $filename;
            if (!is_file($full) || !is_readable($full)) continue;
            $stem = $m[1];
            $id = strtolower($stem);
            if (!preg_match('/^[a-z0-9][a-z0-9_\- .()]*$/i', $stem)) continue;
            if (!isset($map[$id])) $map[$id] = ['id' => $id, 'label' => $stem, 'images' => [], 'scores' => []];
            $map[$id]['images'][$variant] = true;
            $map[$id]['scores'][$variant] = $scores[$id] ?? null;
            if (is_file(YOLO_GT_ROOT . '/' . $stem . '_Kontrolle.png')) $map[$id]['images']['gt'] = true;
        }
    }
    return array_values($map);
}
function yolo_gallery_image_path(array $entry, string $stem, string $variant): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9_\- .()]*$/i', $stem)) return null;
    if ($variant === 'gt') {
        $file = YOLO_GT_ROOT . '/' . $stem . '_Kontrolle.png';
        return is_file($file) && is_readable($file) ? $file : null;
    }
    $dir = yolo_variant_test_dir($entry, $variant);
    if ($dir === null) return null;
    foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
        $file = $dir . '/' . $stem . '_2x2' . $ext;
        if (is_file($file) && is_readable($file)) return $file;
    }
    return null;
}
/** Resolve an image stem without parsing any score CSV/JSON during image transfer. */
function yolo_gallery_resolve_stem(array $entry, string $id): ?string {
    if (!preg_match('/^[a-z0-9][a-z0-9_\- .()]*$/i', $id)) return null;
    foreach (YOLO_V10_VARIANTS as $variant) {
        $dir = yolo_variant_test_dir($entry, $variant);
        if ($dir === null) continue;
        foreach (scandir($dir) ?: [] as $filename) {
            if (!preg_match('/^(.+)_2x2\.(?:jpe?g|png|webp)$/i', $filename, $matches)) continue;
            if (strtolower($matches[1]) !== strtolower($id)) continue;
            if (is_file($dir . '/' . $filename)) return $matches[1];
        }
    }
    return null;
}
function yolo_gallery_page(array $entry, string $sort, int $offset): array {
    $allowed = ['miou_boundary', 'miou_strict', 'defect_boundary', 'text_boundary', 'filename'];
    if (!in_array($sort, $allowed, true)) $sort = 'miou_boundary';
    $items = yolo_gallery_catalog($entry);
    usort($items, static function(array $a, array $b) use ($sort): int {
        if ($sort !== 'filename') {
            // Consistent reference: use val_synth wherever available; fallback
            // to val_mixed only for a run without val_synth output.
            $sa = yolo_number(($a['scores']['val_synth'] ?? $a['scores']['val_mixed'] ?? [])[$sort] ?? null);
            $sb = yolo_number(($b['scores']['val_synth'] ?? $b['scores']['val_mixed'] ?? [])[$sort] ?? null);
            if ($sa !== null && $sb !== null && $sa !== $sb) return $sb <=> $sa;
            if (($sa !== null) !== ($sb !== null)) return $sa === null ? 1 : -1;
        }
        return strnatcasecmp($a['label'], $b['label']);
    });
    $total = count($items);
    $slice = array_slice($items, $offset, YOLO_IMAGE_PAGE_SIZE);
    return ['items' => $slice, 'total' => $total, 'offset' => $offset,
            'next_offset' => $offset + count($slice) < $total ? $offset + count($slice) : null];
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
                        'defect_recall_boundary' => yolo_number($col($row, 'defect_recall_boundary') ?? $col($row, 'defect_boundary_recall')),
                        'defect_recall_strict' => yolo_number($col($row, 'defect_recall_strict') ?? $col($row, 'defect_recall')), 
                        'text_boundary' => yolo_number($col($row, 'text_iou_boundary')),
                        'text_strict' => yolo_number($col($row, 'text_iou_strict') ?? $col($row, 'text_iou')),
                        'text_recall_boundary' => yolo_number($col($row, 'text_recall_boundary') ?? $col($row, 'text_boundary_recall')),
                        'text_recall_strict' => yolo_number($col($row, 'text_recall_strict') ?? $col($row, 'text_recall')),
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
            'defect_recall_boundary' => yolo_number(yolo_path($boundary, 'defect.metrics.recall')),
            'defect_recall_strict' => yolo_number(yolo_path($strict, 'defect.metrics.recall')),
            'text_boundary' => yolo_number(yolo_path($boundary, 'text.metrics.iou')),
            'text_strict' => yolo_number(yolo_path($strict, 'text.metrics.iou')),
            'text_recall_boundary' => yolo_number(yolo_path($boundary, 'text.metrics.recall')),
            'text_recall_strict' => yolo_number(yolo_path($strict, 'text.metrics.recall')),
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
    $dual = $entry['kind'] === 'detail' ? yolo_dual_info($entry['runRoot']) : [];
    $realSplit = $entry['kind'] === 'detail'
        ? yolo_json_file($entry['runRoot'] . '/real_split_v10_0.json') : [];
    $realPolicy = yolo_arr($realSplit['policy'] ?? []);
    $realInputCheck = $entry['kind'] === 'detail'
        ? yolo_json_file($entry['runRoot'] . '/real_input_preflight_v10_0.json') : [];
    $realTotal = yolo_number(yolo_choose(
        $realSplit['image_count'] ?? null,
        $manifest['real_images_total'] ?? null,
        $realInputCheck['valid_images'] ?? null
    ));
    $realValCount = yolo_number(yolo_choose(
        $realPolicy['validation_unique_count'] ?? null,
        $manifest['mixed_real_validation_count'] ?? null,
        $realVal['unique_images'] ?? null,
        $dual['real_val_unique_images'] ?? null
    ));
    $realTestCount = yolo_number(yolo_choose(
        $realPolicy['mixed_test_image_count'] ?? null,
        $manifest['mixed_real_test_count'] ?? null,
        $dual['mixed_real_test_images'] ?? null
    ));
    $sameRealInputs = yolo_bool(yolo_choose(
        $realSplit['identical_images_in_real_validation_and_real_test'] ?? null,
        $manifest['real_validation_and_test_share_images'] ?? null,
        $dual['same_real_images_for_validation_and_test'] ?? null
    ));
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
        'real_bilder_gesamt' => $realTotal,
        'real_val_bilder' => $realValCount,
        'real_test_bilder' => $realTestCount,
        'real_val_test_identisch' => $sameRealInputs,
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
        'dual' => $dual,
        'real_data_inventory' => [
            'image_count' => $realTotal,
            'validation_count' => $realValCount,
            'test_count' => $realTestCount,
            'validation_and_test_same_images' => $sameRealInputs,
            'test_independent_for_val_mixed' => yolo_bool($realPolicy['independent_real_test_for_val_mixed'] ?? null),
            'policy' => $realPolicy,
            'image_filenames' => array_values(array_map(
                static fn($r) => (string)($r['image'] ?? ''),
                array_filter(yolo_arr($realSplit['inventory'] ?? []), 'is_array')
            )),
            'input_preflight_count' => yolo_number($realInputCheck['valid_images'] ?? null),
        ],
        // Images are requested page-by-page only when the Bilder tab opens.
        'test_images' => [],
        'configuration' => [
            'training_args' => $args,
            'mask_configuration' => $mask,
            'loss_configuration' => $loss,
            'dataset_real_validation' => $realVal,
            'real_split_full' => $realSplit,
            'manifest_full' => $manifest,
            'dataset_summary' => $dataset,
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
if (isset($_GET['ajax']) && $_GET['ajax'] === 'image_page') {
    $key = $_GET['run'] ?? null;
    $sort = $_GET['sort'] ?? 'miou_boundary';
    $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
    if (!is_string($key) || !isset($catalog[$key]) || $catalog[$key]['kind'] !== 'detail'
        || !is_string($sort) || $offset === false || $offset < 0 || $offset > 200000) {
        yolo_json(['ok' => false, 'message' => 'Ungültige Galerie-Anfrage.'], 400);
    }
    yolo_json(['ok' => true] + yolo_gallery_page($catalog[$key], $sort, $offset));
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'test_image') {
    $key = $_GET['run'] ?? null;
    $stem = $_GET['image'] ?? null;
    $variant = $_GET['variant'] ?? 'val_synth';
    if (!is_string($key) || !is_string($stem) || !is_string($variant)
        || !isset($catalog[$key]) || $catalog[$key]['kind'] !== 'detail'
        || !in_array($variant, ['val_synth', 'val_mixed', 'gt'], true)) {
        http_response_code(404);
        exit;
    }
    // Filename is only accepted if the current run actually produced it.
    // Avoid loading a score report on every image request.
    $trustedStem = yolo_gallery_resolve_stem($catalog[$key], trim($stem));
    if ($trustedStem !== null) {
        $file = yolo_gallery_image_path($catalog[$key], $trustedStem, $variant);
        if ($file !== null) yolo_image_send($file);
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
    if (count($defaults) < 1 && $run['kind'] === 'detail' && $run['files']['results'] !== null)
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
                2. Übersicht &amp; Ergebnisse
            </button>
            <button type="button" class="yolo-tab" data-tab="graphs" role="tab" aria-selected="false">
                3. Graphen
            </button>
            <button type="button" class="yolo-tab" data-tab="images" role="tab" aria-selected="false">
                4. Bilder
            </button>
            <button type="button" class="yolo-tab" data-tab="glossary" role="tab" aria-selected="false">
                5. Begriffe
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
                <details class="yolo-detail"><summary>Run-Überblick und Konfiguration im Vergleich</summary>
                    <div class="yolo-detail-inner">
                        <div id="yoloSummaryKpis"></div>
                        <div class="yolo-table-scroll"><table id="yoloOverviewTable"></table></div>
                    </div>
                </details>
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
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Ergebnisse – Real- und Synthetiktest</h2>

                    </div>
                </div>
                <div id="yoloBaCards"></div>
            </div>
        </section>

        <section data-panel="graphs" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div><h2>Trainingsverlauf, Validierung und Milestones</h2></div>
                </div>
                <div id="yoloGraphControls" class="yolo-graph-controls">
                    <div class="yolo-graph-options-group"><h3>Modellselektion (v10.0)</h3><div id="yoloDualMetrics" class="yolo-metric-list"></div></div>
                    <div class="yolo-graph-options-group"><h3>Trainingsverlauf</h3><div id="yoloTrainMetrics" class="yolo-metric-list"></div></div>
                    <div class="yolo-graph-options-group"><h3>Milestone-Auswertung</h3><div id="yoloMilestoneMetrics" class="yolo-metric-list"></div></div>
                </div>
                <div id="yoloAllCharts" class="yolo-chart-grid"></div>
            </div>
        </section>


        <section data-panel="images" role="tabpanel" hidden>
            <div class="yolo-section">
                <div class="yolo-section-head">
                    <div>
                        <h2>Reale Testbilder vergleichen</h2>
                        <p>Vorhersage ohne reale Validierung · Ground Truth (Randzone abhängig vom Run) · Vorhersage mit realer Validierung.</p>
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
                        <label for="yoloImageReference">Ausgewählter Run</label>
                        <select id="yoloImageReference" class="yolo-select" aria-label="Run für die Bildgalerie"></select>
                    </div>
                </div>
                <div id="yoloImages"></div>
                <div class="yolo-gallery-footer" id="yoloGalleryFooter" hidden>
                    <span id="yoloGalleryProgress" aria-live="polite"></span>
                    <button type="button" class="yolo-button" id="yoloLoadMoreImages">Weitere Bilder laden</button>
                </div>
                <div id="yoloGallerySentinel" aria-hidden="true"></div>
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
        dualMetrics: new Set(),
        metricInitialized: new Set(),
        activeTab: 'runs',
        galleryRequest: 0,
        galleryPage: 0,
        galleryLoading: false,
        galleryObserver: null,
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
        real_bilder_gesamt: 'Reale Bilder gesamt (vorbereitet)',
        real_val_bilder: 'Reale Val-Bilder (einzigartig)',
        real_test_bilder: 'Reale Testbilder',
        real_val_test_identisch: 'Real-Val und Real-Test identischer Bestand',
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
        if(state.activeTab==='graphs')rebuildGraphs();
        if(state.activeTab==='images')renderImages();
    }
    function renderOverview() {
        const runs = state.loaded;

        $('yoloSummaryKpis').innerHTML = runs.map((run, index) => {
            const real = run.dual?.variants?.val_synth?.real_test?.available ? run.dual.variants.val_synth.real_test : baEval(run, 'real_test');
            const synthetic = run.dual?.variants?.val_synth?.synthetic_test?.available ? run.dual.variants.val_synth.synthetic_test : baEval(run, 'synthetic_test');

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
                            <small>Real-Test – Boundary-mIoU</small>
                            <strong>${esc(nice(real.boundary?.miou, true))}</strong>
                        </div>
                        <div class="yolo-kpi">
                            <small>Synth-Test – Boundary-mIoU</small>
                            <strong>${esc(nice(synthetic.boundary?.miou, true))}</strong>
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
            'milestones',
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

    function variantReport(run, variant, domain) {
        const rep = run.dual?.variants?.[variant]?.[domain];
        return rep?.available ? rep : { available: false };
    }
    function baLegacy(run) { return !(run.dual?.available); }
    function getBaGroups(run) {
        const variants = ['val_synth', 'val_mixed'];
        const pairs = variants.flatMap(variant => ['real_test','synthetic_test'].map(domain =>
            variantReport(run, variant, domain)));
        const put = (section, label, values, kind='value') => ({section, label, values: values.map(v => v ?? null), kind});
        const result = [];
        const model = run.dual?.models || {};
        result.push(put('Modellselektion', 'Beste Epoche',
            [model.val_synth?.best_epoch,model.val_synth?.best_epoch,model.val_mixed?.best_epoch,model.val_mixed?.best_epoch], 'count'));
        result.push(put('Modellselektion', 'Validierungs-mIoU bei Best-Epoche',
            [model.val_synth?.best_metric,model.val_synth?.best_metric,model.val_mixed?.best_metric,model.val_mixed?.best_metric], 'ratio'));
        result.push(put('Modellselektion', 'Checkpoint-Aktualisierungen',
            [model.val_synth?.updates,model.val_synth?.updates,model.val_mixed?.updates,model.val_mixed?.updates], 'count'));
        result.push(put('Modellselektion', 'Reale Validierungsbilder (zur Checkpoint-Auswahl)',
            [0,0,run.real_data_inventory?.validation_count ?? run.dual?.real_val_unique_images,
             run.real_data_inventory?.validation_count ?? run.dual?.real_val_unique_images], 'count'));
        result.push(put('Modellselektion', 'Gemeinsamer Real-Test (identische Bildmenge) – Boundary-mIoU',
            [run.dual?.variants?.val_synth?.common_real?.miou_boundary,null,
             run.dual?.variants?.val_mixed?.common_real?.miou_boundary,null], 'ratio'));
        result.push(put('Datengrundlage', 'Reale Bilder im Eingangsordner',
            [run.real_data_inventory?.image_count,null,run.real_data_inventory?.image_count,null], 'count'));
        result.push(put('Datengrundlage', 'Real-Val und Real-Test gleiche Bildmenge',
            [run.real_data_inventory?.validation_and_test_same_images,null,
             run.real_data_inventory?.validation_and_test_same_images,null], 'bool'));
        result.push(put('Datengrundlage', 'Testbilder', pairs.map(r => r.successful_images), 'count'));
        result.push(put('Datengrundlage', 'Vollständig ausgewertet', pairs.map(r => r.complete), 'bool'));
        result.push(put('Datengrundlage', 'Fehler-Rand ± px', pairs.map(r => r.boundary_band?.per_class?.defect?.inner_px ?? (r.boundary_band?.inner_px ?? null)), 'count'));
        result.push(put('Datengrundlage', 'Schrift-Rand ± px', pairs.map(r => r.boundary_band?.per_class?.text?.inner_px ?? (r.boundary_band?.inner_px ?? null)), 'count'));
        const classes = ['defect','text'];
        for (const mode of ['boundary','strict']) {
            const group = mode === 'boundary' ? 'Boundary – Pixelaggregiert (klassenspezifisch)' : 'Strict – Pixelaggregiert';
            result.push(put(group, 'mIoU', pairs.map(r=>r[mode]?.miou), 'ratio'));
            for (const cls of classes) {
                for (const [key,label] of metrics)
                    result.push(put(group, `${classLabel(cls)} – ${label}`, pairs.map(r=>metric(r,mode,cls,key)), 'ratio'));
            }
            const conf = mode === 'boundary' ? 'Boundary – Konfusionszahlen' : 'Strict – Konfusionszahlen';
            for (const cls of classes) {
                for (const [key,label] of [
                    ['true_positive','TP'], ['false_positive','FP'], ['false_negative','FN'],
                    ['true_negative','TN'], ['ground_truth','GT-Pixel'], ['prediction','Vorhersagepixel'],
                    ['ignored_pixels','Ignorierte Pixel'], ['total','Bewertete Pixel'],
                    ['boundary_band_pixels','Toleranzpixel'], ['neutralized_false_positive','Neutralisierte FP'],
                    ['neutralized_false_negative','Neutralisierte FN']]) {
                    result.push(put(conf, `${classLabel(cls)} – ${label}`, pairs.map(r=>
                        r[mode]?.classes?.[cls]?.counts?.[key] ?? null), 'count'));
                }
            }
            const stats = mode === 'boundary' ? 'Boundary – Statistik je Bild' : 'Strict – Statistik je Bild';
            for (const cls of classes) for (const key of ['iou','dice','precision','recall','f2','pixel_accuracy'])
                for (const stat of ['mean','median','std','min','max','n']) {
                    result.push(put(stats, `${classLabel(cls)} – ${metricNames[key]} ${stat}`,
                        pairs.map(r=>r[mode]?.classes?.[cls]?.image_statistics?.[key]?.[stat] ?? null),
                        stat==='n'?'count':'ratio'));
                }
        }
        return result;
    }
    function cell(v, kind) {
        const valid = v !== null && v !== undefined;
        return `<td class="${valid?'':'yolo-na'}">${esc(nice(v,kind==='ratio'))}</td>`;
    }
    function fiveCells(row) { return row.values.map(v => cell(v,row.kind)).join(''); }
    function baTableHead() {
        return `<thead><tr><th rowspan="2">Kennzahl</th><th colspan="2">Val ohne Realbilder</th><th colspan="2">Val mit Realbildern (50/50)</th></tr>
        <tr><th>Real-Test</th><th>Synth-Test</th><th>Real-Test</th><th>Synth-Test</th></tr></thead>`;
    }
    function completeExport(run) {
        // Die Werte sind die berichteten Originalwerte; fehlende Daten bleiben null.
        return {
            run: run.name,
            summary: run.summary,
            configuration: run.configuration,
            files_present: run.files,
            real_data_inventory: run.real_data_inventory,
            evaluation_policy: {
                v10_3_threshold: 0.50,
                v10_3_boundary_radius_px: {defect: 4, text: 8},
                note: 'Gilt nur für v10.3 und neuere Läufe; historische Runs behalten ihre dokumentierten Regeln.'
            },
            checkpoint_selection_and_validation: run.dual,
            final_test_reports: run.evaluations,
            training_history: run.results,
            milestones: run.milestones
        };
    }
    function exportBaText(run) {
        const lines = ['YOLO – vollständiger Laufbericht', `Run: ${run.name}`, '',
            '[KONFIGURATION UND LAUF]', JSON.stringify({summary:run.summary, configuration:run.configuration, files:run.files},null,2),
            '', '[REALBILDER – ANZAHL, VERWENDUNG UND ÜBERLAPPUNG]',
            JSON.stringify(run.real_data_inventory ?? {},null,2),
            'Achtung: Falls Real-Val und Real-Test identisch sind, ist der Test für den mit Realbildern selektierten Checkpoint nicht unabhängig.',
            '', '[ERGEBNISSE: ALLE TESTKENNZAHLEN]'];
        if (run.dual?.available) {
            lines.push('Kennzahl\tReal-Test ohne Real-Val\tSynth-Test ohne Real-Val\tReal-Test mit Real-Val\tSynth-Test mit Real-Val');
            let last = '';
            for (const row of getBaGroups(run)) {
                if (row.section!==last) { last=row.section; lines.push('', `[${last}]`); }
                lines.push([row.label,...row.values.map(v=>nice(v,row.kind==='ratio'))].join('\t'));
            }
        } else {
            lines.push('Historischer Run ohne Dual-Validation:', JSON.stringify(run.evaluations,null,2));
        }
        lines.push('', '[VOLLSTÄNDIGE STRUKTURIERTE DATEN]', JSON.stringify(completeExport(run),null,2));
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
        $('yoloBaCards').innerHTML = state.loaded.map((run,index) => {
            const dual=run.dual?.available;
            const rows=dual?getBaGroups(run):[];
            const summary=rows.filter(x=>x.section==='Modellselektion'||x.section==='Datengrundlage'||x.section.startsWith('Boundary'));
            const boundarySummary=summary.filter(x=>x.section==='Modellselektion'||x.section==='Datengrundlage'||x.section==='Boundary – Pixelaggregiert (klassenspezifisch)');
            const detailSections=dual?[...new Set(rows.map(r=>r.section))].filter(x=>!['Modellselektion','Datengrundlage','Boundary – Pixelaggregiert (klassenspezifisch)'].includes(x)):[];
            const detailHTML=detailSections.map(section=>{
                const body=rows.filter(r=>r.section===section).map(row=>`<tr><td>${esc(row.label)}</td>${fiveCells(row)}</tr>`).join('');
                return `<details class="yolo-detail yolo-ba-legacy-detail"><summary>${esc(section)}</summary><div class="yolo-table-scroll"><table class="yolo-ba-table yolo-ba-five">${baTableHead()}<tbody>${body}</tbody></table></div></details>`;
            }).join('');
            const legacy=dual?'':`<details class="yolo-detail"><summary>Historische Einzelmodell-Auswertung (Strict / Boundary / Datengrundlage)</summary>
                <div class="yolo-detail-inner"><div class="yolo-table-scroll"><table class="yolo-ba-table"><thead><tr><th>Kennzahl</th><th>Real-Test</th><th>Synth-Test</th></tr></thead>
                <tbody>${['strict','boundary'].map(mode=>{
                    const real=baEval(run,'real_test'),synth=baEval(run,'synthetic_test');
                    return `<tr class="yolo-ba-group"><td colspan="3">${mode==='strict'?'Strict':'Boundary'}</td></tr>`+
                    [`<tr><td>mIoU</td>${cell(real[mode]?.miou,'ratio')}${cell(synth[mode]?.miou,'ratio')}</tr>`]
                    .concat(['defect','text'].flatMap(cls=>metrics.map(([key,label])=>`<tr><td>${esc(classLabel(cls)+' – '+label)}</td>${cell(metric(real,mode,cls,key),'ratio')}${cell(metric(synth,mode,cls,key),'ratio')}</tr>`))).join('');
                }).join('')}</tbody></table></div></div></details>`;
            const summaryBody=boundarySummary.map(row=>`<tr><td>${esc(row.label)}</td>${fiveCells(row)}</tr>`).join('');
            const epochs=run.dual?.epochs||[];
            const validationPairs=['val_synth','val_mixed'].flatMap(variant=>['real_val','synthetic_val'].map(domain=>variantReport(run,variant,domain)));
            const validationRows=dual?[
                ['Bilder',validationPairs.map(r=>r.successful_images),'count'],
                ['Boundary-mIoU',validationPairs.map(r=>r.boundary?.miou),'ratio'],
                ...['defect','text'].flatMap(cls=>metrics.map(([key,label])=>[
                    classLabel(cls)+' – '+label,validationPairs.map(r=>metric(r,'boundary',cls,key)),'ratio'
                ]))
            ]:[];
            const validationDetails=dual?`<details class="yolo-detail"><summary>Validierungsberichte (nicht als unabhängiger Test gewertet)</summary>
                <div class="yolo-table-scroll"><table class="yolo-ba-table yolo-ba-five">
                <thead><tr><th rowspan="2">Kennzahl</th><th colspan="2">Modell ohne Real-Val</th><th colspan="2">Modell mit Real-Val</th></tr>
                <tr><th>Real-Val-Auswertung</th><th>Synth-Val</th><th>Real-Val</th><th>Synth-Val</th></tr></thead>
                <tbody>${validationRows.map(([label,values,kind])=>`<tr><td>${esc(label)}</td>${values.map(v=>cell(v,kind)).join('')}</tr>`).join('')}</tbody>
                </table></div></details>`:'';
            const modelDetails=dual?`<details class="yolo-detail"><summary>Modellberichte · Selektions- und Testkonfiguration</summary>
                <div class="yolo-detail-inner"><div class="yolo-table-scroll"><table><thead><tr><th>Parameter</th><th>Val ohne Realbilder</th><th>Val mit Realbildern</th></tr></thead><tbody>
                ${['validation_policy','real_validation_count','real_test_count','real_test_policy','real_test_independent',
                   'real_test_same_images_as_mixed_validation','shared_real_test_count',
                   'synthetic_test_count','training_shared','selection_metric','timestamp_policy'].map(key=>{
                       const a=run.dual?.variants?.val_synth?.model_report?.[key];
                       const b=run.dual?.variants?.val_mixed?.model_report?.[key];
                       return `<tr><td>${esc(key)}</td><td>${esc(nice(a))}</td><td>${esc(nice(b))}</td></tr>`;
                   }).join('')}
                </tbody></table></div></div></details>`:'';
            const epochHistory=dual?`<details class="yolo-detail yolo-ba-history-detail"><summary>Modellselektion · vollständiger Epochenverlauf (${epochs.length} Epochen)</summary>
                <div class="yolo-table-scroll"><table class="yolo-ba-history-table"><thead><tr><th>Epoche</th><th>Val ohne Real: mIoU</th><th>Neues Best</th><th>Val mit Real: mIoU</th><th>Neues Best</th><th>Beste Epoche nach diesem Schritt (ohne / mit)</th></tr></thead><tbody>
                ${epochs.map(ep=>`<tr><td>${esc(nice(ep.epoch))}</td><td>${esc(nice(ep.val_synth_miou,true))}</td><td>${ep.val_synth_new_best?'Ja':'—'}</td>
                <td>${esc(nice(ep.val_mixed_miou,true))}</td><td>${ep.val_mixed_new_best?'Ja':'—'}</td>
                <td>${esc(nice(ep.val_synth_best_epoch_after))} / ${esc(nice(ep.val_mixed_best_epoch_after))}</td></tr>`).join('')}
                </tbody></table></div></details>`:'';
            return `<article class="yolo-model-card" data-ba-key="${escapeAttr(run.key)}">
                <div class="yolo-model-head"><div><h3><span class="yolo-series-${index%colors.length}">●</span> ${esc(run.name)}</h3>
                <p>${esc(nice(run.summary?.modell))} · ${esc(nice(run.summary?.epoche_letzte))} Epochen</p></div>
                <div class="yolo-flex"><button type="button" class="yolo-button primary" data-ba-copy="${escapeAttr(run.key)}">Alles für KI kopieren</button>
                <button type="button" class="yolo-button" data-ba-copy-json="${escapeAttr(run.key)}">JSON kopieren</button></div></div>
                ${dual?`<details class="yolo-detail yolo-ba-summary"><summary>Zusammenfassung · Boundary-Metriken &amp; Checkpoint-Auswahl</summary>
                    <div class="yolo-table-scroll"><table class="yolo-ba-table yolo-ba-five">${baTableHead()}<tbody>${summaryBody}</tbody></table></div></details>
                    <div class="yolo-ba-extra">${epochHistory}${validationDetails}${modelDetails}${detailHTML}</div>`:
                    `<div class="yolo-notice">Für diesen historischen Run ist keine getrennte v10-Validierung vorhanden.</div>${legacy}`}
                </article>`;
        }).join('')||'<div class="yolo-empty">Bitte einen Run auswählen.</div>';
        $('yoloBaCards').querySelectorAll('[data-ba-copy],[data-ba-copy-json]').forEach(btn=>btn.addEventListener('click',async()=>{
            const key=btn.dataset.baCopy||btn.dataset.baCopyJson;
            const run=state.loaded.find(x=>x.key===key);
            if(!run)return;
            const content=btn.dataset.baCopyJson?JSON.stringify(completeExport(run),null,2):exportBaText(run);
            const old=btn.textContent;
            try{await copyText(content);btn.textContent='Kopiert';}catch(e){renderError(String(e));btn.textContent='Fehler';}
            window.setTimeout(()=>btn.textContent=old,1500);
        }));
    }

    function destroyCharts(prefix = '') {
        for (const [key, chart] of state.charts)
            if (!prefix || key.startsWith(prefix)) {
                chart.destroy();
                state.charts.delete(key);
            }
    }
    const DUAL_GRAPH_OPTIONS=[
        ['val_synth_miou','Val-mIoU ohne Realbilder'],
        ['val_mixed_miou','Val-mIoU mit Realbildern (50/50)'],
        ['val_synth_best_epoch_after','Best-Epoche ohne Realbilder'],
        ['val_mixed_best_epoch_after','Best-Epoche mit Realbildern']
    ];
    function allKeys(type) {
        if(type==='dual')return state.loaded.some(r=>r.dual?.epochs?.length)?DUAL_GRAPH_OPTIONS.map(x=>x[0]):[];
        const keys=new Set();
        for(const r of state.loaded){const values=type==='train'?(r.results?.numeric_columns||[]):(r.milestones?.metric_keys||[]);values.forEach(k=>keys.add(k));}
        return [...keys].sort((a,b)=>a.localeCompare(b,'de',{numeric:true}));
    }
    function defaultsFor(type,keys) {
        const patterns=type==='dual'?[/^val_synth_miou$/,/^val_mixed_miou$/]:type==='train'?
            [/metrics\/.*miou/i,/metrics\/.*recall.*defect/i,/metrics\/.*iou.*defect/i,/metrics\/.*iou.*text/i]:
            [/real.*recall.*defect/i,/real.*iou.*defect/i,/real.*miou/i,/render.*miou/i];
        const selected=[];
        for(const regex of patterns){const key=keys.find(k=>regex.test(k)&&!selected.includes(k));if(key)selected.push(key);}
        return selected.length?selected:keys.filter(k=>/miou|recall|iou/i.test(k)).slice(0,3);
    }
    function rebuildGraphs() {
        for(const type of ['dual','train','milestone']) {
            const keys=allKeys(type);
            const set=type==='dual'?state.dualMetrics:type==='train'?state.trainMetrics:state.milestoneMetrics;
            for(const key of [...set])if(!keys.includes(key))set.delete(key);
            if(!state.metricInitialized.has(type)){
                defaultsFor(type,keys).forEach(key=>set.add(key));
                if(keys.length)state.metricInitialized.add(type);
            }
            const holder=$(type==='dual'?'yoloDualMetrics':type==='train'?'yoloTrainMetrics':'yoloMilestoneMetrics');
            holder.innerHTML=keys.map(key=>{
                const title=type==='dual'?DUAL_GRAPH_OPTIONS.find(x=>x[0]===key)?.[1]||key:key;
                return `<label class="yolo-metric-label"><input type="checkbox" data-metric="${escapeAttr(key)}" ${set.has(key)?'checked':''}><span>${esc(title)}</span></label>`;
            }).join('')||'<div class="yolo-muted">Keine Messwerte</div>';
            holder.querySelectorAll('input').forEach(input=>input.addEventListener('change',()=>{
                input.checked?set.add(input.dataset.metric):set.delete(input.dataset.metric);
                drawCharts();
            }));
        }
        drawCharts();
    }
    function points(run,type,key) {
        if(type==='dual')return (run.dual?.epochs||[]).flatMap(row=>isNum(row[key])?[{x:Number(row.epoch),y:row[key]}]:[]);
        if(type==='milestone')return (run.milestones?.points||[]).flatMap(row=>isNum(row.metrics?.[key])?[{x:Number(row.epoch),y:row.metrics[key]}]:[]);
        const res=run.results||{};
        return (res.rows||[]).flatMap((row,i)=>{
            const y=row[key],x=res.epoch_key?row[res.epoch_key]:i+1;
            return isNum(x)&&isNum(y)?[{x:Number(x)+Number(res.epoch_offset||0),y}]:[];
        });
    }
    function isRatioMetric(key){return /(?:iou|dice|f1|f2|precision|recall|pix(?:el)?[_/ ]?acc|accuracy)/i.test(key)&&!/loss|lr[0-9]/i.test(key);}
    function drawCharts(){
        destroyCharts();
        const holder=$('yoloAllCharts');holder.innerHTML='';
        const chosen=[['dual',state.dualMetrics],['train',state.trainMetrics],['milestone',state.milestoneMetrics]].flatMap(([type,set])=>[...set].map(key=>({type,key})));
        if(!chosen.length){holder.innerHTML='<div class="yolo-empty">Keine Metriken ausgewählt.</div>';return;}
        if(typeof Chart==='undefined'){holder.innerHTML='<div class="yolo-notice warn">Chart.js nicht verfügbar.</div>';return;}
        for(const {type,key} of chosen){
            const datasets=state.loaded.map((run,i)=>({label:run.name,data:points(run,type,key),borderColor:colors[i%colors.length],
                backgroundColor:colors[i%colors.length],borderWidth:2,pointRadius:type==='milestone'?3:0,
                pointHoverRadius:4,tension:0.08,spanGaps:false})).filter(ds=>ds.data.length);
            if(!datasets.length)continue;
            const article=document.createElement('article');article.className='yolo-chart-card';
            const label=type==='dual'?DUAL_GRAPH_OPTIONS.find(x=>x[0]===key)?.[1]||key:key;
            const h=document.createElement('h3');h.textContent=label;article.appendChild(h);
            const wrap=document.createElement('div');wrap.className='yolo-canvas-wrap';
            const canvas=document.createElement('canvas');wrap.appendChild(canvas);article.appendChild(wrap);holder.appendChild(article);
            const ratio=isRatioMetric(key);
            const chart=new Chart(canvas,{type:'line',data:{datasets},options:{responsive:true,maintainAspectRatio:false,parsing:false,animation:false,normalized:true,
                interaction:{mode:'nearest',axis:'x',intersect:false},plugins:{legend:{position:'top'},tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${nice(c.parsed.y,ratio)}`}}},
                scales:{x:{type:'linear',title:{display:true,text:'Epoche'},ticks:{precision:0}},y:{beginAtZero:false,ticks:ratio?{callback:x=>`${Math.round(Number(x)*100)} %`}:{}}}}});
            state.charts.set(type+':'+key,chart);
        }
        if(!holder.children.length)holder.innerHTML='<div class="yolo-empty">Für diese Auswahl keine Diagrammdaten.</div>';
    }

    function imageUrl(key,id,variant) {
        const url=new URL(window.location.href);
        url.searchParams.set('ajax','test_image');url.searchParams.set('run',key);
        url.searchParams.set('image',id);url.searchParams.set('variant',variant);
        return url.toString();
    }
    function imagePageUrl(key,sort,offset){
        const url=new URL(window.location.href);
        url.searchParams.set('ajax','image_page');url.searchParams.set('run',key);
        url.searchParams.set('sort',sort);url.searchParams.set('offset',String(offset));
        return url.toString();
    }
    function stopGallery() {
        ++state.galleryRequest;
        state.galleryObserver?.disconnect();state.galleryObserver=null;
        state.galleryPage=0;state.galleryLoading=false;
    }
    function imageScoreDetails(scores) {
        const fmt = value => isNum(value) ? nice(value, true) : '—';
        const items = [
            ['Recall Fehler', scores?.defect_recall_boundary ?? scores?.defect_recall_strict],
            ['IoU Fehler', scores?.defect_boundary ?? scores?.defect_strict],
            ['Recall Schrift', scores?.text_recall_boundary ?? scores?.text_recall_strict],
            ['IoU Schrift', scores?.text_boundary ?? scores?.text_strict]
        ];
        return `<div class="yolo-image-metrics" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px;padding:10px 8px;font-size:0.85rem;">
            ${items.map(([label,value])=>`<div><span class="yolo-muted">${esc(label)}:</span> <b>${esc(fmt(value))}</b></div>`).join('')}
        </div>`;
    }
    function imageSlot(image,key,variant,title) {
        const available=!!image.images?.[variant];
        return `<div class="yolo-image-item"><strong>${esc(title)}</strong>${available?
            `<a href="${escapeAttr(imageUrl(key,image.id,variant))}" target="_blank" rel="noopener" title="${escapeAttr(title)}">
             <img loading="lazy" decoding="async" src="${escapeAttr(imageUrl(key,image.id,variant))}" alt="${escapeAttr(image.label+' – '+title)}"></a>`:
            '<div class="yolo-empty">Für dieses Testbild nicht vorhanden.</div>'}
            ${variant==='gt' ? '' : imageScoreDetails(image.scores?.[variant])}</div>`;
    }
    async function loadGalleryPage(expectedRequest) {
        if(expectedRequest!==state.galleryRequest || state.galleryLoading || state.galleryPage===null)return;
        const key=$('yoloImageReference').value;
        if(!key)return;
        const sort=$('yoloImageSort').value;
        const offset=state.galleryPage;
        state.galleryLoading=true;
        $('yoloLoadMoreImages').disabled=true;
        try{
            const response=await fetch(imagePageUrl(key,sort,offset),{credentials:'same-origin',cache:'no-store'});
            const data=await response.json();
            if(expectedRequest!==state.galleryRequest)return;
            if(!response.ok||!data.ok)throw Error(data.message||`HTTP ${response.status}`);
            const html=(data.items||[]).map((image,i)=>{
                const title=image.label;
                return `<article class="yolo-image-group"><div class="yolo-image-group-head"><span class="yolo-image-head-main">${esc(title)}</span></div>
                    <div class="yolo-image-triptych">
                    ${imageSlot(image,key,'val_synth',state.loaded.find(r=>r.key===key)?.dual?.available?'Val ohne Realbilder':'Historisches Modell')}
                    ${imageSlot(image,key,'gt','Ground Truth (Randzone)')}
                    ${imageSlot(image,key,'val_mixed','Val mit Realbildern')}
                    </div></article>`;
            }).join('');
            $('yoloImages').insertAdjacentHTML('beforeend',html);
            state.galleryPage=data.next_offset;
            const footer=$('yoloGalleryFooter');footer.hidden=!data.total || data.next_offset===null;
            $('yoloGalleryProgress').textContent=`${Math.min(offset+(data.items||[]).length,data.total)} von ${data.total} Bildern geladen`;
            if(!data.total && !offset)$('yoloImages').innerHTML='<div class="yolo-empty">Keine Real-Testbilder für diesen Run vorhanden.</div>';
        }catch(err){
            if(expectedRequest===state.galleryRequest){
                $('yoloImages').insertAdjacentHTML('beforeend',`<div class="yolo-notice error">${esc(err.message||err)}</div>`);
                $('yoloGalleryFooter').hidden=false;
            }
        }finally{
            if(expectedRequest===state.galleryRequest){state.galleryLoading=false;$('yoloLoadMoreImages').disabled=false;}
        }
    }
    function renderImages() {
        stopGallery();
        const runs=state.loaded.filter(run=>run.kind==='detail');
        const select=$('yoloImageReference'),previous=select.value;
        select.innerHTML=runs.map(run=>`<option value="${escapeAttr(run.key)}">${esc(run.name)}</option>`).join('');
        select.value=runs.some(run=>run.key===previous)?previous:runs[0]?.key||'';
        select.disabled=!runs.length;
        $('yoloImages').innerHTML='';$('yoloGalleryFooter').hidden=true;
        if(!runs.length){$('yoloImages').innerHTML='<div class="yolo-empty">Keinen Detail-Run ausgewählt.</div>';return;}
        const request=state.galleryRequest;
        loadGalleryPage(request);
        if(typeof IntersectionObserver==='function'){
            state.galleryObserver=new IntersectionObserver(entries=>{
                if(entries.some(x=>x.isIntersecting)&&state.activeTab==='images'&&state.galleryPage!==null)
                    loadGalleryPage(request);
            },{rootMargin:'180px'});
            state.galleryObserver.observe($('yoloGallerySentinel'));
        }
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
        ['best.pt', 'Checkpoint mit dem besten Validierungs-mIoU. v10.0 wählt einen eigenen Checkpoint je Validierungsstrategie.'],
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
        state.activeTab=active;
        if (active === 'graphs') {
            rebuildGraphs();
            for (const chart of state.charts.values()) chart.resize?.();
        }
        if (active === 'images') renderImages();
    }));
    $('yoloKindFilter').addEventListener('change', renderRunList);
    $('yoloRunSearch').addEventListener('input', renderRunList);
    $('yoloGlossarySearch').addEventListener('input', renderGlossary);
    $('yoloImageSort').addEventListener('change', () => { if(state.activeTab==='images') renderImages(); });
    $('yoloImageReference').addEventListener('change', () => { if(state.activeTab==='images') renderImages(); });
    $('yoloLoadMoreImages').addEventListener('click', () => loadGalleryPage(state.galleryRequest));
    renderRunList();
    renderGlossary();
    selectionChanged();
})();
</script>
</body>
</html>
