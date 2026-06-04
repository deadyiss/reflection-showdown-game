/* ============================================================
   game-canvas.js — DuelRenderer (DOM + sprite pixel SVG)
   Tema RETRO: sprite dari sprites.js, animasi CSS idle/win/lose.
   ROSTER 5 karakter bernama. Sistem match/room TIDAK diubah.
   ============================================================ */

const ROSTER = [
  { id:"reaper", name:"Mira",  role:"Scythe Heir",    element:"Blood",   color:"#d23b40" },
  { id:"knight", name:"Arden", role:"Knight of Light",   element:"Lightning",   color:"#e0b552" },
  { id:"ninja",  name:"Rin",   role:"Shadow Ninja",   element:"Shadow",color:"#b07ad6" },
  { id:"elf",    name:"Aoi",   role:"Elf Archer",      element:"Ice",      color:"#5cc6c0" },
  { id:"wizard", name:"Kosma", role:"Star Mage", element:"Cosmic",  color:"#6b8bf0" },
];
if (typeof window !== 'undefined') window.ROSTER = ROSTER;

class DuelRenderer {
  constructor(arenaEl, centerEl, stageEl) {
    this.arena  = arenaEl;
    this.center = centerEl;
    this.stage  = stageEl;
    this.lanes  = [];
    this.players = [];
  }

  setPlayers(names, myName, charMap = {}) {
    this.players = names.map((name, i) => ({
      name, isMe: name === myName,
      charIdx: charMap[name] != null ? charMap[name] : (i % ROSTER.length),
    }));
    this._buildLanes();
  }

  _spriteHTML(charId, state) {
    return (typeof SPRITES !== 'undefined') ? SPRITES.get(charId, state) : '';
  }

  _buildLanes() {
    this.arena.innerHTML = '';
    this.lanes = [];
    const n = this.players.length;
    this.players.forEach((p, i) => {
      const char = ROSTER[p.charIdx % ROSTER.length];
      const lane = document.createElement('div');
      lane.className = 'duel-lane';

      const pips = document.createElement('div');
      pips.className = 'duel-lane-pips';

      const sprite = document.createElement('div');
      sprite.className = 'duel-sprite';
      const holder = document.createElement('div');
      holder.className = 'spr-holder idle';
      holder.innerHTML = this._spriteHTML(char.id, 'idle');
      if (i >= Math.ceil(n / 2)) holder.style.transform = 'scaleX(-1)';
      sprite.appendChild(holder);

      const platform = document.createElement('div');
      platform.className = 'duel-platform';
      sprite.appendChild(platform);

      const badge = document.createElement('div');
      badge.className = 'duel-badge';
      badge.style.display = 'none';
      sprite.appendChild(badge);

      const nm = document.createElement('div');
      nm.className = 'duel-name';
      nm.innerHTML = `<div class="nm${p.isMe?' me':''}">${esc(p.name)}</div>
                      <div class="el" style="color:${char.color}">${p.isMe?'YOU':char.element}</div>`;

      lane.appendChild(pips);
      lane.appendChild(sprite);
      lane.appendChild(nm);
      this.arena.appendChild(lane);

      this.lanes.push({ el:lane, sprite, holder, badge, pips, char, isMe:p.isMe, name:p.name, flip:i>=Math.ceil(n/2) });
    });
  }

  _setState(L, state) {
    L.holder.innerHTML = this._spriteHTML(L.char.id, state);
    L.holder.className = 'spr-holder ' + (state === 'win' ? 'win win-glow' : state === 'lose' ? 'lose' : 'idle');
    if (L.flip) L.holder.style.transform = 'scaleX(-1)';
  }

  setScores(scores, totalRounds) {
    this.lanes.forEach(L => {
      const s = scores[L.name] || 0;
      L.pips.innerHTML = '';
      for (let k = 0; k < totalRounds; k++) {
        const pip = document.createElement('span');
        pip.className = 'duel-lane-pip' + (k < s ? ' on' : '');
        L.pips.appendChild(pip);
      }
    });
  }

  resetRound() {
    this.lanes.forEach(L => {
      L.el.classList.remove('dim');
      this._setState(L, 'idle');
      L.badge.style.display = 'none';
    });
    this.center.innerHTML = '';
  }

  showCount(n) { this.center.innerHTML = `<div class="px-count">${n}</div>`; }

  showWait() {
    this.center.innerHTML =
      `<div class="px-prompt"><div class="kanji-pixel">待</div><div class="px-label">HOLD!</div></div>`;
  }

  showSignal(target) {
    let t = '';
    if (target) {
      if (target.type === 'key') t = `<div class="px-target">PRESS [ ${esc(target.value)} ]</div>`;
      else { const m={left:'LEFT CLICK',right:'RIGHT CLICK',middle:'MIDDLE CLICK'}[target.value]||'CLICK'; t=`<div class="px-target">${m}</div>`; }
    }
    this.center.innerHTML = `<div class="px-prompt signal"><div class="kanji-pixel slash">斬</div><div class="px-signal">STRIKE!</div>${t}</div>`;
    this.stage.classList.add('flash-crimson');
    setTimeout(() => this.stage.classList.remove('flash-crimson'), 350);
  }

  clearSignalBg() { this.center.innerHTML = ''; }

  showResult(results, earlyEnded, culprit) {
    const byName = {}; results.forEach(r => byName[r.username] = r);
    const winner = results.find(r => r.rank === 1 && !r.is_early && r.rt < 9000);

    this.lanes.forEach(L => {
      const r = byName[L.name];
      const won = winner && r && r.username === winner.username;
      if (won) {
        this._setState(L, 'win');
        L.el.classList.remove('dim');
        if (typeof FX !== 'undefined') { FX.slash(L.el, L.char.element, L.char.color); FX.shake(this.stage, L.char.element); }
      } else {
        this._setState(L, 'lose');
        L.el.classList.add('dim');
      }
      if (r && won) {
        // Pemenang: tulisan WIN! besar di atas kepala
        L.badge.style.display = '';
        L.badge.className = 'duel-badge win';
        L.badge.textContent = 'WIN! ' + (r.rt < 9000 ? r.rt + 'ms' : '');
      } else if (r && r.is_early) {
        // Early click: tulisan peringatan
        L.badge.style.display = '';
        L.badge.className = 'duel-badge flinch';
        L.badge.textContent = 'FLINCH!';
      } else {
        // Kalah biasa: tidak ada tulisan, cukup redup
        L.badge.style.display = 'none';
      }
    });
    this.center.innerHTML = '';
  }

  static buildRoundPips(container, total, current, finished) {
    container.innerHTML = '';
    for (let i = 0; i < total; i++) {
      const pip = document.createElement('span');
      let cls = 'duel-pip';
      if (i + 1 < current || (i + 1 === current && finished)) cls += ' done';
      else if (i + 1 === current) cls += ' current';
      pip.className = cls;
      container.appendChild(pip);
    }
  }

  destroy() { this.arena.innerHTML=''; this.center.innerHTML=''; }
}

if (typeof esc === 'undefined') {
  window.esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
if (typeof window !== 'undefined') window.DuelRenderer = DuelRenderer;
