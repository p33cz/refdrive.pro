/**
 * RefDrive Aurora — dopravní pozadí úvodní sekce (hero) a patičky.
 * Styl se volí v Customizeru (Vzhled → Přizpůsobit → Dopravní pozadí) a sem přichází
 * přes window.RDBG_STYLE. Další styly stačí doplnit do registru STYLES dole
 * (a do rdaurora_bg_doprava_styly() ve functions.php).
 *
 * Barvy jsou v SVG jen jako CSS proměnné (var(--rd-grad-from), --rdbg-head apod.) — přepnutí
 * barevného tématu i světlého/tmavého režimu se tak projeví okamžitě, bez překreslení.
 * Geometrie závisí na rozměrech stránky, proto se SVG generuje tady a při změně šířky znovu.
 */
(function () {
  'use strict';
  var STYLE = window.RDBG_STYLE;
  if (!STYLE || STYLE === 'vypnuto' || !document.querySelector) return;

  var NS = 'http://www.w3.org/2000/svg';
  var C2 = 'var(--rd-grad-to)', A1 = 'var(--rd-accent-1)', A2 = 'var(--rd-accent-2)';
  var f = function (n) { return (Math.round(n * 10) / 10).toString(); };
  var uid = 0;

  /* ------------------------------------------------------------------
     NOČNÍ DÁLNICE — perspektivní pruhy mizející k horizontu, jezdící auta
     vp = úběžník (horizont), spread = jak široko se pruhy rozevírají u spodního okraje
     ------------------------------------------------------------------ */
  function highway(W, H, o) {
    var id = 'rdbgh' + (++uid), vp = o.vp, by = H + 40, n = o.lanes, i, k;
    var Q = .8;   // pomlčky v geometrické řadě t = Q^k → zvětšení skupiny o 1/Q je plynulá smyčka
    var s = '<defs>' +
      '<radialGradient id="' + id + 'glow"><stop offset="0" style="stop-color:' + A1 + ';stop-opacity:.55"/><stop offset=".5" style="stop-color:' + C2 + ';stop-opacity:.15"/><stop offset="1" style="stop-color:' + C2 + ';stop-opacity:0"/></radialGradient>' +
      '<radialGradient id="' + id + 'head"><stop offset="0" style="stop-color:var(--rdbg-head);stop-opacity:1"/><stop offset=".3" style="stop-color:var(--rdbg-head);stop-opacity:.45"/><stop offset="1" style="stop-color:var(--rdbg-head);stop-opacity:0"/></radialGradient>' +
      '<radialGradient id="' + id + 'tail"><stop offset="0" style="stop-color:var(--rdbg-tail);stop-opacity:1"/><stop offset=".3" style="stop-color:var(--rdbg-tail);stop-opacity:.45"/><stop offset="1" style="stop-color:var(--rdbg-tail);stop-opacity:0"/></radialGradient>' +
      '</defs>';
    // horizont: tenká linie s úzkou září (ne „osvětlení“ plochy)
    s += '<ellipse cx="' + f(vp.x) + '" cy="' + f(vp.y) + '" rx="' + f(W * o.glowRx) + '" ry="' + o.glowRy + '" fill="url(#' + id + 'glow)"/>';
    s += '<line x1="0" y1="' + f(vp.y) + '" x2="' + W + '" y2="' + f(vp.y) + '" style="stroke:' + A1 + '" stroke-opacity=".28"/>';
    var dashes = '', lanes = [];
    for (i = 0; i <= n; i++) {
      var bx = vp.x + (i / n - .5) * W * o.spread; lanes.push(bx);
      if (i === 0 || i === n) {
        s += '<line x1="' + f(vp.x) + '" y1="' + f(vp.y) + '" x2="' + f(bx) + '" y2="' + by + '" style="stroke:' + A1 + '" stroke-opacity=".38" stroke-width="1.6"/>';
        continue;
      }
      for (k = -2; k < 16; k++) {
        var t1 = Math.pow(Q, k), t0 = t1 * .9;
        dashes += '<line x1="' + f(vp.x + (bx - vp.x) * t0) + '" y1="' + f(vp.y + (by - vp.y) * t0) + '" x2="' + f(vp.x + (bx - vp.x) * t1) + '" y2="' + f(vp.y + (by - vp.y) * t1) +
          '" style="stroke:' + (i % 2 ? A2 : A1) + '" stroke-width="' + f(o.dash * t1) + '" stroke-linecap="round"/>';
      }
    }
    s += '<g class="rdbg-lanes" style="transform-origin:' + f(vp.x) + 'px ' + f(vp.y) + 'px">' + dashes + '</g>';

    // auta: koncová světla (vpravo od středu) se vzdalují, přední (vlevo) přijíždějí
    var css = '';
    o.cars.forEach(function (c, j) {
      // c = [pozice pruhu 0–1, t od, t do, doba s, zpoždění s, 'tail'|'head']
      var lane = vp.x + (c[0] - .5) * W * o.spread, dx = lane - vp.x, dy = by - vp.y, name = id + 'c' + j;
      css += '@keyframes ' + name + '{0%{transform:translate(' + f(vp.x + dx * c[1]) + 'px,' + f(vp.y + dy * c[1]) + 'px) scale(' + c[1] + ');opacity:0}' +
        '10%{opacity:1}90%{opacity:1}100%{transform:translate(' + f(vp.x + dx * c[2]) + 'px,' + f(vp.y + dy * c[2]) + 'px) scale(' + c[2] + ');opacity:0}}';
      var g = id + c[5], gap = o.carGap, r = o.carR;
      s += '<g class="rdbg-hwcar" style="animation:' + name + ' ' + c[3] + 's linear ' + c[4] + 's infinite">' +
        '<circle cx="' + (-gap) + '" r="' + r + '" fill="url(#' + g + ')"/><circle cx="' + gap + '" r="' + r + '" fill="url(#' + g + ')"/></g>';
    });
    return '<style>' + css + '</style>' + s;
  }

  // auta pro úvod (více) a patičku (méně) — pruh, t od → do, doba, zpoždění, typ
  var CARS_HERO = [
    [.62, .70, .04, 11, 0, 'tail'], [.70, .85, .05, 14, -4, 'tail'], [.58, .60, .04, 9, -7, 'tail'], [.76, .9, .06, 16, -11, 'tail'],
    [.40, .04, .75, 10, -2, 'head'], [.32, .05, .85, 13, -6, 'head'], [.44, .04, .7, 8, -9, 'head']
  ];
  // šikmá dálnice (úběžník vpravo) — pruhy vpravo od středu vedou mimo obrazovku, proto jen levá polovina
  var CARS_SLANT = [
    [.47, .80, .05, 11, 0, 'tail'], [.42, .9, .05, 14, -5, 'tail'],
    [.36, .05, .85, 10, -2, 'head'], [.28, .05, .9, 13, -7, 'head'], [.20, .05, .9, 12, -10, 'head']
  ];

  var STYLES = {
    dalnice: {
      hero: function (W, H, mobile, card) {
        if (mobile) {
          // Mobil na výšku: první obrazovku zabírá celá text a video je až pod ní, takže silnice
          // vedle videa by nebyla vidět. Proto šikmá dálnice přes celou úvodní sekci jako v patičce —
          // úběžník vpravo nahoře pod hlavičkou, čitelnost textu hlídá závoj (.rdbg-hero.is-mobile).
          return highway(W, H, { vp: { x: W * .84, y: 92 }, lanes: 9, spread: 3.8, dash: 3.8,
            glowRx: .5, glowRy: 18, cars: CARS_SLANT, carGap: 12, carR: 9 });
        }
        // desktop / na šířku: úběžník za kartou s videem
        var vp = card ? { x: card.left + card.width / 2, y: card.top + card.height * .42 } : { x: W * .69, y: H * .44 };
        return highway(W, H, { vp: vp, lanes: 11, spread: 3.2, dash: 3.6, glowRx: .3, glowRy: 34, cars: CARS_HERO, carGap: 13, carR: 10 });
      },
      footer: function (W, H, mobile) {
        // šikmá dálnice přes celou patičku: úběžník vpravo nahoře, pruhy se rozevírají doleva dolů
        var vp = { x: W * (mobile ? .82 : .78), y: mobile ? 16 : 22 };
        return highway(W, H, { vp: vp, lanes: mobile ? 7 : 11, spread: mobile ? 3.6 : 3.4, dash: 3.2,
          glowRx: .25, glowRy: 16, cars: CARS_SLANT, carGap: 13, carR: 10 });
      }
    }
  };
  var S = STYLES[STYLE] || STYLES.dalnice;

  /* ------------------------------------------------------------------ */
  var layers = [];
  function svgWrap(W, H, inner) {
    return '<svg xmlns="' + NS + '" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" aria-hidden="true" focusable="false">' + inner + '</svg>';
  }
  function build() {
    layers.forEach(function (l) { l.remove(); }); layers = [];
    var W = document.documentElement.clientWidth;
    var hero = document.querySelector('.rd-uvod .rd-hero-wrap');
    // „mobil“ = karta s videem je pod textem (na výšku); na šířku je vedle textu jako na desktopu
    var stacked = hero && getComputedStyle(hero).gridTemplateColumns.split(' ').length === 1;

    // úvodní sekce — jen na úvodní stránce ([rd_uvod_platform])
    if (hero) {
      var oy = window.pageYOffset, hr = hero.getBoundingClientRect();
      var H = Math.round(hr.bottom + oy + (stacked ? 60 : 110)), box = null;
      var card = document.querySelector('.rd-hero-card');
      if (card) { var cr = card.getBoundingClientRect(); box = { left: cr.left, width: cr.width, top: cr.top + oy, height: cr.height }; }
      var top = document.createElement('div');
      top.className = 'rdbg rdbg-hero' + (stacked ? ' is-mobile' : '');
      top.style.height = H + 'px';
      // Na výšku (video pod textem) silnice končí pod tlačítky a video stojí na čistém pozadí.
      // Na šířku/desktopu je video vedle textu a silnice pod ním pokračuje (maska z CSS).
      var btns = document.querySelector('.rd-uvod .rd-hero-btns');
      if (stacked && btns && box) {
        var fadeFrom = Math.round(btns.getBoundingClientRect().bottom + oy + 8), fadeTo = Math.round(box.top - 6);
        if (fadeTo > fadeFrom) {
          var m = 'linear-gradient(180deg, #000 ' + fadeFrom + 'px, transparent ' + fadeTo + 'px)';
          top.style.webkitMaskImage = m; top.style.maskImage = m;
        }
      }
      top.innerHTML = svgWrap(W, H, S.hero(W, H, stacked, box)) + '<div class="rdbg-veil"></div>';
      document.body.insertBefore(top, document.body.firstChild);
      layers.push(top);
    }
    // patička — na všech stránkách
    var foot = document.querySelector('.rdpro-footer');
    if (foot) {
      var FH = foot.offsetHeight, fm = W < 700;
      var fb = document.createElement('div');
      fb.className = 'rdbg rdbg-foot' + (fm ? ' is-mobile' : '');
      fb.innerHTML = svgWrap(W, FH, S.footer(W, FH, fm)) + '<div class="rdbg-veil"></div>';
      foot.insertBefore(fb, foot.firstChild);
      layers.push(fb);
    }
    watch();
  }

  // Animace běží jen když je vrstva vidět — šetří baterii na mobilu.
  var io = null;
  function watch() {
    if (!('IntersectionObserver' in window)) return;
    if (io) io.disconnect();
    io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { e.target.classList.toggle('is-paused', !e.isIntersecting); });
    });
    layers.forEach(function (l) { io.observe(l); });
  }

  var lastW = 0, timer = null;
  function onResize() {
    // mobilní prohlížeče mění výšku při skrývání lišty — překreslujeme jen při změně šířky
    // (otočení telefonu šířku mění, takže landscape/portrait se přepočítá)
    var W = document.documentElement.clientWidth;
    if (W === lastW) return;
    lastW = W;
    clearTimeout(timer); timer = setTimeout(build, 200);
  }
  function init() {
    lastW = document.documentElement.clientWidth;
    build();
    // hero se může po načtení fontů/videa mírně změnit — dorovnat rozměry vrstvy
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(build);
    var v = document.getElementById('rd-hero-video');
    if (v) v.addEventListener('loadedmetadata', build, { once: true });
    window.addEventListener('resize', onResize);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
