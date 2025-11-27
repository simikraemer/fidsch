<?php
// sci/Fragen.php

// Auth (Seite geschützt)
require_once __DIR__ . '/../auth.php';

// DB Verbindung
require_once __DIR__ . '/../db.php';
$sciconn->set_charset('utf8mb4');

$uploadDir = __DIR__ . '/../uploads/lernkarten';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

// Fächer + Themen (hardcodiert)
$faecherThemen = [
    'Simulationstechnik' => [
        'Diskrete Systeme',
        'Zustandsraumdarstellung',
        'Monte-Carlo-Simulation',
        'Laplace-Transformation',
        'Signalflusspläne',
    ],
    'Regelungstechnik' => [
        'PID-Regler',
        'Streckenmodellierung',
        'Bode-Diagramm',
        'Stabilitätskriterien',
        'Zustandsrückführung',
    ],
];

$faecher = array_keys($faecherThemen);
$alleThemen = [];
foreach ($faecherThemen as $fachName => $topicList) {
    foreach ($topicList as $topic) {
        $alleThemen[$topic] = true;
    }
}
$themen = array_keys($alleThemen);

$errors = [];

// =====================
// POST-Verarbeitung
// =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_frage') {
        $frageId    = isset($_POST['frage_id']) ? (int)$_POST['frage_id'] : 0;
        $fach       = trim($_POST['fach'] ?? '');
        $thema      = trim($_POST['thema'] ?? '');
        $antwortTyp = $_POST['antwort_typ'] ?? 'freitext';
        $frageText  = trim($_POST['frage_text'] ?? '');
        $istAktiv   = isset($_POST['ist_aktiv']) ? 1 : 0;
        $bildPfad   = null;

        if ($frageText === '') {
            $errors[] = 'Der Fragetext darf nicht leer sein.';
        }
        if ($antwortTyp === '') {
            $antwortTyp = 'freitext';
        }

        // erlaubte Bildtypen (Frage + Antwortoptionen)
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];

        // Bild-Upload für Frage
        if (isset($_FILES['bild']) && $_FILES['bild']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['bild']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['bild']['tmp_name'];
                $mime    = mime_content_type($tmpPath);

                if (isset($allowedTypes[$mime])) {
                    $ext = $allowedTypes[$mime];
                    try {
                        $random = bin2hex(random_bytes(4));
                    } catch (Exception $e) {
                        $random = bin2hex(openssl_random_pseudo_bytes(4));
                    }
                    $filename = 'karte_' . date('Ymd_His') . '_' . $random . '.' . $ext;
                    $destPath = $uploadDir . '/' . $filename;

                    if (move_uploaded_file($tmpPath, $destPath)) {
                        $bildPfad = 'uploads/lernkarten/' . $filename;
                    } else {
                        $errors[] = 'Das Bild zur Frage konnte nicht gespeichert werden.';
                    }
                } else {
                    $errors[] = 'Ungültiges Bildformat bei der Frage. Erlaubt sind JPG, PNG, GIF, WEBP.';
                }
            } else {
                $errors[] = 'Fehler beim Bild-Upload der Frage.';
            }
        } else {
            // bestehendes Bild behalten
            $bildPfad = $_POST['bestehendes_bild'] ?? null;
        }

        if (empty($errors)) {
            if ($frageId > 0) {
                // Update
                $stmt = $sciconn->prepare("
                    UPDATE fragen
                    SET frage_text = ?, antwort_typ = ?, bild_pfad = ?, fach = ?, thema = ?, ist_aktiv = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('ssssssi', $frageText, $antwortTyp, $bildPfad, $fach, $thema, $istAktiv, $frageId);
                $stmt->execute();
                $stmt->close();

                // Antwortoptionen neu anlegen: erst löschen, dann neu einfügen
                $del = $sciconn->prepare("DELETE FROM antwortoptionen WHERE frage_id = ?");
                $del->bind_param('i', $frageId);
                $del->execute();
                $del->close();

                $frageIdNeu    = $frageId;
                $redirectParam = 'updated=1';
            } else {
                // Insert
                $stmt = $sciconn->prepare("
                    INSERT INTO fragen (frage_text, antwort_typ, bild_pfad, fach, thema, ist_aktiv)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('sssssi', $frageText, $antwortTyp, $bildPfad, $fach, $thema, $istAktiv);
                $stmt->execute();
                $frageIdNeu = $stmt->insert_id;
                $stmt->close();

                $redirectParam = 'created=1';
            }

            // Antwortoptionen speichern (Text + optional Bild)
            if (isset($_POST['antwort_text']) && is_array($_POST['antwort_text'])) {
                $antwortTexte            = $_POST['antwort_text'];
                $antwortKorrekt          = $_POST['antwort_ist_korrekt'] ?? [];
                $antwortFeedback         = $_POST['antwort_feedback'] ?? [];
                $antwortBestehendeBilder = $_POST['antwort_bestehendes_bild'] ?? [];

                $insertOpt = $sciconn->prepare("
                    INSERT INTO antwortoptionen (frage_id, text, bild_pfad, ist_korrekt, feedback, sortierung)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($antwortTexte as $idx => $text) {
                    $text = trim($text);
                    $fb   = isset($antwortFeedback[$idx]) ? trim($antwortFeedback[$idx]) : null;

                    // wenn weder Text noch Bild vorhanden -> Zeile ignorieren
                    $hatNeuesBild = isset($_FILES['antwort_bild']['name'][$idx]) &&
                                    $_FILES['antwort_bild']['error'][$idx] !== UPLOAD_ERR_NO_FILE;

                    $bestehendesBild = $antwortBestehendeBilder[$idx] ?? null;

                    $bildPfadOption = null;

                    if ($hatNeuesBild && $_FILES['antwort_bild']['error'][$idx] === UPLOAD_ERR_OK) {
                        $tmpPathOpt = $_FILES['antwort_bild']['tmp_name'][$idx];
                        $mimeOpt    = mime_content_type($tmpPathOpt);

                        if (isset($allowedTypes[$mimeOpt])) {
                            $extOpt = $allowedTypes[$mimeOpt];
                            try {
                                $randomOpt = bin2hex(random_bytes(4));
                            } catch (Exception $e) {
                                $randomOpt = bin2hex(openssl_random_pseudo_bytes(4));
                            }
                            $filenameOpt = 'antwort_' . date('Ymd_His') . '_' . $randomOpt . '.' . $extOpt;
                            $destPathOpt = $uploadDir . '/' . $filenameOpt;

                            if (move_uploaded_file($tmpPathOpt, $destPathOpt)) {
                                $bildPfadOption = 'uploads/lernkarten/' . $filenameOpt;
                            }
                        }
                    } elseif (!$hatNeuesBild && $bestehendesBild) {
                        $bildPfadOption = $bestehendesBild;
                    }

                    // wenn gar nichts ausgefüllt ist (kein Text, kein Bild), überspringen
                    if ($text === '' && !$bildPfadOption) {
                        continue;
                    }

                    $isCorrect = isset($antwortKorrekt[$idx]) ? 1 : 0;
                    $sort      = (int)$idx;

                    if ($fb === '') {
                        $fb = null;
                    }
                    if ($bildPfadOption === '') {
                        $bildPfadOption = null;
                    }

                    $insertOpt->bind_param(
                        'issisi',
                        $frageIdNeu,
                        $text,
                        $bildPfadOption,
                        $isCorrect,
                        $fb,
                        $sort
                    );
                    $insertOpt->execute();
                }
                $insertOpt->close();
            }

            header('Location: /sci/Fragen.php?' . $redirectParam);
            exit;
        }
    } elseif ($action === 'delete_frage') {
        $frageId = isset($_POST['frage_id']) ? (int)$_POST['frage_id'] : 0;
        if ($frageId > 0) {
            $stmt = $sciconn->prepare("DELETE FROM fragen WHERE id = ?");
            $stmt->bind_param('i', $frageId);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: /sci/Fragen.php?deleted=1');
        exit;
    }
}

// =============================
// AJAX: Frage + Antworten laden
// =============================
if (isset($_GET['action']) && $_GET['action'] === 'load_frage') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    $response = [];

    if ($id > 0) {
        $stmt = $sciconn->prepare("
            SELECT id, frage_text, antwort_typ, bild_pfad, fach, thema, ist_aktiv
            FROM fragen
            WHERE id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $frage  = $result->fetch_assoc();
        $stmt->close();

        if ($frage) {
            $stmt2 = $sciconn->prepare("
                SELECT id, text, bild_pfad, ist_korrekt, feedback, sortierung
                FROM antwortoptionen
                WHERE frage_id = ?
                ORDER BY sortierung, id
            ");
            $stmt2->bind_param('i', $id);
            $stmt2->execute();
            $result2   = $stmt2->get_result();
            $antworten = [];
            while ($row = $result2->fetch_assoc()) {
                $antworten[] = $row;
            }
            $stmt2->close();

            $response = [
                'frage'     => $frage,
                'antworten' => $antworten
            ];
        }
    }

    echo json_encode($response);
    exit;
}

// =============================
// Filter / Listen (nur für Initialwerte)
// =============================
$fachFilter  = trim($_GET['fach']  ?? '');
$themaFilter = trim($_GET['thema'] ?? '');
$search      = trim($_GET['q']     ?? '');

// Fragenliste (ungefiltert, JS filtert clientseitig)
$sql = "
    SELECT f.*,
           (SELECT COUNT(*) FROM antwortoptionen ao WHERE ao.frage_id = f.id) AS anzahl_antworten
    FROM fragen f
    ORDER BY f.fach, f.thema, f.id DESC
";
$fragenResult = $sciconn->query($sql);

// Rendering starten
$page_title = 'Lernkarten – Fragen';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="content-wrap content-wrap-sci">
    <h1 class="ueberschrift">Lernkarten – Fragen verwalten</h1>

    <div class="toolbar">
        <div class="fragen-filter">
            <input
                type="text"
                id="searchInput"
                class="search-input"
                placeholder="Suche im Fragetext / Thema..."
                value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
            >

            <select id="fachFilter" class="kategorie-select">
                <option value="">Fach: alle</option>
                <?php foreach ($faecher as $fachOpt): ?>
                    <option value="<?php echo htmlspecialchars($fachOpt, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php if ($fachFilter === $fachOpt) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($fachOpt, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select id="themaFilter" class="kategorie-select">
                <option value="">Thema: alle</option>
                <?php foreach ($themen as $themaOpt): ?>
                    <option value="<?php echo htmlspecialchars($themaOpt, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php if ($themaFilter === $themaOpt) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($themaOpt, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="button" id="newFrageBtn">+ Neue Frage</button>
    </div>

    <?php if (isset($_GET['created']) || isset($_GET['updated']) || isset($_GET['deleted']) || !empty($errors)): ?>
        <div class="status-msg <?php echo !empty($errors) ? 'is-err' : 'is-ok'; ?>" id="statusMsg">
            <?php if (!empty($errors)): ?>
                <?php echo htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?>
            <?php elseif (isset($_GET['created'])): ?>
                Frage wurde angelegt.
            <?php elseif (isset($_GET['updated'])): ?>
                Frage wurde aktualisiert.
            <?php elseif (isset($_GET['deleted'])): ?>
                Frage wurde gelöscht.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="food-grid" id="fragenGrid">
        <?php if ($fragenResult && $fragenResult->num_rows > 0): ?>
            <?php while ($frage = $fragenResult->fetch_assoc()): ?>
                <?php
                    $kurz = mb_substr($frage['frage_text'], 0, 80, 'UTF-8');
                    if (mb_strlen($frage['frage_text'], 'UTF-8') > 80) {
                        $kurz .= '…';
                    }
                    $searchText = $frage['frage_text'] . ' ' . ($frage['thema'] ?? '') . ' ' . ($frage['fach'] ?? '');
                ?>
                <div
                    class="food-card frage-card"
                    data-frage-id="<?php echo (int)$frage['id']; ?>"
                    data-fach="<?php echo htmlspecialchars($frage['fach'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    data-thema="<?php echo htmlspecialchars($frage['thema'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    data-text="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <div class="card-title">
                        <span class="title-text">
                            <?php echo htmlspecialchars($kurz, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <div class="meta-row">
                        <?php if (!empty($frage['fach'])): ?>
                            <span class="pill"><?php echo htmlspecialchars($frage['fach'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($frage['thema'])): ?>
                            <span class="badge"><?php echo htmlspecialchars($frage['thema'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="meta-row">
                        <span class="nutri">
                            <?php
                                switch ($frage['antwort_typ']) {
                                    case 'mc_einfach':  $typLabel = 'MC (einfach)';  break;
                                    case 'mc_mehrfach': $typLabel = 'MC (mehrfach)'; break;
                                    case 'zahl':        $typLabel = 'Zahl';          break;
                                    case 'lueckentext': $typLabel = 'Lückentext';    break;
                                    default:            $typLabel = 'Freitext';
                                }
                                echo $typLabel;
                            ?>
                        </span>
                        <span class="subtle">
                            <?php echo (int)$frage['anzahl_antworten']; ?> Antwortoption(en)
                        </span>
                        <?php if (!(int)$frage['ist_aktiv']): ?>
                            <span class="subtle">• inaktiv</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($frage['bild_pfad'])): ?>
                        <div class="meta-row">
                            <?php $bildUrl = '/sci/karte_image.php?pfad=' . urlencode($frage['bild_pfad']); ?>
                            <img
                                src="<?php echo htmlspecialchars($bildUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                alt="Bild zur Frage"
                                class="frage-bild-thumb"
                            >
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>Keine Fragen gefunden. Lege deine erste Frage an!</p>
        <?php endif; ?>
    </div>
</div>

<div class="modal modal-sci" id="frageModal" style="display:none;">
    <div class="modal-content modal-content-sci">
        <span class="close-button" id="closeFrageModal">&times;</span>
        <h2 id="frageModalTitle">Frage anlegen</h2>
        <form id="frageForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_frage">
            <input type="hidden" name="frage_id" id="frage_id">
            <input type="hidden" name="bestehendes_bild" id="bestehendes_bild">

            <div class="form-block">
                <div class="input-row input-row-top">
                    <div class="input-group-dropdown">
                        <label for="fach">Fach</label>
                        <select name="fach" id="fach">
                            <option value="">Fach wählen</option>
                            <?php foreach ($faecherThemen as $fachName => $topicList): ?>
                                <option value="<?php echo htmlspecialchars($fachName, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($fachName, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group-dropdown">
                        <label for="thema">Thema</label>
                        <select name="thema" id="thema">
                            <option value="">Bitte zuerst Fach wählen</option>
                        </select>
                    </div>
                    <div class="input-group-dropdown">
                        <label for="antwort_typ">Fragetyp</label>
                        <select name="antwort_typ" id="antwort_typ">
                            <option value="freitext">Freitext</option>
                            <option value="mc_einfach">Multiple Choice (eine richtig)</option>
                            <option value="mc_mehrfach">Multiple Choice (mehrere richtig)</option>
                            <option value="zahl">Zahl</option>
                            <option value="lueckentext">Lückentext</option>
                        </select>
                    </div>
                    <div class="input-group input-group-inline-checkbox">
                        <label for="ist_aktiv" class="inline-checkbox-label">
                            <input type="checkbox" name="ist_aktiv" id="ist_aktiv" checked>
                            Frage aktiv
                        </label>
                    </div>
                </div>

                <div class="input-group">
                    <label for="frage_text">Fragetext</label>
                    <textarea name="frage_text" id="frage_text" rows="4" required></textarea>
                </div>

                <div class="input-row">
                    <div class="input-group">
                        <label for="bild">Bild zur Frage (optional)</label>
                        <input type="file" name="bild" id="bild" accept="image/*">
                    </div>
                    <div class="input-group">
                        <label>Aktuelles Bild</label>
                        <div id="bildPreviewWrapper">
                            <img src="" alt="Bildvorschau" id="bildPreview" class="frage-bild-preview" style="display:none;">
                        </div>
                    </div>
                </div>

                <div class="input-group">
                    <label>Antwortoptionen / Musterlösungen</label>
                    <p class="subtle">
                        Für Multiple Choice mehrere Optionen mit Häkchen bei den korrekten Antworten.
                        Für Freitext / Zahl / Lückentext hier die Musterlösung(en) hinterlegen. Entweder Text oder Bild (oder beides).
                    </p>
                    <div id="antwortOptionenContainer"></div>
                    <button type="button" class="btn-secondary" id="addAntwortBtn">+ Antwortoption hinzufügen</button>
                </div>
            </div>

            <div class="modal-actions">
                <button type="submit" id="saveFrageBtn">Speichern</button>
                <button type="button" class="btn-secondary" id="cancelFrageBtn">Abbrechen</button>
                <button type="button" class="btn-secondary" id="deleteFrageBtn" style="display:none;">Löschen</button>
            </div>
        </form>
    </div>
</div>

<script>
    const faecherThemen = <?php
        echo json_encode(
            $faecherThemen,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    ?>;

    let antwortIndexCounter = 0;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function clearAntwortOptionen() {
        const container = document.getElementById('antwortOptionenContainer');
        if (!container) return;
        container.innerHTML = '';
        antwortIndexCounter = 0;
    }

    function updateAntwortUI() {
        const container = document.getElementById('antwortOptionenContainer');
        if (!container) return;

        const rows  = Array.from(container.querySelectorAll('.antwort-row'));
        const count = rows.length;

        rows.forEach(row => {
            const removeBtn = row.querySelector('.antwort-remove-btn');
            const cb        = row.querySelector('.antwort-korrekt-label input[type="checkbox"]');

            if (count === 1) {
                if (cb) {
                    cb.checked  = true;
                    cb.disabled = true;
                }
                if (removeBtn) {
                    removeBtn.style.display = 'none';
                }
            } else {
                if (cb) cb.disabled = false;
                if (removeBtn) removeBtn.style.display = 'inline-block';
            }
        });
    }

    function addAntwortRow(data = null) {
        const container = document.getElementById('antwortOptionenContainer');
        if (!container) return;

        const idx     = antwortIndexCounter++;
        const row     = document.createElement('div');
        row.className = 'antwort-row';

        const textVal   = data && data.text       ? data.text       : '';
        const fbVal     = data && data.feedback   ? data.feedback   : '';
        const isCorrect = data && String(data.ist_korrekt) === '1';
        const bildPfad  = data && data.bild_pfad ? data.bild_pfad : '';

        const escapedText = escapeHtml(textVal);
        const escapedFb   = escapeHtml(fbVal);
        const escapedBild = escapeHtml(bildPfad);

        let previewSrc = '';
        if (bildPfad) {
            previewSrc = '/sci/karte_image.php?pfad=' + encodeURIComponent(bildPfad);
        }

        row.innerHTML = `
            <div class="antwort-row-main">
                <input
                    type="text"
                    name="antwort_text[${idx}]"
                    placeholder="Antworttext / Musterlösung"
                    value="${escapedText}"
                >
                <label class="antwort-korrekt-label">
                    <input
                        type="checkbox"
                        name="antwort_ist_korrekt[${idx}]"
                        ${isCorrect ? 'checked' : ''}
                    >
                    korrekt
                </label>
            </div>
            <div class="antwort-row-meta">
                <input
                    type="text"
                    name="antwort_feedback[${idx}]"
                    placeholder="Feedback / Hinweis (optional)"
                    value="${escapedFb}"
                >
                <button type="button" class="btn-secondary antwort-remove-btn">Entfernen</button>
            </div>
            <div class="antwort-row-bild">
                <div class="antwort-bild-upload">
                    <label>Bild als Antwort (optional)</label>
                    <input
                        type="file"
                        name="antwort_bild[${idx}]"
                        class="antwort-bild-input"
                        accept="image/*"
                    >
                    <input
                        type="hidden"
                        name="antwort_bestehendes_bild[${idx}]"
                        value="${escapedBild}"
                    >
                </div>
                <div class="antwort-bild-preview-wrapper">
                    <img
                        src="${previewSrc}"
                        class="antwort-bild-preview"
                        style="display:${previewSrc ? 'block' : 'none'};"
                        alt="Antwortbild"
                    >
                </div>
            </div>
        `;

        container.appendChild(row);

        const removeBtn = row.querySelector('.antwort-remove-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                container.removeChild(row);
                updateAntwortUI();
            });
        }

        const fileInput = row.querySelector('.antwort-bild-input');
        const imgPrev   = row.querySelector('.antwort-bild-preview');
        if (fileInput && imgPrev) {
            fileInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        imgPrev.src = e.target.result;
                        imgPrev.style.display = 'block';
                    };
                    reader.readAsDataURL(this.files[0]);
                } else {
                    imgPrev.src = '';
                    imgPrev.style.display = 'none';
                }
            });
        }

        updateAntwortUI();
    }

    function setModalFachUndThemaValues(fach, thema) {
        const fachSelect  = document.getElementById('fach');
        const themaSelect = document.getElementById('thema');
        if (!fachSelect || !themaSelect) return;

        fachSelect.value = fach || '';
        updateThemaOptionsForModal(fach || '');

        if (thema) {
            themaSelect.value = thema;
        }
    }

    function updateThemaOptionsForModal(fachValParam) {
        const fachSelect  = document.getElementById('fach');
        const themaSelect = document.getElementById('thema');
        if (!fachSelect || !themaSelect) return;

        const fachVal = typeof fachValParam === 'string' ? fachValParam : fachSelect.value;

        themaSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = fachVal ? 'Thema wählen' : 'Bitte zuerst Fach wählen';
        themaSelect.appendChild(placeholder);

        if (fachVal && faecherThemen && faecherThemen[fachVal]) {
            faecherThemen[fachVal].forEach(topic => {
                const opt = document.createElement('option');
                opt.value = topic;
                opt.textContent = topic;
                themaSelect.appendChild(opt);
            });
        }
    }

    function updateThemaOptionsForFilter() {
        const fachFilterSelect  = document.getElementById('fachFilter');
        const themaFilterSelect = document.getElementById('themaFilter');
        if (!fachFilterSelect || !themaFilterSelect) return;

        const fachVal      = fachFilterSelect.value;
        const currentThema = themaFilterSelect.value;

        themaFilterSelect.innerHTML = '';
        const defaultOpt = document.createElement('option');
        defaultOpt.value = '';
        defaultOpt.textContent = 'Thema: alle';
        themaFilterSelect.appendChild(defaultOpt);

        let topics = [];
        if (fachVal && faecherThemen && faecherThemen[fachVal]) {
            topics = faecherThemen[fachVal].slice();
        } else if (faecherThemen) {
            Object.keys(faecherThemen).forEach(fName => {
                faecherThemen[fName].forEach(t => {
                    if (!topics.includes(t)) {
                        topics.push(t);
                    }
                });
            });
        }

        topics.forEach(t => {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            themaFilterSelect.appendChild(opt);
        });

        if (topics.includes(currentThema)) {
            themaFilterSelect.value = currentThema;
        } else {
            themaFilterSelect.value = '';
        }
    }

    function filterFragen() {
        const searchInput       = document.getElementById('searchInput');
        const fachFilterSelect  = document.getElementById('fachFilter');
        const themaFilterSelect = document.getElementById('themaFilter');
        const cards             = document.querySelectorAll('.frage-card');

        const term  = (searchInput ? searchInput.value : '').trim().toLowerCase();
        const fach  = fachFilterSelect ? fachFilterSelect.value : '';
        const thema = themaFilterSelect ? themaFilterSelect.value : '';

        cards.forEach(card => {
            const cardText  = (card.dataset.text || '').toLowerCase();
            const cardFach  = card.dataset.fach || '';
            const cardThema = card.dataset.thema || '';

            let visible = true;

            if (term && !cardText.includes(term)) {
                visible = false;
            }
            if (fach && cardFach !== fach) {
                visible = false;
            }
            if (thema && cardThema !== thema) {
                visible = false;
            }

            card.style.display = visible ? '' : 'none';
        });
    }

    function openFrageModal(mode, frageId = null) {
        const modal = document.getElementById('frageModal');
        const title = document.getElementById('frageModalTitle');
        const form  = document.getElementById('frageForm');
        const deleteBtn = document.getElementById('deleteFrageBtn');
        if (!modal || !title || !form) return;

        if (deleteBtn) {
            deleteBtn.style.display = 'none';
        }

        // Reset Form
        form.reset();
        const frageIdInput       = document.getElementById('frage_id');
        const bestehendesBildInp = document.getElementById('bestehendes_bild');
        if (frageIdInput)       frageIdInput.value = '';
        if (bestehendesBildInp) bestehendesBildInp.value = '';

        clearAntwortOptionen();

        const aktivCb = document.getElementById('ist_aktiv');
        if (aktivCb) aktivCb.checked = true;

        const bildPreview = document.getElementById('bildPreview');
        if (bildPreview) {
            bildPreview.style.display = 'none';
            bildPreview.src = '';
        }

        const fachSelect  = document.getElementById('fach');
        const themaSelect = document.getElementById('thema');
        if (fachSelect)  fachSelect.value = '';
        if (themaSelect) {
            themaSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Bitte zuerst Fach wählen';
            themaSelect.appendChild(placeholder);
        }

        if (mode === 'new') {
            title.textContent = 'Frage anlegen';
            modal.style.display = 'flex';
            addAntwortRow(); // eine leere Zeile
        } else if (mode === 'edit' && frageId) {
            title.textContent = 'Frage bearbeiten';
            modal.style.display = 'flex';

            fetch('?action=load_frage&id=' + encodeURIComponent(frageId))
                .then(resp => resp.json())
                .then(data => {
                    if (!data || !data.frage) {
                        alert('Frage konnte nicht geladen werden.');
                        closeFrageModal();
                        return;
                    }
                    const f = data.frage;

                    if (frageIdInput) frageIdInput.value = f.id || '';

                    const frageTextEl = document.getElementById('frage_text');
                    if (frageTextEl) frageTextEl.value = f.frage_text || '';

                    const antwortTyp = document.getElementById('antwort_typ');
                    if (antwortTyp && f.antwort_typ) {
                        antwortTyp.value = f.antwort_typ;
                    }

                    if (aktivCb) {
                        aktivCb.checked = parseInt(f.ist_aktiv, 10) === 1;
                    }

                    setModalFachUndThemaValues(f.fach || '', f.thema || '');

                    if (f.bild_pfad && bildPreview && bestehendesBildInp) {
                        const pfad = '/sci/karte_image.php?pfad=' + encodeURIComponent(f.bild_pfad);
                        bildPreview.src = pfad;
                        bildPreview.style.display = 'block';
                        bestehendesBildInp.value  = f.bild_pfad;
                    }

                    clearAntwortOptionen();
                    if (Array.isArray(data.antworten) && data.antworten.length > 0) {
                        data.antworten.forEach(opt => {
                            addAntwortRow({
                                text:        opt.text,
                                ist_korrekt: opt.ist_korrekt,
                                feedback:    opt.feedback,
                                bild_pfad:   opt.bild_pfad
                            });
                        });
                    } else {
                        addAntwortRow();
                    }

                    // Delete-Button nur im Edit-Mode sichtbar
                    const delBtn = document.getElementById('deleteFrageBtn');
                    if (delBtn && f.id) {
                        delBtn.style.display = 'inline-block';
                    }
                })
                .catch(() => {
                    alert('Fehler beim Laden der Frage.');
                    closeFrageModal();
                });
        }
    }

    function closeFrageModal() {
        const modal = document.getElementById('frageModal');
        if (modal) modal.style.display = 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const newBtn           = document.getElementById('newFrageBtn');
        const closeBtn         = document.getElementById('closeFrageModal');
        const cancelBtn        = document.getElementById('cancelFrageBtn');
        const modal            = document.getElementById('frageModal');
        const statusMsg        = document.getElementById('statusMsg');
        const addAntwortBtn    = document.getElementById('addAntwortBtn');
        const fachSelectModal  = document.getElementById('fach');
        const fachFilterSelect = document.getElementById('fachFilter');
        const themaFilterSelect= document.getElementById('themaFilter');
        const searchInput      = document.getElementById('searchInput');
        const frageBildInput   = document.getElementById('bild');
        const frageBildPreview = document.getElementById('bildPreview');
        const frageForm        = document.getElementById('frageForm');
        const deleteBtn        = document.getElementById('deleteFrageBtn');

        if (frageBildInput && frageBildPreview) {
            frageBildInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        frageBildPreview.src = e.target.result;
                        frageBildPreview.style.display = 'block';
                    };
                    reader.readAsDataURL(frageBildInput.files[0]);
                } else {
                    frageBildPreview.src = '';
                    frageBildPreview.style.display = 'none';
                }
            });
        }

        if (newBtn) {
            newBtn.addEventListener('click', () => openFrageModal('new'));
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeFrageModal);
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeFrageModal);
        }
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    closeFrageModal();
                }
            });
        }

        document.querySelectorAll('.frage-card').forEach(card => {
            card.style.cursor = 'pointer';
            card.addEventListener('click', function () {
                const frageId = this.getAttribute('data-frage-id');
                if (frageId) {
                    openFrageModal('edit', frageId);
                }
            });
        });

        if (statusMsg) {
            setTimeout(() => {
                statusMsg.style.display = 'none';
            }, 4000);
        }

        if (addAntwortBtn) {
            addAntwortBtn.addEventListener('click', () => addAntwortRow());
        }

        if (fachSelectModal) {
            fachSelectModal.addEventListener('change', () => updateThemaOptionsForModal());
            updateThemaOptionsForModal('');
        }

        if (fachFilterSelect) {
            fachFilterSelect.addEventListener('change', () => {
                updateThemaOptionsForFilter();
                filterFragen();
            });
            updateThemaOptionsForFilter();
        }

        if (themaFilterSelect) {
            themaFilterSelect.addEventListener('change', filterFragen);
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterFragen);
        }

        if (deleteBtn && frageForm) {
            deleteBtn.addEventListener('click', function () {
                const frageIdInput = document.getElementById('frage_id');
                if (!frageIdInput || !frageIdInput.value) {
                    return;
                }
                if (!confirm('Frage wirklich löschen?')) {
                    return;
                }
                const actionInput = frageForm.querySelector('input[name="action"]');
                if (actionInput) {
                    actionInput.value = 'delete_frage';
                }
                frageForm.submit();
            });
        }


        filterFragen();
    });
</script>


</body>
</html>
