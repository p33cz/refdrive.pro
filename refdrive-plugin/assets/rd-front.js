
// ---- MĚŘENÍ AKTIVNÍHO ČASU STUDIA ----
var rdActiveStart = null;
var rdActiveTotal = parseInt(sessionStorage.getItem('rd_active_sec') || '0');

function rdActiveResume() {
  if (!rdActiveStart) rdActiveStart = Date.now();
}
function rdActivePause() {
  if (rdActiveStart) {
    rdActiveTotal += Math.round((Date.now() - rdActiveStart) / 1000);
    sessionStorage.setItem('rd_active_sec', rdActiveTotal);
    rdActiveStart = null;
  }
}

// Spustit při načtení
rdActiveResume();

// Pausovat při přepnutí tabu nebo okna
document.addEventListener('visibilitychange', function() {
  if (document.hidden) rdActivePause(); else rdActiveResume();
});
window.addEventListener('pagehide', rdActivePause);
window.addEventListener('blur', rdActivePause);
window.addEventListener('focus', rdActiveResume);

// Funkce pro získání minut aktivního studia (čas od načtení této stránky)
function rdGetActiveMin() {
  rdActivePause();
  var total = rdActiveTotal;
  rdActiveResume();
  return Math.round(total / 60);
}

// Pravidelně odesílat přírůstky aktivního času na server, aby se sčítaly
// přes celý kurz (lekce i test), ne jen na jedné stránce.
var rdFlushedSec = 0;
function rdFlushActiveTime() {
  rdActivePause();
  var delta = rdActiveTotal - rdFlushedSec;
  rdActiveResume();
  if (delta < 1) return;
  if (typeof rdAjax === 'undefined') return;
  var fd = new FormData();
  fd.append('action', 'rd_pripocti_cas');
  fd.append('sekundy', Math.min(300, delta));
  fetch(rdAjax.url, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(res){ if (res && res.success) rdFlushedSec = rdActiveTotal; })
    .catch(function(){});
}
setInterval(rdFlushActiveTime, 30000);
window.addEventListener('pagehide', rdFlushActiveTime);
document.addEventListener('visibilitychange', function() { if (document.hidden) rdFlushActiveTime(); });


// ---- ODPOČÍTÁVACÍ TIMER ----
var rdTimers = {};      // requestAnimationFrame handle
var rdTimerState = {};  // {remaining, running}

function fmt(ms) {
  var s = Math.ceil(ms / 1000);
  var m = Math.floor(s / 60), sec = s % 60;
  return m + ':' + (sec < 10 ? '0' : '') + sec;
}

function rdStartTimer(n) {
  if (window.rdDone && window.rdDone.includes(n)) return;
  // Náhledový režim lektora — okamžitě odemknout tlačítko bez čekání
  if (typeof rdAjax !== 'undefined' && rdAjax.nahled) {
    var btnN = document.getElementById('rdbtn' + n);
    var barN = document.getElementById('rdtb' + n);
    var txtN = document.getElementById('rdtt' + n);
    var wrapN = document.getElementById('rdtw' + n);
    if (btnN) { btnN.disabled = false; btnN.textContent = '✓ Dokončit lekci ' + n; }
    if (barN) barN.style.width = '0%';
    if (txtN) txtN.textContent = '';
    if (wrapN) wrapN.style.display = 'none';
    return;
  }
  // Pokud timer již existuje (pauza), jen obnovit
  if (rdTimerState[n]) {
    rdResumeTimer(n);
    return;
  }
  var lekce = document.querySelector('[data-lekce="' + n + '"]');
  var casAttr = lekce ? lekce.getAttribute('data-cas') : null;
  var cas = (casAttr !== null && casAttr !== '') ? parseInt(casAttr) : 180;
  var bar = document.getElementById('rdtb' + n);
  var txt = document.getElementById('rdtt' + n);
  var btn = document.getElementById('rdbtn' + n);
  if (!bar || !btn) return;

  if (cas === 0) { if (btn) { btn.disabled = false; btn.textContent = '✓ Dokončit lekci ' + n; } if (bar) bar.style.width = '0%'; if (txt) txt.textContent = ''; return; }
  rdTimerState[n] = { remaining: cas * 1000, running: false };
  bar.style.width = '100%';
  txt.textContent = fmt(cas * 1000);
  rdResumeTimer(n);
}

