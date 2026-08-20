<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   AGENT: FIRMY — VÝCHOZÍ ŠABLONY PROMPTŮ
   (přesná citace původního kódu; dynamické části nahrazeny
   placeholdery {KONTEXT}, {OTAZKA}, {DATA_JSON}, {FAQ_KONTEXT}, {AUTOSKOLA_KONTAKT})
   ============================================================ */

function rdai_firma_default_prompt_klasifikuj() {
    return "{KONTEXT}Uživatel (firma na platformě RefDrive.pro — objednává školení řidičů) se ptá: \"{OTAZKA}\"\n\n"
        . "Vyber VŠECHNY relevantní kategorie podle toho, na co se otázka ptá (otázka může kombinovat víc témat najednou, a může navazovat na předchozí konverzaci výše):\n"
        . "1 = přehled kódů (kolik máme celkem, využitých, nevyužitých)\n"
        . "2 = expirace/platnost našich nevyužitých kódů (kdy vyprší, jestli někde vidíme datum expirace, brzy expirující kódy)\n"
        . "3 = seznam/výsledky proškolených řidičů (jména, skóre, úspěšnost)\n"
        . "4 = souhrnný počet proškolených řidičů (úspěšní/neúspěšní)\n"
        . "5 = stav našich poptávek (čekající, schválené, zamítnuté)\n"
        . "6 = poslední přidělené/použité kódy\n"
        . "7 = jaký máme balíček/tarif/cenu za kódy, kolik platíme za školení, naše napojení na autoškolu, kontakt na autoškolu od které máme kódy\n"
        . "8 = rozeslané kódy zaměstnancům (komu byl kód odeslán, kdy, zda ho použil, chci znovu odeslat, přehled odeslaných kódů)\n"
        . "faq = obecná otázka o tom, jak platforma funguje — nikoliv o vlastních datech\n\n"
        . "Odpověz POUZE čísly oddělenými čárkou (např. '1,2'), nebo 'faq'. Pokud kombinuje datovou otázku s obecnou, vyber jen číslo/čísla. Nic jiného, žádný text navíc.";
}

function rdai_firma_default_prompt_data() {
    return "{KONTEXT}Jsi AI asistent platformy RefDrive.pro (systém pro povinná školení řidičů — komunikuje s firmou, která školení objednává).\n"
        . "Níže jsou aktuální data PŘIHLÁŠENÉ FIRMY z databáze (JSON):\n\n"
        . "{DATA_JSON}\n\n"
        . "Otázka firmy: \"{OTAZKA}\"\n\n"
        . "NAVIGACE V PORTÁLU: odhlášení = ikonka šipky [→] vedle názvu firmy v záhlaví; přepínání světlý/tmavý režim = ikonka slunce/měsíce; menu = hamburger vpravo. Na tyto dotazy vždy odpověz konkrétně, neposílej na podporu. "
        . "Pole 'trvani_min' u každého řidiče udává skutečnou délku jeho školení v minutách (aktivní čas strávený na lekcích a testu, s pauzou při přepnutí okna). "
        . "Pokud je trvani_min > 0, uváděj ho jako 'Délka školení: X minut'. Pokud je 0 nebo chybí, řekni že délka nebyla zaznamenána. "
        . "Odpověz stručně, konkrétně a věcně v češtině, vycházej pouze z těchto dat. Piš čistým textem BEZ Markdown formátování (žádné **, #, -, odrážky). "
        . "Pokud jsou data prázdná nebo nulová, řekni to přirozeně. Neuváděj technické názvy polí, mluv normálně. "
        . "DŮLEŽITÉ: neuváděj jako fakt žádnou konkrétní hodnotu (číslo, lhůtu, pravidlo), která není obsažena v datech výše ani v dříve uvedeném kontextu (TECHNICKÉ MECHANISMY) — i kdyby se ti zdála pravděpodobná nebo logická. Pokud si nejsi jistý/á, řekni, že tuto konkrétní informaci nemáš k dispozici a doporuč kontaktovat podporu RefDrive.pro. "
        . "DŮLEŽITÉ — OBJEDNÁVKA/DOKOUPENÍ KÓDŮ: pokud firma potřebuje další kódy (nemá volné kódy pro další řidiče), NIKDY nedoporučuj kontaktovat autoškolu e-mailem/telefonicky ani platit mimo platformu. "
        . "Údaje jako autoskola_email, autoskola_telefon a nakupni_cena jsou pouze informativní/kontextové. "
        . "Postup je VŽDY: na stránce /firma-portal/ kliknout na tlačítko 'Objednat školení' u příslušné autoškoly a zadat počet kódů — tím vznikne poptávka, kterou autoškola schválí (do 12 hodin) a kódy se automaticky vygenerují.";
}

