<?php
if (!defined('ABSPATH')) exit;

/* ============================================================
   AGENT: AUTOŠKOLY — VÝCHOZÍ ŠABLONY PROMPTŮ
   (přesná citace původního kódu; dynamické části nahrazeny
   placeholdery {KONTEXT}, {OTAZKA}, {DATA_JSON}, {FAQ_KONTEXT})
   ============================================================ */

function rdai_as_default_prompt_klasifikuj() {
    return "{KONTEXT}Uživatel (autoškola na platformě RefDrive.pro) se ptá: \"{OTAZKA}\"\n\n"
        . "Vyber VŠECHNY relevantní kategorie podle toho, na co se otázka ptá (otázka může kombinovat víc témat najednou, a může navazovat na předchozí konverzaci výše):\n"
        . "1 = kolik kódů ještě můžeme prodat/vygenerovat, zbývající kapacita balíčku (např. \"kolik máme volných kódů\", \"kolik ještě můžeme prodat\", \"kolik nám zbývá z balíčku\")\n"
        . "2 = které kódy brzy expirují (do 30 dní)\n"
        . "3 = kolik kódů bylo celkem využito/aktivováno\n"
        . "4 = platby, faktury, kolik jsme zaplatili nebo dluží\n"
        . "5 = náš tarif/tier, limit kódů na měsíc, cena předplatného\n"
        . "6 = kolik firem máme napojených\n"
        . "7 = kolik řidičů jsme proškolili\n"
        . "8 = čekající poptávky od firem\n"
        . "9 = žádosti o změnu údajů nebo reklamace\n"
        . "10 = kdy expiruje naše předplatné\n"
        . "11 = jak se firma (klient autoškoly) přihlašuje do svého portálu / jaké má přihlašovací údaje / email firmy\n"
        . "12 = kolik už prodaných kódů zatím nikdo nevyužil / čeká na využití zaměstnanci firem (odlišné od kategorie 1 — toto jsou kódy, které firmy už mají, ale ještě je nikdo nepoužil)\n"
        . "faq = obecná otázka o tom, jak platforma funguje (poptávky, kódy, ceny, proces) — nikoliv o vlastních datech\n\n"
        . "Odpověz POUZE čísly oddělenými čárkou (např. '1,2'), nebo 'faq'. Pokud kombinuje datovou otázku s obecnou, vyber jen číslo/čísla. Nic jiného, žádný text navíc.";
}

function rdai_as_default_prompt_data() {
    return "{KONTEXT}Jsi AI asistent platformy RefDrive.pro (systém pro povinná školení řidičů firem přes autoškoly).\n"
        . "Níže jsou aktuální data PŘIHLÁŠENÉ AUTOŠKOLY z databáze (JSON):\n\n"
        . "{DATA_JSON}\n\n"
        . "Otázka autoškoly: \"{OTAZKA}\"\n\n"
        . "Pole 'trvani_min' u každého řidiče udává skutečnou délku jeho školení v minutách (aktivní čas na lekcích a testu). Pokud je > 0, uváděj jako 'Délka školení: X minut'. "
        . "ZMĚNA TARIFU/BALÍČKU: Pokud se autoškola ptá na změnu, upgrade nebo objednání jiného balíčku, VŽDY odpověz: změna balíčku se provede přes menu 'Paušální balíček' v levém menu administrace — tam jsou všechny dostupné tarify a autoškola si vybere sama. NIKDY neposílej na podporu kvůli změně balíčku. "
        . "Odpověz stručně, konkrétně a věcně v češtině, vycházej pouze z těchto dat. Piš čistým textem BEZ Markdown formátování (žádné **, #, -, odrážky). "
        . "Pokud jsou data prázdná nebo nulová, řekni to přirozeně. Neuváděj technické názvy polí, mluv normálně. "
        . "DŮLEŽITÉ: neuváděj jako fakt žádnou konkrétní hodnotu (číslo, lhůtu, pravidlo), která není obsažena v datech výše ani v dříve uvedeném kontextu — i kdyby se ti zdála pravděpodobná nebo logická. Pokud si nejsi jistý/á a nejde o změnu balíčku, řekni, že tuto konkrétní informaci nemáš k dispozici.";
}

