<?php

// Spustit session co nejdříve
add_action('init', function() {
    if (!session_id()) @session_start();
}, 1);

// Jednorázová aktualizace sloganu v patičce
add_action('init', function() {
    if (get_option('rd_slogan_updated_345') !== '1') {
        update_option('rd_txt_slogan', 'RefDrive.pro je platforma, která propojuje firmy s autoškoly.');
        update_option('rd_slogan_updated_345', '1');
    }
});


add_action('wp_head', function() {
    echo '<link rel="manifest" href="/manifest.json">' . PHP_EOL;
    echo '<meta name="theme-color" content="#06040f">' . PHP_EOL;
    echo '<meta name="mobile-web-app-capable" content="yes">' . PHP_EOL;
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . PHP_EOL;
    echo '<meta name="apple-mobile-web-app-title" content="RefDrive">' . PHP_EOL;
    echo '<link rel="apple-touch-icon" href="/icon-192.png">' . PHP_EOL;
}, 2);


/* ============================================================
   PWA SETUP
   ============================================================ */
add_action('after_switch_theme', 'rd_pwa_setup');
add_action('wp_head', 'rd_pwa_check_files', 1);
function rd_pwa_setup() { rd_pwa_copy_files(); }
function rd_pwa_check_files() {
    rd_pwa_copy_files();
}
function rd_pwa_copy_files() {
    $theme = get_template_directory();
    $root  = ABSPATH;
    $manifest = json_encode(['name'=>'refdrive.pro 3.0.1','short_name'=>'refdrive.pro','id'=>'refdrive-3-0-1','description'=>'Online školení referentských řidičů','start_url'=>home_url('/'),
        'display'=>'standalone','background_color'=>'#06040f','theme_color'=>'#06040f','orientation'=>'portrait',
        'icons'=>[['src'=>'/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],['src'=>'/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable']]
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    @file_put_contents($root.'manifest.json', $manifest);
    if (file_exists($theme.'/sw.js')) @copy($theme.'/sw.js', $root.'sw.js');
    if (file_exists($theme.'/icon-192.png')) @copy($theme.'/icon-192.png', $root.'icon-192.png');
    if (file_exists($theme.'/icon-512.png')) @copy($theme.'/icon-512.png', $root.'icon-512.png');
}

/**
 * RefDrive Aurora Theme – functions.php
 * Version: 3.0.0
 */

if (!defined('ABSPATH')) exit;

define('RDAURORA_VERSION', '3.7.5');

/* ---- ZÁKLADNÍ NASTAVENÍ ---- */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption']);
    add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Hlavní navigace']);
});

/* ---- NAČTENÍ STYLŮ A SKRIPTŮ ---- */
add_action('wp_enqueue_scripts', function () {
    // Google Fonts – Outfit (display) + DM Sans (body)
    wp_enqueue_style(
        'rdaurora-fonts',
        'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap',
        [], null
    );
    // Hlavní CSS šablony
    wp_enqueue_style(
        'rdaurora-style',
        get_template_directory_uri() . '/assets/theme.css',
        ['rdaurora-fonts'],
        RDAURORA_VERSION
    );
    // JS šablony
    wp_enqueue_script(
        'rdaurora-script',
        get_template_directory_uri() . '/assets/theme.js',
        [],
        RDAURORA_VERSION,
        true
    );
    // Dopravní pozadí úvodní sekce a patičky (styl z Customizeru, výchozí „dálnice“)
    $bg = rdaurora_bg_doprava();
    if ($bg !== 'vypnuto') {
        wp_enqueue_script(
            'rdaurora-bg-doprava',
            get_template_directory_uri() . '/assets/bg-doprava.js',
            [],
            RDAURORA_VERSION,
            true
        );
        wp_add_inline_script('rdaurora-bg-doprava', 'window.RDBG_STYLE=' . wp_json_encode($bg) . ';', 'before');
    }
});

// Styly dopravního pozadí (klíč => popisek v Customizeru). Nový styl = položka sem
// + implementace v registru STYLES v assets/bg-doprava.js.
function rdaurora_bg_doprava_styly() {
    return [
        'dalnice' => 'Noční dálnice (výchozí)',
        'vypnuto' => 'Vypnuto',
    ];
}
// Neznámá/zrušená hodnota (např. dřívější „mapa“ nebo „stopy“) → výchozí styl
function rdaurora_bg_doprava() {
    $v = get_option('rd_bg_doprava', 'dalnice');
    return array_key_exists($v, rdaurora_bg_doprava_styly()) ? $v : 'dalnice';
}



/* ---- FAVICON ---- */
add_action('wp_head', function() {
    echo '<link rel="icon" type="image/svg+xml" href="' . get_template_directory_uri() . '/favicon.svg">';
}, 1);

/* ── SEO / OG META TAGY ── */
add_action('wp_head', function() {
    $title       = wp_title('|', false, 'right') ?: get_bloginfo('name');
    $desc        = 'Školení řidičů referentů 100% online dle vyhl. 168/2002 Sb. Bez dojíždění, certifikát ihned, ověření QR kódem.';
    $url         = esc_url(home_url(add_query_arg([])));
    $image       = get_template_directory_uri() . '/assets/og-image.png';
    $site_name   = 'RefDrive.pro';

    // Popis ze stránky pokud je nastaven
    if (is_singular() && has_excerpt()) {
        $desc = wp_strip_all_tags(get_the_excerpt());
    }

    echo '<meta name="description" content="' . esc_attr($desc) . '">' . "
";
    echo '<meta property="og:type" content="website">' . "
";
    echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . "
";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "
";
    echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "
";
    echo '<meta property="og:url" content="' . $url . '">' . "
";
    echo '<meta property="og:image" content="' . esc_url($image) . '">' . "
";
    echo '<meta property="og:image:width" content="1200">' . "
";
    echo '<meta property="og:image:height" content="630">' . "
";
    echo '<meta name="twitter:card" content="summary_large_image">' . "
";
    echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "
";
    echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "
";
    echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "
";

    // Schema.org JSON-LD – pouze titulní stránka
    if (is_front_page()) {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebApplication',
            'name'        => 'RefDrive.pro',
            'url'         => home_url('/'),
            'description' => $desc,
            'applicationCategory' => 'EducationApplication',
            'offers'      => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'CZK'],
            'provider'    => ['@type' => 'Organization', 'name' => 'RefDrive Pro', 'url' => home_url('/')],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "
";
    }
}, 5);

/* ---- ODSTRANĚNÍ OMEZENÍ ŠÍŘKY OBSAHU ---- */
// Vypnout wpautop pro stránky (zabraňuje obalování shortcodes do <p> tagů)
add_action('template_redirect', function () {
    if (is_page()) {
        remove_filter('the_content', 'wpautop');
    }
});

// Vypnout block editor globální omezení šířky
add_action('after_setup_theme', function () {
    remove_theme_support('wp-block-styles');
}, 20);

// Přepsat is-layout-constrained šířku na neomezenou
add_action('wp_head', function () {
    echo '<style>
.is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)) {
    max-width: 100% !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}
.wp-block-shortcode, .wp-block { max-width: 100% !important; width: 100% !important; }
</style>';
}, 99);