function rdai_firma_default_prompt_faq() {
    return "{KONTEXT}Jsi AI asistent platformy RefDrive.pro pro firmy objednávající školení řidičů. Zde je dokumentace fungování platformy:\n\n"
        . "{FAQ_KONTEXT}\n\n"
        . "{AUTOSKOLA_KONTAKT}"
        . "Otázka firmy: \"{OTAZKA}\"\n\n"
        . "Odpověz stručně a věcně v češtině na základě této dokumentace. Piš čistým textem BEZ Markdown formátování (žádné **, #, -, odrážky). "
        . "DŮLEŽITÉ: Otázky týkající se OBSAHU školení — konkrétní lekce, témata testu, výklad legislativy, novinky v zákonech, termíny/aktualizace kurzu — NEPATŘÍ na podporu RefDrive.pro (ta řeší jen technické záležitosti platformy). Tyto otázky vždy směruj na AUTOŠKOLU, která kurz vede a vydala kódy (použij její kontakt, pokud je uveden výše). "
        . "Pro technické dotazy o platformě samotné (přihlášení, faktury, chyby systému) směruj na podporu RefDrive.pro. "
        . "Pokud je odpověď v dokumentaci obsažena (včetně sekce TECHNICKÉ MECHANISMY), odpověz s jistotou a NEPŘIDÁVEJ doporučení kontaktovat podporu — taková výzva tam patří JEN když informaci skutečně nemáš. "
        . "Pokud informaci v dokumentaci nemáš a nejde o žádný z výše uvedených případů, řekni, ať se obrátí na podporu RefDrive.pro. "
        . "Nikdy nevymýšlej informace, které tu nejsou.";
}

/* ============================================================
   AGENT: FIRMY — nastavení
   ============================================================ */

function rdai_firma_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Agent: Firmy</h1>
        <form method="post" action="options.php">
            <?php settings_fields('rdai_agent_firma_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Agent aktivní</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rd_ai_enabled_firma" value="1" <?php checked(get_option('rd_ai_enabled_firma', 1), 1); ?>>
                            Zobrazovat AI asistenta přihlášeným firmám
                        </label>
                    </td>
                </tr>
            </table>

            <h2>Prompty agenta</h2>
            <p class="description">Zde můžeš upravit přesné znění promptů, které agent posílá AI modelu. Ve výchozím stavu je zde přesná citace promptů z kódu pluginu. Prázdné pole = použije se výchozí prompt z kódu. Zástupné texty (např. <code>{OTAZKA}</code>) NEMAZAT — na jejich místo se za běhu vloží skutečná data.</p>

            <h3>1) Klasifikace záměru otázky</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code> (historie konverzace), <code>{OTAZKA}</code> (dotaz firmy).</p>
            <textarea name="rd_ai_prompt_firma_klasifikuj" rows="14" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_firma_klasifikuj', rdai_firma_default_prompt_klasifikuj())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_firma_klasifikuj" data-default="rdai-default-firma-klasifikuj">Obnovit výchozí</button></p>
            <textarea id="rdai-default-firma-klasifikuj" style="display:none"><?php echo esc_textarea(rdai_firma_default_prompt_klasifikuj()); ?></textarea>

            <h3>2) Odpověď na datovou otázku</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code>, <code>{DATA_JSON}</code> (data z databáze), <code>{OTAZKA}</code>.</p>
            <textarea name="rd_ai_prompt_firma_data" rows="10" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_firma_data', rdai_firma_default_prompt_data())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_firma_data" data-default="rdai-default-firma-data">Obnovit výchozí</button></p>
            <textarea id="rdai-default-firma-data" style="display:none"><?php echo esc_textarea(rdai_firma_default_prompt_data()); ?></textarea>

            <h3>3) Odpověď z FAQ / dokumentace platformy</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code>, <code>{FAQ_KONTEXT}</code>, <code>{AUTOSKOLA_KONTAKT}</code> (kontakt na napojenou autoškolu), <code>{OTAZKA}</code>.</p>
            <textarea name="rd_ai_prompt_firma_faq" rows="12" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_firma_faq', rdai_firma_default_prompt_faq())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_firma_faq" data-default="rdai-default-firma-faq">Obnovit výchozí</button></p>
            <textarea id="rdai-default-firma-faq" style="display:none"><?php echo esc_textarea(rdai_firma_default_prompt_faq()); ?></textarea>

            <?php submit_button('Uložit'); ?>
        </form>

        <hr>
        <h2>Co tento agent umí</h2>
        <p>Po přihlášení firmy do portálu se zobrazí chat. Firma se může zeptat na:</p>
        <ul style="list-style:disc;margin-left:20px">
            <li>počet zakoupených / nevyužitých / využitých kódů</li>
            <li>kódy expirující do 30 dní</li>
            <li>kteří řidiči byli proškoleni a s jakým výsledkem</li>
            <li>stav podaných poptávek</li>
            <li>obecné dotazy na fungování platformy (z FAQ)</li>
        </ul>
        <p>Všechna data jsou vždy omezena na přihlášenou firmu (<code>firma_email</code> / <code>firma_id</code>).</p>
    </div>
    <?php
}

