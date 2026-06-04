/* ============================================================
   app.js — WebSocket client + UI controller (tema samurai)
   Sistem match/room SAMA seperti kode lama (PHP WebSocket).
   Tampilan mengikuti tema 刃影 Blade & Shadow.
   ============================================================ */

let ws             = null;
let myName         = '';
let isHost         = false;
let roomCode       = '';
let roomRounds     = 5;
let roomMaxPlayers = 4;
let players        = [];   // array {username, char_idx, is_bot}
let hostName       = '';
let usedChars      = {};   // {username: char_idx}
let gameChart      = null;
let renderer       = null;

// Game state
let currentRound   = 0;
let totalRounds    = 5;
let scores         = {};
let clicked        = false;
let currentTarget  = null;
let signalActive   = false;
let signalRecvAt   = 0;     // performance.now() saat sinyal diterima client
let roundActive    = false;  // true hanya antara round_wait s/d round_result
let intentionalAction = false;
let myCharIdx      = 0;          // karakter pilihan sendiri
let lastResults    = null;

// Input listeners
let _keyListener   = null;
let _mouseListener = null;

// PIN modal
let _pendingJoinCode = '';

const $ = id => document.getElementById(id);
const pNames = () => players.map(p => typeof p === 'string' ? p : p.username);
const pName  = p => typeof p === 'string' ? p : p.username;
function charIdxOf(uname){ const p = players.find(x => pName(x) === uname); return (p && typeof p === 'object' && p.char_idx != null) ? p.char_idx : 0; }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ── WebSocket ──────────────────────────────────────────── */
function connect() {
  if (ws && ws.readyState === WebSocket.OPEN) return;
  updateStatus('connecting');
  ws = new WebSocket(WS_SERVER_URL);
  ws.onopen  = () => { updateStatus('connected'); intentionalAction = false; };
  ws.onclose = () => {
    updateStatus('connecting');
    if (!intentionalAction) toast('Connection lost. Reconnecting...');
    intentionalAction = false;
    setTimeout(connect, 3000);
  };
  ws.onerror = () => updateStatus('connecting');
  ws.onmessage = e => {
    // Timestamp PALING AWAL — sebelum parse — agar RT seakurat mungkin.
    const tRecv = performance.now();
    let msg;
    try { msg = JSON.parse(e.data); } catch (_) { return; }
    if (msg.type === 'round_signal') signalRecvAt = tRecv;  // set sedini mungkin
    handleMessage(msg);
  };
}

function send(obj) {
  if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
  else toast('Not connected to server.');
}

function updateStatus(s) {
  const el = $('ws-status'); if (!el) return;
  el.innerHTML = s === 'connected'
    ? '<span class="dot dot-live"></span> Connected to dojo'
    : '<span class="dot dot-idle"></span> Connecting...';
}

/* ── Router ─────────────────────────────────────────────── */
function handleMessage(msg) {
  switch (msg.type) {
    case 'login_ok':     onLoginOk(msg);    break;
    case 'room_list':    onRoomList(msg);   break;
    case 'room_joined':  onRoomJoined(msg); break;
    case 'room_update':  onRoomUpdate(msg); break;
    case 'game_start':   onGameStart(msg);  break;
    case 'round_wait':   onRoundWait(msg);  break;
    case 'round_signal': onRoundSignal(msg);break;
    case 'early_click':  onEarlyClick(msg); break;
    case 'round_result': onRoundResult(msg);break;
    case 'game_over':    onGameOver(msg);   break;
    case 'chat_message': onChatMessage(msg);break;
    case 'error':        onError(msg);      break;
  }
}

function onError(msg) {
  toast(msg.message);
  // Jika error PIN salah, buka kembali modal
  if (/pin/i.test(msg.message) && _pendingJoinCode) {
    $('inp-pin-modal').value = '';
    $('modal-pin').style.display = 'flex';
    setTimeout(() => $('inp-pin-modal').focus(), 50);
  }
}