function rdResumeTimer(n) {
  if (!rdTimerState[n] || rdTimerState[n].running) return;
  if (window.rdDone && window.rdDone.includes(n)) return;
  rdTimerState[n].running = true;
  var bar = document.getElementById('rdtb' + n);
  var txt = document.getElementById('rdtt' + n);
  var btn = document.getElementById('rdbtn' + n);
  var lastTs = null;

  function step(ts) {
    if (!lastTs) lastTs = ts;
    var delta = ts - lastTs;
    lastTs = ts;
    rdTimerState[n].remaining = Math.max(0, rdTimerState[n].remaining - delta);
    var remaining = rdTimerState[n].remaining;
    var total = parseInt(document.querySelector('[data-lekce="' + n + '"]').getAttribute('data-cas') || 180) * 1000;
    bar.style.width = (remaining / total * 100).toFixed(2) + '%';
    txt.textContent = fmt(remaining);
    if (remaining > 0) {
      rdTimers[n] = requestAnimationFrame(step);
    } else {
      rdTimers[n] = null;
      rdTimerState[n].running = false;
      bar.style.width = '0%';
      txt.textContent = '';
      var wrap = document.getElementById('rdtw' + n);
      if (wrap) wrap.style.display = 'none';
      if (btn) { btn.disabled = false; btn.textContent = '✓ Dokončit lekci ' + n; }
    }
  }
  rdTimers[n] = requestAnimationFrame(step);
}

function rdPauseTimer(n) {
  if (!rdTimerState[n]) return;
  if (rdTimers[n]) { cancelAnimationFrame(rdTimers[n]); rdTimers[n] = null; }
  rdTimerState[n].running = false;
}