/* ============================================================
   AGENT: FIRMY — widget vykreslení
   ============================================================ */

add_action('wp_footer', function () {
    if (!get_option('rd_ai_enabled_firma', 1)) return;

    $firma = rdai_firma_get_current_silent();
    if (!$firma) return;

    rdai_enqueue_widget_assets('firma');
    rdai_localize_widget('rd_ai_dotaz_firma', 'rd_ai_nonce_firma', 'AI asistent RefDrive');

    echo rdai_widget_html([
        'title' => 'AI asistent RefDrive',
        'intro' => 'Dobrý den! Zeptejte se mě na vaše kódy, proškolené řidiče, poptávky nebo na fungování platformy.',
    ]);
});

/**
 * Bezpečná verze — vrátí firmu podle session, nebo null.
 */
function rdai_firma_get_current_silent() {
    if (!session_id() && !(defined('DOING_CRON') && DOING_CRON)) @session_start();

    global $wpdb;
    if (empty($_SESSION['rd_firma_id'])) return null;

    return $wpdb->get_row($wpdb->prepare(
        "SELECT id, nazev, email FROM {$wpdb->prefix}rd_firmy WHERE id=%d LIMIT 1",
        intval($_SESSION['rd_firma_id'])
    ));
}

/* ============================================================
   AGENT: FIRMY — AJAX handler
   ============================================================ */

add_action('wp_ajax_rd_ai_dotaz_firma', 'rdai_firma_ajax_handler');
add_action('wp_ajax_nopriv_rd_ai_dotaz_firma', 'rdai_firma_ajax_handler');

function rdai_firma_ajax_handler() {
    check_ajax_referer('rd_ai_nonce_firma', 'nonce');

    if (!get_option('rd_ai_enabled_firma', 1)) {
        wp_send_json_error(['message' => 'Asistent je momentálně vypnutý.']);
    }

    $firma = rdai_firma_get_current_silent();
    if (!$firma) {
        wp_send_json_error(['message' => 'Nejste přihlášeni jako firma.']);
    }

    $otazka = sanitize_text_field(wp_unslash($_POST['otazka'] ?? ''));
    if (!$otazka) wp_send_json_error(['message' => 'Prázdná otázka.']);
    if (mb_strlen($otazka) > 500) $otazka = mb_substr($otazka, 0, 500);
    $context = rdai_get_conversation_context();

    $intents = rdai_firma_klasifikuj($otazka, $context);

    if ($intents === ['faq']) {
        $odpoved = rdai_firma_odpoved_faq($otazka, $context, $firma);
        wp_send_json_success(['odpoved' => $odpoved]);
    } else {
        $data = [];
        foreach ($intents as $i) {
            $d = rdai_firma_nacti_data($i, $firma);
            if ($d !== null) $data = array_merge($data, $d);
        }
        if (empty($data)) {
            $odpoved = rdai_firma_odpoved_faq($otazka, $context, $firma);
            wp_send_json_success(['odpoved' => $odpoved]);
        } else {
            $odpoved = rdai_firma_odpoved_data($otazka, $data, $context);
            $resp = ['odpoved' => $odpoved];
            if (get_option('rd_ai_show_rawdata', 1)) $resp['data'] = $data;
            wp_send_json_success($resp);
        }
    }
}