/* ---- WIDGET OBLASTI ---- */
add_action('widgets_init', function () {
    register_sidebar([
        'name'          => 'Footer',
        'id'            => 'footer-1',
        'before_widget' => '<div class="footer-widget">',
        'after_widget'  => '</div>',
        'before_title'  => '<h4>',
        'after_title'   => '</h4>',
    ]);
});


/* ============================================================
   v3.0.0 — AUTOŠKOLY: ?ref= SESSION HANDLER
   Zachytí ?ref=slug co nejdříve a uloží do session.
   ============================================================ */
add_action('init', function() {
    if (!session_id()) @session_start();
    if (!empty($_GET['ref'])) {
        $ref = sanitize_key($_GET['ref']);
        // Ověřit že slug existuje a autoškola je aktivní
        if (function_exists('rd_get_current_autoskola')) {
            $as = rd_get_current_autoskola();
            if ($as) {
                $_SESSION['rd_autoskola_id'] = $as->id;
            }
        }
    }
}, 5);


/* ============================================================
   v3.0.0 — SHORTCODE: SEZNAM AUTOŠKOL [rd_seznam_autoskol]
   Používá nativní CSS třídy tématu (rd-choice-card, rd-how-step atd.)
   ============================================================ */
add_shortcode('rd_seznam_autoskol', 'rdaurora_sc_seznam_autoskol');
function rdaurora_sc_seznam_autoskol() {
    if (!function_exists('rd_autoskola_kapacita_stav')) return '';
    if (!defined('RD_TABLE_AUTOSKOLY') || !defined('RD_TABLE_AS_TIERY')) return '';
    global $wpdb;

    $autoskoly = $wpdb->get_results(
        "SELECT a.*, t.limit_kodu
         FROM " . RD_TABLE_AUTOSKOLY . " a
         LEFT JOIN " . RD_TABLE_AS_TIERY . " t ON t.id=a.tier_id
         WHERE a.stav='aktivni'
         ORDER BY a.nazev ASC"
    );

    if (empty($autoskoly)) {
        return '<div class="rd-info-chip rd-chip-blue">Zatím nejsou registrovány žádné autoškoly. Buďte první!</div>';
    }

    ob_start();
    ?>
    <div class="rd-choice" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr));margin-bottom:0">
        <?php foreach ($autoskoly as $as):
            $kapacita   = rd_autoskola_kapacita_stav($as);
            $objednat   = add_query_arg('ref', $as->slug, home_url('/objednavka/'));
            $cena_karta = intval($as->cena_kus) > 0 ? intval($as->cena_kus) : intval(get_option('rd_cena_kus', 99));
            $chip_class = ['ok' => 'rd-chip-green', 'omezena' => 'rd-chip-red', 'vycerpana' => 'rd-chip-red'][$kapacita] ?? 'rd-chip-red';
            $chip_label = ['ok' => 'Kapacita dostupná', 'omezena' => 'Omezená kapacita', 'vycerpana' => 'Kapacita vyčerpána'][$kapacita] ?? '—';
            $chip_dot   = ['ok' => '<svg width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#16a34a"/></svg>', 'omezena' => '<svg width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#d97706"/></svg>', 'vycerpana' => '<svg width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#dc2626"/></svg>'][$kapacita] ?? '<svg width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#dc2626"/></svg>';
            $card_class = $kapacita === 'vycerpana' ? '' : 'rd-choice-new';
        ?>
        <div class="rd-choice-card <?php echo $card_class; ?>" style="cursor:<?php echo $kapacita !== 'vycerpana' ? 'pointer' : 'default'; ?>"
             <?php if ($kapacita !== 'vycerpana'): ?>onclick="location.href='<?php echo esc_url($objednat); ?>'"<?php endif; ?>>
            <div class="rd-choice-icon" style="line-height:1"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="url(#as-grad)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><defs><linearGradient id="as-grad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="var(--au-violet,#7c3aed)"/><stop offset="100%" stop-color="var(--au-pink,#db2777)"/></linearGradient></defs><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>
            <h3><?php echo esc_html($as->nazev); ?></h3>
            <?php if ($as->mesto): ?>
            <p style="margin:0;flex:0">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:3px;opacity:.6"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php echo esc_html($as->mesto); ?>
            </p>
            <?php endif; ?>
            <div class="rd-info-chip <?php echo $chip_class; ?>" style="margin:0;padding:6px 12px;font-size:.75rem">
                <span style="display:inline-flex;align-items:center;gap:5px"><?php echo $chip_dot; ?> <?php echo $chip_label; ?></span>
            </div>
            <p style="margin:0;font-size:.85rem;color:var(--au-text-2)">
                <strong style="color:var(--au-text-1);font-size:1rem"><?php echo $cena_karta; ?> Kč</strong> / řidič bez DPH
            </p>
            <?php if ($kapacita === 'ok'): ?>
            <a href="<?php echo esc_url($objednat); ?>" class="rd-btn rd-btn-primary" style="width:100%;text-align:center;justify-content:center;margin-top:auto">Objednat školení →</a>
            <?php elseif ($kapacita === 'omezena'): ?>
            <a href="<?php echo esc_url($objednat); ?>" class="rd-btn rd-btn-primary" style="width:100%;text-align:center;justify-content:center;margin-top:auto">Objednat školení →</a>
            <?php else: ?>
            <a href="<?php echo esc_url(add_query_arg(['ref'=>$as->slug,'poptavka'=>'1'], home_url('/objednavka/'))); ?>" class="rd-btn" style="width:100%;text-align:center;justify-content:center;margin-top:auto;background:rgba(220,38,38,.12);color:#dc2626;border:1px solid rgba(220,38,38,.3)">Požádat o školení →</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}