/* RefDrive – Frontend JS */
(function() {
  'use strict';

  var CELKEM = (typeof rdAjax !== 'undefined' && rdAjax.celkem) ? parseInt(rdAjax.celkem) : 10;
  // Progress se ukládá per-kód (localStorage je per-prohlížeč, ne per-uživatel,
  // takže bez klíčování podle kódu by nový kód "zdědil" hotové lekce předchozího).
  var KOD = (typeof rdAjax !== 'undefined' && rdAjax.kod) ? rdAjax.kod : '';
  var DK_KEY = 'rd_dk_' + KOD;
  var LEKCI_VERZE = (typeof rdAjax !== 'undefined' && rdAjax.verze) ? rdAjax.verze : '10';
  if (localStorage.getItem('rd_lv') !== LEKCI_VERZE) {
    localStorage.removeItem(DK_KEY);
    localStorage.setItem('rd_lv', LEKCI_VERZE);
  }
  // Server je zdroj pravdy (i prázdný seznam = žádná lekce hotová pro tento
  // kód). localStorage slouží jen jako fallback, pokud rdAjax není k dispozici.
  var dk = (typeof rdAjax !== 'undefined' && rdAjax.done)
    ? rdAjax.done.map(Number)
    : JSON.parse(localStorage.getItem(DK_KEY) || '[]');
  window.rdDone = dk;

  document.addEventListener('DOMContentLoaded', function() {
    initKurz();
    initKviz();
    initCert();
  });

  /* ---- KURZ ---- */
  function initKurz() {
    if (!document.getElementById('rd-kurz')) return;
    obnovStav();
    updateProg();
    var prvni = najdiPrvni();
    if (prvni) { otevrit(prvni); setTimeout(function() { rdStartTimer(prvni); }, 100); }

    document.querySelectorAll('.rd-lekce-head').forEach(function(h) {
      h.addEventListener('click', function() {
        var n = parseInt(h.closest('.rd-lekce').dataset.lekce);
        if (n > 1 && !dk.includes(n - 1)) { toast('Nejdříve dokončete lekci ' + (n-1), 'warn'); return; }
        var el = document.getElementById('rdlb' + n);
        var byl = el.classList.contains('rd-open');
        // Pausovat všechny běžící timery
        document.querySelectorAll('.rd-lekce').forEach(function(l) {
          var ln = parseInt(l.dataset.lekce);
          if (ln) rdPauseTimer(ln);
        });
        document.querySelectorAll('.rd-lekce-body').forEach(function(b) { b.classList.remove('rd-open'); });
        if (!byl) { el.classList.add('rd-open'); rdStartTimer(n); }
      });
    });

    document.querySelectorAll('.rd-btn-done').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var n = parseInt(btn.dataset.lekce);
        dokoncit(n);
      });
    });
  }

  function dokoncit(n) {
    if (!dk.includes(n)) {
      dk.push(n);
      window.rdDone = dk;
      localStorage.setItem(DK_KEY, JSON.stringify(dk));
      // Uložit na server
      if (typeof rdAjax !== 'undefined') {
        var fd = new FormData();
        fd.append('action', 'rd_dokoncit_lekci');
        fd.append('lekce', n);
        fetch(rdAjax.url, { method: 'POST', body: fd, credentials: 'same-origin' });
      }
    }
    var lekce = document.querySelector('[data-lekce="' + n + '"]');
    lekce.classList.add('rd-done');
    var btn = document.getElementById('rdbtn' + n);
    if (btn) { btn.disabled = true; btn.textContent = '✓ Lekce dokončena'; btn.classList.remove('rd-btn-green'); btn.classList.add('rd-btn-done-ok'); }
    var st = document.getElementById('rdls' + n);
    if (st) st.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    document.getElementById('rdlb' + n).classList.remove('rd-open');
    updateProg();
    var dal = n + 1;
    if (dal <= CELKEM) {
      setTimeout(function() {
        document.querySelectorAll('.rd-lekce-body').forEach(function(b) { b.classList.remove('rd-open'); });
        var nb = document.getElementById('rdlb' + dal);
        if (nb) { nb.classList.add('rd-open'); nb.closest('.rd-lekce').scrollIntoView({behavior:'smooth', block:'start'}); rdStartTimer(dal); }
      }, 200);
      toast('Lekce ' + n + ' dokončena! Pokračujte na lekci ' + dal + '.', 'ok');
    } else {
      var fc = document.getElementById('rd-kurz-complete');
      if (fc) { fc.style.display = 'block'; fc.scrollIntoView({behavior:'smooth'}); }
      toast('Všechny lekce dokončeny!', 'ok');
    }
  }

  function otevrit(n) {
    var el = document.getElementById('rdlb' + n);
    if (el) el.classList.add('rd-open');
  }

  function najdiPrvni() {
    for (var i = 1; i <= CELKEM; i++) { if (!dk.includes(i)) return i; }
    return null;
  }

  function obnovStav() {
    dk.forEach(function(n) {
      var l = document.querySelector('[data-lekce="' + n + '"]');
      if (!l) return;
      l.classList.add('rd-done');
      var st = document.getElementById('rdls' + n);
      if (st) st.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
      var btn = document.getElementById('rdbtn' + n);
      if (btn) { btn.disabled = true; btn.textContent = '✓ Lekce dokončena'; btn.classList.remove('rd-btn-green'); btn.classList.add('rd-btn-done-ok'); }
    });
    if (dk.length >= CELKEM) {
      var fc = document.getElementById('rd-kurz-complete');
      if (fc) fc.style.display = 'block';
    }
  }

  function updateProg() {
    var pct = dk.length > 0 ? Math.round(dk.length / CELKEM * 100) : 0;
    var f = document.getElementById('rd-prog-fill');
    var t = document.getElementById('rd-prog-txt');
    if (f) f.style.width = pct + '%';
    if (t) t.textContent = dk.length + ' / ' + CELKEM + ' lekcí';
  }

  /* ---- KVÍZ ---- */
  function initKviz() {
    document.querySelectorAll('.rd-opt').forEach(function(opt) {
      opt.addEventListener('click', function(e) {
        var radio = opt.querySelector('input[type="radio"]');
        if (radio) {
          radio.checked = true;
          // Dispatch change event
          var evt = new Event('change', {bubbles: true});
          radio.dispatchEvent(evt);
        }
        opt.closest('.rd-opts').querySelectorAll('.rd-opt').forEach(function(o) { o.classList.remove('rd-sel'); });
        opt.classList.add('rd-sel');
        opt.closest('.rd-otazka').classList.remove('rd-q-err');
      });
    });
    // Také zachytit přímou změnu radio
    document.querySelectorAll('.rd-opt input[type="radio"]').forEach(function(radio) {
      radio.addEventListener('change', function() {
        var opts = radio.closest('.rd-opts');
        if (opts) opts.querySelectorAll('.rd-opt').forEach(function(o) { o.classList.remove('rd-sel'); });
        radio.closest('.rd-opt').classList.add('rd-sel');
      });
    });

    var form = document.getElementById('rd-kviz-form');
    if (!form) return;
    form.addEventListener('submit', function(e) {
      // Jednoduchá validace - nekontrolovat na mobilu, jen odeslat
      var btn = document.getElementById('rd-submit');
      if (btn) { btn.textContent = 'Odesílám...'; btn.disabled = true; btn.style.opacity = '.6'; }
    });
  }

  /* ---- CERTIFIKÁT – SVG download ---- */
  function initCert() {
    window.rdDownloadCert = function(jmeno, datum, firma, score) {
      var svg = generateCertSVG(jmeno, datum, firma, score);
      var blob = new Blob([svg], {type: 'image/svg+xml'});
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href = url;
      a.download = 'certifikat-' + jmeno.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '') + '-' + datum.replace(/\./g, '-') + '.svg';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      toast('Certifikát byl stažen!', 'ok');
    };
  }

  function generateCertSVG(jmeno, datum, firma, score) {
    return '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="620" viewBox="0 0 900 620">\n' +
    '<defs>\n' +
    '  <style>@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&amp;display=swap");</style>\n' +
    '</defs>\n' +
    '<rect width="900" height="620" fill="#0d1117"/>\n' +
    '<rect x="1" y="1" width="898" height="618" fill="none" stroke="#21262d" stroke-width="1" rx="14"/>\n' +
    '<rect x="9" y="9" width="882" height="602" fill="none" stroke="#1d4ed8" stroke-width="0.5" rx="10" opacity="0.4"/>\n' +
    '<rect x="0" y="0" width="900" height="7" fill="#2563eb" rx="0"/>\n' +
    '<rect x="0" y="0" width="7" height="620" fill="#2563eb" rx="0"/>\n' +
    '<text x="450" y="70" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" font-weight="700" fill="#60a5fa" letter-spacing="4">CERTIFIKÁT O ABSOLVOVÁNÍ</text>\n' +
    '<line x1="180" y1="88" x2="720" y2="88" stroke="#21262d" stroke-width="0.5"/>\n' +
    '<text x="450" y="128" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="24" font-weight="800" fill="#e6edf3" letter-spacing="1">ŠKOLENÍ REFERENTSKÝCH ŘIDIČŮ</text>\n' +
    '<text x="450" y="168" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="13" fill="#8b949e" font-style="italic">uděluje se</text>\n' +
    '<text x="450" y="228" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="42" font-weight="800" fill="#e6edf3">' + escXml(jmeno) + '</text>\n' +
    '<line x1="200" y1="248" x2="700" y2="248" stroke="#2563eb" stroke-width="2"/>\n' +
    '<rect x="310" y="272" width="280" height="90" rx="10" fill="#132a1e" stroke="#196530" stroke-width="0.5"/>\n' +
    '<text x="450" y="326" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="44" font-weight="800" fill="#3fb950">' + score + '%</text>\n' +
    '<text x="450" y="350" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" fill="#8b949e">výsledek závěrečného testu</text>\n' +
    '<rect x="80" y="388" width="730" height="1" fill="#21262d"/>\n' +
    '<text x="160" y="416" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" fill="#484f58">FIRMA</text>\n' +
    '<text x="160" y="438" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="600" fill="#e6edf3">' + escXml(firma) + '</text>\n' +
    '<text x="450" y="416" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" fill="#484f58">DATUM ABSOLVOVÁNÍ</text>\n' +
    '<text x="450" y="438" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="600" fill="#e6edf3">' + escXml(datum) + '</text>\n' +
    '<text x="740" y="416" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="11" fill="#484f58">PLATNOST</text>\n' +
    '<text x="740" y="438" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="15" font-weight="600" fill="#e6edf3">1 rok</text>\n' +
    '<rect x="80" y="470" width="730" height="1" fill="#21262d"/>\n' +
    '<line x1="130" y1="535" x2="330" y2="535" stroke="#30363d" stroke-width="0.5"/>\n' +
    '<text x="230" y="550" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="10" fill="#484f58">ORGANIZÁTOR ŠKOLENÍ</text>\n' +
    '<line x1="570" y1="535" x2="770" y2="535" stroke="#30363d" stroke-width="0.5"/>\n' +
    '<text x="670" y="550" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="10" fill="#484f58">' + escXml(datum) + '</text>\n' +
    '<text x="820" y="610" text-anchor="end" font-family="Inter,Arial,sans-serif" font-size="9" fill="#21262d">refdrive.pro</text>\n' +
    '</svg>';
  }

  function escXml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ---- TOAST ---- */
  function toast(msg, typ) {
    var el = document.createElement('div');
    el.textContent = msg;
    var bg = typ === 'ok' ? '#132a1e' : '#1f1315';
    var bc = typ === 'ok' ? '#196530' : '#da3633';
    var tc = typ === 'ok' ? '#3fb950' : '#f47067';
    el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' + bg + ';color:' + tc + ';border:1px solid ' + bc + ';padding:12px 20px;border-radius:8px;font-family:Inter,sans-serif;font-size:13px;font-weight:500;max-width:320px;line-height:1.4;transition:all .3s;transform:translateY(60px);opacity:0';
    document.body.appendChild(el);
    setTimeout(function() { el.style.transform = 'translateY(0)'; el.style.opacity = '1'; }, 20);
    setTimeout(function() { el.style.transform = 'translateY(60px)'; el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 350); }, 3500);
  }

})();