/* ── Auth handlers ──────────────────────────────────────── */
function onLoginOk(msg) {
  myName = msg.username;
  // FIX bug login: pastikan transisi screen + isi label aman walau elemen null
  const label = $('menu-username-label');
  if (label) label.textContent = `${myName} · 名${msg.is_new ? '  (new account)' : ''}`;
  showScreen('menu');
  // minta daftar room terbaru
  send({ type:'get_rooms' });
}

/* ── Room List ──────────────────────────────────────────── */
function onRoomList(msg) {
  const rooms = msg.rooms || [];
  const badge = $('room-count-badge');
  const box   = $('room-list-container');
  if (badge) badge.textContent = rooms.length;
  if (!box) return;

  if (rooms.length === 0) {
    box.innerHTML = '<p class="empty-state">No rooms yet.<br>Open the first one!</p>';
    return;
  }

  box.innerHTML = rooms.map(r => {
    const playing = r.status === 'playing';
    const pin = r.has_pin ? '🔒 ' : '';
    return `
      <div class="room-card ${playing ? 'playing' : ''}"
           onclick="${playing ? '' : `tryJoinRoom('${r.code}', ${r.has_pin})`}">
        <div class="room-card-top">
          <span class="room-card-code">${pin}${esc(r.code)}</span>
          <span class="room-card-status ${playing ? 'status-playing' : 'status-waiting'}">
            ${playing ? '▶ main' : '⏳ tunggu'}
          </span>
        </div>
        <div class="room-card-info">
          <span>👤 ${r.players}/${r.max_players}</span>
          <span>⚔ ${r.rounds} ronde</span>
          <span>${esc(r.host)}</span>
        </div>
      </div>`;
  }).join('');
}

function tryJoinRoom(code, hasPin) {
  if (hasPin) {
    _pendingJoinCode = code;
    $('inp-pin-modal').value = '';
    $('modal-pin').style.display = 'flex';
    setTimeout(() => $('inp-pin-modal').focus(), 50);
  } else {
    send({ type:'join_room', code });
  }
}

/* ── Room handlers ──────────────────────────────────────── */
function onRoomJoined(msg) {
  roomCode       = msg.code;
  isHost         = msg.is_host;
  hostName       = msg.host;
  roomRounds     = msg.rounds;
  roomMaxPlayers = msg.max_players || 4;
  players        = msg.players;
  usedChars      = msg.chars || {};
  myCharIdx      = usedChars[myName] != null ? usedChars[myName] : 0;
  showScreen('room');
  renderCharRoster();
  renderRoom();
}

function onRoomUpdate(msg) {
  players  = msg.players;
  hostName = msg.host;
  usedChars = msg.chars || usedChars;
  if (usedChars[myName] != null) myCharIdx = usedChars[myName];
  renderCharRoster();
  renderRoom();
}

/* ── Game handlers ──────────────────────────────────────── */
function onGameStart(msg) {
  roundActive = false;
  totalRounds = msg.total_rounds; currentRound = 0;
  scores = {}; players.forEach(p => scores[pName(p)] = 0);
  lastResults = null;

  if (renderer) renderer.destroy();
  // charMap: pakai char_idx asli tiap pemain dari server
  const charMap = {};
  players.forEach(p => { charMap[pName(p)] = (typeof p === 'object' && p.char_idx != null) ? p.char_idx : 0; });

  renderer = new DuelRenderer($('duel-arena'), $('duel-center'), $('screen-game'));
  renderer.setPlayers(pNames(), myName, charMap);
  renderer.setScores(scores, totalRounds);

  showScreen('game');
  $('game-room-label').textContent = 'ROOM: ' + roomCode;
  $('game-round-label').textContent = `RONDE 0/${totalRounds}`;
  DuelRenderer.buildRoundPips($('duel-round-pips'), totalRounds, 0, false);
  setTapZone(false, '');
}