/* ============================================================
   AGENT: FIRMY — datové dotazy (vždy omezené na firma_email/id)
   ============================================================ */

/**
 * Vrátí krátký textový blok s kontaktem na kmenovou autoškolu firmy
 * (pro směrování dotazů na obsah školení/legislativu).
 */
function rdai_firma_get_autoskola_kontakt($firma) {
    global $wpdb;
    $tbl_fa = $wpdb->prefix . 'rd_firma_autoskola';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT a.nazev, a.email, a.telefon
         FROM {$tbl_fa} fa
         JOIN " . RD_TABLE_AUTOSKOLY . " a ON a.id = fa.autoskola_id
         WHERE fa.firma_id=%d AND fa.kmenova=1 AND (fa.do IS NULL OR fa.do > NOW())
         ORDER BY fa.od DESC LIMIT 1", intval($firma->id)), ARRAY_A);

    if (!$row) return '';

    $text = "KONTAKT NA AUTOŠKOLU (POUZE pro dotazy k OBSAHU školení — lekce, témata testu, legislativa, výklad. "
          . "NIKDY tento kontakt nepoužívej pro objednání/dokoupení kódů — to je vždy přes tlačítko 'Objednat školení' na /firma-portal/, viz výše): "
          . $row['nazev'];
    if (!empty($row['email'])) $text .= ", email: " . $row['email'];
    if (!empty($row['telefon'])) $text .= ", telefon: " . $row['telefon'];
    return $text . "\n\n";
}

function rdai_firma_nacti_data($intent, $firma) {
    global $wpdb;
    $email = $firma->email;
    $firma_id = intval($firma->id);

    switch ($intent) {
        case '1': // přehled kódů (celkem, využité, nevyužité)
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS celkem, SUM(pouzit) AS pouzito, COUNT(*)-SUM(pouzit) AS nevyuzito
                 FROM " . RD_TABLE_KODY . " WHERE firma_email=%s", $email), ARRAY_A);
            return ['prehled_kodu' => $row];

        case '2': // expirace nevyužitých kódů (všechny, ne jen 30 dní)
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT kod, vytvoreno, expiruje, autoskola_id FROM " . RD_TABLE_KODY . "
                 WHERE firma_email=%s AND pouzit=0
                 ORDER BY expiruje ASC", $email), ARRAY_A);
            return [
                'nevyuzite_kody_a_expirace' => $rows,
                'vysvetleni' => 'vytvoreno = datum zakoupení/vygenerování kódu. expiruje = datum, do kdy musí být kód využit (typicky 1 rok od vygenerování, podle nastavení autoškoly). Hodnota NULL u expiruje znamená, že kód nemá nastavenou expiraci. Po datu expirace kód již nelze použít ke školení. Ve firemním portálu je v tabulce "Přehled řidičů" sloupec "Zakoupeno" (datum vytvoreno) a "Platnost do" (datum expiruje) — expirovaný nevyužitý kód je zde zvýrazněn červeně.',
            ];

        case '3': // proškolení řidiči
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT jmeno, score, uspech, datum_skoleni, trvani_min FROM " . RD_TABLE_RIDICI . "
                 WHERE firma_email=%s ORDER BY datum_skoleni DESC LIMIT 20", $email), ARRAY_A);
            return ['proskoleni_ridici' => $rows];

        case '4': // souhrn proškolení (počty)
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS celkem, SUM(uspech) AS uspesnych, COUNT(*)-SUM(uspech) AS neuspesnych
                 FROM " . RD_TABLE_RIDICI . " WHERE firma_email=%s", $email), ARRAY_A);
            return ['souhrn_skoleni' => $row];

        case '5': // stav poptávek podaných firmou
            $tbl = $wpdb->prefix . 'rd_poptavky';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, pocet, stav, vytvoreno FROM {$tbl}
                 WHERE firma_email=%s ORDER BY vytvoreno DESC LIMIT 10", $email), ARRAY_A);
            return ['poptavky' => $rows];

        case '6': // posledně použité/přidělené kódy
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT kod, ridic_jmeno, pouzit, datum_pouziti, vytvoreno FROM " . RD_TABLE_KODY . "
                 WHERE firma_email=%s ORDER BY vytvoreno DESC LIMIT 15", $email), ARRAY_A);
            return ['nedavne_kody' => $rows];

        case '8': // rozeslané kódy zaměstnancům
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT kod, zamestnanec_jmeno, zamestnanec_email, kod_odeslan_at, pouzit, ridic_jmeno, expiruje
                 FROM " . RD_TABLE_KODY . "
                 WHERE firma_email=%s AND zamestnanec_email IS NOT NULL AND zamestnanec_email != ''
                 ORDER BY kod_odeslan_at DESC", $email), ARRAY_A);
            $volne = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . RD_TABLE_KODY . "
                 WHERE firma_email=%s AND pouzit=0 AND (zamestnanec_email IS NULL OR zamestnanec_email='')", $email));
            return [
                'rozeslane_kody' => $rows,
                'volnych_k_rozeslani' => (int)$volne,
                'vysvetleni' => 'zamestnanec_jmeno/zamestnanec_email = komu byl kód odeslán systémem. kod_odeslan_at = datum odeslání. pouzit=1 znamená zaměstnanec se přihlásil a absolvoval školení. ridic_jmeno = jméno které zaměstnanec zadal při přihlášení (může se lišit od zamestnanec_jmeno). Firma může kód znovu odeslat přes tlačítko Znovu v portálu.',
            ];

        case '7': // cena/balíček — naše napojení na autoškolu
            $tbl_fa = $wpdb->prefix . 'rd_firma_autoskola';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.nazev AS autoskola_nazev, a.email AS autoskola_email, a.telefon AS autoskola_telefon,
                        fa.nakupni_cena, fa.cena_smer, fa.kmenova, fa.od, fa.do
                 FROM {$tbl_fa} fa
                 JOIN " . RD_TABLE_AUTOSKOLY . " a ON a.id = fa.autoskola_id
                 WHERE fa.firma_id=%d ORDER BY fa.kmenova DESC, fa.od DESC", $firma_id), ARRAY_A);
            return [
                'napojeni_a_ceny' => $rows,
                'vysvetleni' => 'RefDrive.pro nenabízí firmám předplacené balíčky ani tarify — firma platí za jednotlivé kódy (školení) napojené autoškole. nakupni_cena je cena za kód dohodnutá s danou autoškolou, pokud je nastavena (jinak platí standardní ceník autoškoly). autoskola_email a autoskola_telefon jsou přímé kontaktní údaje na autoškolu, od které firma kódy nakupuje.',
            ];
    }

    return null;
}