// ── Rozeslání kódů zaměstnancům ──────────────────────────────────────────────
var rdRozeslatData = [];

window.rdRozeslatTab = function(tab) {
  var pr = document.getElementById('rd-panel-rucne');
  var pc = document.getElementById('rd-panel-csv');
  var tr = document.getElementById('rd-tab-rucne');
  var tc = document.getElementById('rd-tab-csv');
  if (!pr) return;
  pr.style.display = tab === 'rucne' ? 'block' : 'none';
  pc.style.display = tab === 'csv'   ? 'block' : 'none';
  tr.style.borderBottomColor = tab === 'rucne' ? '#7c3aed' : 'transparent';
  tc.style.borderBottomColor = tab === 'csv'   ? '#7c3aed' : 'transparent';
  tr.style.opacity = tab === 'rucne' ? '1' : '0.7';
  tc.style.opacity = tab === 'csv'   ? '1' : '0.7';
};

window.rdPridatRadek = function() {
  var c = document.getElementById('rd-rozeslat-radky');
  if (!c) return;
  var maxKodu = parseInt(c.getAttribute('data-max') || '99');
  if (c.querySelectorAll('.rd-rzr').length >= maxKodu) return;
  var d = document.createElement('div');
  d.className = 'rd-rzr';
  d.style.cssText = 'display:flex;flex-direction:column;gap:8px;margin-bottom:12px';
  d.innerHTML =
    '<input type="text" placeholder="Jan Novák" class="rd-rzr-input" style="background:rgba(255,255,255,.06);border:1px solid rgba(124,58,237,.25);border-radius:8px;padding:12px;color:inherit;font-size:16px;box-sizing:border-box;-webkit-appearance:none;pointer-events:auto;-webkit-user-select:text;user-select:text">'
  + '<input type="email" placeholder="jan@firma.cz" class="rd-rzr-input" style="background:rgba(255,255,255,.06);border:1px solid rgba(124,58,237,.25);border-radius:8px;padding:12px;color:inherit;font-size:16px;box-sizing:border-box;-webkit-appearance:none;pointer-events:auto;-webkit-user-select:text;user-select:text">'
  + '<button type="button" onclick="this.closest(\'.rd-rzr\').remove();rdAktualizujPridatBtn();" style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);border-radius:8px;color:#f87171;padding:8px 14px;cursor:pointer;min-height:44px">Odebrat</button>';
  c.appendChild(d);
  setTimeout(function() { d.querySelector('input').focus(); }, 50);
  rdAktualizujPridatBtn();
};