function rdai_as_default_prompt_faq() {
    return "{KONTEXT}Jsi AI asistent platformy RefDrive.pro pro autoškoly. Zde je dokumentace fungování platformy:\n\n"
        . "{FAQ_KONTEXT}\n\n"
        . "Otázka autoškoly: \"{OTAZKA}\"\n\n"
        . "ZMĚNA TARIFU/BALÍČKU: Pokud se autoškola ptá na změnu, upgrade nebo objednání jiného balíčku, VŽDY odpověz: jde to přes menu 'Paušální balíček' v levém menu administrace — tam jsou všechny dostupné tarify, autoškola si vybere sama. NIKDY neposílej na podporu kvůli změně balíčku. "
        . "Odpověz stručně a věcně v češtině na základě této dokumentace. Piš čistým textem BEZ Markdown formátování (žádné **, #, -, odrážky). "
        . "DŮLEŽITÉ: Otázky týkající se OBSAHU školení — konkrétní lekce, témata testu, výklad legislativy, novinky v zákonech, aktualizace osnov — jsou v gesci AUTOŠKOLY samotné (tedy tebe/uživatele), nikoliv podpory RefDrive.pro. Na takové otázky odpověz, že obsah a aktuálnost kurzu si autoškola správcuje sama, případně RefDrive.pro řeší jen technickou stránku platformy. "
        . "Pro technické dotazy o platformě (přihlášení, faktury, chyby systému) směruj na podporu RefDrive.pro. "
        . "Pokud je odpověď v dokumentaci obsažena (včetně sekce TECHNICKÉ MECHANISMY), odpověz s jistotou a NEPŘIDÁVEJ doporučení kontaktovat podporu — taková výzva tam patří JEN když informaci skutečně nemáš. "
        . "Pokud informaci v dokumentaci nemáš a nejde o žádný z výše uvedených případů, řekni, ať se obrátí na podporu RefDrive.pro. "
        . "Nikdy nevymýšlej informace, které tu nejsou.";
}

/* ============================================================
   AGENT: AUTOŠKOLY — nastavení
   ============================================================ */