/* ============================================================
   AGENT: FIRMY — AI prompty
   ============================================================ */

function rdai_firma_klasifikuj($otazka, $context = '') {
    $template = get_option('rd_ai_prompt_firma_klasifikuj', '');
    if ($template === '') $template = rdai_firma_default_prompt_klasifikuj();
    $prompt = strtr($template, [
        '{KONTEXT}' => $context,
        '{OTAZKA}'  => $otazka,
    ]);

    $resp = trim(strtolower(rdai_call_claude($prompt, 15)));
    if ($resp === 'faq' || $resp === '') return ['faq'];

    $parts = array_filter(array_map('trim', explode(',', $resp)), 'ctype_digit');
    if (empty($parts)) return ['faq'];
    return array_values($parts);
}

function rdai_firma_odpoved_data($otazka, $data, $context = '') {
    $template = get_option('rd_ai_prompt_firma_data', '');
    if ($template === '') $template = rdai_firma_default_prompt_data();
    $prompt = strtr($template, [
        '{KONTEXT}'   => $context,
        '{DATA_JSON}' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        '{OTAZKA}'    => $otazka,
    ]);

    return rdai_call_claude($prompt, 400);
}

function rdai_firma_odpoved_faq($otazka, $context = '', $firma = null) {
    $faq = rdai_firma_get_faq_kontext();
    $autoskola_kontakt = $firma ? rdai_firma_get_autoskola_kontakt($firma) : '';

    $template = get_option('rd_ai_prompt_firma_faq', '');
    if ($template === '') $template = rdai_firma_default_prompt_faq();
    $prompt = strtr($template, [
        '{KONTEXT}'            => $context,
        '{FAQ_KONTEXT}'        => $faq,
        '{AUTOSKOLA_KONTAKT}'  => $autoskola_kontakt,
        '{OTAZKA}'             => $otazka,
    ]);

    return rdai_call_claude($prompt, 400);
}

