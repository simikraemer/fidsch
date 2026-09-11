(() => {
    'use strict';

    document.body.classList.add('anki-page-active');

    const cfg = window.FIJI_ANKI || {};
    const API_URL = cfg.apiUrl || window.location.pathname;
    const CSRF = cfg.csrf || '';

    const els = {
        deck: document.getElementById('ankiDeckSelect'),
        sync: document.getElementById('ankiSyncBtn'),
        syncInfo: document.getElementById('ankiSyncInfo'),

        countNew: document.getElementById('ankiCountNew'),
        countLearning: document.getElementById('ankiCountLearning'),
        countReview: document.getElementById('ankiCountReview'),
        countDue: document.getElementById('ankiCountDue'),

        card: document.getElementById('ankiCard'),
        cardDeck: document.getElementById('ankiCardDeck'),
        cardMeta: document.getElementById('ankiCardMeta'),
        tags: document.getElementById('ankiCardTags'),

        frame: document.getElementById('ankiCardFrame'),
        cardScroll: document.getElementById('ankiCardScroll'),

        reveal: document.getElementById('ankiRevealBtn'),
        ratings: document.getElementById('ankiRatingGrid'),
        ratingButtons: Array.from(
            document.querySelectorAll('[data-ease]')
        ),

        empty: document.getElementById('ankiEmpty'),
        status: document.getElementById('ankiStatus')
    };

    const state = {
        deckId: null,
        card: null,
        answerShown: false,
        locked: false,
        startedAt: performance.now()
    };

    async function api(payload) {
        const response = await fetch(API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json;charset=UTF-8'
            },
            body: JSON.stringify({
                ...payload,
                csrf: CSRF
            })
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(
                data.message || 'Anki-Fehler.'
            );
        }

        return data;
    }

    function mediaUrl(filename) {
        return (
            `${API_URL}?action=media&file=` +
            encodeURIComponent(filename)
        );
    }

    function isLocalMedia(value) {
        if (!value) {
            return false;
        }

        const v = String(value).trim();

        if (!v) {
            return false;
        }

        if (
            /^(?:data:|blob:|https?:|ftp:|mailto:|javascript:|#|\/\/)/i
                .test(v)
        ) {
            return false;
        }

        return true;
    }

    function normalizeMediaName(value) {
        try {
            return decodeURIComponent(
                String(value).replace(/^\.\//, '')
            );
        } catch {
            return String(value).replace(/^\.\//, '');
        }
    }

    function rewriteCssUrls(css) {
        return String(css).replace(
            /url\(\s*(['"]?)(.*?)\1\s*\)/gi,
            (full, quote, raw) => {
                const value = String(raw || '').trim();

                if (!isLocalMedia(value)) {
                    if (
                        /^(?:https?:|ftp:|\/\/)/i
                            .test(value)
                    ) {
                        return 'url("")';
                    }

                    return full;
                }

                const name = normalizeMediaName(value);

                if (
                    name.includes('/') ||
                    name.includes('\\')
                ) {
                    return 'url("")';
                }

                return (
                    `url("${mediaUrl(name)
                        .replaceAll('"', '%22')}")`
                );
            }
        );
    }

    function sanitizeAndRewrite(
        rawHtml,
        audioFiles
    ) {
        const parser = new DOMParser();

        const doc = parser.parseFromString(
            `<div id="anki-root">${rawHtml || ''}</div>`,
            'text/html'
        );

        const root =
            doc.getElementById('anki-root');

        root
            .querySelectorAll(
                'script, iframe, object, embed, form, meta, link'
            )
            .forEach(el => el.remove());

        root
            .querySelectorAll('*')
            .forEach(el => {
                Array
                    .from(el.attributes)
                    .forEach(attr => {
                        const name =
                            attr.name.toLowerCase();

                        const value =
                            attr.value || '';

                        if (
                            name.startsWith('on')
                        ) {
                            el.removeAttribute(
                                attr.name
                            );
                            return;
                        }

                        if (
                            name === 'style'
                        ) {
                            el.setAttribute(
                                'style',
                                rewriteCssUrls(value)
                            );
                            return;
                        }

                        if (
                            ['src', 'poster']
                                .includes(name)
                        ) {
                            if (
                                isLocalMedia(value)
                            ) {
                                const file =
                                    normalizeMediaName(
                                        value
                                    );

                                if (
                                    !file.includes('/') &&
                                    !file.includes('\\')
                                ) {
                                    el.setAttribute(
                                        attr.name,
                                        mediaUrl(file)
                                    );
                                } else {
                                    el.removeAttribute(
                                        attr.name
                                    );
                                }
                            } else if (
                                /^(?:https?:|ftp:|\/\/)/i
                                    .test(value)
                            ) {
                                el.removeAttribute(
                                    attr.name
                                );
                            }

                            return;
                        }

                        if (
                            name === 'href'
                        ) {
                            if (
                                /^javascript:/i
                                    .test(value)
                            ) {
                                el.removeAttribute(
                                    attr.name
                                );
                            } else if (
                                /^https?:/i
                                    .test(value)
                            ) {
                                el.setAttribute(
                                    'target',
                                    '_blank'
                                );

                                el.setAttribute(
                                    'rel',
                                    'noopener noreferrer'
                                );
                            } else if (
                                isLocalMedia(value)
                            ) {
                                const file =
                                    normalizeMediaName(
                                        value
                                    );

                                if (
                                    !file.includes('/') &&
                                    !file.includes('\\')
                                ) {
                                    el.setAttribute(
                                        'href',
                                        mediaUrl(file)
                                    );
                                }
                            }
                        }
                    });
            });

        root
            .querySelectorAll('style')
            .forEach(style => {
                style.textContent =
                    rewriteCssUrls(
                        style.textContent || ''
                    );
            });

        root.innerHTML =
            root.innerHTML
                .replace(
                    /\[anki:play:[qa]:\d+\]/gi,
                    ''
                )
                .replace(
                    /\[sound:([^\]]+)\]/gi,
                    ''
                );

        if (
            Array.isArray(audioFiles) &&
            audioFiles.length
        ) {
            const audioWrap =
                doc.createElement('div');

            audioWrap.className =
                'fiji-anki-audio';

            audioFiles.forEach(
                filename => {
                    if (
                        !filename ||
                        filename.includes('/') ||
                        filename.includes('\\')
                    ) {
                        return;
                    }

                    const audio =
                        doc.createElement('audio');

                    audio.controls = true;
                    audio.preload = 'metadata';
                    audio.src =
                        mediaUrl(filename);

                    audioWrap.appendChild(
                        audio
                    );
                }
            );

            root.appendChild(
                audioWrap
            );
        }

        return root.innerHTML;
    }

    function cardDocument(
        html,
        audioFiles
    ) {
        const safe =
            sanitizeAndRewrite(
                html,
                audioFiles
            );

        return `<!doctype html>
<html lang="de">
<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<style>
html,
body {
    margin: 0;
    padding: 0;
    background: transparent;
    color: #222;
}

body {
    box-sizing: border-box;
    padding: 20px 22px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 22px;
    line-height: 1.45;
    text-align: center;
}

img {
    max-width: 100%;
    height: auto;
}

video {
    max-width: 100%;
    height: auto;
}

audio {
    max-width: 100%;
}

table {
    max-width: 100%;
    margin-left: auto;
    margin-right: auto;
}

.fiji-anki-audio {
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: center;
    margin-top: 18px;
}
</style>

<script>
window.MathJax = {
    tex: {
        inlineMath: [
            ['\\\\(', '\\\\)'],
            ['$', '$']
        ],
        displayMath: [
            ['\\\\[', '\\\\]'],
            ['$$', '$$']
        ],
        processEscapes: true
    },
    svg: {
        fontCache: 'global'
    }
};
<\/script>

<script
    defer
    src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js"
><\/script>

</head>

<body class="card">

${safe}

<script>
(() => {
    const send = () => {
        parent.postMessage(
            {
                type: 'fiji-anki-height',
                height: Math.max(
                    document.documentElement.scrollHeight,
                    document.body.scrollHeight
                )
            },
            '*'
        );
    };

    new MutationObserver(send)
        .observe(
            document.body,
            {
                subtree: true,
                childList: true,
                attributes: true
            }
        );

    window.addEventListener(
        'load',
        () => {
            send();
            setTimeout(send, 300);
            setTimeout(send, 1200);
        }
    );

    send();
})();
<\/script>

</body>
</html>`;
    }

    function showStatus(
        message,
        kind = ''
    ) {
        els.status.className =
            'anki-status';

        if (kind) {
            els.status.classList.add(
                `is-${kind}`
            );
        }

        els.status.textContent =
            message;
    }

    function hideStatus() {
        els.status.className =
            'anki-status hidden';

        els.status.textContent =
            '';
    }

    function prettyDeckName(name) {
        return String(name || '')
            .replaceAll(
                '::',
                ' › '
            );
    }

    function resetCardScroll() {
        if (els.cardScroll) {
            els.cardScroll.scrollTop = 0;
        }
    }

    function renderDecks(
        decks,
        selectedId
    ) {
        els.deck.innerHTML = '';

        if (
            !Array.isArray(decks) ||
            !decks.length
        ) {
            const option =
                document.createElement(
                    'option'
                );

            option.textContent =
                'Keine Decks';

            option.value = '';

            els.deck.appendChild(
                option
            );

            els.deck.disabled =
                true;

            return;
        }

        decks.forEach(deck => {
            const option =
                document.createElement(
                    'option'
                );

            option.value =
                String(deck.id);

            option.textContent =
                `${prettyDeckName(deck.name)} (${deck.total})`;

            option.selected =
                Number(deck.id) ===
                Number(selectedId);

            els.deck.appendChild(
                option
            );
        });

        els.deck.disabled =
            false;
    }

    function renderCounts(counts) {
        const c =
            counts || {};

        els.countNew.textContent =
            String(c.new ?? 0);

        els.countLearning.textContent =
            String(c.learning ?? 0);

        els.countReview.textContent =
            String(c.review ?? 0);

        els.countDue.textContent =
            String(c.due ?? 0);
    }

    function renderIntervals(card) {
        const values =
            card?.intervals || {};

        els.ratingButtons
            .forEach(button => {
                const ease =
                    String(
                        button.dataset.ease || ''
                    );

                const small =
                    button.querySelector(
                        'small'
                    );

                if (small) {
                    small.textContent =
                        values[ease] || '–';
                }
            });
    }

    function renderCard(card) {
        state.card =
            card || null;

        state.answerShown =
            false;

        state.locked =
            false;

        state.startedAt =
            performance.now();

        els.ratings.classList.add(
            'hidden'
        );

        els.reveal.classList.remove(
            'hidden'
        );

        els.ratingButtons
            .forEach(btn => {
                btn.disabled = false;
            });

        if (!card) {
            els.card.classList.add(
                'hidden'
            );

            els.empty.classList.remove(
                'hidden'
            );

            els.frame.removeAttribute(
                'srcdoc'
            );

            return;
        }

        els.empty.classList.add(
            'hidden'
        );

        els.card.classList.remove(
            'hidden'
        );

        els.cardDeck.textContent =
            prettyDeckName(
                card.deck_name
            );

        els.cardMeta.textContent =
            `Wiederholungen: ${card.reps ?? 0} · Fehler: ${card.lapses ?? 0}`;

        els.tags.innerHTML = '';

        (card.tags || [])
            .forEach(tag => {
                const span =
                    document.createElement(
                        'span'
                    );

                span.textContent =
                    tag;

                els.tags.appendChild(
                    span
                );
            });

        renderIntervals(card);

        resetCardScroll();

        els.frame.srcdoc =
            cardDocument(
                card.question_html,
                card.question_audio
            );
    }

    function renderSyncInfo(sync) {
        if (!sync) {
            els.syncInfo.textContent =
                '';
            return;
        }

        const imported =
            Array.isArray(
                sync.imported
            )
                ? sync.imported
                : [];

        const files =
            Array.isArray(
                sync.files
            )
                ? sync.files
                : [];

        if (imported.length) {
            const details =
                imported
                    .map(item =>
                        `${item.file}: ${item.new} neu, ${item.updated} aktualisiert`
                    )
                    .join(' · ');

            els.syncInfo.textContent =
                `APKG eingelesen: ${details}`;
        } else {
            els.syncInfo.textContent =
                `${files.length} APKG-Datei${files.length === 1 ? '' : 'en'} gefunden · unverändert`;
        }
    }

    function applyResponse(data) {
        state.deckId =
            data.deck_id == null
                ? null
                : Number(
                    data.deck_id
                );

        renderDecks(
            data.decks,
            state.deckId
        );

        renderCounts(
            data.counts
        );

        renderSyncInfo(
            data.sync
        );

        renderCard(
            data.card
        );
    }

    async function loadInitial() {
        hideStatus();

        state.locked = true;

        try {
            const remembered =
                Number(
                    localStorage.getItem(
                        'fiji-anki-deck-id'
                    ) || 0
                ) || null;

            const data =
                await api({
                    action: 'init',
                    deck_id: remembered
                });

            applyResponse(
                data
            );

            if (state.deckId) {
                localStorage.setItem(
                    'fiji-anki-deck-id',
                    String(
                        state.deckId
                    )
                );
            }
        } catch (error) {
            showStatus(
                error.message,
                'error'
            );

            els.card.classList.add(
                'hidden'
            );

            els.empty.classList.add(
                'hidden'
            );
        } finally {
            state.locked =
                false;
        }
    }

    async function changeDeck() {
        const deckId =
            Number(
                els.deck.value || 0
            );

        if (
            !deckId ||
            state.locked
        ) {
            return;
        }

        state.locked =
            true;

        els.deck.disabled =
            true;

        hideStatus();

        try {
            const data =
                await api({
                    action: 'deck',
                    deck_id: deckId
                });

            applyResponse(
                data
            );

            localStorage.setItem(
                'fiji-anki-deck-id',
                String(deckId)
            );
        } catch (error) {
            showStatus(
                error.message,
                'error'
            );
        } finally {
            state.locked =
                false;

            els.deck.disabled =
                false;
        }
    }

    function revealAnswer() {
        if (
            !state.card ||
            state.answerShown ||
            state.locked
        ) {
            return;
        }

        state.answerShown =
            true;

        resetCardScroll();

        els.frame.srcdoc =
            cardDocument(
                state.card.answer_html,
                state.card.answer_audio
            );

        els.reveal.classList.add(
            'hidden'
        );

        els.ratings.classList.remove(
            'hidden'
        );
    }

    async function answer(ease) {
        if (
            !state.card ||
            !state.answerShown ||
            state.locked
        ) {
            return;
        }

        state.locked =
            true;

        els.ratingButtons
            .forEach(btn => {
                btn.disabled = true;
            });

        hideStatus();

        try {
            const data =
                await api({
                    action: 'answer',
                    deck_id:
                        state.deckId,
                    card_id:
                        state.card.id,
                    ease,
                    elapsed_ms:
                        Math.round(
                            performance.now() -
                            state.startedAt
                        )
                });

            applyResponse(
                data
            );
        } catch (error) {
            showStatus(
                error.message,
                'error'
            );

            state.locked =
                false;

            els.ratingButtons
                .forEach(btn => {
                    btn.disabled = false;
                });

            return;
        }

        state.locked =
            false;
    }

    async function forceSync() {
        if (state.locked) {
            return;
        }

        state.locked =
            true;

        els.sync.disabled =
            true;

        hideStatus();

        els.syncInfo.textContent =
            'APKG-Dateien werden eingelesen …';

        try {
            const data =
                await api({
                    action: 'sync',
                    deck_id:
                        state.deckId,
                    force: true
                });

            applyResponse(
                data
            );

            showStatus(
                'APKG-Dateien wurden neu eingelesen.',
                'good'
            );
        } catch (error) {
            showStatus(
                error.message,
                'error'
            );
        } finally {
            state.locked =
                false;

            els.sync.disabled =
                false;
        }
    }

    els.deck.addEventListener(
        'change',
        changeDeck
    );

    els.reveal.addEventListener(
        'click',
        revealAnswer
    );

    els.sync.addEventListener(
        'click',
        forceSync
    );

    els.ratingButtons
        .forEach(button => {
            button.addEventListener(
                'click',
                () => {
                    answer(
                        Number(
                            button.dataset.ease
                        )
                    );
                }
            );
        });

    document.addEventListener(
        'keydown',
        event => {
            if (
                state.locked ||
                !state.card
            ) {
                return;
            }

            const tag =
                String(
                    document
                        .activeElement
                        ?.tagName || ''
                )
                    .toLowerCase();

            if (
                [
                    'input',
                    'textarea',
                    'select'
                ].includes(tag)
            ) {
                return;
            }

            if (
                !state.answerShown &&
                (
                    event.code === 'Space' ||
                    event.code === 'Enter'
                )
            ) {
                event.preventDefault();

                revealAnswer();

                return;
            }

            if (
                state.answerShown &&
                [
                    'Digit1',
                    'Digit2',
                    'Digit3',
                    'Digit4'
                ].includes(
                    event.code
                )
            ) {
                event.preventDefault();

                answer(
                    Number(
                        event.code.slice(-1)
                    )
                );
            }
        }
    );

    window.addEventListener(
        'message',
        event => {
            if (
                event.source !==
                els.frame.contentWindow
            ) {
                return;
            }

            const data =
                event.data || {};

            if (
                data.type !==
                'fiji-anki-height'
            ) {
                return;
            }

            const height =
                Math.max(
                    280,
                    Math.min(
                        12000,
                        Number(
                            data.height || 0
                        ) + 10
                    )
                );

            if (
                Number.isFinite(
                    height
                )
            ) {
                els.frame.style.height =
                    `${height}px`;
            }
        }
    );

    loadInitial();
})();