/* ============================================================
   v3.0.0 — HOMEPAGE: [rd_uvod_platform]
   Prezentační stránka platformy — používá nativní CSS třídy tématu.
   ============================================================ */
add_shortcode('rd_uvod_platform', 'rdaurora_sc_uvod_platform');
function rdaurora_sc_uvod_platform() {
    if (!session_id()) @session_start();

    // Pokud má řidič aktivní session — přesměrovat do kurzu (ale ne z homepage)
    if (!empty($_SESSION['rd_kod']) && !is_front_page()) {
        wp_redirect(home_url('/kurz/')); exit;
    }

    // Promo video v kartě vpravo od hero textu. Výchozí video je přibalené
    // v tématu (assets/video/), vlastní URL i vypnutí se nastavuje v Customizeru.
    // Soubor musí ležet na vlastní doméně — CSP (refdrive-plugin) povoluje média jen z 'self'.
    $hero_video_on  = get_option('rd_hero_video_on', '1') === '1';
    $hero_video_url = get_option('rd_hero_video_url') ?: get_template_directory_uri() . '/assets/video/refdrive-promo.mp4';
    $hero_poster    = get_template_directory_uri() . '/assets/video/refdrive-promo-poster.jpg';

    ob_start(); ?>
<div class="rd-page rd-uvod" style="padding-top:0">

  <!-- HERO -->
  <div class="rd-hero-wrap<?php echo $hero_video_on ? ' rd-hero-has-video' : ''; ?>">
    <div class="rd-hero-text">
    <!-- Badge linky nad nadpisem -->
    <div class="rd-badge-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:32px;align-items:center">
      <a href="<?php echo home_url('/novinky/'); ?>" class="rd-pill-novinky" style="display:inline-flex;align-items:center;gap:5px;background:rgba(124,58,237,.18);border:1px solid rgba(167,139,250,.5);color:#c4b5fd;border-radius:99px;padding:5px 12px;font-size:.75rem;font-weight:600;text-decoration:none;transition:background .2s" onmouseover="this.style.background='rgba(124,58,237,.28)'" onmouseout="this.style.background='rgba(124,58,237,.18)'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Novinky</a>
      <a href="<?php echo home_url('/legislativa/'); ?>" class="rd-pill-legislativa" style="display:inline-flex;align-items:center;gap:5px;background:rgba(8,145,178,.18);border:1px solid rgba(34,211,238,.4);color:#67e8f9;border-radius:99px;padding:5px 12px;font-size:.75rem;font-weight:600;text-decoration:none;transition:background .2s" onmouseover="this.style.background='rgba(8,145,178,.28)'" onmouseout="this.style.background='rgba(8,145,178,.18)'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg> Legislativa</a>
      <a href="<?php echo home_url('/faq/'); ?>" class="rd-pill-faq" style="display:inline-flex;align-items:center;gap:5px;background:rgba(22,163,74,.15);border:1px solid rgba(74,222,128,.4);color:#4ade80;border-radius:99px;padding:5px 12px;font-size:.75rem;font-weight:600;text-decoration:none;transition:background .2s" onmouseover="this.style.background='rgba(22,163,74,.25)'" onmouseout="this.style.background='rgba(22,163,74,.15)'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> FAQ</a>
    </div>

    <h1 class="rd-hero-h1"><?php echo esc_html((get_option('rd_txt_hero_nadpis') ?: 'Školení řidičů referentů')); ?><br><span class="rd-hero-accent"><?php echo esc_html((get_option('rd_txt_hero_akcent') ?: 'online & okamžitě')); ?></span></h1>
    <p class="rd-hero-sub"><?php echo esc_html((get_option('rd_txt_hero_perex') ?: 'Certifikované e-learningové školení v souladu s § 103 zákoníku práce a NV č. 168/2002 Sb. Certifikát ihned po dokončení, evidence online.')); ?></p>
    <div class="rd-hero-btns">
      <?php if (!empty($_SESSION['rd_kod'])): ?>
      <?php
        global $wpdb;
        $splnil_check = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . (defined('RD_TABLE_RIDICI') ? RD_TABLE_RIDICI : $wpdb->prefix . 'rd_ridici') . " WHERE kod=%s AND uspech=1",
            $_SESSION['rd_kod']
        ));
      ?>
      <?php if ($splnil_check): ?>
      <a href="<?php echo home_url('/certifikat/'); ?>" class="rd-btn rd-btn-primary">Zobrazit certifikát →</a>
      <?php else: ?>
      <a href="<?php echo home_url('/kurz/'); ?>" class="rd-btn rd-btn-primary">Zpět do kurzu →</a>
      <?php endif; ?>
      <?php else: ?>
      <a href="#autoskoly" class="rd-btn rd-btn-primary">Objednat školení <svg style="display:inline;vertical-align:middle;margin-left:4px" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
      <?php if (!isset($_SESSION['rd_firma_id']) && !isset($_SESSION['rd_lektor'])): ?>
      <a href="<?php echo home_url('/prihlaseni/'); ?>" class="rd-btn rd-btn-ghost">Mám kód →</a>

      <?php endif; ?>
      <?php endif; ?>
    </div>
    </div>

    <?php if ($hero_video_on): ?>
    <div class="rd-hero-media">
      <div class="rd-hero-card">
        <video id="rd-hero-video" src="<?php echo esc_url($hero_video_url); ?>" poster="<?php echo esc_url($hero_poster); ?>"
               autoplay muted loop playsinline preload="metadata" aria-label="Ukázka platformy refdrive.pro"></video>
        <button type="button" id="rd-hero-sound" class="rd-hero-sound" aria-label="Zapnout zvuk">
          <svg class="rd-ico-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
          <svg class="rd-ico-on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
        </button>
      </div>
    </div>
    <script>
    (function () {
      var v = document.getElementById('rd-hero-video'), b = document.getElementById('rd-hero-sound');
      if (!v || !b) return;
      // Kdo má v systému omezené animace, nedostane automaticky běžící video — spustí si ho klepnutím.
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        v.removeAttribute('autoplay'); v.pause();
      }
      // Zapnutí zvuku přehraje video od začátku, aby návštěvník slyšel celou reklamu.
      b.addEventListener('click', function () {
        v.muted = !v.muted;
        if (!v.muted) { v.currentTime = 0; v.play().catch(function () {}); }
        b.classList.toggle('is-on', !v.muted);
        b.setAttribute('aria-label', v.muted ? 'Zapnout zvuk' : 'Vypnout zvuk');
      });
      v.addEventListener('click', function () { if (v.paused) v.play().catch(function () {}); else v.pause(); });
    })();
    </script>
    <?php endif; ?>
  </div>

  <!-- JAK TO FUNGUJE – skryto pro přihlášeného řidiče -->
  <?php if (empty($_SESSION['rd_kod'])): ?>
  <div class="rd-how" id="jak-to-funguje">
    <div class="rd-how-label"><?php echo esc_html((get_option('rd_txt_jak_label') ?: 'Jak to funguje')); ?></div>
    <div class="rd-how-steps">
      <?php
      $kroky_hp = rd_get_kroky();
      foreach ($kroky_hp as $ki => $krok): ?>
      <div class="rd-how-step">
        <div class="rd-how-n"><?php echo $ki + 1; ?></div>
        <div class="rd-how-body">
          <h4><?php echo esc_html($krok['h']); ?></h4>
          <p><?php echo esc_html($krok['p']); ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:24px">
      <a href="<?php echo esc_url(home_url('/jaktofunguje/')); ?>" style="display:inline-flex;align-items:center;gap:6px;color:var(--au-violet,#7c3aed);font-size:.9rem;font-weight:600;text-decoration:none;opacity:.85;transition:opacity .15s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.85">
        Zjistit více
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
    </div>
  </div>
  <?php endif; // rd_kod ?>



  <!-- SEZNAM AUTOŠKOL – skrýt pro přihlášeného řidiče -->
  <?php if (empty($_SESSION['rd_kod'])): ?>
  <div id="autoskoly" style="margin-bottom:60px">
    <?php if (!empty($_GET['zprava']) && $_GET['zprava'] === 'cena_navysena'):
        // Načíst ceny pro banner
        $banner_as_id    = intval($_GET['as_id'] ?? 0);
        $banner_firma_id = intval($_GET['firma_id'] ?? 0);
        $banner_ceny = null;
        if ($banner_as_id && $banner_firma_id) {
            global $wpdb;
            $banner_ceny = $wpdb->get_row($wpdb->prepare(
                "SELECT fa.nakupni_cena, a.cena_kus as aktualni_cena, a.nazev as as_nazev
                 FROM " . RD_TABLE_FIRMA_AS . " fa
                 JOIN " . RD_TABLE_AUTOSKOLY . " a ON a.id=fa.autoskola_id
                 WHERE fa.firma_id=%d AND fa.autoskola_id=%d LIMIT 1",
                $banner_firma_id, $banner_as_id
            ));
        }
    ?>
    <div class="rd-info-chip rd-chip-red" style="margin-bottom:20px">
      <strong>Změna ceny školení<?php if ($banner_ceny): ?> — <?php echo esc_html($banner_ceny->as_nazev); ?><?php endif; ?></strong><br>
      <?php if ($banner_ceny && $banner_ceny->nakupni_cena): ?>
      Vaše poslední nákupní cena: <strong><?php echo intval($banner_ceny->nakupni_cena); ?> Kč / řidič</strong>. Aktuální cena: <strong><?php echo intval($banner_ceny->aktualni_cena); ?> Kč / řidič</strong>.<br>
      <?php endif; ?>
      Můžete pokračovat u stávající autoškoly nebo vybrat jinou.
    </div>
    <?php endif; ?>
    <div style="padding:8px 0 24px">
      <a href="<?php echo home_url('/registrace-autoskoly/'); ?>" class="rd-reg-badge" style="display:flex;align-items:center;gap:14px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);border-left:3px solid #22c55e;border-radius:10px;padding:16px 18px;text-decoration:none;width:100%;box-sizing:border-box;transition:background .2s;animation:rdBadgeFadeUp .6s ease both" onmouseover="this.style.background='rgba(34,197,94,.17)'" onmouseout="this.style.background='rgba(34,197,94,.1)'">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
        <div style="flex:1;min-width:0">
          <p class="rd-reg-h" style="margin:0 0 4px;font-size:.88rem;font-weight:600;color:#4ade80">Registrace nových autoškol spuštěna</p>
          <p class="rd-reg-p" style="margin:0;font-size:.78rem;color:#86efac;line-height:1.5;opacity:.85">Přestaňte být jen místní autoškola. S RefDrive.pro oslovíte firemní klienty z celé České republiky – školení probíhá online, objednávky přijímáte bez zbytečné administrativy.</p>
        </div>

      </a>
    </div>
    <div class="rd-how-label" style="margin-top:32px"><?php echo esc_html((get_option('rd_txt_autoskoly_label') ?: 'Partnerské autoškoly')); ?></div>
    <?php echo rdaurora_sc_seznam_autoskol(); ?>
    <p style="margin-top:16px;margin-bottom:0;font-size:.85rem;color:var(--au-text-2);text-align:center;max-width:320px;margin-left:auto;margin-right:auto">
      <?php echo esc_html((get_option('rd_txt_autoskoly_podtext') ?: 'Jste autoškola a chcete nabízet školení?')); ?><br><a href="<?php echo home_url('/registrace-autoskoly/'); ?>" style="color:var(--au-violet)">Zaregistrujte se →</a>
    </p>
  </div>
  <?php endif; ?>



