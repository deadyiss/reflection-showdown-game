/* ============================================================
   fx.js — efek tebasan per elemen (vanilla, port dari fx.jsx)
   Dipanggil: FX.slash(laneEl, element, color)
   ============================================================ */
const FX = (() => {

  function el(tag, style, parent) {
    const d = document.createElement(tag);
    Object.assign(d.style, style);
    if (parent) parent.appendChild(d);
    return d;
  }

  function burst(parent, n, color, kind, spread = 120, size = 10, dur = 540) {
    for (let i = 0; i < n; i++) {
      const ang = (i / n) * Math.PI * 2 + Math.random() * 0.5;
      const dist = spread * (0.5 + Math.random() * 0.7);
      const tx = Math.cos(ang) * dist, ty = Math.sin(ang) * dist;
      const s = size * (0.6 + Math.random() * 0.8);
      const delay = Math.random() * 90;
      const p = el('div', {
        position: 'absolute', top: '50%', left: '50%', width: s + 'px', height: s + 'px',
        pointerEvents: 'none',
        animation: `${kind === 'drop' ? 'fxDrop' : 'fxBurst'} ${dur}ms cubic-bezier(.2,.7,.3,1) ${delay}ms both`,
      }, parent);
      p.style.setProperty('--tx', tx + 'px');
      p.style.setProperty('--ty', ty + 'px');
      p.style.setProperty('--rot', (Math.random() * 360) + 'deg');
      if (kind === 'shard') {
        p.style.height = (s * 2.2) + 'px';
        p.style.background = `linear-gradient(${color}, transparent)`;
        p.style.clipPath = 'polygon(50% 0, 100% 100%, 0 100%)';
        p.style.filter = `drop-shadow(0 0 5px ${color})`;
      } else if (kind === 'star') {
        p.style.background = color;
        p.style.clipPath = 'polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)';
        p.style.filter = `drop-shadow(0 0 6px ${color})`;
      } else if (kind === 'drop') {
        p.style.width = (s * 0.7) + 'px';
        p.style.height = (s * 1.3) + 'px';
        p.style.background = color;
        p.style.borderRadius = '60% 60% 60% 0';
        p.style.filter = `drop-shadow(0 0 4px ${color})`;
      } else {
        p.style.borderRadius = '50%';
        p.style.background = color;
        p.style.boxShadow = `0 0 8px ${color}`;
      }
    }
  }

  function tintSlash(parent, color, soft) {
    const s = el('div', {
      position: 'absolute', top: '50%', left: '50%', width: '240%', height: '16px',
      marginLeft: '-120%', marginTop: '-8px',
      background: `linear-gradient(90deg, transparent, #fff 42%, ${soft || '#fff'} 52%, transparent)`,
      boxShadow: `0 0 28px ${color}`, animation: 'fxSlashTint .55s ease forwards', pointerEvents: 'none',
    }, parent);
  }

  // Tampilkan efek pada lane pemenang
  function slash(laneEl, element, color) {
    if (!laneEl) return;
    const layer = el('div', {
      position: 'absolute', inset: '0', zIndex: '8', pointerEvents: 'none', overflow: 'visible',
    }, laneEl);
    const center = el('div', { position: 'absolute', top: '48%', left: '0', right: '0' }, layer);

    // Flash arena-lokal
    el('div', {
      position: 'absolute', inset: '0',
      background: `radial-gradient(60% 70% at 50% 55%, ${color}, transparent 70%)`,
      animation: 'fxFlash .5s ease forwards', mixBlendMode: 'screen',
    }, laneEl);

    if (element === 'Petir') {
      tintSlash(center, color, '#ffe9a8'); burst(center, 10, color, 'spark', 130);
    } else if (element === 'Es') {
      tintSlash(center, color, '#dffbfb'); burst(center, 12, color, 'shard', 130, 11);
    } else if (element === 'Kosmik') {
      el('div', { position:'absolute', top:'50%', left:'50%', width:'120px', height:'120px',
        marginLeft:'-60px', marginTop:'-60px', borderRadius:'50%', border:`3px solid ${color}`,
        animation:'fxRing .55s ease forwards' }, center);
      tintSlash(center, color, '#dfe6ff'); burst(center, 11, color, 'star', 140, 13);
    } else if (element === 'Bayangan') {
      [0,70,140].forEach(d => {
        el('div', { position:'absolute', top:'50%', left:'50%', width:'230%', height:'13px',
          marginLeft:'-115%', marginTop:'-6px',
          background:`linear-gradient(90deg, transparent, ${color} 50%, transparent)`,
          boxShadow:`0 0 22px ${color}`, animation:`fxShadowSlash .55s ease ${d}ms forwards` }, center);
      });
      burst(center, 8, color, 'dot', 110);
    } else { // Darah / default
      tintSlash(center, color, '#ff8a8f'); burst(center, 14, color, 'drop', 120, 12);
    }

    setTimeout(() => layer.remove(), 700);
  }

  // Camera shake pada elemen panggung
  const SHAKE_BY_ELEMENT = { Petir:'shake-hard', Darah:'shake-rumble', Bayangan:'shake-flicker', Es:'shake-soft', Kosmik:'shake-soft' };
  function shake(stageEl, element) {
    const cls = SHAKE_BY_ELEMENT[element] || 'shake-soft';
    stageEl.classList.add(cls);
    setTimeout(() => stageEl.classList.remove(cls), 600);
  }

  // Sakura jatuh — isi sebuah .petal-field
  function spawnPetals(fieldEl, n = 12) {
    if (!fieldEl) return;
    fieldEl.innerHTML = '';
    for (let i = 0; i < n; i++) {
      const size = 6 + Math.random() * 9;
      const p = el('div', {
        left: (Math.random() * 100) + '%',
        width: size + 'px', height: (size * 0.78) + 'px',
        transform: `rotate(${Math.random()*360}deg)`,
        animation: `petalFall ${9 + Math.random()*9}s linear ${-Math.random()*14}s infinite`,
      }, fieldEl);
      p.className = 'petal';
    }
  }

  return { slash, shake, spawnPetals, SHAKE_BY_ELEMENT };
})();