function onRoundWait(msg) {
  currentRound  = msg.round;
  clicked       = false;
  signalActive  = false;
  currentTarget = null;
  roundActive   = true;
  signalRecvAt  = 0;
  $('game-round-label').textContent = `RONDE ${msg.round}/${msg.total}`;
  DuelRenderer.buildRoundPips($('duel-round-pips'), totalRounds, msg.round, false);
  if (renderer) { renderer.resetRound(); renderer.showWait(); }
  setTapZone(true, '');
  // Pasang listener SEJAK FASE WAIT. Input apapun saat WAIT = early click.
  attachRoundListeners();
}

function onRoundSignal(msg) {
  // signalRecvAt sudah di-set di ws.onmessage (sedini mungkin).
  if (!signalRecvAt) signalRecvAt = performance.now();
  currentTarget = msg.target;
  signalActive  = true;
  if (renderer) renderer.showSignal(currentTarget);
}

function onEarlyClick(msg) {
  toast(msg.message || '⚠ Too early! Round ended. −1 point.');
  setTapZone(false, '');
  removeInputListeners();
}

function onRoundResult(msg) {
  roundActive = false;   // ronde selesai, klik tidak lagi diproses
  scores = msg.scores;
  lastResults = msg.results;
  removeInputListeners();
  currentTarget = null;
  setTapZone(false, '');

  if (renderer) {
    renderer.setScores(scores, totalRounds);
    renderer.showResult(msg.results, msg.early_ended, msg.culprit);
  }
  DuelRenderer.buildRoundPips($('duel-round-pips'), totalRounds, currentRound, true);

  if (msg.early_ended && msg.culprit && msg.culprit !== myName) {
    toast(`⚠ ${esc(msg.culprit)} flinched! Round void.`);
  }

  // reveal bar
  showRevealBar(msg);
}

function showRevealBar(msg) {
  const bar = $('duel-reveal');
  if (!bar) return;
  const winner = msg.results.find(r => r.rank === 1 && !r.is_early && r.rt < 9000);
  const me     = msg.results.find(r => r.username === myName);
  let label;
  if (msg.early_ended) label = `${esc(msg.culprit || '?')} flinched — round void`;
  else if (winner && winner.username === myName) label = 'You were fastest! ⚔';
  else if (me && me.is_early) label = 'You flinched — hold steady!';
  else if (winner) label = `${esc(winner.username)} wins this round`;
  else label = 'No winner';

  bar.style.display = 'flex';
  bar.innerHTML = `<span class="mono" style="font-size:13px;color:var(--paper-2)">${label}</span>`;
}

