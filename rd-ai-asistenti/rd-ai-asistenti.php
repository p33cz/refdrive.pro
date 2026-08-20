<?php
/**
 * Plugin Name: refdrive.pro - AI Asistenti
 * Plugin URI:  https://refdrive.pro/
 * Description: AI chatoví asistenti pro RefDrive.pro — samostatní agenti pro autoškoly a firmy, odpovídají na otázky o vlastních datech i o fungování platformy.
 * Version: 1.0.65
 * Author: refdrive.pro
 * Author URI: https://refdrive.pro/
 * Text Domain: rd-ai-asistenti
 */

if (!defined('ABSPATH')) exit;

define('RDAI_VERSION', '1.0.65');
define('RDAI_PATH', plugin_dir_path(__FILE__));
define('RDAI_URL', plugin_dir_url(__FILE__));

require_once RDAI_PATH . 'includes/platform-mechanics.php';
require_once RDAI_PATH . 'includes/agent-autoskola.php';
require_once RDAI_PATH . 'includes/agent-firma.php';

/* ============================================================
   ADMIN MENU — RD AI Asistenti (hlavní stránka + podstránky agentů)
   ============================================================ */

add_action('admin_menu', function () {
    add_menu_page(
        'RD AI Asistenti',
        'RD AI Asistenti',
        'manage_options',
        'rd-ai-asistenti',
        'rdai_main_settings_page',
        'dashicons-format-chat',
        58
    );

    add_submenu_page(
        'rd-ai-asistenti',
        'Obecné nastavení',
        'Obecné nastavení',
        'manage_options',
        'rd-ai-asistenti',
        'rdai_main_settings_page'
    );

    add_submenu_page(
        'rd-ai-asistenti',
        'Agent: Autoškoly',
        'Agent: Autoškoly',
        'manage_options',
        'rd-ai-agent-autoskola',
        'rdai_autoskola_settings_page'
    );

    add_submenu_page(
        'rd-ai-asistenti',
        'Agent: Firmy',
        'Agent: Firmy',
        'manage_options',
        'rd-ai-agent-firma',
        'rdai_firma_settings_page'
    );
});

add_action('admin_init', function () {
    // Sdílený API klíč pro všechny agenty — VLASTNÍ skupina
    register_setting('rdai_apikey_group', 'rd_ai_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('rdai_apikey_group', 'rd_ai_show_rawdata', [
        'sanitize_callback' => function ($v) { return $v ? 1 : 0; },
    ]);
    // Každý agent má VLASTNÍ skupinu, aby se formuláře nepřepisovaly navzájem
    register_setting('rdai_agent_autoskola_group', 'rd_ai_enabled_autoskola', [
        'sanitize_callback' => function ($v) { return $v ? 1 : 0; },
    ]);
    register_setting('rdai_agent_firma_group', 'rd_ai_enabled_firma', [
        'sanitize_callback' => function ($v) { return $v ? 1 : 0; },
    ]);

    // Editovatelné prompty — agent Autoškoly
    register_setting('rdai_agent_autoskola_group', 'rd_ai_prompt_as_klasifikuj', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);
    register_setting('rdai_agent_autoskola_group', 'rd_ai_prompt_as_data', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);
    register_setting('rdai_agent_autoskola_group', 'rd_ai_prompt_as_faq', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);

    // Editovatelné prompty — agent Firmy
    register_setting('rdai_agent_firma_group', 'rd_ai_prompt_firma_klasifikuj', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);
    register_setting('rdai_agent_firma_group', 'rd_ai_prompt_firma_data', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);
    register_setting('rdai_agent_firma_group', 'rd_ai_prompt_firma_faq', [
        'sanitize_callback' => 'rdai_sanitize_prompt',
    ]);
});

/**
 * Sanitizace pro textarea s promptem — zachovává řádkování a diakritiku,
 * jen odstraní případné HTML tagy a osekáni krajní bílé znaky.
 * Prázdný řetězec je validní hodnota — znamená "použij výchozí prompt z kódu".
 */
function rdai_sanitize_prompt($value) {
    $value = wp_unslash($value ?? '');
    $value = wp_strip_all_tags($value);
    return trim($value);
}

