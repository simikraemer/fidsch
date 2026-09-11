#!/opt/fiji-anki/venv/bin/python
from __future__ import annotations

import fcntl
import hashlib
import json
import os
import sys
import time
import traceback
from pathlib import Path
from typing import Any

# Wichtig: Collection zuerst importieren.
# Ein direkter Import von anki.cards vor anki.collection löst in Anki 26.8.1
# einen zirkulären Import über hooks_gen aus.
from anki.collection import (
    Collection,
    ImportAnkiPackageOptions,
    ImportAnkiPackageRequest,
)
from anki.decks import DeckId
from anki.sound import SoundOrVideoTag

# ---------------------------------------------------------------------
# Konfiguration
# ---------------------------------------------------------------------

SOURCE_DIR = Path("/storage/Dokumente/RWTH/Strömungsmechanik/anki")
STATE_DIR = Path("/var/lib/fiji-anki")
COLLECTION_PATH = STATE_DIR / "collection.anki2"
MANIFEST_PATH = STATE_DIR / "imports.json"
LOCK_PATH = STATE_DIR / "collection.lock"


def respond(payload: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(payload, ensure_ascii=False))
    sys.stdout.flush()


def load_request() -> dict[str, Any]:
    raw = sys.stdin.read()
    if not raw.strip():
        return {}
    value = json.loads(raw)
    if not isinstance(value, dict):
        raise ValueError("Ungültige Anfrage.")
    return value


def load_manifest() -> dict[str, Any]:
    if not COLLECTION_PATH.exists():
        return {}

    try:
        value = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except FileNotFoundError:
        return {}
    except Exception:
        return {}


def save_manifest(manifest: dict[str, Any]) -> None:
    tmp = MANIFEST_PATH.with_suffix(".json.tmp")
    tmp.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    os.replace(tmp, MANIFEST_PATH)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def package_fingerprint(path: Path, old: dict[str, Any] | None) -> dict[str, Any]:
    stat = path.stat()
    size = int(stat.st_size)
    mtime_ns = int(stat.st_mtime_ns)

    if (
        old
        and int(old.get("size", -1)) == size
        and int(old.get("mtime_ns", -1)) == mtime_ns
        and isinstance(old.get("sha256"), str)
    ):
        digest = old["sha256"]
    else:
        digest = sha256_file(path)

    return {
        "size": size,
        "mtime_ns": mtime_ns,
        "sha256": digest,
    }


def sync_packages(col: Collection, force: bool = False) -> dict[str, Any]:
    if not SOURCE_DIR.is_dir():
        raise RuntimeError(f"Anki-Quellordner fehlt: {SOURCE_DIR}")

    manifest = load_manifest()
    new_manifest = dict(manifest)
    packages = sorted(SOURCE_DIR.glob("*.apkg"), key=lambda p: p.name.casefold())

    imported: list[dict[str, Any]] = []
    unchanged: list[str] = []

    for package in packages:
        key = str(package.resolve())
        old = manifest.get(key)
        fingerprint = package_fingerprint(package, old if isinstance(old, dict) else None)

        if not force and isinstance(old, dict) and all(
            old.get(k) == fingerprint.get(k)
            for k in ("size", "mtime_ns", "sha256")
        ):
            unchanged.append(package.name)
            continue

        result = col.import_anki_package(
            ImportAnkiPackageRequest(
                package_path=str(package),
                options=ImportAnkiPackageOptions(
                    merge_notetypes=True,
                    with_scheduling=False,
                    with_deck_configs=True,
                ),
            )
        )

        log = result.log
        imported.append(
            {
                "file": package.name,
                "new": len(log.new),
                "updated": len(log.updated),
                "duplicate": len(log.duplicate),
                "conflicting": len(log.conflicting),
            }
        )

        new_manifest[key] = {
            **fingerprint,
            "file": package.name,
            "imported_at": int(time.time()),
        }

    existing_keys = {str(p.resolve()) for p in packages}
    missing = [
        data.get("file", Path(key).name)
        for key, data in manifest.items()
        if key not in existing_keys and isinstance(data, dict)
    ]

    save_manifest(new_manifest)

    return {
        "source_dir": str(SOURCE_DIR),
        "files": [p.name for p in packages],
        "imported": imported,
        "unchanged": unchanged,
        "missing_sources": missing,
    }


def deck_rows(col: Collection) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []

    for deck in col.decks.all_names_and_ids(
        skip_empty_default=True,
        include_filtered=False,
    ):
        deck_id = DeckId(int(deck.id))
        total = int(col.decks.card_count(deck_id, include_subdecks=True))
        if total <= 0:
            continue

        rows.append(
            {
                "id": int(deck.id),
                "name": str(deck.name),
                "total": total,
                "depth": str(deck.name).count("::"),
            }
        )

    rows.sort(key=lambda row: row["name"].casefold())
    return rows