window.rdAktualizujPridatBtn = function() {
  var c = document.getElementById('rd-rozeslat-radky');
  var btn = document.getElementById('rd-pridat-btn');
  if (!c || !btn) return;
  var maxKodu = parseInt(c.getAttribute('data-max') || '99');
  var plno = c.querySelectorAll('.rd-rzr').length >= maxKodu;
  btn.disabled = plno;
  btn.style.opacity = plno ? '.4' : '1';
  btn.style.cursor = plno ? 'default' : 'pointer';
};

window.rdNacistCSV = function(input) {
  var file = input.files[0];
  if (!file) return;
  document.getElementById('rd-csv-label-text').textContent = file.name;
  var maxKodu = parseInt((document.getElementById('rd-panel-csv') || {getAttribute: function(){return '99';}}).getAttribute('data-max') || '99');
  var reader = new FileReader();
  reader.onload = function(e) {
    var lines = e.target.result.split(/\r?\n/).filter(Boolean);
    var nahled = document.getElementById('rd-csv-nahled');
    var zamestnanci = [];
    lines.forEach(function(line, i) {
      if (i === 0 && line.toLowerCase().startsWith('jmeno')) return;
      var parts = line.split(',');
      if (parts.length >= 2) zamestnanci.push({jmeno: parts[0].trim(), email: parts[1].trim()});
    });
    if (zamestnanci.length > maxKodu) {
      nahled.innerHTML = '<div style="color:#f87171;font-size:13px;margin-top:8px">CSV obsahuje ' + zamestnanci.length + ' zaměstnanců, ale máte jen ' + maxKodu + ' volných kódů. Bude přiřazeno prvních ' + maxKodu + '.</div>';
      zamestnanci = zamestnanci.slice(0, maxKodu);
    }
    rdRozeslatData = zamestnanci;
    nahled.innerHTML += '<div style="font-size:13px;margin-top:8px;opacity:.8">Načteno <strong>' + zamestnanci.length + '</strong> zaměstnanců:</div>'
      + zamestnanci.map(function(z) { return '<div style="font-size:12px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.05)">' + z.jmeno + ' — ' + z.email + '</div>'; }).join('');
  };
  reader.readAsText(file, 'UTF-8');
};