function rdai_autoskola_settings_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Agent: Autoškoly</h1>
        <form method="post" action="options.php">
            <?php settings_fields('rdai_agent_autoskola_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Agent aktivní</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rd_ai_enabled_autoskola" value="1" <?php checked(get_option('rd_ai_enabled_autoskola', 1), 1); ?>>
                            Zobrazovat AI asistenta přihlášeným autoškolám
                        </label>
                    </td>
                </tr>
            </table>

            <h2>Prompty agenta</h2>
            <p class="description">Zde můžeš upravit přesné znění promptů, které agent posílá AI modelu. Ve výchozím stavu je zde přesná citace promptů z kódu pluginu. Prázdné pole = použije se výchozí prompt z kódu. Zástupné texty (např. <code>{OTAZKA}</code>) NEMAZAT — na jejich místo se za běhu vloží skutečná data.</p>

            <h3>1) Klasifikace záměru otázky</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code> (historie konverzace), <code>{OTAZKA}</code> (dotaz autoškoly).</p>
            <textarea name="rd_ai_prompt_as_klasifikuj" rows="14" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_as_klasifikuj', rdai_as_default_prompt_klasifikuj())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_as_klasifikuj" data-default="rdai-default-as-klasifikuj">Obnovit výchozí</button></p>
            <textarea id="rdai-default-as-klasifikuj" style="display:none"><?php echo esc_textarea(rdai_as_default_prompt_klasifikuj()); ?></textarea>

            <h3>2) Odpověď na datovou otázku</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code>, <code>{DATA_JSON}</code> (data z databáze), <code>{OTAZKA}</code>.</p>
            <textarea name="rd_ai_prompt_as_data" rows="10" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_as_data', rdai_as_default_prompt_data())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_as_data" data-default="rdai-default-as-data">Obnovit výchozí</button></p>
            <textarea id="rdai-default-as-data" style="display:none"><?php echo esc_textarea(rdai_as_default_prompt_data()); ?></textarea>

            <h3>3) Odpověď z FAQ / dokumentace platformy</h3>
            <p class="description">Placeholdery: <code>{KONTEXT}</code>, <code>{FAQ_KONTEXT}</code> (FAQ + technické mechanismy + veřejné stránky), <code>{OTAZKA}</code>.</p>
            <textarea name="rd_ai_prompt_as_faq" rows="12" class="large-text code" style="font-family:monospace"><?php echo esc_textarea(get_option('rd_ai_prompt_as_faq', rdai_as_default_prompt_faq())); ?></textarea>
            <p><button type="button" class="button rdai-reset-prompt" data-target="rd_ai_prompt_as_faq" data-default="rdai-default-as-faq">Obnovit výchozí</button></p>
            <textarea id="rdai-default-as-faq" style="display:none"><?php echo esc_textarea(rdai_as_default_prompt_faq()); ?></textarea>

            <?php submit_button('Uložit'); ?>
        </form>

        <hr>
        <h2>Co tento agent umí</h2>
        <p>Po přihlášení autoškoly se v portálu zobrazí chat. Autoškola se může zeptat na:</p>
        <ul style="list-style:disc;margin-left:20px">
            <li>kolik kódů ještě může autoškola prodat (zbývající kapacita balíčku)</li>
            <li>kolik už prodaných kódů zatím nikdo nevyužil</li>
            <li>kódy expirující do 30 dní</li>
            <li>celkem aktivovaných kódů</li>
            <li>platby a faktury</li>
            <li>tarif, limit kódů, cenu předplatného</li>
            <li>počet napojených firem</li>
            <li>počet proškolených řidičů</li>
            <li>čekající poptávky</li>
            <li>žádosti o změnu údajů / reklamace</li>
            <li>expirace předplatného</li>
            <li>obecné dotazy na fungování platformy (z FAQ)</li>
        </ul>
        <p>Všechna data jsou vždy omezena na přihlášenou autoškolu (<code>autoskola_id</code>).</p>
    </div>
    <?php
}

/* ============================================================
   AGENT: AUTOŠKOLY — widget vykreslení
   ============================================================ */

add_action('admin_footer', function () {
    if (!get_option('rd_ai_enabled_autoskola', 1)) return;
    if (!function_exists('rd_get_my_autoskola')) return;

    $as = rdai_as_get_current_silent();
    if (!$as) return;

    rdai_enqueue_widget_assets('autoskola');
    rdai_localize_widget('rd_ai_dotaz_autoskola', 'rd_ai_nonce_autoskola', 'AI asistent RefDrive');

    echo rdai_widget_html([
        'title'       => 'AI asistent RefDrive',
        'intro'       => 'Dobrý den! Zeptejte se mě na vaše kódy, faktury, expiraci předplatného nebo na fungování platformy.',
        'button_text' => 'AI asistent',
    ]);
});

/**
 * Bezpečná verze — vrátí autoškolu podle přihlášeného WP uživatele (admin portál), nebo null.
 * Nikdy nevypisuje HTML (na rozdíl od rd_get_my_autoskola()).
 */
function rdai_as_get_current_silent() {
    if (!is_user_logged_in()) return null;
    if (current_user_can('manage_options')) return null; // hlavní admin nemá agenta autoškoly

    global $wpdb;
    if (!defined('RD_TABLE_AUTOSKOLY')) return null;

    $user_id = get_current_user_id();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT id, nazev FROM " . RD_TABLE_AUTOSKOLY . " WHERE wp_user_id=%d AND stav='aktivni' LIMIT 1",
        $user_id
    ));
}

