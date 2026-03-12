<?php
$page_title = 'Timer';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../navbar.php';
?>

<style>
  .timer-wrap{
    display:flex;
    flex-direction:column;
    gap:14px;
  }

  .timer-display{
    position: relative;

    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum" 1, "lnum" 1;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;

    font-size: 3rem;
    font-weight: 900;
    text-align:center;

    /* Symmetrisches Padding reserviert Platz fürs Icon -> kein Overlap, kein Shift */
    padding: 16px 56px;

    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    background: #fff;
    border: 2px solid rgba(0,0,0,0.06);
    user-select:none;

    line-height: 1.1;
  }

  .timer-icon{
    position:absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;

    display:flex;
    align-items:center;
    justify-content:center;

    opacity: 0.85;
    pointer-events:none;

    line-height: 1;
    font-size: 1.1rem;
    font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  }

  .timer-time{
    min-width: 9ch; /* "00:00:00" = 8, bisschen Luft */
    display:inline-block;
    text-align:center;
    letter-spacing: 0.5px;
  }

  .timer-input-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    align-items:end;
  }

  .timer-label{
    display:block;
    font-weight:800;
    margin-bottom:6px;
  }

  .timer-actions{
    display:flex;
    gap: 10px;
    align-items:stretch;
  }

  .timer-actions .timer-mainbtn{
    flex: 2 1 0;  /* 2/3 */
    justify-content:center;
  }

  .timer-actions .timer-resetbtn{
    flex: 1 1 0;  /* 1/3 */
    justify-content:center;
  }

  .timer-actions .btn-secondary{
    background: #eee;
    color: #333;
    box-shadow: var(--shadow);
  }

  .timer-actions .btn-secondary:hover{
    background: #e5e5e5;
  }

  .timer-actions .btn-danger{
    background: #fff;
    color: #c0392b;
    border: 2px solid rgba(192,57,43,.55);
    box-shadow: none;
  }

  .timer-actions .btn-danger:hover{
    background:#c0392b;
    color:#fff;
    border-color:#c0392b;
  }

  .timer-finished{
    border-color: var(--accent);
    background: #fff7ee;
  }

  @media (max-width: 520px){
    .timer-input-grid{ grid-template-columns: 1fr; }
    .timer-display{ font-size: 2.5rem; }
  }
</style>

<div class="container">
  <div class="timer-wrap">
    <div id="timerDisplay" class="timer-display" aria-live="polite">
      <span id="timerIcon" class="timer-icon" aria-hidden="true"></span>
      <span id="timerTime" class="timer-time">00:00:00</span>
    </div>

    <div class="timer-input-grid">
      <div>
        <label class="timer-label" for="hoursInput">Stunden</label>
        <input id="hoursInput" type="number" inputmode="numeric" min="0" max="999" value="2">
      </div>
      <div>
        <label class="timer-label" for="minutesInput">Minuten</label>
        <input id="minutesInput" type="number" inputmode="numeric" min="0" max="59" value="0">
      </div>
    </div>

    <div class="timer-actions">
      <button id="mainBtn" type="button" class="timer-mainbtn">Start</button>
      <button id="resetBtn" type="button" class="timer-resetbtn btn-danger" disabled>Reset</button>
    </div>
  </div>
</div>