window.rdOdeslatKody = function() {
  var jeCSV = document.getElementById('rd-panel-csv') && document.getElementById('rd-panel-csv').style.display !== 'none';
  var zamestnanci = [];
  if (jeCSV) {
    zamestnanci = rdRozeslatData;
  } else {
    document.querySelectorAll('.rd-rzr').forEach(function(r) {
      var inputs = r.querySelectorAll('input');
      var j = inputs[0] ? inputs[0].value.trim() : '';
      var em = inputs[1] ? inputs[1].value.trim() : '';
      if (j && em) zamestnanci.push({jmeno: j, email: em});
    });
  }
  if (!zamestnanci.length) { alert('Zadejte alespoň jednoho zaměstnance.'); return; }
  var btn = document.getElementById('rd-rozeslat-btn');
  if (!btn) return;
  btn.disabled = true;
  btn.dataset.orig = btn.innerHTML;
  btn.innerHTML = 'Odesílám…';
  var fd = new FormData();
  fd.append('action', 'rd_rozeslat_kody');
  fd.append('nonce', (typeof rdAjax !== 'undefined' && rdAjax.firma_nonce) ? rdAjax.firma_nonce : '');
  fd.append('zamestnanci', JSON.stringify(zamestnanci));
  fd.append('firma_email', (typeof rdAjax !== 'undefined' && rdAjax.firma_email) ? rdAjax.firma_email : '');
  var ajaxUrl = (typeof rdAjax !== 'undefined' && rdAjax.url) ? rdAjax.url : '/wp-admin/admin-ajax.php';
  fetch(ajaxUrl, {method: 'POST', body: fd, credentials: 'include'})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.orig || 'Rozeslat e-maily';
      var vysl = document.getElementById('rd-rozeslat-vysledky');
      if (!vysl) return;
      vysl.style.display = 'block';
      if (res.success) {
        var html = '<div style="font-size:13px;font-weight:600;margin-bottom:10px">Výsledky rozeslání:</div>';
        res.data.vysledky.forEach(function(v) {
          var ok = v.stav === 'ok';
          var skip = v.stav === 'absolvoval' || v.stav === 'prirazen';
          var icon = ok
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            : skip
            ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
          var bg = ok ? 'rgba(34,197,94,.1)' : skip ? 'rgba(245,158,11,.1)' : 'rgba(239,68,68,.1)';
          var border = ok ? 'rgba(34,197,94,.2)' : skip ? 'rgba(245,158,11,.2)' : 'rgba(239,68,68,.2)';
          html += '<div style="display:flex;align-items:center;gap:10px;padding:8px;border-radius:8px;margin-bottom:6px;background:' + bg + ';border:1px solid ' + border + '">'
            + icon + '<div><strong>' + v.jmeno + '</strong> — ' + v.email
            + (v.kod ? ' <code style="opacity:.7">' + v.kod + '</code>' : '')
            + '<br><span style="font-size:11px;opacity:.7">' + v.msg + '</span></div></div>';
        });
        vysl.innerHTML = html;
        setTimeout(function() {
          document.body.style.overflow = '';
          location.reload();
        }, 3000);
      } else {
        vysl.innerHTML = '<div style="color:#f87171;padding:10px;border-radius:8px;background:rgba(239,68,68,.1)">' + (res.data && res.data.msg ? res.data.msg : 'Chyba při odesílání.') + '</div>';
      }
    })
    .catch(function(err) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.orig;
      var vysl = document.getElementById('rd-rozeslat-vysledky');
      if (vysl) {
        vysl.style.display = 'block';
        vysl.innerHTML = '<div style="color:#f87171;padding:10px;border-radius:8px;background:rgba(239,68,68,.1)">Chyba: ' + (err.message || err) + '</div>';
      }
    });
};

