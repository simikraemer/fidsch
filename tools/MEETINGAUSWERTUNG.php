<?php
$page_title = 'Kahoot Titel-Auswertung';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
  .kq-page{
    max-width:1200px;
    margin:30px auto 70px;
    padding:0 16px 40px;
  }

  .kahoot-wrap{
    display:flex;
    flex-direction:column;
    gap:16px;
  }

  .kahoot-panel,
  .kahoot-card,
  .kahoot-stat,
  .kahoot-qualifier,
  .kahoot-status{
    background:#fff;
    border-radius:var(--border-radius);
    box-shadow:var(--shadow);
  }

  .kahoot-panel{
    padding:16px 18px;
  }

  .kahoot-dropzone{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:155px;
    text-align:center;
    border:2px dashed rgba(0,0,0,.16);
    border-radius:var(--border-radius);
    background:var(--bg-light);
    cursor:pointer;
    user-select:none;
    padding:18px;
    transition:background-color .15s ease, border-color .15s ease, transform .1s ease;
  }

  .kahoot-dropzone:hover,
  .kahoot-dropzone.is-dragover{
    border-color:var(--primary);
    background:#fff2e5;
    transform:translateY(-1px);
  }

  .kahoot-dropicon{
    font-size:2.1rem;
    line-height:1;
  }

  .kahoot-title{
    font-weight:800;
    font-size:1.12rem;
    margin:0;
    color:var(--text);
  }

  .kahoot-sub{
    margin:0;
    color:rgba(34,34,34,.72);
    line-height:1.45;
  }

  .kahoot-hidden{
    display:none !important;
  }

  .kahoot-summary{
    display:grid;
    grid-template-columns:repeat(4, minmax(0,1fr));
    gap:12px;
  }

  .kahoot-stat{
    padding:12px 14px;
    background:var(--bg-light);
    border:1px solid rgba(0,0,0,.08);
  }

  .kahoot-statlabel{
    font-size:.9rem;
    font-weight:600;
    color:rgba(34,34,34,.72);
    margin-bottom:4px;
  }

  .kahoot-statvalue{
    font-size:1.2rem;
    font-weight:800;
    color:var(--text);
    line-height:1.15;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    font-variant-numeric:tabular-nums;
  }

  .kahoot-actions{
    display:flex;
    gap:10px;
    align-items:stretch;
    flex-wrap:wrap;
  }

  .kahoot-actions .kahoot-mainbtn{
    flex:2 1 260px;
    justify-content:center;
  }

  .kahoot-actions .kahoot-secondarybtn{
    flex:1 1 210px;
    justify-content:center;
  }

  .kahoot-secondary{
    background:#eee;
    color:#333;
  }

  .kahoot-secondary:hover{
    background:#e5e5e5;
  }

  .kahoot-danger{
    background:#fff;
    color:#c0392b;
    border:2px solid rgba(192,57,43,.55);
    box-shadow:none;
  }

  .kahoot-danger:hover{
    background:#c0392b;
    color:#fff;
    border-color:#c0392b;
  }

  .kahoot-status{
    min-height:46px;
    display:flex;
    align-items:center;
    padding:10px 14px;
    border-left:6px solid var(--primary);
    font-weight:600;
  }

  .kahoot-status.is-busy{
    border-left-color:var(--accent);
    background:#fff7ee;
  }

  .kahoot-status.is-error{
    border-left-color:#c0392b;
    background:#fff4f2;
    color:#a93226;
  }

  .kahoot-note{
    color:rgba(34,34,34,.78);
    line-height:1.5;
  }

  .kahoot-filebox{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:12px;
    align-items:center;
  }

  .kahoot-filename{
    font-weight:800;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }

  .kahoot-meta{
    margin-top:3px;
    font-size:.95rem;
    color:rgba(34,34,34,.72);
  }

  .kahoot-optionrow{
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:12px;
  }

  .kahoot-checkbox{
    display:inline-flex;
    gap:8px;
    align-items:center;
    cursor:pointer;
    user-select:none;
    font-weight:700;
  }

  .kahoot-checkbox input{
    accent-color:var(--primary);
  }

  .kahoot-results{
    display:grid;
    grid-template-columns:repeat(2, minmax(0,1fr));
    gap:16px;
  }

  .kahoot-card{
    overflow:hidden;
  }

  .kahoot-cardhead{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:12px;
    align-items:start;
    padding:14px 16px;
    border-bottom:1px solid rgba(0,0,0,.08);
    background:var(--bg-light);
  }

  .kahoot-cardtitle{
    margin:0;
    font-size:1.08rem;
    line-height:1.25;
    font-weight:800;
    color:var(--text);
  }

  .kahoot-cardsub{
    margin:4px 0 0 0;
    color:rgba(34,34,34,.72);
    font-size:.94rem;
    line-height:1.35;
  }

  .kahoot-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:26px;
    padding:3px 9px;
    border-radius:999px;
    background:var(--primary-dark);
    color:var(--text-light);
    font-size:.86rem;
    font-weight:800;
    white-space:nowrap;
    box-shadow:var(--shadow);
  }

  .kahoot-badge.is-winner{
    background:var(--primary);
    color:var(--text-light);
  }

  .kahoot-tablewrap{
    overflow:auto;
  }

  .kahoot-table{
    width:100%;
    border-collapse:collapse;
    font-family:var(--font);
    font-size:.95rem;
    background:#fff;
  }

  .kahoot-table th,
  .kahoot-table td{
    padding:11px 14px;
    text-align:left;
    border-bottom:1px solid #eee;
    vertical-align:middle;
  }

  .kahoot-table th{
    background:var(--accent);
    color:var(--text);
    font-weight:800;
    font-size:.86rem;
  }

  .kahoot-table tbody tr:hover{
    background:#fff2e5;
  }

  .kahoot-table tr:last-child td{
    border-bottom:0;
  }

  .kahoot-rank{
    width:52px;
    font-weight:800;
    font-variant-numeric:tabular-nums;
  }

  .kahoot-score{
    width:100px;
    text-align:right !important;
    font-weight:800;
    white-space:nowrap;
    font-variant-numeric:tabular-nums;
  }

  .kahoot-player{
    min-width:180px;
    font-weight:700;
  }

  .kahoot-muted{
    color:#777;
    font-size:.85rem;
    font-weight:600;
    margin-top:2px;
  }

  .kahoot-qualifiers{
    display:grid;
    grid-template-columns:repeat(3, minmax(0,1fr));
    gap:12px;
    margin-top:12px;
  }

  .kahoot-qualifier{
    padding:12px 14px;
    border-left:6px solid var(--primary);
  }

  .kahoot-qualtitle{
    font-size:.9rem;
    color:rgba(34,34,34,.72);
    font-weight:800;
    margin-bottom:3px;
  }

  .kahoot-qualname{
    font-size:1.08rem;
    font-weight:800;
    color:var(--text);
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }

  .kahoot-qualscore{
    margin-top:3px;
    color:rgba(34,34,34,.72);
    font-size:.95rem;
  }

  .kahoot-empty{
    padding:18px;
    color:#777;
    text-align:center;
  }

  @media (max-width:900px){
    .kahoot-results,
    .kahoot-qualifiers{
      grid-template-columns:1fr;
    }
  }

  @media (max-width:700px){
    .kq-page{
      padding-left:12px;
      padding-right:12px;
    }

    .kahoot-summary{
      grid-template-columns:1fr 1fr;
    }

    .kahoot-filebox{
      grid-template-columns:1fr;
    }
  }

  @media (max-width:520px){
    .kahoot-summary{
      grid-template-columns:1fr;
    }
  }
