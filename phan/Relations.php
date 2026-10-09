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

        'cousins' => [
            'label' => 'Cousins',
            'color' => '#9b6bd3',
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


<div class="relations-page relations-page--graph" id="relationsPage">

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


        <div class="relations-graph-actions" id="relationsGraphActions">

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
                id="relationsLegendToggle"
                class="relations-phone-only"
                aria-expanded="false"
                aria-controls="relationsLegend"
                hidden
            >
                Filter
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
                >
                    <g id="relationsEdges"></g>
                </svg>


                <div id="relationsNodes"></div>

            </div>

        </div>


        <div class="relations-legend" id="relationsLegend">

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
                        <?= $key !== 'cousins' ? 'checked' : '' ?>
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

    const PHONE_UI = !!(
        window.matchMedia
        && window.matchMedia('(max-width: 650px)').matches
    );

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


    const PHONE_WORLD_FACTOR =
        PHONE_UI
            ? 0.16
            : 1;

    const WORLD_WIDTH =
        4000
        * PHONE_WORLD_FACTOR;

    const WORLD_HEIGHT =
        3000
        * PHONE_WORLD_FACTOR;


    function displayCoord(
        value
    ) {
        return value
            * PHONE_WORLD_FACTOR;
    }


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

    const relationsSvg =
        document.getElementById(
            'relationsSvg'
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

    const relationsLegend =
        document.getElementById(
            'relationsLegend'
        );

    const relationsLegendToggle =
        document.getElementById(
            'relationsLegendToggle'
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
        || !relationsSvg
    ) {
        return;
    }


    if (PHONE_UI) {
        world.style.width =
            WORLD_WIDTH
            + 'px';

        world.style.height =
            WORLD_HEIGHT
            + 'px';

        relationsSvg.style.width =
            WORLD_WIDTH
            + 'px';

        relationsSvg.style.height =
            WORLD_HEIGHT
            + 'px';

        relationsSvg.setAttribute(
            'viewBox',
            `0 0 ${WORLD_WIDTH} ${WORLD_HEIGHT}`
        );
    }


    const nodeMap =
        new Map();

    const edgeMap =
        new Map();

    /*
     * Eigener Positions-Cache nur für die globale "Alle"-Ansicht.
     * Regionsansichten dürfen ihre Radialpositionen ändern, ohne
     * dadurch die globale Force-Anordnung zu zerstören.
     */
    const globalLayoutCache =
        new Map();

    let scale = 1;
    let translateX = 0;
    let translateY = 0;
    let panState = null;

    const phonePointers =
        new Map();

    let phonePinchState =
        null;

    let phoneGestureMoved =
        false;

    let phoneTapNode =
        null;

    let selectedRelationId = null;
    let selectedRegionId = null;
    let centeredCharId = null;
    let statusTimer = null;


    if (
        PHONE_UI
        && relationsLegendToggle
    ) {
        relationsLegendToggle.hidden =
            false;
    }


    /* =====================================================
     * Phone-UI
     * ===================================================== */

    function setPhoneLegendOpen(
        open
    ) {
        if (
            !PHONE_UI
            || !relationsLegend
            || !relationsLegendToggle
        ) {
            return;
        }

        const isOpen =
            !!open;

        relationsLegend.classList.toggle(
            'is-open',
            isOpen
        );

        relationsLegendToggle.classList.toggle(
            'active',
            isOpen
        );

        relationsLegendToggle.setAttribute(
            'aria-expanded',
            isOpen
                ? 'true'
                : 'false'
        );
    }


    relationsLegendToggle?.addEventListener(
        'click',
        event => {
            event.stopPropagation();

            setPhoneLegendOpen(
                !relationsLegend
                    ?.classList
                    .contains(
                        'is-open'
                    )
            );
        }
    );


    document.addEventListener(
        'pointerdown',
        event => {
            if (
                !PHONE_UI
                || !relationsLegend
                    ?.classList
                    .contains(
                        'is-open'
                    )
            ) {
                return;
            }

            if (
                relationsLegend.contains(
                    event.target
                )
                || relationsLegendToggle
                    ?.contains(
                        event.target
                    )
            ) {
                return;
            }

            setPhoneLegendOpen(false);
        }
    );


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
        if (PHONE_UI) {
            world.style.transform =
                `matrix(${scale}, 0, 0, ${scale}, ${translateX}, ${translateY})`;

            return;
        }

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


    /*
     * Bestrafung, wenn eine Beziehungslinie durch einen dritten
     * Charakter verläuft. Das beeinflusst bereits die Reihenfolge
     * der radial angeordneten Teiläste.
     */
    function radialLineOcclusionPenalty(
        positions,
        relations,
        clearance = 205
    ) {
        const nodes = [...positions.entries()];
        const seen = new Set();
        let penalty = 0;

        relations.forEach(relation => {
            const low = Math.min(relation.from, relation.to);
            const high = Math.max(relation.from, relation.to);
            const key = `${low}:${high}`;

            if (seen.has(key)) {
                return;
            }
            seen.add(key);

            const a = positions.get(relation.from);
            const b = positions.get(relation.to);

            if (!a || !b) {
                return;
            }

            const ex = b.x - a.x;
            const ey = b.y - a.y;
            const lenSq = ex * ex + ey * ey;

            if (lenSq < 10000) {
                return;
            }

            nodes.forEach(([id, point]) => {
                if (id === relation.from || id === relation.to) {
                    return;
                }

                const t = (
                    (point.x - a.x) * ex
                    + (point.y - a.y) * ey
                ) / lenSq;

                if (t <= 0.08 || t >= 0.92) {
                    return;
                }

                const distance = Math.hypot(
                    point.x - a.x - t * ex,
                    point.y - a.y - t * ey
                );

                if (distance < clearance) {
                    const overlap = clearance - distance;
                    penalty += overlap * overlap;
                }
            });
        });

        return penalty;
    }


    /*
     * Das starre Radiallayout lässt eine bestehende Zwischen-Node
     * manchmal exakt auf einer langen Kante liegen. Daher werden
     * die Node-Positionen nach der Branch-Optimierung vorsichtig
     * senkrecht zu solchen Kanten verschoben.
     *
     * Die Ausgangspositionen bleiben als schwache Anker erhalten,
     * der gewählte Wurzel-Charakter bleibt fest, und die einzelnen
     * Verbindungen bleiben gerade SVG-Linien.
     */
    function separateRadialEdgeOcclusions(
        initialPositions,
        relations,
        root
    ) {
        if (
            initialPositions.size < 3
            || relations.length < 2
            || radialLineOcclusionPenalty(initialPositions, relations) < 1
        ) {
            return initialPositions;
        }

        const positions = new Map(
            [...initialPositions.entries()].map(([id, p]) => [
                id,
                {x: p.x, y: p.y},
            ])
        );

        const nodes = [...positions.entries()];
        const seen = new Set();
        const edges = relations.filter(relation => {
            const low = Math.min(relation.from, relation.to);
            const high = Math.max(relation.from, relation.to);
            const key = `${low}:${high}`;
            if (seen.has(key)) {
                return false;
            }
            seen.add(key);
            return true;
        });

        const clearance = 205;
        const nodeClearance = 168;

        for (let iteration = 0; iteration < 100; iteration++) {
            let moved = 0;

            edges.forEach(relation => {
                const a = positions.get(relation.from);
                const b = positions.get(relation.to);
                if (!a || !b) {
                    return;
                }

                const ex = b.x - a.x;
                const ey = b.y - a.y;
                const lenSq = ex * ex + ey * ey;
                if (lenSq < 10000) {
                    return;
                }
                const length = Math.sqrt(lenSq);

                nodes.forEach(([id, point]) => {
                    if (id === relation.from || id === relation.to) {
                        return;
                    }

                    const t = (
                        (point.x - a.x) * ex
                        + (point.y - a.y) * ey
                    ) / lenSq;
                    if (t <= 0.08 || t >= 0.92) {
                        return;
                    }

                    let nx = point.x - (a.x + t * ex);
                    let ny = point.y - (a.y + t * ey);
                    const distance = Math.hypot(nx, ny);
                    if (distance >= clearance) {
                        return;
                    }

                    if (distance < 0.5 || id === root) {
                        // Bei fixierter Wurzel eine konstante Seite
                        // benutzen; sonst würden sich die Endpunkte
                        // bei jeder Iteration gegenseitig zurückziehen.
                        const side = deterministicUnit(
                            id,
                            relation.from * 31 + relation.to
                        ) < 0.5 ? -1 : 1;
                        nx = -ey / length * side;
                        ny = ex / length * side;
                    } else {
                        nx /= distance;
                        ny /= distance;
                    }

                    const shift = Math.min(
                        16,
                        (clearance - distance) * 0.19
                    );

                    if (id !== root) {
                        point.x += nx * shift;
                        point.y += ny * shift;
                        moved = Math.max(moved, shift);
                    }

                    // Liegt die festgehaltene Wurzel im Segment,
                    // müssen die beiden Endpunkte stärker ausweichen.
                    const endpointShare = id === root ? 1.8 : 0.27;
                    moved = Math.max(moved, shift * endpointShare);

                    if (relation.from !== root) {
                        a.x -= nx * shift * endpointShare * (1 - t);
                        a.y -= ny * shift * endpointShare * (1 - t);
                    }
                    if (relation.to !== root) {
                        b.x -= nx * shift * endpointShare * t;
                        b.y -= ny * shift * endpointShare * t;
                    }
                });
            });

            // Abstände zwischen den runden Charakterbildern.
            for (let i = 0; i < nodes.length - 1; i++) {
                const [idA, a] = nodes[i];
                for (let j = i + 1; j < nodes.length; j++) {
                    const [idB, b] = nodes[j];
                    let dx = b.x - a.x;
                    let dy = b.y - a.y;
                    let distance = Math.hypot(dx, dy);
                    if (distance >= nodeClearance) {
                        continue;
                    }
                    if (distance < 0.5) {
                        const angle = deterministicUnit(idA + idB, 71)
                            * Math.PI * 2;
                        dx = Math.cos(angle);
                        dy = Math.sin(angle);
                        distance = 1;
                    }
                    const shift = Math.min(12, (nodeClearance - distance) * 0.28);
                    const nx = dx / distance;
                    const ny = dy / distance;
                    if (idA !== root) {
                        a.x -= nx * shift;
                        a.y -= ny * shift;
                    }
                    if (idB !== root) {
                        b.x += nx * shift;
                        b.y += ny * shift;
                    }
                    moved = Math.max(moved, shift);
                }
            }

            // Sanfte Rückführung zur ursprünglichen Radialstruktur.
            nodes.forEach(([id, point]) => {
                if (id === root) {
                    return;
                }
                const origin = initialPositions.get(id);
                point.x += (origin.x - point.x) * 0.012;
                point.y += (origin.y - point.y) * 0.012;
            });

            if (moved < 0.05) {
                break;
            }
        }

        return positions;
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

        const occlusionPenalty = radialLineOcclusionPenalty(
            positions,
            relations
        );

        return (
            crossings * 1000000000
            + occlusionPenalty * 100
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

        const radialPositions = optimizeBranchOrder(
            tree,
            root,
            component.length,
            componentRelations
        );

        return separateRadialEdgeOcclusions(
            radialPositions,
            componentRelations,
            root
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
     * Globale "Alle"-Ansicht:
     * Force-Directed + Collision + schwache Regionscluster
     *
     * Die einzelnen Regionsansichten benutzen weiterhin
     * unverändert layoutGraphByBranches().
     * ===================================================== */

    function globalRegionKey(char) {
        const regionId =
            Number(
                char?.region_id
                || 0
            );

        return regionId > 0
            ? 'region:' + regionId
            : 'region:none';
    }


    function deterministicUnit(
        value,
        salt = 0
    ) {
        let x =
            (
                Number(value)
                * 2654435761
                + Number(salt)
                * 1013904223
            ) >>> 0;

        x ^= x << 13;
        x ^= x >>> 17;
        x ^= x << 5;

        return (
            (x >>> 0)
            % 100000
        ) / 100000;
    }


    function buildGlobalRegionAnchors(
        chars
    ) {
        const groups =
            new Map();


        chars.forEach(
            char => {
                const key =
                    globalRegionKey(
                        char
                    );

                if (!groups.has(key)) {
                    groups.set(
                        key,
                        {
                            key,
                            title:
                                String(
                                    char.region
                                    || ''
                                ),
                            chars: [],
                        }
                    );
                }

                groups
                    .get(key)
                    .chars
                    .push(char);
            }
        );


        const ordered =
            [...groups.values()]
                .sort(
                    (a, b) => {
                        const sizeDiff =
                            b.chars.length
                            - a.chars.length;

                        if (sizeDiff) {
                            return sizeDiff;
                        }

                        return a.title
                            .localeCompare(
                                b.title,
                                'de'
                            );
                    }
                );


        if (!ordered.length) {
            return new Map();
        }


        /*
         * Rechteckiges 2D-Raster statt Kreis/Sektoren.
         * 1.35 sorgt dafür, dass die Gesamtansicht etwas
         * breiter als hoch wird und den Viewport besser nutzt.
         */
        const columns =
            Math.max(
                1,
                Math.ceil(
                    Math.sqrt(
                        ordered.length
                        * 1.35
                    )
                )
            );

        const rows =
            Math.ceil(
                ordered.length
                / columns
            );


        const largestGroup =
            Math.max(
                1,
                ...ordered.map(
                    group =>
                        group.chars.length
                )
            );


        const sizeBonus =
            Math.min(
                260,
                Math.sqrt(
                    largestGroup
                ) * 38
            );


        const spacingX =
            590
            + sizeBonus;

        const spacingY =
            500
            + sizeBonus * 0.75;


        const centerX =
            2000;

        const centerY =
            1500;


        const anchors =
            new Map();


        ordered.forEach(
            (
                group,
                index
            ) => {
                const row =
                    Math.floor(
                        index
                        / columns
                    );

                /*
                 * Snake-Anordnung:
                 * zweite Reihe läuft rückwärts.
                 * Dadurch liegen aufeinanderfolgende Cluster
                 * nicht unnötig weit auseinander.
                 */
                const plainColumn =
                    index
                    % columns;

                const column =
                    row % 2 === 0
                        ? plainColumn
                        : (
                            columns
                            - 1
                            - plainColumn
                        );


                const x =
                    centerX
                    + (
                        column
                        - (
                            columns
                            - 1
                        ) / 2
                    )
                    * spacingX;

                const y =
                    centerY
                    + (
                        row
                        - (
                            rows
                            - 1
                        ) / 2
                    )
                    * spacingY;


                anchors.set(
                    group.key,
                    {
                        x,
                        y,
                    }
                );
            }
        );


        return anchors;
    }


    function globalRelationLength(
        relation
    ) {
        const base =
            {
                committed: 185,
                siblings: 200,                
                cousins: 1015,
                parent_child: 515,
                friend: 225,
                casual: 245,
                colleges: 450,
                enemies: 805,
            }[
                relation.type
            ]
            || 235;


        const from =
            charMap.get(
                relation.from
            );

        const to =
            charMap.get(
                relation.to
            );


        const differentRegion =
            from
            && to
            && globalRegionKey(from)
                !== globalRegionKey(to);


        return base
            + (
                differentRegion
                    ? 45
                    : 0
            );
    }


    /* =====================================================
     * Globale Ansicht: Zusammenhängende Seitenäste und lokale
     * Communities als Einheiten in weniger belegte Räume legen.
     *
     * Einzelfedern und lokale Node-Abstoßung reichen nicht aus:
     * Bei größeren Graphen können Gruppen trotz freiem Außenraum
     * mitten in einem dichten Cluster landen.
     *
     * Kandidaten werden allein aus der Topologie berechnet:
     * ein oder zwei Schnittkanten sowie schwach verbundene
     * Communities. Keine Namen/IDs und keine Relationstyp-Sonderfälle.
     * ===================================================== */

    function findGlobalSparseBranches(points, relations) {
        const ids = new Set(points.map(point => point.char.id));
        const adjacency = new Map([...ids].map(id => [id, []]));
        const edges = [];
        const seen = new Set();

        relations.forEach(relation => {
            if (!ids.has(relation.from) || !ids.has(relation.to)) return;
            const a = Math.min(relation.from, relation.to);
            const b = Math.max(relation.from, relation.to);
            const key = `${a}:${b}`;
            if (seen.has(key)) return;
            seen.add(key);
            const index = edges.length;
            edges.push({a, b, relation, index});
            adjacency.get(a).push({to: b, index});
            adjacency.get(b).push({to: a, index});
        });

        const maxSize = Math.min(18, Math.max(5, Math.ceil(points.length * 0.18)));
        const candidates = new Map();
        const components = [];
        const visited = new Set();

        function walk(start, ignoredA = -1, ignoredB = -1, cap = Infinity) {
            const result = new Set([start]);
            const queue = [start];
            for (let i = 0; i < queue.length; i++) {
                for (const edge of adjacency.get(queue[i]) || []) {
                    if (edge.index === ignoredA || edge.index === ignoredB
                        || result.has(edge.to)) continue;
                    result.add(edge.to);
                    if (result.size > cap) return result;
                    queue.push(edge.to);
                }
            }
            return result;
        }

        // Nur echte ursprüngliche Zusammenhangskomponenten betrachten:
        // eine abgetrennte Gruppe soll nicht an einen fremden Ast
        // einer anderen Komponente angehängt werden.
        for (const id of ids) {
            if (visited.has(id)) continue;
            const component = walk(id);
            for (const member of component) visited.add(member);
            if (component.size > 1) components.push(component);
        }

        function addCandidate(members, component, origin) {
            if (members.size < 2 || members.size > maxSize) return;
            if (component.size - members.size < Math.max(6, members.size * 1.6)) return;
            if ([...members].some(id => !component.has(id))) return;

            const first = members.values().next().value;
            const connected = new Set([first]);
            const queue = [first];
            for (let i = 0; i < queue.length; i++) {
                for (const edge of adjacency.get(queue[i]) || []) {
                    if (!members.has(edge.to) || connected.has(edge.to)) continue;
                    connected.add(edge.to);
                    queue.push(edge.to);
                }
            }
            if (connected.size !== members.size) return;

            let inside = 0;
            const boundary = [];
            for (const edge of edges) {
                const inA = members.has(edge.a);
                const inB = members.has(edge.b);
                if (inA && inB) inside++;
                else if (inA !== inB) boundary.push(edge);
            }
            if (boundary.length < 1 || boundary.length > 3) return;
            if (inside < members.size - 1 || inside < boundary.length * 0.75) return;

            const key = [...members].sort((a, b) => a - b).join(':');
            const previous = candidates.get(key);
            if (!previous || previous.origin > origin) {
                candidates.set(key, {
                    ids: new Set(members), boundary, inside,
                    origin,
                });
            }
        }

        // Ein- und Zwei-Kanten-Schnitte: kleine peripher angeschlossene
        // Teilgraphen, die von einer normalen Brückensuche übersehen werden.
        for (const component of components) {
            if (component.size < 8) continue;
            const componentEdges = edges.filter(edge =>
                component.has(edge.a) && component.has(edge.b)
            );

            for (let i = 0; i < componentEdges.length; i++) {
                const edgeA = componentEdges[i];
                const sideA = walk(edgeA.a, edgeA.index, -1, maxSize + 1);
                if (sideA.size <= maxSize && !sideA.has(edgeA.b)) {
                    addCandidate(sideA, component, 1);
                }
                const sideB = walk(edgeA.b, edgeA.index, -1, maxSize + 1);
                if (sideB.size <= maxSize && !sideB.has(edgeA.a)) {
                    addCandidate(sideB, component, 1);
                }

                // Zwei Kanten nur für überschaubare Komponenten prüfen.
                // Für sehr große Graphen übernehmen die Communities.
                if (componentEdges.length > 260) continue;
                for (let j = i + 1; j < componentEdges.length; j++) {
                    const edgeB = componentEdges[j];
                    const smallA = walk(edgeA.a, edgeA.index, edgeB.index, maxSize + 1);
                    if (smallA.size <= maxSize && !smallA.has(edgeA.b)) {
                        addCandidate(smallA, component, 2);
                    }
                    const smallB = walk(edgeA.b, edgeA.index, edgeB.index, maxSize + 1);
                    if (smallB.size <= maxSize && !smallB.has(edgeA.a)) {
                        addCandidate(smallB, component, 2);
                    }
                }
            }
        }

        // Zusätzlich modularitätsorientierte Gruppierung: So sind auch
        // kleine Communities mit drei Anschlüssen Kandidaten, wenn
        // ihr interner Zusammenhalt deutlich stärker ist.
        const labels = new Map([...ids].map(id => [id, id]));
        const degrees = new Map([...ids].map(id => [id, adjacency.get(id).length]));
        const order = [...ids].sort((a, b) => a - b);
        const totalDegree = Math.max(1, edges.length * 2);
        for (let pass = 0; pass < 14; pass++) {
            let changed = false;
            const totals = new Map();
            for (const id of order) {
                const label = labels.get(id);
                totals.set(label, (totals.get(label) || 0) + degrees.get(id));
            }
            for (const id of order) {
                const current = labels.get(id);
                const degree = degrees.get(id);
                totals.set(current, totals.get(current) - degree);
                const votes = new Map();
                for (const edge of adjacency.get(id)) {
                    const label = labels.get(edge.to);
                    votes.set(label, (votes.get(label) || 0) + 1);
                }
                votes.set(current, votes.get(current) || 0);
                let winner = current;
                let best = -Infinity;
                for (const [label, count] of votes) {
                    const score = count - degree * (totals.get(label) || 0) / totalDegree;
                    if (score > best + 1e-9 || (Math.abs(score - best) < 1e-9 && label === current)) {
                        best = score;
                        winner = label;
                    }
                }
                labels.set(id, winner);
                totals.set(winner, (totals.get(winner) || 0) + degree);
                if (winner !== current) changed = true;
            }
            if (!changed) break;
        }
        const communities = new Map();
        for (const id of order) {
            const label = labels.get(id);
            if (!communities.has(label)) communities.set(label, new Set());
            communities.get(label).add(id);
        }
        for (const community of communities.values()) {
            const component = components.find(c => c.has(community.values().next().value));
            if (component) addCandidate(community, component, 3);
        }

        // Lokale, dichte Teilgruppen mit bis zu drei Anschlüssen.
        // Von Knoten mit wenigen Nachbarn aus wird so lange erweitert,
        // wie zusätzliche interne Kanten die Schnittkante verkleinern.
        // Damit werden auch Dreiecke/Zyklen mit 3 Kernanbindungen erfasst,
        // die keine 1- oder 2-Kanten-Schnittgruppen sind.
        for (const seed of order) {
            if (degrees.get(seed) < 2 || degrees.get(seed) > 5) continue;
            const component = components.find(c => c.has(seed));
            if (!component || component.size < 8) continue;
            const group = new Set([seed]);
            while (group.size < maxSize) {
                const frontier = new Set();
                for (const id of group) {
                    for (const edge of adjacency.get(id)) {
                        if (!group.has(edge.to)) frontier.add(edge.to);
                    }
                }
                let best = null;
                let bestDelta = Infinity;
                for (const id of frontier) {
                    let inside = 0;
                    for (const edge of adjacency.get(id)) {
                        if (group.has(edge.to)) inside++;
                    }
                    const delta = degrees.get(id) - 2 * inside;
                    if (delta < bestDelta || (delta === bestDelta && id < best)) {
                        best = id;
                        bestDelta = delta;
                    }
                }
                if (best === null || (group.size >= 3 && bestDelta > 0)) break;
                group.add(best);
                addCandidate(group, component, 3);
            }
        }

        // Schwach angebundene, intern zusammenhängende Gruppen zuerst.
        const result = [...candidates.values()].sort((a, b) =>
            a.boundary.length - b.boundary.length
            || b.ids.size - a.ids.size
            || a.origin - b.origin
        );
        const claimed = new Set();
        return result.filter(candidate => {
            if ([...candidate.ids].some(id => claimed.has(id))) return false;
            for (const id of candidate.ids) claimed.add(id);
            return true;
        });
    }

    function spreadGlobalSparseBranches(points, relations) {
        const groups = findGlobalSparseBranches(points, relations);
        if (!groups.length) return;

        const state = new Map(points.map(point => [point.char.id, point]));
        const edges = [];
        const seen = new Set();
        relations.forEach(relation => {
            const a = Math.min(relation.from, relation.to);
            const b = Math.max(relation.from, relation.to);
            const key = `${a}:${b}`;
            if (!state.has(a) || !state.has(b) || seen.has(key)) return;
            seen.add(key);
            edges.push({a, b, relation});
        });

        function segmentDistance(point, from, to) {
            const dx = to.x - from.x;
            const dy = to.y - from.y;
            const len2 = dx * dx + dy * dy;
            const t = len2 < 1e-6 ? 0 : Math.max(0, Math.min(1,
                ((point.x - from.x) * dx + (point.y - from.y) * dy) / len2
            ));
            return Math.hypot(point.x - from.x - t * dx,
                              point.y - from.y - t * dy);
        }

        for (const group of groups) {
            const moving = [...group.ids].map(id => state.get(id));
            const fixed = points.filter(point => !group.ids.has(point.char.id));
            const fixedEdges = edges.filter(edge =>
                !group.ids.has(edge.a) && !group.ids.has(edge.b)
            );
            const movingEdges = edges.filter(edge =>
                group.ids.has(edge.a) || group.ids.has(edge.b)
            );
            const boundary = movingEdges.filter(edge =>
                group.ids.has(edge.a) !== group.ids.has(edge.b)
            );
            if (!boundary.length) continue;

            const center = {
                x: moving.reduce((sum, p) => sum + p.x, 0) / moving.length,
                y: moving.reduce((sum, p) => sum + p.y, 0) / moving.length,
            };
            const anchored = boundary.map(edge =>
                state.get(group.ids.has(edge.a) ? edge.b : edge.a)
            );
            const anchorCenter = {
                x: anchored.reduce((sum, p) => sum + p.x, 0) / anchored.length,
                y: anchored.reduce((sum, p) => sum + p.y, 0) / anchored.length,
            };
            const averageLength = boundary.reduce((sum, edge) =>
                sum + globalRelationLength(edge.relation), 0
            ) / boundary.length;
            const currentAngle = Math.atan2(center.y - anchorCenter.y,
                                             center.x - anchorCenter.x);
            const internalRadius = Math.max(30, ...moving.map(point =>
                Math.hypot(point.x - center.x, point.y - center.y)
            ));
            const orbit = averageLength + Math.min(170, internalRadius * 0.42);

            function score(proposed, movementPenalty, quick = false) {
                const position = id => proposed.get(id) || state.get(id);
                let cost = 0;
                for (const edge of boundary) {
                    const a = position(edge.a);
                    const b = position(edge.b);
                    const length = Math.hypot(a.x - b.x, a.y - b.y);
                    const target = globalRelationLength(edge.relation);
                    cost += 0.55 * (length - target) ** 2;
                }

                for (const member of moving) {
                    const positionMember = position(member.char.id);
                    for (const other of fixed) {
                        const dx = positionMember.x - other.x;
                        const dy = positionMember.y - other.y;
                        const distance = Math.hypot(dx, dy);
                        const related = boundary.some(edge =>
                            edge.a === member.char.id && edge.b === other.char.id
                            || edge.b === member.char.id && edge.a === other.char.id
                        );
                        const clearance = related ? 165 : 300;
                        if (distance < clearance) {
                            cost += 3.1 * (clearance - distance) ** 2;
                        }
                        // Zusätzlich die Dichte der UMGEBUNG beurteilen,
                        // nicht nur unmittelbare Kollisionen.
                        if (!related && distance < 540) {
                            cost += 0.14 * (540 - distance) ** 2;
                        }
                    }
                    if (!quick) {
                        for (const edge of fixedEdges) {
                            const distance = segmentDistance(positionMember,
                                state.get(edge.a), state.get(edge.b));
                            if (distance < 145) {
                                cost += 2.5 * (145 - distance) ** 2;
                            }
                        }
                    }
                    if (movementPenalty) {
                        cost += 0.033 * ((positionMember.x - member.x) ** 2
                                         + (positionMember.y - member.y) ** 2);
                    }
                }

                if (!quick) for (const edge of movingEdges) {
                    const a = position(edge.a);
                    const b = position(edge.b);
                    for (const other of fixed) {
                        if (other.char.id === edge.a || other.char.id === edge.b) continue;
                        const distance = segmentDistance(other, a, b);
                        if (distance < 140) cost += 2.5 * (140 - distance) ** 2;
                    }
                    for (const fixedEdge of fixedEdges) {
                        if (edge.a === fixedEdge.a || edge.a === fixedEdge.b
                            || edge.b === fixedEdge.a || edge.b === fixedEdge.b) continue;
                        if (radialEdgesCross(a, b, state.get(fixedEdge.a),
                                              state.get(fixedEdge.b))) {
                            cost += 110000;
                        }
                    }
                }
                return cost;
            }

            const initialScore = score(new Map(), false);
            let bestScore = initialScore;
            let best = null;
            const proposals = [];
            const bestPerSector = new Map();

            // Zweistufige Suche: erst billige Dichte-/Längenwertung,
            // anschließend teure Kantenkreuzungen nur für die besten
            // Kandidaten und mindestens einen Kandidaten pro Sektor.
            // So bleibt das Layout auch bei vielen Charakteren flüssig.
            for (const radiusFactor of [0.9, 1.12, 1.35, 1.6, 1.9]) {
                for (let step = 0; step < 32; step++) {
                    const angle = 2 * Math.PI * step / 32;
                    const newCenterX = anchorCenter.x + Math.cos(angle) * orbit * radiusFactor;
                    const newCenterY = anchorCenter.y + Math.sin(angle) * orbit * radiusFactor;
                    for (const rotation of [0, angle - currentAngle]) {
                        const c = Math.cos(rotation);
                        const s = Math.sin(rotation);
                        const proposed = new Map();
                        for (const member of moving) {
                            const dx = member.x - center.x;
                            const dy = member.y - center.y;
                            proposed.set(member.char.id, {
                                x: newCenterX + dx * c - dy * s,
                                y: newCenterY + dx * s + dy * c,
                            });
                        }
                        const light = score(proposed, true, true);
                        const candidate = {proposed, light};
                        proposals.push(candidate);
                        const sector = Math.floor(step / 4);
                        const previous = bestPerSector.get(sector);
                        if (!previous || light < previous.light) {
                            bestPerSector.set(sector, candidate);
                        }
                    }
                }
            }

            proposals.sort((a, b) => a.light - b.light);
            const finalists = new Set(proposals.slice(0, 15));
            for (const candidate of bestPerSector.values()) finalists.add(candidate);
            for (const candidate of finalists) {
                const candidateScore = score(candidate.proposed, true);
                if (candidateScore < bestScore - 3000) {
                    bestScore = candidateScore;
                    best = candidate.proposed;
                }
            }
            if (best) {
                for (const member of moving) {
                    const p = best.get(member.char.id);
                    member.x = p.x;
                    member.y = p.y;
                }
            }
        }
    }

    function layoutGraphForceGlobal() {
        const chars =
            visibleChars();

        const relations =
            visibleRelations();


        if (!chars.length) {
            return;
        }


        if (chars.length === 1) {
            const node =
                nodeMap.get(
                    chars[0].id
                );

            if (node) {
                node.x = 2000;
                node.y = 1500;

                globalLayoutCache.set(
                    chars[0].id,
                    {
                        x: node.x,
                        y: node.y,
                    }
                );
            }

            return;
        }


        const anchors =
            buildGlobalRegionAnchors(
                chars
            );


        const state =
            new Map();


        const groupCounters =
            new Map();


        chars.forEach(
            char => {
                const cached =
                    globalLayoutCache.get(
                        char.id
                    );

                const regionKey =
                    globalRegionKey(
                        char
                    );

                const anchor =
                    anchors.get(
                        regionKey
                    )
                    || {
                        x: 2000,
                        y: 1500,
                    };


                const groupIndex =
                    groupCounters.get(
                        regionKey
                    )
                    || 0;

                groupCounters.set(
                    regionKey,
                    groupIndex + 1
                );


                let x;
                let y;


                if (
                    cached
                    && Number.isFinite(
                        cached.x
                    )
                    && Number.isFinite(
                        cached.y
                    )
                ) {
                    x =
                        cached.x;

                    y =
                        cached.y;

                } else {
                    /*
                     * Deterministischer Spiral-Start innerhalb
                     * des Regionsclusters. Kein Zufall, damit
                     * Reloads nicht jedes Mal anders aussehen.
                     */
                    const angle =
                        groupIndex
                        * 2.399963229728653
                        + deterministicUnit(
                            char.id,
                            11
                        )
                        * 0.45;

                    const radius =
                        38
                        + Math.sqrt(
                            groupIndex
                            + 1
                        )
                        * 76;

                    x =
                        anchor.x
                        + Math.cos(
                            angle
                        )
                        * radius;

                    y =
                        anchor.y
                        + Math.sin(
                            angle
                        )
                        * radius;
                }


                state.set(
                    char.id,
                    {
                        char,
                        x,
                        y,
                        vx: 0,
                        vy: 0,
                    }
                );
            }
        );


        const points =
            [...state.values()];


        const iterationCount =
            Math.max(
                300,
                Math.min(
                    520,
                    250
                    + chars.length * 4
                )
            );


        /*
         * Abstände sind bewusst deutlich größer als die
         * Node-Breite. Labels sollen sich ebenfalls nicht
         * dauernd überdecken.
         */
        const collisionDistance =
            172;

        const repulsionStrength =
            88000;

        const springStrength =
            0.017;

        const regionStrength =
            0.0019;

        const centerStrength =
            0.00016;

        const damping =
            0.82;

        /*
         * Node-zu-Kante-Abstoßung: Eine lange Beziehungslinie darf
         * nicht über das Bild eines dritten Charakters laufen.
         * Damit werden insbesondere fast deckungsgleiche Linien
         * mit gemeinsamem Ausgangspunkt auseinandergezogen.
         *
         * Abstände beziehen sich auf Weltkoordinaten. Die bestehende
         * Node-zu-Node-Kollision und die Federlängen bleiben erhalten.
         */
        const edgeNodeClearance = 160;
        const edgeNodeStrength = 0.055;
        const edgeNodeMinProjection = 0.08;

        /*
         * Mehrere Relationstypen zwischen demselben Paar erzeugen
         * geometrisch dieselbe Kante und sollen die Abstoßung
         * nicht mehrfach verstärken.
         */
        const uniqueEdgeRelations = [];
        const seenEdgePairs = new Set();

        relations.forEach(relation => {
            const low = Math.min(relation.from, relation.to);
            const high = Math.max(relation.from, relation.to);
            const key = `${low}:${high}`;

            if (seenEdgePairs.has(key)) {
                return;
            }

            seenEdgePairs.add(key);
            uniqueEdgeRelations.push(relation);
        });


        /*
         * Zusätzliche semantische Trennung:
         * Direkt verbundene Charaktere dürfen entsprechend ihrer
         * Relationsfeder nah beieinander liegen. Charaktere ohne
         * direkte Relation bekommen unterhalb eines größeren
         * Mindestabstands eine zusätzliche weiche Abstoßung.
         *
         * Das verhindert, dass räumlich zufällig benachbarte,
         * unverknüpfte Äste visuell wie zusammengehörig wirken.
         */
        const directlyRelated =
            new Set();

        relations.forEach(
            relation => {
                const a =
                    Math.min(
                        relation.from,
                        relation.to
                    );

                const b =
                    Math.max(
                        relation.from,
                        relation.to
                    );

                directlyRelated.add(
                    `${a}:${b}`
                );
            }
        );


        const unrelatedDistance =
            300;

        const unrelatedStrength =
            0.035;


        for (
            let iteration = 0;
            iteration < iterationCount;
            iteration++
        ) {
            const progress =
                iteration
                / Math.max(
                    1,
                    iterationCount - 1
                );

            const temperature =
                Math.max(
                    0.08,
                    1 - progress
                );


            const fx =
                new Map();

            const fy =
                new Map();


            points.forEach(
                point => {
                    fx.set(
                        point.char.id,
                        0
                    );

                    fy.set(
                        point.char.id,
                        0
                    );
                }
            );


            /*
             * Globale Abstoßung.
             * O(n²) ist für die hier erwartete Charakterzahl
             * klein genug und ergibt ein ruhigeres Layout als
             * zusätzliche Bibliotheken/Approximationen.
             */
            for (
                let i = 0;
                i < points.length - 1;
                i++
            ) {
                const a =
                    points[i];

                for (
                    let j = i + 1;
                    j < points.length;
                    j++
                ) {
                    const b =
                        points[j];

                    let dx =
                        a.x
                        - b.x;

                    let dy =
                        a.y
                        - b.y;


                    if (
                        Math.abs(dx)
                            < 0.001
                        && Math.abs(dy)
                            < 0.001
                    ) {
                        const angle =
                            deterministicUnit(
                                a.char.id
                                + b.char.id,
                                i + j + 31
                            )
                            * Math.PI
                            * 2;

                        dx =
                            Math.cos(
                                angle
                            )
                            * 0.01;

                        dy =
                            Math.sin(
                                angle
                            )
                            * 0.01;
                    }


                    const distanceSquared =
                        Math.max(
                            100,
                            dx * dx
                            + dy * dy
                        );

                    const distance =
                        Math.sqrt(
                            distanceSquared
                        );


                    const force =
                        Math.min(
                            10,
                            repulsionStrength
                            / distanceSquared
                        );


                    const nx =
                        dx
                        / distance;

                    const ny =
                        dy
                        / distance;


                    const pairA =
                        Math.min(
                            a.char.id,
                            b.char.id
                        );

                    const pairB =
                        Math.max(
                            a.char.id,
                            b.char.id
                        );

                    const hasDirectRelation =
                        directlyRelated.has(
                            `${pairA}:${pairB}`
                        );


                    /*
                     * Unverbundene Charaktere erhalten zusätzlich
                     * eine weiche Nahbereichs-Abstoßung. Sie wirkt
                     * nur unterhalb unrelatedDistance und bläst
                     * den gesamten Graphen daher nicht unnötig auf.
                     */
                    if (
                        !hasDirectRelation
                        && distance
                            < unrelatedDistance
                    ) {
                        const pressure =
                            (
                                unrelatedDistance
                                - distance
                            )
                            * unrelatedStrength;

                        fx.set(
                            a.char.id,
                            fx.get(
                                a.char.id
                            )
                            + nx
                                * pressure
                        );

                        fy.set(
                            a.char.id,
                            fy.get(
                                a.char.id
                            )
                            + ny
                                * pressure
                        );

                        fx.set(
                            b.char.id,
                            fx.get(
                                b.char.id
                            )
                            - nx
                                * pressure
                        );

                        fy.set(
                            b.char.id,
                            fy.get(
                                b.char.id
                            )
                            - ny
                                * pressure
                        );
                    }


                    fx.set(
                        a.char.id,
                        fx.get(
                            a.char.id
                        )
                        + nx
                            * force
                    );

                    fy.set(
                        a.char.id,
                        fy.get(
                            a.char.id
                        )
                        + ny
                            * force
                    );

                    fx.set(
                        b.char.id,
                        fx.get(
                            b.char.id
                        )
                        - nx
                            * force
                    );

                    fy.set(
                        b.char.id,
                        fy.get(
                            b.char.id
                        )
                        - ny
                            * force
                    );
                }
            }


            /*
             * Beziehungslinien als Federn.
             */
            relations.forEach(
                relation => {
                    const a =
                        state.get(
                            relation.from
                        );

                    const b =
                        state.get(
                            relation.to
                        );


                    if (!a || !b) {
                        return;
                    }


                    let dx =
                        b.x
                        - a.x;

                    let dy =
                        b.y
                        - a.y;

                    let distance =
                        Math.hypot(
                            dx,
                            dy
                        );


                    if (distance < 0.001) {
                        distance =
                            0.001;
                    }


                    const target =
                        globalRelationLength(
                            relation
                        );


                    const force =
                        (
                            distance
                            - target
                        )
                        * springStrength;


                    const nx =
                        dx
                        / distance;

                    const ny =
                        dy
                        / distance;


                    fx.set(
                        a.char.id,
                        fx.get(
                            a.char.id
                        )
                        + nx
                            * force
                    );

                    fy.set(
                        a.char.id,
                        fy.get(
                            a.char.id
                        )
                        + ny
                            * force
                    );

                    fx.set(
                        b.char.id,
                        fx.get(
                            b.char.id
                        )
                        - nx
                            * force
                    );

                    fy.set(
                        b.char.id,
                        fy.get(
                            b.char.id
                        )
                        - ny
                            * force
                    );
                }
            );


            /*
             * Kantenvermeidung: Prüfe für jede sichtbare Relation,
             * ob ein dritter Charakter nah an deren Liniensegment
             * liegt. Der Charakter wird senkrecht zur Linie bewegt;
             * beide Endpunkte geben leicht nach. So bleibt die
             * eigentliche Beziehungsgeometrie weitgehend erhalten.
             *
             * Die Projektion auf das Segment verhindert, dass die
             * unendlich verlängerte Linie andere Nodes verdrängt.
             * Der Bereich direkt an den Endpunkten bleibt der
             * normalen Node-Kollision vorbehalten.
             */
            uniqueEdgeRelations.forEach(relation => {
                const start = state.get(relation.from);
                const end = state.get(relation.to);

                if (!start || !end) {
                    return;
                }

                const ex = end.x - start.x;
                const ey = end.y - start.y;
                const lenSquared = ex * ex + ey * ey;

                if (lenSquared < 10000) {
                    return;
                }

                const len = Math.sqrt(lenSquared);

                points.forEach(point => {
                    const id = point.char.id;

                    if (id === relation.from || id === relation.to) {
                        return;
                    }

                    const projection = (
                        (point.x - start.x) * ex
                        + (point.y - start.y) * ey
                    ) / lenSquared;

                    if (
                        projection <= edgeNodeMinProjection
                        || projection >= 1 - edgeNodeMinProjection
                    ) {
                        return;
                    }

                    const px = start.x + projection * ex;
                    const py = start.y + projection * ey;
                    let nx = point.x - px;
                    let ny = point.y - py;
                    const distance = Math.hypot(nx, ny);

                    if (distance >= edgeNodeClearance) {
                        return;
                    }

                    if (distance < 0.5) {
                        // Deterministische Ausweichrichtung, falls
                        // ein Node exakt auf dem Segment liegt.
                        const side = deterministicUnit(
                            id,
                            relation.from * 31 + relation.to
                        ) < 0.5 ? -1 : 1;

                        nx = -ey / len * side;
                        ny = ex / len * side;
                    } else {
                        nx /= distance;
                        ny /= distance;
                    }

                    const pressure = Math.min(
                        24,
                        (edgeNodeClearance - distance)
                        * edgeNodeStrength
                    );

                    fx.set(id, fx.get(id) + nx * pressure);
                    fy.set(id, fy.get(id) + ny * pressure);

                    // Leichter Gegenschub auf die Endpunkte.
                    const startShare = 0.35 * (1 - projection);
                    const endShare = 0.35 * projection;

                    fx.set(
                        relation.from,
                        fx.get(relation.from) - nx * pressure * startShare
                    );
                    fy.set(
                        relation.from,
                        fy.get(relation.from) - ny * pressure * startShare
                    );
                    fx.set(
                        relation.to,
                        fx.get(relation.to) - nx * pressure * endShare
                    );
                    fy.set(
                        relation.to,
                        fy.get(relation.to) - ny * pressure * endShare
                    );
                });
            });


            /*
             * Schwache Regionsanziehung.
             * Sie gruppiert nur grob; Relationen dürfen die
             * Charaktere weiterhin deutlich aus dem Cluster
             * herausziehen.
             */
            points.forEach(
                point => {
                    const anchor =
                        anchors.get(
                            globalRegionKey(
                                point.char
                            )
                        )
                        || {
                            x: 2000,
                            y: 1500,
                        };


                    fx.set(
                        point.char.id,
                        fx.get(
                            point.char.id
                        )
                        + (
                            anchor.x
                            - point.x
                        )
                        * regionStrength
                    );

                    fy.set(
                        point.char.id,
                        fy.get(
                            point.char.id
                        )
                        + (
                            anchor.y
                            - point.y
                        )
                        * regionStrength
                    );


                    /*
                     * Sehr schwache Gesamtzentrierung, damit
                     * der Graph nicht als Ganzes wegdriftet.
                     */
                    fx.set(
                        point.char.id,
                        fx.get(
                            point.char.id
                        )
                        + (
                            2000
                            - point.x
                        )
                        * centerStrength
                    );

                    fy.set(
                        point.char.id,
                        fy.get(
                            point.char.id
                        )
                        + (
                            1500
                            - point.y
                        )
                        * centerStrength
                    );
                }
            );


            /*
             * Integration mit Abkühlung.
             */
            points.forEach(
                point => {
                    point.vx =
                        (
                            point.vx
                            + fx.get(
                                point.char.id
                            )
                        )
                        * damping;

                    point.vy =
                        (
                            point.vy
                            + fy.get(
                                point.char.id
                            )
                        )
                        * damping;


                    const speed =
                        Math.hypot(
                            point.vx,
                            point.vy
                        );

                    const maxStep =
                        3
                        + 24
                            * temperature;


                    if (
                        speed
                        > maxStep
                    ) {
                        const factor =
                            maxStep
                            / speed;

                        point.vx *=
                            factor;

                        point.vy *=
                            factor;
                    }


                    point.x +=
                        point.vx;

                    point.y +=
                        point.vy;
                }
            );


            /*
             * Harte Collision-Korrektur nach dem Force-Schritt.
             * Dadurch dürfen Nodes auch dann nicht ineinander
             * rutschen, wenn mehrere Beziehungen sie eng
             * zusammenziehen.
             */
            for (
                let i = 0;
                i < points.length - 1;
                i++
            ) {
                const a =
                    points[i];

                for (
                    let j = i + 1;
                    j < points.length;
                    j++
                ) {
                    const b =
                        points[j];

                    let dx =
                        b.x
                        - a.x;

                    let dy =
                        b.y
                        - a.y;

                    let distance =
                        Math.hypot(
                            dx,
                            dy
                        );


                    if (distance < 0.001) {
                        const angle =
                            deterministicUnit(
                                a.char.id
                                + b.char.id,
                                73 + i + j
                            )
                            * Math.PI
                            * 2;

                        dx =
                            Math.cos(
                                angle
                            );

                        dy =
                            Math.sin(
                                angle
                            );

                        distance = 1;
                    }


                    if (
                        distance
                        >= collisionDistance
                    ) {
                        continue;
                    }


                    const overlap =
                        (
                            collisionDistance
                            - distance
                        )
                        * 0.52;


                    const nx =
                        dx
                        / distance;

                    const ny =
                        dy
                        / distance;


                    a.x -=
                        nx
                        * overlap;

                    a.y -=
                        ny
                        * overlap;

                    b.x +=
                        nx
                        * overlap;

                    b.y +=
                        ny
                        * overlap;
                }
            }
        }


        /*
         * Kleine, über genau eine Brücke angebundene Gruppen
         * am Rand des Netzes in tatsächlich freie Sektoren legen.
         * Die Hauptberechnung und die inneren Abstände bleiben erhalten.
         */
        spreadGlobalSparseBranches(points, relations);


        /*
         * Zum Schluss den fertigen Graphen wieder ungefähr
         * in den 4000×3000-World-Bereich schieben.
         * Die Form selbst bleibt dabei unverändert.
         */
        let minX =
            Infinity;

        let minY =
            Infinity;

        let maxX =
            -Infinity;

        let maxY =
            -Infinity;


        points.forEach(
            point => {
                minX =
                    Math.min(
                        minX,
                        point.x
                    );

                minY =
                    Math.min(
                        minY,
                        point.y
                    );

                maxX =
                    Math.max(
                        maxX,
                        point.x
                    );

                maxY =
                    Math.max(
                        maxY,
                        point.y
                    );
            }
        );


        const centerX =
            (
                minX
                + maxX
            ) / 2;

        const centerY =
            (
                minY
                + maxY
            ) / 2;


        const shiftX =
            2000
            - centerX;

        const shiftY =
            1500
            - centerY;


        points.forEach(
            point => {
                point.x +=
                    shiftX;

                point.y +=
                    shiftY;


                const node =
                    nodeMap.get(
                        point.char.id
                    );

                if (!node) {
                    return;
                }


                node.x =
                    point.x;

                node.y =
                    point.y;


                globalLayoutCache.set(
                    point.char.id,
                    {
                        x: point.x,
                        y: point.y,
                    }
                );
            }
        );
    }


    function layoutCurrentGraph() {
        if (
            selectedRegionId === null
        ) {
            layoutGraphForceGlobal();
            return;
        }

        /*
         * Einzelne Region:
         * bisheriges Branch-/Radiallayout unverändert.
         */
        layoutGraphByBranches();
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


        layoutCurrentGraph();

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
                    displayCoord(
                        node.x
                    )
                    + 'px';

                node.el.style.top =
                    displayCoord(
                        node.y
                    )
                    + 'px';
            }
        );
    }


    function attachNodeDrag(node) {
        if (PHONE_UI) {
            return;
        }


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


                    if (
                        moved
                        && selectedRegionId === null
                    ) {
                        globalLayoutCache.set(
                            node.char.id,
                            {
                                x: node.x,
                                y: node.y,
                            }
                        );
                    }


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
                        displayCoord(
                            from.x
                        ),

                    y1:
                        displayCoord(
                            from.y
                        ),

                    x2:
                        displayCoord(
                            to.x
                        ),

                    y2:
                        displayCoord(
                            to.y
                        ),
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
         * "Alle" benutzt das globale Force-Layout.
         * Einzelne Regionen bleiben beim bisherigen
         * Branch-/Radiallayout.
         */
        layoutCurrentGraph();

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

        setPhoneLegendOpen(false);


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
                    img.dataset.thumbSrc;

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

        const visibleIds =
            visibleCharIds();

        const activeTypes =
            activeRelationTypes();


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

                /*
                 * Im Auswahlmodus zählen ausschließlich
                 * Relationen, die durch den aktuellen
                 * Regions- UND Relationstyp-Filter tatsächlich
                 * sichtbar sind.
                 *
                 * Eine ausgeblendete Cousins-Relation darf den
                 * anderen Charakter daher nicht mehr als
                 * direkten Nachbarn markieren.
                 */
                if (
                    !visibleIds.has(
                        relation.from
                    )
                    || !visibleIds.has(
                        relation.to
                    )
                    || !activeTypes.has(
                        relation.type
                    )
                ) {
                    return;
                }

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
                PHONE_UI
                    ? 1.55
                    : 0.72,
                Math.min(
                    PHONE_UI
                        ? 3.20
                        : 1.25,
                    scale
                )
            );


        translateX =
            rect.width / 2
            - displayCoord(
                node.x
            )
                * scale;

        translateY =
            rect.height / 2
            - displayCoord(
                node.y
            )
                * scale;


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
         * Auswahl ist auf Desktop und Phone ausschließlich
         * ein visueller Filter:
         * - keine Neuanordnung
         * - kein Full-Image
         * - kein Zoom
         * - keine Kamerabewegung
         */
        markCenteredNode();
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
         * Auswahlzustand nur optisch aufheben.
         * Viewport und Node-Positionen bleiben exakt stehen.
         */
        markCenteredNode();
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

                const halfWidth =
                    PHONE_UI
                        ? 30
                        : 70;

                const topSpace =
                    PHONE_UI
                        ? 30
                        : 70;

                const bottomSpace =
                    PHONE_UI
                        ? 42
                        : 90;

                const nodeX =
                    displayCoord(
                        node.x
                    );

                const nodeY =
                    displayCoord(
                        node.y
                    );

                minX =
                    Math.min(
                        minX,
                        nodeX - halfWidth
                    );

                minY =
                    Math.min(
                        minY,
                        nodeY - topSpace
                    );

                maxX =
                    Math.max(
                        maxX,
                        nodeX + halfWidth
                    );

                maxY =
                    Math.max(
                        maxY,
                        nodeY + bottomSpace
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

        const padding =
            PHONE_UI
                ? 28
                : 95;


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


        const fitScale =
            Math.min(
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
            );

        scale =
            Math.max(
                PHONE_UI
                    ? 0.35
                    : 0.18,
                Math.min(
                    PHONE_UI
                        ? 1.35
                        : 1.35,

                    fitScale
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
     * Pan + Phone-Pinch
     * ===================================================== */

    function phoneStartPinch() {
        if (
            !PHONE_UI
            || phonePointers.size < 2
        ) {
            phonePinchState =
                null;

            return;
        }

        const entries =
            [...phonePointers.entries()]
                .slice(0, 2);

        const first =
            entries[0][1];

        const second =
            entries[1][1];

        const rect =
            viewport
                .getBoundingClientRect();

        const midpointX =
            (
                first.x
                + second.x
            )
            / 2
            - rect.left;

        const midpointY =
            (
                first.y
                + second.y
            )
            / 2
            - rect.top;

        const distance =
            Math.max(
                1,
                Math.hypot(
                    second.x
                        - first.x,
                    second.y
                        - first.y
                )
            );

        phonePinchState = {
            pointerIds: [
                entries[0][0],
                entries[1][0],
            ],

            startDistance:
                distance,

            startScale:
                scale,

            worldX:
                (
                    midpointX
                    - translateX
                )
                / scale,

            worldY:
                (
                    midpointY
                    - translateY
                )
                / scale,
        };

        phoneGestureMoved =
            true;

        phoneTapNode =
            null;

        panState =
            null;
    }


    function phoneRebaseSinglePan() {
        if (
            !PHONE_UI
            || phonePointers.size !== 1
        ) {
            panState =
                null;

            return;
        }

        const entry =
            phonePointers
                .entries()
                .next()
                .value;

        const pointerId =
            entry[0];

        const point =
            entry[1];

        panState = {
            pointerId,

            startX:
                point.x,

            startY:
                point.y,

            translateX,
            translateY,

            moved:
                true,
        };
    }


    viewport.addEventListener(
        'pointerdown',
        event => {
            if (
                event.target.closest(
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


            if (PHONE_UI) {
                event.preventDefault();

                phonePointers.set(
                    event.pointerId,
                    {
                        x:
                            event.clientX,

                        y:
                            event.clientY,
                    }
                );


                try {
                    viewport.setPointerCapture(
                        event.pointerId
                    );
                } catch (_) {}


                viewport.classList.add(
                    'panning'
                );


                if (
                    phonePointers.size === 1
                ) {
                    phoneGestureMoved =
                        false;

                    phonePinchState =
                        null;

                    phoneTapNode =
                        event.target.closest(
                            '.relation-node'
                        );

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

                    return;
                }


                phoneStartPinch();
                return;
            }


            if (
                event.target.closest(
                    '.relation-node'
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
            if (PHONE_UI) {
                if (
                    !phonePointers.has(
                        event.pointerId
                    )
                ) {
                    return;
                }

                phonePointers.set(
                    event.pointerId,
                    {
                        x:
                            event.clientX,

                        y:
                            event.clientY,
                    }
                );


                if (
                    phonePointers.size >= 2
                ) {
                    const activeIds =
                        [...phonePointers.keys()]
                            .slice(0, 2);

                    if (
                        !phonePinchState
                        || phonePinchState
                            .pointerIds[0]
                            !== activeIds[0]
                        || phonePinchState
                            .pointerIds[1]
                            !== activeIds[1]
                    ) {
                        phoneStartPinch();
                    }

                    if (!phonePinchState) {
                        return;
                    }

                    const first =
                        phonePointers.get(
                            phonePinchState
                                .pointerIds[0]
                        );

                    const second =
                        phonePointers.get(
                            phonePinchState
                                .pointerIds[1]
                        );

                    if (
                        !first
                        || !second
                    ) {
                        return;
                    }

                    const rect =
                        viewport
                            .getBoundingClientRect();

                    const midpointX =
                        (
                            first.x
                            + second.x
                        )
                        / 2
                        - rect.left;

                    const midpointY =
                        (
                            first.y
                            + second.y
                        )
                        / 2
                        - rect.top;

                    const distance =
                        Math.max(
                            1,
                            Math.hypot(
                                second.x
                                    - first.x,
                                second.y
                                    - first.y
                            )
                        );

                    const nextScale =
                        Math.max(
                            PHONE_UI
                                ? 0.35
                                : 0.12,
                            Math.min(
                                3.2,
                                phonePinchState
                                    .startScale
                                * (
                                    distance
                                    / phonePinchState
                                        .startDistance
                                )
                            )
                        );

                    translateX =
                        midpointX
                        - phonePinchState
                            .worldX
                            * nextScale;

                    translateY =
                        midpointY
                        - phonePinchState
                            .worldY
                            * nextScale;

                    scale =
                        nextScale;

                    phoneGestureMoved =
                        true;

                    applyTransform();
                    return;
                }


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

                    phoneGestureMoved =
                        true;
                }


                translateX =
                    panState.translateX
                    + deltaX;

                translateY =
                    panState.translateY
                    + deltaY;

                applyTransform();
                return;
            }


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
        if (PHONE_UI) {
            if (
                !phonePointers.has(
                    event.pointerId
                )
            ) {
                return;
            }

            const wasOnlyPointer =
                phonePointers.size === 1;

            const wasTap =
                wasOnlyPointer
                && event.type
                    === 'pointerup'
                && !phoneGestureMoved;

            const tappedNode =
                wasTap
                    ? phoneTapNode
                    : null;

            phonePointers.delete(
                event.pointerId
            );


            if (
                phonePointers.size >= 2
            ) {
                phoneStartPinch();
                return;
            }


            if (
                phonePointers.size === 1
            ) {
                phonePinchState =
                    null;

                phoneGestureMoved =
                    true;

                phoneTapNode =
                    null;

                phoneRebaseSinglePan();
                return;
            }


            panState =
                null;

            phonePinchState =
                null;

            viewport.classList.remove(
                'panning'
            );


            if (wasTap) {
                if (tappedNode) {
                    const id =
                        Number(
                            tappedNode
                                .dataset
                                .id
                            || 0
                        );

                    if (id > 0) {
                        enterCenteredMode(
                            id
                        );
                    }
                } else if (
                    centeredCharId !== null
                ) {
                    exitCenteredMode();
                }
            }


            phoneGestureMoved =
                false;

            phoneTapNode =
                null;

            return;
        }


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

        if (!PHONE_UI) {
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

                if (!PHONE_UI) {
                    document
                        .getElementById(
                            'addToSearch'
                        )
                        ?.focus();
                }

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


        if (!PHONE_UI) {
            window.setTimeout(
                () => {
                    relationSearch?.focus();
                },
                0
            );
        }
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
