<?php
// phan/Fraktion.php

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

function pf_h(mixed $value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function pf_exec(
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

        $stmt->bind_param(
            $types,
            ...$refs
        );
    }

    $stmt->execute();

    return $stmt;
}

function pf_one(
    mysqli $db,
    string $sql,
    array $params = []
): ?array {
    $stmt = pf_exec(
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

function pf_all(
    mysqli $db,
    string $sql,
    array $params = []
): array {
    $stmt = pf_exec(
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

function pf_json(
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

function pf_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    try {
        return (
            new DateTimeImmutable($value)
        )->format('d.m.Y, H:i');
    } catch (Throwable) {
        return '—';
    }
}

function pf_legacy_faction_text(
    mysqli $db,
    int $charId
): string {
    if ($charId <= 0) {
        return '';
    }

    $titles = array_map(
        static fn(array $row): string =>
            (string)$row['title'],
        pf_all(
            $db,
            'SELECT f.title
             FROM char_factions cf
             INNER JOIN factions f
                ON f.id = cf.faction_id
             WHERE cf.char_id = ?
             ORDER BY f.title',
            [$charId]
        )
    );

    $text = implode(', ', $titles);

    if (function_exists('mb_substr')) {
        return mb_substr(
            $text,
            0,
            255,
            'UTF-8'
        );
    }

    return substr(
        $text,
        0,
        255
    );
}

function pf_refresh_char_legacy_faction(
    mysqli $db,
    int $charId
): void {
    if ($charId <= 0) {
        return;
    }

    pf_exec(
        $db,
        'UPDATE chars
         SET faction = ?
         WHERE id = ?',
        [
            pf_legacy_faction_text(
                $db,
                $charId
            ),
            $charId,
        ]
    )->close();
}


/* =========================================================
 * POST / AJAX
 * ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (
        (string)($_POST['ajax'] ?? '') === '1'
        || strtolower(
            (string)(
                $_SERVER[
                    'HTTP_X_REQUESTED_WITH'
                ] ?? ''
            )
        ) === 'xmlhttprequest'
    );

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
            ?? 'save'
        );

        $id = max(
            0,
            (int)($_POST['id'] ?? 0)
        );


        /* -------------------------------------------------
         * Fraktion löschen
         * ------------------------------------------------- */

        if ($action === 'delete') {
            if ($id <= 0) {
                throw new RuntimeException(
                    'Ungültige Fraktion.'
                );
            }

            $affectedCharIds = array_map(
                static fn(array $row): int =>
                    (int)$row['char_id'],
                pf_all(
                    $phanconn,
                    'SELECT char_id
                     FROM char_factions
                     WHERE faction_id = ?',
                    [$id]
                )
            );

            $phanconn->begin_transaction();

            try {
                pf_exec(
                    $phanconn,
                    'DELETE FROM factions
                     WHERE id = ?',
                    [$id]
                )->close();

                foreach (
                    $affectedCharIds
                    as $charId
                ) {
                    pf_refresh_char_legacy_faction(
                        $phanconn,
                        $charId
                    );
                }

                $phanconn->commit();

            } catch (Throwable $e) {
                $phanconn->rollback();
                throw $e;
            }

            if ($isAjax) {
                pf_json([
                    'ok' => true,
                    'deleted' => true,
                ]);
            }

            header(
                'Location: /phan/factions?deleted=1',
                true,
                303
            );
            exit;
        }


        /* -------------------------------------------------
         * Fraktion anlegen / umbenennen
         * ------------------------------------------------- */

        if ($action !== 'save') {
            throw new RuntimeException(
                'Unbekannte Aktion.'
            );
        }

        $title = trim(
            (string)($_POST['title'] ?? '')
        );

        if ($title === '') {
            throw new RuntimeException(
                'Bitte einen Namen für die Fraktion eingeben.'
            );
        }

        if (function_exists('mb_strlen')) {
            if (
                mb_strlen(
                    $title,
                    'UTF-8'
                ) > 255
            ) {
                throw new RuntimeException(
                    'Der Fraktionsname darf maximal 255 Zeichen lang sein.'
                );
            }
        } elseif (strlen($title) > 255) {
            throw new RuntimeException(
                'Der Fraktionsname darf maximal 255 Zeichen lang sein.'
            );
        }

        $duplicateSql =
            'SELECT id
             FROM factions
             WHERE title = ?';

        $duplicateParams = [
            $title,
        ];

        if ($id > 0) {
            $duplicateSql .=
                ' AND id <> ?';

            $duplicateParams[] = $id;
        }

        if (
            pf_one(
                $phanconn,
                $duplicateSql,
                $duplicateParams
            )
        ) {
            throw new RuntimeException(
                'Diese Fraktion existiert bereits.'
            );
        }

        $affectedCharIds = [];

        if ($id > 0) {
            if (
                !pf_one(
                    $phanconn,
                    'SELECT id
                     FROM factions
                     WHERE id = ?',
                    [$id]
                )
            ) {
                throw new RuntimeException(
                    'Fraktion existiert nicht mehr.'
                );
            }

            $affectedCharIds = array_map(
                static fn(array $row): int =>
                    (int)$row['char_id'],
                pf_all(
                    $phanconn,
                    'SELECT char_id
                     FROM char_factions
                     WHERE faction_id = ?',
                    [$id]
                )
            );
        }

        $phanconn->begin_transaction();

        try {
            if ($id > 0) {
                pf_exec(
                    $phanconn,
                    'UPDATE factions
                     SET title = ?
                     WHERE id = ?',
                    [
                        $title,
                        $id,
                    ]
                )->close();

            } else {
                $stmt = pf_exec(
                    $phanconn,
                    'INSERT INTO factions (
                        title
                     ) VALUES (?)',
                    [$title]
                );

                $id = (int)$stmt->insert_id;
                $stmt->close();
            }

            foreach (
                $affectedCharIds
                as $charId
            ) {
                pf_refresh_char_legacy_faction(
                    $phanconn,
                    $charId
                );
            }

            $phanconn->commit();

        } catch (Throwable $e) {
            $phanconn->rollback();
            throw $e;
        }

        $saved = pf_one(
            $phanconn,
            'SELECT updated_at
             FROM factions
             WHERE id = ?',
            [$id]
        );

        if ($isAjax) {
            pf_json([
                'ok' => true,
                'id' => $id,
                'updated_at' =>
                    pf_format_datetime(
                        $saved['updated_at']
                        ?? null
                    ),
            ]);
        }

        header(
            'Location: /phan/factions?id='
            . $id
            . '&saved=1',
            true,
            303
        );
        exit;

    } catch (Throwable $e) {
        if ($isAjax) {
            pf_json(
                [
                    'ok' => false,
                    'message' =>
                        $e->getMessage(),
                ],
                400
            );
        }

        $error = $e->getMessage();
    }
}


/* =========================================================
 * Daten
 * ========================================================= */

$factions = pf_all(
    $phanconn,
    'SELECT
        f.id,
        f.title,
        f.created_at,
        f.updated_at,
        COUNT(cf.char_id) AS char_count
     FROM factions f
     LEFT JOIN char_factions cf
        ON cf.faction_id = f.id
     GROUP BY
        f.id,
        f.title,
        f.created_at,
        f.updated_at
     ORDER BY f.title'
);

$id = max(
    0,
    (int)($_GET['id'] ?? 0)
);

$isNew = isset($_GET['new']);
$faction = null;
$error ??= '';
$flash = '';

if ($id > 0) {
    $faction = pf_one(
        $phanconn,
        'SELECT *
         FROM factions
         WHERE id = ?',
        [$id]
    );

    if (!$faction) {
        http_response_code(404);
        exit('Fraktion nicht gefunden.');
    }

} elseif ($isNew) {
    $faction = [
        'id' => 0,
        'title' => '',
        'created_at' => null,
        'updated_at' => null,
    ];
}

if (isset($_GET['saved'])) {
    $flash = 'Gespeichert.';
}

if (isset($_GET['deleted'])) {
    $flash =
        'Fraktion gelöscht. Die Charaktere bleiben erhalten.';
}


/* =========================================================
 * Rendering
 * ========================================================= */

$page_title = 'Fraktionen';

require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<div class="phan-page">

    <div class="phan-head">
        <h1 class="ueberschrift phan-title">
            Fraktionen
        </h1>

        <div class="phan-actions phan-actions--top">
            <button
                type="button"
                onclick="location.href='/phan/factions?new=1'"
            >
                + Fraktion
            </button>
        </div>
    </div>


    <?php if ($flash): ?>
        <div class="phan-msg">
            <?= pf_h($flash) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="phan-msg phan-error">
            <?= pf_h($error) ?>
        </div>
    <?php endif; ?>


    <div class="phan-detail">

        <div class="phan-card">
            <div class="relations-char-results">

                <?php if (!$factions): ?>
                    <div class="relations-char-empty">
                        Noch keine Fraktionen vorhanden.
                    </div>
                <?php endif; ?>


                <?php foreach ($factions as $row): ?>
                    <?php
                    $rowId = (int)$row['id'];
                    $isActive =
                        $faction
                        && $rowId
                            === (int)$faction['id'];
                    ?>

                    <button
                        type="button"
                        class="relations-char-result"
                        style="<?= $isActive
                            ? 'border-color:var(--primary);background:#fff2e5;'
                            : ''
                        ?>"
                        onclick="location.href='/phan/factions?id=<?= $rowId ?>'"
                    >
                        <span class="relations-char-avatar">
                            <?= pf_h(
                                function_exists('mb_substr')
                                    ? mb_substr(
                                        (string)$row['title'],
                                        0,
                                        1,
                                        'UTF-8'
                                    )
                                    : substr(
                                        (string)$row['title'],
                                        0,
                                        1
                                    )
                            ) ?>
                        </span>

                        <span class="relations-char-result-text">
                            <strong>
                                <?= pf_h($row['title']) ?>
                            </strong>

                            <span>
                                <?= (int)$row['char_count'] ?>
                                Charakter<?= (int)$row['char_count'] === 1 ? '' : 'e' ?>
                            </span>
                        </span>
                    </button>

                <?php endforeach; ?>

            </div>
        </div>


        <div class="phan-card">

            <?php if (!$faction): ?>

                <div class="relations-char-empty">
                    Links eine Fraktion auswählen oder eine neue Fraktion anlegen.
                </div>

            <?php else: ?>

                <form
                    method="post"
                    id="factionForm"
                    autocomplete="off"
                >
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= pf_h($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="factionId"
                        value="<?= (int)$faction['id'] ?>"
                    >


                    <div class="phan-form-grid">
                        <label class="phan-wide">
                            Name

                            <input
                                type="text"
                                name="title"
                                id="factionTitle"
                                maxlength="255"
                                value="<?= pf_h(
                                    $faction['title']
                                ) ?>"
                                placeholder="Name der Fraktion"
                            >
                        </label>
                    </div>


                    <div class="phan-bottom-actions">
                        <div class="phan-bottom-actions-left">
                            <button
                                type="button"
                                class="phan-danger"
                                id="deleteFactionButton"
                                <?= (int)$faction['id'] <= 0
                                    ? 'hidden'
                                    : ''
                                ?>
                            >
                                Fraktion löschen
                            </button>
                        </div>

                        <div class="phan-bottom-actions-right">
                            <span
                                class="phan-autosave-status"
                                id="factionAutosaveStatus"
                                aria-live="polite"
                            ></span>

                            <span class="phan-last-saved">
                                Zuletzt gespeichert am
                                <span id="factionLastSaved">
                                    <?= pf_h(
                                        pf_format_datetime(
                                            $faction['updated_at']
                                            ?? null
                                        )
                                    ) ?>
                                </span>
                            </span>
                        </div>
                    </div>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>


<script>
(() => {
    'use strict';

    const form =
        document.getElementById(
            'factionForm'
        );

    if (!form) {
        return;
    }

    const factionId =
        document.getElementById(
            'factionId'
        );

    const title =
        document.getElementById(
            'factionTitle'
        );

    const status =
        document.getElementById(
            'factionAutosaveStatus'
        );

    const lastSaved =
        document.getElementById(
            'factionLastSaved'
        );

    const deleteButton =
        document.getElementById(
            'deleteFactionButton'
        );

    let saveTimer = null;
    let saveChain = Promise.resolve();
    let statusTimer = null;


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

        status.textContent = text;
        status.classList.toggle(
            'is-error',
            isError
        );

        if (
            text === 'Gespeichert'
            || text === ''
        ) {
            statusTimer =
                window.setTimeout(
                    () => {
                        status.textContent = '';
                        status.classList.remove(
                            'is-error'
                        );
                    },
                    1200
                );
        }
    }


    function titleReady() {
        return Boolean(
            title
            && title.value.trim() !== ''
        );
    }


    function applyReturnedId(payload) {
        const returnedId =
            Number(payload?.id ?? 0);

        if (
            !Number.isInteger(returnedId)
            || returnedId <= 0
        ) {
            return;
        }

        if (Number(factionId.value) <= 0) {
            factionId.value =
                String(returnedId);

            history.replaceState(
                null,
                '',
                '/phan/factions?id='
                    + returnedId
            );

            if (deleteButton) {
                deleteButton.hidden = false;
            }
        }
    }


    async function performRequest(
        action = 'save'
    ) {
        if (
            action === 'save'
            && !titleReady()
        ) {
            setStatus(
                'Erst Namen eingeben',
                true
            );

            return {
                ok: false,
                skipped: true,
            };
        }

        const data =
            new FormData(form);

        data.set('ajax', '1');
        data.set('action', action);

        setStatus(
            action === 'delete'
                ? 'Lösche…'
                : 'Speichere…'
        );

        const response =
            await fetch(
                '/phan/factions',
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
                .catch(() => null);

        if (
            !response.ok
            || !payload?.ok
        ) {
            throw new Error(
                payload?.message
                || 'Aktion fehlgeschlagen.'
            );
        }

        applyReturnedId(payload);

        if (
            lastSaved
            && payload.updated_at
        ) {
            lastSaved.textContent =
                payload.updated_at;
        }

        if (action !== 'delete') {
            setStatus('Gespeichert');
        }

        return payload;
    }


    function queueRequest(
        action = 'save'
    ) {
        saveChain =
            saveChain
                .catch(() => {})
                .then(
                    () => performRequest(
                        action
                    )
                )
                .catch(error => {
                    setStatus(
                        error.message
                        || 'Fehler',
                        true
                    );

                    throw error;
                });

        return saveChain;
    }


    function scheduleAutosave() {
        window.clearTimeout(
            saveTimer
        );

        saveTimer =
            window.setTimeout(
                () => {
                    queueRequest('save')
                        .catch(() => {});
                },
                450
            );
    }


    title?.addEventListener(
        'input',
        scheduleAutosave
    );


    deleteButton?.addEventListener(
        'click',
        async () => {
            if (
                !confirm(
                    'Fraktion wirklich löschen? '
                    + 'Die Charaktere bleiben bestehen und verlieren nur diese Zuordnung.'
                )
            ) {
                return;
            }

            window.clearTimeout(
                saveTimer
            );

            try {
                await queueRequest(
                    'delete'
                );

                location.href =
                    '/phan/factions';

            } catch (_) {}
        }
    );

})();
</script>

</body>
</html>