</style>

<div class="content-wrap kq-page">
  <div class="kahoot-wrap">
    <div class="kahoot-panel">
      <label id="dropzone" class="kahoot-dropzone" for="xlsxInput">
        <div class="kahoot-dropicon">🏆</div>
        <p class="kahoot-title">Kahoot-Auswertung hochladen</p>
        <p class="kahoot-sub">
          Das XLSX-Report-File aus Kahoot auswählen oder hier hineinziehen. Die Auswertung läuft lokal im Browser.
        </p>
      </label>
      <input id="xlsxInput" type="file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="kahoot-hidden">

      <div class="kahoot-optionrow">
        <label class="kahoot-checkbox" title="Wenn dieselbe Person mehrere Titel gewinnt, wird für spätere Titel die nächste noch nicht qualifizierte Person genommen.">
          <input id="uniqueFinalists" type="checkbox" checked>
          Finale mit eindeutigen Personen bilden
        </label>
      </div>
    </div>

    <div class="kahoot-summary">
      <div class="kahoot-stat">
        <div class="kahoot-statlabel">Spieler</div>
        <div id="statPlayers" class="kahoot-statvalue">0</div>
      </div>
      <div class="kahoot-stat">
        <div class="kahoot-statlabel">Gewertete Fragen</div>
        <div id="statQuestions" class="kahoot-statvalue">0</div>
      </div>
      <div class="kahoot-stat">
        <div class="kahoot-statlabel">Titel</div>
        <div id="statTitles" class="kahoot-statvalue">6</div>
      </div>
      <div class="kahoot-stat">
        <div class="kahoot-statlabel">Datei</div>
        <div id="statFile" class="kahoot-statvalue">-</div>
      </div>
    </div>

    <div id="filePanel" class="kahoot-panel kahoot-hidden">
      <div class="kahoot-filebox">
        <div>
          <div id="fileName" class="kahoot-filename"></div>
          <div id="fileMeta" class="kahoot-meta"></div>
        </div>
        <button id="clearBtn" type="button" class="kahoot-secondarybtn kahoot-danger">Auswertung leeren</button>
      </div>
    </div>

    <div id="qualifierPanel" class="kahoot-hidden">
      <div class="kahoot-panel">
        <h2 class="kahoot-cardtitle">Finalisten</h2>
      </div>
      <div id="qualifierList" class="kahoot-qualifiers"></div>
    </div>

    <div id="results" class="kahoot-results"></div>

    <div class="kahoot-actions">
      <button id="loadBtn" type="button" class="kahoot-mainbtn">XLSX auswählen</button>
      <button id="demoMapBtn" type="button" class="kahoot-secondarybtn kahoot-secondary">Kategorie-Mapping anzeigen</button>
    </div>

    <div id="mappingPanel" class="kahoot-panel kahoot-hidden">
      <div class="kahoot-note" id="mappingText"></div>
    </div>

    <div id="statusBox" class="kahoot-status" aria-live="polite">
      Bereit.
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(() => {
  const $ = (id) => document.getElementById(id);

  const TITLES = [
    {
      id: "lokalpatriot",
      title: "Lokalpatriot",
      subtitle: "RWTH & Aachen",
      ranges: [[3, 7], [33, 37]],
      description: "Fragen 3-7 und 33-37"
    },
    {
      id: "vibechecker",
      title: "Vibe-Checker",
      subtitle: "Musik & Emojis",
      ranges: [[9, 13], [39, 43]],
      description: "Fragen 9-13 und 39-43"
    },
    {
      id: "schlaumeier",
      title: "Schlaumeier",
      subtitle: "Geographie & Informatik",
      ranges: [[15, 19], [45, 49]],
      description: "Fragen 15-19 und 45-49"
    },
    {
      id: "fitnessfreak",
      title: "Fitnessfreak",
      subtitle: "Ernährung & Sport",
      ranges: [[21, 25], [51, 55]],
      description: "Fragen 21-25 und 51-55"
    },
    {
      id: "schamane",
      title: "Schamane",
      subtitle: "Tiere & Mythologie",
      ranges: [[27, 31], [57, 61]],
      description: "Fragen 27-31 und 57-61"
    }
  ];

  const ALLROUNDER = {
    id: "allrounder",
    title: "Allrounder",
    subtitle: "Höchste Gesamtpunktzahl",
    description: "Final Scores / Total score (points)"
  };

  const xlsxInput = $("xlsxInput");
  const dropzone = $("dropzone");
  const uniqueFinalists = $("uniqueFinalists");
  const statPlayers = $("statPlayers");
  const statQuestions = $("statQuestions");
  const statFile = $("statFile");
  const filePanel = $("filePanel");
  const fileName = $("fileName");
  const fileMeta = $("fileMeta");
  const clearBtn = $("clearBtn");
  const qualifierPanel = $("qualifierPanel");
  const qualifierList = $("qualifierList");
  const results = $("results");
  const loadBtn = $("loadBtn");
  const demoMapBtn = $("demoMapBtn");
  const mappingPanel = $("mappingPanel");
  const mappingText = $("mappingText");
  const statusBox = $("statusBox");

  let busy = false;
  let currentEvaluation = null;

  function setStatus(text, mode = ""){
    statusBox.textContent = text;
    statusBox.classList.toggle("is-busy", mode === "busy");
    statusBox.classList.toggle("is-error", mode === "error");
  }

  function formatBytes(bytes){
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  }

  function escapeHtml(str){
    return String(str ?? "").replace(/[&<>"']/g, (ch) => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#039;'
    }[ch]));
  }

  function normalizeHeader(value){
    return String(value ?? "")
      .replace(/<[^>]*>/g, "")
      .replace(/\s+/g, " ")
      .trim()
      .toLowerCase();
  }

  function toNumber(value){
    if (typeof value === "number" && Number.isFinite(value)) return value;
    if (typeof value === "boolean") return value ? 1 : 0;

    const text = String(value ?? "")
      .trim()
      .replace(/\s/g, "")
      .replace(/\.(?=\d{3}(\D|$))/g, "")
      .replace(",", ".");

    if (!text || text === "-" || text.toLowerCase() === "nan") return 0;

    const num = Number(text);
    return Number.isFinite(num) ? num : 0;
  }

  function toQuestionNumber(value){
    const num = toNumber(value);
    return Number.isFinite(num) ? Math.trunc(num) : null;
  }

  function cleanIdentifier(value){
    const text = String(value ?? "").trim();
    return text && text !== "-" ? text : "";
  }

  function playerKey(player, identifier){
    const cleanPlayer = String(player ?? "").trim();
    const cleanId = cleanIdentifier(identifier);
    return cleanId ? `${cleanPlayer}||${cleanId}` : cleanPlayer;
  }

  function getSheet(workbook, preferredNames){
    const names = workbook.SheetNames || [];

    for (const preferred of preferredNames) {
      const exact = names.find((name) => name === preferred);
      if (exact) return workbook.Sheets[exact];
    }

    const normalizedPreferred = preferredNames.map(normalizeHeader);
    const fuzzy = names.find((name) => normalizedPreferred.includes(normalizeHeader(name)));
    return fuzzy ? workbook.Sheets[fuzzy] : null;
  }

  function sheetToRows(sheet){
    return XLSX.utils.sheet_to_json(sheet, {
      header: 1,
      raw: true,
      defval: "",
      blankrows: false
    });
  }

  function findHeader(rows, requiredHeaders){
    const required = requiredHeaders.map(normalizeHeader);

    for (let r = 0; r < rows.length; r++) {
      const normalizedRow = rows[r].map(normalizeHeader);
      const hit = required.every((header) => normalizedRow.includes(header));
      if (!hit) continue;

      const index = {};
      normalizedRow.forEach((header, col) => {
        if (header && index[header] === undefined) index[header] = col;
      });

      return { rowIndex: r, index };
    }

    return null;
  }

  function getCol(header, names){
    for (const name of names) {
      const key = normalizeHeader(name);
      if (header.index[key] !== undefined) return header.index[key];
    }
    return -1;
  }

  function categoryForQuestion(questionNumber){
    for (const title of TITLES) {
      for (const [from, to] of title.ranges) {
        if (questionNumber >= from && questionNumber <= to) return title;
      }
    }
    return null;
  }

  function createPlayer(map, player, identifier){
    const name = String(player ?? "").trim();
    const id = cleanIdentifier(identifier);
    const key = playerKey(name, id);

    if (!name || normalizeHeader(name) === "player") return null;

    if (!map.has(key)) {
      const categories = {};
      for (const title of TITLES) categories[title.id] = 0;

      map.set(key, {
        key,
        player: name,
        identifier: id,
        categories,
        totalScore: 0,
        rawTotalScore: 0,
        valuedQuestions: new Set()
      });
    }

    return map.get(key);
  }

  function parseRawReport(workbook){
    const sheet = getSheet(workbook, ["Raw Report Data"]);
    if (!sheet) {
      throw new Error('Sheet "Raw Report Data" nicht gefunden. Bitte den vollständigen Kahoot-XLSX-Report hochladen.');
    }

    const rows = sheetToRows(sheet);
    const header = findHeader(rows, ["Question number", "Player", "Score (points)"]);
    if (!header) {
      throw new Error('Im Sheet "Raw Report Data" wurden die Spalten "Question number", "Player" und "Score (points)" nicht gefunden.');
    }

    const qCol = getCol(header, ["Question number"]);
    const playerCol = getCol(header, ["Player"]);
    const identifierCol = getCol(header, ["Player Identifier", "Player identifier"]);
    const scoreCol = getCol(header, ["Score (points)"]);
    const currentTotalCol = getCol(header, ["Current Total Score (points)"]);

    const players = new Map();
    const valuedQuestions = new Set();

    for (let r = header.rowIndex + 1; r < rows.length; r++) {
      const row = rows[r];
      const questionNumber = toQuestionNumber(row[qCol]);
      const player = row[playerCol];
      const identifier = identifierCol >= 0 ? row[identifierCol] : "";
      const item = createPlayer(players, player, identifier);
      if (!item || questionNumber === null) continue;

      const score = toNumber(row[scoreCol]);
      item.rawTotalScore += score;

      if (currentTotalCol >= 0) {
        const currentTotal = toNumber(row[currentTotalCol]);
        if (currentTotal > item.totalScore) item.totalScore = currentTotal;
      }

      const title = categoryForQuestion(questionNumber);
      if (title) {
        item.categories[title.id] += score;
        item.valuedQuestions.add(questionNumber);
        valuedQuestions.add(questionNumber);
      }
    }

    for (const item of players.values()) {
      if (!item.totalScore) item.totalScore = item.rawTotalScore;
    }

    return { players, valuedQuestions };
  }

  function parseFinalScores(workbook, players){
    const sheet = getSheet(workbook, ["Final Scores", "Kahoot! Summary"]);
    if (!sheet) return;

    const rows = sheetToRows(sheet);
    const header = findHeader(rows, ["Player", "Total score (points)"]) || findHeader(rows, ["Player", "Total score"]);
    if (!header) return;

    const playerCol = getCol(header, ["Player"]);
    const identifierCol = getCol(header, ["Player identifier", "Player Identifier"]);
    const totalCol = getCol(header, ["Total score (points)", "Total score"]);

    if (playerCol < 0 || totalCol < 0) return;

    for (let r = header.rowIndex + 1; r < rows.length; r++) {
      const row = rows[r];
      const player = row[playerCol];
      const identifier = identifierCol >= 0 ? row[identifierCol] : "";
      const item = createPlayer(players, player, identifier);
      if (!item) continue;

      const total = toNumber(row[totalCol]);
      if (total > 0 || row[totalCol] !== "") item.totalScore = total;
    }
  }

  function sortRows(rows, scoreGetter){
    return [...rows].sort((a, b) => {
      const scoreDiff = scoreGetter(b) - scoreGetter(a);
      if (scoreDiff !== 0) return scoreDiff;
      const totalDiff = b.totalScore - a.totalScore;
      if (totalDiff !== 0) return totalDiff;
      return a.player.localeCompare(b.player, "de", { sensitivity: "base" });
    });
  }

  function buildEvaluation(workbook){
    const { players, valuedQuestions } = parseRawReport(workbook);
    parseFinalScores(workbook, players);

    const playerRows = Array.from(players.values());
    if (!playerRows.length) {
      throw new Error("Keine Spielerzeilen im Kahoot-Report gefunden.");
    }

    const rankings = {};
    for (const title of TITLES) {
      rankings[title.id] = sortRows(playerRows, (item) => item.categories[title.id]);
    }
    rankings[ALLROUNDER.id] = sortRows(playerRows, (item) => item.totalScore);

    return {
      playerRows,
      valuedQuestions,
      rankings
    };
  }

  function topRowsFor(titleId, limit = 5){
    if (!currentEvaluation) return [];
    return (currentEvaluation.rankings[titleId] || []).slice(0, limit);
  }

  function scoreFor(row, titleId){
    return titleId === ALLROUNDER.id ? row.totalScore : row.categories[titleId];
  }

  function formatScore(score){
    return Math.round(score).toLocaleString("de-DE");
  }

  function buildQualifiers(){
    if (!currentEvaluation) return [];

    const unique = uniqueFinalists.checked;
    const used = new Set();
    const qualifiers = [];

    for (const title of [...TITLES, ALLROUNDER]) {
      const ranking = currentEvaluation.rankings[title.id] || [];
      const selected = ranking.find((row) => !unique || !used.has(row.key));

      if (selected) {
        if (unique) used.add(selected.key);
        qualifiers.push({
          title,
          row: selected,
          score: scoreFor(selected, title.id),
          rank: ranking.findIndex((row) => row.key === selected.key) + 1
        });
      } else {
        qualifiers.push({ title, row: null, score: 0, rank: null });
      }
    }

    return qualifiers;
  }

  function renderQualifiers(){
    const qualifiers = buildQualifiers();
    qualifierPanel.classList.toggle("kahoot-hidden", !currentEvaluation);

    qualifierList.innerHTML = qualifiers.map((entry) => {
      if (!entry.row) {
        return `
          <div class="kahoot-qualifier">
            <div class="kahoot-qualtitle">${escapeHtml(entry.title.title)}</div>
            <div class="kahoot-qualname">-</div>
            <div class="kahoot-qualscore">Nicht vergeben</div>
          </div>
        `;
      }

      const rankInfo = entry.rank && entry.rank > 1 ? ` · Rang ${entry.rank} nachgerückt` : "";
      return `
        <div class="kahoot-qualifier">
          <div class="kahoot-qualtitle">${escapeHtml(entry.title.title)}</div>
          <div class="kahoot-qualname" title="${escapeHtml(entry.row.player)}">${escapeHtml(entry.row.player)}</div>
          <div class="kahoot-qualscore">${formatScore(entry.score)} Punkte${rankInfo}</div>
        </div>
      `;
    }).join("");
  }

  function renderCard(title, isAllrounder = false){
    const rows = topRowsFor(title.id, 5);
    const qualifiers = buildQualifiers();
    const qualifiedKey = qualifiers.find((entry) => entry.title.id === title.id)?.row?.key || null;

    const body = rows.length ? rows.map((row, index) => {
      const score = scoreFor(row, title.id);
      const isQualified = row.key === qualifiedKey;
      return `
        <tr>
          <td class="kahoot-rank">${index + 1}</td>
          <td class="kahoot-player">${escapeHtml(row.player)}</td>
          <td class="kahoot-score">${formatScore(score)}</td>
          <td>${isQualified ? '<span class="kahoot-badge is-winner">Qualifiziert</span>' : ''}</td>
        </tr>
      `;
    }).join("") : `
      <tr>
        <td colspan="4" class="kahoot-empty">Keine Daten.</td>
      </tr>
    `;

    return `
      <div class="kahoot-card">
        <div class="kahoot-cardhead">
          <div>
            <h2 class="kahoot-cardtitle">${escapeHtml(title.title)}</h2>
            <p class="kahoot-cardsub">${escapeHtml(title.subtitle)}</p>
          </div>
        </div>
        <div class="kahoot-tablewrap">
          <table class="kahoot-table">
            <thead>
              <tr>
                <th>Rang</th>
                <th>Spieler</th>
                <th class="kahoot-score">${isAllrounder ? 'Gesamt' : 'Kategorie'}</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>${body}</tbody>
          </table>
        </div>
      </div>
    `;
  }

  function renderResults(){
    if (!currentEvaluation) {
      results.innerHTML = "";
      qualifierPanel.classList.add("kahoot-hidden");
      return;
    }

    renderQualifiers();
    results.innerHTML = [
      ...TITLES.map((title) => renderCard(title, false)),
      renderCard(ALLROUNDER, true)
    ].join("");
  }

  function updateStats(file = null){
    const playerCount = currentEvaluation ? currentEvaluation.playerRows.length : 0;
    const questionCount = currentEvaluation ? currentEvaluation.valuedQuestions.size : 0;

    statPlayers.textContent = String(playerCount);
    statQuestions.textContent = String(questionCount);
    statFile.textContent = file ? file.name : "-";
    filePanel.classList.toggle("kahoot-hidden", !file);

    if (file) {
      fileName.textContent = file.name;
      fileMeta.textContent = `${formatBytes(file.size)} · ${playerCount} Spieler · ${questionCount} gewertete Fragen`;
    } else {
      fileName.textContent = "";
      fileMeta.textContent = "";
    }
  }

  async function loadFile(file){
    if (!file || busy) return;

    const isExcel = /\.(xlsx|xls)$/i.test(file.name) || /spreadsheet|excel/i.test(file.type);
    if (!isExcel) {
      setStatus("Keine gültige Excel-Datei erkannt.", "error");
      return;
    }

    busy = true;
    setStatus("Lese Kahoot-Report ein...", "busy");

    try {
      const bytes = await file.arrayBuffer();
      const workbook = XLSX.read(bytes, { type: "array", cellDates: false });
      currentEvaluation = buildEvaluation(workbook);
      updateStats(file);
      renderResults();
      setStatus(`Fertig. ${currentEvaluation.playerRows.length} Spieler ausgewertet.`);
    } catch (err) {
      console.error(err);
      currentEvaluation = null;
      updateStats(null);
      renderResults();
      setStatus(err.message || "Die Excel-Datei konnte nicht ausgewertet werden.", "error");
    } finally {
      busy = false;
      xlsxInput.value = "";
    }
  }

  function clearEvaluation(){
    currentEvaluation = null;
    updateStats(null);
    renderResults();
    setStatus("Auswertung geleert.");
  }

  function renderMapping(){
    const mapping = [
      ...TITLES.map((title) => `<strong>${escapeHtml(title.title)}</strong>: ${escapeHtml(title.subtitle)} — ${escapeHtml(title.description)}`),
      `<strong>${escapeHtml(ALLROUNDER.title)}</strong>: ${escapeHtml(ALLROUNDER.subtitle)} — ${escapeHtml(ALLROUNDER.description)}`
    ].join("<br>");

    mappingText.innerHTML = mapping;
    mappingPanel.classList.toggle("kahoot-hidden");
  }

  xlsxInput.addEventListener("change", (e) => {
    loadFile(e.target.files && e.target.files[0]);
  });

  loadBtn.addEventListener("click", () => {
    if (busy) return;
    xlsxInput.click();
  });

  clearBtn.addEventListener("click", () => {
    if (busy) return;
    clearEvaluation();
  });

  uniqueFinalists.addEventListener("change", () => {
    renderResults();
  });

  demoMapBtn.addEventListener("click", renderMapping);

  dropzone.addEventListener("dragover", (e) => {
    e.preventDefault();
    if (busy) return;
    dropzone.classList.add("is-dragover");
  });

  dropzone.addEventListener("dragleave", () => {
    dropzone.classList.remove("is-dragover");
  });

  dropzone.addEventListener("drop", (e) => {
    e.preventDefault();
    dropzone.classList.remove("is-dragover");
    if (busy) return;
    loadFile(e.dataTransfer.files && e.dataTransfer.files[0]);
  });

  updateStats(null);
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