function onGameOver(msg) {
  roundActive = false;
  removeInputListeners();
  if ($('duel-reveal')) $('duel-reveal').style.display = 'none';
  if (typeof FX !== 'undefined') FX.spawnPetals($('stats-petals'), 14);

  const winnerName = msg.winner || '?';
  const youWon = winnerName === myName;
  $('winner-name').textContent = winnerName;
  $('winner-kanji').textContent = youWon ? '勝' : '敗';
  $('winner-kanji').style.color = youWon ? 'var(--gold)' : 'var(--crimson)';
  $('victory-sub').textContent = youWon ? 'YOU ARE THE CHAMPION' : 'THE CHAMPION';

  // Urutkan pemain berdasarkan poin (desc)
  const ranked = Object.entries(msg.stats)
    .map(([uname, s]) => ({ uname, ...s, charIdx: charIdxOf(uname) }))
    .sort((a, b) => b.points - a.points || a.avg_rt - b.avg_rt);

  // ── PODIUM: tampilkan sprite tiap pemain, juara di tengah & lebih besar ──
  const podium = $('victory-podium');
  // urutan tampil: 2nd, 1st, 3rd, 4th, 5th (juara di tengah kalau ≤3)
  let order = ranked.map((_, i) => i);
  if (ranked.length >= 3) order = [1, 0, 2, ...ranked.slice(3).map((_, i) => i + 3)];
  else if (ranked.length === 2) order = [0, 1];
  podium.innerHTML = order.map(idx => {
    const p = ranked[idx];
    const rank = idx + 1;
    const char = ROSTER[p.charIdx % ROSTER.length];
    const isWin = rank === 1;
    const me = p.uname === myName;
    const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : '#' + rank;
    return `
      <div class="podium-slot ${isWin ? 'champ' : ''}">
        <div class="podium-medal">${medal}</div>
        <div class="podium-spr"><div class="spr-holder ${isWin ? 'win win-glow' : ''}">${SPRITES.get(char.id, isWin ? 'win' : 'lose')}</div></div>
        <div class="podium-base" style="--c:${char.color}">
          <div class="podium-name ${me ? 'me' : ''}">${esc(p.uname)}${me ? ' ·YOU' : ''}</div>
          <div class="podium-pts">${p.points} <span>pt</span></div>
        </div>
      </div>`;
  }).join('');

  // ── RANKS: rincian statistik tiap pemain ──
  const box = $('all-player-stats');
  box.innerHTML = '';
  const datasets = [];
  const clrs = ['#d23b40','#e0b552','#b07ad6','#5cc6c0','#6b8bf0'];
  ranked.forEach((s, i) => {
    const uname = s.uname;
    const me = uname === myName;
    const char = ROSTER[s.charIdx % ROSTER.length];
    box.innerHTML += `
      <div class="rank-row ${me?'me':''} ${i===0?'first':''}">
        <div class="rank-num">${i+1}</div>
        <div class="rank-spr">${SPRITES.get(char.id,'idle')}</div>
        <div class="rank-info">
          <div class="rank-name">${esc(uname)}${me?' (you)':''}${i===0?' 🏆':''}</div>
          <div class="rank-stats">
            <span><b class="gold-text">${s.points}</b> poin</span>
            <span><b>${s.avg_rt}</b>ms avg</span>
            <span><b style="color:var(--jade)">${s.best_rt}</b>ms best</span>
          </div>
        </div>
      </div>`;
    datasets.push({
      label: uname, data: s.rt_history.map(rt => rt < 9000 ? rt : null),
      borderColor: clrs[s.charIdx % clrs.length], backgroundColor: clrs[s.charIdx % clrs.length] + '22',
      tension: 0.3, fill: false, pointRadius: 4,
    });
  });

  const ctx = $('rt-chart').getContext('2d');
  if (gameChart) gameChart.destroy();
  gameChart = new Chart(ctx, {
    type: 'line',
    data: { labels: Array.from({length:totalRounds},(_,i)=>'R'+(i+1)), datasets },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend:{labels:{color:'#8d8474',font:{family:'JetBrains Mono'}}},
        tooltip:{callbacks:{label:c=>c.dataset.label+': '+(c.raw??'TIMEOUT')+'ms'}} },
      scales: {
        y:{title:{display:true,text:'RT (ms)',color:'#8d8474'},ticks:{color:'#8d8474'},grid:{color:'#251e34'}},
        x:{ticks:{color:'#8d8474'},grid:{color:'#251e34'}}
      }
    }
  });

  if (renderer) { renderer.destroy(); renderer = null; }
  showScreen('stats');
}