add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'rd-ai-agent-') === false) return;
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll(".rdai-reset-prompt").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var target = document.getElementsByName(btn.dataset.target)[0];
                var def = document.getElementById(btn.dataset.default);
                if (target && def) {
                    if (!confirm("Opravdu obnovit výchozí prompt? Neuložené vlastní úpravy budou přepsány.")) return;
                    target.value = def.value;
                }
            });
        });
    });
    </script>
    <?php
});

/* ============================================================
   Hlavní stránka — společný API klíč + přehled agentů
   ============================================================ */

function rdai_main_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>RD AI Asistenti — nastavení</h1>
        <p>Zde nastavíš společný API klíč pro AI asistenty. Jednotlivé agenty (autoškoly, firmy) zapínáš/vypínáš na jejich vlastních záložkách v menu vlevo.</p>

        <form method="post" action="options.php">
            <?php settings_fields('rdai_apikey_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="rd_ai_api_key">Anthropic API klíč</label></th>
                    <td>
                        <input type="password" id="rd_ai_api_key" name="rd_ai_api_key"
                               value="<?php echo esc_attr(get_option('rd_ai_api_key', '')); ?>"
                               class="regular-text" autocomplete="off" placeholder="sk-ant-...">
                        <p class="description">Klíč najdeš na <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a> (API Keys). Nutné mít doplněný kredit v sekci Billing. Tento klíč používají všichni agenti níže.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Zdrojová data v chatu</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rd_ai_show_rawdata" value="1" <?php checked(get_option('rd_ai_show_rawdata', 1), 1); ?>>
                            Zobrazovat rozklikávací "Zobrazit zdrojová data" pod odpověďmi (transparentnost — uživatel vidí přesná data, ze kterých AI odpovídala)
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Uložit nastavení'); ?>
        </form>

        <hr>
        <h2>Přehled agentů</h2>
        <table class="widefat" style="max-width:700px">
            <thead>
                <tr><th>Agent</th><th>Stav</th><th>Nastavení</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Autoškoly</td>
                    <td><?php echo get_option('rd_ai_enabled_autoskola', 1) ? '✅ Aktivní' : '⛔ Vypnuto'; ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=rd-ai-agent-autoskola')); ?>">Upravit</a></td>
                </tr>
                <tr>
                    <td>Firmy</td>
                    <td><?php echo get_option('rd_ai_enabled_firma', 1) ? '✅ Aktivní' : '⛔ Vypnuto'; ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=rd-ai-agent-firma')); ?>">Upravit</a></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

/* ============================================================
   SDÍLENÉ AI VOLÁNÍ (Anthropic API)
   ============================================================ */

/**
 * Naparsuje historii konverzace (JSON z JS) na text pro kontext promptu.
 * Max 4 zprávy (2 výměny). Bezpečně ošetří chybný/chybějící vstup.
 */
function rdai_get_conversation_context() {
    $raw = wp_unslash($_POST['historie'] ?? '');
    if (!$raw) return '';

    $items = json_decode($raw, true);
    if (!is_array($items)) return '';

    $lines = [];
    foreach (array_slice($items, -4) as $item) {
        if (empty($item['role']) || empty($item['text'])) continue;
        $role = $item['role'] === 'user' ? 'Uživatel' : 'Asistent';
        $text = sanitize_text_field($item['text']);
        if (mb_strlen($text) > 300) $text = mb_substr($text, 0, 300);
        $lines[] = "$role: $text";
    }

    if (empty($lines)) return '';
    return "Předchozí část konverzace (pro kontext):\n" . implode("\n", $lines) . "\n\n";
}

/**
 * Stáhne textový obsah veřejné stránky webu (kontakt, VOP, GDPR) a vrátí
 * jako prostý text. Cachuje na 6 hodin přes transient, ať se nestahuje
 * při každém dotazu.
 */
function rdai_fetch_public_page_text($slug) {
    $cache_key = 'rdai_page_' . sanitize_key($slug);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $url = home_url('/' . trim($slug, '/') . '/');
    $response = wp_remote_get($url, ['timeout' => 8]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        set_transient($cache_key, '', 10 * MINUTE_IN_SECONDS); // krátký cache i pro neúspěch
        return '';
    }

    $html = wp_remote_retrieve_body($response);

    // Extrahovat hlavní obsah — odstranit script/style, pak tagy
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
    $text = wp_strip_all_tags($html);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    // Omezit délku, ať prompt nenarůstá nekontrolovaně
    if (mb_strlen($text) > 3000) $text = mb_substr($text, 0, 3000) . '…';

    set_transient($cache_key, $text, 6 * HOUR_IN_SECONDS);
    return $text;
}

/**
 * Vrátí blok s veřejnými informacemi o platformě (kontakt, VOP, GDPR)
 * stažený přímo ze stránek webu — vždy aktuální.
 */
function rdai_get_platform_pages_context() {
    $pages = [
        'kontakt' => 'Kontakt',
        'vseobecne-podminky'     => 'Všeobecné obchodní podmínky (VOP)',
        'ochrana-osobnich-udaju' => 'Ochrana osobních údajů (GDPR)',
    ];

    $text = '';
    foreach ($pages as $slug => $title) {
        $content = rdai_fetch_public_page_text($slug);
        if ($content) {
            $text .= "=== Stránka: $title (" . home_url('/' . $slug . '/') . ") ===\n" . $content . "\n\n";
        }
    }
    return $text;
}

function rdai_call_claude($prompt, $max_tokens) {
    $api_key = get_option('rd_ai_api_key', '');
    if (!$api_key) {
        return 'AI asistent není nakonfigurován (chybí API klíč v Nastavení → RD AI Asistenti).';
    }

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key'         => $api_key,
            'content-type'      => 'application/json',
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode([
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => $max_tokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return 'Omlouváme se, AI asistent je momentálně nedostupný. Zkuste to prosím později.';
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || empty($body['content'][0]['text'])) {
        return 'Omlouváme se, nepodařilo se získat odpověď od AI asistenta.';
    }

    return $body['content'][0]['text'];
}

/* ============================================================
   SDÍLENÝ FAQ KONTEXT (rd_faq tabulka)
   ============================================================ */

function rdai_get_faq_rows() {
    global $wpdb;
    $tbl = $wpdb->prefix . 'rd_faq';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) return [];
    return $wpdb->get_results("SELECT otazka, odpoved FROM {$tbl} ORDER BY poradi");
}

/* ============================================================
   SDÍLENÝ WIDGET — vykreslení + JS/CSS
   ============================================================ */

function rdai_enqueue_widget_assets($css_variant = 'firma') {
    wp_enqueue_script('rdai-widget', RDAI_URL . 'assets/widget.js', [], RDAI_VERSION, true);
    wp_enqueue_style('rdai-widget', RDAI_URL . 'assets/widget-' . $css_variant . '.css', [], RDAI_VERSION);
}

function rdai_localize_widget($action, $nonce_action, $title) {
    wp_localize_script('rdai-widget', 'rdaiConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce($nonce_action),
        'action'  => $action,
        'title'   => $title,
    ]);
}