</div>
    <?php return ob_get_clean();
}


/* ============================================================
   CUSTOMIZER — Editovatelné texty tématu
   ============================================================ */
add_action('customize_register', function($wp_customize) {

    // Sekce: Základní texty
    $wp_customize->add_section('aurora_texty', [
        'title'    => 'Texty webu',
        'priority' => 30,
    ]);

    $texty = [
        // [option_name, label, default, type]
        ['rd_txt_slogan',           'Slogan (patička)',
         'RefDrive.pro je platforma, která propojuje firmy s autoškoly. Zprostředkovává zákonné školení referentských řidičů. Vše online – certifikát bez zbytečného papírování.',
         'textarea'],
        ['rd_txt_splash_tagline',   'Splash screen – tagline',
         'Školení řidičů online', 'text'],
        ['rd_txt_copyright',        'Copyright text (patička)',
         'RefDrive Pro. Všechna práva vyhrazena.', 'text'],
        ['rd_txt_cookies',          'Text cookie lišty',
         'Tento web používá cookies pro zajištění funkčnosti a analýzu návštěvnosti. Kliknutím na „Přijmout" souhlasíte s jejich použitím.',
         'textarea'],
        ['rd_txt_btn_kurz',         'Tlačítko hlavičky – kurz',
         'Vstoupit do kurzu', 'text'],
        ['rd_txt_btn_firma',        'Tlačítko hlavičky – firma',
         'Firemní přístup', 'text'],
        ['rd_txt_btn_install',      'Tlačítko – instalace aplikace',
         'Nainstalovat aplikaci', 'text'],
        ['rd_txt_novinky_odkaz',    'Odkaz Novinky – text',
         'Novinky 2026', 'text'],
        ['rd_txt_novinky_url',      'Odkaz Novinky – URL (relativní)',
         '/novinky-2026/', 'text'],
        ['rd_txt_footer_vop',       'Patička – odkaz VOP (text)',
         'VOP', 'text'],
        ['rd_txt_footer_gdpr',      'Patička – odkaz GDPR (text)',
         'GDPR', 'text'],
        ['rd_txt_footer_faq',       'Patička – odkaz FAQ (text)',
         'FAQ', 'text'],
        ['rd_txt_footer_ssl',       'Patička – SSL badge text',
         'SSL zabezpečeno', 'text'],
        ['rd_txt_autoskoly_label',  'Homepage – nadpis sekce autoškol',
         'Partnerské autoškoly', 'text'],
        ['rd_txt_autoskoly_podtext','Homepage – podtext sekce autoškol',
         'Jste autoškola a chcete nabízet školení?', 'text'],
    ];

    foreach ($texty as [$id, $label, $default, $type]) {
        $wp_customize->add_setting($id, [
            'default'           => $default,
            'type'              => 'option',
            'transport'         => 'refresh',
            'sanitize_callback' => $type === 'textarea' ? 'sanitize_textarea_field' : 'sanitize_text_field',
        ]);
        $wp_customize->add_control($id, [
            'label'   => $label,
            'section' => 'aurora_texty',
            'type'    => $type,
        ]);
    }

    // Sekce: Video na úvodní stránce (karta vedle hero textu)
    $wp_customize->add_section('aurora_hero_video', [
        'title'    => 'Video na úvodní stránce',
        'priority' => 32,
    ]);
    $wp_customize->add_setting('rd_hero_video_on', [
        'default'           => '1',
        'type'              => 'option',
        'transport'         => 'refresh',
        'sanitize_callback' => function ($v) { return $v ? '1' : '0'; },
    ]);
    $wp_customize->add_control('rd_hero_video_on', [
        'label'   => 'Zobrazit video na úvodní stránce',
        'section' => 'aurora_hero_video',
        'type'    => 'checkbox',
    ]);
    $wp_customize->add_setting('rd_hero_video_url', [
        'default'           => '',
        'type'              => 'option',
        'transport'         => 'refresh',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    $wp_customize->add_control('rd_hero_video_url', [
        'label'       => 'URL vlastního videa (MP4)',
        'description' => 'Prázdné = výchozí video tématu. Video musí být na této doméně (např. z knihovny médií) a na výšku 9:16.',
        'section'     => 'aurora_hero_video',
        'type'        => 'url',
    ]);

    // Sekce: Dopravní pozadí (úvodní sekce + patička)
    $wp_customize->add_section('aurora_bg_doprava', [
        'title'       => 'Dopravní pozadí',
        'description' => 'Decentní animované pozadí za úvodní sekcí a v patičce. Barvy se řídí zvoleným barevným tématem a světlým/tmavým režimem.',
        'priority'    => 33,
    ]);
    $wp_customize->add_setting('rd_bg_doprava', [
        'default'           => 'dalnice',
        'type'              => 'option',
        'transport'         => 'refresh',
        'sanitize_callback' => function ($v) { return array_key_exists($v, rdaurora_bg_doprava_styly()) ? $v : 'dalnice'; },
    ]);
    $wp_customize->add_control('rd_bg_doprava', [
        'label'   => 'Styl pozadí',
        'section' => 'aurora_bg_doprava',
        'type'    => 'select',
        'choices' => rdaurora_bg_doprava_styly(),
    ]);

    // Sekce: URL patičky
    $wp_customize->add_section('aurora_urls', [
        'title'    => 'URL patičky a headeru',
        'priority' => 31,
    ]);

    $urls = [
        ['rd_url_vop',         'URL – VOP',              '/vseobecne-podminky/'],
        ['rd_url_gdpr',        'URL – GDPR/Ochrana dat', '/ochrana-osobnich-udaju/'],
        ['rd_url_faq',         'URL – FAQ',              '/faq/'],
        ['rd_url_legislativa', 'URL – Legislativa',      '/legislativa/'],
        ['rd_url_prihlaseni',  'URL – Přihlášení',       '/prihlaseni/'],
        ['rd_url_firma',       'URL – Firemní portál',   '/firma-portal/'],
        ['rd_url_registrace',  'URL – Registrace autoškoly', '/registrace-autoskoly/'],
        ['rd_url_cookies_vice','URL – Více o cookies',   '/ochrana-osobnich-udaju/'],
    ];

    foreach ($urls as [$id, $label, $default]) {
        $wp_customize->add_setting($id, [
            'default'           => $default,
            'type'              => 'option',
            'transport'         => 'refresh',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        $wp_customize->add_control($id, [
            'label'   => $label,
            'section' => 'aurora_urls',
            'type'    => 'text',
        ]);
    }
});

// Helper: načti text s fallbackem na výchozí hodnotu
function aurora_txt($option, $default = '') {
    echo esc_html(get_option($option) ?: $default);
}
function aurora_url($option, $default = '/') {
    echo esc_url(home_url(get_option($option) ?: $default));
}
