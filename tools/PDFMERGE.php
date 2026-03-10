<?php
$page_title = 'PDF Duplex Merge';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
  .pdfmerge-wrap{
    display:flex;
    flex-direction:column;
    gap:14px;
  }

  .pdfmerge-panel{
    position:relative;
    padding:16px 18px;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    background:#fff;
    border:2px solid rgba(0,0,0,0.06);
  }

  .pdfmerge-dropzone{
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:10px;
    min-height:160px;
    text-align:center;
    border:2px dashed rgba(0,0,0,0.14);
    border-radius: var(--border-radius);
    background: linear-gradient(180deg, #fff 0%, #fafafa 100%);
    transition:.15s ease;
    cursor:pointer;
    user-select:none;
    padding:18px;
  }

  .pdfmerge-dropzone:hover,
  .pdfmerge-dropzone.is-dragover{
    border-color: var(--accent);
    background:#fff7ee;
    transform: translateY(-1px);
  }

  .pdfmerge-dropicon{
    font-size:2rem;
    line-height:1;
    opacity:.85;
  }

  .pdfmerge-title{
    font-weight:900;
    font-size:1.05rem;
    margin:0;
  }

  .pdfmerge-sub{
    margin:0;
    opacity:.8;
  }

  .pdfmerge-hidden{
    display:none !important;
  }

  .pdfmerge-actions{
    display:flex;
    gap:10px;
    align-items:stretch;
    flex-wrap:wrap;
  }

  .pdfmerge-actions .pdfmerge-mainbtn{
    flex:2 1 240px;
    justify-content:center;
  }

  .pdfmerge-actions .pdfmerge-secondarybtn{
    flex:1 1 180px;
    justify-content:center;
  }

  .btn-secondary{
    background:#eee;
    color:#333;
    box-shadow: var(--shadow);
  }

  .btn-secondary:hover{
    background:#e5e5e5;
  }

  .btn-danger{
    background:#fff;
    color:#c0392b;
    border:2px solid rgba(192,57,43,.55);
    box-shadow:none;
  }

  .btn-danger:hover{
    background:#c0392b;
    color:#fff;
    border-color:#c0392b;
  }

  .pdfmerge-list{
    display:flex;
    flex-direction:column;
    gap:10px;
  }

  .pdfmerge-item{
    display:grid;
    grid-template-columns: 36px minmax(0,1fr) auto;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius: var(--border-radius);
    border:2px solid rgba(0,0,0,0.06);
    background:#fff;
    box-shadow: var(--shadow);
    cursor:grab;
  }

  .pdfmerge-item.dragging{
    opacity:.55;
    cursor:grabbing;
  }

  .pdfmerge-handle{
    width:36px;
    text-align:center;
    font-size:1.25rem;
    opacity:.6;
    user-select:none;
  }

  .pdfmerge-main{
    min-width:0;
  }

  .pdfmerge-filename{
    font-weight:800;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }

  .pdfmerge-meta{
    margin-top:2px;
    font-size:.95rem;
    opacity:.78;
  }

  .pdfmerge-right{
    display:flex;
    align-items:center;
    gap:8px;
  }

  .pdfmerge-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:38px;
    height:32px;
    padding:0 10px;
    border-radius:999px;
    background:#f3f3f3;
    border:1px solid rgba(0,0,0,0.08);
    font-weight:800;
  }

  .pdfmerge-iconbtn{
    width:36px;
    height:36px;
    padding:0;
    border-radius:10px;
    border:1px solid rgba(0,0,0,0.14);
    background:#fff;
    color:#444;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    box-shadow: var(--shadow);
    cursor:pointer;
    font-size:1.05rem;
    font-weight:900;
    line-height:1;
    transition: background .12s ease, border-color .12s ease, color .12s ease, transform .12s ease;
  }

  .pdfmerge-iconbtn:hover{
    background:#f3f3f3;
    border-color:rgba(0,0,0,0.22);
    color:#111;
    transform: translateY(-1px);
  }

  .pdfmerge-iconbtn:active{
    transform: translateY(0);
  }

  .pdfmerge-iconbtn.is-remove{
    color:#b03a2e;
    border-color:rgba(176,58,46,.28);
    background:#fff;
  }

  .pdfmerge-iconbtn.is-remove:hover{
    background:#fff3f1;
    border-color:rgba(176,58,46,.5);
    color:#8e2b21;
  }

  .pdfmerge-icon{
    display:block;
    line-height:1;
    transform: translateY(-1px);
    pointer-events:none;
  }

  .pdfmerge-summary{
    display:grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap:10px;
  }

  .pdfmerge-stat{
    padding:14px;
    border-radius: var(--border-radius);
    border:2px solid rgba(0,0,0,0.06);
    background:#fff;
    box-shadow: var(--shadow);
  }

  .pdfmerge-statlabel{
    font-size:.92rem;
    opacity:.72;
    margin-bottom:4px;
  }

  .pdfmerge-statvalue{
    font-size:1.35rem;
    font-weight:900;
    line-height:1.1;
  }

  .pdfmerge-status{
    padding:12px 14px;
    border-radius: var(--border-radius);
    border:2px solid rgba(0,0,0,0.06);
    background:#fff;
    box-shadow: var(--shadow);
    min-height:50px;
    display:flex;
    align-items:center;
  }

  .pdfmerge-status.is-busy{
    border-color: var(--accent);
    background:#fff7ee;
  }

  .pdfmerge-status.is-error{
    border-color: rgba(192,57,43,.45);
    background:#fff4f2;
    color:#a93226;
  }

  .pdfmerge-note{
    opacity:.8;
    line-height:1.45;
  }

  @media (max-width: 700px){
    .pdfmerge-summary{
      grid-template-columns: 1fr 1fr;
    }

    .pdfmerge-item{
      grid-template-columns: 28px minmax(0,1fr);
    }

    .pdfmerge-right{
      grid-column: 1 / -1;
      justify-content:flex-end;
      padding-top:2px;
    }
  }

  @media (max-width: 520px){
    .pdfmerge-summary{
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="container">
  <div class="pdfmerge-wrap">
    <div class="pdfmerge-panel">
      <label id="dropzone" class="pdfmerge-dropzone" for="pdfInput">
        <div class="pdfmerge-dropicon">📄</div>
        <p class="pdfmerge-title">PDFs hochladen</p>
        <p class="pdfmerge-sub">
          Mehrere PDFs auswählen oder hier hineinziehen. Die Reihenfolge kann danach per Drag & Drop geändert werden.
        </p>
      </label>
      <input id="pdfInput" type="file" accept="application/pdf" multiple class="pdfmerge-hidden">
    </div>

    <div class="pdfmerge-summary">
      <div class="pdfmerge-stat">
        <div class="pdfmerge-statlabel">Dokumente</div>
        <div id="statDocs" class="pdfmerge-statvalue">0</div>
      </div>
      <div class="pdfmerge-stat">
        <div class="pdfmerge-statlabel">PDF-Seiten</div>
        <div id="statPages" class="pdfmerge-statvalue">0</div>
      </div>
      <div class="pdfmerge-stat">
        <div class="pdfmerge-statlabel">Leerseiten</div>
        <div id="statBlanks" class="pdfmerge-statvalue">0</div>
      </div>
      <div class="pdfmerge-stat">
        <div class="pdfmerge-statlabel">Endsumme</div>
        <div id="statTotal" class="pdfmerge-statvalue">0</div>
      </div>
    </div>

    <div class="pdfmerge-panel">
      <div id="fileList" class="pdfmerge-list"></div>
      <div id="emptyHint" class="pdfmerge-note">
        Noch keine PDFs ausgewählt.
      </div>
    </div>

    <div class="pdfmerge-actions">
      <button id="mergeBtn" type="button" class="pdfmerge-mainbtn" disabled>Merge & Download</button>
      <button id="clearBtn" type="button" class="pdfmerge-secondarybtn btn-danger" disabled>Liste leeren</button>
      <button id="addBtn" type="button" class="pdfmerge-secondarybtn btn-secondary">Weitere PDFs hinzufügen</button>
    </div>

    <div id="statusBox" class="pdfmerge-status" aria-live="polite">
      Bereit.
    </div>
  </div>
</div>

<script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
<script>
(() => {
  const { PDFDocument } = PDFLib;

  const $ = (id) => document.getElementById(id);

  const pdfInput   = $("pdfInput");
  const dropzone   = $("dropzone");
  const fileList   = $("fileList");
  const emptyHint  = $("emptyHint");

  const statDocs   = $("statDocs");
  const statPages  = $("statPages");
  const statBlanks = $("statBlanks");
  const statTotal  = $("statTotal");

  const mergeBtn   = $("mergeBtn");
  const clearBtn   = $("clearBtn");
  const addBtn     = $("addBtn");
  const statusBox  = $("statusBox");

  let docs = [];
  let busy = false;
  let dragId = null;

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

  function uid(){
    return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, (ch) => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#039;'
    }[ch]));
  }

  function calcBlankPages(){
    let blanks = 0;
    for (let i = 0; i < docs.length - 1; i++) {
      if ((docs[i].pageCount % 2) === 1) blanks++;
    }
    return blanks;
  }

  function updateStats(){
    const docCount = docs.length;
    const pageCount = docs.reduce((sum, d) => sum + d.pageCount, 0);
    const blankCount = calcBlankPages();
    const totalCount = pageCount + blankCount;

    statDocs.textContent = String(docCount);
    statPages.textContent = String(pageCount);
    statBlanks.textContent = String(blankCount);
    statTotal.textContent = String(totalCount);

    mergeBtn.disabled = busy || docCount === 0;
    clearBtn.disabled = busy || docCount === 0;
    emptyHint.classList.toggle("pdfmerge-hidden", docCount > 0);
  }

  function renderList(){
    fileList.innerHTML = docs.map((doc, index) => {
      const needsBlankAfter = index < docs.length - 1 && (doc.pageCount % 2 === 1);
      return `
        <div class="pdfmerge-item" draggable="true" data-id="${escapeHtml(doc.id)}">
          <div class="pdfmerge-handle" title="Ziehen zum Sortieren">⋮⋮</div>

          <div class="pdfmerge-main">
            <div class="pdfmerge-filename">${escapeHtml(doc.name)}</div>
            <div class="pdfmerge-meta">
              ${doc.pageCount} Seite${doc.pageCount === 1 ? '' : 'n'} · ${formatBytes(doc.size)}
              ${needsBlankAfter ? ' · + 1 Leerseite danach' : ''}
            </div>
          </div>

          <div class="pdfmerge-right">
            <span class="pdfmerge-badge">${index + 1}</span>
            <button type="button" class="pdfmerge-iconbtn" data-action="up" data-id="${escapeHtml(doc.id)}" title="Nach oben">↑</button>
            <button type="button" class="pdfmerge-iconbtn" data-action="down" data-id="${escapeHtml(doc.id)}" title="Nach unten">↓</button>
            <button type="button" class="pdfmerge-iconbtn" data-action="remove" data-id="${escapeHtml(doc.id)}" title="Entfernen">✕</button>
          </div>
        </div>
      `;
    }).join("");

    updateStats();
  }

  async function readPdfMeta(file){
    const bytes = new Uint8Array(await file.arrayBuffer());
    const pdf = await PDFDocument.load(bytes, { ignoreEncryption: false });
    const pages = pdf.getPages();
    const first = pages[0] || null;

    return {
      id: uid(),
      file,
      name: file.name,
      size: file.size,
      bytes,
      pageCount: pdf.getPageCount(),
      firstPageSize: first ? {
        width: first.getWidth(),
        height: first.getHeight()
      } : null
    };
  }

  async function addFiles(fileListLike){
    const files = Array.from(fileListLike || []).filter(f => f.type === "application/pdf" || /\.pdf$/i.test(f.name));
    if (!files.length) {
      setStatus("Keine gültigen PDFs erkannt.", "error");
      return;
    }

    busy = true;
    updateStats();
    setStatus(`Lese ${files.length} PDF${files.length === 1 ? '' : 's'} ein...`, "busy");

    try {
      for (const file of files) {
        const meta = await readPdfMeta(file);
        docs.push(meta);
        renderList();
      }
      setStatus(`${files.length} PDF${files.length === 1 ? '' : 's'} hinzugefügt.`);
    } catch (err) {
      console.error(err);
      setStatus("Mindestens eine PDF konnte nicht gelesen werden.", "error");
    } finally {
      busy = false;
      updateStats();
      pdfInput.value = "";
    }
  }

  function moveDoc(id, direction){
    const idx = docs.findIndex(d => d.id === id);
    if (idx === -1) return;

    const nextIdx = idx + direction;
    if (nextIdx < 0 || nextIdx >= docs.length) return;

    const [item] = docs.splice(idx, 1);
    docs.splice(nextIdx, 0, item);
    renderList();
  }

  function removeDoc(id){
    docs = docs.filter(d => d.id !== id);
    renderList();
    setStatus("Dokument entfernt.");
  }

  function clearDocs(){
    docs = [];
    renderList();
    setStatus("Liste geleert.");
  }

  function downloadBlob(blob, filename){
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  async function mergePdfs(){
    if (!docs.length || busy) return;

    busy = true;
    updateStats();
    setStatus("Merge läuft...", "busy");

    try {
      const out = await PDFDocument.create();

      for (let i = 0; i < docs.length; i++) {
        const srcInfo = docs[i];
        const src = await PDFDocument.load(srcInfo.bytes, { ignoreEncryption: false });
        const pageIndices = src.getPageIndices();
        const copiedPages = await out.copyPages(src, pageIndices);

        for (const page of copiedPages) {
          out.addPage(page);
        }

        const isLastDoc = i === docs.length - 1;
        const needsBlankAfter = !isLastDoc && (srcInfo.pageCount % 2 === 1);

        if (needsBlankAfter) {
          const size = srcInfo.firstPageSize || { width: 595.28, height: 841.89 };
          out.addPage([size.width, size.height]);
        }
      }

      const pdfBytes = await out.save();
      const blob = new Blob([pdfBytes], { type: "application/pdf" });

      const today = new Date();
      const fileName =
        `merged-duplex-${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}.pdf`;

      downloadBlob(blob, fileName);
      setStatus(`Fertig. ${docs.length} Dokumente gemerged, ${calcBlankPages()} Leerseite(n) eingefügt.`);
    } catch (err) {
      console.error(err);
      setStatus("Merge fehlgeschlagen. Prüfe, ob alle PDFs lesbar und nicht geschützt sind.", "error");
    } finally {
      busy = false;
      updateStats();
    }
  }

  pdfInput.addEventListener("change", (e) => {
    addFiles(e.target.files);
  });

  addBtn.addEventListener("click", () => {
    if (busy) return;
    pdfInput.click();
  });

  clearBtn.addEventListener("click", () => {
    if (busy) return;
    clearDocs();
  });

  mergeBtn.addEventListener("click", mergePdfs);

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
    addFiles(e.dataTransfer.files);
  });

  fileList.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-action]");
    if (!btn || busy) return;

    const { action, id } = btn.dataset;

    if (action === "remove") removeDoc(id);
    if (action === "up") moveDoc(id, -1);
    if (action === "down") moveDoc(id, 1);
  });

  fileList.addEventListener("dragstart", (e) => {
    const item = e.target.closest(".pdfmerge-item");
    if (!item || busy) return;
    dragId = item.dataset.id;
    item.classList.add("dragging");
    e.dataTransfer.effectAllowed = "move";
  });

  fileList.addEventListener("dragend", (e) => {
    const item = e.target.closest(".pdfmerge-item");
    if (item) item.classList.remove("dragging");
    dragId = null;
  });

  fileList.addEventListener("dragover", (e) => {
    const overItem = e.target.closest(".pdfmerge-item");
    if (!overItem || !dragId || busy) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
  });

  fileList.addEventListener("drop", (e) => {
    const overItem = e.target.closest(".pdfmerge-item");
    if (!overItem || !dragId || busy) return;
    e.preventDefault();

    const targetId = overItem.dataset.id;
    if (targetId === dragId) return;

    const fromIdx = docs.findIndex(d => d.id === dragId);
    const toIdx = docs.findIndex(d => d.id === targetId);
    if (fromIdx === -1 || toIdx === -1) return;

    const [moved] = docs.splice(fromIdx, 1);
    docs.splice(toIdx, 0, moved);
    renderList();
    setStatus("Reihenfolge aktualisiert.");
  });

  renderList();
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>