/* ── Chat ──────────────────────────────────────────────── */
function onChatMessage(msg) {
  const box = $('chat-messages'); if (!box) return;
  const mine = msg.username === myName;
  const div = document.createElement('div');
  div.className = 'chat-msg' + (mine ? ' mine' : '');
  div.innerHTML = `<div class="chat-user">${esc(msg.username)} <span class="chat-time">${esc(msg.time)}</span></div>
                   <div>${esc(msg.text)}</div>`;
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

function sendChat() {
  const inp = $('inp-chat');
  const text = inp.value.trim();
  if (!text) return;
  send({ type:'chat_send', text });
  inp.value = '';
}

/* ── Input system (random target) ───────────────────────── */
/**
 * Listener tunggal aktif sepanjang ronde (WAIT + SIGNAL).
 * - Saat WAIT (signalActive=false): input APAPUN (keyboard/klik) = early click.
 * - Saat SIGNAL (signalActive=true): hanya input yang COCOK target = menang;
 *   input salah saat sinyal = early click (kena penalti, biar fair).
 */
function attachRoundListeners() {
  removeInputListeners();

  _keyListener = (e) => {
    if (clicked || !roundActive) return;
    // abaikan tombol modifier / fungsi
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.key.length !== 1 && e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    handleRoundInput('key', e.key.toUpperCase());
  };
  document.addEventListener('keydown', _keyListener);

  _mouseListener = (e) => {
    if (clicked || !roundActive) return;
    e.preventDefault();
    const nameByBtn = { 0:'left', 1:'middle', 2:'right' };
    handleRoundInput('mouse', nameByBtn[e.button] || 'left');
  };
  const zone = $('btn-click');
  if (zone) {
    zone.addEventListener('mousedown', _mouseListener);
    zone.addEventListener('contextmenu', e => e.preventDefault());
  }
}

/** Proses input pemain sesuai fase ronde. */
function handleRoundInput(kind, value) {
  if (clicked || !roundActive) return;

  if (!signalActive) {
    // Fase WAIT → input apapun = early click (kepancing)
    clicked = true;
    removeInputListeners();
    send({ type:'player_click' });   // tanpa client_rt → server tahu ini early
    return;
  }

  // Fase SIGNAL → cek apakah input cocok target
  const t = currentTarget;
  if (!t) return;
  let correct = false;
  if (t.type === 'key' && kind === 'key') {
    correct = (value === String(t.value).toUpperCase());
  } else if (t.type === 'mouse' && kind === 'mouse') {
    correct = (value === t.value);
  }

  if (correct) {
    doClick();
  } else {
    // Input salah saat sinyal = tetap dihitung klik (kena penalti early-style)
    // supaya tidak bisa asal gebuk semua tombol. Tapi hanya jika beda TIPE
    // atau beda nilai. Kita kirim sebagai klik → server hitung sebagai normal late.
    // Pilihan desain: input salah DIABAIKAN (tidak menghukum), agar tidak frustrasi.
    // -> diabaikan: tidak melakukan apa-apa.
  }
}

function removeInputListeners() {
  if (_keyListener) { document.removeEventListener('keydown', _keyListener); _keyListener = null; }
  if (_mouseListener) {
    const z = $('btn-click');
    if (z) z.removeEventListener('mousedown', _mouseListener);
    _mouseListener = null;
  }
}

function doClick() {
  if (clicked) return;
  clicked = true;
  // Hitung RT di CLIENT (akurat, bebas delay jaringan & beda jam server).
  const clientRt = signalRecvAt ? Math.round(performance.now() - signalRecvAt) : null;
  send({ type:'player_click', client_rt: clientRt });
  removeInputListeners();
}

/* ── UI helpers ─────────────────────────────────────────── */
function setTapZone(enabled, label) {
  const z = $('btn-click');
  if (!z) return;
  z.disabled = !enabled;
}

function showScreen(name) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  const el = $('screen-' + name);
  if (el) el.classList.add('active');
}

function renderCharRoster() {
  const box = $('char-roster'); if (!box) return;
  // siapa pakai karakter apa (selain diriku)
  const takenBy = {};   // charIdx → username
  for (const [uname, idx] of Object.entries(usedChars)) {
    if (uname !== myName) takenBy[idx] = uname;
  }
  box.innerHTML = ROSTER.map((c, i) => {
    const taken = takenBy[i] != null;
    const cls = 'char-chip' + (i===myCharIdx?' active':'') + (taken?' taken':'');
    const onclick = taken ? '' : `onclick="selectChar(${i})"`;
    const tag = taken ? `<span class="char-taken-tag">${esc(takenBy[i])}</span>` : '';
    return `<div class="${cls}" ${onclick} title="${esc(c.name)} · ${esc(c.role)}${taken?' (used by '+esc(takenBy[i])+')':''}">
      ${SPRITES.get(c.id,'idle')}${tag}
    </div>`;
  }).join('');
  renderCharPreview();
}

function selectChar(i) {
  // cek dulu apakah dipakai orang lain
  for (const [uname, idx] of Object.entries(usedChars)) {
    if (uname !== myName && idx === i) { toast('That character is already taken by ' + uname); return; }
  }
  myCharIdx = i;
  renderCharRoster();
  send({ type:'set_char', char_idx:i });   // reservasi di server
}