/* ============================================================
   AGENT: AUTOŠKOLY — AJAX handler
   ============================================================ */

add_action('wp_ajax_rd_ai_dotaz_autoskola', 'rdai_as_ajax_handler');

function rdai_as_ajax_handler() {
    check_ajax_referer('rd_ai_nonce_autoskola', 'nonce');

    if (!get_option('rd_ai_enabled_autoskola', 1)) {
        wp_send_json_error(['message' => 'Asistent je momentálně vypnutý.']);
    }

    $as = rdai_as_get_current_silent();
    if (!$as) {
        wp_send_json_error(['message' => 'Nejste přihlášeni jako autoškola.']);
    }

    $otazka = sanitize_text_field(wp_unslash($_POST['otazka'] ?? ''));
    if (!$otazka) wp_send_json_error(['message' => 'Prázdná otázka.']);
    if (mb_strlen($otazka) > 500) $otazka = mb_substr($otazka, 0, 500);

    $as_id = intval($as->id);
    $context = rdai_get_conversation_context();

    $intents = rdai_as_klasifikuj($otazka, $context);

    if ($intents === ['faq']) {
        $odpoved = rdai_as_odpoved_faq($otazka, $context);
        wp_send_json_success(['odpoved' => $odpoved]);
    } else {
        $data = [];
        foreach ($intents as $i) {
            $d = rdai_as_nacti_data($i, $as_id);
            if ($d !== null) $data = array_merge($data, $d);
        }
        if (empty($data)) {
            $odpoved = rdai_as_odpoved_faq($otazka, $context);
            wp_send_json_success(['odpoved' => $odpoved]);
        } else {
            $odpoved = rdai_as_odpoved_data($otazka, $data, $context);
            $resp = ['odpoved' => $odpoved];
            if (get_option('rd_ai_show_rawdata', 1)) $resp['data'] = $data;
            wp_send_json_success($resp);
        }
    }
}

/* ============================================================
   AGENT: AUTOŠKOLY — datové dotazy
   ============================================================ */

