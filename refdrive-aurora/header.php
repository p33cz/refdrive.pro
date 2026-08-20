<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#07041a">

  <script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('/sw.js');
    });
  }
  </script>
<link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#06040f">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="RefDrive">
  <link rel="apple-touch-icon" href="/icon-192.png">

  <script>
  var rdInstallPrompt = null;
  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    rdInstallPrompt = e;
    if (localStorage.getItem('rd_no_install')) return;
    var wrap = document.getElementById('rd-install-wrap');
    if (wrap) wrap.style.display = 'flex';
  });
  function rdInstallApp() {
    if (!rdInstallPrompt) return;
    rdInstallPrompt.prompt();
    rdInstallPrompt.userChoice.then(function(r) {
      rdInstallPrompt = null;
      var wrap = document.getElementById('rd-install-wrap');
      if (wrap) wrap.style.display = 'none';
    });
  }
  window.addEventListener('appinstalled', function() {
    var wrap = document.getElementById('rd-install-wrap');
    if (wrap) wrap.style.display = 'none';
  });
  </script>

  <script>
  (function() {
    var root = document.documentElement;
    function getVar(v) { return getComputedStyle(root).getPropertyValue(v).trim() || null; }
    function applySplashTheme() {
      var from = getVar('--rd-grad-from') || '#7c3aed';
      var to   = getVar('--rd-grad-to')   || '#db2777';
      var grad = 'linear-gradient(135deg,' + from + ',' + to + ')';
      // Splash wheel gradient
      var defs = document.querySelectorAll('#rd-splash stop');
      if (defs.length >= 2) { defs[0].setAttribute('stop-color', from); defs[1].setAttribute('stop-color', to); }
      // Orbs
      var orbs = document.querySelectorAll('#rd-splash > div');
      if (orbs[0]) orbs[0].style.background = 'radial-gradient(circle,' + from + '55 0%,transparent 70%)';
      if (orbs[1]) orbs[1].style.background = 'radial-gradient(circle,' + to + '44 0%,transparent 70%)';
      // Install button
      var btn = document.getElementById('rd-install-btn');
      if (btn) btn.style.background = grad;
      // Logo text colors
      var logoFrom = document.getElementById('rd-splash-logo-from');
      var logoTo   = document.getElementById('rd-splash-logo-to');
      var logoDot  = document.getElementById('rd-splash-logo-dot');
      if (logoFrom) logoFrom.style.background = 'linear-gradient(135deg,' + from + ',' + to + ')';
      if (logoFrom) { logoFrom.style.webkitBackgroundClip = 'text'; logoFrom.style.webkitTextFillColor = 'transparent'; }
      if (logoDot) logoDot.style.color = to;
      // Tagline
      var tagline = document.getElementById('rd-splash-tagline');
      if (tagline) tagline.style.color = from + '99';
      // Dots
      document.querySelectorAll('.rd-splash-dot').forEach(function(d) {
        d.style.background = from + '55';
      });
    }
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(applySplashTheme, 50);
    });
  })();
  </script>
