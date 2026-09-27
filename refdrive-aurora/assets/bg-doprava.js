/**
 * RefDrive Aurora — dopravní pozadí úvodní sekce (hero) a patičky.
 * Styl se volí v Customizeru (Vzhled → Přizpůsobit → Dopravní pozadí) a sem přichází
 * přes window.RDBG_STYLE: 'mapa' | 'stopy' | 'dalnice'.
 *
 * Barvy jsou v SVG jen jako CSS proměnné (var(--rd-grad-from) apod.) — přepnutí barevného
 * tématu i světlého/tmavého režimu se tak projeví okamžitě, bez překreslení.
 * Geometrie závisí na rozměrech stránky, proto se SVG generuje tady a při změně šířky znovu.
 */
(function () {
  'use strict';
  var STYLE = window.RDBG_STYLE;
  if (!STYLE || STYLE === 'vypnuto' || !document.querySelector) return;

  var NS = 'http://www.w3.org/2000/svg';
  var C1 = 'var(--rd-grad-from)', C2 = 'var(--rd-grad-to)', A1 = 'var(--rd-accent-1)', A2 = 'var(--rd-accent-2)';
  var INK = 'var(--au-text-1)';            // ulice/čáry: světlé v tmavém režimu, tmavé ve světlém
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var f = function (n) { return (Math.round(n * 10) / 10).toString(); };
  var seed = 1;
  var rnd = function () { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
  var uid = 0;

  function defs(id) {
    return '<defs>' +
      '<linearGradient id="' + id + 'g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" style="stop-color:' + C1 + '"/><stop offset="1" style="stop-color:' + C2 + '"/></linearGradient>' +
      '<radialGradient id="' + id + 'r"><stop offset="0" style="stop-color:' + A1 + ';stop-opacity:.4"/><stop offset=".45" style="stop-color:' + C2 + ';stop-opacity:.12"/><stop offset="1" style="stop-color:' + C2 + ';stop-opacity:0"/></radialGradient>' +
      '<radialGradient id="' + id + 'light"><stop offset="0" stop-color="#fff" stop-opacity=".9"/><stop offset=".35" stop-color="#fff" stop-opacity=".35"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>' +
      '<radialGradient id="' + id + 'tail"><stop offset="0" stop-color="#ff5a5a" stop-opacity=".95"/><stop offset=".35" stop-color="#ff5a5a" stop-opacity=".35"/><stop offset="1" stop-color="#ff5a5a" stop-opacity="0"/></radialGradient>' +
      '</defs>';
  }

  /* ------------------------------------------------------------------
     1) MAPA TRASY — síť ulic, trasa k cíli (autoškola), auto na cestě
     ------------------------------------------------------------------ */
  function mapGrid(W, H, s0, soft) {
    seed = s0;
    var s = '', xs = [], ys = [], x, y, i;
    for (x = -200; x < W + 200; x += 70 + rnd() * 50) xs.push(x);
    for (y = -200; y < H + 200; y += 60 + rnd() * 40) ys.push(y);
    s += '<path d="M -200 ' + f(H * .78) + ' C ' + f(W * .25) + ' ' + f(H * .62) + ' ' + f(W * .45) + ' ' + f(H * 1.02) + ' ' + f(W + 200) + ' ' + f(H * .70) + '" fill="none" style="stroke:' + A1 + '" stroke-opacity=".05" stroke-width="38"/>';
    for (i = 0; i < xs.length; i++) s += '<line x1="' + f(xs[i]) + '" y1="-200" x2="' + f(xs[i] + (rnd() - .5) * 30) + '" y2="' + (H + 200) + '" style="stroke:' + INK + '" stroke-opacity="' + (i % 5 === 2 ? .07 : .035) + '" stroke-width="' + (i % 5 === 2 ? 3 : 1) + '"/>';
    for (i = 0; i < ys.length; i++) s += '<line x1="-200" y1="' + f(ys[i]) + '" x2="' + (W + 200) + '" y2="' + f(ys[i] + (rnd() - .5) * 30) + '" style="stroke:' + INK + '" stroke-opacity="' + (i % 4 === 1 ? .07 : .035) + '" stroke-width="' + (i % 4 === 1 ? 3 : 1) + '"/>';
    if (!soft) s += '<circle cx="' + f(W * .62) + '" cy="' + f(H * .45) + '" r="' + f(Math.min(W, H) * .55) + '" fill="none" style="stroke:' + INK + '" stroke-opacity=".05" stroke-width="3"/>';
    else s = s.replace(/stroke-opacity="\.07"/g, 'stroke-opacity=".04"').replace(/stroke-opacity="\.035"/g, 'stroke-opacity=".022"');
    return { s: s, xs: xs, ys: ys };
  }
  function pin(x, y, sc, delay, op) {
    return '<g transform="translate(' + f(x) + ' ' + f(y) + ') scale(' + sc + ')" opacity="' + (op || 1) + '">' +
      '<circle class="rdbg-pulse" style="fill:' + C2 + ';animation-delay:' + delay + 's" r="16" opacity=".22"/>' +
      '<path d="M0 0 C -9 -12 -14 -18 -14 -26 A 14 14 0 1 1 14 -26 C 14 -18 9 -12 0 0 Z" style="fill:' + C2 + '" opacity=".85"/>' +
      '<circle cy="-26" r="5.5" fill="#fff" opacity=".9"/></g>';
  }
  function mapHero(W, H, mobile, card) {
    var id = 'rdbgm' + (++uid), g = mapGrid(W, H, 11), s = defs(id);
    var ROT = mobile ? 0 : -9, cx = W / 2, cy = H / 2;
    var pick = function (arr, v) { return arr.reduce(function (a, b) { return Math.abs(b - v) < Math.abs(a - v) ? b : a; }); };
    var sx, sy, mx, my, ex, ey, d;
    if (mobile && card) {
      // Mobil: text zabírá celou šířku, volné jsou jen okraje vedle videa. Trasa vede levým okrajem
      // nahoru, přejede „za videem“ na druhou stranu a končí pinem u pravého okraje.
      var lx = card.left / 2, rx = (card.right + W) / 2;
      sx = lx; sy = Math.min(H - 8, card.bottom + 30); mx = lx; my = card.top + card.height * .3; ex = rx; ey = card.top + card.height * .62;
      d = 'M ' + f(sx) + ' ' + f(sy) + ' L ' + f(mx) + ' ' + f(my) + ' L ' + f(ex) + ' ' + f(my) + ' L ' + f(ex) + ' ' + f(ey);
    } else {
      // Desktop: trasa vpravo od textu, cíl mezi textem a videem.
      sx = pick(g.xs, W * .06); sy = pick(g.ys, H * .93);
      mx = pick(g.xs, W * .50); my = pick(g.ys, H * .66);
      ex = pick(g.xs, W * .63); ey = pick(g.ys, H * .30);
      d = 'M ' + sx + ' ' + sy + ' L ' + mx + ' ' + sy + ' L ' + mx + ' ' + my + ' L ' + ex + ' ' + my + ' L ' + ex + ' ' + ey;
    }
    s += '<g transform="rotate(' + ROT + ' ' + cx + ' ' + cy + ')">' + g.s;
    s += '<path d="' + d + '" fill="none" stroke="url(#' + id + 'g)" stroke-width="12" stroke-linejoin="round" opacity=".10"/>';
    s += '<path id="' + id + 'route" class="rdbg-draw" pathLength="1000" d="' + d + '" fill="none" stroke="url(#' + id + 'g)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" opacity=".7"/>';
    s += '<path class="rdbg-flow" d="' + d + '" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="1.2" stroke-dasharray="3 9" stroke-linecap="round"/>';
    s += '<circle cx="' + sx + '" cy="' + sy + '" r="8" fill="none" style="stroke:' + C1 + '" stroke-width="2.5" opacity=".75"/><circle cx="' + sx + '" cy="' + sy + '" r="3" style="fill:' + C1 + '" opacity=".75"/>';
    // auto jede po trase (SMIL — animateMotion umí sledovat cestu i v natočené skupině)
    var car = '<circle r="11" fill="#fff" opacity=".12"/><circle r="5.5" fill="#fff" opacity=".85"/><circle r="2.5" style="fill:' + C1 + '"/>';
    if (reduce) s += '<g transform="translate(' + f(mx) + ' ' + f(sy + (my - sy) * .45) + ')">' + car + '</g>';
    else s += '<g opacity="0">' + car + '<animateMotion dur="' + (mobile ? 9 : 11) + 's" begin="3.2s" repeatCount="indefinite" calcMode="linear"><mpath href="#' + id + 'route"/></animateMotion>' +
      '<set attributeName="opacity" to="1" begin="3.2s"/></g>';
    s += '</g>';
    var a = ROT * Math.PI / 180;
    var px = cx + (ex - cx) * Math.cos(a) - (ey - cy) * Math.sin(a), py = cy + (ex - cx) * Math.sin(a) + (ey - cy) * Math.cos(a);
    s += pin(px, py, mobile ? .9 : 1.1, 0, .85);
    return s;
  }
  function mapFooter(W, H, mobile) {
    var s = defs('rdbgmf' + (++uid)) + mapGrid(W, H, 29, true).s;
    var P = mobile ? [[.90, .22], [.95, .48]] : [[.64, .34], [.72, .62], [.82, .38], [.91, .60]];
    for (var i = 0; i < P.length; i++) s += pin(W * P[i][0], H * P[i][1], mobile ? .5 : .6, (i * .7).toFixed(2), .45);
    return s;
  }

  /* ------------------------------------------------------------------
     2) SVĚTELNÉ STOPY — dlouhá expozice noční dopravy
     ------------------------------------------------------------------ */
  function trails(W, H, paths, gid, widths) {
    var s = '<linearGradient id="' + gid + '" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="' + W + '" y2="0">' +
      '<stop offset="0" style="stop-color:' + C1 + ';stop-opacity:0"/><stop offset=".35" style="stop-color:' + C1 + ';stop-opacity:.6"/><stop offset="1" style="stop-color:' + C2 + ';stop-opacity:.7"/></linearGradient>';
    s += '<g class="rdbg-sway">';
    paths.forEach(function (d, j) { s += '<path d="' + d + '" fill="none" stroke="url(#' + gid + ')" stroke-width="' + (widths[0] + (j % 3) * 3) + '" opacity=".09"/>'; });
    paths.forEach(function (d, j) { s += '<path d="' + d + '" fill="none" stroke="url(#' + gid + ')" stroke-width="' + (widths[1] + (j % 3) * .6).toFixed(1) + '" opacity="' + (.38 + (j % 4) * .07).toFixed(2) + '"/>'; });
    // „auta“ — krátké jasné úseky, které po stopách přejíždějí
    paths.forEach(function (d, j) {
      if (j % 2) return;
      s += '<path class="rdbg-car" style="animation-duration:' + (7 + (j % 3) * 2.5) + 's;animation-delay:-' + (j * 1.7).toFixed(1) + 's" d="' + d + '" pathLength="1000" fill="none" stroke="#fff7e0" stroke-width="2.2" stroke-linecap="round" stroke-dasharray="12 988" opacity=".65"/>';
    });
    return s + '</g>';
  }
  function trailsHero(W, H, mobile) {
    var N = mobile ? 7 : 11, paths = [];
    for (var j = 0; j < N; j++) {
      var k = j / (N - 1);
      var y0 = H * (1.02 - k * .10), y3 = H * (mobile ? .55 + k * .30 : .06 + k * .42);
      var c1x = W * (mobile ? .35 : .42), c1y = H * (.98 - k * .06), c2x = W * (mobile ? .60 : .60), c2y = H * (mobile ? .70 + k * .15 : .30 + k * .30);
      paths.push('M -60 ' + f(y0) + ' C ' + f(c1x) + ' ' + f(c1y) + ' ' + f(c2x) + ' ' + f(c2y) + ' ' + f(W + 60) + ' ' + f(y3));
    }
    return trails(W, H, paths, 'rdbgt' + (++uid), [4, .9]);
  }
  function trailsFooter(W, H) {
    var paths = [];
    for (var j = 0; j < 5; j++) {
      var y = H - 81 - j * 9, a = 5 + j * 2;
      paths.push('M -40 ' + y + ' C ' + f(W * .3) + ' ' + (y - a) + ' ' + f(W * .6) + ' ' + (y + a) + ' ' + (W + 40) + ' ' + (y - a / 2));
    }
    return trails(W, H, paths, 'rdbgtf' + (++uid), [3, .9]);
  }

  /* ------------------------------------------------------------------
     3) NOČNÍ DÁLNICE — perspektivní pruhy k horizontu za videem
     ------------------------------------------------------------------ */
  function highwayHero(W, H, mobile, vp) {
    var id = 'rdbgh' + (++uid), s = defs(id), n = mobile ? 7 : 11, spread = mobile ? 2.4 : 3.2, i, k;
    var by = H + 40, Q = .8;          // pomlčky v geometrické řadě t = Q^k → zvětšení o 1/Q je plynulá smyčka
    s += '<ellipse cx="' + f(vp.x) + '" cy="' + f(vp.y) + '" rx="' + f(W * (mobile ? .7 : .38)) + '" ry="' + (mobile ? 80 : 110) + '" fill="url(#' + id + 'r)"/>';
    s += '<line x1="0" y1="' + f(vp.y) + '" x2="' + W + '" y2="' + f(vp.y) + '" style="stroke:' + A1 + '" stroke-opacity=".10"/>';
    var dashes = '', bxs = [];
    for (i = 0; i <= n; i++) {
      var bx = vp.x + (i / n - .5) * W * spread; bxs.push(bx);
      if (i === 0 || i === n) { s += '<line x1="' + f(vp.x) + '" y1="' + f(vp.y) + '" x2="' + f(bx) + '" y2="' + by + '" style="stroke:' + A1 + '" stroke-opacity=".16" stroke-width="1.4"/>'; continue; }
      for (k = -2; k < 16; k++) {
        var t1 = Math.pow(Q, k), t0 = t1 * .9;
        dashes += '<line x1="' + f(vp.x + (bx - vp.x) * t0) + '" y1="' + f(vp.y + (by - vp.y) * t0) + '" x2="' + f(vp.x + (bx - vp.x) * t1) + '" y2="' + f(vp.y + (by - vp.y) * t1) + '" style="stroke:' + (i % 2 ? A2 : A1) + '" stroke-width="' + f(3.4 * t1) + '" stroke-linecap="round"/>';
      }
    }
    s += '<g class="rdbg-lanes" style="transform-origin:' + f(vp.x) + 'px ' + f(vp.y) + 'px" opacity=".22">' + dashes + '</g>';
    // auta: koncová světla se vzdalují vpravo, přední světla přijíždějí vlevo
    var css = '', cars = [[.64, .55, .05, 13, 0, 'tail'], [.73, .75, .08, 16, -6, 'tail'], [.38, .06, .7, 12, -3, 'head'], [.30, .05, .6, 15, -10, 'head']];
    cars.forEach(function (c, j) {
      var lane = vp.x + (c[0] - .5) * W * spread, dx = lane - vp.x, dy = by - vp.y, name = id + 'car' + j;
      css += '@keyframes ' + name + '{0%{transform:translate(' + f(vp.x + dx * c[1]) + 'px,' + f(vp.y + dy * c[1]) + 'px) scale(' + c[1] + ');opacity:0}' +
        '12%{opacity:.8}88%{opacity:.8}100%{transform:translate(' + f(vp.x + dx * c[2]) + 'px,' + f(vp.y + dy * c[2]) + 'px) scale(' + c[2] + ');opacity:0}}';
      var grad = c[5] === 'tail' ? id + 'tail' : id + 'light';
      s += '<g class="rdbg-hwcar" style="animation:' + name + ' ' + c[3] + 's linear ' + c[4] + 's infinite">' +
        '<circle cx="-13" r="9" fill="url(#' + grad + ')"/><circle cx="13" r="9" fill="url(#' + grad + ')"/></g>';
    });
    return '<style>' + css + '</style>' + s;
  }
  function highwayFooter(W, H) {
    var id = 'rdbghf' + (++uid), s = defs(id), y = 10;   // silnice na horní hraně patičky = předěl mezi obsahem a patičkou
    s += '<rect x="0" y="' + y + '" width="' + W + '" height="44" style="fill:' + INK + '" fill-opacity=".02"/>';
    s += '<line x1="0" y1="' + y + '" x2="' + W + '" y2="' + y + '" style="stroke:' + A1 + '" stroke-opacity=".14"/>';
    s += '<line x1="0" y1="' + (y + 44) + '" x2="' + W + '" y2="' + (y + 44) + '" style="stroke:' + A1 + '" stroke-opacity=".14"/>';
    s += '<line class="rdbg-roadline" x1="-60" y1="' + (y + 22) + '" x2="' + (W + 60) + '" y2="' + (y + 22) + '" style="stroke:' + A1 + '" stroke-opacity=".25" stroke-width="2" stroke-dasharray="26 22"/>';
    s += '<linearGradient id="' + id + 'st" x1="0" x2="1"><stop offset="0" stop-color="#fff7e0" stop-opacity="0"/><stop offset="1" stop-color="#fff7e0" stop-opacity=".45"/></linearGradient>';
    s += '<linearGradient id="' + id + 'sr" x1="0" x2="1"><stop offset="0" stop-color="#ff5a5a" stop-opacity=".45"/><stop offset="1" stop-color="#ff5a5a" stop-opacity="0"/></linearGradient>';
    [[11, 9, 0, 'st', 1], [33, 12, -5, 'sr', -1], [11, 14, -9, 'st', 1]].forEach(function (c) {
      var len = 130;
      s += '<g class="rdbg-streak' + (c[4] < 0 ? ' is-rev' : '') + '" style="animation-duration:' + c[1] + 's;animation-delay:' + c[2] + 's">' +
        (c[4] > 0 ? '<rect x="' + (-len) + '" y="' + (y + c[0] - 1.5) + '" width="' + len + '" height="3" rx="1.5" fill="url(#' + id + c[3] + ')"/><circle cx="0" cy="' + (y + c[0]) + '" r="2.4" fill="#fff7e0"/>'
                  : '<rect x="0" y="' + (y + c[0] - 1.5) + '" width="' + len + '" height="3" rx="1.5" fill="url(#' + id + c[3] + ')"/><circle cx="0" cy="' + (y + c[0]) + '" r="2.4" fill="#ff5a5a"/>') + '</g>';
    });
    return s;
  }

  /* ------------------------------------------------------------------ */
  var layers = [];
  function svgWrap(W, H, inner) {
    return '<svg xmlns="' + NS + '" width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" style="--rdbg-w:' + W + 'px" aria-hidden="true" focusable="false">' + inner + '</svg>';
  }
  function build() {
    layers.forEach(function (l) { l.remove(); }); layers = [];
    var W = document.documentElement.clientWidth, mobile = W < 700;

    // úvodní sekce — jen na úvodní stránce ([rd_uvod_platform])
    var hero = document.querySelector('.rd-uvod .rd-hero-wrap');
    if (hero) {
      var hr = hero.getBoundingClientRect(), H = Math.round(hr.bottom + window.pageYOffset + (mobile ? 60 : 110));
      var card = document.querySelector('.rd-hero-card'), vp = { x: W * .69, y: H * .44 }, box = null;
      if (card) {
        var cr = card.getBoundingClientRect(), oy = window.pageYOffset;
        vp = { x: cr.left + cr.width / 2, y: cr.top + oy + cr.height * .42 };
        box = { left: cr.left, right: cr.right, top: cr.top + oy, bottom: cr.bottom + oy, height: cr.height };
      }
      var inner = STYLE === 'stopy' ? trailsHero(W, H, mobile) : STYLE === 'dalnice' ? highwayHero(W, H, mobile, vp) : mapHero(W, H, mobile, box);
      var top = document.createElement('div');
      top.className = 'rdbg rdbg-hero' + (mobile ? ' is-mobile' : '');
      top.style.height = H + 'px';
      top.innerHTML = svgWrap(W, H, inner) + '<div class="rdbg-veil"></div>';
      document.body.insertBefore(top, document.body.firstChild);
      layers.push(top);
    }
    // patička — na všech stránkách
    var foot = document.querySelector('.rdpro-footer');
    if (foot) {
      var FH = foot.offsetHeight;
      var finner = STYLE === 'stopy' ? trailsFooter(W, FH) : STYLE === 'dalnice' ? highwayFooter(W, FH) : mapFooter(W, FH, mobile);
      var fb = document.createElement('div');
      fb.className = 'rdbg rdbg-foot' + (mobile ? ' is-mobile' : '');
      fb.innerHTML = svgWrap(W, FH, finner) + '<div class="rdbg-veil"></div>';
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
      entries.forEach(function (e) {
        e.target.classList.toggle('is-paused', !e.isIntersecting);
        var svg = e.target.querySelector('svg');
        if (svg && svg.pauseAnimations) { if (e.isIntersecting) svg.unpauseAnimations(); else svg.pauseAnimations(); }
      });
    });
    layers.forEach(function (l) { io.observe(l); });
  }

  var lastW = 0, timer = null;
  function onResize() {
    // mobilní prohlížeče mění výšku při skrývání lišty — překreslujeme jen při změně šířky
    var W = document.documentElement.clientWidth;
    if (W === lastW) return;
    lastW = W;
    clearTimeout(timer); timer = setTimeout(build, 200);
  }
  function init() {
    lastW = document.documentElement.clientWidth;
    build();
    // hero se může po načtení fontů mírně změnit — dorovnat výšku vrstvy
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(build);
    window.addEventListener('resize', onResize);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
