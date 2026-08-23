<?php
// phan/Relations.php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

$phanconn->set_charset('utf8mb4');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['phan_csrf'])) {
    $_SESSION['phan_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string)$_SESSION['phan_csrf'];


/* =========================================================
 * Helfer
 * ========================================================= */

function rel_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function rel_exec(
    mysqli $db,
    string $sql,
    array $params = []
): mysqli_stmt {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($params) {
        $types = '';
        $values = array_values($params);
        $refs = [];

        foreach ($values as $i => $value) {
            $types .= is_int($value)
                ? 'i'
                : (is_float($value) ? 'd' : 's');

            $refs[$i] = &$values[$i];
        }

        $stmt->bind_param($types, ...$refs);
    }

    $stmt->execute();

    return $stmt;
}

function rel_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = rel_exec(
        $db,
        $sql,
        $params
    );

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return is_array($row)
        ? $row
        : null;
}

function rel_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = rel_exec(
        $db,
        $sql,
        $params
    );

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    return $rows;
}

function rel_json(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    header(
        'Cache-Control: no-store, max-age=0'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function rel_types(): array
{
    return [
        'friend' => [
            'label' => 'Befreundet',
            'color' => '#2e9f52',
            'symmetric' => true,
        ],

        'parent_child' => [
            'label' => 'Eltern / Kind',
            'color' => '#3178c6',
            'symmetric' => false,
        ],

        'siblings' => [
            'label' => 'Geschwister',
            'color' => '#7a52c7',
            'symmetric' => true,
        ],

        'committed' => [
            'label' => 'Feste Beziehung',
            'color' => '#d43b8c',
            'symmetric' => true,
        ],

        'casual' => [
            'label' => 'Lose Beziehung',
            'color' => '#dc8126',
            'symmetric' => true,
        ],

        'colleges' => [
            'label' => 'Kollegen',
            'color' => '#727272',
            'symmetric' => true,
        ],

        'enemies' => [
            'label' => 'Verfeindet',
            'color' => '#c93c32',
            'symmetric' => true,
        ],
    ];
}

function rel_validate_pair(
    mysqli $db,
    array $types,
    int $from,
    int $to,
    string $type,
    int $excludeId = 0
): array {
    if (
        $from <= 0
        || $to <= 0
    ) {
        throw new RuntimeException(
            'Bitte beide Charaktere auswählen.'
        );
    }

    if ($from === $to) {
        throw new RuntimeException(
            'Ein Charakter kann keine Beziehung '
            . 'zu sich selbst haben.'
        );
    }

    if (!isset($types[$type])) {
        throw new RuntimeException(
            'Ungültiger Beziehungstyp.'
        );
    }

    if (
        !rel_one(
            $db,
            'SELECT id
             FROM chars
             WHERE id = ?',
            [$from]
        )
        || !rel_one(
            $db,
            'SELECT id
             FROM chars
             WHERE id = ?',
            [$to]
        )
    ) {
        throw new RuntimeException(
            'Mindestens ein Charakter existiert nicht mehr.'
        );
    }

    if ($types[$type]['symmetric']) {
        [
            $from,
            $to,
        ] = [
            min($from, $to),
            max($from, $to),
        ];
    }

    $duplicateSql = '
        SELECT id
        FROM relations
        WHERE char_from_id = ?
          AND char_to_id = ?
          AND relation_type = ?
    ';

    $params = [
        $from,
        $to,
        $type,
    ];

    if ($excludeId > 0) {
        $duplicateSql .= '
          AND id <> ?
        ';

        $params[] = $excludeId;
    }

    if (
        rel_one(
            $db,
            $duplicateSql,
            $params
        )
    ) {
        throw new RuntimeException(
            'Diese Beziehung existiert bereits.'
        );
    }

    return [
        $from,
        $to,
        $type,
    ];
}

$types = rel_types();


/* =========================================================
 * POST / AJAX
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (
            !hash_equals(
                $csrf,
                (string)($_POST['csrf'] ?? '')
            )
        ) {
            throw new RuntimeException(
                'Ungültiges Formular-Token.'
            );
        }

        $action = (string)(
            $_POST['action']
            ?? ''
        );

        if (
            $action === 'create'
            || $action === 'update'
        ) {
            $id = max(
                0,
                (int)($_POST['id'] ?? 0)
            );

            $from = max(
                0,
                (int)(
                    $_POST['char_from_id']
                    ?? 0
                )
            );

            $to = max(
                0,
                (int)(
                    $_POST['char_to_id']
                    ?? 0
                )
            );

            $type = (string)(
                $_POST['relation_type']
                ?? ''
            );

            [
                $from,
                $to,
                $type,
            ] = rel_validate_pair(
                $phanconn,
                $types,
                $from,
                $to,
                $type,
                $action === 'update'
                    ? $id
                    : 0
            );

            if ($action === 'create') {
                $stmt = rel_exec(
                    $phanconn,
                    'INSERT INTO relations (
                        char_from_id,
                        char_to_id,
                        relation_type
                     )
                     VALUES (?, ?, ?)',
                    [
                        $from,
                        $to,
                        $type,
                    ]
                );

                $id =
                    (int)$stmt->insert_id;

                $stmt->close();

                rel_json([
                    'ok' => true,
                    'id' => $id,
                    'created' => true,
                ]);
            }

            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Beziehung.'
                );
            }

            $existing = rel_one(
                $phanconn,
                'SELECT id
                 FROM relations
                 WHERE id = ?',
                [$id]
            );

            if (!$existing) {
                throw new RuntimeException(
                    'Beziehung existiert nicht mehr.'
                );
            }

            rel_exec(
                $phanconn,
                'UPDATE relations
                 SET
                    char_from_id = ?,
                    char_to_id = ?,
                    relation_type = ?
                 WHERE id = ?',
                [
                    $from,
                    $to,
                    $type,
                    $id,
                ]
            )->close();

            rel_json([
                'ok' => true,
                'id' => $id,
                'updated' => true,
            ]);
        }


        if ($action === 'delete') {
            $id = max(
                0,
                (int)($_POST['id'] ?? 0)
            );

            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Beziehung.'
                );
            }

            rel_exec(
                $phanconn,
                'DELETE FROM relations
                 WHERE id = ?',
                [$id]
            )->close();

            rel_json([
                'ok' => true,
                'deleted' => true,
            ]);
        }

        throw new RuntimeException(
            'Unbekannte Aktion.'
        );

    } catch (Throwable $e) {
        rel_json(
            [
                'ok' => false,
                'message' => $e->getMessage(),
            ],
            400
        );
    }
}


/* =========================================================
 * Daten
 * ========================================================= */

$chars = rel_all(
    $phanconn,
    '
    SELECT
        c.id,
        c.call_name,
        c.first_name,
        c.last_name,
        c.species,
        c.occupation,
        c.faction,
        c.region_id,
        c.image_path,
        r.title AS region_title
    FROM chars c
    LEFT JOIN regions r
        ON r.id = c.region_id
    ORDER BY
        c.call_name,
        c.last_name,
        c.first_name
    '
);

$regions = rel_all(
    $phanconn,
    '
    SELECT
        id,
        title,
        image_path
    FROM regions
    ORDER BY title
    '
);


$relations = rel_all(
    $phanconn,
    '
    SELECT
        id,
        char_from_id,
        char_to_id,
        relation_type
    FROM relations
    ORDER BY
        relation_type,
        id
    '
);


/* =========================================================
 * JSON für Diagramm
 * ========================================================= */

$graphChars = array_map(
    static fn(array $char): array => [
        'id' =>
            (int)$char['id'],

        'name' =>
            (string)$char['call_name'],

        'full' =>
            trim(
                (string)(
                    $char['first_name']
                    ?? ''
                )
                . ' '
                . (string)(
                    $char['last_name']
                    ?? ''
                )
            ),

        'species' =>
            (string)(
                $char['species']
                ?? ''
            ),

        'occupation' =>
            (string)(
                $char['occupation']
                ?? ''
            ),

        'faction' =>
            (string)(
                $char['faction']
                ?? ''
            ),

        'region' =>
            (string)(
                $char['region_title']
                ?? ''
            ),

        'region_id' =>
            isset($char['region_id'])
                ? (int)$char['region_id']
                : null,

        'thumb' =>
            !empty($char['image_path'])
                ? '/phan/chars?thumb='
                    . (int)$char['id']
                : null,

        'image' =>
            !empty($char['image_path'])
                ? '/phan/chars?image='
                    . (int)$char['id']
                : null,
    ],
    $chars
);

$graphRelations = [];

foreach ($relations as $relation) {
    $type =
        (string)$relation['relation_type'];

    if (!isset($types[$type])) {
        continue;
    }

    $graphRelations[] = [
        'id' =>
            (int)$relation['id'],

        'from' =>
            (int)$relation['char_from_id'],

        'to' =>
            (int)$relation['char_to_id'],

        'type' =>
            $type,

        'label' =>
            $types[$type]['label'],

        'color' =>
            $types[$type]['color'],

        'directed' =>
            !$types[$type]['symmetric'],
    ];
}


/* =========================================================
 * Rendering
 * ========================================================= */

$page_title = 'Beziehungen';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>


<div class="relations-page">

    <div
        class="relations-region-tabs"
        id="relationsRegionTabs"
    >

        <button
            type="button"
            class="relations-region-tab active"
            data-region-id=""
            data-region-image=""
        >
            Alle
        </button>


        <?php foreach ($regions as $region): ?>

            <button
                type="button"
                class="relations-region-tab"
                data-region-id="<?= (int)$region['id'] ?>"
                data-region-image="<?= !empty($region['image_path'])
                    ? '/phan/regions?image=' . (int)$region['id']
                    : ''
                ?>"
            >
                <?= rel_h($region['title']) ?>
            </button>

        <?php endforeach; ?>

    </div>


    <div
        class="relations-shell"
        id="relationsShell"
    >

        <div
            class="relations-center-indicator"
            id="relationsCenterIndicator"
            hidden
        >
            <span class="relations-center-indicator-dot"></span>

            <span class="relations-center-indicator-label">
                Zentriert:
            </span>

            <strong id="relationsCenteredCharName"></strong>

            <span class="relations-center-indicator-hint">
                Klick ins Freie zum Beenden
            </span>

            <button
                type="button"
                class="relations-center-indicator-close"
                id="relationsCenterIndicatorClose"
                aria-label="Zentrierung beenden"
                title="Zentrierung beenden"
            >
                ×
            </button>
        </div>


        <div class="relations-graph-actions">

            <div
                class="relations-status"
                id="relationsStatus"
                aria-live="polite"
            ></div>

            <button
                type="button"
                id="newRelationButton"
            >
                + Beziehung
            </button>

            <button
                type="button"
                id="manageRelationsButton"
            >
                Verwalten
            </button>

            <button
                type="button"
                id="resetViewButton"
            >
                Zentrieren
            </button>

        </div>

        <div
            class="relations-viewport"
            id="relationsViewport"
        >

            <div
                class="relations-world"
                id="relationsWorld"
            >

                <svg
                    class="relations-svg"
                    id="relationsSvg"
                    viewBox="0 0 4000 3000"
                    preserveAspectRatio="none"
                >                    <g id="relationsEdges"></g>
                </svg>


                <div id="relationsNodes"></div>

            </div>

        </div>


        <div class="relations-legend">

            <div class="relations-legend-title">
                Legende
            </div>

            <?php foreach (
                $types as $key => $info
            ): ?>

                <label>

                    <input
                        type="checkbox"
                        class="relation-filter"
                        data-type="<?= rel_h($key) ?>"
                        checked
                    >

                    <span
                        class="relations-legend-color"
                        style="
                            background:
                            <?= rel_h(
                                $info['color']
                            ) ?>;
                        "
                    ></span>

                    <span>
                        <?= rel_h(
                            $info['label']
                        ) ?>
                    </span>

                </label>

            <?php endforeach; ?>

        </div>


        <?php if (!$chars): ?>

            <div class="relations-empty">
                Noch keine Charaktere vorhanden.
            </div>

        <?php elseif (!$relations): ?>

            <div class="relations-empty">
                Noch keine Beziehungen vorhanden.
            </div>

        <?php endif; ?>

    </div>

</div>



<!-- =====================================================
     Neue Beziehung
     ===================================================== -->

<div
    class="relations-modal"
    id="addRelationModal"
    hidden
>

    <div
        class="relations-modal-backdrop"
        data-close-add-modal
    ></div>


    <div
        class="relations-modal-dialog relations-modal-dialog--add"
        role="dialog"
        aria-modal="true"
        aria-labelledby="addRelationModalTitle"
    >

        <div class="relations-modal-head">

            <h2 id="addRelationModalTitle">
                Neue Beziehung
            </h2>

            <button
                type="button"
                class="relations-modal-close"
                data-close-add-modal
                aria-label="Schließen"
            >
                ×
            </button>

        </div>


        <form
            class="relations-add-form"
            id="addRelationForm"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= rel_h($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="create"
            >


            <div class="relations-add-grid">

                <div
                    class="relations-char-picker"
                    data-picker="add-from"
                >

                    <div
                        class="relations-char-picker-title"
                        id="addFromTitle"
                    >
                        Charakter A
                    </div>

                    <input
                        type="hidden"
                        name="char_from_id"
                        id="addFromId"
                    >

                    <input
                        type="search"
                        class="relations-char-search"
                        id="addFromSearch"
                        placeholder="Name, Art, Beruf, Region …"
                        autocomplete="off"
                    >

                    <div
                        class="relations-char-selected"
                        id="addFromSelected"
                        hidden
                    ></div>

                    <div
                        class="relations-char-results"
                        id="addFromResults"
                    ></div>

                </div>


                <div class="relations-add-type">

                    <label>
                        Beziehung

                        <select
                            name="relation_type"
                            id="addRelationType"
                            required
                        >

                            <?php foreach (
                                $types
                                as $key => $info
                            ): ?>

                                <option
                                    value="<?= rel_h($key) ?>"
                                >
                                    <?= rel_h(
                                        $info['label']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                </div>


                <div
                    class="relations-char-picker"
                    data-picker="add-to"
                >

                    <div
                        class="relations-char-picker-title"
                        id="addToTitle"
                    >
                        Charakter B
                    </div>

                    <input
                        type="hidden"
                        name="char_to_id"
                        id="addToId"
                    >

                    <input
                        type="search"
                        class="relations-char-search"
                        id="addToSearch"
                        placeholder="Name, Art, Beruf, Region …"
                        autocomplete="off"
                    >

                    <div
                        class="relations-char-selected"
                        id="addToSelected"
                        hidden
                    ></div>

                    <div
                        class="relations-char-results"
                        id="addToResults"
                    ></div>

                </div>

            </div>


            <div class="relations-modal-footer">

                <button
                    type="submit"
                >
                    Beziehung hinzufügen
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     Beziehungen bearbeiten
     ===================================================== -->

<div
    class="relations-modal"
    id="editRelationsModal"
    hidden
>

    <div
        class="relations-modal-backdrop"
        data-close-edit-modal
    ></div>


    <div
        class="relations-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="editRelationsModalTitle"
    >

        <div class="relations-modal-head">

            <h2 id="editRelationsModalTitle">
                Beziehungen verwalten
            </h2>

            <button
                type="button"
                class="relations-modal-close"
                data-close-edit-modal
                aria-label="Schließen"
            >
                ×
            </button>

        </div>


        <div class="relations-modal-body">

            <div class="relations-manager-list">

                <input
                    type="search"
                    id="relationSearch"
                    placeholder="Beziehung oder beteiligte Person suchen …"
                    autocomplete="off"
                >

                <div
                    class="relations-manager-results"
                    id="relationResults"
                ></div>

            </div>


            <form
                class="relations-manager-editor"
                id="editRelationForm"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= rel_h($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="update"
                >

                <input
                    type="hidden"
                    name="id"
                    id="editRelationId"
                    value=""
                >


                <div
                    class="relations-manager-placeholder"
                    id="editRelationPlaceholder"
                >
                    Links eine Beziehung auswählen.
                </div>


                <div
                    class="relations-manager-fields"
                    id="editRelationFields"
                    hidden
                >

                    <div
                        class="relations-char-picker"
                        data-picker="edit-from"
                    >

                        <div
                            class="relations-char-picker-title"
                            id="editFromTitle"
                        >
                            Charakter A
                        </div>

                        <input
                            type="hidden"
                            name="char_from_id"
                            id="editFromId"
                        >

                        <input
                            type="search"
                            class="relations-char-search"
                            id="editFromSearch"
                            placeholder="Name, Art, Beruf, Region …"
                            autocomplete="off"
                        >

                        <div
                            class="relations-char-selected"
                            id="editFromSelected"
                            hidden
                        ></div>

                        <div
                            class="relations-char-results"
                            id="editFromResults"
                        ></div>

                    </div>


                    <label class="relations-manager-type">
                        Beziehung

                        <select
                            name="relation_type"
                            id="editRelationType"
                            required
                        >

                            <?php foreach (
                                $types
                                as $key => $info
                            ): ?>

                                <option
                                    value="<?= rel_h($key) ?>"
                                >
                                    <?= rel_h(
                                        $info['label']
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>


                    <div
                        class="relations-char-picker"
                        data-picker="edit-to"
                    >

                        <div
                            class="relations-char-picker-title"
                            id="editToTitle"
                        >
                            Charakter B
                        </div>

                        <input
                            type="hidden"
                            name="char_to_id"
                            id="editToId"
                        >

                        <input
                            type="search"
                            class="relations-char-search"
                            id="editToSearch"
                            placeholder="Name, Art, Beruf, Region …"
                            autocomplete="off"
                        >

                        <div
                            class="relations-char-selected"
                            id="editToSelected"
                            hidden
                        ></div>

                        <div
                            class="relations-char-results"
                            id="editToResults"
                        ></div>

                    </div>


                    <div class="relations-manager-buttons">

                        <button type="submit">
                            Änderungen speichern
                        </button>

                        <button
                            type="button"
                            class="phan-danger"
                            id="deleteRelationButton"
                        >
                            Beziehung löschen
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

</div>


<script>
(() => {
    'use strict';

    const CHARS =
        <?= json_encode(
            $graphChars,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const RELATIONS =
        <?= json_encode(
            $graphRelations,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const RELATION_TYPES =
        <?= json_encode(
            $types,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;

    const CSRF =
        <?= json_encode(
            $csrf,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        ) ?>;


    const charMap =
        new Map(
            CHARS.map(
                char => [
                    char.id,
                    char,
                ]
            )
        );

    const relationMap =
        new Map(
            RELATIONS.map(
                relation => [
                    relation.id,
                    relation,
                ]
            )
        );


    /* =====================================================
     * DOM
     * ===================================================== */

    const relationsShell =
        document.getElementById(
            'relationsShell'
        );

    const centerIndicator =
        document.getElementById(
            'relationsCenterIndicator'
        );

    const centeredCharName =
        document.getElementById(
            'relationsCenteredCharName'
        );

    const centerIndicatorClose =
        document.getElementById(
            'relationsCenterIndicatorClose'
        );

    const regionTabs =
        Array.from(
            document.querySelectorAll(
                '.relations-region-tab'
            )
        );

    const viewport =
        document.getElementById(
            'relationsViewport'
        );

    const world =
        document.getElementById(
            'relationsWorld'
        );

    const nodesLayer =
        document.getElementById(
            'relationsNodes'
        );

    const edgesLayer =
        document.getElementById(
            'relationsEdges'
        );

    const status =
        document.getElementById(
            'relationsStatus'
        );

    const newRelationButton =
        document.getElementById(
            'newRelationButton'
        );

    const manageRelationsButton =
        document.getElementById(
            'manageRelationsButton'
        );

    const resetViewButton =
        document.getElementById(
            'resetViewButton'
        );


    const addModal =
        document.getElementById(
            'addRelationModal'
        );

    const addForm =
        document.getElementById(
            'addRelationForm'
        );

    const addType =
        document.getElementById(
            'addRelationType'
        );


    const editModal =
        document.getElementById(
            'editRelationsModal'
        );

    const relationSearch =
        document.getElementById(
            'relationSearch'
        );

    const relationResults =
        document.getElementById(
            'relationResults'
        );

    const editForm =
        document.getElementById(
            'editRelationForm'
        );

    const editRelationId =
        document.getElementById(
            'editRelationId'
        );

    const editType =
        document.getElementById(
            'editRelationType'
        );

    const editPlaceholder =
        document.getElementById(
            'editRelationPlaceholder'
        );

    const editFields =
        document.getElementById(
            'editRelationFields'
        );

    const deleteRelationButton =
        document.getElementById(
            'deleteRelationButton'
        );


    if (
        !viewport
        || !world
        || !nodesLayer
        || !edgesLayer
    ) {
        return;
    }


    const nodeMap =
        new Map();

    const edgeMap =
        new Map();

    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let panState = null;
    let selectedRelationId = null;
    let selectedRegionId = null;
    let centeredCharId = null;
    let statusTimer = null;


    /* =====================================================
     * Status
     * ===================================================== */

    function setStatus(
        text,
        isError = false
    ) {
        if (!status) {
            return;
        }

        window.clearTimeout(
            statusTimer
        );

        status.textContent =
            text;

        status.classList.toggle(
            'error',
            isError
        );

        if (
            text
            && !isError
        ) {
            statusTimer =
                window.setTimeout(
                    () => {
                        status.textContent =
                            '';
                    },
                    1400
                );
        }
    }


    /* =====================================================
     * Allgemeine Hilfen
     * ===================================================== */

    function normalize(value) {
        return String(
            value ?? ''
        )
            .trim()
            .toLocaleLowerCase(
                'de'
            );
    }


    function initials(name) {
        return (
            String(name ?? '')
                .trim()
                .split(/\s+/)
                .filter(Boolean)
                .slice(0, 2)
                .map(
                    part => part[0]
                )
                .join('')
                .toUpperCase()
            || '?'
        );
    }


    function charName(id) {
        return (
            charMap.get(id)?.name
            || 'Unbekannt'
        );
    }


    function charSearchText(char) {
        return normalize(
            [
                char.name,
                char.full,
                char.species,
                char.occupation,
                char.faction,
                char.region,
            ].join(' ')
        );
    }


    function charMeta(char) {
        return [
            char.full,
            char.species,
            char.occupation,
            char.faction,
            char.region,
        ]
            .filter(Boolean)
            .join(' · ');
    }


    function visibleChars() {
        if (selectedRegionId === null) {
            return CHARS;
        }

        return CHARS.filter(
            char =>
                Number(char.region_id)
                === selectedRegionId
        );
    }


    function visibleCharIds() {
        return new Set(
            visibleChars().map(
                char => char.id
            )
        );
    }


    function activeRelationTypes() {
        return new Set(
            Array.from(
                document.querySelectorAll(
                    '.relation-filter:checked'
                )
            ).map(
                checkbox =>
                    checkbox.dataset.type
            )
        );
    }


    function visibleRelations() {
        const visibleIds =
            visibleCharIds();

        const activeTypes =
            activeRelationTypes();

        return RELATIONS.filter(
            relation =>
                visibleIds.has(
                    relation.from
                )
                && visibleIds.has(
                    relation.to
                )
                && activeTypes.has(
                    relation.type
                )
        );
    }


    function relationFromForm(
        id,
        formData
    ) {
        const type =
            String(
                formData.get(
                    'relation_type'
                )
                || ''
            );

        const info =
            RELATION_TYPES[type]
            || {
                label: type,
                color: '#777',
                symmetric: true,
            };

        let from =
            Number(
                formData.get(
                    'char_from_id'
                )
                || 0
            );

        let to =
            Number(
                formData.get(
                    'char_to_id'
                )
                || 0
            );

        /*
         * Der Server normalisiert symmetrische Relationen
         * ebenfalls auf die kleinere/größere ID.
         */
        if (
            info.symmetric
            && from > to
        ) {
            [
                from,
                to,
            ] = [
                to,
                from,
            ];
        }

        return {
            id:
                Number(id),

            from,
            to,
            type,

            label:
                info.label
                || type,

            color:
                info.color
                || '#777',

            directed:
                !info.symmetric,
        };
    }


    function replaceRelationInMemory(
        relation
    ) {
        const index =
            RELATIONS.findIndex(
                item =>
                    item.id
                    === relation.id
            );

        if (index >= 0) {
            RELATIONS[index] =
                relation;
        } else {
            RELATIONS.push(
                relation
            );
        }

        relationMap.set(
            relation.id,
            relation
        );
    }


    function rebuildGraphAfterRelationChange() {
        createEdges();
        applyFiltersAndRelayout();
    }


    function relationText(relation) {
        if (
            relation.type
            === 'parent_child'
        ) {
            return (
                charName(relation.from)
                + ' → '
                + charName(relation.to)
                + ' · '
                + relation.label
            );
        }

        return (
            charName(relation.from)
            + ' ↔ '
            + charName(relation.to)
            + ' · '
            + relation.label
        );
    }


    function applyTransform() {
        world.style.transform =
            `translate(${translateX}px, ${translateY}px) `
            + `scale(${scale})`;
    }


    /* =====================================================
     * API
     * ===================================================== */

    async function postRelation(data) {
        data.set(
            'ajax',
            '1'
        );

        const response =
            await fetch(
                '/phan/relations',
                {
                    method: 'POST',
                    body: data,
                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',
                    },
                    credentials:
                        'same-origin',
                }
            );

        const payload =
            await response
                .json()
                .catch(
                    () => null
                );

        if (
            !response.ok
            || !payload?.ok
        ) {
            throw new Error(
                payload?.message
                || 'Aktion fehlgeschlagen.'
            );
        }

        return payload;
    }


    /* =====================================================
     * Wiederverwendbarer Charakter-Sucher
     * ===================================================== */

    function createCharacterPicker({
        hiddenId,
        searchId,
        selectedId,
        resultsId,
    }) {
        const hidden =
            document.getElementById(
                hiddenId
            );

        const search =
            document.getElementById(
                searchId
            );

        const selected =
            document.getElementById(
                selectedId
            );

        const results =
            document.getElementById(
                resultsId
            );


        function currentChar() {
            const id =
                Number(
                    hidden?.value
                    || 0
                );

            return charMap.get(id)
                || null;
        }


        function renderSelected() {
            const char =
                currentChar();

            if (
                !selected
                || !search
            ) {
                return;
            }

            if (!char) {
                selected.hidden =
                    true;

                selected.innerHTML =
                    '';

                search.hidden =
                    false;

                return;
            }

            selected.hidden =
                false;

            search.hidden =
                true;

            selected.innerHTML =
                '';


            const card =
                document.createElement(
                    'div'
                );

            card.className =
                'relations-char-selected-card';


            const face =
                document.createElement(
                    'div'
                );

            face.className =
                'relations-char-avatar';


            if (char.thumb) {
                const img =
                    document.createElement(
                        'img'
                    );

                img.src =
                    char.thumb;

                img.alt = '';
                img.loading =
                    'lazy';

                img.decoding =
                    'async';

                face.appendChild(
                    img
                );
            } else {
                face.textContent =
                    initials(
                        char.name
                    );
            }


            const text =
                document.createElement(
                    'div'
                );

            text.className =
                'relations-char-selected-text';


            const strong =
                document.createElement(
                    'strong'
                );

            strong.textContent =
                char.name;


            const meta =
                document.createElement(
                    'span'
                );

            meta.textContent =
                charMeta(char)
                || '—';


            text.appendChild(
                strong
            );

            text.appendChild(
                meta
            );


            const change =
                document.createElement(
                    'button'
                );

            change.type =
                'button';

            change.textContent =
                'Ändern';

            change.addEventListener(
                'click',
                () => {
                    hidden.value =
                        '';

                    search.hidden =
                        false;

                    search.value =
                        '';

                    selected.hidden =
                        true;

                    renderResults('');

                    search.focus();
                }
            );


            card.appendChild(
                face
            );

            card.appendChild(
                text
            );

            card.appendChild(
                change
            );

            selected.appendChild(
                card
            );
        }


        function selectChar(char) {
            if (!hidden) {
                return;
            }

            hidden.value =
                String(
                    char.id
                );

            if (search) {
                search.value =
                    '';
            }

            if (results) {
                results.innerHTML =
                    '';
            }

            renderSelected();
        }


        function renderResults(query) {
            if (!results) {
                return;
            }

            const needle =
                normalize(query);

            const filtered =
                visibleChars()
                    .filter(
                        char =>
                            !needle
                            || charSearchText(
                                char
                            ).includes(
                                needle
                            )
                    )
                    .slice(
                        0,
                        60
                    );

            results.innerHTML =
                '';


            if (!filtered.length) {
                const empty =
                    document.createElement(
                        'div'
                    );

                empty.className =
                    'relations-char-empty';

                empty.textContent =
                    'Keine Charaktere gefunden.';

                results.appendChild(
                    empty
                );

                return;
            }


            filtered.forEach(
                char => {
                    const button =
                        document.createElement(
                            'button'
                        );

                    button.type =
                        'button';

                    button.className =
                        'relations-char-result';


                    const face =
                        document.createElement(
                            'div'
                        );

                    face.className =
                        'relations-char-avatar';


                    if (char.thumb) {
                        const img =
                            document.createElement(
                                'img'
                            );

                        img.src =
                            char.thumb;

                        img.alt = '';
                        img.loading =
                            'lazy';

                        img.decoding =
                            'async';

                        face.appendChild(
                            img
                        );
                    } else {
                        face.textContent =
                            initials(
                                char.name
                            );
                    }


                    const text =
                        document.createElement(
                            'div'
                        );

                    text.className =
                        'relations-char-result-text';


                    const strong =
                        document.createElement(
                            'strong'
                        );

                    strong.textContent =
                        char.name;


                    const meta =
                        document.createElement(
                            'span'
                        );

                    meta.textContent =
                        charMeta(char)
                        || '—';


                    text.appendChild(
                        strong
                    );

                    text.appendChild(
                        meta
                    );


                    button.appendChild(
                        face
                    );

                    button.appendChild(
                        text
                    );


                    button.addEventListener(
                        'click',
                        () => {
                            selectChar(
                                char
                            );
                        }
                    );


                    results.appendChild(
                        button
                    );
                }
            );
        }


        search?.addEventListener(
            'input',
            () => {
                renderResults(
                    search.value
                );
            }
        );


        search?.addEventListener(
            'focus',
            () => {
                renderResults(
                    search.value
                );
            }
        );


        function setValue(id) {
            if (!hidden) {
                return;
            }

            hidden.value =
                id
                    ? String(id)
                    : '';

            renderSelected();

            if (!id) {
                renderResults('');
            }
        }


        function reset() {
            setValue(null);

            if (search) {
                search.value =
                    '';
            }
        }


        renderSelected();


        return {
            setValue,
            reset,
            renderResults,
            currentChar,
        };
    }


    const addFromPicker =
        createCharacterPicker({
            hiddenId:
                'addFromId',

            searchId:
                'addFromSearch',

            selectedId:
                'addFromSelected',

            resultsId:
                'addFromResults',
        });


    const addToPicker =
        createCharacterPicker({
            hiddenId:
                'addToId',

            searchId:
                'addToSearch',

            selectedId:
                'addToSelected',

            resultsId:
                'addToResults',
        });


    const editFromPicker =
        createCharacterPicker({
            hiddenId:
                'editFromId',

            searchId:
                'editFromSearch',

            selectedId:
                'editFromSelected',

            resultsId:
                'editFromResults',
        });


    const editToPicker =
        createCharacterPicker({
            hiddenId:
                'editToId',

            searchId:
                'editToSearch',

            selectedId:
                'editToSelected',

            resultsId:
                'editToResults',
        });


    /* =====================================================
     * Richtungslabels
     * ===================================================== */

    function updatePickerTitles(
        type,
        fromTitleId,
        toTitleId
    ) {
        const fromTitle =
            document.getElementById(
                fromTitleId
            );

        const toTitle =
            document.getElementById(
                toTitleId
            );

        const parentChild =
            type?.value
            === 'parent_child';

        if (fromTitle) {
            fromTitle.textContent =
                parentChild
                    ? 'Elternteil'
                    : 'Charakter A';
        }

        if (toTitle) {
            toTitle.textContent =
                parentChild
                    ? 'Kind'
                    : 'Charakter B';
        }
    }


    addType?.addEventListener(
        'change',
        () => {
            updatePickerTitles(
                addType,
                'addFromTitle',
                'addToTitle'
            );
        }
    );


    editType?.addEventListener(
        'change',
        () => {
            updatePickerTitles(
                editType,
                'editFromTitle',
                'editToTitle'
            );
        }
    );


    /* =====================================================
     * Graph: Adjazenz / Komponenten
     * ===================================================== */

    function buildAdjacency(
        chars,
        relations
    ) {
        const adjacency =
            new Map();

        chars.forEach(
            char => {
                adjacency.set(
                    char.id,
                    new Set()
                );
            }
        );

        relations.forEach(
            relation => {
                adjacency
                    .get(relation.from)
                    ?.add(relation.to);

                adjacency
                    .get(relation.to)
                    ?.add(relation.from);
            }
        );

        return adjacency;
    }


    function connectedComponents(
        chars,
        adjacency
    ) {
        const visited =
            new Set();

        const components =
            [];


        chars.forEach(
            char => {
                if (
                    visited.has(
                        char.id
                    )
                ) {
                    return;
                }

                const component =
                    [];

                const queue = [
                    char.id,
                ];

                visited.add(
                    char.id
                );


                while (
                    queue.length
                ) {
                    const id =
                        queue.shift();

                    component.push(
                        id
                    );

                    adjacency
                        .get(id)
                        ?.forEach(
                            neighbour => {
                                if (
                                    visited.has(
                                        neighbour
                                    )
                                ) {
                                    return;
                                }

                                visited.add(
                                    neighbour
                                );

                                queue.push(
                                    neighbour
                                );
                            }
                        );
                }


                components.push(
                    component
                );
            }
        );


        components.sort(
            (a, b) =>
                b.length
                - a.length
        );


        return components;
    }


    function chooseComponentRoot(
        component,
        adjacency
    ) {
        let best = null;


        component.forEach(
            candidate => {
                const distances =
                    new Map([
                        [
                            candidate,
                            0,
                        ],
                    ]);

                const queue = [
                    candidate,
                ];


                while (
                    queue.length
                ) {
                    const current =
                        queue.shift();

                    const distance =
                        distances.get(
                            current
                        );


                    adjacency
                        .get(current)
                        ?.forEach(
                            neighbour => {
                                if (
                                    distances.has(
                                        neighbour
                                    )
                                ) {
                                    return;
                                }

                                distances.set(
                                    neighbour,
                                    distance + 1
                                );

                                queue.push(
                                    neighbour
                                );
                            }
                        );
                }


                const values =
                    [...distances.values()];

                const eccentricity =
                    values.length
                        ? Math.max(
                            ...values
                        )
                        : 0;

                const distanceSum =
                    values.reduce(
                        (
                            sum,
                            value
                        ) =>
                            sum + value,
                        0
                    );

                const degree =
                    adjacency
                        .get(candidate)
                        ?.size
                    || 0;


                const score = {
                    id:
                        candidate,

                    eccentricity,
                    distanceSum,
                    degree,
                };


                if (
                    !best
                    || score.eccentricity
                        < best.eccentricity
                    || (
                        score.eccentricity
                            === best.eccentricity
                        && score.distanceSum
                            < best.distanceSum
                    )
                    || (
                        score.eccentricity
                            === best.eccentricity
                        && score.distanceSum
                            === best.distanceSum
                        && score.degree
                            > best.degree
                    )
                ) {
                    best =
                        score;
                }
            }
        );


        return best?.id
            ?? component[0];
    }


    function buildBranchTree(
        component,
        adjacency,
        root
    ) {
        const componentSet =
            new Set(
                component
            );

        const parent =
            new Map([
                [
                    root,
                    null,
                ],
            ]);

        const depth =
            new Map([
                [
                    root,
                    0,
                ],
            ]);

        const children =
            new Map(
                component.map(
                    id => [
                        id,
                        [],
                    ]
                )
            );

        const queue = [
            root,
        ];


        while (
            queue.length
        ) {
            const current =
                queue.shift();

            const neighbours =
                [...(
                    adjacency
                        .get(current)
                    || []
                )]
                    .filter(
                        id =>
                            componentSet.has(
                                id
                            )
                    )
                    .sort(
                        (a, b) => {
                            const degreeDiff =
                                (
                                    adjacency
                                        .get(b)
                                        ?.size
                                    || 0
                                )
                                -
                                (
                                    adjacency
                                        .get(a)
                                        ?.size
                                    || 0
                                );

                            if (degreeDiff) {
                                return degreeDiff;
                            }

                            return charName(a)
                                .localeCompare(
                                    charName(b),
                                    'de'
                                );
                        }
                    );


            neighbours.forEach(
                neighbour => {
                    if (
                        parent.has(
                            neighbour
                        )
                    ) {
                        return;
                    }

                    parent.set(
                        neighbour,
                        current
                    );

                    depth.set(
                        neighbour,
                        depth.get(
                            current
                        ) + 1
                    );

                    children
                        .get(current)
                        .push(
                            neighbour
                        );

                    queue.push(
                        neighbour
                    );
                }
            );
        }


        const weights =
            new Map();


        function subtreeWeight(id) {
            const childIds =
                children.get(id)
                || [];

            if (!childIds.length) {
                weights.set(
                    id,
                    1
                );

                return 1;
            }

            const weight =
                childIds.reduce(
                    (
                        sum,
                        childId
                    ) =>
                        sum
                        + subtreeWeight(
                            childId
                        ),
                    0
                );

            weights.set(
                id,
                Math.max(
                    1,
                    weight
                )
            );

            return weights.get(
                id
            );
        }


        subtreeWeight(
            root
        );


        return {
            parent,
            depth,
            children,
            weights,
        };
    }


    /*
     * Bestehende Radial-Geometrie bleibt erhalten.
     * Optimiert wird nur die Reihenfolge von Geschwister-Branches.
     */

    function layoutBranchTreeRadially(
        tree,
        root,
        componentSize
    ) {
        const positions =
            new Map([
                [
                    root,
                    {
                        x: 0,
                        y: 0,
                    },
                ],
            ]);

        const depthStep =
            Math.max(
                205,
                Math.min(
                    270,
                    225
                    + componentSize
                        * 2.4
                )
            );

        function placeChildren(
            parentId,
            startAngle,
            endAngle
        ) {
            const childIds =
                tree.children
                    .get(parentId)
                || [];

            if (!childIds.length) {
                return;
            }

            const totalWeight =
                childIds.reduce(
                    (sum, id) =>
                        sum
                        + (
                            tree.weights
                                .get(id)
                            || 1
                        ),
                    0
                );

            const totalSpan =
                endAngle
                - startAngle;

            const branchGap =
                Math.min(
                    0.14,
                    totalSpan
                        / Math.max(
                            18,
                            childIds.length
                                * 8
                        )
                );

            const usableSpan =
                Math.max(
                    0.1,
                    totalSpan
                    - branchGap
                        * Math.max(
                            0,
                            childIds.length
                                - 1
                        )
                );

            let cursor =
                startAngle;

            childIds.forEach(
                (
                    childId,
                    index
                ) => {
                    const weight =
                        tree.weights
                            .get(childId)
                        || 1;

                    const span =
                        usableSpan
                        * weight
                        / totalWeight;

                    const childStart =
                        cursor;

                    const childEnd =
                        cursor
                        + span;

                    const angle =
                        (
                            childStart
                            + childEnd
                        )
                        / 2;

                    const depth =
                        tree.depth
                            .get(childId)
                        || 1;

                    const radius =
                        depthStep
                        * depth;

                    positions.set(
                        childId,
                        {
                            x:
                                Math.cos(angle)
                                * radius,

                            y:
                                Math.sin(angle)
                                * radius,
                        }
                    );

                    const innerPadding =
                        Math.min(
                            0.08,
                            span * 0.08
                        );

                    placeChildren(
                        childId,
                        childStart
                            + innerPadding,
                        childEnd
                            - innerPadding
                    );

                    cursor =
                        childEnd
                        + (
                            index
                                < childIds.length - 1
                                    ? branchGap
                                    : 0
                        );
                }
            );
        }

        placeChildren(
            root,
            -Math.PI / 2,
            Math.PI * 1.5
        );

        return positions;
    }


    function radialOrientation(
        a,
        b,
        c
    ) {
        const value =
            (
                b.x - a.x
            )
            * (
                c.y - a.y
            )
            -
            (
                b.y - a.y
            )
            * (
                c.x - a.x
            );

        if (
            Math.abs(value)
            < 0.000001
        ) {
            return 0;
        }

        return value > 0
            ? 1
            : -1;
    }


    function radialEdgesCross(
        a1,
        a2,
        b1,
        b2
    ) {
        const o1 =
            radialOrientation(
                a1,
                a2,
                b1
            );

        const o2 =
            radialOrientation(
                a1,
                a2,
                b2
            );

        const o3 =
            radialOrientation(
                b1,
                b2,
                a1
            );

        const o4 =
            radialOrientation(
                b1,
                b2,
                a2
            );

        return (
            o1 !== 0
            && o2 !== 0
            && o3 !== 0
            && o4 !== 0
            && o1 !== o2
            && o3 !== o4
        );
    }


    function radialLayoutScore(
        positions,
        relations
    ) {
        const edges =
            relations
                .map(
                    relation => {
                        const from =
                            positions.get(
                                relation.from
                            );

                        const to =
                            positions.get(
                                relation.to
                            );

                        if (!from || !to) {
                            return null;
                        }

                        return {
                            relation,
                            from,
                            to,
                        };
                    }
                )
                .filter(Boolean);

        let lengthScore = 0;

        edges.forEach(
            edge => {
                const dx =
                    edge.from.x
                    - edge.to.x;

                const dy =
                    edge.from.y
                    - edge.to.y;

                lengthScore +=
                    dx * dx
                    + dy * dy;
            }
        );

        let crossings = 0;

        for (
            let i = 0;
            i < edges.length;
            i++
        ) {
            const a =
                edges[i];

            for (
                let j = i + 1;
                j < edges.length;
                j++
            ) {
                const b =
                    edges[j];

                if (
                    a.relation.from
                        === b.relation.from
                    || a.relation.from
                        === b.relation.to
                    || a.relation.to
                        === b.relation.from
                    || a.relation.to
                        === b.relation.to
                ) {
                    continue;
                }

                if (
                    radialEdgesCross(
                        a.from,
                        a.to,
                        b.from,
                        b.to
                    )
                ) {
                    crossings++;
                }
            }
        }

        return (
            crossings
                * 1000000000
            + lengthScore
        );
    }


    function radialSwapCandidates(
        count
    ) {
        const pairs = [];

        if (count <= 10) {
            for (
                let a = 0;
                a < count - 1;
                a++
            ) {
                for (
                    let b = a + 1;
                    b < count;
                    b++
                ) {
                    pairs.push([
                        a,
                        b,
                    ]);
                }
            }

            return pairs;
        }

        const offsets = [
            1,
            2,
            3,
            Math.floor(count / 2),
        ];

        for (
            let a = 0;
            a < count;
            a++
        ) {
            offsets.forEach(
                offset => {
                    const b =
                        a + offset;

                    if (b < count) {
                        pairs.push([
                            a,
                            b,
                        ]);
                    }
                }
            );
        }

        return pairs;
    }


    function optimizeBranchOrder(
        tree,
        root,
        componentSize,
        relations
    ) {
        let positions =
            layoutBranchTreeRadially(
                tree,
                root,
                componentSize
            );

        if (
            componentSize < 4
            || relations.length < 3
        ) {
            return positions;
        }

        let bestScore =
            radialLayoutScore(
                positions,
                relations
            );

        const parentIds =
            [...tree.children.keys()]
                .sort(
                    (a, b) =>
                        (
                            tree.depth.get(a)
                            || 0
                        )
                        -
                        (
                            tree.depth.get(b)
                            || 0
                        )
                );

        for (
            let pass = 0;
            pass < 3;
            pass++
        ) {
            let improved = false;

            for (
                const parentId
                of parentIds
            ) {
                const childIds =
                    tree.children
                        .get(parentId)
                    || [];

                if (
                    childIds.length < 2
                ) {
                    continue;
                }

                const pairs =
                    radialSwapCandidates(
                        childIds.length
                    );

                let localBestScore =
                    bestScore;

                let localBestPair =
                    null;

                let localBestPositions =
                    null;

                for (
                    const [
                        a,
                        b,
                    ]
                    of pairs
                ) {
                    [
                        childIds[a],
                        childIds[b],
                    ] = [
                        childIds[b],
                        childIds[a],
                    ];

                    const candidate =
                        layoutBranchTreeRadially(
                            tree,
                            root,
                            componentSize
                        );

                    const candidateScore =
                        radialLayoutScore(
                            candidate,
                            relations
                        );

                    [
                        childIds[a],
                        childIds[b],
                    ] = [
                        childIds[b],
                        childIds[a],
                    ];

                    if (
                        candidateScore
                        < localBestScore
                            - 0.001
                    ) {
                        localBestScore =
                            candidateScore;

                        localBestPair = [
                            a,
                            b,
                        ];

                        localBestPositions =
                            candidate;
                    }
                }

                if (localBestPair) {
                    const [
                        a,
                        b,
                    ] = localBestPair;

                    [
                        childIds[a],
                        childIds[b],
                    ] = [
                        childIds[b],
                        childIds[a],
                    ];

                    positions =
                        localBestPositions;

                    bestScore =
                        localBestScore;

                    improved =
                        true;
                }
            }

            if (!improved) {
                break;
            }
        }

        return positions;
    }


    function layoutComponentRadially(
        component,
        adjacency,
        componentRelations,
        forcedRoot = null
    ) {
        if (
            component.length
            === 1
        ) {
            return new Map([
                [
                    component[0],
                    {
                        x: 0,
                        y: 0,
                    },
                ],
            ]);
        }

        const root =
            forcedRoot !== null
            && component.includes(
                forcedRoot
            )
                ? forcedRoot
                : chooseComponentRoot(
                    component,
                    adjacency
                );

        const tree =
            buildBranchTree(
                component,
                adjacency,
                root
            );

        return optimizeBranchOrder(
            tree,
            root,
            component.length,
            componentRelations
        );
    }


    function layoutGraphByBranches() {
        const chars =
            visibleChars();

        const relations =
            visibleRelations();

        const adjacency =
            buildAdjacency(
                chars,
                relations
            );

        const components =
            connectedComponents(
                chars,
                adjacency
            );


        const layouts =
            components.map(
                component => {
                    const forcedRoot =
                        centeredCharId !== null
                        && component.includes(
                            centeredCharId
                        )
                            ? centeredCharId
                            : null;

                    const componentSet =
                        new Set(
                            component
                        );

                    const componentRelations =
                        relations.filter(
                            relation =>
                                componentSet.has(
                                    relation.from
                                )
                                && componentSet.has(
                                    relation.to
                                )
                        );

                    const positions =
                        layoutComponentRadially(
                            component,
                            adjacency,
                            componentRelations,
                            forcedRoot
                        );

                    let minX =
                        Infinity;

                    let minY =
                        Infinity;

                    let maxX =
                        -Infinity;

                    let maxY =
                        -Infinity;


                    positions.forEach(
                        position => {
                            minX =
                                Math.min(
                                    minX,
                                    position.x
                                );

                            minY =
                                Math.min(
                                    minY,
                                    position.y
                                );

                            maxX =
                                Math.max(
                                    maxX,
                                    position.x
                                );

                            maxY =
                                Math.max(
                                    maxY,
                                    position.y
                                );
                        }
                    );


                    return {
                        positions,
                        minX,
                        minY,

                        width:
                            Math.max(
                                220,
                                maxX
                                    - minX
                            ),

                        height:
                            Math.max(
                                220,
                                maxY
                                    - minY
                            ),
                    };
                }
            );


        const estimatedArea =
            layouts.reduce(
                (
                    sum,
                    layout
                ) =>
                    sum
                    + (
                        layout.width
                        + 280
                    )
                    * (
                        layout.height
                        + 240
                    ),
                0
            );


        const targetRowWidth =
            Math.max(
                1150,
                Math.min(
                    3100,
                    Math.sqrt(
                        estimatedArea
                    )
                    * 1.35
                )
            );


        let x = 420;
        let y = 380;
        let rowHeight = 0;


        layouts.forEach(
            layout => {
                const boxWidth =
                    layout.width
                    + 300;

                const boxHeight =
                    layout.height
                    + 250;


                if (
                    x > 420
                    && x + boxWidth
                        > 420
                            + targetRowWidth
                ) {
                    x = 420;

                    y +=
                        rowHeight
                        + 220;

                    rowHeight = 0;
                }


                const offsetX =
                    x
                    + 150
                    - layout.minX;

                const offsetY =
                    y
                    + 125
                    - layout.minY;


                layout.positions.forEach(
                    (
                        position,
                        id
                    ) => {
                        const node =
                            nodeMap.get(
                                id
                            );

                        if (!node) {
                            return;
                        }

                        node.x =
                            position.x
                            + offsetX;

                        node.y =
                            position.y
                            + offsetY;
                    }
                );


                x +=
                    boxWidth;

                rowHeight =
                    Math.max(
                        rowHeight,
                        boxHeight
                    );
            }
        );
    }


    /* =====================================================
     * Nodes
     * ===================================================== */

    function createNodes() {
        nodesLayer.innerHTML =
            '';

        nodeMap.clear();


        CHARS.forEach(
            char => {
                const el =
                    document.createElement(
                        'div'
                    );

                el.className =
                    'relation-node';

                el.dataset.id =
                    String(
                        char.id
                    );


                const face =
                    document.createElement(
                        'div'
                    );

                face.className =
                    'relation-node-face';


                if (char.thumb) {
                    const img =
                        document.createElement(
                            'img'
                        );

                    img.src =
                        char.thumb;

                    img.alt = '';

                    img.loading =
                        'lazy';

                    img.decoding =
                        'async';

                    img.dataset.thumbSrc =
                        char.thumb;

                    img.dataset.fullSrc =
                        char.image
                        || char.thumb;

                    face.appendChild(
                        img
                    );
                } else {
                    const initial =
                        document.createElement(
                            'div'
                        );

                    initial.className =
                        'relation-node-initial';

                    initial.textContent =
                        initials(
                            char.name
                        );

                    face.appendChild(
                        initial
                    );
                }


                const label =
                    document.createElement(
                        'div'
                    );

                label.className =
                    'relation-node-name';

                label.textContent =
                    char.name;

                label.title =
                    charMeta(char)
                        ? (
                            char.name
                            + ' · '
                            + charMeta(char)
                        )
                        : char.name;


                el.appendChild(
                    face
                );

                el.appendChild(
                    label
                );

                nodesLayer.appendChild(
                    el
                );


                const node = {
                    char,
                    el,
                    x: 0,
                    y: 0,
                };


                nodeMap.set(
                    char.id,
                    node
                );

                attachNodeDrag(
                    node
                );
            }
        );


        layoutGraphByBranches();

        renderNodePositions();
    }


    function renderNodePositions() {
        const visibleIds =
            visibleCharIds();

        nodeMap.forEach(
            node => {
                const visible =
                    visibleIds.has(
                        node.char.id
                    );

                node.el.hidden =
                    !visible;

                if (!visible) {
                    return;
                }

                node.el.style.left =
                    node.x
                    + 'px';

                node.el.style.top =
                    node.y
                    + 'px';
            }
        );
    }


    function attachNodeDrag(node) {
        node.el.addEventListener(
            'pointerdown',
            event => {
                event.stopPropagation();


                const startClientX =
                    event.clientX;

                const startClientY =
                    event.clientY;

                const startX =
                    node.x;

                const startY =
                    node.y;

                let moved =
                    false;


                node.el.classList.add(
                    'dragging'
                );


                try {
                    node.el.setPointerCapture(
                        event.pointerId
                    );
                } catch (_) {}


                function move(
                    moveEvent
                ) {
                    const deltaX =
                        moveEvent.clientX
                        - startClientX;

                    const deltaY =
                        moveEvent.clientY
                        - startClientY;


                    if (
                        Math.hypot(
                            deltaX,
                            deltaY
                        ) > 5
                    ) {
                        moved =
                            true;
                    }


                    node.x =
                        startX
                        + deltaX
                        / scale;

                    node.y =
                        startY
                        + deltaY
                        / scale;


                    node.el.style.left =
                        node.x
                        + 'px';

                    node.el.style.top =
                        node.y
                        + 'px';

                    updateEdges();
                }


                function end() {
                    node.el.classList.remove(
                        'dragging'
                    );

                    node.el.removeEventListener(
                        'pointermove',
                        move
                    );

                    node.el.removeEventListener(
                        'pointerup',
                        end
                    );

                    node.el.removeEventListener(
                        'pointercancel',
                        end
                    );


                    if (!moved) {
                        enterCenteredMode(
                            node.char.id
                        );
                    }
                }


                node.el.addEventListener(
                    'pointermove',
                    move
                );

                node.el.addEventListener(
                    'pointerup',
                    end
                );

                node.el.addEventListener(
                    'pointercancel',
                    end
                );
            }
        );
    }


    /* =====================================================
     * Edges
     * ===================================================== */

    function svgElement(name) {
        return document.createElementNS(
            'http://www.w3.org/2000/svg',
            name
        );
    }


    function createEdges() {
        edgesLayer.innerHTML =
            '';

        edgeMap.clear();


        RELATIONS.forEach(
            relation => {
                const group =
                    svgElement(
                        'g'
                    );

                group.dataset.id =
                    String(
                        relation.id
                    );

                group.dataset.type =
                    relation.type;


                const hit =
                    svgElement(
                        'line'
                    );

                hit.classList.add(
                    'relation-edge-hit'
                );


                const line =
                    svgElement(
                        'line'
                    );

                line.classList.add(
                    'relation-edge',
                    relation.type
                );

                line.setAttribute(
                    'stroke',
                    relation.color
                );


                hit.addEventListener(
                    'click',
                    event => {
                        event.stopPropagation();

                        openEditModal(
                            relation.id
                        );
                    }
                );


                group.appendChild(
                    hit
                );

                group.appendChild(
                    line
                );

                edgesLayer.appendChild(
                    group
                );


                edgeMap.set(
                    relation.id,
                    {
                        relation,
                        group,
                        hit,
                        line,
                    }
                );
            }
        );


        updateEdges();
    }


    function updateEdges() {
        edgeMap.forEach(
            edge => {
                const from =
                    nodeMap.get(
                        edge.relation.from
                    );

                const to =
                    nodeMap.get(
                        edge.relation.to
                    );


                if (!from || !to) {
                    edge.group.style.display =
                        'none';

                    return;
                }


                const attrs = {
                    x1:
                        from.x,

                    y1:
                        from.y,

                    x2:
                        to.x,

                    y2:
                        to.y,
                };


                Object.entries(
                    attrs
                ).forEach(
                    ([
                        key,
                        value,
                    ]) => {
                        edge.hit.setAttribute(
                            key,
                            String(value)
                        );

                        edge.line.setAttribute(
                            key,
                            String(value)
                        );
                    }
                );
            }
        );
    }


    /* =====================================================
     * Filter
     * ===================================================== */

    function applyFiltersAndRelayout() {
        const visibleIds =
            visibleCharIds();

        const activeTypes =
            activeRelationTypes();


        /*
         * Falls Region/Filter den zentrierten Charakter
         * ausblendet, Center-Modus sauber verlassen.
         */
        if (
            centeredCharId !== null
            && !visibleIds.has(
                centeredCharId
            )
        ) {
            centeredCharId =
                null;

            clearCenteredNodeState();
        }


        edgeMap.forEach(
            edge => {
                const relation =
                    edge.relation;

                const visible =
                    visibleIds.has(
                        relation.from
                    )
                    && visibleIds.has(
                        relation.to
                    )
                    && activeTypes.has(
                        relation.type
                    );

                edge.group.style.display =
                    visible
                        ? ''
                        : 'none';
            }
        );


        /*
         * Wichtig:
         * Die bestehende Branch-Radial-Anordnung bleibt
         * unverändert. Sie bekommt lediglich den aktuell
         * gefilterten Graphen als Grundlage.
         */
        layoutGraphByBranches();

        renderNodePositions();
        updateEdges();
        markCenteredNode();


        requestAnimationFrame(
            () => {
                if (
                    centeredCharId !== null
                ) {
                    centerViewportOnChar(
                        centeredCharId
                    );
                } else {
                    fitGraph();
                }
            }
        );
    }


    document
        .querySelectorAll(
            '.relation-filter'
        )
        .forEach(
            checkbox => {
                checkbox.addEventListener(
                    'change',
                    applyFiltersAndRelayout
                );
            }
        );


    function applyRegionBackground(
        button
    ) {
        if (!relationsShell) {
            return;
        }

        const image =
            button?.dataset
                ?.regionImage
            || '';


        if (image) {
            relationsShell.style.setProperty(
                '--relations-region-background',
                `url("${image}")`
            );

            relationsShell.classList.add(
                'has-region-background'
            );

        } else {
            relationsShell.style.removeProperty(
                '--relations-region-background'
            );

            relationsShell.classList.remove(
                'has-region-background'
            );
        }
    }


    function selectRegionTab(button) {
        regionTabs.forEach(
            tab => {
                tab.classList.toggle(
                    'active',
                    tab === button
                );
            }
        );


        const rawId =
            button?.dataset
                ?.regionId
            ?? '';

        selectedRegionId =
            rawId === ''
                ? null
                : Number(rawId);


        applyRegionBackground(
            button
        );

        /*
         * Offene Modals sofort mit dem neuen Regionsfilter
         * synchronisieren.
         */
        if (
            addModal
            && !addModal.hidden
        ) {
            addFromPicker.reset();
            addToPicker.reset();
        }

        if (
            editModal
            && !editModal.hidden
        ) {
            clearEditSelection();

            renderRelationResults(
                relationSearch?.value
                || ''
            );
        }

        applyFiltersAndRelayout();
    }


    regionTabs.forEach(
        button => {
            button.addEventListener(
                'click',
                () => {
                    selectRegionTab(
                        button
                    );
                }
            );
        }
    );


    /* =====================================================
     * Charakter zentrieren
     * ===================================================== */

    function clearCenteredNodeState() {
        nodeMap.forEach(
            node => {
                node.el.classList.remove(
                    'is-centered',
                    'is-center-neighbor'
                );
            }
        );

        edgeMap.forEach(
            edge => {
                edge.group.classList.remove(
                    'is-center-connected'
                );
            }
        );
    }


    function updateCenteredModeUi() {
        const active =
            centeredCharId !== null;

        relationsShell?.classList.toggle(
            'is-center-mode',
            active
        );

        if (centerIndicator) {
            centerIndicator.hidden =
                !active;
        }

        if (centeredCharName) {
            centeredCharName.textContent =
                active
                    ? charName(
                        centeredCharId
                    )
                    : '';
        }
    }

    function updateCenteredNodeImages() {
        nodeMap.forEach(
            node => {
                const img =
                    node.el.querySelector(
                        '.relation-node-face img'
                    );

                if (!img) {
                    return;
                }

                const isCentered =
                    centeredCharId !== null
                    && node.char.id
                        === centeredCharId;

                const targetSrc =
                    isCentered
                        ? (
                            img.dataset.fullSrc
                            || img.dataset.thumbSrc
                        )
                        : img.dataset.thumbSrc;

                if (
                    targetSrc
                    && img.getAttribute('src')
                        !== targetSrc
                ) {
                    img.src =
                        targetSrc;
                }
            }
        );
    }


    function markCenteredNode() {
        clearCenteredNodeState();

        if (
            centeredCharId === null
        ) {
            updateCenteredNodeImages();
            updateCenteredModeUi();
            return;
        }

        nodeMap
            .get(centeredCharId)
            ?.el
            .classList.add(
                'is-centered'
            );


        edgeMap.forEach(
            edge => {
                const relation =
                    edge.relation;

                if (
                    relation.from
                        !== centeredCharId
                    && relation.to
                        !== centeredCharId
                ) {
                    return;
                }


                edge.group.classList.add(
                    'is-center-connected'
                );


                const neighbourId =
                    relation.from
                        === centeredCharId
                            ? relation.to
                            : relation.from;


                nodeMap
                    .get(neighbourId)
                    ?.el
                    .classList.add(
                        'is-center-neighbor'
                    );
            }
        );


        updateCenteredNodeImages();
        updateCenteredModeUi();
    }


    function centerViewportOnChar(
        charId
    ) {
        const node =
            nodeMap.get(
                charId
            );

        if (!node) {
            return;
        }

        const rect =
            viewport.getBoundingClientRect();


        /*
         * Center-Modus darf näher heranzoomen als der
         * normale Komplett-Fit, aber vorhandenen Zoom nicht
         * unnötig überschreiben.
         */
        scale =
            Math.max(
                0.72,
                Math.min(
                    1.25,
                    scale
                )
            );


        translateX =
            rect.width / 2
            - node.x * scale;

        translateY =
            rect.height / 2
            - node.y * scale;


        applyTransform();
    }


    function enterCenteredMode(
        charId
    ) {
        const visibleIds =
            visibleCharIds();

        if (
            !visibleIds.has(
                charId
            )
        ) {
            return;
        }

        centeredCharId =
            charId;

        /*
         * Exakt dieselbe vorhandene Branch-Anordnung,
         * lediglich mit dem geklickten Charakter als Root.
         */
        layoutGraphByBranches();

        renderNodePositions();
        updateEdges();
        markCenteredNode();


        requestAnimationFrame(
            () => {
                centerViewportOnChar(
                    charId
                );
            }
        );
    }


    function exitCenteredMode() {
        if (
            centeredCharId === null
        ) {
            return;
        }

        centeredCharId =
            null;

        /*
         * Normale automatische Root-Auswahl wiederherstellen.
         */
        layoutGraphByBranches();

        renderNodePositions();
        updateEdges();
        markCenteredNode();


        requestAnimationFrame(
            fitGraph
        );
    }


    centerIndicatorClose?.addEventListener(
        'click',
        event => {
            event.stopPropagation();
            exitCenteredMode();
        }
    );


    /* =====================================================
     * Fit / Center
     * ===================================================== */

    function graphBounds() {
        if (!nodeMap.size) {
            return null;
        }

        let minX =
            Infinity;

        let minY =
            Infinity;

        let maxX =
            -Infinity;

        let maxY =
            -Infinity;


        const visibleIds =
            visibleCharIds();

        nodeMap.forEach(
            node => {
                if (
                    !visibleIds.has(
                        node.char.id
                    )
                ) {
                    return;
                }

                minX =
                    Math.min(
                        minX,
                        node.x - 70
                    );

                minY =
                    Math.min(
                        minY,
                        node.y - 70
                    );

                maxX =
                    Math.max(
                        maxX,
                        node.x + 70
                    );

                maxY =
                    Math.max(
                        maxY,
                        node.y + 90
                    );
            }
        );


        if (
            !Number.isFinite(minX)
            || !Number.isFinite(minY)
            || !Number.isFinite(maxX)
            || !Number.isFinite(maxY)
        ) {
            return null;
        }

        return {
            minX,
            minY,
            maxX,
            maxY,

            width:
                maxX
                - minX,

            height:
                maxY
                - minY,
        };
    }


    function fitGraph() {
        const bounds =
            graphBounds();

        if (!bounds) {
            scale = 1;
            translateX = 0;
            translateY = 0;

            applyTransform();

            return;
        }


        const rect =
            viewport
                .getBoundingClientRect();

        const padding = 95;


        const availableWidth =
            Math.max(
                100,
                rect.width
                    - padding * 2
            );

        const availableHeight =
            Math.max(
                100,
                rect.height
                    - padding * 2
            );


        scale =
            Math.max(
                0.18,
                Math.min(
                    1.35,

                    availableWidth
                        / Math.max(
                            1,
                            bounds.width
                        ),

                    availableHeight
                        / Math.max(
                            1,
                            bounds.height
                        )
                )
            );


        const centerX =
            (
                bounds.minX
                + bounds.maxX
            )
            / 2;

        const centerY =
            (
                bounds.minY
                + bounds.maxY
            )
            / 2;


        translateX =
            rect.width / 2
            - centerX
                * scale;

        translateY =
            rect.height / 2
            - centerY
                * scale;


        applyTransform();
    }


    resetViewButton?.addEventListener(
        'click',
        fitGraph
    );


    /* =====================================================
     * Pan
     * ===================================================== */

    viewport.addEventListener(
        'pointerdown',
        event => {
            if (
                event.target.closest(
                    '.relation-node'
                )
                || event.target.closest(
                    '.relations-legend'
                )
                || event.target.closest(
                    '.relations-graph-actions'
                )
                || event.target.closest(
                    '.relations-center-indicator'
                )
            ) {
                return;
            }


            panState = {
                pointerId:
                    event.pointerId,

                startX:
                    event.clientX,

                startY:
                    event.clientY,

                translateX,
                translateY,

                moved:
                    false,
            };


            viewport.classList.add(
                'panning'
            );


            try {
                viewport.setPointerCapture(
                    event.pointerId
                );
            } catch (_) {}
        }
    );


    viewport.addEventListener(
        'pointermove',
        event => {
            if (
                !panState
                || panState.pointerId
                    !== event.pointerId
            ) {
                return;
            }


            const deltaX =
                event.clientX
                - panState.startX;

            const deltaY =
                event.clientY
                - panState.startY;


            if (
                Math.hypot(
                    deltaX,
                    deltaY
                ) > 5
            ) {
                panState.moved =
                    true;
            }


            translateX =
                panState.translateX
                + deltaX;

            translateY =
                panState.translateY
                + deltaY;


            applyTransform();
        }
    );


    function stopPan(
        event
    ) {
        const wasClick =
            panState
            && !panState.moved
            && event?.type
                === 'pointerup';

        panState =
            null;

        viewport.classList.remove(
            'panning'
        );


        if (
            wasClick
            && centeredCharId !== null
        ) {
            exitCenteredMode();
        }
    }


    viewport.addEventListener(
        'pointerup',
        stopPan
    );

    viewport.addEventListener(
        'pointercancel',
        stopPan
    );


    /* =====================================================
     * Zoom
     * ===================================================== */

    viewport.addEventListener(
        'wheel',
        event => {
            event.preventDefault();


            const rect =
                viewport
                    .getBoundingClientRect();

            const mouseX =
                event.clientX
                - rect.left;

            const mouseY =
                event.clientY
                - rect.top;


            const worldX =
                (
                    mouseX
                    - translateX
                )
                / scale;

            const worldY =
                (
                    mouseY
                    - translateY
                )
                / scale;


            const nextScale =
                Math.max(
                    0.15,
                    Math.min(
                        3.2,
                        scale
                        * (
                            event.deltaY
                                < 0
                                ? 1.12
                                : 0.89
                        )
                    )
                );


            translateX =
                mouseX
                - worldX
                    * nextScale;

            translateY =
                mouseY
                - worldY
                    * nextScale;

            scale =
                nextScale;


            applyTransform();
        },
        {
            passive: false,
        }
    );


    /* =====================================================
     * Add Modal
     * ===================================================== */

    function openAddModal() {
        addFromPicker.reset();
        addToPicker.reset();

        addFromPicker.renderResults(
            ''
        );

        addToPicker.renderResults(
            ''
        );

        if (addType) {
            addType.value =
                'friend';
        }

        updatePickerTitles(
            addType,
            'addFromTitle',
            'addToTitle'
        );

        addModal.hidden =
            false;

        document.body.classList.add(
            'relations-modal-open'
        );

        window.setTimeout(
            () => {
                document
                    .getElementById(
                        'addFromSearch'
                    )
                    ?.focus();
            },
            0
        );
    }


    function closeAddModal() {
        addModal.hidden =
            true;

        document.body.classList.remove(
            'relations-modal-open'
        );
    }


    newRelationButton?.addEventListener(
        'click',
        openAddModal
    );


    addModal
        ?.querySelectorAll(
            '[data-close-add-modal]'
        )
        .forEach(
            element => {
                element.addEventListener(
                    'click',
                    closeAddModal
                );
            }
        );


    addForm?.addEventListener(
        'submit',
        async event => {
            event.preventDefault();

            const data =
                new FormData(
                    addForm
                );

            setStatus(
                'Speichere…'
            );


            try {
                const payload =
                    await postRelation(
                        data
                    );

                const relation =
                    relationFromForm(
                        payload.id,
                        data
                    );

                replaceRelationInMemory(
                    relation
                );

                rebuildGraphAfterRelationChange();

                /*
                 * Serienanlage:
                 * Charakter A und Beziehungstyp bleiben.
                 * Nur Charakter B wird geleert.
                 */
                addToPicker.reset();

                updatePickerTitles(
                    addType,
                    'addFromTitle',
                    'addToTitle'
                );

                addToPicker.renderResults(
                    ''
                );

                setStatus(
                    'Beziehung gespeichert'
                );

                document
                    .getElementById(
                        'addToSearch'
                    )
                    ?.focus();

            } catch (error) {
                setStatus(
                    error.message
                    || 'Fehler',
                    true
                );
            }
        }
    );


    /* =====================================================
     * Edit Modal
     * ===================================================== */

    function relationSearchText(
        relation
    ) {
        const from =
            charMap.get(
                relation.from
            );

        const to =
            charMap.get(
                relation.to
            );

        return normalize(
            [
                relation.label,

                from?.name,
                from?.full,
                from?.species,
                from?.occupation,
                from?.faction,
                from?.region,

                to?.name,
                to?.full,
                to?.species,
                to?.occupation,
                to?.faction,
                to?.region,
            ].join(' ')
        );
    }


    function renderRelationResults(
        query
    ) {
        if (!relationResults) {
            return;
        }

        const needle =
            normalize(
                query
            );

        const regionCharIds =
            visibleCharIds();

        const filtered =
            RELATIONS.filter(
                relation => {
                    const inSelectedRegion =
                        regionCharIds.has(
                            relation.from
                        )
                        && regionCharIds.has(
                            relation.to
                        );

                    if (!inSelectedRegion) {
                        return false;
                    }

                    return (
                        !needle
                        || relationSearchText(
                            relation
                        ).includes(
                            needle
                        )
                    );
                }
            );


        relationResults.innerHTML =
            '';


        if (!filtered.length) {
            const empty =
                document.createElement(
                    'div'
                );

            empty.className =
                'relations-manager-empty';

            empty.textContent =
                'Keine Beziehungen gefunden.';

            relationResults.appendChild(
                empty
            );

            return;
        }


        filtered.forEach(
            relation => {
                const button =
                    document.createElement(
                        'button'
                    );

                button.type =
                    'button';

                button.className =
                    'relations-manager-result';


                if (
                    relation.id
                    === selectedRelationId
                ) {
                    button.classList.add(
                        'active'
                    );
                }


                const color =
                    document.createElement(
                        'span'
                    );

                color.className =
                    'relations-manager-color';

                color.style.background =
                    relation.color;


                const text =
                    document.createElement(
                        'span'
                    );

                text.textContent =
                    relationText(
                        relation
                    );


                button.appendChild(
                    color
                );

                button.appendChild(
                    text
                );


                button.addEventListener(
                    'click',
                    () => {
                        selectRelationForEdit(
                            relation.id
                        );
                    }
                );


                relationResults.appendChild(
                    button
                );
            }
        );
    }


    function clearEditSelection() {
        selectedRelationId =
            null;

        if (editRelationId) {
            editRelationId.value =
                '';
        }

        if (editPlaceholder) {
            editPlaceholder.hidden =
                false;
        }

        if (editFields) {
            editFields.hidden =
                true;
        }

        editFromPicker.reset();
        editToPicker.reset();

        renderRelationResults(
            relationSearch?.value
            || ''
        );
    }


    function selectRelationForEdit(id) {
        const relation =
            relationMap.get(
                id
            );

        if (!relation) {
            clearEditSelection();
            return;
        }

        const regionCharIds =
            visibleCharIds();

        if (
            !regionCharIds.has(
                relation.from
            )
            || !regionCharIds.has(
                relation.to
            )
        ) {
            clearEditSelection();

            setStatus(
                'Beziehung liegt außerhalb der gewählten Region.',
                true
            );

            return;
        }


        selectedRelationId =
            id;

        editRelationId.value =
            String(id);

        editFromPicker.setValue(
            relation.from
        );

        editToPicker.setValue(
            relation.to
        );

        editType.value =
            relation.type;


        editPlaceholder.hidden =
            true;

        editFields.hidden =
            false;


        updatePickerTitles(
            editType,
            'editFromTitle',
            'editToTitle'
        );


        renderRelationResults(
            relationSearch?.value
            || ''
        );
    }


    function openEditModal(
        relationId = null
    ) {
        editModal.hidden =
            false;

        document.body.classList.add(
            'relations-modal-open'
        );


        if (
            relationSearch
            && !relationId
        ) {
            relationSearch.value =
                '';
        }


        if (relationId) {
            selectRelationForEdit(
                relationId
            );
        } else {
            clearEditSelection();
        }


        renderRelationResults(
            relationSearch?.value
            || ''
        );


        window.setTimeout(
            () => {
                relationSearch?.focus();
            },
            0
        );
    }


    function closeEditModal() {
        editModal.hidden =
            true;

        document.body.classList.remove(
            'relations-modal-open'
        );
    }


    manageRelationsButton?.addEventListener(
        'click',
        () => {
            openEditModal();
        }
    );


    editModal
        ?.querySelectorAll(
            '[data-close-edit-modal]'
        )
        .forEach(
            element => {
                element.addEventListener(
                    'click',
                    closeEditModal
                );
            }
        );


    relationSearch?.addEventListener(
        'input',
        () => {
            renderRelationResults(
                relationSearch.value
            );
        }
    );


    editForm?.addEventListener(
        'submit',
        async event => {
            event.preventDefault();


            if (!selectedRelationId) {
                return;
            }


            const data =
                new FormData(
                    editForm
                );

            setStatus(
                'Speichere…'
            );


            try {
                const payload =
                    await postRelation(
                        data
                    );

                const relation =
                    relationFromForm(
                        payload.id
                        || selectedRelationId,
                        data
                    );

                replaceRelationInMemory(
                    relation
                );

                rebuildGraphAfterRelationChange();

                /*
                 * Modal bleibt offen und dieselbe Relation
                 * bleibt ausgewählt.
                 */
                selectedRelationId =
                    relation.id;

                selectRelationForEdit(
                    relation.id
                );

                renderRelationResults(
                    relationSearch?.value
                    || ''
                );

                setStatus(
                    'Änderungen gespeichert'
                );

            } catch (error) {
                setStatus(
                    error.message
                    || 'Fehler',
                    true
                );
            }
        }
    );


    deleteRelationButton?.addEventListener(
        'click',
        async () => {
            if (!selectedRelationId) {
                return;
            }


            if (
                !confirm(
                    'Beziehung wirklich löschen?'
                )
            ) {
                return;
            }


            const data =
                new FormData();

            data.set(
                'csrf',
                CSRF
            );

            data.set(
                'action',
                'delete'
            );

            data.set(
                'id',
                String(
                    selectedRelationId
                )
            );


            setStatus(
                'Lösche…'
            );


            try {
                const deletedId =
                    selectedRelationId;

                await postRelation(
                    data
                );

                const index =
                    RELATIONS.findIndex(
                        relation =>
                            relation.id
                            === deletedId
                    );

                if (index >= 0) {
                    RELATIONS.splice(
                        index,
                        1
                    );
                }

                relationMap.delete(
                    deletedId
                );

                /*
                 * Kein Reload:
                 * Region, Modal und Suchtext bleiben bestehen.
                 */
                clearEditSelection();

                rebuildGraphAfterRelationChange();

                renderRelationResults(
                    relationSearch?.value
                    || ''
                );

                setStatus(
                    'Beziehung gelöscht'
                );

            } catch (error) {
                setStatus(
                    error.message
                    || 'Fehler',
                    true
                );
            }
        }
    );


    /* =====================================================
     * Escape
     * ===================================================== */

    document.addEventListener(
        'keydown',
        event => {
            if (event.key !== 'Escape') {
                return;
            }

            if (!addModal.hidden) {
                closeAddModal();
                return;
            }

            if (!editModal.hidden) {
                closeEditModal();
            }
        }
    );


    /* =====================================================
     * Initialisieren
     * ===================================================== */

    document.body.classList.add(
        'relations-page-active'
    );

    createNodes();
    createEdges();

    const initialRegionTab =
        regionTabs.find(
            button =>
                button.classList.contains(
                    'active'
                )
        )
        || regionTabs[0]
        || null;

    applyRegionBackground(
        initialRegionTab
    );

    applyFiltersAndRelayout();

    requestAnimationFrame(
        () => {
            fitGraph();

            requestAnimationFrame(
                fitGraph
            );
        }
    );

})();
</script>

</body>
</html>