<?php wp_head(); ?>
<?php if (is_customize_preview()): ?>
<script>
(function() {
    var themes = {
        aurora:   {from:'#7c3aed',to:'#db2777',a1:'#a78bfa',a2:'#f472b6'},
        ocean:    {from:'#0ea5e9',to:'#7c3aed',a1:'#38bdf8',a2:'#a78bfa'},
        sunset:   {from:'#f97316',to:'#db2777',a1:'#fb923c',a2:'#f472b6'},
        forest:   {from:'#16a34a',to:'#0ea5e9',a1:'#4ade80',a2:'#38bdf8'},
        midnight: {from:'#1e40af',to:'#7c3aed',a1:'#818cf8',a2:'#a78bfa'},
        rosegold: {from:'#e11d48',to:'#f59e0b',a1:'#fb7185',a2:'#fbbf24'}
    };
    function applyTheme(key) {
        var t = themes[key] || themes.aurora;
        var r = document.documentElement;
        r.style.setProperty('--rd-grad-from', t.from);
        r.style.setProperty('--rd-grad-to', t.to);
        r.style.setProperty('--rd-grad', 'linear-gradient(135deg,'+t.from+','+t.to+')');
        r.style.setProperty('--rd-accent-1', t.a1);
        r.style.setProperty('--rd-accent-2', t.a2);
        r.style.setProperty('--au-violet', t.from);
        r.style.setProperty('--au-violet-l', t.a1);
        r.style.setProperty('--au-pink', t.to);
        r.style.setProperty('--au-pink-l', t.a2);
        r.style.setProperty('--au-grad', 'linear-gradient(135deg,'+t.from+','+t.to+')');
        r.style.setProperty('--au-grad-soft', 'linear-gradient(135deg,'+t.from+'22,'+t.to+'11)');
    }
    window.addEventListener('load', function() {
        if (typeof wp === 'undefined' || typeof wp.customize === 'undefined') return;
        wp.customize('rd_color_theme', function(value) {
            value.bind(function(key) { applyTheme(key); });
        });
    });
})();
</script>
<?php endif; ?>
<?php
// Fallback barevné téma - pokud plugin neposkytne barvy
if (!did_action('rd_colors_injected')):
    $rd_themes = [
        'aurora'   => ['from' => '#7c3aed', 'to' => '#db2777', 'a1' => '#a78bfa', 'a2' => '#f472b6'],
        'ocean'    => ['from' => '#0ea5e9', 'to' => '#7c3aed', 'a1' => '#38bdf8', 'a2' => '#a78bfa'],
        'sunset'   => ['from' => '#f97316', 'to' => '#db2777', 'a1' => '#fb923c', 'a2' => '#f472b6'],
        'forest'   => ['from' => '#16a34a', 'to' => '#0ea5e9', 'a1' => '#4ade80', 'a2' => '#38bdf8'],
        'midnight' => ['from' => '#1e40af', 'to' => '#7c3aed', 'a1' => '#818cf8', 'a2' => '#a78bfa'],
        'rosegold' => ['from' => '#e11d48', 'to' => '#f59e0b', 'a1' => '#fb7185', 'a2' => '#fbbf24'],
    ];
    $rd_key = get_theme_mod('rd_color_theme', get_option('rd_color_theme', 'aurora'));
    if ($rd_key === 'auto') {
        $auto_map = [0=>'rosegold',1=>'aurora',2=>'ocean',3=>'sunset',4=>'forest',5=>'midnight',6=>'rosegold'];
        $rd_key = $auto_map[date('w')] ?? 'aurora';
    }
    $rd_t = $rd_themes[$rd_key] ?? $rd_themes['aurora'];
    echo '<style>:root{'
        . '--rd-grad-from:' . esc_attr($rd_t['from']) . ';'
        . '--rd-grad-to:'   . esc_attr($rd_t['to'])   . ';'
        . '--rd-grad:linear-gradient(135deg,' . esc_attr($rd_t['from']) . ',' . esc_attr($rd_t['to']) . ');'
        . '--rd-accent-1:'  . esc_attr($rd_t['a1'])   . ';'
        . '--rd-accent-2:'  . esc_attr($rd_t['a2'])   . ';'
        . '--au-violet:'    . esc_attr($rd_t['from'])  . ';'
        . '--au-violet-l:'  . esc_attr($rd_t['a1'])   . ';'
        . '--au-pink:'      . esc_attr($rd_t['to'])    . ';'
        . '--au-pink-l:'    . esc_attr($rd_t['a2'])   . ';'
        . '--au-grad:linear-gradient(135deg,' . esc_attr($rd_t['from']) . ',' . esc_attr($rd_t['to']) . ');'
        . '--au-grad-soft:linear-gradient(135deg,' . esc_attr($rd_t['from']) . '22,' . esc_attr($rd_t['to']) . '11);'
        . '}</style>';