function renderCharPreview() {
  const box = $('char-preview'); if (!box) return;
  const c = ROSTER[myCharIdx];
  box.innerHTML = `
    <div class="char-preview-name">
      <div class="brush-title" style="font-size:13px;color:${c.color}">${esc(c.name)}</div>
      <div class="label">${esc(c.role)} · ${esc(c.element)}</div>
    </div>
    <div class="preview-spr"><div class="spr-holder">${SPRITES.get(c.id,'idle')}</div></div>`;
}

function renderRoom() {
  $('room-code-display').textContent = roomCode;
  $('room-count').textContent = players.length;
  $('room-max').textContent   = roomMaxPlayers;
  $('rounds-label').textContent = 'RONDE: ' + roomRounds;
  $('room-player-list').innerHTML = players.map(p => {
    const nm = pName(p);
    const bot = (typeof p === 'object' && p.is_bot);
    const cIdx = (typeof p === 'object' && p.char_idx != null) ? p.char_idx : 0;
    const cName = ROSTER[cIdx] ? ROSTER[cIdx].name : '';
    return `<li><span class="dot dot-live"></span>${esc(nm)}
      ${bot?'<span class="player-badge" style="background:var(--purple)">BOT</span>':''}
      ${nm===hostName?'<span class="player-badge">HOST</span>':''}
      ${nm===myName?'<span class="mono" style="color:var(--gold-soft);font-size:10px;margin-left:4px">(you)</span>':''}
      <span class="mono" style="color:var(--paper-3);font-size:11px;margin-left:auto">${esc(cName)}</span>
    </li>`;
  }).join('');

  // Kontrol host + tombol bot
  if (isHost) {
    $('host-controls').style.display = 'block';
    $('guest-waiting').style.display = 'none';
    $('btn-start-game').disabled = players.length < 2;
    const botBox = $('bot-controls');
    if (botBox) {
      const full = players.length >= roomMaxPlayers;
      const hasBot = players.some(p => typeof p === 'object' && p.is_bot);
      $('btn-add-bot').disabled = full;
      $('btn-remove-bot').disabled = !hasBot;
    }
  } else {
    $('host-controls').style.display = 'none';
    $('guest-waiting').style.display = 'flex';
  }
}

function toast(msg, dur=3500) {
  const el = $('toast'); el.textContent = msg; el.classList.add('show');
  setTimeout(()=>el.classList.remove('show'), dur);
}

/* ── Loading screen animation ───────────────────────────── */
const LOAD_STEPS = ['forging the blade','painting the night','summoning the wind','connecting the dojo','sharpening reflexes'];
function runLoading(onDone) {
  let p = 0;
  const fill = $('loading-fill'), pct = $('loading-pct'), step = $('loading-step');
  const id = setInterval(() => {
    p = Math.min(100, p + (2 + Math.random()*5));
    if (fill) fill.style.width = p + '%';
    if (pct)  pct.textContent = Math.floor(p) + '%';
    if (step) step.textContent = LOAD_STEPS[Math.min(LOAD_STEPS.length-1, Math.floor((p/100)*LOAD_STEPS.length))];
    if (p >= 100) { clearInterval(id); setTimeout(onDone, 500); }
  }, 120);
}