function rdai_as_nacti_data($intent, $as_id) {
    global $wpdb;

    switch ($intent) {
        case '1':
            // Kolik kódů ještě může autoškola prodat/vygenerovat v rámci svého balíčku —
            // stejná logika jako "Zbývá celkem" na Přehledu (rd_autoskola_zbyvajici_kapacita).
            // NENÍ to počet už prodaných, ale nevyužitých kódů (to je karta "Nepřiřazených kódů").
            if (function_exists('rd_autoskola_zbyvajici_kapacita') && function_exists('rd_autoskola_aktivni_platby')) {
                $zbyvaji_celkem = rd_autoskola_zbyvajici_kapacita($as_id);
                $platby = rd_autoskola_aktivni_platby($as_id);
                $balicky = [];
                foreach ($platby as $p) {
                    $balicky[] = [
                        'tier_nazev' => $p->tier_nazev,
                        'limit_kodu' => $p->limit_kodu >= 99999 ? 'neomezeno' : (int)$p->limit_kodu,
                        'zbyva'      => $p->limit_kodu >= 99999 ? 'neomezeno' : (int)$p->zbyvaji,
                        'plati_do'   => $p->platnost_do,
                    ];
                }
                return [
                    'zbyvajici_kapacita_k_prodeji' => ($zbyvaji_celkem === null) ? 0 : ($zbyvaji_celkem === PHP_INT_MAX ? 'neomezeno' : (int)$zbyvaji_celkem),
                    'balicky_detail' => $balicky,
                    'vysvetleni' => 'zbyvajici_kapacita_k_prodeji = kolik kódů ještě může autoškola vygenerovat/prodat v rámci aktivních balíčků (předplaceného limitu). Toto NENÍ počet již prodaných, ale zatím nevyužitých kódů — to je jiná informace (kódy čekající na využití zaměstnanci).',
                ];
            }
            // Záložní varianta, pokud funkce hlavního pluginu nejsou dostupné
            return ['pocet_nevyuzitych_kodu' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . RD_TABLE_KODY . " WHERE autoskola_id=%d AND pouzit=0", $as_id))];

        case '2':
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT kod, expiruje, firma_nazev FROM " . RD_TABLE_KODY . "
                 WHERE autoskola_id=%d AND pouzit=0 AND expiruje IS NOT NULL
                 AND expiruje BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY expiruje ASC", $as_id), ARRAY_A);
            return ['expirujici_kody_30_dni' => $rows];

        case '3':
            return ['celkem_aktivovanych_kodu' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . RD_TABLE_KODY . " WHERE autoskola_id=%d AND pouzit=1", $as_id))];

        case '4':
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT castka, stav, typ, platnost_od, platnost_do, vytvoreno FROM " . RD_TABLE_AS_PLATBY . "
                 WHERE autoskola_id=%d ORDER BY vytvoreno DESC LIMIT 12", $as_id), ARRAY_A);
            return ['platby' => $rows];

        case '5':
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT a.kody_tento_mesic, t.limit_kodu, t.nazev AS tier_nazev, t.cena_mesic, a.predplatne_expiruje
                 FROM " . RD_TABLE_AUTOSKOLY . " a LEFT JOIN " . RD_TABLE_AS_TIERY . " t ON t.id = a.tier_id
                 WHERE a.id=%d", $as_id), ARRAY_A);
            $row['co_se_stane_pri_nezaplaceni'] = 'Pokud autoškola po vypršení předplatného platbu neuhradí, 7 dní po expiraci se profil autoškoly automaticky deaktivuje (stav predplatne_expirovano) a ZMIZÍ ze seznamu autoškol viditelného firmám/zákazníkům na platformě. To znamená ztrátu viditelnosti pro nové klienty, dokud autoškola předplatné neobnoví. Existující napojené firmy a vydané kódy tím nejsou zpětně zrušeny, ale autoškola nebude moci přijímat nové objednávky přes platformu.';
            return ['predplatne' => $row];

        case '6':
            $tbl = $wpdb->prefix . 'rd_firma_autoskola';
            return ['pocet_napojenych_firem' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl} WHERE autoskola_id=%d AND kmenova=1 AND (do IS NULL OR do > NOW())", $as_id))];

        case '7':
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS pocet, MIN(datum_skoleni) AS od, MAX(datum_skoleni) AS do
                 FROM " . RD_TABLE_RIDICI . " WHERE autoskola_id=%d AND uspech=1", $as_id), ARRAY_A);
            $seznam = $wpdb->get_results($wpdb->prepare(
                "SELECT jmeno, firma_nazev, score, datum_skoleni, trvani_min
                 FROM " . RD_TABLE_RIDICI . " WHERE autoskola_id=%d AND uspech=1
                 ORDER BY datum_skoleni DESC LIMIT 50", $as_id), ARRAY_A);
            return ['proskoleni_ridici' => $row, 'seznam_ridiců' => $seznam];

        case '8':
            $tbl = $wpdb->prefix . 'rd_poptavky';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, firma_nazev, pocet, vytvoreno FROM {$tbl}
                 WHERE autoskola_id=%d AND stav='nova' ORDER BY vytvoreno ASC", $as_id), ARRAY_A);
            return ['cekajici_poptavky' => $rows];

        case '9':
            $tbl = $wpdb->prefix . 'rd_zmeny_zadosti';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pole_label, stara_hodnota, nova_hodnota, stav, vytvoreno FROM {$tbl}
                 WHERE autoskola_id=%d ORDER BY vytvoreno DESC LIMIT 10", $as_id), ARRAY_A);
            return ['zadosti_o_zmenu' => $rows];

        case '10':
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT predplatne_expiruje, stav FROM " . RD_TABLE_AUTOSKOLY . " WHERE id=%d", $as_id), ARRAY_A);
            $row['co_se_stane_pri_nezaplaceni'] = 'Pokud autoškola po vypršení předplatného platbu neuhradí, 7 dní po expiraci se profil automaticky deaktivuje a ZMIZÍ ze seznamu autoškol viditelného firmám/zákazníkům — ztráta viditelnosti pro nové klienty, dokud se předplatné neobnoví. Pokud se neobnoví do 6 měsíců, profil se automaticky anonymizuje (nevratně) — 5 měsíců po deaktivaci přijde varovný email s výzvou stáhnout export absolventů a certifikáty.';
            return ['predplatne_expirace' => $row];

        case '11': // přihlašovací údaje napojených firem (princip + email, bez tokenu)
            $tbl = $wpdb->prefix . 'rd_firma_autoskola';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT f.nazev, f.email FROM {$tbl} fa
                 JOIN {$wpdb->prefix}rd_firmy f ON f.id = fa.firma_id
                 WHERE fa.autoskola_id=%d AND fa.kmenova=1 AND (fa.do IS NULL OR fa.do > NOW())
                 ORDER BY f.nazev", $as_id), ARRAY_A);
            return [
                'napojene_firmy_prihlaseni' => $rows,
                'princip_prihlaseni' => 'Firma se přihlašuje do svého portálu pomocí svého emailu. Heslo se nezadává — firma na přihlašovací stránce zadá email a systém jí pošle jednorázový přihlašovací kód/odkaz na tento email, kterým se přihlásí. Autoškola nemá přístup k tomuto kódu, pouze k emailu firmy.',
            ];

        case '12':
            return ['pocet_prodanych_nevyuzitych_kodu' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . RD_TABLE_KODY . " WHERE autoskola_id=%d AND pouzit=0", $as_id))];
    }

    return null;
}