endif;
?>
<!-- Prevence blikání při přepínání tématu -->
<script>
(function(){
  var t = localStorage.getItem('rdpro-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body <?php body_class('rdpro-body'); ?>>
<!-- SPLASH SCREEN -->
  <div id="rd-splash" style="position:fixed;inset:0;background:#06040f;z-index:99999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:24px;transition:opacity .5s ease,visibility .5s ease">
    <div style="position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(124,58,237,.35) 0%,transparent 70%);top:-60px;left:-60px;animation:rdOrb 3s ease-in-out infinite"></div>
    <div style="position:absolute;width:250px;height:250px;border-radius:50%;background:radial-gradient(circle,rgba(219,39,119,.25) 0%,transparent 70%);bottom:-40px;right:-40px;animation:rdOrb 3s ease-in-out infinite 1.5s"></div>
    <svg id="rd-splash-wheel" style="width:56px;height:56px;animation:rdSpin 8s linear infinite;position:relative;z-index:2;opacity:0;transition:opacity .3s ease" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
      <defs><linearGradient id="swg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#7c3aed"/><stop offset="100%" stop-color="#db2777"/></linearGradient></defs>
      <circle cx="40" cy="40" r="36" stroke="url(#swg)" stroke-width="3" stroke-dasharray="6 4"/>
      <circle cx="40" cy="40" r="22" stroke="url(#swg)" stroke-width="2.5"/>
      <circle cx="40" cy="40" r="8" fill="url(#swg)"/>
      <line x1="40" y1="4" x2="40" y2="18" stroke="url(#swg)" stroke-width="2.5" stroke-linecap="round"/>
      <line x1="40" y1="62" x2="40" y2="76" stroke="url(#swg)" stroke-width="2.5" stroke-linecap="round"/>
      <line x1="4" y1="40" x2="18" y2="40" stroke="url(#swg)" stroke-width="2.5" stroke-linecap="round"/>
      <line x1="62" y1="40" x2="76" y2="40" stroke="url(#swg)" stroke-width="2.5" stroke-linecap="round"/>
      <line x1="14" y1="14" x2="24" y2="24" stroke="url(#swg)" stroke-width="2" stroke-linecap="round"/>
      <line x1="56" y1="56" x2="66" y2="66" stroke="url(#swg)" stroke-width="2" stroke-linecap="round"/>
      <line x1="66" y1="14" x2="56" y2="24" stroke="url(#swg)" stroke-width="2" stroke-linecap="round"/>
      <line x1="24" y1="56" x2="14" y2="66" stroke="url(#swg)" stroke-width="2" stroke-linecap="round"/>
    </svg>
    <div style="position:relative;z-index:2;font-family:Arial,sans-serif;font-size:28px;font-weight:800;letter-spacing:-.02em;animation:rdFadeIn 1s ease forwards">
      <span style="color:#f0ecff">ref</span><span id="rd-splash-logo-from" style="background:linear-gradient(135deg,#a78bfa,#f472b6);-webkit-background-clip:text;-webkit-text-fill-color:transparent">drive</span><span id="rd-splash-logo-dot" style="color:#6b5fa8">.pro</span>
    </div>
    <div id="rd-splash-tagline" style="position:relative;z-index:2;font-family:Arial,sans-serif;font-size:12px;color:#4a3b7c;letter-spacing:.1em;text-transform:uppercase;font-weight:600"><?php aurora_txt('rd_txt_splash_tagline', 'Školení řidičů online'); ?></div>
    <div style="position:relative;z-index:2;display:flex;gap:8px;margin-top:16px">
      <div class="rd-splash-dot" style="width:6px;height:6px;border-radius:50%;background:var(--rd-grad-from,#7c3aed);animation:rdDot 1.4s ease-in-out infinite"></div>
      <div class="rd-splash-dot" style="width:6px;height:6px;border-radius:50%;background:var(--rd-grad-from,#7c3aed);animation:rdDot 1.4s ease-in-out infinite .2s"></div>
      <div class="rd-splash-dot" style="width:6px;height:6px;border-radius:50%;background:var(--rd-grad-from,#7c3aed);animation:rdDot 1.4s ease-in-out infinite .4s"></div>
    </div>
  </div>
  <style>
  @keyframes rdOrb{0%,100%{transform:scale(1);opacity:.7}50%{transform:scale(1.15);opacity:1}}
  @keyframes rdSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
  @keyframes rdFadeIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
  @keyframes rdDot{0%,80%,100%{transform:scale(1);opacity:.35}40%{transform:scale(1.4);opacity:1}}
  </style>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    var w = document.getElementById('rd-splash-wheel');
    if (w) setTimeout(function() { w.style.opacity='1'; }, 50);
  });
  var s = document.getElementById('rd-splash');
  if (sessionStorage.getItem('rd_splash_shown')) {
    if (s) s.remove();
  } else {
    sessionStorage.setItem('rd_splash_shown', '1');
    window.addEventListener('load', function() {
      setTimeout(function() {
        if (s) { s.style.opacity='0'; s.style.visibility='hidden'; setTimeout(function(){s.remove();},500); }
      }, 1800);
    });
  }
  </script>

<?php wp_body_open(); ?>

<!-- AMBIENT BACKGROUND -->
<div class="rdpro-ambient" aria-hidden="true">
  <div class="rdpro-orb rdpro-orb-1"></div>
  <div class="rdpro-orb rdpro-orb-2"></div>
  <div class="rdpro-orb rdpro-orb-3"></div>
  <div class="rdpro-noise"></div>
</div>

<!-- HEADER -->
<header class="rdpro-header" id="rdpro-header">
  <div class="rdpro-header-inner">

    <!-- LOGO -->
    <a href="<?php echo esc_url(home_url('/')); ?>" class="rdpro-logo" aria-label="RefDrive – Domů">
      <span class="rdpro-logo-ref">ref</span><span class="rdpro-logo-drive">drive</span><span class="rdpro-logo-dot">.pro</span>
    </a>

    <!-- DESKTOP NAV -->
    <nav class="rdpro-nav" aria-label="Hlavní menu">
      <?php wp_nav_menu([
        'theme_location' => 'primary',
        'container'      => false,
        'fallback_cb'    => false,
        'menu_class'     => 'rdpro-nav-list',
        'link_before'    => '<span>',
        'link_after'     => '</span>',
      ]); ?>
    </nav>

    <!-- CTA + PŘEPÍNAČ + BURGER -->
    <div class="rdpro-header-actions">
      <?php if (function_exists('do_action') && has_action('rd_header_nav')): ?>
        <?php do_action('rd_header_nav'); ?>
      <?php else: ?>
        <a href="<?php echo esc_url(home_url('/novinky-2026/')); ?>" class="rdpro-btn rdpro-btn-ghost rdpro-btn-sm rdpro-hide-mobile" style="border-color:rgba(219,39,119,.3);color:var(--au-text-2)"><svg style="display:inline;vertical-align:middle;margin-right:3px" width="10" height="10" viewBox="0 0 24 24" fill="url(#stg)" stroke="none"><defs><linearGradient id="stg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#f472b6"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>Novinky 2026</a>
        <?php if (!isset($_SESSION['rd_firma_id']) && !isset($_SESSION['rd_lektor']) && !isset($_SESSION['rd_kod'])): ?>
        <a href="<?php echo esc_url(home_url(get_option('rd_url_prihlaseni', '/prihlaseni/'))); ?>" class="rdpro-btn rdpro-btn-ghost rdpro-btn-sm rdpro-hide-mobile"><?php aurora_txt('rd_txt_btn_kurz', 'Vstoupit do kurzu'); ?></a>
        <a href="<?php echo esc_url(home_url(get_option('rd_url_firma', '/firma-portal/'))); ?>" class="rdpro-btn rdpro-btn-primary rdpro-btn-sm rdpro-hide-mobile"><?php aurora_txt('rd_txt_btn_firma', 'Firemní přístup'); ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <button class="rdpro-theme-toggle" id="rdpro-theme-toggle" aria-label="Přepnout téma" title="Přepnout světlý/tmavý režim">
        <span id="rdpro-theme-icon">☀️</span>
      </button>
      <button class="rdpro-burger" id="rdpro-burger" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>

  </div>

  <!-- MOBILE MENU -->
  <div class="rdpro-mobile-menu" id="rdpro-mobile-menu" aria-hidden="true">
    <?php wp_nav_menu([
      'theme_location' => 'primary',
      'container'      => false,
      'fallback_cb'    => false,
      'menu_class'     => 'rdpro-mobile-nav-list',
    ]); ?>
    <div class="rdpro-mobile-actions">
      <?php if (isset($_SESSION['rd_jmeno'])): ?>
        <a href="<?php echo esc_url(home_url('/novinky-2026/')); ?>" class="rdpro-btn rdpro-btn-ghost" style="border-color:rgba(219,39,119,.3)"><svg style="display:inline;vertical-align:middle;margin-right:3px" width="10" height="10" viewBox="0 0 24 24" fill="url(#stg)" stroke="none"><defs><linearGradient id="stg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#f472b6"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>Novinky 2026</a>
        <a href="<?php echo esc_url(home_url('/legislativa/')); ?>" class="rdpro-btn rdpro-btn-ghost">Legislativa</a>
        <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="rdpro-btn rdpro-btn-ghost">FAQ</a>
      <?php elseif (isset($_SESSION['rd_firma_id'])): ?>
        <a href="<?php echo esc_url(home_url('/novinky-2026/')); ?>" class="rdpro-btn rdpro-btn-ghost" style="border-color:rgba(219,39,119,.3)"><svg style="display:inline;vertical-align:middle;margin-right:3px" width="10" height="10" viewBox="0 0 24 24" fill="url(#stg)" stroke="none"><defs><linearGradient id="stg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#f472b6"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>Novinky 2026</a>
        <a href="<?php echo esc_url(home_url('/legislativa/')); ?>" class="rdpro-btn rdpro-btn-ghost">Legislativa</a>
        <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="rdpro-btn rdpro-btn-ghost">FAQ</a>
        <a href="<?php echo esc_url(home_url('/firma-portal/')); ?>" class="rdpro-btn rdpro-btn-primary">Firemní přístup</a>
      <?php elseif (isset($_SESSION['rd_lektor'])): ?>
        <a href="<?php echo esc_url(home_url('/novinky-2026/')); ?>" class="rdpro-btn rdpro-btn-ghost" style="border-color:rgba(219,39,119,.3)"><svg style="display:inline;vertical-align:middle;margin-right:3px" width="10" height="10" viewBox="0 0 24 24" fill="url(#stg)" stroke="none"><defs><linearGradient id="stg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#f472b6"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>Novinky 2026</a>
        <a href="<?php echo esc_url(home_url('/legislativa/')); ?>" class="rdpro-btn rdpro-btn-ghost">Legislativa</a>
        <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="rdpro-btn rdpro-btn-ghost">FAQ</a>
        <a href="<?php echo esc_url(home_url('/lektor/')); ?>" class="rdpro-btn rdpro-btn-primary">Portál lektora</a>
      <?php else: ?>
        <a href="<?php echo esc_url(home_url('/novinky-2026/')); ?>" class="rdpro-btn rdpro-btn-ghost" style="border-color:rgba(219,39,119,.3)"><svg style="display:inline;vertical-align:middle;margin-right:3px" width="10" height="10" viewBox="0 0 24 24" fill="url(#stg)" stroke="none"><defs><linearGradient id="stg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#a78bfa"/><stop offset="100%" stop-color="#f472b6"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>Novinky 2026</a>
        <a href="<?php echo esc_url(home_url('/legislativa/')); ?>" class="rdpro-btn rdpro-btn-ghost">Legislativa</a>
        <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="rdpro-btn rdpro-btn-ghost">FAQ</a>
        <?php if (!isset($_SESSION['rd_kod'])): ?>
        <a href="<?php echo esc_url(home_url('/prihlaseni/')); ?>" class="rdpro-btn rdpro-btn-ghost">Vstoupit do kurzu</a>
        <a href="<?php echo esc_url(home_url('/registrace-autoskoly/')); ?>" class="rdpro-btn rdpro-btn-ghost">Pro autoškoly</a>
        <a href="<?php echo esc_url(home_url('/firma-portal/')); ?>" class="rdpro-btn rdpro-btn-primary">Firemní přístup</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</header>
<div id="rd-install-wrap" style="display:none;position:fixed;bottom:24px;right:24px;z-index:9999;align-items:center;gap:8px">
  <button id="rd-install-btn" onclick="rdInstallApp()" style="background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff;border:none;border-radius:99px;padding:12px 20px;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;box-shadow:0 4px 20px rgba(124,58,237,.4)">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v13M7 9l5 6 5-6"/><path d="M3 20h18"/></svg>
    <?php aurora_txt('rd_txt_btn_install', 'Nainstalovat aplikaci'); ?>
  </button>
  <button onclick="document.getElementById('rd-install-wrap').remove();localStorage.setItem('rd_no_install','1')" style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center">✕</button>
</div>

<main class="rdpro-main" id="main">