def deck_name_map(col: Collection) -> dict[int, str]:
    return {
        int(deck.id): str(deck.name)
        for deck in col.decks.all_names_and_ids(
            skip_empty_default=False,
            include_filtered=True,
        )
    }


def av_filenames(tags: list[Any]) -> list[str]:
    out: list[str] = []
    for tag in tags:
        if isinstance(tag, SoundOrVideoTag):
            name = str(tag.filename)
            if name and name not in out:
                out.append(name)
    return out


def card_payload(col: Collection, card: Any) -> dict[str, Any]:
    deck_names = deck_name_map(col)
    note = card.note()

    intervals: dict[str, str] = {}
    for ease in (1, 2, 3, 4):
        try:
            intervals[str(ease)] = str(col.sched.nextIvlStr(card, ease))
        except Exception:
            intervals[str(ease)] = "–"

    return {
        "id": int(card.id),
        "deck_id": int(card.current_deck_id()),
        "deck_name": deck_names.get(int(card.current_deck_id()), ""),
        "question_html": card.question(),
        "answer_html": card.answer(),
        "question_audio": av_filenames(card.question_av_tags()),
        "answer_audio": av_filenames(card.answer_av_tags()),
        "intervals": intervals,
        "tags": list(note.tags),
        "reps": int(card.reps),
        "lapses": int(card.lapses),
    }


def select_deck(col: Collection, deck_id: int) -> None:
    valid = {row["id"] for row in deck_rows(col)}
    if deck_id not in valid:
        raise RuntimeError("Unbekanntes Anki-Deck.")
    col.decks.select(DeckId(deck_id))


def state_payload(col: Collection, deck_id: int) -> dict[str, Any]:
    select_deck(col, deck_id)
    new_count, learning_count, review_count = col.sched.counts()
    card = col.sched.getCard()

    return {
        "deck_id": deck_id,
        "counts": {
            "new": int(new_count),
            "learning": int(learning_count),
            "review": int(review_count),
            "due": int(new_count) + int(learning_count) + int(review_count),
        },
        "card": card_payload(col, card) if card else None,
    }


def initial_deck_id(col: Collection, requested: Any = None) -> int | None:
    decks = deck_rows(col)
    if not decks:
        return None

    ids = {row["id"] for row in decks}

    try:
        requested_id = int(requested)
        if requested_id in ids:
            return requested_id
    except (TypeError, ValueError):
        pass

    try:
        current = int(col.decks.get_current_id())
        if current in ids:
            return current
    except Exception:
        pass

    return int(decks[0]["id"])


def handle(col: Collection, request: dict[str, Any]) -> dict[str, Any]:
    action = str(request.get("action", "init"))

    if action in {"init", "sync"}:
        sync_result = sync_packages(col, force=bool(request.get("force", False)))
        decks = deck_rows(col)
        selected = initial_deck_id(col, request.get("deck_id"))

        state = (
            state_payload(col, selected)
            if selected is not None
            else {
                "deck_id": None,
                "counts": {"new": 0, "learning": 0, "review": 0, "due": 0},
                "card": None,
            }
        )

        return {
            "ok": True,
            "action": action,
            "decks": decks,
            "sync": sync_result,
            **state,
        }

    if action == "deck":
        deck_id = int(request.get("deck_id", 0))
        return {
            "ok": True,
            "action": action,
            "decks": deck_rows(col),
            **state_payload(col, deck_id),
        }

    if action == "answer":
        deck_id = int(request.get("deck_id", 0))
        card_id = int(request.get("card_id", 0))
        ease = int(request.get("ease", 0))
        elapsed_ms = max(0, min(3_600_000, int(request.get("elapsed_ms", 0))))

        if ease not in (1, 2, 3, 4):
            raise RuntimeError("Ungültige Bewertung.")

        select_deck(col, deck_id)
        card = col.get_card(card_id)

        # Legacy convenience API uses the timer to write review duration.
        card.timer_started = time.time() - (elapsed_ms / 1000.0)
        col.sched.answerCard(card, ease)

        return {
            "ok": True,
            "action": action,
            "decks": deck_rows(col),
            **state_payload(col, deck_id),
        }

    raise RuntimeError(f"Unbekannte Aktion: {action}")


def main() -> int:
    STATE_DIR.mkdir(parents=True, exist_ok=True)

    with LOCK_PATH.open("a+b") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)

        request = load_request()
        col: Collection | None = None

        try:
            col = Collection(str(COLLECTION_PATH))
            result = handle(col, request)
            respond(result)
            return 0
        finally:
            if col is not None:
                col.close(downgrade=False)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        respond(
            {
                "ok": False,
                "message": str(exc),
                "exception": exc.__class__.__name__,
                "trace": traceback.format_exc(limit=8),
            }
        )
        raise SystemExit(1)