/* ── Init ───────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  // Sakura
  if (typeof FX !== 'undefined') {
    FX.spawnPetals($('loading-petals'), 10);
    FX.spawnPetals($('login-petals'), 9);
    FX.spawnPetals($('reg-petals'), 9);
  }
  // Hero sprite di auth
  if (typeof SPRITES !== 'undefined') {
    const lh = $('login-hero'); if (lh) lh.innerHTML = `<div class="spr-holder">${SPRITES.get('knight','idle')}</div>`;
    const rh = $('reg-hero');   if (rh) rh.innerHTML = `<div class="spr-holder">${SPRITES.get('reaper','idle')}</div>`;
  }

  // Loading → login
  runLoading(() => showScreen('login'));

  // Auth nav
  $('btn-goto-register').onclick = () => showScreen('register');
  $('btn-goto-login').onclick    = () => showScreen('login');

  $('btn-login').onclick = () => {
    const user = $('inp-login-user').value.trim();
    const pass = $('inp-login-pass').value;
    if (!user) return toast('Enter a username!');
    if (!pass) return toast('Enter a password!');
    send({ type:'login', username:user, password:pass });
  };
  $('btn-register').onclick = () => {
    const user  = $('inp-reg-user').value.trim();
    const pass  = $('inp-reg-pass').value;
    const pass2 = $('inp-reg-pass2').value;
    if (user.length < 2) return toast('Username must be at least 2 characters!');
    if (pass.length < 6) return toast('Password must be at least 6 characters!');
    if (pass !== pass2)  return toast('Passwords do not match!');
    send({ type:'register', username:user, password:pass });
  };
  $('inp-login-pass').onkeydown = e => { if(e.key==='Enter') $('btn-login').click(); };
  $('inp-reg-pass2').onkeydown  = e => { if(e.key==='Enter') $('btn-register').click(); };

  // Create / join
  $('btn-create-room').onclick = () => {
    const rounds     = parseInt($('sel-rounds').value);
    const maxPlayers = parseInt($('sel-max-players').value);
    const pin        = $('inp-create-pin').value.trim();
    if (pin && (pin.length < 4 || !/^\d+$/.test(pin)))
      return toast('PIN must be 4–6 digits, or leave empty.');
    send({ type:'create_room', rounds, max_players:maxPlayers, pin });
  };
  $('btn-join-code').onclick = () => {
    const code = $('inp-room-code').value.trim().toUpperCase();
    if (code.length !== 6) return toast('Room code must be 6 letters.');
    send({ type:'join_room', code, pin:'' });
  };
  $('inp-room-code').oninput = e => e.target.value = e.target.value.toUpperCase();

  // PIN modal
  $('btn-pin-cancel').onclick = () => { $('modal-pin').style.display='none'; _pendingJoinCode=''; };
  $('btn-pin-confirm').onclick = () => {
    const pin = $('inp-pin-modal').value.trim();
    if (!pin) return toast('Enter the PIN first!');
    $('modal-pin').style.display = 'none';
    send({ type:'join_room', code:_pendingJoinCode, pin });
  };
  $('inp-pin-modal').onkeydown = e => { if(e.key==='Enter') $('btn-pin-confirm').click(); };

  // Room
  $('btn-copy-code').onclick = () =>
    navigator.clipboard.writeText(roomCode).then(() => toast('Code ' + roomCode + ' copied!'));
  $('btn-start-game').onclick = () => send({ type:'start_game' });
  if ($('btn-add-bot'))    $('btn-add-bot').onclick    = () => send({ type:'add_bot' });
  if ($('btn-remove-bot')) $('btn-remove-bot').onclick = () => send({ type:'remove_bot' });
  $('btn-leave-room').onclick = () => {
    intentionalAction = true; send({ type:'leave_room' }); isHost = false; showScreen('menu');
    send({ type:'get_rooms' });
  };
  $('btn-logout').onclick = () => {
    intentionalAction = true; send({ type:'leave_room' }); myName='';
    if (ws) { ws.close(); ws=null; }
    showScreen('login'); setTimeout(connect, 500);
  };

  // Tap zone: mousedown sudah ditangani _mouseListener di attachRoundListeners.
  // onclick dibiarkan kosong agar tidak dobel-proses.
  $('btn-click').onclick = (e) => { e.preventDefault(); };

  // Chat
  $('btn-chat-send').onclick = sendChat;
  $('inp-chat').onkeydown = e => { if(e.key==='Enter') sendChat(); };

  // Stats
  $('btn-play-again').onclick = () => showScreen('room');
  $('btn-back-menu').onclick  = () => {
    intentionalAction = true; send({ type:'leave_room' }); showScreen('menu');
    send({ type:'get_rooms' });
  };

  connect();
});