function rdai_firma_get_faq_kontext() {
    $text = '';
    foreach (rdai_get_faq_rows() as $r) {
        $text .= "Q: {$r->otazka}\nA: {$r->odpoved}\n\n";
    }

    $text .= rdai_get_platform_mechanics_kontext() . "\n";

    $pages = rdai_get_platform_pages_context();
    if ($pages) {
        $text .= "VEŘEJNÉ INFORMACE Z WEBU (kontakt, VOP, GDPR — staženo přímo ze stránek):\n" . $pages . "\n";
    }

    $text .= "DOPLŇUJÍCÍ INFORMACE O FUNGOVÁNÍ PLATFORMY PRO FIRMY:\n\n";
    $text .= "KÓDY: Firma získá kódy pro proškolení svých řidičů buď nákupem (objednávkou), "
           . "nebo přidělením od autoškoly. Každý kód lze použít jednou — řidič ho zadá a absolvuje e-learning a kvíz.\n\n";
    $text .= "EXPIRACE KÓDŮ: Nevyužité kódy mají datum expirace. Po expiraci je nelze použít — "
           . "v takovém případě je možné požádat o reklamaci/výměnu kódu přes autoškolu, která kódy vydala.\n\n";
    $text .= "POPTÁVKY A DOKOUPENÍ KÓDŮ: Pokud firma potřebuje více kódů, než kolik má aktuálně k dispozici (např. nemá žádné volné kódy pro další řidiče), "
           . "POSTUP JE VŽDY: na stránce /firma-portal/ klikne na tlačítko 'Objednat školení' u příslušné partnerské autoškoly a zadá počet kódů. "
           . "Tím vznikne poptávka u autoškoly. Autoškola má 12 hodin na schválení nebo zamítnutí; po schválení se kódy automaticky vygenerují a objeví ve firemním portálu. "
           . "Firma může poptávku kdykoliv zrušit tlačítkem 'Nechci čekat'. "
           . "DŮLEŽITÉ: NIKDY nedoporučuj firmě, aby kódy objednávala přímo e-mailem/telefonicky u autoškoly nebo platila mimo platformu — "
           . "veškeré objednávky a platby za kódy probíhají VÝHRADNĚ přes tlačítko 'Objednat školení' na /firma-portal/. "
           . "Cena za kód (je-li v datech k dispozici) je informativní — konkrétní postup objednání je vždy přes platformu, ne kontaktováním autoškoly.\n\n";
    $text .= "CERTIFIKÁTY: Po úspěšném absolvování školení a kvízu řidič obdrží certifikát, "
           . "který je dostupný ke stažení/ověření v systému.\n\n";
    $text .= "NAVIGACE V PORTÁLU:\n"
           . "Odhlášení — ikonka šipky [→] vedle názvu firmy v záhlaví stránky.\n"
           . "Přepínání světlý/tmavý režim — ikonka slunce/měsíce v záhlaví vpravo.\n"
           . "Menu — hamburger (tři vodorovné čáry) vpravo v záhlaví.\n"
           . "Na tyto dotazy vždy odpověz konkrétně, neposílej na podporu.\n\n"
           . "OVLÁDÁNÍ PŘEHLEDU ŘIDIČŮ (tabulka kódů):\n"
           . "STATISTICKÉ KARTY: Čtyři karty nahoře — CELKEM, SPLNILO, PROBÍHÁ, VOLNÝCH. "
           . "Kliknutím na kartu se tabulka filtruje na daný stav. "
           . "Karta VOLNÝCH ukazuje i počet odeslaných (přiřazených) kódů pod číslem. "
           . "Druhý klik na aktivní kartu filtr zruší a zobrazí vše.\n"
           . "FILTROVÁNÍ: Karta VOLNÝCH zobrazí jak skutečně volné kódy, tak kódy ve stavu Odesláno (přiřazené, ale dosud nepoužité).\n"
           . "ŘAZENÍ: Kliknutím na název sloupce (Kód, Jméno řidiče, Stav, Score, Absolvováno, Zakoupeno, Platnost do) se tabulka seřadí. "
           . "První klik = vzestupně (↑), druhý klik = sestupně (↓). Ikonka ⇅ se změní na ↑ nebo ↓.\n"
           . "STAVY KÓDŮ: Splnil (zelený) = absolvoval úspěšně; Nesplnil (červený) = neprošel testem; "
           . "Probíhá (modrý) = přihlášen, studuje; Odesláno (fialový) = kód přiřazen zaměstnanci emailem, čeká; "
           . "Volný (žlutý) = nepřiřazený, nikdo ho dosud nepoužil.\n"
           . "SLOUPEC AKCE: Tři ikonková tlačítka s popisky — "
           . "Certifikát (zelená, ikonka medaile) = zobrazí PDF certifikát absolventa; "
           . "Znovu (fialová, ikonka šipky) = znovu odešle email zaměstnanci s kódem; "
           . "Přeřadit (žlutá, ikonka přeřazení) = přiřadí kód jinému zaměstnanci. "
           . "Tooltip s popisem se zobrazí při najetí myší.\n"
           . "EXPORT: Pod tabulkou jsou tlačítka Export absolventů (CSV) a Certifikáty (ZIP).\n\n";
    $text .= "ROZESLÁNÍ KÓDŮ ZAMĚSTNANCŮM — KOMPLETNÍ PŘEHLED:\n\n"
           . "SPUŠTĚNÍ: Na /firma-portal/ je tlačítko 'Rozeslat kódy zaměstnancům (X volných)' — zobrazí se jen pokud jsou volné nepřiřazené kódy. "
           . "Číslo v závorce = počet kódů dostupných k rozeslání (nezahrnuje již přiřazené).\n\n"
           . "ZADÁNÍ ZAMĚSTNANCŮ: Dva způsoby — (1) Ruční zadání: jméno + e-mail pro každého zaměstnance, tlačítko '+ Přidat zaměstnance'. "
           . "(2) Import CSV: soubor s hlavičkou 'jmeno,email', každý řádek jeden zaměstnanec, kódování UTF-8.\n\n"
           . "OCHRANA PROTI DUPLICITÁM: Systém automaticky přeskočí zaměstnance kteří: "
           . "(a) jsou v CSV uvedeni dvakrát — zpracuje se jen první výskyt; "
           . "(b) již mají přiřazený kód (stav Odesláno) — přeskočeno s hláškou 'již má přiřazený kód, lze znovu odeslat z tabulky'; "
           . "(c) již absolvovali školení — přeskočeno s hláškou 'již absolvoval'. "
           . "Výsledky přeskočených se zobrazí v modalu jantarovou barvou — NEJSOU v tabulce kódů.\n\n"
           . "ODESLÁNÍ: Systém odešle každému zaměstnanci e-mail s jeho osobním kódem a přímým odkazem. "
           . "Kód se přiřadí zaměstnanci AŽ po úspěšném odeslání — pokud e-mail selže, kód zůstane volný.\n\n"
           . "UZAMČENÍ KÓDU NA JMÉNO: Pokud je kód přiřazen konkrétnímu zaměstnanci, jiný člověk se pod tímto kódem přihlásit nemůže "
           . "(systém ověřuje shodu jméno při přihlášení). Volné kódy (bez přiřazeného zaměstnance) může použít kdokoliv.\n\n"
           . "PŘEHLED V TABULCE: U každého přiřazeného kódu je vidět jméno zaměstnance, e-mail a datum odeslání. "
           . "Stavy: 'Odesláno' (fialový) = přiřazeno, čeká; 'Probíhá' = přihlásil se, studuje; 'Splnil'/'Nesplnil' = dokončeno.\n\n"
           . "AKCE V TABULCE (sloupec Akce): "
           . "(1) Certifikát — pro absolventy, otevře PDF certifikát. "
           . "(2) Znovu odeslat — pro stav Odesláno, pošle e-mail znovu na stejnou adresu. "
           . "(3) Přeřadit — pro stav Odesláno, umožní přiřadit kód jinému zaměstnanci; původní dostane informační e-mail o zrušení, nový dostane e-mail s kódem. "
           . "Přeřazení je možné pouze pokud zaměstnanec ještě nezačal (stav Odesláno), ne ve stavu Probíhá/Splnil/Nesplnil.\n\n"
           . "STATISTIKY: Karta 'Volných' ukazuje celkový počet volných kódů; pod číslem je fialový text kolik jich je již odesláno zaměstnancům. "
           . "Expirační upozornění uvádí kolik z expirujících/expirovaných kódů bylo odesláno zaměstnancům.\n\n"
           . "PŘIHLÁŠENÍ ZAMĚSTNANCE: Zaměstnanec klikne odkaz v e-mailu → formulář má předvyplněné jméno (může ho opravit) → certifikát je na skutečně zadané jméno.\n\n"
           . "DEAKTIVACE: Hlavní admin může funkci rozeslání vypnout v Nastavení plateb — tlačítko pak zmizí úplně.\n";

    return $text;
}