window.rdZnovuOdeslat = function(kod) {
  if (!confirm('Znovu odeslat kód ' + kod + ' zaměstnanci?')) return;
  var fd = new FormData();
  fd.append('action', 'rd_znovu_odeslat_kod');
  fd.append('kod', kod);
  fd.append('firma_email', (typeof rdAjax !== 'undefined' && rdAjax.firma_email) ? rdAjax.firma_email : '');
  var ajaxUrl = (typeof rdAjax !== 'undefined' && rdAjax.url) ? rdAjax.url : '/wp-admin/admin-ajax.php';
  fetch(ajaxUrl, {method: 'POST', body: fd, credentials: 'include'})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res && res.success) {
        alert('E-mail byl znovu odeslán.');
      } else {
        alert('Chyba při odesílání. Zkuste to znovu.');
      }
    })
    .catch(function() { alert('Chyba sítě.'); });
};

window.rdPreraditKod = function(kod, puvodniJmeno, puvodniEmail) {
  var modal = document.getElementById('rd-priradit-modal');
  if (!modal) return;
  // Uložit aktuální kód pro odeslání
  modal.dataset.kod = kod;
  // Zobrazit info o původním zaměstnanci
  document.getElementById('rd-priradit-info').textContent =
    'Aktuálně přiřazeno: ' + puvodniJmeno + ' (' + puvodniEmail + '). Původní zaměstnanec obdrží informační e-mail.';
  // Vyčistit pole
  document.getElementById('rd-priradit-jmeno').value = '';
  document.getElementById('rd-priradit-email').value = '';
  document.getElementById('rd-priradit-vysledek').style.display = 'none';
  // Otevřít
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  setTimeout(function() { document.getElementById('rd-priradit-jmeno').focus(); }, 50);
};