<script>
(() => {
  const $ = (id) => document.getElementById(id);

  const hoursInput   = $("hoursInput");
  const minutesInput = $("minutesInput");

  const timerDisplay = $("timerDisplay");
  const timerIcon    = $("timerIcon");
  const timerTime    = $("timerTime");

  const mainBtn  = $("mainBtn");
  const resetBtn = $("resetBtn");

  let initialSeconds = 0;      // zuletzt gesetzte "Startdauer"
  let remainingSeconds = 0;

  let running = false;
  let everStarted = false;     // unterscheidet "Start" vs "Weiter"
  let endAtMs = 0;
  let tickHandle = null;

  const ICON_PLAY  = "▶";
  const ICON_PAUSE = "⏸";

  let audioCtx = null;

  function ensureAudio(){
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;

    if (!audioCtx){
      audioCtx = new Ctx();
    }

    if (audioCtx.state === "suspended"){
      audioCtx.resume();
    }

    return audioCtx;
  }

  function playFinishedSound(){
    const ctx = ensureAudio();
    if (!ctx) return;

    const start = ctx.currentTime + 0.02;

    [0, 0.28, 0.56].forEach((offset) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = "sine";
      osc.frequency.value = 880;

      gain.gain.setValueAtTime(0.0001, start + offset);
      gain.gain.exponentialRampToValueAtTime(0.18, start + offset + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.18);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(start + offset);
      osc.stop(start + offset + 0.2);
    });
  }

  function clampInt(v, min, max){
    const n = Number.isFinite(v) ? Math.trunc(v) : 0;
    return Math.min(max, Math.max(min, n));
  }

  function readInputsToSeconds(){
    const h = clampInt(parseInt(hoursInput.value, 10), 0, 999);
    const m = clampInt(parseInt(minutesInput.value, 10), 0, 59);
    hoursInput.value = String(h);
    minutesInput.value = String(m);
    return (h * 3600) + (m * 60);
  }

  function fmtHMS(totalSec){
    const sec = Math.max(0, Math.trunc(totalSec));
    const h = Math.trunc(sec / 3600);
    const m = Math.trunc((sec % 3600) / 60);
    const s = sec % 60;

    const hh = String(h).padStart(2, "0");
    const mm = String(m).padStart(2, "0");
    const ss = String(s).padStart(2, "0");
    return `${hh}:${mm}:${ss}`;
  }

  function setInputsEnabled(enabled){
    hoursInput.disabled = !enabled;
    minutesInput.disabled = !enabled;
  }

  function getMainBtnLabel(){
    if (running) return "Pause";
    if (!everStarted) return "Start";
    return "Weiter";
  }

  function updateIcon(){
    // Reset/noch nicht gestartet => nichts
    if (!everStarted || (remainingSeconds === initialSeconds && !running && !tickHandle)){
      timerIcon.textContent = "";
      return;
    }
    // Fertig => nichts (wie Reset)
    if (!running && remainingSeconds === 0 && initialSeconds > 0){
      timerIcon.textContent = "";
      return;
    }
    timerIcon.textContent = running ? ICON_PLAY : ICON_PAUSE;
  }

  function updateUI(){
    timerTime.textContent = fmtHMS(remainingSeconds);
    mainBtn.textContent = getMainBtnLabel();

    resetBtn.disabled = (!everStarted && !running) || (remainingSeconds === initialSeconds && !running);

    if (!running && remainingSeconds === 0 && initialSeconds > 0){
      timerDisplay.classList.add("timer-finished");
    } else {
      timerDisplay.classList.remove("timer-finished");
    }

    updateIcon();
  }

  function stopTick(){
    if (tickHandle !== null){
      clearInterval(tickHandle);
      tickHandle = null;
    }
  }

  function startTick(){
    stopTick();
    tickHandle = setInterval(() => {
      const now = Date.now();
      const msLeft = endAtMs - now;
      const next = Math.ceil(msLeft / 1000);

      if (next <= 0){
        remainingSeconds = 0;
        running = false;
        stopTick();
        setInputsEnabled(true);
        playFinishedSound();
        updateUI();
        return;
      }

      remainingSeconds = next;
      updateUI();
    }, 200);
  }

  function startOrResume(){
    if (running) return;

    // Wenn noch nie gestartet ODER "Reset-Zustand": Eingaben neu übernehmen
    if (!everStarted || (remainingSeconds === initialSeconds && !running && remainingSeconds === initialSeconds && !tickHandle)){
      initialSeconds = readInputsToSeconds();
      remainingSeconds = initialSeconds;
    }

    // Falls 0 gesetzt wurde -> nicht starten
    if (remainingSeconds <= 0){
      initialSeconds = readInputsToSeconds();
      remainingSeconds = initialSeconds;
      if (remainingSeconds <= 0){
        // Kein Text gewünscht -> einfach UI updaten
        updateUI();
        return;
      }
    }

    everStarted = true;
    running = true;
    setInputsEnabled(false);

    endAtMs = Date.now() + (remainingSeconds * 1000);
    startTick();
    updateUI();
  }

  function pause(){
    if (!running) return;

    const now = Date.now();
    const msLeft = endAtMs - now;
    remainingSeconds = Math.max(0, Math.ceil(msLeft / 1000));

    running = false;
    stopTick();
    setInputsEnabled(true);
    updateUI();
  }

  function reset(){
    stopTick();
    running = false;
    everStarted = false;

    // Reset soll "noch nicht gestartet" sein => Icon weg + MainBtn "Start"
    initialSeconds = readInputsToSeconds(); // hält Inputs als neue Basis
    remainingSeconds = initialSeconds;

    setInputsEnabled(true);
    updateUI();
  }

  // Klicks
  mainBtn.addEventListener("click", () => {
    ensureAudio();
    if (running) pause();
    else startOrResume();
  });

  resetBtn.addEventListener("click", reset);

  // Inputs: nur wenn nicht running
  function onInputsChange(){
    if (running) return;
    const sec = readInputsToSeconds();
    initialSeconds = sec;
    remainingSeconds = sec;
    // noch nicht gestartet Zustand beibehalten
    everStarted = false;
    stopTick();
    updateUI();
  }

  hoursInput.addEventListener("change", onInputsChange);
  minutesInput.addEventListener("change", onInputsChange);

  // Init (Standard 2:00)
  initialSeconds = readInputsToSeconds();
  remainingSeconds = initialSeconds;
  updateUI();
})();
</script>