function rdai_widget_html($welcome_text) {
    $btn_text = $welcome_text['button_text'] ?? null;
    ob_start();
    ?>
    <div id="rdai-widget" class="rdai-widget">
        <input type="checkbox" id="rdai-toggle-cb" class="rdai-toggle-cb">
        <label for="rdai-toggle-cb" class="rdai-toggle" aria-label="AI asistent">
            <?php if ($btn_text): ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                <?php echo esc_html($btn_text); ?>
            <?php else: ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/><circle cx="8" cy="10" r="1" fill="currentColor" stroke="none"/><circle cx="12" cy="10" r="1" fill="currentColor" stroke="none"/><circle cx="16" cy="10" r="1" fill="currentColor" stroke="none"/></svg>
            <?php endif; ?>
        </label>
        <div id="rdai-panel" class="rdai-panel">
            <div class="rdai-header">
                <span><?php echo esc_html($welcome_text['title']); ?></span>
                <label for="rdai-toggle-cb" class="rdai-close-label" aria-label="Zavřít">✕</label>
            </div>
            <div id="rdai-messages" class="rdai-messages">
                <div class="rdai-msg rdai-msg-bot"><?php echo esc_html($welcome_text['intro']); ?></div>
            </div>
            <form id="rdai-form" class="rdai-form">
                <input type="text" id="rdai-input" placeholder="Napište otázku..." autocomplete="off" required>
                <button type="submit" aria-label="Odeslat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