window.rdOdeslatPrerazeni = function() {
  var modal = document.getElementById('rd-priradit-modal');
  var kod = modal.dataset.kod;
  var jmeno = document.getElementById('rd-priradit-jmeno').value.trim();
  var email = document.getElementById('rd-priradit-email').value.trim();
  var vysl = document.getElementById('rd-priradit-vysledek');

  if (!jmeno || !email) { alert('Zadejte jméno a e-mail nového zaměstnance.'); return; }

  var btn = document.getElementById('rd-priradit-btn');
  btn.disabled = true;
  btn.dataset.orig = btn.innerHTML;
  btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Odesílám…';

  var fd = new FormData();
  fd.append('action', 'rd_priradit_kod');
  fd.append('kod', kod);
  fd.append('nove_jmeno', jmeno);
  fd.append('novy_email', email);
  fd.append('firma_email', (typeof rdAjax !== 'undefined' && rdAjax.firma_email) ? rdAjax.firma_email : '');

  var ajaxUrl = (typeof rdAjax !== 'undefined' && rdAjax.url) ? rdAjax.url : '/wp-admin/admin-ajax.php';
  fetch(ajaxUrl, {method: 'POST', body: fd, credentials: 'include'})
    .then(function(r) { return r.json(); })
    .then(function(res) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.orig || 'Přeřadit a odeslat';
      vysl.style.display = 'block';
      if (res && res.success) {
        vysl.innerHTML = '<div style="color:#22c55e;font-size:13px;padding:10px;border-radius:8px;background:rgba(34,197,94,.1)">✓ ' + (res.data && res.data.msg ? res.data.msg : 'Hotovo.') + '</div>';
        // Změnit "Zrušit" na "Zavřít"
        var cancelBtn = document.querySelector('#rd-priradit-modal .rd-priradit-cancel');
        if (cancelBtn) cancelBtn.textContent = 'Zavřít';
        setTimeout(function() {
          document.body.style.overflow = '';
          location.reload();
        }, 2000);
      } else {
        vysl.innerHTML = '<div style="color:#f87171;font-size:13px;padding:10px;border-radius:8px;background:rgba(239,68,68,.1)">' + (res.data && res.data.msg ? res.data.msg : 'Chyba.') + '</div>';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.orig;
      alert('Chyba sítě.');
    });
};