/* ============================================================
   AGENT: AUTOŠKOLY — AI prompty
   ============================================================ */

function rdai_as_klasifikuj($otazka, $context = '') {
    $template = get_option('rd_ai_prompt_as_klasifikuj', '');
    if ($template === '') $template = rdai_as_default_prompt_klasifikuj();
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

function rdai_as_odpoved_data($otazka, $data, $context = '') {
    $template = get_option('rd_ai_prompt_as_data', '');
    if ($template === '') $template = rdai_as_default_prompt_data();
    $prompt = strtr($template, [
        '{KONTEXT}'   => $context,
        '{DATA_JSON}' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
        '{OTAZKA}'    => $otazka,
    ]);

    return rdai_call_claude($prompt, 400);
}

function rdai_as_odpoved_faq($otazka, $context = '') {
    $faq = rdai_as_get_faq_kontext();

    $template = get_option('rd_ai_prompt_as_faq', '');
    if ($template === '') $template = rdai_as_default_prompt_faq();
    $prompt = strtr($template, [
        '{KONTEXT}'     => $context,
        '{FAQ_KONTEXT}' => $faq,
        '{OTAZKA}'      => $otazka,
    ]);

    return rdai_call_claude($prompt, 400);
}

function rdai_as_get_faq_kontext() {
    $text = '';
    foreach (rdai_get_faq_rows() as $r) {
        $text .= "Q: {$r->otazka}\nA: {$r->odpoved}\n\n";
    }

    $text .= rdai_get_platform_mechanics_kontext() . "\n";

    $pages = rdai_get_platform_pages_context();
    if ($pages) {
        $text .= "VEŘEJNÉ INFORMACE Z WEBU (kontakt, VOP, GDPR — staženo přímo ze stránek):\n" . $pages . "\n";
    }

    $text .= "DOPLŇUJÍCÍ INFORMACE O FUNGOVÁNÍ PLATFORMY PRO AUTOŠKOLY:\n\n";
    $text .= "POPTÁVKOVÝ FLOW: Pokud firma poptá více kódů, než kolik má autoškola volné kapacity, "
           . "vytvoří se poptávka se stavem 'nova'. Autoškola má 12 hodin na schválení nebo zamítnutí. "
           . "Firma může poptávku zrušit tlačítkem 'Nechci čekat'. Pokud autoškola poptávku schválí, "
           . "kódy se vygenerují a přiřadí firmě.\n\n";
    $text .= "PŘEDPLATNÉ A TIERY: Autoškola má tarif (tier) s měsíčním limitem kódů a měsíční cenou. "
           . "Po vyčerpání limitu se další kódy řeší formou poptávky nebo doplatku. "
           . "Předplatné má datum expirace — po expiraci je nutné platbu obnovit, jinak hrozí omezení účtu. "
           . "ZMĚNA TARIFU/BALÍČKU: Autoškola si balíček mění SAMA přímo v admin menu pod položkou 'Paušální balíček'. "
           . "Tam vidí všechny dostupné balíčky s cenami a limity, vybere požadovaný a potvrdí. "
           . "Správce platformy změnu schválí a autoškole přijde nová proforma faktura. "
           . "NIKDY neposílej autoškolu na e-mail podpory kvůli změně balíčku — udělá to sama přes menu 'Paušální balíček'.\n\n";
    $text .= "NAVIGACE V ADMIN ROZHRANÍ:\n"
           . "Odhlášení — ikonka šipky [→] v záhlaví stránky vedle názvu autoškoly.\n"
           . "Přepínání světlý/tmavý režim — ikonka slunce/měsíce v záhlaví vpravo.\n"
           . "Levé menu (WP admin) obsahuje: Přehled, Firmy a kódy, Paušální balíček, Nastavení, Platby.\n"
           . "Paušální balíček — změna tarifu/balíčku, výběr z dostupných plánů.\n"
           . "Firmy a kódy — přehled všech firem napojených na autoškolu, jejich kódy a stav školení.\n"
           . "Nastavení — profil autoškoly, kontaktní údaje, cena za kód.\n\n";
    $text .= "ŽÁDOSTI O ZMĚNU ÚDAJŮ: Pokud chce autoškola změnit registrační údaje (fakturační, kontaktní), "
           . "podá žádost, kterou schvaluje hlavní administrátor platformy.\n\n";
    $text .= "FAKTURACE: Platby a faktury za předplatné se evidují v sekci plateb autoškoly, "
           . "každá platba má stav (např. 'ceka', 'zaplaceno').\n\n";
    $text .= "ROZESLÁNÍ KÓDŮ ZAMĚSTNANCŮM (funkce pro firmy — info pro autoškolu):\n"
           . "Firmy mají možnost rozeslat přístupové kódy svým zaměstnancům přímo z platformy. "
           . "Autoškola tuto funkci přímo neovládá, ale je důležité ji znát:\n"
           . "- Firma zadá jména a e-maily zaměstnanců (ručně nebo CSV importem) a systém odešle každému e-mail s kódem a odkazem.\n"
           . "- Kód je uzamčen na jméno přiřazeného zaměstnance — jiný zaměstnanec se pod ním přihlásit nemůže.\n"
           . "- Firma může kód přeřadit jinému zaměstnanci (pokud ještě nezačal) — původní dostane informační e-mail.\n"
           . "- V přehledu absolventů autoškoly se zobrazují všichni proškolení řidiči bez ohledu na to, zda kód byl rozeslán systémem nebo předán ručně.\n"
           . "- Pokud se firma ptá na problémy s rozesláním (nedoručený e-mail, přeřazení), navrhni jim použít tlačítko 'Znovu odeslat' nebo 'Přeřadit' v jejich firemním portálu.\n"
           . "- Funkci rozeslání může hlavní admin platformy deaktivovat — pak firmám tlačítko vůbec nevidí.\n";

    return $text;
}
