<?php
/**
 * Plugin Name: refdrive.pro - AI Sledování legislativy
 * Plugin URI:  https://refdrive.pro/
 * Description: Samostatný, nezávislý plugin pro sledování změn zákona č. 361/2000 Sb.
 *              Nezasahuje do hlavního RefDrive pluginu žádným způsobem — čte a zapisuje
 *              do existujících tabulek (rd_faq, rd_lekce, rd_otazky, rd_novinky) jen
 *              pokud je hlavní plugin aktivní, a do stránky /lektor/ se vkládá neinvazivně
 *              přes output buffering (bez úpravy souboru hlavního pluginu). Lze kdykoliv
 *              deaktivovat/odstranit bez vlivu na zbytek platformy — vlastní DB tabulky
 *              (rd_leg_zneni, rd_leg_navrhy) jsou oddělené a hlavní plugin o nich neví.
 * Version:     2.2.1
 * Author:      refdrive.pro
 * Author URI:  https://refdrive.pro/
 */

if (!defined('ABSPATH')) exit;

define('RDLEG_VERSION', '2.2.1');
define('RDLEG_ZAKON_KOD', '361/2000');
define('RDLEG_DAVKA_VELIKOST', 1);
// Práh délky (znaků, bez HTML) — nad tímto prahem je lekce "dlouhá" a AI dostane za úkol
// NEVRACET kompletní přepsaný text (riziko odříznutí odpovědi i při RDLEG_DAVKA_VELIKOST=1,
// protože i jediná dlouhá lekce sama o sobě může vyčerpat max_tokens), jen stručné doporučení
// k ruční úpravě — viz rdleg_sestavit_kontext_platformy() a RDLEG_DOPORUCENI_PREFIX.
define('RDLEG_LEKCE_DLOUHA_PRAH', 3500);
// Přesný řetězec, kterým AI musí ZAČÍT navrh_upravy, pokud místo kompletního přepsaného
// textu lekce vrací jen doporučení k ruční úpravě (viz instrukce v kontextu platformy).
// Render i zpracování návrhu v rdleg_vykreslit_sekci_navrhu() podle tohoto prefixu rozlišuje
// "doporučení" režim od běžného "kompletní náhradní text" režimu.
define('RDLEG_DOPORUCENI_PREFIX', 'DOPORUČENÍ PRO RUČNÍ ÚPRAVU:');

// Dvě nezávislé volby pro admina:
// - rdleg_aktivni: vypíná zpracování úplně (žádné nahrávání DOCX, žádné AI volání).
//   Stará data (historie, čekající návrhy) zůstávají viditelná, jen needitovatelná.
// - rdleg_lektor_viditelnost: skrývá banner/sekci lektorovi, ale admin může dál
//   nahrávat a generovat návrhy (např. připravit změny předem, než je lektorovi ukáže).

/* ============================================================
   AKTIVACE / DEAKTIVACE — vlastní tabulky, nezávislé na hlavním pluginu
   ============================================================ */
register_activation_hook(__FILE__, 'rdleg_aktivace');
function rdleg_aktivace() {
    add_option('rdleg_aktivni', 1);
    add_option('rdleg_lektor_viditelnost', 1);

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rd_leg_zneni (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        zakon_kod varchar(50) NOT NULL,
        nazev_souboru varchar(255) NOT NULL,
        plny_text longtext NOT NULL,
        paragrafy_json longtext NOT NULL,
        je_aktivni_reference tinyint(1) NOT NULL DEFAULT 1,
        nahrano datetime DEFAULT CURRENT_TIMESTAMP,
        nahral_uzivatel varchar(255) DEFAULT '',
        zmeny_json longtext,
        davek_celkem int NOT NULL DEFAULT 0,
        davek_hotovo int NOT NULL DEFAULT 0,
        zpracovani_hotovo tinyint(1) NOT NULL DEFAULT 1,
        rezim_zpracovani varchar(20) NOT NULL DEFAULT 'novela',
        PRIMARY KEY (id)
    ) $c;");

    dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rd_leg_navrhy (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        zneni_id mediumint(9) NOT NULL,
        paragraf varchar(50) DEFAULT '',
        typ_zmeny varchar(20) NOT NULL DEFAULT 'zmena',
        typ_obsahu varchar(20) NOT NULL DEFAULT 'uprava',
        stav varchar(20) NOT NULL DEFAULT 'ceka',
        stary_text longtext,
        novy_text longtext,
        nazev_zmeny varchar(255) DEFAULT '',
        shrnuti_zmeny text,
        navrh_upravy longtext,
        cilove_misto varchar(100) DEFAULT '',
        cilovy_identifikator varchar(255) DEFAULT '',
        upraveny_text_lektorem longtext,
        poznamka_zamitnuti text,
        vytvoreno datetime DEFAULT CURRENT_TIMESTAMP,
        vyreseno datetime DEFAULT NULL,
        vyresil_kdo varchar(255) DEFAULT '',
        PRIMARY KEY (id)
    ) $c;");

    // Bezpečné rozšíření existujících tabulek hlavního pluginu o sloupec pro interní
    // citaci zákona — dbDelta jen DOPLNÍ sloupec, pokud chybí, nikdy nemaže data.
    // Pokud hlavní plugin (nebo jeho starší verze) tyto tabulky vůbec nemá, dbDelta
    // by je vytvořilo nekompletní — proto kontrolujeme existenci tabulky předem.
    foreach (['rd_faq' => 'odpoved', 'rd_lekce' => 'obsah', 'rd_novinky' => 'obsah'] as $tabulka => $sloupec) {
        $plny_nazev = $wpdb->prefix . $tabulka;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$plny_nazev}'") === $plny_nazev) {
            $sloupec_existuje = $wpdb->get_var("SHOW COLUMNS FROM `{$plny_nazev}` LIKE 'rd_leg_zdroj'");
            if (!$sloupec_existuje) {
                $wpdb->query("ALTER TABLE `{$plny_nazev}` ADD COLUMN rd_leg_zdroj varchar(255) DEFAULT ''");
            }
        }
    }
}

/* ============================================================
   NEINVAZIVNÍ VLOŽENÍ DO STRÁNKY /lektor/ — output buffering
   ============================================================
/* ============================================================
   FILTR: povolit atribut style na <div> pro wp_kses_post()
   ============================================================
   Modré/červené informační boxy (rd-info-chip) na webu i v lektorské
   editaci spoléhají VÝHRADNĚ na inline style (samotná CSS třída
   rd-info-chip nemá v šabloně žádný vlastní vzhled — barvy/padding/border
   jsou jen v atributu style). Defaultní wp_kses_post() atribut style na
   div nepovoluje a tiše ho odstraňuje — bez tohoto filtru by se po
   schválení návrhu lektorem (přes wp_kses_post()) veškerý vizuální vzhled
   chipů ztratil, zůstal by jen holý <div> bez barvy a paddingu.
   ============================================================ */
add_filter('wp_kses_allowed_html', function($tags, $context) {
    if ($context === 'post' && isset($tags['div'])) {
        $tags['div']['style'] = true;
        $tags['div']['class'] = true;
    }
    return $tags;
}, 10, 2);

/* ============================================================
   Místo úpravy hlavního pluginu zachytíme celý výstup stránky /lektor/
   a vložíme
   banner/sekci s návrhy přesně za div.rd-lektor-tabs. Pokud hlavní plugin v budoucnu
   změní strukturu té stránky tak, že se hledaný fragment nenajde, vkládání se prostě
   neprovede (žádná chyba, žádný dopad na zbytek stránky) — bezpečný fallback.
   ============================================================ */
add_action('template_redirect', function() {
    if (!rdleg_je_lektor_stranka()) return;
    if (!rdleg_hlavni_plugin_pripraven()) return;

    // Zpracovat POST akce (schválit/zamítnout/odmítnout) DŘÍV, než se stránka vykreslí —
    // hlavní plugin (zejména starší verze 9.0.x) o rd_action=leg_* neví, takže to musí
    // zachytit tento modul samostatně.
    rdleg_zpracovat_post_akci();

    if ((int) get_option('rdleg_aktivni', 1) !== 1) return; // funkce úplně vypnutá adminem
    if ((int) get_option('rdleg_lektor_viditelnost', 1) !== 1) return; // skrytá jen lektorovi

    ob_start('rdleg_vlozit_do_vystupu');
});

/* ============================================================
   AUTOMATICKÁ KONTROLA PO RESETU OBSAHU: hlavní plugin (refdrive-plugin.php) spouští
   do_action('rd_obsah_resetovan', $co, $kdo) hned po DELETE v reset_lekce/reset_otazky/
   reset_novinky (lektorský i admin-side reset), ještě před vlastním wp_redirect() —
   tedy ve stejném requestu, ne až při příštím načtení stránky. Reagujeme nepodmíněně
   (bez závislosti na kontextu template_redirect výše), protože tenhle hook může
   vystřelit kdykoliv v rámci POST zpracování hlavního pluginu.
   Na rozdíl od tlačítka "Provést kontrolu znovu" se tahle kontrola NEBLOKUJE čekajícími
   návrhy z jiných sekcí — reset je akce, kterou lektor/admin udělal nezávisle a admin
   nad ní nemá kontrolu, takže by neměla tiše propadnout jen proto, že zrovna něco jiného
   čeká na vyřízení. Místo blokace se uloží informační záznam, aby admin viděl, PROČ
   k přegenerování došlo.
   Zároveň se z historie vyřízených návrhů ODSTRANÍ schválené záznamy, jejichž cílové
   místo patří do právě resetované sekce — jejich citace na webu už neexistuje (byla
   přepsána výchozím obsahem), takže by v historii zbytečně zůstávaly jako neaktuální
   "duchové". Zamítnuté záznamy se nemažou, ty se nikdy nikam nezapsaly, takže reset
   se jich netýká.
   ============================================================ */
add_action('rd_obsah_resetovan', function($co, $kdo = null) {
    if (!rdleg_hlavni_plugin_pripraven()) return;
    if ((int) get_option('rdleg_aktivni', 1) !== 1) return;

    global $wpdb;
    $popisky_sekci = ['lekce' => 'Lekce', 'otazky' => 'Testové otázky', 'novinky' => 'Novinky'];
    update_option('rdleg_posledni_reset', [
        'sekce' => $popisky_sekci[$co] ?? $co,
        'kdo_jmeno' => is_array($kdo) ? ($kdo['jmeno'] ?? 'Neznámý') : 'Neznámý',
        'kdo_typ' => is_array($kdo) ? ($kdo['typ'] ?? '') : '',
        'kdy' => current_time('mysql'),
    ]);

    // Mapování názvu sekce hlavního pluginu na cilove_misto používané v rd_leg_navrhy.
    // 'novinky' sekce ovlivňuje OBA cíle, co do ní zapisují (novinka = vznik nové, novinka_uprava = úprava existující).
    $mapa_cilove_misto = ['lekce' => 'lekce', 'otazky' => 'kviz_otazka', 'novinky' => ['novinka', 'novinka_uprava']];
    if (isset($mapa_cilove_misto[$co])) {
        $cile_k_vymazani = (array) $mapa_cilove_misto[$co];
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav='schvaleno' AND cilove_misto IN (" . implode(',', array_fill(0, count($cile_k_vymazani), '%s')) . ")",
            $cile_k_vymazani
        ));
    }

    rdleg_provest_kontrolu_znovu('audit', $mapa_cilove_misto[$co] ?? null);
}, 10, 2);

function rdleg_zpracovat_post_akci() {
    if (empty($_SESSION['rd_lektor']) || empty($_POST['rd_action'])) return;
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'rdleg_lektor_action')) return;

    global $wpdb;
    $action = sanitize_text_field($_POST['rd_action']);
    if (!in_array($action, ['leg_schvalit', 'leg_zamitnout_trvale', 'leg_smazat_novinku'], true)) return;

    $navrh_id = intval($_POST['navrh_id'] ?? 0);
    $navrh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rd_leg_navrhy WHERE id=%d", $navrh_id));
    if (!$navrh || $navrh->stav !== 'ceka') return;

    $rdleg_jmeno_lektora = '';
    if (!empty($_SESSION['rd_lektor'])) {
        $rdleg_jmeno_lektora = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT jmeno FROM {$wpdb->prefix}rd_lektor WHERE id=%d", $_SESSION['rd_lektor']
        ));
    }

    if ($action === 'leg_smazat_novinku') {
        // Jen pro 'novinka_uprava': AI navrhovala opravu textu, ale lektor se rozhodl,
        // že celá novinka už nemá smysl (téma zaniklo) a smazal ji rovnou, místo aby
        // schvaloval opravený text. Smaže SE konkrétní novinka podle názvu — nic jiného.
        if ($navrh->cilove_misto === 'novinka_uprava' && $navrh->cilovy_identifikator) {
            $novinka_id = rdleg_najit_novinku_id($navrh->cilovy_identifikator);
            if ($novinka_id) {
                $wpdb->delete("{$wpdb->prefix}rd_novinky", ['id' => $novinka_id]);
            }
        }
        $wpdb->update("{$wpdb->prefix}rd_leg_navrhy", [
            'stav' => 'smazano',
            'vyreseno' => current_time('mysql'),
            'vyresil_kdo' => $rdleg_jmeno_lektora,
        ], ['id' => $navrh_id]);
    } elseif ($action === 'leg_zamitnout_trvale') {
        $poznamka = sanitize_textarea_field($_POST['poznamka_zamitnuti'] ?? '');
        if (trim($poznamka) === '') return; // poznámka je povinná — bez ní se odmítnutí neprovede (i kdyby JS validaci na klientu někdo obešel)
        $wpdb->update("{$wpdb->prefix}rd_leg_navrhy", [
            'stav' => 'zamitnuto_trvale',
            'poznamka_zamitnuti' => $poznamka,
            'vyreseno' => current_time('mysql'),
            'vyresil_kdo' => $rdleg_jmeno_lektora,
        ], ['id' => $navrh_id]);
    } else { // leg_schvalit
        // KRITICKÉ: pro faq/lekce/novinka musí projít wp_kses_post() (povolené HTML tagy
        // včetně rd-info-chip divů z WYSIWYG editoru zachovány) — sanitize_textarea_field()
        // by veškeré HTML formátování odstranilo a zničilo by to, co lektor v editoru vidí
        // a schvaluje.
        $je_html_cil = in_array($navrh->cilove_misto, ['faq', 'lekce', 'novinka', 'novinka_uprava'], true);
        if ($navrh->cilove_misto === 'kviz_otazka') {
            // Testová otázka má strukturovaný editor (znění + 4 možnosti + výběr správné) —
            // z těchto polí sestavíme zpět formát OTAZKA:/A:/.../SPRAVNA:, který umí
            // rdleg_aplikovat_navrh() rozparsovat a zapsat do rd_otazky.
            $sp = strtoupper(sanitize_text_field($_POST['otazka_spravna'] ?? 'A'));
            if (!in_array($sp, ['A','B','C','D'], true)) $sp = 'A';
            $finalni_text = "OTAZKA: " . sanitize_textarea_field($_POST['otazka_znenie'] ?? '') . "\n"
                . "A: " . sanitize_text_field($_POST['otazka_moznost_a'] ?? '') . "\n"
                . "B: " . sanitize_text_field($_POST['otazka_moznost_b'] ?? '') . "\n"
                . "C: " . sanitize_text_field($_POST['otazka_moznost_c'] ?? '') . "\n"
                . "D: " . sanitize_text_field($_POST['otazka_moznost_d'] ?? '') . "\n"
                . "SPRAVNA: " . $sp;
        } else {
            $finalni_text = isset($_POST['upraveny_text']) && trim($_POST['upraveny_text']) !== ''
                ? ($je_html_cil ? wp_kses_post($_POST['upraveny_text']) : sanitize_textarea_field($_POST['upraveny_text']))
                : $navrh->navrh_upravy;
        }
        rdleg_aplikovat_navrh($navrh, $finalni_text);
        $wpdb->update("{$wpdb->prefix}rd_leg_navrhy", [
            'stav' => 'schvaleno',
            'upraveny_text_lektorem' => $finalni_text,
            'vyreseno' => current_time('mysql'),
            'vyresil_kdo' => $rdleg_jmeno_lektora,
        ], ['id' => $navrh_id]);
        if ($navrh->cilove_misto === 'novinka') {
            wp_redirect(home_url('/lektor/?tab=rdleg_zmeny&rdleg_novinka_ulozena=1'));
            exit;
        }
    }

    wp_redirect(home_url('/lektor/?tab=rdleg_zmeny'));
    exit;
}

function rdleg_je_lektor_stranka() {
    $cesta = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    return $cesta === 'lektor';
}

// Ověří, že hlavní plugin skutečně běží a má potřebné tabulky — pokud ne, modul
// se nikam nevkládá a jen tiše nic nedělá (žádná fatální chyba na webu).
function rdleg_hlavni_plugin_pripraven() {
    global $wpdb;
    $faq_tabulka = $wpdb->prefix . 'rd_faq';
    return $wpdb->get_var("SHOW TABLES LIKE '{$faq_tabulka}'") === $faq_tabulka;
}

function rdleg_vlozit_do_vystupu($html) {
    if (empty($_SESSION['rd_lektor'])) return $html; // nepřihlášený lektor — nic nevkládat

    $marker = '<div class="rd-lektor-tabs">';
    $pos = strpos($html, $marker);
    if ($pos === false) return $html; // fallback: hledaná struktura nenalezena, nic neděláme

    $konec_div = strpos($html, '</div>', $pos);
    if ($konec_div === false) return $html;
    $vlozit_za = $konec_div + strlen('</div>');

    $banner = rdleg_vykreslit_banner();
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
    $sekce = ($tab === 'rdleg_zmeny') ? rdleg_vykreslit_sekci_navrhu() : '';

    return substr($html, 0, $vlozit_za) . $banner . $sekce . substr($html, $vlozit_za);
}

/* ============================================================
   ZPRACOVÁNÍ DOCX A MECHANICKÝ DIFF (beze změny oproti hlavnímu pluginu, jen rdleg_ prefix)
   ============================================================ */

function rdleg_extrahovat_docx($cesta_k_souboru) {
    if (!class_exists('ZipArchive')) return new WP_Error('rdleg_zip', 'Na serveru chybí PHP rozšíření ZipArchive, nutné pro čtení DOCX.');
    $zip = new ZipArchive();
    if ($zip->open($cesta_k_souboru) !== true) return new WP_Error('rdleg_open', 'Nepodařilo se otevřít DOCX soubor (poškozený nebo to není platný .docx).');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return new WP_Error('rdleg_xml', 'V DOCX souboru chybí word/document.xml — nejde o standardní Word dokument.');

    $text = preg_replace('/<\/w:p>/', "\n", $xml);
    $text = preg_replace('/<[^>]+>/', '', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function rdleg_rozdelit_na_paragrafy($plny_text) {
    $bloky = preg_split('/(?=^§\s?\d+[a-z]?\b)/mu', $plny_text);
    $vysledek = [];
    foreach ($bloky as $blok) {
        $blok = trim($blok);
        if ($blok === '') continue;
        if (preg_match('/^§\s?(\d+[a-z]?)\b/u', $blok, $m)) {
            $cislo = '§' . $m[1];
            $vysledek[$cislo] = mb_substr($blok, 0, 20000);
        }
    }
    return $vysledek;
}

function rdleg_diff_paragrafy($stare_paragrafy, $nove_paragrafy) {
    $zmeny = [];
    foreach ($nove_paragrafy as $cislo => $novy_text) {
        if (!isset($stare_paragrafy[$cislo])) {
            $zmeny[] = ['paragraf' => $cislo, 'typ' => 'novy', 'stary_text' => '', 'novy_text' => $novy_text];
        } elseif (trim($stare_paragrafy[$cislo]) !== trim($novy_text)) {
            $zmeny[] = ['paragraf' => $cislo, 'typ' => 'zmena', 'stary_text' => $stare_paragrafy[$cislo], 'novy_text' => $novy_text];
        }
    }
    foreach ($stare_paragrafy as $cislo => $stary_text) {
        if (!isset($nove_paragrafy[$cislo])) {
            $zmeny[] = ['paragraf' => $cislo, 'typ' => 'smazany', 'stary_text' => $stary_text, 'novy_text' => ''];
        }
    }
    return $zmeny;
}

/* ============================================================
   KONTEXT PLATFORMY PRO AI — čte z tabulek hlavního pluginu, ale nezávisle na jeho verzi
   ============================================================ */

// Pomocná funkce: HTML z WYSIWYG editoru -> čitelný text s odřádkováním (bez tagů).
function rdleg_ocistit_html($html) {
    if ($html === null || $html === '') return $html;
    $text = preg_replace('/<\/(p|div|li|h[1-6])>/i', "\n", $html);
    if ($text === null) $text = $html; // preg_replace selhal (např. neplatné UTF-8) — pokračovat s původním textem
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    if ($text === null) return wp_strip_all_tags((string) $html);
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    if ($text === null) return '';
    return trim($text);
}

function rdleg_sestavit_kontext_platformy() {
    global $wpdb;
    $kontext = '';

    $faq = $wpdb->get_results("SELECT otazka, odpoved FROM {$wpdb->prefix}rd_faq ORDER BY poradi ASC");
    if ($faq) {
        $kontext .= "AKTUÁLNÍ FAQ (otázka — odpověď):\n";
        foreach ($faq as $f) $kontext .= "- {$f->otazka} — {$f->odpoved}\n";
        $kontext .= "\n";
    }

    $lekce = $wpdb->get_results("SELECT id, nazev, obsah FROM {$wpdb->prefix}rd_lekce ORDER BY poradi ASC");
    $dlouhe_lekce_cisla = [];
    if ($lekce) {
        $kontext .= "AKTUÁLNÍ LEKCE KURZU (číslo lekce — STEJNÉ, jaké vidí lektor v editoru i student v kurzu, pořadí 1, 2, 3...; název; PLNÝ OBSAH VČETNĚ HTML — pro úpravu musíš znát celý text se všemi tagy (h4, ul, li, strong, rd-info-chip boxy), abys ho mohl/a beze změny opsat a jen doplnit/upravit relevantní část; existující rd-info-chip boxy v textu zachovej, pokud zůstávají platné):\n";
        $cislo_lekce = 0;
        foreach ($lekce as $l) {
            $cislo_lekce++;
            $kontext .= "--- Lekce {$cislo_lekce}: {$l->nazev} ---\n{$l->obsah}\n\n";
            if (mb_strlen(wp_strip_all_tags($l->obsah)) > RDLEG_LEKCE_DLOUHA_PRAH) {
                $dlouhe_lekce_cisla[] = $cislo_lekce;
            }
        }
        // OMEZENÍ PRO DLOUHÉ LEKCE: požadavek "opiš celou lekci beze změny" dělá odpověď
        // objemnou úměrně délce lekce — i s RDLEG_DAVKA_VELIKOST=1 může jediná dlouhá lekce
        // sama o sobě narazit na limit délky odpovědi (stop_reason=max_tokens). Pro takové
        // lekce proto AI dostane jiný úkol: místo kompletního přepsaného textu jen stručné
        // doporučení, které lektor v editoru ručně zapracuje do (nedotčeného) aktuálního textu.
        if ($dlouhe_lekce_cisla) {
            $kontext .= "DŮLEŽITÉ OMEZENÍ PRO DLOUHÉ LEKCE: lekce číslo " . implode(', ', $dlouhe_lekce_cisla) . " jsou DLOUHÉ "
                . "(obsah přes " . RDLEG_LEKCE_DLOUHA_PRAH . " znaků). Pokud navrhuješ úpravu cílenou na 'lekce' s cilovy_identifikator "
                . "rovným jednomu z těchto čísel, NEVRACEJ do navrh_upravy kompletní přepsaný text celé lekce — hrozí odříznutí odpovědi. "
                . "Místo toho do navrh_upravy napiš STRUČNÉ doporučení pro lektora (2-4 věty, česky), kde v lekci (která část/odstavec) "
                . "a co konkrétně je třeba změnit nebo doplnit, s odkazem na konkrétní hodnotu/lhůtu/pravidlo z posuzovaného paragrafu — "
                . "a tento text ZAČNI přesně tímto řetězcem (bez uvozovek): " . RDLEG_DOPORUCENI_PREFIX . " "
                . "Lektor pak uvidí tvé doporučení i aktuální text lekce vedle sebe a úpravu provede ručně. "
                . "Pro lekce, které v tomto seznamu NEJSOU, postupuj jako obvykle — kompletní přepsaný text celé lekce.\n\n";
        }
    }

    // POJISTKA: čteme přes rd_novinky_source() z hlavního pluginu (pokud je k dispozici),
    // ne přímým dotazem do tabulky — ta funkce má vlastní fallback na hardcoded novinky,
    // pokud je tabulka prázdná (přesně to, co se ukázalo u rd_lekce — viz diagnostika
    // tabulek v adminu). Přímý dotaz do tabulky by v tom případě AI ukázal PRÁZDNÝ seznam,
    // přestože web reálně 5 novinek zobrazuje — celá kontrola duplicit by byla slepá.
    if (function_exists('rd_novinky_source')) {
        $novinky = array_map(function($n) { return (object) $n; }, rd_novinky_source());
    } else {
        $novinky = $wpdb->get_results("SELECT nazev, obsah FROM {$wpdb->prefix}rd_novinky ORDER BY poradi DESC");
    }
    if ($novinky) {
        $kontext .= "AKTUÁLNÍ NOVINKY NA WEBU (název, PLNÝ TEXT — UŽ ZVEŘEJNĚNÉ; použij to ke DVĚMA NEZÁVISLÝM věcem: (1) zjistit, jestli "
            . "téma posuzovaného paragrafu už je mezi nimi pokryté, pro rozhodnutí o cíli 'novinka' níže, a (2) zjistit, jestli KONKRÉTNÍ "
            . "existující novinka už neobsahuje nesprávnou/zastaralou informaci kvůli této legislativní změně, pro rozhodnutí o cíli "
            . "'novinka_uprava' níže):\n";
        foreach ($novinky as $nv) {
            $kontext .= "--- Novinka: {$nv->nazev} ---\n" . wp_strip_all_tags($nv->obsah) . "\n\n";
        }
        $kontext .= "DŮLEŽITÉ — DUPLICITA NOVINEK JE SAMOSTATNÉ ROZHODNUTÍ, NEZÁVISLÉ NA LEKCI/FAQ: pokud téma posuzovaného paragrafu odpovídá "
            . "novince, která je v seznamu výše JIŽ ZVEŘEJNĚNA (i pod jiným názvem nebo formulací — posuzuj věcný obsah, ne shodu textu), "
            . "NENAVRHUJ pro něj další 'novinka' cíl. Tohle ALE platí VÝHRADNĚ pro cíl 'novinka' — v ŽÁDNÉM případě to neznamená, že je "
            . "paragraf 'vyřízený' jako celek. Lekci a FAQ posuzuj NEZÁVISLE, podle úplně jiného kritéria: obsahuje KONKRÉTNÍ lekce/FAQ "
            . "výše věcně správnou informaci o tomto pravidle, nebo ji NEOBSAHUJE VŮBEC? Existence novinky na tom nic nemění — novinka a "
            . "obsah kurzu jsou dvě oddělená místa webu, mohou se rozejít (typicky novinka vznikne dřív, než se stihne dotáhnout kurz). "
            . "DŮLEŽITÉ — POSUZUJ JEN AKTUÁLNÍ SEZNAM VÝŠE, NIC JINÉHO: o tom, jestli novinka k danému paragrafu existuje, rozhoduje "
            . "VÝHRADNĚ seznam 'AKTUÁLNÍ NOVINKY NA WEBU' výše, tak jak je uveden TEĎ — ne to, co sis o tomto paragrafu mohl/a domyslet "
            . "z dřívějšího posuzování nebo z obecné znalosti. Pokud paragraf v seznamu výše NENÍ pokrytý žádnou novinkou (ani byl-li dřív, "
            . "ale teď tam není — typicky byl smazán), je to chybějící novinka a MÁ se navrhnout, stejně jako kterýkoli jiný nový případ. "
            . "PŘÍKLAD (obecný, nevztahuje se na žádný konkrétní paragraf v této dávce): pravidlo X už může být v seznamu výše oznámeno "
            . "jako novinka, ale lekce o pravidle X nikde nemluví — pak ÚPRAVA TÉ LEKCE JE STÁLE POTŘEBA, vynechá se jen ta duplicitní "
            . "druhá novinka, ne úprava lekce.\n\n";
    }

    $otazky = $wpdb->get_results("SELECT id, otazka, moznost_a, moznost_b, moznost_c, moznost_d, spravna FROM {$wpdb->prefix}rd_otazky ORDER BY poradi ASC");
    if ($otazky) {
        $kontext .= "AKTUÁLNÍ TESTOVÉ OTÁZKY (znění, čtyři možnosti A–D, a která je správná):\n";
        foreach ($otazky as $o) {
            $kontext .= "- {$o->otazka}\n";
            $kontext .= "    A) {$o->moznost_a}\n    B) {$o->moznost_b}\n    C) {$o->moznost_c}\n    D) {$o->moznost_d}\n";
            $kontext .= "    Správná odpověď: {$o->spravna}\n";
        }
        $kontext .= "\n";
    }

    return $kontext;
}

/* ============================================================
   OPRAVA NEESCAPOVANÝCH ŘÍDICÍCH ZNAKŮ V JSON TEXTU
   ============================================================
   Při generování dlouhého textu (typicky opisování celé lekce do navrh_upravy,
   navíc zabaleného do zdvojeného JSON — viz rdleg_ai_navrhnout_zmeny() níže) model
   občas v JSON stringu nechá doslovný (neescapovaný) řádkový zlom/tabulátor místo
   správného \n/\t. JSON to striktně zakazuje — json_decode() pak celý dokument
   zahodí jako neplatný, i když je jinak v pořádku (viděno opakovaně u §87/lekce #234).
   Tahle funkce projde text bajt po bajtu, hlídá si, jestli je "uvnitř" JSON stringu
   (přes uvozovky a escapování), a pokud narazí na neescapovaný řídicí znak UVNITŘ
   stringu, nahradí ho platnou escape sekvencí. Mimo stringy (strukturální mezery
   mezi tokeny) text nechá beze změny — tam je literal newline v JSON beztak povolený.
   Bezpečné i pro UTF-8: vícebajtové znaky mají všechny bajty >= 0x80, takže se nikdy
   nespletou s ASCII uvozovkou/zpětným lomítkem/řídicím znakem, které tu hlídáme —
   bajt-po-bajtu průchod multibyte sekvenci nerozbije.
   ============================================================ */
function rdleg_opravit_ovladaci_znaky_v_json($text) {
    $vystup = '';
    $delka = strlen($text);
    $v_retezci = false;
    $escape = false;
    for ($i = 0; $i < $delka; $i++) {
        $c = $text[$i];
        if ($v_retezci) {
            if ($escape) {
                $vystup .= $c;
                $escape = false;
                continue;
            }
            if ($c === '\\') {
                $vystup .= $c;
                $escape = true;
                continue;
            }
            if ($c === '"') {
                $vystup .= $c;
                $v_retezci = false;
                continue;
            }
            if ($c === "\n") { $vystup .= '\\n'; continue; }
            if ($c === "\r") { $vystup .= '\\r'; continue; }
            if ($c === "\t") { $vystup .= '\\t'; continue; }
            $vystup .= $c;
        } else {
            if ($c === '"') $v_retezci = true;
            $vystup .= $c;
        }
    }
    return $vystup;
}

/* Najde v textu první ucelenou (vyváženou) JSON hodnotu — pole [...] nebo objekt {...} —
   a vrátí jen tu, bez čehokoli okolo (preambule před, poznámka za, např. "— použijte
   tlačítko níže manuálně.", co se občas objeví PO platně uzavřené struktuře). Respektuje,
   co je uvnitř JSON stringů (uvozovky/escapování), takže závorky v textových hodnotách
   nemate počítání hloubky. Vrací null, pokud žádnou vyváženou strukturu nenajde. */
function rdleg_extrahovat_json_hodnotu($text) {
    $delka = strlen($text);
    $start = null;
    for ($i = 0; $i < $delka; $i++) {
        if ($text[$i] === '[' || $text[$i] === '{') { $start = $i; break; }
    }
    if ($start === null) return null;

    $hloubka = 0;
    $v_retezci = false;
    $escape = false;
    for ($i = $start; $i < $delka; $i++) {
        $c = $text[$i];
        if ($v_retezci) {
            if ($escape) { $escape = false; continue; }
            if ($c === '\\') { $escape = true; continue; }
            if ($c === '"') { $v_retezci = false; }
            continue;
        }
        if ($c === '"') { $v_retezci = true; continue; }
        if ($c === '[' || $c === '{') { $hloubka++; continue; }
        if ($c === ']' || $c === '}') {
            $hloubka--;
            if ($hloubka === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }
    return null; // nenašla se vyvážená dvojice — nekompletní struktura (např. odříznutá)
}

/* Zkusí json_decode() normálně; pokud selže, zkusí to znovu po opravě neescapovaných
   řídicích znaků výše; pokud selže i to, zkusí ještě vyextrahovat jen vyváženou JSON
   hodnotu (ignoruje cokoli mimo ni — preambuli, přilepenou poznámku za). Vrací
   rozparsovanou hodnotu, nebo null, pokud selže úplně všechno. */
function rdleg_json_decode_s_opravou($text) {
    $vysledek = json_decode($text, true);
    if ($vysledek !== null || trim((string) $text) === 'null') return $vysledek;

    $opraveny = rdleg_opravit_ovladaci_znaky_v_json($text);
    $vysledek = json_decode($opraveny, true);
    if ($vysledek !== null) return $vysledek;

    $extrahovany = rdleg_extrahovat_json_hodnotu($opraveny);
    if ($extrahovany !== null) {
        return json_decode($extrahovany, true);
    }
    return null;
}

/* ============================================================
   AI PROMPT — verze odpovídající opravám z hlavního pluginu 9.4.0–9.4.3
   ============================================================ */
function rdleg_ai_navrhnout_zmeny($davka_zmen, $kontext_platformy, $rezim = 'novela') {
    $api_key = get_option('rd_ai_api_key', '');
    if (!$api_key) return new WP_Error('rdleg_apikey', 'Chybí Anthropic API klíč. Zadejte ho v nastavení AI asistentů.');

    $zmeny_text = '';
    foreach ($davka_zmen as $z) {
        $zmeny_text .= "--- Paragraf {$z['paragraf']} (typ: {$z['typ']}) ---\n";
        if ($z['typ'] !== 'novy') $zmeny_text .= "PŮVODNÍ ZNĚNÍ:\n{$z['stary_text']}\n\n";
        if ($z['typ'] !== 'smazany') $zmeny_text .= "NOVÉ ZNĚNÍ:\n{$z['novy_text']}\n\n";
    }

    $dnesni_datum = date_i18n('j. n. Y');
    $prompt = "Jsi pečlivý asistent pro sledování legislativních změn pro platformu RefDrive.pro (online školení referentských řidičů firem). "
        . "ZÁSADNÍ PRAVIDLO, KTERÉ PLATÍ PRO CELÝ TENTO ÚKOL: tvým JEDINÝM zdrojem pravdy o znění zákona je text paragrafů uvedený "
        . "níže v této zprávě — NIC JINÉHO. Nepoužívej svoje obecné znalosti o českém zákoně o silničním provozu, o jeho historii, "
        . "nebo o tom, co si \"pamatuješ\" z tréninkových dat — tyto znalosti mohou být neúplné nebo zastaralé a v tomto úkolu se "
        . "NEPOUŽÍVAJÍ jako zdroj faktů o zákoně. Pokud si nejsi jistý, co konkrétní paragraf níže říká, opři se VÝHRADNĚ o jeho "
        . "doslovné znění uvedené v textu — nedoplňuj si chybějící kontext z paměti. Pokud si nejsi jistý relevancí, raději změnu "
        . "nenavrhuj, než abys spoléhal na nejistou obecnou znalost.\n\n"
        . "DNEŠNÍ DATUM JE: {$dnesni_datum}. Tohle slouží JEN k odlišení pravidel, která jsou věcně dávno zavedená a obecně známá "
        . "(např. starý limit nebo povinnost platná už řadu let), od skutečně nových — NENÍ to striktní okénko v týdnech/měsících. "
        . "I pravidlo, jehož datum účinnosti je už několik měsíců v minulosti, je STÁLE plnohodnotná 'novinka', pokud věcně jde "
        . "o nedávno zavedenou (ne dávno usazenou) změnu — rozhodující signál, jestli už byla uživatelům oznámena, je seznam "
        . "AKTUÁLNÍCH NOVINEK NA WEBU v kontextu platformy níže, ne počet uplynulých týdnů/měsíců od data účinnosti.\n\n";

    if ($rezim === 'komplet') {
        $prompt .= "REŽIM: KOMPLETNÍ KONTROLA SOULADU WEBU SE ZÁKONEM. Posuzuješ jeden konkrétní paragraf aktuálně platného znění "
            . "zákona č. 361/2000 Sb. (o silničním provozu), uvedený NA ÚPLNÉM KONCI této zprávy. Tento paragraf je součástí "
            . "průběžné kontroly celého zákona (každý paragraf se posuzuje samostatně). Tvým úkolem je porovnat ho s obsahem platformy "
            . "(kontext níže). Zajímá nás VÝHRADNĚ nesoulad mezi zákonem a webem — vytvoř návrh JEN v jednom z těchto tří případů:\n"
            . "  (A) PŘIDAT: zákon obsahuje povinnost/pravidlo/limit relevantní pro řidiče referenty, které na webu ÚPLNĚ CHYBÍ a mělo by tam být. "
            . "Cíl je 'lekce' (doplň do tématicky nejbližší existující lekce) nebo 'faq' (pokud se hodí spíš jako otázka/odpověď), NIKDY 'novinka'.\n"
            . "  (B) UPRAVIT: web o daném pravidle píše, ale NESPRÁVNĚ nebo ZASTARALE oproti aktuálnímu znění zákona (špatná hodnota, lhůta, sankce, podmínka).\n"
            . "  (C) ODEBRAT/PŘEPSAT: web zmiňuje povinnost/pravidlo, které už v zákoně NENÍ (bylo zrušeno) — navrhni přepsání textu tak, aby zrušenou informaci neobsahoval.\n\n"
            . "DŮLEŽITÉ — cíl 'novinka' v TOMHLE režimu NEPOUŽÍVEJ VŮBEC, ani pro chybějící obsah podle bodu (A). 'Novinka' znamená "
            . "NEDÁVNOU legislativní změnu, ale v tomto režimu se neporovnává nic s předchozí verzí zákona — nemáš tedy žádný způsob, "
            . "jak věcně rozlišit pravidlo platné 20 let od pravidla platného měsíc. Chybějící obsah řeš výhradně doplněním do "
            . "lekce/FAQ (bod A výše), bez ohledu na to, jak důležité nebo nápadné to pravidlo je.\n\n"
            . "ZÁSADNÍ: pokud je obsah webu k danému paragrafu SPRÁVNÝ a aktuální, NEVYTVÁŘEJ žádný návrh — nechci vědět o informacích, "
            . "které už na webu správně jsou. Žádná potvrzení typu 'tato informace už na webu je a je správně'. Mlč o všem, co je v "
            . "pořádku. Očekávej, že drtivá většina paragrafů nebude vyžadovat žádný návrh — to je normální a správný výsledek.\n\n";
    } elseif ($rezim === 'audit') {
        $prompt .= "REŽIM: KOMPLETNÍ KONTROLA SOULADU WEBU SE ZÁKONEM. Posuzuješ jeden konkrétní paragraf aktuálně platného znění "
            . "zákona č. 361/2000 Sb. (o silničním provozu), uvedený NA ÚPLNÉM KONCI této zprávy — NE výsledek mechanického porovnání "
            . "dvou verzí, ale prostě aktuální text paragrafu (je součástí kontroly celého zákona, kde se každý paragraf posuzuje samostatně). "
            . "Tvým úkolem je nezávisle na jakékoli předchozí novele zkontrolovat, zda obsah platformy (kontext níže) "
            . "věcně souhlasí se zákonem — tedy zda existuje chyba, zastaralá informace, nebo nesoulad, který tam mohl vzniknout kdykoli "
            . "v minulosti (ne nutně nedávnou změnou zákona). Vytvoř návrh JEN tehdy, pokud najdeš skutečný věcný nesoulad nebo chybu — "
            . "pokud je obsah platformy k tomuto paragrafu v pořádku, nebo paragraf není pro platformu relevantní, NEVYTVÁŘEJ žádný prvek "
            . "v odpovědi (viz formát odpovědi níže). Cíl 'novinka' v TOMHLE režimu NEPOUŽÍVEJ VŮBEC — ze stejného důvodu jako u "
            . "kompletní kontroly výše: tady se neporovnává nic s předchozí verzí zákona, takže nemáš způsob, jak rozlišit starou "
            . "usazenou věc od skutečné nedávné novinky. Chybějící nebo špatný obsah řeš cílem 'lekce'/'faq'/'kviz_otazka'/'novinka_uprava', "
            . "podle toho, co se hodí. Očekávej, že drtivá většina paragrafů nebude vyžadovat žádný návrh — to je "
            . "normální a správný výsledek kompletní kontroly, ne chyba.\n\n";
    } else {
        $prompt .= "PRAVIDLO PRO 'novinka': paragraf, který je níže (na konci zprávy) označený jako typ 'novy' (v předchozí verzi zákona "
            . "tento paragraf NEEXISTOVAL) nebo 'smazany' (v předchozí verzi EXISTOVAL, v nové verzi ze zákona zmizel), JE důvodem pro "
            . "'novinka' — to, jak dávno nabyl/nabude účinnosti, ani to, jestli by to 'běžný řidič už dávno znal', na tom nic nemění. "
            . "JEDINÁ výjimka: nástroj, který tuhle mechanickou klasifikaci dělá, porovnává čistě TEXT dvou verzí dokumentu, takže "
            . "občas takhle omylem označí i triviální přečíslování paragrafu, sloučení s jiným ustanovením, nebo přesun STEJNÉHO obsahu "
            . "do jiné části zákona BEZE ZMĚNY SMYSLU — to věcně NIC nového/zrušeného nezavádí, takže to novinkou není, i když to diff "
            . "nástroj takto vyhodnotil. Mimo tuhle výjimku se mechanické klasifikaci 'novy'/'smazany' důvěřuj jako spolehlivému signálu, "
            . "že jde o věcnou změnu, kterou stojí za to oznámit.\n\n"
            . "Posuzuj relevanci podle toho, zda VĚCNÝ OBSAH pravidla chybí nebo je nesprávně uveden na platformě (viz kontext níže), "
            . "ne podle toho, že se v dokumentu změnil text/formátování paragrafu. Skutečnou novinkou pro 'novinka' cíl je věcně "
            . "nová/zrušená povinnost podle pravidla výše, která ZÁROVEŇ není už v seznamu AKTUÁLNÍCH NOVINEK NA WEBU v kontextu "
            . "platformy níže.\n\n"
            . "Paragraf, který se MECHANICKY (textovým porovnáním, bez AI) ukázal jako nový, změněný, nebo ZRUŠENÝ "
            . "(typ 'smazany' — paragraf byl v předchozí verzi zákona, ale v nové verzi už neexistuje) oproti poslední uložené verzi, "
            . "je uvedený NA ÚPLNÉM KONCI této zprávy (za větou \"POSUZOVANÝ PARAGRAF:\").\n\n";
    }

    $prompt .= "Kontext aktuálního obsahu platformy RefDrive.pro:\n\n{$kontext_platformy}\n"
        . "ÚKOL: Pro paragraf, který bude uvedený NA ÚPLNÉM KONCI této zprávy (za větou \"POSUZOVANÝ PARAGRAF:\"), posuď, zda je relevantní pro platformu RefDrive.pro (týká se BOZP, povinností řidičů/zaměstnavatelů, "
        . "limitů, lhůt, pravidel relevantních pro firemní školení řidičů). Pokud NENÍ relevantní (např. týká se jen registrace vozidel, "
        . "technických prohlídek apod. nezmiňovaných v kurzu), VYNECHEJ ho z odpovědi úplně — nevytvářej pro něj žádný návrh.\n\n"
        . "DŮLEŽITÉ UPŘESNĚNÍ CÍLOVÉ SKUPINY: kurz je určen výhradně ŘIDIČŮM REFERENTŮM — zaměstnancům firem, kteří v rámci své práce "
        . "řídí BĚŽNÁ OSOBNÍ VOZIDLA (kategorie B, do 3,5 t) na pracovních cestách, NE profesionálním řidičům z povolání. Paragrafy, "
        . "které se týkají VÝHRADNĚ profesionálních řidičů nebo vozidel, která referent neřídí — např. tachograf a doby řízení/odpočinku, "
        . "profesní způsobilost řidiče (zákon č. 247/2000 Sb.), řízení nákladních vozidel nad 3,5 t, autobusů, taxislužby, vozidel "
        . "hromadné dopravy, přepravy nebezpečných věcí (ADR) — NEJSOU relevantní pro tuto platformu a takový paragraf VYNECHEJ z "
        . "odpovědi úplně, i kdyby byl jinak v zákoně o silničním provozu mechanicky označen jako nový/změněný. Pravidla platná pro "
        . "VŠECHNY řidiče bez rozdílu (rychlostní limity, alkohol, telefon za volantem, bezpečnostní vzdálenost, přednost, bodový "
        . "systém apod.) jsou relevantní vždy, protože se vztahují i na referenty v osobním autě.\n\n"
        . "U paragrafů typu 'smazany' (zrušené ustanovení): zkontroluj, zda kontext platformy výše (FAQ, lekce, otázky) zmiňuje povinnost nebo "
        . "pravidlo, které tento paragraf zaváděl. Pokud ano, navrhni PŘEPSÁNÍ textu té části obsahu, která už neplatí (FAQ otázka i nadále existuje, "
        . "jen se mění text odpovědi tak, aby neobsahoval zrušenou informaci — NIKDY nenavrhuj smazání celé otázky, jen úpravu odpovědi) "
        . "a v shrnuti_zmeny to jasně uveď jako ZRUŠENÍ, ne jako změnu. Pokud na platformě nic takového není zmíněno, návrh nevytvářej.\n\n"
        . "Pokud JE relevantní, navrhni strukturované změny pro KAŽDÉ místo platformy, kterého se týká (může to být jedno i více míst). "
        . "POZOR NA DUPLICITU MEZI 'lekce'/'faq' A 'novinka' PRO TENTÝŽ PARAGRAF: 'novinka' cíl použij JEN pokud jde o skutečně čerstvou, "
        . "věcně novou změnu (viz instrukce o datech a dávno-zavedených-pravidlech výše) — pak je v pořádku mít zároveň úpravu lekce/FAQ "
        . "(aby obsah kurzu odpovídal) I novinku (aby si o tom uživatelé mohli přečíst jako o aktualitě). Pokud ale pravidlo NENÍ čerstvou "
        . "novinkou (je dávno zavedené, nebo lekce/FAQ už obsah správně má), NENAVRHUJ pro něj 'novinka' jen proto, že existuje úprava "
        . "lekce/FAQ — to by vytvořilo zbytečně duplicitní oznámení o něčem, co není aktuální zprávou. Jeden paragraf = maximálně JEDEN "
        . "prvek pro 'novinka' cíl, a to jen když je to oprávněné podle výše uvedených kritérií.\n\n"
        . "DŮLEŽITÉ — kontext platformy výše ukazuje AKTUÁLNÍ (živý, právě teď platný) stav, který může lektor mezitím už sám ručně upravit nezávisle na tomto procesu. "
        . "Pokud konkrétní FAQ odpověď, text lekce nebo otázka v kontextu výše JIŽ odpovídá novému znění paragrafu (např. už obsahuje správnou aktuální hodnotu, "
        . "nebo už neobsahuje zrušenou povinnost), NENAVRHUJ pro toto místo žádnou změnu — už je v pořádku.\n\n"
        . "DŮLEŽITÉ K FORMÁTU navrh_upravy U 'faq' A 'lekce': pole navrh_upravy musí vždy obsahovat KOMPLETNÍ finální text, "
        . "kterým se PŘÍMO NAHRADÍ celý současný obsah daného místa po schválení — nikdy ne jen popis úkolu, fragment k doplnění, "
        . "nebo větu typu \"Doplnit do lekce...\"/\"Upravit FAQ o...\". Pokud měníš jen část existujícího textu z kontextu výše, "
        . "OPIŠ celý zbytek původního textu BEZE ZMĚNY a jen vlož/uprav tu konkrétní část, která se týká této legislativní změny. "
        . "Lektor schválením rovnou nahradí starý text tímto polem, takže musí být použitelné okamžitě, bez dalších úprav.\n\n"
        . "FORMÁTOVÁNÍ pro 'faq' a 'lekce': pole obsah na platformě podporuje přímo HTML, konkrétně tagy h4, p, ul, li, strong, em, a — tam "
        . "je strukturovaný výklad (nadpisy, odrážky) vhodný a žádaný. 'novinka' má JINÝ, jednodušší styl — viz samostatná poznámka u cíle "
        . "'novinka' níže, nepřebírej odsud h4/ul strukturu do novinky. "
        . "ZÁKAZ MARKDOWN (platí pro VŠECHNY tři cíle): NIKDY nepoužívej markdown syntax (hvězdičky **tučně**, pomlčky jako odrážky, # pro "
        . "nadpisy apod.) — web markdown "
        . "nepřevádí na vzhled, takže by se hvězdičky zobrazily uživatelům doslova jako znaky. Zvýraznění vždy zapiš jako <strong>text</strong>, "
        . "odrážky (jen u 'faq'/'lekce') jako <ul><li>...</li></ul>, nadpis (jen u 'faq'/'lekce') jako <h4>...</h4>. I 'novinka' je HTML, "
        . "ne plain text/markdown — jen bez h4/ul, viz níže. "
        . "Navíc je k dispozici speciální zvýrazňující box: <div class=\"rd-info-chip rd-chip-blue\">text</div> pro důležitou informaci "
        . "(např. novou povinnost, lhůtu, limit) a <div class=\"rd-info-chip rd-chip-red\">text</div> pro varování (např. sankci, pokutu, "
        . "ztrátu oprávnění). Použij tyto boxy jen tam, kde to odpovídá stylu existujícího kontextu výše a kde to skutečně zvýrazňuje "
        . "klíčovou informaci plynoucí z této legislativní změny — ne u každého návrhu automaticky. "
        . "DŮLEŽITÉ — existující rd-info-chip boxy v původním textu výše: pokud current text obsahuje takové boxy a informace v nich "
        . "zůstává platná i po této legislativní změně, ZACHOVEJ je přesně (včetně HTML značek) jako součást beze-změny-opsaného textu — "
        . "neodstraňuj formátování jen proto, že daný box přímo nesouvisí s touto konkrétní změnou. Pokud naopak informace v existujícím "
        . "boxu byla touto změnou překonána, uprav obsah boxu (nebo ho zruš, pokud už nedává smysl) tak, aby odpovídal novému stavu. "
        . "Pokud existující text v kontextu výše podobné boxy už používá, zachovej konzistentní styl.\n\n"
        . "- 'novinka': POUZE pokud je posuzovaný paragraf mechanicky klasifikovaný jako typ 'novy' nebo 'smazany' (viz PRAVIDLO PRO "
        . "'novinka' výše) — NIKDY pro paragraf typu 'zmena' (= ustanovení už dříve existovalo, jen se text přeformuloval/upřesnil — "
        . "to NENÍ věcně nová ani zrušená povinnost, i kdyby šlo o změnu, která vypadá důležitě). NEJDŘÍV zkontroluj seznam AKTUÁLNÍCH NOVINEK NA WEBU výše, jestli tohle téma už není zveřejněné (viz instrukce "
        . "o duplicitě výše); pokud není, navrhni krátký nadpis (do nazev_zmeny) a text novinky. STYL TEXTU NOVINKY: krátká plynulá zpráva "
        . "(1-3 věty, max. jeden krátký odstavec) — STEJNÝ styl jako existující novinky v seznamu výše (prostá zpráva, ne strukturovaný "
        . "výklad). Do těla novinky NIKDY nevkládej <h4> nadpis (název už je v samostatném poli nazev_zmeny, v textu by se jen zbytečně "
        . "opakoval) ani <ul>/<li> seznam — jen prostý text v <p> tagu, s občasným <strong> na klíčové číslo/lhůtu/pravidlo. U zrušení "
        . "nadpis i text musí jasně vyjadřovat, že povinnost ZANIKLA (např. \"Zrušena povinnost X\"), ne že přibyla.\n"
        . "- 'novinka_uprava': pokud KONKRÉTNÍ existující novinka ze seznamu AKTUÁLNÍCH NOVINEK NA WEBU výše už obsahuje nesprávnou/zastaralou "
        . "informaci kvůli této legislativní změně (na rozdíl od 'novinka' výše, tady NEZÁLEŽÍ na typ_zmeny — i 'zmena' může způsobit, že "
        . "existující novinka je teď věcně špatně) — do cilovy_identifikator uveď PŘESNÝ název té novinky z kontextu (přesně jak je psaný "
        . "za 'Novinka:') a do navrh_upravy kompletní nový text té novinky, který PŘÍMO NAHRADÍ celý současný text po schválení (ne jen "
        . "popis co opravit). STEJNÝ STYL jako u 'novinka' výše (krátká prostá zpráva, žádný <h4> ani <ul>). "
        . "ZÁSADNÍ OMEZENÍ: použij 'novinka_uprava' JEN tehdy, když novinka obsahuje věcně NESPRÁVNOU nebo ZASTARALOU informaci — tedy informaci, "
        . "která je v rozporu s aktuálním zněním zákona. Pokud novinka téma věcně pokrývá správně (byť možná ne doslova či úplně), NEVYTVÁŘEJ "
        . "návrh — formulační nedokonalost ani neúplnost NENÍ důvod k úpravě. Nepoužívej tento cíl, pokud "
        . "žádná existující novinka věcně nesouvisí s touto změnou — to není důvod novinku vytvářet ani upravovat.\n"
        . "- 'faq': pokud existující FAQ otázka výše obsahuje teď nesprávnou/zastaralou informaci (včetně zmínky zrušené povinnosti) — uveď PŘESNÉ znění existující otázky z kontextu a do navrh_upravy kompletní novou odpověď (viz instrukce o formátu výše).\n"
        . "- 'lekce': DVA případy. (1) Existující lekce výše už látku, kterou tato změna ovlivňuje, vysvětluje, ale teď neúplně/nesprávně/zastarale "
        . "— uprav tu konkrétní část. (2) Žádná lekce zatím tohle konkrétní pravidlo nezmiňuje, ale jedna z nich je TÉMATICKY nejbližší (např. "
        . "pravidlo o autonomním vozidle patří do lekce o moderních technologiích/asistenčních systémech, ne do lekce o alkoholu) — v tom případě "
        . "DOPLŇ do té nejbližší lekce novou část (odstavec, případně s <h4> podnadpisem, ve stylu zbytku lekce), místo abys 'lekce' cíl vynechal/a "
        . "jen proto, že žádná lekce o tom DOSUD nemluví. V obou případech: do cilovy_identifikator uveď JEN ČÍSLO té lekce z kontextu (např. \"5\" "
        . "ne \"Lekce 5\" ani název) a do navrh_upravy KOMPLETNÍ nový obsah CELÉ lekce (existující text beze změny + nová/upravená část), viz "
        . "instrukce o formátu výše. Nepoužívej 'lekce' cíl, pokud je téma změny natolik mimo záběr kurzu (řidiči referenti, osobní vozidla), že by "
        . "doplnění do JAKÉKOLI ze stávajících lekcí působilo vsazeně/nepatřičně — to se ale stává jen výjimečně, většina věcně relevantních "
        . "pravidel se dá přiřadit k některé z 10 lekcí kurzu.\n"
        . "ZÁSADNÍ OMEZENÍ (case 1, stejně jako u 'novinka_uprava'): pokud existující text lekce už danou věc vysvětluje VĚCNĚ SPRÁVNĚ — i "
        . "jinou formulací, kratším nebo delším textem, jiným úhlem pohledu — NEVYTVÁŘEJ návrh jen kvůli odlišné formulaci. Návrh patří jen tehdy, "
        . "když text obsahuje skutečně nesprávnou hodnotu/lhůtu/podmínku, nebo když danou věc neobsahuje vůbec.\n"
        . "POVINNOST NAVRHNOUT VŠECHNY POSTIŽENÉ LEKCE: pokud při psaní shrnuti_zmeny zmíníš, že problém se týká VÍCE než jedné lekce (např. "
        . "\"lekce 4 i lekce 10 obsahují tuto chybu\"), MUSÍŠ vytvořit SAMOSTATNÝ návrh s cílem 'lekce' pro KAŽDOU z těch lekcí zvlášť — nesmíš "
        . "zmínit více lekcí ve shrnutí, ale vytvořit návrh jen pro jednu z nich. Pokud nechceš navrhovat víc než jednu lekci, nezmiňuj ve shrnutí "
        . "ostatní lekce jako postižené.\n"
        . "- 'kviz_otazka': pokud existující testová otázka výše má teď nesprávnou správnou odpověď nebo zastaralé znění — do cilovy_identifikator uveď PŘESNÉ znění té otázky z kontextu (celý text otázky, ne číslo) a do navrh_upravy uveď KOMPLETNÍ nové znění otázky v PŘESNĚ tomto formátu (každá část na samostatném řádku, dodrž přesně tyto popisky):\n"
        . "OTAZKA: <znění otázky>\n"
        . "A: <text možnosti A>\n"
        . "B: <text možnosti B>\n"
        . "C: <text možnosti C>\n"
        . "D: <text možnosti D>\n"
        . "SPRAVNA: <jedno písmeno A, B, C nebo D>\n"
        . "Urči správnou odpověď podle aktuálního znění zákona co nejlépe umíš — lektor ji před schválením v editoru zkontroluje a může opravit. Vždy vyplň všechny čtyři možnosti i správnou odpověď; pokud měníš jen některou možnost nebo jen správnou odpověď, ostatní OPIŠ beze změny z aktuálního znění otázky v kontextu výše. "
        . "ZÁKAZ: u 'kviz_otazka' NIKDY nevracej do navrh_upravy volný slovní popis typu \"Ověřte, zda...\" nebo \"Je třeba zkontrolovat...\". I když si nejsi jistý správnou odpovědí, MUSÍŠ vrátit kompletní formát OTAZKA:/A:/B:/C:/D:/SPRAVNA: s nejlepším odhadem správné možnosti — lektor ho opraví. Slovní popis místo struktury je chyba, kterou editor neumí zpracovat.\n\n"
        . "FINÁLNÍ KONTROLA PŘED ODPOVĚDÍ — PROTI HALUCINACI: než zavoláš nástroj, ověř si u KAŽDÉHO prvku, který chceš zahrnout, že "
        . "každé tvrzení v shrnuti_zmeny a navrh_upravy má přímou opěru v doslovném textu paragrafu uvedeném výše v této zprávě, NE "
        . "v tom, co si o zákoně pamatuješ obecně. Pokud si všimneš, že nějaké tvrzení nebo detail (číslo, lhůta, sankce, podmínka) "
        . "pochází z tvé obecné znalosti a ne přímo z textu paragrafu, který máš k dispozici — tento detail buď z odpovědi vynech, "
        . "nebo formuluj návrh tak, aby se na něj nespoléhal. Lektor schválením návrh rovnou publikuje na web bez dalšího ověřování "
        . "faktů, takže jakákoli nepřesnost se promítne přímo do školicího obsahu pro reálné řidiče.\n\n"
        . "ODPOVĚĎ: posouzení relevance, dat a dávno-zavedených-pravidel proveď myšlenkově, ale výsledek vždy odešli VÝHRADNĚ voláním "
        . "některého z dostupných nástrojů, NIKDY ne jako běžný text. Pro KAŽDOU jednotlivou navrhovanou změnu zavolej 'navrhnout_zmenu' "
        . "(pro jeden paragraf jich může být i víc, např. úprava lekce i novinka zároveň — pak ho zavolej víckrát). Pokud po pečlivém "
        . "zvážení paragraf NEVYŽADUJE žádnou změnu, zavolej PŘESNĚ JEDNOU nástroj 'paragraf_nevyzaduje_zmenu' se stručným důvodem — "
        . "nikdy nezůstávej bez volání nástroje úplně.";

    // Text posuzovaného paragrafu jde jako SAMOSTATNÝ blok na úplný konec zprávy.
    // Důvod: prompt caching (níže) cachuje velkou stabilní část ($prompt: instrukce +
    // kontext platformy + formát), která je u všech dávek IDENTICKÁ. Měnící se část
    // (text konkrétního paragrafu) musí být až ZA cachovaným blokem, jinak by se cache
    // při každé dávce rozbila a neušetřila nic.
    $vlastni_instrukce = trim(get_option('rdleg_vlastni_instrukce', ''));
    $paragraf_blok = ($vlastni_instrukce ? "DODATEČNÉ INSTRUKCE OD SPRÁVCE:\n" . $vlastni_instrukce . "\n\n" : '')
        . "POSUZOVANÝ PARAGRAF:\n\n" . $zmeny_text;

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'headers' => [
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'model' => 'claude-sonnet-4-6',
            // POZNÁMKA: zvýšeno z 4000 — u dávky paragrafů, kde prompt vyžaduje, aby
            // navrh_upravy pro 'faq'/'lekce' obsahoval KOMPLETNÍ text celé lekce/odpovědi
            // (ne jen fragment), se odpověď u většího počtu relevantních nálezů v jedné
            // dávce může snadno odříznout uprostřed JSON. Postupně zvyšováno 4000→8000→
            // 16000→20000. Sekvenční automatický retry s menší dávkou byl zvažován, ale
            // VĚDOMĚ NEIMPLEMENTOVÁN — i jediné API volání může narazit na ~180s cURL/PHP
            // timeout hostingu, retry by ho jen znásobil a timeout by nastal jistě. Místo
            // toho je hlavní obranou menší RDLEG_DAVKA_VELIKOST a tahle vyšší max_tokens
            // rezerva; detekce 'stop_reason'==='max_tokens' níže dává uživateli jasnou
            // chybovou hlášku, pokud i tak dojde k odříznutí. Zvýšeno na 32000 — Sonnet 4.6
            // zvládá až 64000 výstupních tokenů, takže je prostor pro delší navrh_upravy
            // (kompletní opsání lekce) i u dávky s více objemnými nálezy.
            'max_tokens' => 48000,
            // STRUKTUROVANÝ VÝSTUP PŘES TOOL USE (v1.75.0, přepracováno v1.78.0): PŮVODNĚ
            // se nástroj volal jednou za dávku s polem 'zmeny' (více nálezů najednou). To
            // opakovaně padalo přesně na kombinaci "vnořené pole objektů + jedno z polí
            // (navrh_upravy) obsahuje velký kus HTML" — model se v týhle konkrétní struktuře
            // ztrácel a místo skutečného vnořeného pole vracel 'zmeny' jako STRING s JSON
            // textem uvnitř, navíc s nekonzistentním escapováním (různé opravné vrstvy to
            // částečně zachránily, ale problém se vracel v nových variantách). ŘEŠENÍ: žádné
            // vnořené pole. Nástroj teď přijímá JEDEN nález najednou (ploché parametry,
            // žádné zanoření) a model ho zavolá VÍCKRÁT za odpověď, pokud má víc nálezů pro
            // tentýž paragraf (Anthropic API běžně podporuje víc tool_use bloků v jedné
            // odpovědi). Tahle plochá struktura se dosud nikdy nerozbila ani u objemného
            // navrh_upravy — problém byl specificky v zanoření, ne v délce textu samotné.
            // tool_choice je 'any' (musí zavolat NĚJAKÝ nástroj, ne nic) — v1.78.0 bylo 'auto',
            // což modelu dovolovalo odpovědět i čistým textem bez jakéhokoli volání nástroje.
            // V PRAXI se to ukázalo jako riziko: u celého běhu (20/20 dávek) nevzniknul JEDINÝ
            // návrh, včetně paragrafu, který měl být jasně relevantní — podezření je, že model
            // "přemýšlel nahlas" v textu místo volání nástroje, a takový výsledek vypadá úplně
            // stejně jako legitimní "nic nenalezeno" (nula tool_use bloků, žádná chyba). 'any'
            // tohle ticho strukturálně vylučuje — model musí zavolat buď navrhnout_zmenu, nebo
            // explicitně paragraf_nevyzaduje_zmenu (druhý nástroj níže). Pokud by nezavolal ani
            // jedno, je to teď skutečná anomálie, kterou kód níže nahlásí jako chybu, ne tiše
            // přijme jako "nic k řešení".
            'tools' => [[
                'name' => 'navrhnout_zmenu',
                'description' => 'Nahlásí JEDNU navrhovanou změnu obsahu platformy RefDrive.pro na základě posouzeného paragrafu zákona. Zavolej tento nástroj jednou pro KAŽDOU samostatnou navrhovanou změnu — pokud má jeden paragraf víc nálezů (např. úprava lekce i novinka zároveň), zavolej ho víckrát.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paragraf' => ['type' => 'string', 'description' => 'Posuzovaný paragraf, např. "§87 odst. 3".'],
                        'nazev_zmeny' => ['type' => 'string', 'description' => 'Krátký název pro lektora.'],
                        'shrnuti_zmeny' => ['type' => 'string', 'description' => 'Věcné shrnutí, 1-2 věty.'],
                        'cilove_misto' => ['type' => 'string', 'enum' => ['novinka', 'novinka_uprava', 'faq', 'lekce', 'kviz_otazka']],
                        'cilovy_identifikator' => ['type' => 'string', 'description' => 'Přesná otázka FAQ / číslo lekce / přesné znění otázky / přesný název existující novinky (jen pro novinka_uprava), nebo prázdné pro novinku (vznik nové).'],
                        'navrh_upravy' => ['type' => 'string', 'description' => 'Navrhovaný text — viz pravidla formátu v zadání výše.'],
                    ],
                    'required' => ['paragraf', 'nazev_zmeny', 'shrnuti_zmeny', 'cilove_misto', 'navrh_upravy'],
                ],
            ], [
                'name' => 'paragraf_nevyzaduje_zmenu',
                'description' => 'Nahlas, že posuzovaný paragraf po pečlivém zvážení NEVYŽADUJE žádnou změnu obsahu platformy. Zavolej PŘESNĚ JEDNOU, a jen pokud jsi nezavolal/a navrhnout_zmenu ani jednou pro tento paragraf.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'paragraf' => ['type' => 'string', 'description' => 'Posuzovaný paragraf, např. "§87 odst. 3".'],
                        'duvod' => ['type' => 'string', 'description' => 'Stručně (1 věta), proč paragraf nevyžaduje změnu — např. "není relevantní pro referentské řidiče" nebo "obsah platformy už je správný".'],
                    ],
                    'required' => ['paragraf', 'duvod'],
                ],
            ]],
            'tool_choice' => ['type' => 'any'],
            // PROMPT CACHING: stabilní část (instrukce + kontext platformy + formát) je
            // u všech dávek identická, proto ji označíme cache_control 'ephemeral' — při
            // první dávce se zapíše do cache (1.25x cena vstupu), u dalších dávek se načte
            // z cache za 0.1x ceny (90% úspora). Druhý blok (text paragrafu) se mění s
            // každou dávkou, ten se necachuje. Cache vydrží ~5 minut mezi voláními, což
            // při automatickém zpracování dávek v rychlém sledu bohatě stačí. Tools blok je
            // u všech dávek také identický a stojí PŘED messages, takže se do cachované
            // předpony počítá automaticky spolu s ním.
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt, 'cache_control' => ['type' => 'ephemeral']],
                    ['type' => 'text', 'text' => $paragraf_blok],
                ],
            ]],
        ]),
        'timeout' => 180,
    ]);

    if (is_wp_error($response)) return $response;
    $body = rdleg_json_decode_s_opravou(wp_remote_retrieve_body($response));

    // ANTHROPIC API VRÁTILA CHYBU (ne normální zprávu) — typicky špatný/chybějící kredit,
    // neplatný API klíč, rate limit, nebo přetížení API. Tvar takové odpovědi je úplně jiný
    // ({"type":"error","error":{"type":...,"message":...}}, žádné 'content' ani 'stop_reason'),
    // takže by jinak skončila jako nejasná "AI nezavolala žádný nástroj" hláška s JSON dumpem.
    // Rozpoznáme to tady hned a vrátíme srozumitelnou hlášku podle konkrétního typu chyby.
    if (($body['type'] ?? '') === 'error') {
        $api_typ = $body['error']['type'] ?? '';
        $api_zprava = $body['error']['message'] ?? 'neznámá chyba';
        $cesky = [
            'insufficient_balance_error' => 'na účtu Anthropic API není dostatek kreditu. Doplňte kredit v Anthropic Console (Billing) a zkuste to znovu.',
            'authentication_error' => 'API klíč pro Anthropic API není platný nebo chybí. Zkontrolujte API klíč v nastavení pluginu.',
            'permission_error' => 'API klíč nemá oprávnění pro tento požadavek/model. Zkontrolujte nastavení API klíče v Anthropic Console.',
            'rate_limit_error' => 'dosažen limit počtu požadavků na Anthropic API (rate limit). Zkuste to znovu za chvíli.',
            'overloaded_error' => 'Anthropic API je momentálně přetížené. Zkuste to znovu za chvíli.',
            'not_found_error' => 'požadovaný model nebyl na Anthropic API nalezen — zkontrolujte název modelu v kódu pluginu.',
        ][$api_typ] ?? "Anthropic API vrátila chybu ({$api_typ}): {$api_zprava}";
        return new WP_Error('rdleg_api_chyba', "Anthropic API: " . $cesky);
    }

    // Sebrat VŠECHNY tool_use bloky. 'navrhnout_zmenu' = konkrétní nález (0, 1, nebo víc).
    // 'paragraf_nevyzaduje_zmenu' = explicitní "nic tu není", s důvodem — uložíme ho do
    // rdleg_vyhodnoceni_log (option), aby lektor mohl zpětně přelétnout, že "nic nenalezeno"
    // u každého paragrafu dává smysl, místo aby věřil černé skříňce.
    // Každé volání má ploché parametry bez zanoření (viz POZNÁMKA u tool_choice výše), takže
    // input přichází přímo jako hotová PHP struktura, žádné další parsování není potřeba.
    $navrhy = [];
    $zavolan_nejaky_nastroj = false;
    $nenalezeno_log = [];
    foreach ($body['content'] ?? [] as $block) {
        if (($block['type'] ?? '') !== 'tool_use') continue;
        if (($block['name'] ?? '') === 'navrhnout_zmenu' && is_array($block['input'] ?? null)) {
            $navrhy[] = $block['input'];
            $zavolan_nejaky_nastroj = true;
        } elseif (($block['name'] ?? '') === 'paragraf_nevyzaduje_zmenu' && is_array($block['input'] ?? null)) {
            $zavolan_nejaky_nastroj = true;
            $p = sanitize_text_field($block['input']['paragraf'] ?? '?');
            $nenalezeno_log[$p] = [
                'duvod' => sanitize_text_field($block['input']['duvod'] ?? ''),
                'cas' => current_time('mysql'),
            ];
        }
    }
    if ($nenalezeno_log) {
        $existujici = json_decode(get_option('rdleg_vyhodnoceni_log', '{}'), true);
        if (!is_array($existujici)) $existujici = [];
        update_option('rdleg_vyhodnoceni_log', wp_json_encode(array_merge($existujici, $nenalezeno_log)), false);
    }

    // ROZLIŠENÍ PŘÍČINY: pokud byla odpověď odříznutá kvůli max_tokens, mohl být rozjetý
    // tool_use blok nedokončený. I kdyby $navrhy obsahovalo nějaké už dokončené nálezy,
    // nevracíme je jako hotový výsledek — tím by se davek_hotovo posunulo dál a zbytek
    // paragrafu by se tiše přeskočil navždy. Lepší nahlásit chybu, ať se dávka zopakuje.
    if (($body['stop_reason'] ?? '') === 'max_tokens') {
        return new WP_Error('rdleg_ai_truncated', 'Odpověď AI byla odříznuta kvůli limitu délky — zmenšete velikost dávky (RDLEG_DAVKA_VELIKOST) a zkuste znovu.');
    }

    // S tool_choice='any' MUSÍ model zavolat nějaký nástroj — pokud nezavolal ani jeden,
    // jde o skutečnou anomálii (ne legitimní "nic nenalezeno", to se hlásí explicitně přes
    // paragraf_nevyzaduje_zmenu výše), proto to teď nahlásíme jako chybu k diagnostice,
    // místo aby se to ztratilo jako tichá nula.
    if (!$zavolan_nejaky_nastroj) {
        $surova = wp_json_encode($body);
        $delka = mb_strlen($surova);
        $ukazka = mb_substr($surova, 0, 1500);
        if ($delka > 1500) $ukazka .= ' […] ' . mb_substr($surova, -500);
        return new WP_Error('rdleg_ai_parse', "AI nezavolala žádný nástroj, ačkoli tool_choice='any' to vyžaduje. Délka odpovědi API: {$delka} znaků. Odpověď: {$ukazka}");
    }

    return $navrhy;
}

/* ============================================================
   DOTAŽENÍ AKTUÁLNÍHO OBSAHU CÍLE — pro porovnání "aktuálně na webu" vs. návrh
   ============================================================ */
/* ============================================================
   NAJÍT LEKCI / OTÁZKU PODLE STABILNÍHO IDENTIFIKÁTORU
   ============================================================
   Hlavní plugin při KAŽDÉM uložení záložky "Lekce" nebo "Test" v lektor portálu
   (i kvůli úpravě jiné položky) smaže celou tabulku a znovu ji vloží — všechny
   položky tak dostanou nová auto-increment ID, i ty, které se vůbec neměnily.
   Číselné DATABÁZOVÉ ID je proto nestabilní identifikátor mezi tím, kdy AI návrh
   vznikne, a tím, kdy je schválen.
   U lekce se místo toho používá ČÍSLO LEKCE (1, 2, 3...), tedy přesně to, co
   lektor vidí v editoru a student v kurzu — to při uložení zůstává stejné, dokud
   se lekce nepřeřadí. V DB odpovídá sloupci poradi (0-indexovanému, číslo lekce
   je poradi+1). U otázky se používá přesné znění otázky (stejný princip jako
   u FAQ), protože otázky v UI vlastní číslo nemají.
   Staré čekající návrhy vzniklé PŘED touto změnou ale mohou mít v cilovy_identifikator
   ještě název lekce (krátké přechodné schéma, v1.87.0) nebo přímo databázové ID
   (nejstarší schéma) — proto se zkouší víc variant po sobě.
   ============================================================ */
function rdleg_najit_lekce_id($identifikator) {
    global $wpdb;
    if ($identifikator === null || $identifikator === '') return null;
    $identifikator = trim((string) $identifikator);

    // 1) Aktuální schéma: číslo lekce (1, 2, 3...), jak ho vidí lektor i student — tedy
    // POZICE v pořadí (1. lekce, 2. lekce...), NE literální hodnota sloupce poradi. Pokud
    // by měl poradi mezery (např. 0,1,2,4,5... bez 3), porovnání "poradi=číslo-1" by selhalo
    // i pro lekci, která fakticky existuje — proto se hledá podle POZICE v ORDER BY, stejně
    // jako to počítá hlavní plugin ($i+1 po ORDER BY poradi ASC), ne podle syrové hodnoty.
    if (ctype_digit($identifikator)) {
        $pozice = ((int) $identifikator) - 1;
        if ($pozice >= 0) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rd_lekce ORDER BY poradi ASC LIMIT 1 OFFSET %d", $pozice
            ));
            if ($id) return (int) $id;
        }
    }

    // 2) Přechodné schéma (krátce používané, v1.87.0): přesný název lekce.
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_lekce WHERE nazev=%s LIMIT 1", $identifikator));
    if ($id) return (int) $id;

    // 3) Nejstarší schéma (před v1.87.0): přímo číslo databázového řádku.
    if (ctype_digit($identifikator)) {
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_lekce WHERE id=%d", (int) $identifikator));
        if ($id) return (int) $id;
    }

    return null;
}

function rdleg_najit_otazku_id($identifikator) {
    global $wpdb;
    if (empty($identifikator)) return null;
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_otazky WHERE otazka=%s LIMIT 1", $identifikator));
    if ($id) return (int) $id;
    if (ctype_digit(trim((string) $identifikator))) {
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_otazky WHERE id=%d", (int) $identifikator));
        if ($id) return (int) $id;
    }
    return null;
}

// Pro 'novinka_uprava' (úprava EXISTUJÍCÍ novinky, na rozdíl od 'novinka' = vznik NOVÉ).
// Identifikace podle přesného názvu — stejný princip jako FAQ, ze stejného důvodu
// (přeuložení záložky "Novinky" v lektor portálu dává všem novinkám nová ID).
function rdleg_najit_novinku_id($identifikator) {
    global $wpdb;
    if (empty($identifikator)) return null;
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_novinky WHERE nazev=%s LIMIT 1", $identifikator));
    return $id ? (int) $id : null;
}

function rdleg_aktualni_obsah_cile($cilove_misto, $cilovy_identifikator) {
    global $wpdb;
    if (empty($cilovy_identifikator)) return null;

    if ($cilove_misto === 'faq') {
        $val = $wpdb->get_var($wpdb->prepare("SELECT odpoved FROM {$wpdb->prefix}rd_faq WHERE otazka=%s", $cilovy_identifikator));
        return rdleg_ocistit_html($val);
    }
    if ($cilove_misto === 'lekce') {
        $lekce_id = rdleg_najit_lekce_id($cilovy_identifikator);
        if ($lekce_id) {
            $val = $wpdb->get_var($wpdb->prepare("SELECT obsah FROM {$wpdb->prefix}rd_lekce WHERE id=%d", $lekce_id));
            return rdleg_ocistit_html($val);
        }
        return null;
    }
    if ($cilove_misto === 'kviz_otazka') {
        $otazka_id = rdleg_najit_otazku_id($cilovy_identifikator);
        if ($otazka_id) {
            $o = $wpdb->get_row($wpdb->prepare(
                "SELECT otazka, moznost_a, moznost_b, moznost_c, moznost_d, spravna FROM {$wpdb->prefix}rd_otazky WHERE id=%d", $otazka_id
            ));
            if ($o) return "{$o->otazka}\nA) {$o->moznost_a}\nB) {$o->moznost_b}\nC) {$o->moznost_c}\nD) {$o->moznost_d}\nSprávně: {$o->spravna}";
        }
        return null;
    }
    if ($cilove_misto === 'novinka_uprava') {
        $novinka_id = rdleg_najit_novinku_id($cilovy_identifikator);
        if ($novinka_id) {
            $val = $wpdb->get_var($wpdb->prepare("SELECT obsah FROM {$wpdb->prefix}rd_novinky WHERE id=%d", $novinka_id));
            return rdleg_ocistit_html($val);
        }
        return null;
    }
    return null; // 'novinka' nemá existující obsah — vždy vzniká nový záznam
}

/* ============================================================
   DOTAŽENÍ AKTUÁLNÍHO OBSAHU CÍLE — SUROVÉ HTML pro vizuální zobrazení
   ============================================================
   Na rozdíl od rdleg_aktualni_obsah_cile() (která prochází přes
   rdleg_ocistit_html() a strhává VEŠKERÉ HTML — to je správně pro AI
   prompt, kde chceme čistý text bez šumu) tato varianta vrací obsah
   PŘÍMO z databáze beze změny, aby lektor v náhledu "Aktuálně na webu"
   viděl přesně to, co je teď živé na webu — včetně nadpisů, seznamů,
   tučného textu a barevných rd-info-chip boxů. Výstup se na výstupu
   pouští přes wp_kses_post() (bezpečné HTML), ne přes esc_html().
   ============================================================ */
function rdleg_aktualni_html_cile($cilove_misto, $cilovy_identifikator) {
    global $wpdb;
    if (empty($cilovy_identifikator)) return null;

    if ($cilove_misto === 'faq') {
        return $wpdb->get_var($wpdb->prepare("SELECT odpoved FROM {$wpdb->prefix}rd_faq WHERE otazka=%s", $cilovy_identifikator));
    }
    if ($cilove_misto === 'lekce') {
        $lekce_id = rdleg_najit_lekce_id($cilovy_identifikator);
        if ($lekce_id) {
            return $wpdb->get_var($wpdb->prepare("SELECT obsah FROM {$wpdb->prefix}rd_lekce WHERE id=%d", $lekce_id));
        }
        return null;
    }
    if ($cilove_misto === 'novinka_uprava') {
        $novinka_id = rdleg_najit_novinku_id($cilovy_identifikator);
        if ($novinka_id) {
            return $wpdb->get_var($wpdb->prepare("SELECT obsah FROM {$wpdb->prefix}rd_novinky WHERE id=%d", $novinka_id));
        }
        return null;
    }
    // kviz_otazka a novinka (vznik nové) nemají HTML variantu (otázka je strukturovaný text, nová novinka neexistuje, dokud nevznikne)
    return null;
}

/* ============================================================
   TESTOVÉ OTÁZKY — parsování AI návrhu a načtení aktuální otázky
   ============================================================
   AI vrací návrh testové otázky ve strukturovaném textovém formátu:
     OTAZKA: ...
     A: ...
     B: ...
     C: ...
     D: ...
     SPRAVNA: A|B|C|D
   Tyto funkce ten formát parsují na asociativní pole a opačně, plus
   načtou aktuální otázku z DB jako pole pro předvyplnění editoru.
   ============================================================ */
function rdleg_parsovat_navrh_otazky($text) {
    $vysledek = ['otazka' => '', 'a' => '', 'b' => '', 'c' => '', 'd' => '', 'spravna' => ''];
    if (!is_string($text) || $text === '') return $vysledek;
    // Strhnout případné HTML (AI by ho tu neměla psát, ale pro jistotu) a projít po řádcích.
    $cisty = wp_strip_all_tags($text);
    $radky = preg_split('/\r\n|\r|\n/', $cisty);
    foreach ($radky as $r) {
        $r = trim($r);
        if (preg_match('/^OTAZKA:\s*(.*)$/u', $r, $m)) $vysledek['otazka'] = trim($m[1]);
        elseif (preg_match('/^A:\s*(.*)$/u', $r, $m)) $vysledek['a'] = trim($m[1]);
        elseif (preg_match('/^B:\s*(.*)$/u', $r, $m)) $vysledek['b'] = trim($m[1]);
        elseif (preg_match('/^C:\s*(.*)$/u', $r, $m)) $vysledek['c'] = trim($m[1]);
        elseif (preg_match('/^D:\s*(.*)$/u', $r, $m)) $vysledek['d'] = trim($m[1]);
        elseif (preg_match('/^SPRAVNA:\s*([ABCD])/ui', $r, $m)) $vysledek['spravna'] = strtoupper($m[1]);
    }
    return $vysledek;
}

function rdleg_nacist_otazku_pole($cilovy_identifikator) {
    global $wpdb;
    $otazka_id = rdleg_najit_otazku_id($cilovy_identifikator);
    if (!$otazka_id) return null;
    $o = $wpdb->get_row($wpdb->prepare(
        "SELECT otazka, moznost_a, moznost_b, moznost_c, moznost_d, spravna FROM {$wpdb->prefix}rd_otazky WHERE id=%d", $otazka_id
    ));
    if (!$o) return null;
    return [
        'otazka' => $o->otazka,
        'a' => $o->moznost_a, 'b' => $o->moznost_b, 'c' => $o->moznost_c, 'd' => $o->moznost_d,
        'spravna' => $o->spravna,
    ];
}

/* Čitelné textové shrnutí návrhu testové otázky pro read-only náhledy v adminu.
   Pokud je navrh_upravy ve strukturovaném formátu, vykreslí otázku + možnosti
   s vyznačenou správnou; pokud AI vrátila jen slovní popis, vrátí ten popis. */
function rdleg_navrh_otazky_citelne($navrh_upravy) {
    $p = rdleg_parsovat_navrh_otazky($navrh_upravy);
    if ($p['otazka'] === '' && $p['a'] === '' && $p['b'] === '') {
        return nl2br(esc_html(rdleg_ocistit_html($navrh_upravy)));
    }
    $out = esc_html($p['otazka']) . '<br>';
    foreach (['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'] as $pis => $kl) {
        $oznac = ($p['spravna'] === $pis) ? ' ✓' : '';
        $out .= '<br>' . $pis . ') ' . esc_html($p[$kl]) . $oznac;
    }
    return $out;
}

/* ============================================================
   APLIKACE SCHVÁLENÉHO NÁVRHU NA CÍLOVÉ MÍSTO PLATFORMY
   ============================================================ */
function rdleg_aplikovat_navrh($navrh, $finalni_text) {
    global $wpdb;
    if (empty($finalni_text)) return false;

    $rdleg_citace = trim($navrh->paragraf) . ' zákona č. 361/2000 Sb. (schváleno ' . date('d.m.Y') . ')';

    if ($navrh->cilove_misto === 'faq') {
        $wpdb->update("{$wpdb->prefix}rd_faq", ['odpoved' => $finalni_text, 'rd_leg_zdroj' => $rdleg_citace], ['otazka' => $navrh->cilovy_identifikator]);
        return true;
    }

    if ($navrh->cilove_misto === 'lekce') {
        $lekce_id = rdleg_najit_lekce_id($navrh->cilovy_identifikator);
        if ($lekce_id) {
            $wpdb->update("{$wpdb->prefix}rd_lekce", ['obsah' => $finalni_text, 'rd_leg_zdroj' => $rdleg_citace], ['id' => $lekce_id]);
        }
        return true;
    }

    if ($navrh->cilove_misto === 'novinka') {
        $max_poradi = (int) $wpdb->get_var("SELECT MAX(poradi) FROM {$wpdb->prefix}rd_novinky");
        $wpdb->insert("{$wpdb->prefix}rd_novinky", [
            'poradi' => $max_poradi + 1,
            'tag' => 'Legislativa',
            'nazev' => $navrh->nazev_zmeny ?: 'Změna v zákoně č. 361/2000 Sb.',
            'obsah' => $finalni_text,
            'rd_leg_zdroj' => $rdleg_citace,
        ]);
        return true;
    }

    if ($navrh->cilove_misto === 'novinka_uprava') {
        $novinka_id = rdleg_najit_novinku_id($navrh->cilovy_identifikator);
        if ($novinka_id) {
            $wpdb->update("{$wpdb->prefix}rd_novinky", ['obsah' => $finalni_text, 'rd_leg_zdroj' => $rdleg_citace], ['id' => $novinka_id]);
        }
        return true;
    }

    if ($navrh->cilove_misto === 'kviz_otazka') {
        $otazka_id = rdleg_najit_otazku_id($navrh->cilovy_identifikator);
        if (!$otazka_id) return false;
        $p = rdleg_parsovat_navrh_otazky($finalni_text);
        // Pokud se z návrhu nepodařilo vyparsovat znění ani možnosti, nezapisuj nic
        // (lepší než přepsat otázku prázdnými hodnotami).
        if ($p['otazka'] === '' || $p['a'] === '' || $p['b'] === '' || $p['c'] === '' || $p['d'] === '') return false;
        $spravna = in_array($p['spravna'], ['A','B','C','D'], true) ? $p['spravna'] : 'A';
        $wpdb->update("{$wpdb->prefix}rd_otazky", [
            'otazka' => $p['otazka'],
            'moznost_a' => $p['a'],
            'moznost_b' => $p['b'],
            'moznost_c' => $p['c'],
            'moznost_d' => $p['d'],
            'spravna' => $spravna,
        ], ['id' => $otazka_id]);
        return true;
    }

    return false;
}

/* ============================================================
   VYKRESLENÍ BANNERU A SEKCE NÁVRHŮ V LEKTOR PORTÁLU
   (vlastní <style> blok — nezávislý na Aurora tématu, funguje i bez něj)
   ============================================================ */

function rdleg_banner_css() {
    return '<style>
/* Vlastní proměnné nezávislé na tématu — fallback je dark mode (výchozí stav webu),
   [data-theme="light"] override odpovídá přepínači světlo/tma na webu. */
.rdleg-wrap-outer{--rdleg-bg:#1a1625;--rdleg-surface:#241f33;--rdleg-border:rgba(255,255,255,.1);--rdleg-text-1:#f3f0ff;--rdleg-text-2:#c4bdd9;--rdleg-text-3:#8d84a8}
[data-theme="light"] .rdleg-wrap-outer{--rdleg-bg:#ffffff;--rdleg-surface:#ffffff;--rdleg-border:#e5e1ee;--rdleg-text-1:#1a1625;--rdleg-text-2:#564f6e;--rdleg-text-3:#8d84a8}

.rdleg-banner{display:flex;gap:12px;align-items:flex-start;text-decoration:none;padding:14px 16px;margin:14px 0;border-radius:12px;background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);transition:background .2s,border-color .2s}
.rdleg-banner:hover{background:rgba(248,113,113,.16);border-color:rgba(248,113,113,.45)}
.rdleg-banner svg{flex-shrink:0;color:#f87171;margin-top:2px}
.rdleg-banner-title{font-size:.9rem;font-weight:700;margin:0 0 2px;color:#fca5a5}
.rdleg-banner-count{color:#f87171;font-weight:800}
.rdleg-banner-text{font-size:.82rem;color:#e8b4b4;margin:0;line-height:1.5}
[data-theme="light"] .rdleg-banner{background:#fef2f2;border-color:#fecaca}
[data-theme="light"] .rdleg-banner-title{color:#991b1b}
[data-theme="light"] .rdleg-banner-text{color:#7f1d1d}
[data-theme="light"] .rdleg-banner svg{color:#dc2626}
[data-theme="light"] .rdleg-banner-count{color:#dc2626}

.rdleg-section{margin:18px 0}
.rdleg-card{background:var(--rdleg-surface,#241f33);border:1px solid var(--rdleg-border,rgba(255,255,255,.1));border-radius:14px;padding:0;margin-bottom:18px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.rdleg-card-inner{padding:26px 24px}
.rdleg-card h3{margin:0 0 8px;font-size:16px;font-weight:700;color:var(--rdleg-text-1,#f3f0ff);line-height:1.4}
.rdleg-badge-row{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}
.rdleg-cil{color:var(--rdleg-text-3,#8d84a8);font-size:13px;margin:0 0 18px}
.rdleg-cil strong{color:var(--rdleg-text-2,#c4bdd9)}
.rdleg-shrnuti{font-size:13.5px;color:var(--rdleg-text-2,#c4bdd9);line-height:1.65;margin:0 0 20px}
.rdleg-badge{display:inline-flex;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:700;margin-top:6px}
.rdleg-badge-novy{color:#4ade80;background:radial-gradient(circle at 20% 30%,rgba(74,222,128,.25) 0%,rgba(22,163,74,.1) 70%);border:1px solid rgba(74,222,128,.4)}
.rdleg-badge-zmena{color:#fb923c;background:radial-gradient(circle at 20% 30%,rgba(251,146,60,.25) 0%,rgba(234,88,12,.1) 70%);border:1px solid rgba(251,146,60,.4)}
.rdleg-badge-smazany{color:#f87171;background:radial-gradient(circle at 20% 30%,rgba(248,113,113,.25) 0%,rgba(220,38,38,.1) 70%);border:1px solid rgba(248,113,113,.4)}
[data-theme="light"] .rdleg-badge-novy{color:#15803d;background:#dcfce7;border:1px solid #86efac}
[data-theme="light"] .rdleg-badge-zmena{color:#b45309;background:#fef3c7;border:1px solid #fcd34d}
[data-theme="light"] .rdleg-badge-smazany{color:#b91c1c;background:#fee2e2;border:1px solid #fca5a5}
.rdleg-badge-obsah-nova{color:#4ade80;background:radial-gradient(circle at 20% 30%,rgba(74,222,128,.25) 0%,rgba(22,163,74,.1) 70%);border:1px solid rgba(74,222,128,.4)}
.rdleg-badge-obsah-uprava{color:#fb923c;background:radial-gradient(circle at 20% 30%,rgba(251,146,60,.25) 0%,rgba(234,88,12,.1) 70%);border:1px solid rgba(251,146,60,.4)}
[data-theme="light"] .rdleg-badge-obsah-nova{color:#15803d;background:#dcfce7;border:1px solid #86efac}
[data-theme="light"] .rdleg-badge-obsah-uprava{color:#b45309;background:#fef3c7;border:1px solid #fcd34d}
.rdleg-diff{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:0 0 20px}
.rdleg-diff-box{border-radius:10px;overflow:hidden;color:var(--rdleg-text-2,#c4bdd9)}
.rdleg-diff-box.stare{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3)}
.rdleg-diff-box.nove{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3)}
[data-theme="light"] .rdleg-diff-box.stare{background:rgba(220,38,38,.06);border-color:rgba(220,38,38,.25)}
[data-theme="light"] .rdleg-diff-box.nove{background:rgba(22,163,74,.07);border-color:rgba(22,163,74,.25)}
.rdleg-diff-box summary{padding:14px 16px;cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:8px;user-select:none}
.rdleg-diff-box summary::-webkit-details-marker{display:none}
.rdleg-diff-box summary::after{content:"Zobrazit celé ▾";font-size:11px;font-weight:600;color:var(--rdleg-text-3,#8d84a8);white-space:nowrap;flex-shrink:0}
.rdleg-diff-box[open] summary::after{content:"Skrýt ▴"}
.rdleg-diff-box-content{padding:0 16px 14px;font-size:12.5px;line-height:1.65;max-height:140px;overflow:hidden;position:relative;mask-image:linear-gradient(to bottom,#000 calc(100% - 28px),transparent 100%);-webkit-mask-image:linear-gradient(to bottom,#000 calc(100% - 28px),transparent 100%)}
.rdleg-diff-box[open] .rdleg-diff-box-content{max-height:none;mask-image:none;-webkit-mask-image:none}
.rdleg-diff-box-content p{margin:0 0 8px}
.rdleg-diff-box-content ul{padding-left:20px;margin:6px 0}
.rdleg-diff-box-content li{margin:0 0 6px}
.rdleg-diff-box-content h4{font-size:13px;margin:10px 0 4px;color:var(--rdleg-text-1,#f3f0ff);text-transform:uppercase;letter-spacing:.03em}
.rdleg-diff-box-content strong{color:var(--rdleg-text-1,#f3f0ff)}
.rdleg-diff-box-content .rd-info-chip{margin:8px 0}
.rdleg-diff-label{display:block;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--rdleg-text-3,#8d84a8)}
.rdleg-navrh-box{background:var(--rdleg-bg,#1a1625);border:1px solid var(--rdleg-border,rgba(255,255,255,.1));border-radius:10px;padding:16px 18px;font-size:13.5px;margin:0 0 20px;color:var(--rdleg-text-1,#f3f0ff);line-height:1.65}
.rdleg-textarea{width:100%;min-height:130px;border:1px solid var(--rdleg-border,rgba(255,255,255,.1));border-radius:10px;padding:14px 16px;font-size:13.5px;font-family:inherit;box-sizing:border-box;margin:10px 0 18px;background:var(--rdleg-bg,#1a1625);color:var(--rdleg-text-1,#f3f0ff);resize:vertical;line-height:1.55}
.rdleg-textarea:focus{outline:none;border-color:#7c3aed}
.rdleg-label{font-size:12.5px;font-weight:600;color:var(--rdleg-text-3,#8d84a8);display:block}
.rdleg-editor-wrap{border:1px solid var(--rdleg-border,rgba(255,255,255,.1));border-radius:10px;overflow:hidden;margin:10px 0 18px}
.rdleg-editor-toolbar{display:flex;gap:2px;padding:6px 8px;background:var(--rdleg-surface,#241f33);border-bottom:1px solid var(--rdleg-border,rgba(255,255,255,.1))}
.rdleg-editor-btn{background:transparent;border:1px solid transparent;border-radius:4px;padding:4px 6px;cursor:pointer;color:var(--rdleg-text-2,#c4bdd9);display:flex;align-items:center;justify-content:center;transition:background .15s}
.rdleg-editor-btn:hover{background:var(--rdleg-border,rgba(255,255,255,.1));color:var(--rdleg-text-1,#f3f0ff)}
.rdleg-editor-body{min-height:130px;padding:14px 16px;color:var(--rdleg-text-1,#f3f0ff);font-size:13.5px;line-height:1.6;outline:none;background:var(--rdleg-bg,#1a1625)}
.rdleg-editor-body:empty::before{content:attr(data-placeholder);color:var(--rdleg-text-3,#8d84a8);pointer-events:none}
.rdleg-editor-body ul{padding-left:20px;margin:6px 0}
.rdleg-editor-body h4{font-size:14px;margin:8px 0 4px;color:var(--rdleg-text-1,#f3f0ff)}
.rdleg-addchip-row{display:flex;gap:8px;padding:8px;border-top:1px solid var(--rdleg-border,rgba(255,255,255,.1));background:var(--rdleg-surface,#241f33)}
.rdleg-addchip-btn{border-radius:6px;padding:4px 10px;font-size:.78rem;cursor:pointer;font-family:inherit}
.rdleg-addchip-blue{background:rgba(96,165,250,.15);border:1px solid #60a5fa;color:#93c5fd}
.rdleg-addchip-red{background:rgba(239,68,68,.15);border:1px solid #f87171;color:#fca5a5}
.rdleg-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.rdleg-btn{display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:7px 14px;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .2s,border-color .2s,color .2s;background:transparent}
.rdleg-btn-schvalit{border:1px solid rgba(110,231,183,.5);background:rgba(52,211,153,.15);color:#6ee7b7}
.rdleg-btn-schvalit:hover{background:rgba(52,211,153,.22);border-color:#6ee7b7}
.rdleg-btn-zamitnout{border:1px solid var(--rdleg-border,rgba(255,255,255,.15));color:var(--rdleg-text-3,#8d84a8)}
.rdleg-btn-zamitnout:hover{background:rgba(248,113,113,.12);border-color:#f87171;color:#f87171}
[data-theme="light"] .rdleg-btn-schvalit{color:#065f46}
.rdleg-zamitnout-note{display:none;margin-top:18px;padding-top:20px;border-top:1px solid var(--rdleg-border,rgba(255,255,255,.1))}
.rdleg-hint{font-size:12px;color:var(--rdleg-text-3,#8d84a8);margin:6px 0 14px;line-height:1.5}
.rdleg-footnote{font-size:11.5px;color:var(--rdleg-text-3,#8d84a8);margin:20px 0 0}
.rdleg-empty{color:var(--rdleg-text-3,#8d84a8);font-size:13px;padding:28px;text-align:center}
.rdleg-callout{border-radius:10px;padding:12px 16px;margin:0 0 18px;font-size:13.5px;line-height:1.55;display:flex;gap:10px;align-items:flex-start}
.rdleg-callout svg{flex-shrink:0;margin-top:2px}
.rdleg-callout-success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#bbf7d0}
.rdleg-callout-success svg{color:#4ade80}
[data-theme="light"] .rdleg-callout-success{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
[data-theme="light"] .rdleg-callout-success svg{color:#16a34a}
.rdleg-callout-warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);color:#fde68a}
.rdleg-callout-warning svg{color:#fbbf24}
[data-theme="light"] .rdleg-callout-warning{background:#fef9c3;border-color:#fde047;color:#854d0e}
[data-theme="light"] .rdleg-callout-warning svg{color:#ca8a04}
.rdleg-btn-smazat-novinku{color:#fde68a;border:1px solid rgba(245,158,11,.4);background:rgba(245,158,11,.1)}
.rdleg-btn-smazat-novinku:hover{background:rgba(245,158,11,.18);border-color:#fbbf24}
[data-theme="light"] .rdleg-btn-smazat-novinku{color:#92400e;border-color:#fde68a;background:#fffbeb}
@media(max-width:640px){ .rdleg-diff{grid-template-columns:1fr} }
</style>';
}

function rdleg_vykreslit_banner() {
    global $wpdb;
    $ceka = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav='ceka'");
    if ($ceka === 0) return '';

    $slovo = ($ceka === 1) ? 'změna' : (($ceka >= 2 && $ceka <= 4) ? 'změny' : 'změn');

    return rdleg_banner_css() . '<a href="?tab=rdleg_zmeny" class="rdleg-banner">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>'
        . '<div><p class="rdleg-banner-title">Nalezeno <span class="rdleg-banner-count">' . $ceka . '</span> možných legislativních ' . $slovo . '</p>'
        . '<p class="rdleg-banner-text">Tato funkce je experimentální a jde jen o pomocníka — výsledky AI jsou orientační. Návrhy si prosím věcně zkontrolujte, případně upravte, než je schválíte. Klikněte pro zobrazení.</p></div></a>';
}

function rdleg_vykreslit_sekci_navrhu() {
    global $wpdb;

    $cil_popisky = ['novinka' => 'Novinka', 'novinka_uprava' => 'Úprava novinky', 'faq' => 'FAQ', 'lekce' => 'Lekce kurzu', 'kviz_otazka' => 'Testová otázka'];
    $typ_popisky = ['novy' => 'Nové ustanovení', 'zmena' => 'Změna', 'smazany' => 'Zrušeno'];
    $typ_tridy = ['novy' => 'rdleg-badge-novy', 'zmena' => 'rdleg-badge-zmena', 'smazany' => 'rdleg-badge-smazany'];
    $obsah_popisky = ['nova' => 'Nová položka', 'uprava' => 'Úprava položky'];
    $obsah_tridy = ['nova' => 'rdleg-badge-obsah-nova', 'uprava' => 'rdleg-badge-obsah-uprava'];

    $navrhy = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav='ceka' ORDER BY vytvoreno DESC");

    // POZNÁMKA: tahle funkce vědomě NEPOUŽÍVÁ vnořený ob_start()/ob_get_clean() — je volaná
    // uvnitř jiného output-buffer callbacku (registrovaného v template_redirect) a vnořené
    // buffery v této kombinaci na produkčním hostingu způsobovaly tvrdý pád PHP procesu
    // (bílá stránka, bez možnosti zachytit chybu přes try/catch). Místo toho se HTML staví
    // přímou konkatenací stringu, což bylo empiricky ověřeno jako funkční.
    $html = rdleg_banner_css();
    $html .= '<div class="rdleg-section rdleg-wrap-outer">';
    $html .= '<h2 style="display:flex;align-items:center;gap:10px;margin:0 0 22px">Legislativní změny <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;background:rgba(245,158,11,.15);color:#f59e0b;border-radius:99px;padding:3px 10px">Experimentální</span></h2>';

    // Potvrzení po schválení novinky — zobrazí, kolik novinek je na webu CELKEM po vložení,
    // aby bylo hned vidět, jestli se přidala k existujícím, nebo je jediná (bez nutnosti
    // otevírat databázi). Jednorázové — platí jen pro tenhle jeden požadavek po redirectu.
    if (isset($_GET['rdleg_novinka_ulozena'])) {
        $celkem_novinek = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_novinky");
        $html .= '<div class="rdleg-callout rdleg-callout-success">'
            . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>'
            . '<span>Novinka byla schválena a vložena. Na webu je teď celkem <strong>' . $celkem_novinek . '</strong> '
            . ($celkem_novinek === 1 ? 'novinka' : (($celkem_novinek >= 2 && $celkem_novinek <= 4) ? 'novinky' : 'novinek')) . '.</span></div>';
    }

    if (empty($navrhy)) {
        $html .= '<div class="rdleg-empty">Momentálně nejsou žádné čekající návrhy ke zkontrolování.</div>';
    }

    foreach ($navrhy as $n) {
        // DOPORUČENÍ-REŽIM PRO DLOUHÉ LEKCE: pokud navrh_upravy začíná řetězcem
        // RDLEG_DOPORUCENI_PREFIX (viz instrukce v rdleg_sestavit_kontext_platformy()),
        // AI nevrátila kompletní přepsaný text lekce, jen stručné doporučení k ruční úpravě.
        // V tomto režimu editor NESMÍ být přednaplněný tímto krátkým textem (riziko, že
        // lektor omylem schválí a přepíše celou lekci jen tím doporučením) — viz níže.
        $je_doporuceni_lekce = ($n->cilove_misto === 'lekce')
            && (strpos(trim((string) $n->navrh_upravy), RDLEG_DOPORUCENI_PREFIX) === 0);

        // Pro faq/lekce potřebujeme SUROVÉ HTML (rdleg_aktualni_html_cile) pro vizuální
        // náhled — ne očištěný text (rdleg_aktualni_obsah_cile), který strhává veškeré
        // HTML a je určen pro AI prompt. Pro kviz_otazka HTML varianta neexistuje (vrací
        // null), proto tam použijeme tu očištěnou/strukturovanou textovou verzi.
        // Spočítáno HNED na začátku (před badge), aby badge i zbytek karty vycházely ze
        // STEJNÉHO čerstvého zjištění — NE ze sloupce $n->typ_obsahu, který se ukládá jen
        // jednou při vzniku návrhu a u starších čekajících návrhů může být zastaralý
        // (typicky když se mezitím opravila logika hledání cíle, jako u v1.92.1).
        $aktualni_html = rdleg_aktualni_html_cile($n->cilove_misto, $n->cilovy_identifikator);
        $aktualni = ($aktualni_html !== null) ? $aktualni_html : rdleg_aktualni_obsah_cile($n->cilove_misto, $n->cilovy_identifikator);
        $je_novy_obsah = ($aktualni === null);
        $typ_obsahu_cerstvy = $je_novy_obsah ? 'nova' : 'uprava';

        $html .= '<div class="rdleg-card" data-typ="' . esc_attr($n->typ_zmeny) . '">';
        $html .= '<div class="rdleg-card-inner">';
        $html .= '<h3>' . esc_html($n->paragraf) . ' — ' . esc_html($n->nazev_zmeny ?: 'Legislativní změna') . '</h3>';
        $html .= '<div class="rdleg-badge-row">'
            . '<span class="rdleg-badge ' . ($typ_tridy[$n->typ_zmeny] ?? '') . '">' . esc_html($typ_popisky[$n->typ_zmeny] ?? $n->typ_zmeny) . '</span>'
            . '<span class="rdleg-badge ' . ($obsah_tridy[$typ_obsahu_cerstvy] ?? '') . '">' . esc_html($obsah_popisky[$typ_obsahu_cerstvy] ?? $typ_obsahu_cerstvy) . '</span>'
            . ($je_doporuceni_lekce ? '<span class="rdleg-badge" style="background:rgba(245,158,11,.18);color:#f59e0b">Doporučení k ruční úpravě</span>' : '')
            . '</div>';
        $html .= '<p class="rdleg-cil">Navrhovaná úprava pro: <strong>' . esc_html($cil_popisky[$n->cilove_misto] ?? $n->cilove_misto) . '</strong>'
            . ($n->cilovy_identifikator ? ' (' . esc_html($n->cilovy_identifikator) . ')' : '') . '</p>';
        $html .= '<p class="rdleg-shrnuti"><strong>Shrnutí:</strong> ' . esc_html($n->shrnuti_zmeny) . '</p>';

        if ($n->typ_zmeny !== 'novy' && $n->stary_text) {
            $html .= '<div class="rdleg-diff">'
                . '<details class="rdleg-diff-box stare"><summary><span class="rdleg-diff-label">Dříve (citace zákona)</span></summary><div class="rdleg-diff-box-content">' . nl2br(esc_html($n->stary_text)) . '</div></details>'
                . '<details class="rdleg-diff-box nove"><summary><span class="rdleg-diff-label">Nově (citace zákona)</span></summary><div class="rdleg-diff-box-content">' . nl2br(esc_html($n->novy_text)) . '</div></details>'
                . '</div>';
        } elseif ($n->novy_text) {
            $html .= '<details class="rdleg-diff-box nove" style="margin:0 0 20px"><summary><span class="rdleg-diff-label">Nové znění (citace zákona)</span></summary><div class="rdleg-diff-box-content">' . nl2br(esc_html($n->novy_text)) . '</div></details>';
        }

        if ($aktualni !== null) {
            // POZNÁMKA: dříve byl tu i druhý box "Navrhovaná úprava" vedle tohoto —
            // čistě zobrazovací duplicit téhož textu, který je hned pod tím editovatelný
            // v textarea. Zrušeno na žádost uživatele, aby obsah nebyl zdvojený; textarea
            // níže je teď jediné místo, kde se navrh_upravy zobrazuje i upravuje.
            //
            // DŮLEŽITÉ: pro faq/lekce je $aktualni teď SUROVÉ HTML přímo z webu (může
            // obsahovat rd-info-chip barevné boxy, h4 nadpisy, seznamy) — musí se
            // vykreslit jako HTML (wp_kses_post), ne escapovat jako text, jinak lektor
            // vidí "Aktuálně na webu" jako plochý text bez formátování, zatímco návrh
            // úpravy pod tím formátování ukazuje — nesrovnatelné "před" a "po". Pro
            // kviz_otazka je to čistý strukturovaný text bez HTML (otázka + možnosti
            // A-D), tam zůstává esc_html()+nl2br().
            // Pro kviz_otazka vykreslíme aktuální otázku jako přehledný blok: znění +
            // čtyři možnosti, kde správná je zvýrazněná (zelená, s ✓). Pro faq/lekce je
            // $aktualni surové HTML z webu (wp_kses_post). Pro novinku očištěný text.
            if ($n->cilove_misto === 'kviz_otazka') {
                $akt_pole = rdleg_nacist_otazku_pole($n->cilovy_identifikator);
                if ($akt_pole) {
                    $aktualni_zobrazeni = '<p style="font-weight:600;margin:0 0 10px">' . esc_html($akt_pole['otazka']) . '</p>';
                    foreach (['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'] as $pis => $kl) {
                        $je_spravna = ($akt_pole['spravna'] === $pis);
                        // POZOR — explicitní color:#... je nutná: tyhle boxy mají napevno světlé
                        // pozadí, a v dark módu by jinak zdědily světlou barvu textu stránky →
                        // světlý text na světlém pozadí, nečitelné (viz hlášený bug).
                        $styl = $je_spravna ? 'background:#dcfce7;border:1px solid #86efac;font-weight:600;color:#14532d' : 'background:#f9fafb;border:1px solid #e5e7eb;color:#1f2937';
                        $aktualni_zobrazeni .= '<div style="padding:7px 10px;margin:0 0 5px;border-radius:6px;' . $styl . '">'
                            . '<strong>' . $pis . ')</strong> ' . esc_html($akt_pole[$kl])
                            . ($je_spravna ? ' <span style="color:#15803d">✓ správná</span>' : '')
                            . '</div>';
                    }
                } else {
                    $aktualni_zobrazeni = nl2br(esc_html($aktualni));
                }
            } else {
                $aktualni_zobrazeni = wp_kses_post($aktualni);
            }
            $html .= '<details class="rdleg-diff-box stare" style="margin:0 0 20px"><summary><span class="rdleg-diff-label">Aktuálně na webu</span></summary><div class="rdleg-diff-box-content">' . $aktualni_zobrazeni . '</div></details>';
        }

        $html .= '<form method="post">';
        $html .= wp_nonce_field('rdleg_lektor_action', '_wpnonce', true, false);
        $html .= '<input type="hidden" name="navrh_id" value="' . intval($n->id) . '">';
        if ($je_doporuceni_lekce) {
            $doporuceni_text = trim(mb_substr(trim((string) $n->navrh_upravy), mb_strlen(RDLEG_DOPORUCENI_PREFIX)));
            $html .= '<div class="rdleg-callout rdleg-callout-warning">'
                . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>'
                . '<span><strong>Lekce je příliš dlouhá pro automatický návrh — AI doporučuje ručně upravit:</strong><br>' . nl2br(esc_html($doporuceni_text))
                . '</span></div>';
            $html .= '<label class="rdleg-label">Ruční úprava lekce:</label>';
            $html .= '<p class="rdleg-hint">Editor níže obsahuje současný obsah lekce beze změny — podle doporučení výše do něj prosím sami zapracujte potřebnou úpravu, než schválíte.</p>';
        } elseif ($je_novy_obsah) {
            $html .= '<label class="rdleg-label">Návrh nového obsahu k vytvoření:</label>';
            $html .= '<p class="rdleg-hint">Na webu zatím nic podobného neexistuje — tímto textem vznikne úplně nový záznam (' . esc_html($cil_popisky[$n->cilove_misto] ?? $n->cilove_misto) . '). Před schválením si ho prosím přečtěte a případně upravte.</p>';
        } else {
            $html .= '<label class="rdleg-label">Upravený text k propsání na web:</label>';
            $html .= '<p class="rdleg-hint">Tímto textem se přepíše stávající obsah zobrazený výše ("Aktuálně na webu"). Před schválením si prosím ověřte, že úprava odpovídá nové verzi zákona.</p>';
        }
        if (in_array($n->cilove_misto, ['faq', 'lekce', 'novinka', 'novinka_uprava'], true)) {
            // V doporučení-režimu editor naplníme AKTUÁLNÍM obsahem lekce ($aktualni_html),
            // ne krátkým textem doporučení — jinak by neupravené schválení tichounce
            // přepsalo celou lekci jen tou jednou větou doporučení (přesně typ chyby,
            // jaký byl dřív opraven u handleru "Přegenerovat").
            $obsah_pro_editor = $je_doporuceni_lekce ? ($aktualni_html !== null ? $aktualni_html : '') : $n->navrh_upravy;
            $editor_id = 'rdleg-editor-' . intval($n->id);
            $html .= '<div class="rdleg-editor-wrap">';
            $html .= '<div class="rdleg-editor-toolbar">';
            $html .= '<button type="button" class="rdleg-editor-btn" onclick="rdlegEdFmt(this,\'bold\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"/></svg></button>';
            $html .= '<button type="button" class="rdleg-editor-btn" onclick="rdlegEdFmt(this,\'insertUnorderedList\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></button>';
            $html .= '<button type="button" class="rdleg-editor-btn" onclick="rdlegEdFmt(this,\'formatBlock\',\'h4\')">H4</button>';
            $html .= '</div>';
            $html .= '<div class="rdleg-editor-body" id="' . esc_attr($editor_id) . '" contenteditable="true" data-placeholder="Návrh textu...">' . wp_kses_post($obsah_pro_editor) . '</div>';
            $html .= '<div class="rdleg-addchip-row">';
            $html .= '<button type="button" class="rdleg-addchip-btn rdleg-addchip-blue" onclick="rdlegAddChip(this,\'blue\')">ℹ Přidat modrý box</button>';
            $html .= '<button type="button" class="rdleg-addchip-btn rdleg-addchip-red" onclick="rdlegAddChip(this,\'red\')">⚠ Přidat červený box</button>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<textarea name="upraveny_text" class="rdleg-textarea" style="display:none" id="' . esc_attr($editor_id) . '-ta">' . esc_textarea($obsah_pro_editor) . '</textarea>';
        } elseif ($n->cilove_misto === 'kviz_otazka') {
            // Strukturovaný editor testové otázky — znění, čtyři možnosti, výběr správné.
            // Předvyplní se z AI návrhu (parsovaného z formátu OTAZKA:/A:/.../SPRAVNA:);
            // pokud se některé pole nevyparsuje, doplní se z aktuální otázky na webu.
            $np = rdleg_parsovat_navrh_otazky($n->navrh_upravy);
            $akt = rdleg_nacist_otazku_pole($n->cilovy_identifikator);
            // Pokud AI nevrátila strukturovaný formát (žádné OTAZKA:/A:/... řádky), ale jen
            // volný slovní popis, parsování vrátí prázdno. Ten popis pak ukážeme lektorovi
            // jako poznámku "co AI navrhuje" nad editorem, aby věděl, co má ručně upravit,
            // a samotná pole předvyplníme aktuální otázkou z webu.
            $ai_nestrukturovane = ($np['otazka'] === '' && $np['a'] === '' && $np['b'] === '' && $np['c'] === '' && $np['d'] === '');
            if ($ai_nestrukturovane && trim($n->navrh_upravy) !== '') {
                $html .= '<div class="rdleg-callout rdleg-callout-warning">'
                    . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>'
                    . '<span><strong>Co AI doporučuje zkontrolovat:</strong><br>' . nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy)))
                    . '</span></div>';
            }
            if ($akt) {
                if ($np['otazka'] === '') $np['otazka'] = $akt['otazka'];
                foreach (['a','b','c','d'] as $kl) if ($np[$kl] === '') $np[$kl] = $akt[$kl];
                if ($np['spravna'] === '') $np['spravna'] = $akt['spravna'];
            }
            if (!in_array($np['spravna'], ['A','B','C','D'], true)) $np['spravna'] = 'A';
            $html .= '<div class="rdleg-otazka-editor">';
            $html .= '<label class="rdleg-label" style="font-size:13px">Znění otázky:</label>';
            $html .= '<textarea name="otazka_znenie" class="rdleg-textarea" style="min-height:60px">' . esc_textarea($np['otazka']) . '</textarea>';
            foreach (['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'] as $pis => $kl) {
                $checked = ($np['spravna'] === $pis) ? ' checked' : '';
                $html .= '<div style="display:flex;align-items:center;gap:8px;margin:6px 0">';
                $html .= '<label style="display:flex;align-items:center;gap:5px;font-weight:600;white-space:nowrap;cursor:pointer">';
                $html .= '<input type="radio" name="otazka_spravna" value="' . $pis . '"' . $checked . '> ' . $pis . ')</label>';
                $html .= '<input type="text" name="otazka_moznost_' . strtolower($pis) . '" class="rdleg-input" style="flex:1;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px" value="' . esc_attr($np[$kl]) . '">';
                $html .= '</div>';
            }
            $html .= '<p class="rdleg-hint" style="margin-top:8px">Vyberte (zeleným přepínačem vlevo) správnou odpověď a podle potřeby upravte text možností i znění otázky. Schválením se otázka přepíše v testu.</p>';
            $html .= '</div>';
        } else {
            $html .= '<textarea name="upraveny_text" class="rdleg-textarea">' . esc_textarea($n->navrh_upravy) . '</textarea>';
        }
        $html .= '<div class="rdleg-actions">';
        $html .= '<button type="submit" name="rd_action" value="leg_schvalit" class="rdleg-btn rdleg-btn-schvalit" onclick="return confirm(\'Schválit a propsat tento text na web?\');"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Schválit a propsat na web</button>';
        if ($n->cilove_misto === 'novinka_uprava') {
            $html .= '<button type="submit" name="rd_action" value="leg_smazat_novinku" class="rdleg-btn rdleg-btn-smazat-novinku" onclick="return confirm(\'Smazat celou tuto novinku z webu, místo aby se opravil její text? Tahle akce maže novinku natrvalo, ne jen tento návrh.\');"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Smazat novinku z webu</button>';
        }
        $html .= '<button type="button" class="rdleg-btn rdleg-btn-zamitnout" onclick="document.getElementById(\'rdleg-note-' . intval($n->id) . '\').style.display=\'block\'"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Odmítnout</button>';
        $html .= '</div>';
        $html .= '<div class="rdleg-zamitnout-note" id="rdleg-note-' . intval($n->id) . '">';
        $html .= '<label class="rdleg-label">Poznámka k odmítnutí (proč tato změna není potřeba):</label>';
        $html .= '<p class="rdleg-hint">Poznámku uvidí hlavní admin ve výpisu. Po dobu do dalšího nahrání zákona se vám tento návrh znovu nepřipomene.</p>';
        $html .= '<textarea name="poznamka_zamitnuti" class="rdleg-textarea" style="min-height:70px" id="rdleg-poznamka-' . intval($n->id) . '"></textarea>';
        $html .= '<button type="submit" name="rd_action" value="leg_zamitnout_trvale" class="rdleg-btn rdleg-btn-zamitnout" onclick="var p=document.getElementById(\'rdleg-poznamka-' . intval($n->id) . '\'); if(!p.value.trim()){alert(\'Poznámka je povinná — napište prosím proč tuto změnu odmítáte.\'); p.focus(); return false;} return confirm(\'Odmítnout? Tato změna se vám už znovu nezobrazí, dokud admin nenahraje další verzi zákona.\');">Potvrdit odmítnutí</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '<p class="rdleg-footnote">Tato funkce je jen pomocník — návrh prosím vždy věcně ověřte před schválením.</p>';
        $html .= '</div>'; // .rdleg-card-inner
        $html .= '</div>'; // .rdleg-card
    }

    $html .= '</div>';

    // WYSIWYG editor — formátovací příkazy a vkládání barevných boxů, replikuje
    // přesně chování lektorské editace lekcí v hlavním pluginu (rdEdFmt/rdAddChip),
    // aby AI návrh vypadal a editoval se stejně, jako lektor zná z jiných částí webu.
    $html .= '<script>
    window.rdlegEdFmt = function(btn, cmd, val) {
        var wrap = btn.closest(".rdleg-editor-wrap");
        var editor = wrap.querySelector(".rdleg-editor-body");
        editor.focus();
        document.execCommand(cmd, false, val || null);
    };
    window.rdlegAddChip = function(btn, color) {
        var wrap = btn.closest(".rdleg-editor-wrap");
        var editor = wrap.querySelector(".rdleg-editor-body");
        var isBlue = color === "blue";
        var chip = document.createElement("div");
        chip.className = isBlue ? "rd-info-chip rd-chip-blue" : "rd-info-chip rd-chip-red";
        chip.style.cssText = isBlue
            ? "display:block;background:rgba(96,165,250,.12);border-left:3px solid #60a5fa;border-radius:6px;padding:10px 14px;margin:8px 0;color:#93c5fd;font-size:.88rem;line-height:1.5;min-height:1.5em"
            : "display:block;background:rgba(239,68,68,.12);border-left:3px solid #f87171;border-radius:6px;padding:10px 14px;margin:8px 0;color:#fca5a5;font-size:.88rem;line-height:1.5;min-height:1.5em";
        chip.setAttribute("contenteditable", "true");
        chip.textContent = isBlue ? "Informace..." : "Upozornění...";
        var btnWrap = document.createElement("div");
        btnWrap.setAttribute("contenteditable", "false");
        btnWrap.style.cssText = "display:flex;gap:6px;margin-top:6px";
        var exitBtn = document.createElement("button");
        exitBtn.type = "button";
        exitBtn.setAttribute("contenteditable", "false");
        exitBtn.textContent = "↵ Pokračovat v textu";
        exitBtn.style.cssText = "background:none;border:1px dashed currentColor;border-radius:4px;padding:2px 8px;font-size:.72rem;cursor:pointer;opacity:.6;color:inherit";
        exitBtn.addEventListener("click", function(e) {
            e.preventDefault(); e.stopPropagation();
            var p = document.createElement("p");
            p.innerHTML = "<br>";
            chip.parentNode.insertBefore(p, chip.nextSibling);
            var r = document.createRange();
            r.setStart(p, 0); r.collapse(true);
            var s = window.getSelection();
            s.removeAllRanges(); s.addRange(r);
            editor.focus();
        });
        var delBtn = document.createElement("button");
        delBtn.type = "button";
        delBtn.setAttribute("contenteditable", "false");
        delBtn.textContent = "✕ Odstranit pole";
        delBtn.style.cssText = "background:none;border:1px dashed currentColor;border-radius:4px;padding:2px 8px;font-size:.72rem;cursor:pointer;opacity:.6;color:inherit";
        delBtn.addEventListener("click", function(e) {
            e.preventDefault(); e.stopPropagation();
            chip.remove();
            editor.focus();
        });
        btnWrap.appendChild(exitBtn);
        btnWrap.appendChild(delBtn);
        chip.appendChild(btnWrap);
        editor.appendChild(chip);
        chip.focus();
        if (document.createRange) {
            var r2 = document.createRange();
            r2.setStart(chip.firstChild, 0);
            r2.setEnd(chip.firstChild, chip.firstChild.length);
            var s2 = window.getSelection();
            s2.removeAllRanges();
            s2.addRange(r2);
        }
    };
    // Inicializovat existující chipy (AI návrh už obsahuje HTML s rd-info-chip) —
    // doplní stejné ovládací prvky (Pokračovat/Odstranit), jaké mají nově vložené.
    function rdlegInitChips(editor) {
        editor.querySelectorAll(".rd-info-chip").forEach(function(chip) {
            if (chip.dataset.chipeditinit) return;
            chip.dataset.chipeditinit = "1";
            var isBlue = chip.classList.contains("rd-chip-blue");
            chip.setAttribute("contenteditable", "true");
            chip.style.cssText = isBlue
                ? "display:block;background:rgba(96,165,250,.12);border-left:3px solid #60a5fa;border-radius:6px;padding:10px 14px;margin:8px 0;color:#93c5fd;font-size:.88rem;line-height:1.5;min-height:1.5em"
                : "display:block;background:rgba(239,68,68,.12);border-left:3px solid #f87171;border-radius:6px;padding:10px 14px;margin:8px 0;color:#fca5a5;font-size:.88rem;line-height:1.5;min-height:1.5em";
            var btnWrap = document.createElement("div");
            btnWrap.setAttribute("contenteditable", "false");
            btnWrap.style.cssText = "display:flex;gap:6px;margin-top:6px";
            var exitBtn = document.createElement("button");
            exitBtn.type = "button";
            exitBtn.setAttribute("contenteditable", "false");
            exitBtn.textContent = "↵ Pokračovat v textu";
            exitBtn.style.cssText = "background:none;border:1px dashed currentColor;border-radius:4px;padding:2px 8px;font-size:.72rem;cursor:pointer;opacity:.6;color:inherit";
            exitBtn.addEventListener("click", function(e) {
                e.preventDefault(); e.stopPropagation();
                var p = document.createElement("p");
                p.innerHTML = "<br>";
                chip.parentNode.insertBefore(p, chip.nextSibling);
                var r = document.createRange();
                r.setStart(p, 0); r.collapse(true);
                var s = window.getSelection();
                s.removeAllRanges(); s.addRange(r);
                chip.closest(".rdleg-editor-body").focus();
            });
            var delBtn = document.createElement("button");
            delBtn.type = "button";
            delBtn.setAttribute("contenteditable", "false");
            delBtn.textContent = "✕ Odstranit pole";
            delBtn.style.cssText = "background:none;border:1px dashed currentColor;border-radius:4px;padding:2px 8px;font-size:.72rem;cursor:pointer;opacity:.6;color:inherit";
            delBtn.addEventListener("click", function(e) {
                e.preventDefault(); e.stopPropagation();
                var ed = chip.closest(".rdleg-editor-body");
                chip.remove();
                if (ed) ed.focus();
            });
            btnWrap.appendChild(exitBtn);
            btnWrap.appendChild(delBtn);
            chip.appendChild(btnWrap);
        });
    }
    function rdlegInitAllChips() {
        document.querySelectorAll(".rdleg-editor-body").forEach(function(editor) {
            rdlegInitChips(editor);
        });
    }
    rdlegInitAllChips();
    setTimeout(rdlegInitAllChips, 100);
    setTimeout(rdlegInitAllChips, 500);
    // Před odesláním formuláře vyčistit a synchronizovat obsah editoru do skryté textarea
    document.querySelectorAll(".rdleg-editor-wrap").forEach(function(wrap) {
        var form = wrap.closest("form");
        if (!form || form.dataset.rdlegSyncBound) return;
        form.dataset.rdlegSyncBound = "1";
        form.addEventListener("submit", function() {
            form.querySelectorAll(".rdleg-editor-wrap").forEach(function(w) {
                var editor = w.querySelector(".rdleg-editor-body");
                var ta = w.nextElementSibling;
                if (!editor || !ta || ta.tagName !== "TEXTAREA") return;
                var clone = editor.cloneNode(true);
                clone.querySelectorAll(".rd-info-chip").forEach(function(chip) {
                    chip.removeAttribute("contenteditable");
                    chip.removeAttribute("style");
                    chip.removeAttribute("data-chipeditinit");
                    chip.querySelectorAll("button").forEach(function(b) { b.remove(); });
                    chip.querySelectorAll("div[contenteditable=\"false\"]").forEach(function(d) { d.remove(); });
                });
                ta.value = clone.innerHTML;
            });
        }, true);
    });
    </script>';

    return $html;
}

/* ============================================================
   ORCHESTRACE (FÁZE 1): nahrání DOCX -> extrakce -> diff -> připravit dávky k zpracování
   Žádné AI volání zde — to se děje samostatně po dávkách (viz rdleg_zpracovat_jednu_davku),
   aby jedno nahrání nikdy nemohlo narazit na timeout webového serveru.
   ============================================================ */
/* ============================================================
   ULOŽENÍ ZNĚNÍ BEZ ROLE — jen uloží soubor, žádný diff, žádné AI
   ============================================================
   Nahrání souboru samo o sobě nic nespouští. Nový záznam se uloží s
   je_aktivni_reference=0 (zobrazí se jako "Znění před novelou", dokud ho
   uživatel v historii ručně nepřepne na "Aktuální znění" tlačítkem
   rdleg_nastavit_roli). Porovnání + AI vyhodnocení spustí uživatel
   samostatným tlačítkem až poté, co má jistotu, že obě znění (Aktuální /
   Před novelou) jsou v historii správně nastavená.
   ============================================================ */
function rdleg_ulozit_zneni_bez_role($plny_text, $nazev_souboru) {
    global $wpdb;

    $nove_paragrafy = rdleg_rozdelit_na_paragrafy($plny_text);
    if (empty($nove_paragrafy)) {
        return new WP_Error('rdleg_parse', 'V dokumentu se nepodařilo rozpoznat žádné paragrafy (očekává se formát "§123").');
    }

    $vlozeno = $wpdb->insert("{$wpdb->prefix}rd_leg_zneni", [
        'zakon_kod' => RDLEG_ZAKON_KOD,
        'nazev_souboru' => $nazev_souboru,
        'plny_text' => $plny_text,
        'paragrafy_json' => wp_json_encode($nove_paragrafy),
        'je_aktivni_reference' => 0,
        'nahral_uzivatel' => wp_get_current_user()->display_name ?: 'admin',
        'zmeny_json' => wp_json_encode([]),
        'davek_celkem' => 0,
        'davek_hotovo' => 0,
        'zpracovani_hotovo' => 1,
    ]);

    if ($vlozeno === false) {
        return new WP_Error('rdleg_insert_failed', 'Uložení znění do databáze selhalo: ' . $wpdb->last_error);
    }

    // UDRŽOVAT MAX TŘI ZÁZNAMY DOČASNĚ — protože role se nastavuje ručně AŽ
    // PO nahrání, dovolíme krátce 3 záznamy (2 stávající + nově nahraný), aby
    // uživatel mohl v klidu dokončit ruční přiřazení rolí. Definitivní úklid
    // na 2 záznamy dělá rdleg_spustit_porovnani() až při spuštění porovnání.
    $vsechna_id_serazena = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}rd_leg_zneni WHERE zakon_kod=%s ORDER BY nahrano DESC", RDLEG_ZAKON_KOD
    ));
    if (count($vsechna_id_serazena) > 3) {
        $id_ke_smazani = array_slice($vsechna_id_serazena, 3);
        $placeholders = implode(',', array_fill(0, count($id_ke_smazani), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rd_leg_zneni WHERE id IN ($placeholders)", $id_ke_smazani));
    }

    return ['zneni_id' => $wpdb->insert_id];
}

/* ============================================================
   SPUŠTĚNÍ POROVNÁNÍ — mezi dvěma JIŽ EXISTUJÍCÍMI záznamy znění,
   kterým uživatel ručně přiřadil role v historii. Žádné nahrávání souboru
   tady neprobíhá — to se stalo dřív (rdleg_ulozit_zneni_bez_role) a role
   se nastavily ručně (rdleg_nastavit_roli). Tato funkce udělá mechanický
   diff mezi "Aktuálním zněním" a "Zněním před novelou" a založí dávky
   k AI vyhodnocení u záznamu "Aktuální znění".
   ============================================================ */
function rdleg_spustit_porovnani() {
    global $wpdb;

    $aktualni_zaznam = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE zakon_kod=%s AND je_aktivni_reference=1 ORDER BY nahrano DESC LIMIT 1", RDLEG_ZAKON_KOD
    ));
    if (!$aktualni_zaznam) {
        return new WP_Error('rdleg_chybi_aktualni', 'Nejprve musí být v historii jeden záznam nastaven jako "Aktuální znění".');
    }
    $pred_novelou_zaznam = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE zakon_kod=%s AND je_aktivni_reference=0 ORDER BY nahrano DESC LIMIT 1", RDLEG_ZAKON_KOD
    ));

    $nove_paragrafy = json_decode($aktualni_zaznam->paragrafy_json, true);
    if (!is_array($nove_paragrafy)) $nove_paragrafy = [];

    // Bez "Znění před novelou" nemáme s čím porovnávat — VŠECHNY paragrafy se
    // posuzují jako 'novy', stejně jako při historicky prvním nahrání vůbec.
    if (!$pred_novelou_zaznam) {
        $zmeny = [];
        foreach ($nove_paragrafy as $cislo => $text) $zmeny[] = ['paragraf' => $cislo, 'typ' => 'novy', 'stary_text' => '', 'novy_text' => $text];
    } else {
        $stare_paragrafy = json_decode($pred_novelou_zaznam->paragrafy_json, true);
        if (!is_array($stare_paragrafy)) $stare_paragrafy = [];
        $zmeny = rdleg_diff_paragrafy($stare_paragrafy, $nove_paragrafy);
    }

    $davky = array_chunk($zmeny, RDLEG_DAVKA_VELIKOST);

    $wpdb->update("{$wpdb->prefix}rd_leg_zneni", [
        'zmeny_json' => wp_json_encode($zmeny),
        'davek_celkem' => count($davky),
        'davek_hotovo' => 0,
        'zpracovani_hotovo' => empty($zmeny) ? 1 : 0,
        'rezim_zpracovani' => 'novela',
    ], ['id' => $aktualni_zaznam->id]);

    // POJISTKA: dříve se tu mazala VEŠKERÁ historie (schválené/zamítnuté/smazané), ne jen
    // čekající návrhy — záměr byl, že AI v novém kole objeví problém znovu nezávisle na
    // staré historii. To je ale zbytečné: AI při rozhodování tabulku rd_leg_navrhy vůbec
    // nečte (posuzuje jen aktuální zákon vs. aktuální web), takže mazání historie pro tento
    // účel nic neřeší — jen ničí lektorovi přehled o tom, co už dřív rozhodl. Smazat jen
    // staré ČEKAJÍCÍ návrhy (ty by stejně nové kolo přepsalo); historie zůstává, dokud ji
    // lektor sám nesmaže tlačítkem "Smazat historii".
    $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
    // Automaticky smazat i historii vyřízených návrhů — čistý start pro nové kolo.
    $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('schvaleno','zamitnuto','smazano','zamitnuto_trvale')");
    // Stejně tak log "nic nenalezeno" z předchozího kola už neplatí — nové kolo ho postupně
    // znovu naplní (viz rdleg_ai_navrhnout_zmeny).
    delete_option('rdleg_vyhodnoceni_log');
    // Pojistka: pokud pro tohle znění zůstal z dřívější přerušené scoped kontroly (viz
    // rdleg_provest_kontrolu_znovu) záznam v rdleg_audit_scope, smazat ho — tohle běžné
    // porovnání novely má posuzovat úplně všechno, ne se nechat omezit starým scope.
    $audit_scope_novela = get_option('rdleg_audit_scope', []);
    if (is_array($audit_scope_novela) && isset($audit_scope_novela[$aktualni_zaznam->id])) {
        unset($audit_scope_novela[$aktualni_zaznam->id]);
        update_option('rdleg_audit_scope', $audit_scope_novela);
    }

    return [
        'zneni_id' => $aktualni_zaznam->id,
        'pocet_zmen_paragrafu' => count($zmeny),
        'pocet_davek' => count($davky),
        'prvni_nahrani' => !$pred_novelou_zaznam,
    ];
}

/* ============================================================
   ZNOVU PROVEDENÍ KONTROLY: použije poslední uložené znění zákona (žádné nové
   nahrání souboru) a posoudí VŠECHNY jeho paragrafy proti aktuálnímu stavu webu,
   stejně jako při prvním nahrání. Slouží k zachycení situací, kdy se web změnil
   nezávisle na zákonu (typicky "Obnovit výchozí" v hlavním pluginu přepsalo dřív
   schválenou legislativní úpravu) — výsledek je vždy čerstvý aktuální stav, žádná
   historie se neporovnává.
   $scope_cilove_misto: pokud je zadáno (jen z automatického hooku rd_obsah_resetovan,
   viz výše), kontrola se OMEZÍ na tohle jedno cilove_misto — promaže a znovu posoudí
   jen návrhy/historii TÉTO sekce, ostatní (lekce/FAQ/otázky/novinky), které reset
   nezasáhl, zůstanou nedotčené. Bez scope (manuální tlačítka v adminu) se promaže
   a posoudí úplně všechno, jako dřív.
   ============================================================ */
function rdleg_provest_kontrolu_znovu($rezim = 'audit', $scope_cilove_misto = null) {
    global $wpdb;

    $aktivni = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE zakon_kod=%s AND je_aktivni_reference=1 ORDER BY nahrano DESC LIMIT 1",
        RDLEG_ZAKON_KOD
    ));
    if (!$aktivni) {
        return new WP_Error('rdleg_zadne_zneni', 'Zatím nebylo nahráno žádné znění zákona, není co kontrolovat.');
    }

    $paragrafy = json_decode($aktivni->paragrafy_json, true);
    if (!is_array($paragrafy) || empty($paragrafy)) {
        return new WP_Error('rdleg_data', 'Uložené znění zákona má poškozená data, kontrolu nelze provést.');
    }

    $zmeny = [];
    foreach ($paragrafy as $cislo => $text) $zmeny[] = ['paragraf' => $cislo, 'typ' => 'novy', 'stary_text' => '', 'novy_text' => $text];
    $davky = array_chunk($zmeny, RDLEG_DAVKA_VELIKOST);

    $scope_pole = get_option('rdleg_audit_scope', []);
    if (!is_array($scope_pole)) $scope_pole = [];

    if ($scope_cilove_misto) {
        // Jen tahle jedna sekce — ostatní cilove_misto se nemažou ani znovu neposuzují.
        // $scope_cilove_misto může být jeden string nebo pole víc cílů (novinky sekce
        // ovlivňuje dva cíle zároveň: 'novinka' i 'novinka_uprava'). Jen ČEKAJÍCÍ — historie
        // (schváleno/zamítnuto/smazáno) zůstává, stejný princip jako u větve bez scope níže.
        $scope_cile = (array) $scope_cilove_misto;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin') AND cilove_misto IN (" . implode(',', array_fill(0, count($scope_cile), '%s')) . ")",
            $scope_cile
        ));
        $scope_pole[$aktivni->id] = $scope_cilove_misto;
        update_option('rdleg_audit_scope', $scope_pole);
    } else {
        // POJISTKA: dříve se tu mazala VEŠKERÁ historie (schválené/zamítnuté/smazané), ne jen
        // čekající návrhy — AI při rozhodování tabulku rd_leg_navrhy ale vůbec nečte (posuzuje
        // jen aktuální zákon vs. aktuální web), takže to nic neřešilo, jen to ničilo lektorovi
        // přehled o tom, co už dřív rozhodl (viz proč se §79b vracelo jako "nové"). Smazat jen
        // staré ČEKAJÍCÍ návrhy; historie zůstává, dokud ji lektor sám nesmaže.
        $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        // Automaticky smazat i historii — čistý start pro nové kolo.
        $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('schvaleno','zamitnuto','smazano','zamitnuto_trvale')");
        if (is_array($scope_pole) && isset($scope_pole[$aktivni->id])) {
            unset($scope_pole[$aktivni->id]);
            update_option('rdleg_audit_scope', $scope_pole);
        }
    }
    delete_option('rdleg_vyhodnoceni_log');

    $rezim_ulozit = ($rezim === 'komplet') ? 'komplet' : 'audit';
    $wpdb->update("{$wpdb->prefix}rd_leg_zneni", [
        'zmeny_json' => wp_json_encode($zmeny),
        'davek_celkem' => count($davky),
        'davek_hotovo' => 0,
        'zpracovani_hotovo' => empty($zmeny) ? 1 : 0,
        'rezim_zpracovani' => $rezim_ulozit,
    ], ['id' => $aktivni->id]);

    return [
        'zneni_id' => $aktivni->id,
        'pocet_zmen_paragrafu' => count($zmeny),
        'pocet_davek' => count($davky),
    ];
}

/* ============================================================
   ORCHESTRACE (FÁZE 2): zpracovat JEDNU dávku čekajícího znění.
   Volá se opakovaně z admin stránky (tlačítko "Zpracovat další dávku") nebo automaticky
   přes JS na pozadí — každé volání je jeden krátký AI request, nikdy nehrozí timeout.
   ============================================================ */
/* ============================================================
   POJISTKA PROTI NÁVRHŮM BEZE ZMĚNY: AI někdy navrhne text, který je po
   odstranění HTML/mezer a velikosti písmen TOTOŽNÝ s tím, co už na webu je
   (typicky když mylně usoudí, že něco chybí, ačkoliv to tam ve skutečnosti
   je — viz §79b během tohoto vývoje). Schválení takového návrhu by nic
   nezměnilo, jen by to zatěžovalo lektora zbytečnou kontrolou. Tahle funkce
   takový případ rozpozná PŘED uložením návrhu, aby se vůbec nevytvořil.
   Záměrně jen PŘESNÁ shoda po normalizaci — žádná fuzzy podobnost — aby
   nikdy nespadla pod stůl drobná, ale věcně důležitá oprava.
   ============================================================ */
function rdleg_normalizovat_pro_porovnani($text) {
    $cisty = wp_strip_all_tags((string) $text);
    $cisty = mb_strtolower($cisty);
    return trim(preg_replace('/\s+/u', ' ', $cisty));
}

// Vrátí true, pokud je novinka s daným názvem na admin-definovaném seznamu výjimek —
// ty se nikdy nenavrhují k úpravě, i když AI najde "nesoulad" s aktuálním zněním zákona.
// Porovnání je case-insensitive a ignoruje nadbytečné mezery.
function rdleg_je_vyloucena_novinka($nazev) {
    $seznam_raw = get_option('rdleg_vyloucene_novinky', '');
    if (empty(trim($seznam_raw))) return false;
    $nazev_norm = mb_strtolower(trim($nazev));
    foreach (explode("\n", $seznam_raw) as $radek) {
        if (mb_strtolower(trim($radek)) === $nazev_norm) return true;
    }
    return false;
}

function rdleg_navrh_je_beze_zmeny($cil_misto, $cil_id, $navrh_text) {
    if ($cil_misto === 'kviz_otazka') {
        $navrzeno = rdleg_parsovat_navrh_otazky($navrh_text);
        // Pokud AI nevrátila strukturovaný formát, nelze spolehlivě posoudit — neblokovat.
        if ($navrzeno['otazka'] === '' && $navrzeno['a'] === '') return false;
        $aktualni = rdleg_nacist_otazku_pole($cil_id);
        if (!$aktualni) return false;
        foreach (['otazka', 'a', 'b', 'c', 'd'] as $pole) {
            if (rdleg_normalizovat_pro_porovnani($navrzeno[$pole]) !== rdleg_normalizovat_pro_porovnani($aktualni[$pole])) return false;
        }
        return strtoupper($navrzeno['spravna']) === strtoupper($aktualni['spravna']);
    }
    if (in_array($cil_misto, ['lekce', 'faq', 'novinka_uprava'], true)) {
        $aktualni = rdleg_aktualni_obsah_cile($cil_misto, $cil_id);
        if ($aktualni === null) return false; // cíl neexistuje/nenalezen — to řeší jiná logika, ne tahle
        return rdleg_normalizovat_pro_porovnani($navrh_text) === rdleg_normalizovat_pro_porovnani($aktualni);
    }
    return false; // 'novinka' (vznik nové) nemá s čím srovnávat
}

function rdleg_zpracovat_jednu_davku($zneni_id) {
    global $wpdb;
    $zneni = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE id=%d", $zneni_id));
    if (!$zneni || $zneni->zpracovani_hotovo) return new WP_Error('rdleg_hotovo', 'Toto znění už je zpracované.');

    $vsechny_zmeny = json_decode($zneni->zmeny_json, true);
    if (!is_array($vsechny_zmeny)) return new WP_Error('rdleg_data', 'Uložená data o změnách jsou poškozená.');

    $davky = array_chunk($vsechny_zmeny, RDLEG_DAVKA_VELIKOST);

    // Pokud se RDLEG_DAVKA_VELIKOST mezi nahráním a tímto zpracováním změnila, uložené
    // davek_celkem už neodpovídá aktuálnímu přepočtu — opravíme ho, ale index NIKDY
    // neodhadujeme zpětně (dřívější výpočet byl nespolehlivý a mohl index posunout
    // za konec pole, což okamžitě nastavovalo zpracovani_hotovo=1 už po první dávce).
    // Bezpečný přístup: pokud se počet dávek změnil, začneme znovu od nuly — to v
    // nejhorším případě znovu zpracuje pár už hotových paragrafů (vznikne nadbytečný,
    // ne chybějící návrh), což je mnohem bezpečnější než předčasné přeskočení zbytku.
    if (count($davky) !== (int) $zneni->davek_celkem) {
        $wpdb->update("{$wpdb->prefix}rd_leg_zneni", [
            'davek_celkem' => count($davky),
            'davek_hotovo' => 0,
        ], ['id' => $zneni_id]);
        $index = 0;
    } else {
        $index = (int) $zneni->davek_hotovo;
    }
    // Bezpečnostní pojistka: index nikdy nesmí přesáhnout poslední platnou dávku.
    if ($index >= count($davky)) $index = max(0, count($davky) - 1);

    if (!isset($davky[$index])) {
        $wpdb->update("{$wpdb->prefix}rd_leg_zneni", ['zpracovani_hotovo' => 1], ['id' => $zneni_id]);
        $audit_scope_brzy = get_option('rdleg_audit_scope', []);
        if (is_array($audit_scope_brzy) && isset($audit_scope_brzy[$zneni_id])) {
            unset($audit_scope_brzy[$zneni_id]);
            update_option('rdleg_audit_scope', $audit_scope_brzy);
        }
        return ['hotovo' => true, 'pocet_navrhu_v_davce' => 0];
    }

    $davka = $davky[$index];
    $kontext_platformy = rdleg_sestavit_kontext_platformy();
    // POZNÁMKA: dříve byla zde zkouška sekvenčního rozpůlení dávky při odříznutí
    // odpovědi (volání AI 2-4x za sebou v jednom PHP requestu) — na produkci se ale
    // ukázalo jako nebezpečné: i samotné PRVNÍ volání na celou dávku mohlo narazit na
    // cURL timeout (180s) dřív, než se vůbec dostalo k rozhodnutí o rozpůlení, a tři
    // až čtyři volání po sobě v jednom requestu by garantovaně přesáhly časový limit
    // PHP na hostingu. Bezpečnější je trvale menší RDLEG_DAVKA_VELIKOST, kde KAŽDÉ
    // jednotlivé volání (jedno na AJAX request) je krátké a spolehlivé samo o sobě.
    $navrhy = rdleg_ai_navrhnout_zmeny($davka, $kontext_platformy, $zneni->rezim_zpracovani ?: 'novela');

    if (is_wp_error($navrhy)) return $navrhy;

    // POJISTKA NA ÚROVNI KÓDU (ne jen promptu): v 'komplet'/'audit' režimu se každý
    // paragraf posílá AI s natvrdo nastaveným typ='novy' (žádné srovnání s předchozí
    // verzí zákona zde neexistuje) — nelze tedy věcně rozlišit pravidlo platné 20 let
    // od skutečné novinky. Prompt instrukci o zákazu cíle 'novinka' v tomto režimu má,
    // ale promptová instrukce může selhat (viz historie téhle session) — proto i tady
    // zahodit jakýkoli 'novinka' návrh, pokud by ho AI i přesto vrátila.
    $rezim_pouziy = $zneni->rezim_zpracovani ?: 'novela';
    if (in_array($rezim_pouziy, ['komplet', 'audit'], true)) {
        $navrhy = array_values(array_filter($navrhy, function($nn) {
            return ($nn['cilove_misto'] ?? '') !== 'novinka';
        }));
    }

    // Pokud byla tahle kontrola spuštěná jako reakce na reset JEDNÉ sekce v hlavním
    // pluginu (viz rd_obsah_resetovan výše), omezit nálezy jen na tu sekci — nálezy
    // pro jiná cilove_misto by se týkaly obsahu, který reset nezasáhl, a nemělo by
    // smysl je teď znovu nabízet.
    $audit_scope = get_option('rdleg_audit_scope', []);
    $scope_pro_tohle_zneni = is_array($audit_scope) ? ($audit_scope[$zneni_id] ?? null) : null;
    if ($scope_pro_tohle_zneni) {
        $navrhy = array_values(array_filter($navrhy, function($nn) use ($scope_pro_tohle_zneni) {
            return in_array($nn['cilove_misto'] ?? '', (array) $scope_pro_tohle_zneni, true);
        }));
    }

    $pocet = 0;
    foreach ($navrhy as $nn) {
        if (empty($nn['cilove_misto'])) continue;
        // BEZPEČNOSTNÍ POJISTKA: AI někdy i přes instrukce vytvoří prvek s validním
        // cilove_misto, ale prázdným/triviálním navrh_upravy (typicky když sama usoudí,
        // že žádná změna není potřeba, ale "zapomene" celý prvek z odpovědi vynechat).
        // Takový návrh by lektor v UI viděl jako prázdný WYSIWYG editor bez obsahu —
        // přeskočit ho je lepší než uložit nepoužitelný návrh.
        $navrh_text_zkraceny = trim(wp_strip_all_tags($nn['navrh_upravy'] ?? ''));
        if (mb_strlen($navrh_text_zkraceny) < 5) continue;
        $puvodni = null;
        foreach ($davka as $d) if ($d['paragraf'] === ($nn['paragraf'] ?? '')) { $puvodni = $d; break; }
        $cil_misto = sanitize_text_field($nn['cilove_misto']);
        $cil_id = sanitize_text_field($nn['cilovy_identifikator'] ?? '');
        $par = sanitize_text_field($nn['paragraf'] ?? '');
        // DEDUPLIKACE: pokud už existuje čekající návrh pro stejný paragraf + stejné
        // cílové místo + stejný identifikátor, nevkládat další. AI (zvlášť v komplet
        // režimu, kde se posuzuje celý zákon) občas vytvoří víc téměř shodných návrhů
        // na totéž místo, formulovaných jen jinými slovy — lektora to zahltí. Necháme
        // první, ostatní zahodíme. Pro 'novinka' (cil_id prázdný) deduplikujeme podle
        // paragrafu + cílového místa, aby nevznikly dvě novinky o tomtéž paragrafu.
        $duplikat = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin') AND paragraf=%s AND cilove_misto=%s AND cilovy_identifikator=%s LIMIT 1",
            $par, $cil_misto, $cil_id
        ));
        if ($duplikat) continue;
        // POJISTKA: navrh_upravy je po normalizaci totožný s tím, co už na webu je —
        // schválení by nic nezměnilo, takový návrh vůbec neukládat (viz vysvětlení u
        // rdleg_navrh_je_beze_zmeny() výše).
        if (rdleg_navrh_je_beze_zmeny($cil_misto, $cil_id, $nn['navrh_upravy'] ?? '')) continue;
        // POJISTKA: novinka je na seznamu výjimek (admin ji záměrně vyloučil z AI kontroly)
        if ($cil_misto === 'novinka_uprava' && rdleg_je_vyloucena_novinka($cil_id)) continue;
        $typ_obsahu = (rdleg_aktualni_obsah_cile($cil_misto, $cil_id) === null) ? 'nova' : 'uprava';
        $wpdb->insert("{$wpdb->prefix}rd_leg_navrhy", [
            'zneni_id' => $zneni_id,
            'paragraf' => $par,
            'typ_zmeny' => $puvodni ? $puvodni['typ'] : 'zmena',
            'typ_obsahu' => $typ_obsahu,
            'stav' => 'ceka_admin', // čeká na adminovo schválení před zobrazením lektorovi
            'stary_text' => $puvodni ? $puvodni['stary_text'] : '',
            'novy_text' => $puvodni ? $puvodni['novy_text'] : '',
            'nazev_zmeny' => sanitize_text_field($nn['nazev_zmeny'] ?? ''),
            'shrnuti_zmeny' => sanitize_textarea_field($nn['shrnuti_zmeny'] ?? ''),
            'navrh_upravy' => wp_kses_post($nn['navrh_upravy'] ?? ''),
            'cilove_misto' => $cil_misto,
            'cilovy_identifikator' => $cil_id,
        ]);
        $pocet++;
    }

    $nove_hotovo = $index + 1;
    $hotovo_ted = ($nove_hotovo >= count($davky));
    $wpdb->update("{$wpdb->prefix}rd_leg_zneni", [
        'davek_hotovo' => $nove_hotovo,
        'zpracovani_hotovo' => $hotovo_ted ? 1 : 0,
    ], ['id' => $zneni_id]);
    if ($hotovo_ted && $scope_pro_tohle_zneni && is_array($audit_scope) && isset($audit_scope[$zneni_id])) {
        unset($audit_scope[$zneni_id]);
        update_option('rdleg_audit_scope', $audit_scope);
    }

    // POZNÁMKA: konsolidace lekcí se už NESPOUŠTÍ automaticky po poslední dávce —
    // dřív tu bylo volání rdleg_konsolidovat_navrhy_lekci(), které v jednom PHP
    // requestu (uvnitř tohoto AJAX volání) sekvenčně volalo AI pro KAŽDOU lekci se
    // 2+ čekajícími návrhy. U víc než jedné takové lekce to riskovalo timeout na
    // shared hostingu přesně stejným způsobem, jakému se davky vyhýbají díky
    // RDLEG_DAVKA_VELIKOST=1. Konsolidace je teď výhradně manuální akce v adminu
    // (tlačítko "Konsolidovat lekce"), postavená na vlastní bezpečné AJAX smyčce
    // (jedno volání = jedna lekce, viz rdleg_konsolidovat_jednu_lekci).

    return ['hotovo' => $nove_hotovo >= count($davky), 'pocet_navrhu_v_davce' => $pocet, 'davka_cislo' => $nove_hotovo, 'davek_celkem' => count($davky)];
}

/* ============================================================
   KONSOLIDACE NÁVRHŮ PRO LEKCE: pokud pro jednu lekci existuje více
   čekajících návrhů, sloučí je AI do jednoho uceleného textu.
   Volá se automaticky po dokončení poslední dávky. Ostatní cíle (novinka,
   faq, kviz_otazka) se nesloučují — každý je unikátní cíl.
   Vrací pole s detailním výsledkem pro diagnostiku.
   ============================================================ */
/**
 * Levný dotaz bez AI volání — zjistí, které lekce mají 2+ čekající návrhy
 * (tedy je u nich konsolidace potřeba). Používá se pro zobrazení tlačítka
 * a pro AJAX smyčku (jedno volání = jedna lekce), aby se nikdy neprovádělo
 * víc AI volání sekvenčně v jednom PHP requestu.
 */
function rdleg_ziskat_skupiny_ke_konsolidaci() {
    global $wpdb;
    $lekce_navrhy = $wpdb->get_results("
        SELECT cilovy_identifikator FROM {$wpdb->prefix}rd_leg_navrhy
        WHERE stav IN ('ceka','ceka_admin') AND cilove_misto='lekce'
    ");
    $pocty = [];
    foreach ($lekce_navrhy as $n) {
        $key = trim($n->cilovy_identifikator);
        if ($key === '') continue;
        $pocty[$key] = ($pocty[$key] ?? 0) + 1;
    }
    return array_filter($pocty, fn($p) => $p > 1);
}

/**
 * Zkonsoliduje JEDNU lekci (jedno AI volání) — voláno buď z AJAX handleru
 * (bezpečné, jedno volání na jeden HTTP request), nebo postupně z CLI/testů.
 * Vrací WP_Error při chybě, jinak pole s výsledkem.
 */
function rdleg_konsolidovat_jednu_lekci($cil_id) {
    global $wpdb;
    $navrhy = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}rd_leg_navrhy
        WHERE stav IN ('ceka','ceka_admin') AND cilove_misto='lekce' AND cilovy_identifikator=%s
        ORDER BY vytvoreno ASC
    ", $cil_id));

    if (count($navrhy) <= 1) {
        return ['slouceno' => false, 'duvod' => 'jediný_navrh', 'cil_id' => $cil_id];
    }

    $aktualni_html = rdleg_aktualni_html_cile('lekce', $cil_id);
    if ($aktualni_html === null) {
        return new WP_Error('rdleg_cil_nenalezen', "Lekce {$cil_id}: cíl nenalezen v DB");
    }

    $paragrafy_seznam = [];
    $prompt = "Máš za úkol sloučit několik navrhovaných úprav jedné lekce do jediného uceleného textu.\n\n"
        . "AKTUÁLNÍ TEXT LEKCE (HTML):\n" . $aktualni_html . "\n\n"
        . "NAVRHOVANÉ ÚPRAVY (každá z jiného paragrafu zákona):\n";
    foreach ($navrhy as $i => $n) {
        $paragrafy_seznam[] = $n->paragraf;
        $prompt .= "\n--- Návrh " . ($i + 1) . " (z " . $n->paragraf . ", shrnutí: " . $n->shrnuti_zmeny . ") ---\n"
            . $n->navrh_upravy . "\n";
    }
    $prompt .= "\nNAPIŠ VÝSLEDNÝ SLOUČENÝ TEXT LEKCE v HTML, který zapracovává všechny výše uvedené úpravy. "
        . "Zachovej stávající strukturu, formátování, tučné texty a informační boxy (div.rd-info-chip atd.) — "
        . "pouze uprav nebo doplň obsah tak, aby odpovídal všem legislativním změnám najednou. "
        . "DŮLEŽITÉ: Odpověz POUZE výsledným HTML textem lekce, bez jakéhokoli komentáře, vysvětlení nebo markdown obalů. "
        . "Text musí být kompletní — NIKDY ho nezkracuj, nepoužívej '...' ani '[zbytek textu zůstává stejný]'. "
        . "Pokud by text byl příliš dlouhý, raději vynech méně důležité části vlastního komentáře, ale HTML lekce musí být celé. "
        . "V textu lekce nezmiňuj jiné lekce (např. 'lekce 10 by také měla být upravena') — tato konsolidace se týká výhradně této jedné lekce.";

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 90,
        'headers' => [
            'x-api-key' => get_option('rd_ai_api_key', ''),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('rdleg_ai_chyba', "Lekce {$cil_id}: WP_Error — " . $response->get_error_message());
    }
    $http_kod = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $slouceny_text = trim($data['content'][0]['text'] ?? '');
    $slouceny_text = preg_replace('/^```[a-z]*\n?/i', '', $slouceny_text);
    $slouceny_text = preg_replace('/\n?```$/i', '', $slouceny_text);
    $slouceny_text = trim($slouceny_text);
    if (mb_strlen(wp_strip_all_tags($slouceny_text)) < 20) {
        $api_chyba = $data['error']['message'] ?? "HTTP {$http_kod}, prázdná odpověď";
        return new WP_Error('rdleg_ai_prazdno', "Lekce {$cil_id}: API selhalo — {$api_chyba}");
    }

    $slouceny_text = wp_kses_post($slouceny_text);

    // Pokud byl ALESPOŇ JEDEN z dílčích návrhů už odeslaný lektorovi (stav='ceka'),
    // zachovej tento stav i pro sloučený výsledek — jinak by konsolidace lektorovi
    // tiše smazala něco, co už viděl/čekal na schválení.
    $byl_odeslan_lektorovi = false;
    foreach ($navrhy as $n) {
        if ($n->stav === 'ceka') { $byl_odeslan_lektorovi = true; break; }
    }
    $vysledny_stav = $byl_odeslan_lektorovi ? 'ceka' : 'ceka_admin';

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin') AND cilove_misto='lekce' AND cilovy_identifikator=%s",
        $cil_id
    ));
    $prvni = $navrhy[0];
    $insert_ok = $wpdb->insert("{$wpdb->prefix}rd_leg_navrhy", [
        'zneni_id'            => $prvni->zneni_id,
        'paragraf'            => implode(', ', $paragrafy_seznam),
        'typ_zmeny'           => 'zmena',
        'typ_obsahu'          => 'uprava',
        'stav'                => $vysledny_stav,
        'stary_text'          => '',
        'novy_text'           => '',
        'nazev_zmeny'         => 'Sloučená úprava lekce (' . count($navrhy) . ' změny)',
        'shrnuti_zmeny'       => implode(' | ', array_map(fn($n) => $n->paragraf . ': ' . $n->shrnuti_zmeny, $navrhy)),
        'navrh_upravy'        => $slouceny_text,
        'cilove_misto'        => 'lekce',
        'cilovy_identifikator' => $cil_id,
    ]);
    if ($insert_ok === false) {
        return new WP_Error('rdleg_db_chyba', "Lekce {$cil_id}: uložení sloučeného návrhu do DB selhalo — " . $wpdb->last_error);
    }

    return ['slouceno' => true, 'cil_id' => $cil_id, 'pocet_navrhu' => count($navrhy)];
}

/**
 * Zpětně kompatibilní hromadná varianta — projde VŠECHNY skupiny sekvenčně
 * v jednom PHP requestu. POZOR: u víc než ~1 skupiny riskuje timeout na
 * shared hostingu (viz RDLEG_DAVKA_VELIKOST=1 a komentář u dávek zákona).
 * Ponecháno jen pro interní/CLI použití — admin UI používá AJAX smyčku
 * (rdleg_konsolidovat_jednu_lekci) přes jedno volání na jednu lekci.
 */
function rdleg_konsolidovat_navrhy_lekci() {
    $vysledky = ['skupiny' => [], 'slouceno' => 0, 'preskoceno' => 0, 'chyby' => []];
    $skupiny = rdleg_ziskat_skupiny_ke_konsolidaci();
    if (empty($skupiny)) return $vysledky;

    foreach ($skupiny as $cil_id => $pocet) {
        $vysledky['skupiny'][$cil_id] = $pocet;
        $vysledek = rdleg_konsolidovat_jednu_lekci($cil_id);
        if (is_wp_error($vysledek)) {
            $vysledky['chyby'][] = $vysledek->get_error_message();
        } elseif (!empty($vysledek['slouceno'])) {
            $vysledky['slouceno']++;
        } else {
            $vysledky['preskoceno']++;
        }
    }
    return $vysledky;
}


/* ============================================================
   KONTROLA TESTU PODLE LEKCÍ: projde všechny testové otázky a porovná je
   s aktuálním obsahem lekcí — navrhne úpravy otázek/odpovědí, které jsou
   v rozporu s tím, co lekce říkají. Zdrojem pravdy jsou lekce, ne zákon.
   ============================================================ */
function rdleg_zkontrolovat_test_podle_lekci() {
    global $wpdb;

    // Načíst lekce přes fallback-aware funkci
    if (!function_exists('rd_lekce_source') || !function_exists('rd_otazky_source')) {
        return new WP_Error('rdleg_chybi_funkce', 'Hlavní plugin není dostupný — funkce rd_lekce_source() nebo rd_otazky_source() chybí.');
    }

    $lekce = rd_lekce_source();
    $otazky = rd_otazky_source(9999); // všechny otázky

    if (empty($lekce)) return new WP_Error('rdleg_prazdne_lekce', 'Nepodařilo se načíst obsah lekcí.');
    if (empty($otazky)) return new WP_Error('rdleg_prazdne_otazky', 'Nepodařilo se načíst testové otázky.');

    // Sestavit prompt
    $lekce_text = '';
    foreach ($lekce as $i => $l) {
        $lekce_text .= "\n=== Lekce " . ($i + 1) . ": " . $l['nazev'] . " ===\n"
            . wp_strip_all_tags($l['obsah'] ?? '') . "\n";
    }

    $otazky_text = '';
    foreach ($otazky as $o) {
        $otazky_text .= "\nOTAZKA: " . $o['otazka']
            . "\nA: " . ($o['moznosti']['A'] ?? '') . "\nB: " . ($o['moznosti']['B'] ?? '')
            . "\nC: " . ($o['moznosti']['C'] ?? '') . "\nD: " . ($o['moznosti']['D'] ?? '')
            . "\nSPRAVNA: " . ($o['spravna'] ?? '') . "\n";
    }

    $prompt = "Jsi kontrolor vzdělávacího obsahu. Máš k dispozici texty lekcí kurzu referentských řidičů a testové otázky.\n\n"
        . "ZDROJEM PRAVDY jsou POUZE lekce — testové otázky musí odpovídat tomu, co lekce říkají.\n\n"
        . "LEKCE KURZU:\n" . $lekce_text . "\n\n"
        . "TESTOVÉ OTÁZKY:\n" . $otazky_text . "\n\n"
        . "Projdi každou otázku a zkontroluj:\n"
        . "1. Zda je správná odpověď skutečně správná podle lekcí\n"
        . "2. Zda formulace otázky nebo odpovědí neodporuje tomu, co lekce říkají\n"
        . "3. Zda číselné hodnoty, limity a pravidla v otázkách odpovídají lekcím\n\n"
        . "Vrať POUZE otázky, které je potřeba upravit. Pro každou vráť JSON objekt v tomto formátu:\n"
        . "[\n"
        . "  {\n"
        . "    \"otazka_puvodni\": \"přesné znění původní otázky\",\n"
        . "    \"otazka\": \"nové znění otázky\",\n"
        . "    \"a\": \"možnost A\", \"b\": \"možnost B\", \"c\": \"možnost C\", \"d\": \"možnost D\",\n"
        . "    \"spravna\": \"A\",\n"
        . "    \"duvod\": \"stručné vysvětlení, co bylo špatně a podle které lekce\"\n"
        . "  }\n"
        . "]\n"
        . "Pokud jsou všechny otázky správně, vrať prázdné pole []. Odpověz POUZE validním JSON, nic jiného.";

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 120,
        'headers' => [
            'x-api-key'         => get_option('rd_ai_api_key', ''),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 4000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $raw = trim($data['content'][0]['text'] ?? '');
    $raw = preg_replace('/^```json\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);
    $navrhy_ai = json_decode($raw, true);

    if (!is_array($navrhy_ai)) {
        return new WP_Error('rdleg_parse', 'AI vrátila neplatný JSON: ' . substr($raw, 0, 200));
    }

    $pocet = 0;
    foreach ($navrhy_ai as $nn) {
        if (empty($nn['otazka_puvodni']) || empty($nn['otazka'])) continue;

        // Najít ID otázky podle původního znění
        $otazka_id = null;
        foreach ($otazky as $o) {
            if (trim($o['otazka']) === trim($nn['otazka_puvodni'])) {
                $otazka_id = $o['id'] ?? null;
                break;
            }
        }
        if (!$otazka_id) continue; // nenalezeno — přeskočit

        // Sestavit navrh_upravy ve formátu OTAZKA:/A:/... který rdleg_aplikovat_navrh() zná
        $sp = strtoupper(trim($nn['spravna'] ?? 'A'));
        if (!in_array($sp, ['A','B','C','D'], true)) $sp = 'A';
        $navrh_text = "OTAZKA: " . trim($nn['otazka']) . "\n"
            . "A: " . trim($nn['a'] ?? '') . "\n"
            . "B: " . trim($nn['b'] ?? '') . "\n"
            . "C: " . trim($nn['c'] ?? '') . "\n"
            . "D: " . trim($nn['d'] ?? '') . "\n"
            . "SPRAVNA: " . $sp;

        if (rdleg_navrh_je_beze_zmeny('kviz_otazka', (string) $otazka_id, $navrh_text)) continue;

        // Deduplikace
        $duplikat = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin') AND cilove_misto='kviz_otazka' AND cilovy_identifikator=%s LIMIT 1",
            (string) $otazka_id
        ));
        if ($duplikat) continue;

        $wpdb->insert("{$wpdb->prefix}rd_leg_navrhy", [
            'zneni_id'             => 0, // kontrola testu není vázaná na konkrétní znění zákona
            'paragraf'             => 'Test',
            'typ_zmeny'            => 'zmena',
            'typ_obsahu'           => 'uprava',
            'stav'                 => 'ceka_admin',
            'stary_text'           => '',
            'novy_text'            => '',
            'nazev_zmeny'          => 'Úprava testové otázky',
            'shrnuti_zmeny'        => sanitize_textarea_field($nn['duvod'] ?? ''),
            'navrh_upravy'         => sanitize_textarea_field($navrh_text),
            'cilove_misto'         => 'kviz_otazka',
            'cilovy_identifikator' => (string) $otazka_id,
        ]);
        $pocet++;
    }

    return ['pocet_navrhu' => $pocet];
}


/* ============================================================
   JEDNORÁZOVÁ MIGRACE: vyčistí z historie schválené záznamy, jejichž citace na webu
   už neexistuje (typicky kvůli resetu provedenému ještě před zavedením automatického
   čištění výše). Spustí se jen jednou, hlídá si to přes verzovanou option — po
   spuštění se znovu nezopakuje, ani po reaktivaci pluginu.
   ============================================================ */
add_action('admin_init', function() {
    if (get_option('rdleg_migrace_vycisteni_v1')) return;
    if (!rdleg_hlavni_plugin_pripraven()) return;

    global $wpdb;
    $schvalene = $wpdb->get_results("SELECT id, cilove_misto, cilovy_identifikator, nazev_zmeny FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav='schvaleno'");
    foreach ($schvalene as $s) {
        $existuje = null;
        if ($s->cilove_misto === 'faq' && $s->cilovy_identifikator) {
            $existuje = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_faq WHERE otazka=%s", $s->cilovy_identifikator));
        } elseif ($s->cilove_misto === 'lekce' && $s->cilovy_identifikator) {
            $lekce_id = rdleg_najit_lekce_id($s->cilovy_identifikator);
            $existuje = $lekce_id ? $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_lekce WHERE id=%d", $lekce_id)) : null;
        } elseif ($s->cilove_misto === 'novinka') {
            $hledany_nazev = $s->nazev_zmeny ?: 'Změna v zákoně č. 361/2000 Sb.';
            $existuje = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rd_novinky WHERE nazev=%s LIMIT 1", $hledany_nazev));
        } elseif ($s->cilove_misto === 'novinka_uprava' && $s->cilovy_identifikator) {
            $existuje = rdleg_najit_novinku_id($s->cilovy_identifikator);
        } else {
            continue; // kviz_otazka se nikam automaticky nezapisuje, tady nemáme co ověřovat
        }
        if (!$existuje) {
            $wpdb->delete("{$wpdb->prefix}rd_leg_navrhy", ['id' => $s->id]);
        }
    }

    update_option('rdleg_migrace_vycisteni_v1', 1);
});

/* ============================================================
   JEDNORÁZOVÁ MIGRACE: doplní sloupec typ_obsahu do existující tabulky (dbDelta umí
   přidat chybějící sloupec, aniž by se musel plugin deaktivovat/reaktivovat) a zpětně
   dopočítá jeho hodnotu pro záznamy, které vznikly ještě před zavedením tohoto sloupce
   — ty mají defaultně 'uprava', což pro skutečně nově vytvořený obsah (typicky
   novinky, kde nic podobného na webu předtím nebylo) není správně.
   ============================================================ */
add_action('admin_init', function() {
    if (get_option('rdleg_migrace_typ_obsahu_v2')) return;
    if (!rdleg_hlavni_plugin_pripraven()) return;

    global $wpdb;
    $tabulka = $wpdb->prefix . 'rd_leg_navrhy';

    // Tabulka rd_leg_navrhy musí už existovat (vytváří se při aktivaci pluginu) —
    // pokud neexistuje vůbec, není co měnit, a NESMÍME si tu chybu splést s úspěchem.
    if ($wpdb->get_var("SHOW TABLES LIKE '{$tabulka}'") !== $tabulka) return;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$tabulka} (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        zneni_id mediumint(9) NOT NULL,
        paragraf varchar(50) DEFAULT '',
        typ_zmeny varchar(20) NOT NULL DEFAULT 'zmena',
        typ_obsahu varchar(20) NOT NULL DEFAULT 'uprava',
        stav varchar(20) NOT NULL DEFAULT 'ceka',
        stary_text longtext,
        novy_text longtext,
        nazev_zmeny varchar(255) DEFAULT '',
        shrnuti_zmeny text,
        navrh_upravy longtext,
        cilove_misto varchar(100) DEFAULT '',
        cilovy_identifikator varchar(255) DEFAULT '',
        upraveny_text_lektorem longtext,
        poznamka_zamitnuti text,
        vytvoreno datetime DEFAULT CURRENT_TIMESTAMP,
        vyreseno datetime DEFAULT NULL,
        vyresil_kdo varchar(255) DEFAULT '',
        PRIMARY KEY (id)
    ) $c;");

    // OVĎŘENÍ: dbDelta() může v některých konfiguracích (chybějící oprávnění, neobvyklý
    // formát existující tabulky) tiše neudělat nic. Než zapíšeme "migrace hotová",
    // musíme si být jistí, že sloupec skutečně existuje — jinak by se tahle migrace
    // už NIKDY nezkusila znovu, a problém by zůstal navždy nevyřešený a neviditelný.
    $sloupec_existuje = $wpdb->get_var("SHOW COLUMNS FROM {$tabulka} LIKE 'typ_obsahu'");
    if (!$sloupec_existuje) return; // zkusí se znovu při příští návštěvě admin stránky

    $vsechny = $wpdb->get_results("SELECT id, cilove_misto, cilovy_identifikator FROM {$tabulka}");
    foreach ($vsechny as $z) {
        $typ_obsahu = (rdleg_aktualni_obsah_cile($z->cilove_misto, $z->cilovy_identifikator) === null) ? 'nova' : 'uprava';
        $wpdb->update($tabulka, ['typ_obsahu' => $typ_obsahu], ['id' => $z->id]);
    }

    update_option('rdleg_migrace_typ_obsahu_v2', 1);
});

/* ============================================================
   JEDNORÁZOVÁ MIGRACE: doplní chybějící sloupce do rd_leg_zneni. Na produkčním
   serveru se ukázalo (diagnostika v1.19.0), že tabulka byla vytvořena se starším
   schématem — chyběly sloupce davek_celkem/davek_hotovo/zpracovani_hotovo/zmeny_json
   (přesný rozsah neznámý, proto se každý sloupec ověřuje a doplňuje samostatně, ne
   spoléháním na dbDelta(), které se na tomhle hostingu už jednou neosvědčilo —
   viz migrace typ_obsahu výše). ADD COLUMN se použije jen u sloupců, které skutečně
   chybí, aby nehrozila chyba "Duplicate column" u těch, co případně existují.
   ============================================================ */
add_action('admin_init', function() {
    if (get_option('rdleg_migrace_zneni_sloupce_v2')) return;
    if (!rdleg_hlavni_plugin_pripraven()) return;

    global $wpdb;
    $tabulka = $wpdb->prefix . 'rd_leg_zneni';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$tabulka}'") !== $tabulka) return;

    $pozadovane_sloupce = [
        'zakon_kod' => "varchar(50) NOT NULL DEFAULT ''",
        'nazev_souboru' => "varchar(255) NOT NULL DEFAULT ''",
        'plny_text' => "longtext",
        'paragrafy_json' => "longtext",
        'je_aktivni_reference' => "tinyint(1) NOT NULL DEFAULT 1",
        'nahrano' => "datetime DEFAULT CURRENT_TIMESTAMP",
        'nahral_uzivatel' => "varchar(255) DEFAULT ''",
        'zmeny_json' => "longtext",
        'davek_celkem' => "int NOT NULL DEFAULT 0",
        'davek_hotovo' => "int NOT NULL DEFAULT 0",
        'zpracovani_hotovo' => "tinyint(1) NOT NULL DEFAULT 1",
        'rezim_zpracovani' => "varchar(20) NOT NULL DEFAULT 'novela'",
    ];

    $existujici = $wpdb->get_results("SHOW COLUMNS FROM {$tabulka}");
    $existujici_nazvy = array_map(function($c) { return $c->Field; }, $existujici);

    foreach ($pozadovane_sloupce as $nazev => $definice) {
        if (!in_array($nazev, $existujici_nazvy, true)) {
            $wpdb->query("ALTER TABLE {$tabulka} ADD COLUMN {$nazev} {$definice}");
        }
    }

    // OVĎŘENÍ: znovu zkontrolovat sloupce po ALTER — jen pokud VŠECHNY požadované
    // sloupce skutečně existují, zapíšeme migraci jako hotovou. Jinak se zkusí znovu
    // při příští návštěvě admin stránky.
    $po_alteru = $wpdb->get_results("SHOW COLUMNS FROM {$tabulka}");
    $po_alteru_nazvy = array_map(function($c) { return $c->Field; }, $po_alteru);
    $chybi = array_diff(array_keys($pozadovane_sloupce), $po_alteru_nazvy);
    if (!empty($chybi)) return;

    update_option('rdleg_migrace_zneni_sloupce_v2', 1);
});

/* ============================================================
   JEDNORÁZOVÁ MIGRACE: vyčistí z UŽ ULOŽENÉHO obsahu (lekce, FAQ, novinky) atribut
   data-chipeditinit — ten je čistě DOČASNÝ stav editoru rd-info-chip boxů (značí
   JavaScriptu, že tlačítka "Pokračovat"/"Odstranit" už byla u chipu vykreslená, ať je
   nevykresluje podruhé). Dřív byl navíc i explicitně povolený ve wp_kses_post() filtru
   (viz výše), takže ani server-side sanitizace při schválení návrhu ho nezachytila —
   unikl tak až do uloženého HTML (zjištěno u lekce #234). JS úklid před odesláním
   formuláře i kses filtr jsou teď opravené (atribut se už znovu neuloží), tahle migrace
   jen dočistí, co tam zůstalo z minula.
   ============================================================ */
add_action('admin_init', function() {
    if (get_option('rdleg_migrace_chipeditinit_v1')) return;
    if (!rdleg_hlavni_plugin_pripraven()) return;

    global $wpdb;
    $vzor = '/\s*data-chipeditinit=(["\'])[^"\']*\1/';

    foreach ([
        ['tabulka' => $wpdb->prefix . 'rd_lekce', 'sloupec' => 'obsah'],
        ['tabulka' => $wpdb->prefix . 'rd_faq', 'sloupec' => 'odpoved'],
        ['tabulka' => $wpdb->prefix . 'rd_novinky', 'sloupec' => 'obsah'],
    ] as $cil) {
        if ($wpdb->get_var("SHOW TABLES LIKE '{$cil['tabulka']}'") !== $cil['tabulka']) continue;
        $radky = $wpdb->get_results("SELECT id, {$cil['sloupec']} AS obsah FROM {$cil['tabulka']}");
        foreach ($radky as $r) {
            if ($r->obsah === null || strpos($r->obsah, 'data-chipeditinit') === false) continue;
            $vycisteny = preg_replace($vzor, '', $r->obsah);
            if ($vycisteny !== $r->obsah) {
                $wpdb->update($cil['tabulka'], [$cil['sloupec'] => $vycisteny], ['id' => $r->id]);
            }
        }
    }

    update_option('rdleg_migrace_chipeditinit_v1', 1);
});

add_action('admin_init', function() {
    if (get_option('rdleg_migrace_paragraf_varchar_v2')) return;
    global $wpdb;
    // Rozšíření varchar(50) → varchar(500): nestačí pro sloučené návrhy s více paragrafy
    $tabulka = $wpdb->prefix . 'rd_leg_navrhy';
    $existuje = $wpdb->get_var("SHOW TABLES LIKE '{$tabulka}'");
    if ($existuje) {
        $wpdb->suppress_errors(true);
        $wpdb->query("ALTER TABLE {$tabulka} MODIFY COLUMN paragraf varchar(500) NOT NULL DEFAULT ''");
        $wpdb->suppress_errors(false);
    }
    update_option('rdleg_migrace_paragraf_varchar_v2', 1);
});

/* ============================================================
   AJAX ENDPOINT: zpracuje jednu dávku a vrátí JSON, aby ji mohl JavaScript v admin
   stránce volat opakovaně na pozadí bez nutnosti reloadovat stránku po každém kliknutí.
   Používá stejnou funkci a stejný nonce mechanismus jako klasický formulář s tlačítkem
   "Zpracovat další dávku" — ten zůstává funkční jako fallback, pokud má uživatel
   vypnutý JavaScript.
   ============================================================ */
add_action('wp_ajax_rdleg_zpracovat_davku_ajax', function() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Přístup odepřen'], 403);
    check_ajax_referer('rdleg_davka_nonce', 'nonce');

    $zneni_id = intval($_POST['zneni_id'] ?? 0);
    $vysledek = rdleg_zpracovat_jednu_davku($zneni_id);

    if (is_wp_error($vysledek)) {
        wp_send_json_error(['message' => $vysledek->get_error_message()]);
    }
    wp_send_json_success($vysledek);
});

add_action('wp_ajax_rdleg_konsolidovat_lekci_ajax', function() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Přístup odepřen'], 403);
    check_ajax_referer('rdleg_konsolidovat_ajax_nonce', 'nonce');

    $cil_id = sanitize_text_field($_POST['cil_id'] ?? '');
    if ($cil_id === '') wp_send_json_error(['message' => 'Chybí identifikátor lekce.']);

    $vysledek = rdleg_konsolidovat_jednu_lekci($cil_id);
    if (is_wp_error($vysledek)) {
        wp_send_json_error(['message' => $vysledek->get_error_message()]);
    }
    wp_send_json_success($vysledek);
});

/* ============================================================
   ADMIN STRÁNKA — Nástroje -> RefDrive Legislativa
   ============================================================ */
add_action('admin_menu', function() {
    add_management_page(
        'RefDrive — AI Sledování legislativy',
        'RefDrive Legislativa',
        'manage_options',
        'rdleg-sledovani',
        'rdleg_admin_stranka'
    );
});

function rdleg_admin_css() {
    return '<style>
.rdleg-wrap{max-width:1100px}
.rdleg-admin-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-top:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.rdleg-admin-card h2{margin-top:0;font-size:16px;font-weight:700;color:#111827}
.rdleg-admin-card p.desc{color:#6b7280;font-size:13px;margin-top:-4px}
.rdleg-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0}
.rdleg-switch{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.rdleg-switch input{opacity:0;width:0;height:0}
.rdleg-slider{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:99px;transition:.2s}
.rdleg-slider:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.rdleg-switch input:checked + .rdleg-slider{background:#7c3aed}
.rdleg-switch input:checked + .rdleg-slider:before{transform:translateX(20px)}
.rdleg-btn-primary{background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer}
.rdleg-btn-primary:hover{background:#6d28d9}
.rdleg-btn-secondary{background:#fff;color:#374151;border:1px solid #d1d5db;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer}
.rdleg-btn-secondary:hover{background:#f9fafb}
.rdleg-dropzone{border:2px dashed #d1d5db;border-radius:10px;padding:28px;text-align:center;background:#fafafa}
.rdleg-dropzone input[type=file]{margin:10px auto}
.rdleg-stat{display:flex;gap:24px;margin-top:6px}
.rdleg-stat-item{display:flex;flex-direction:column}
.rdleg-stat-num{font-size:22px;font-weight:800;color:#111827}
.rdleg-stat-label{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.rdleg-navrh-list{display:flex;flex-direction:column;gap:0}
.rdleg-navrh-item{border-bottom:1px solid #f3f4f6}
.rdleg-navrh-item:last-child{border-bottom:none}
.rdleg-navrh-summary{cursor:pointer;padding:12px 4px;display:grid;grid-template-columns:70px 1fr 130px 180px;gap:10px;align-items:center;font-size:13px;list-style:none}
.rdleg-navrh-summary-vyber{grid-template-columns:24px 70px 1fr 130px 180px}
.rdleg-navrh-checkbox{width:16px;height:16px;cursor:pointer}
.rdleg-navrh-summary::-webkit-details-marker{display:none}
.rdleg-navrh-par{font-weight:600;color:#111827}
.rdleg-navrh-cil{color:#6b7280;font-size:12px}
.rdleg-navrh-detail{padding:4px 4px 18px}
.rdleg-navrh-summary-hist{opacity:.85}
.rdleg-admin-t{width:100%;border-collapse:collapse;font-size:13px}
.rdleg-admin-t th{background:#f9fafb;padding:9px 12px;text-align:left;font-weight:600;font-size:11px;color:#374151;border-bottom:2px solid #e5e7eb;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.rdleg-admin-t td{padding:10px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.rdleg-admin-t tr:hover td{background:#fafafa}
.rdleg-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700}
.rdleg-badge-obsah-nova{color:#15803d;background:#dcfce7}
.rdleg-badge-obsah-uprava{color:#b45309;background:#fef3c7}
@media(max-width:782px){
    .rdleg-navrh-summary{grid-template-columns:1fr;gap:4px}
    .rdleg-navrh-cil{order:4}
}
</style>';
}

function rdleg_admin_stranka() {
    if (!current_user_can('manage_options')) wp_die('Přístup odepřen');
    global $wpdb;

    $rdleg_aktivni = (int) get_option('rdleg_aktivni', 1);
    $rdleg_lektor_viditelnost = (int) get_option('rdleg_lektor_viditelnost', 1);
    $hlaseni = [];

    // Vypínače
    if (isset($_POST['rdleg_ulozit_nastaveni']) && check_admin_referer('rdleg_nastaveni_nonce')) {
        update_option('rdleg_aktivni', isset($_POST['rdleg_aktivni']) ? 1 : 0);
        update_option('rdleg_lektor_viditelnost', isset($_POST['rdleg_lektor_viditelnost']) ? 1 : 0);
        update_option('rdleg_vlastni_instrukce', sanitize_textarea_field($_POST['rdleg_vlastni_instrukce'] ?? ''));
        update_option('rdleg_vyloucene_novinky', sanitize_textarea_field($_POST['rdleg_vyloucene_novinky'] ?? ''));
        $rdleg_aktivni = (int) get_option('rdleg_aktivni', 1);
        $rdleg_lektor_viditelnost = (int) get_option('rdleg_lektor_viditelnost', 1);
        $hlaseni[] = ['typ' => 'success', 'text' => 'Nastavení uloženo.'];
    }

    // Nahrání DOCX — pouze uloží soubor jako nový záznam s nepřiřazenou rolí.
    // Roli (Aktuální / Před novelou) i spuštění porovnání + AI vyhodnocení dělá
    // uživatel výslovně, ručně, v sekci "Historie nahraných znění" níže.
    if (isset($_POST['rdleg_nahrat']) && check_admin_referer('rdleg_nahrat_nonce')) {
        $pozastavena_handler = get_option('rdleg_pozastavena_zneni', []);
        if (!is_array($pozastavena_handler)) $pozastavena_handler = [];
        $rozpracovana_id_handler = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo=0");
        $rozpracovanych = count(array_filter($rozpracovana_id_handler, function($id) use ($pozastavena_handler) {
            return !isset($pozastavena_handler[$id]);
        }));
        if (!$rdleg_aktivni) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Funkce je vypnutá — nejprve ji zapněte v nastavení níže.'];
        } elseif ($rozpracovanych > 0) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nelze nahrát nové znění — předchozí AI vyhodnocení ještě neskončilo. Nejprve dokončete zpracování dávek níže.'];
        } elseif (empty($_FILES['rdleg_docx']['tmp_name'])) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nebyl vybrán žádný soubor.'];
        } else {
            $plny_text = rdleg_extrahovat_docx($_FILES['rdleg_docx']['tmp_name']);
            if (is_wp_error($plny_text)) {
                $hlaseni[] = ['typ' => 'error', 'text' => $plny_text->get_error_message()];
            } else {
                $vysledek = rdleg_ulozit_zneni_bez_role($plny_text, sanitize_file_name($_FILES['rdleg_docx']['name']));
                if (is_wp_error($vysledek)) {
                    $hlaseni[] = ['typ' => 'error', 'text' => $vysledek->get_error_message()];
                } else {
                    $hlaseni[] = ['typ' => 'success', 'text' => 'Soubor uložen. V sekci "Historie nahraných znění" níže mu nastavte roli (Aktuální znění / Znění před novelou).'];
                }
            }
        }
    }

    // Zpracovat jednu dávku rozpracovaného znění (AI vyhodnocení) — krátká operace,
    // nikdy nehrozí timeout. Volá se opakovaně, dokud nejsou všechny dávky hotové.
    if (isset($_POST['rdleg_zpracovat_davku']) && check_admin_referer('rdleg_davka_nonce')) {
        $zneni_id = intval($_POST['zneni_id'] ?? 0);
        $vysledek = rdleg_zpracovat_jednu_davku($zneni_id);
        if (is_wp_error($vysledek)) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Dávka se nezdařila: ' . $vysledek->get_error_message()];
        } elseif ($vysledek['hotovo']) {
            $hlaseni[] = ['typ' => 'success', 'text' => 'Zpracování dokončeno — všechny dávky vyhodnoceny.'];
        } else {
            $hlaseni[] = ['typ' => 'success', 'text' => "Dávka {$vysledek['davka_cislo']}/{$vysledek['davek_celkem']} hotová — nalezeno {$vysledek['pocet_navrhu_v_davce']} návrhů. Klikněte znovu pro pokračování."];
        }
    }


    // Znovu provést kontrolu posledního nahraného zákona proti aktuálnímu stavu webu —
    // zachytí situace, kdy se obsah webu změnil nezávisle na zákonu (typicky "Obnovit
    // výchozí" v hlavním pluginu přepsalo dříve schválenou legislativní úpravu).
    // Smazání archivního (neaktivního) znění z historie — typicky poškozené/nedokončené
    // záznamy z dřívějších selhání. Aktivní referenci NIKDY nelze smazat tímto handlerem
    // (kontrola stavu níže), aby nemohlo dojít k nechtěné ztrátě aktuálně používaného znění.
    if (isset($_POST['rdleg_smazat_zneni']) && check_admin_referer('rdleg_smazat_zneni_nonce')) {
        $zneni_id_smazat = intval($_POST['zneni_id_smazat'] ?? 0);
        $zneni_ke_smazani = $wpdb->get_row($wpdb->prepare("SELECT id, je_aktivni_reference, zpracovani_hotovo FROM {$wpdb->prefix}rd_leg_zneni WHERE id=%d", $zneni_id_smazat));
        $pozastavena_mazani = get_option('rdleg_pozastavena_zneni', []);
        if (!is_array($pozastavena_mazani)) $pozastavena_mazani = [];
        if (!$zneni_ke_smazani) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Záznam nebyl nalezen.'];
        } elseif (!$zneni_ke_smazani->zpracovani_hotovo && !isset($pozastavena_mazani[$zneni_ke_smazani->id])) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Tento záznam má aktivně běžící AI zpracování — nejprve ho zastavte tlačítkem "Zastavit zpracování" v sekci "Probíhající AI vyhodnocení" níže.'];
        } else {
            $wpdb->delete("{$wpdb->prefix}rd_leg_zneni", ['id' => $zneni_id_smazat]);
            if (isset($pozastavena_mazani[$zneni_ke_smazani->id])) {
                unset($pozastavena_mazani[$zneni_ke_smazani->id]);
                update_option('rdleg_pozastavena_zneni', $pozastavena_mazani);
            }
            $hlaseni[] = ['typ' => 'success', 'text' => 'Záznam byl smazán.'];
        }
    }

    if (isset($_POST['rdleg_nastavit_roli']) && check_admin_referer('rdleg_nastavit_roli_nonce')) {
        $zneni_id_role = intval($_POST['zneni_id_role'] ?? 0);
        $nova_role = (sanitize_text_field($_POST['nova_role'] ?? '') === 'aktualni') ? 1 : 0;
        if ($nova_role === 1) {
            // Jen jeden záznam může být "Aktuální" — ostatní (pokud nějaký byl) se
            // přepnou na "Před novelou", aby nevznikly dva "Aktuální" záznamy.
            $wpdb->update("{$wpdb->prefix}rd_leg_zneni", ['je_aktivni_reference' => 0], ['zakon_kod' => RDLEG_ZAKON_KOD, 'je_aktivni_reference' => 1]);
        }
        $wpdb->update("{$wpdb->prefix}rd_leg_zneni", ['je_aktivni_reference' => $nova_role], ['id' => $zneni_id_role]);
        $hlaseni[] = ['typ' => 'success', 'text' => 'Role znění byla nastavena.'];
    }

    if (isset($_POST['rdleg_spustit_porovnani']) && check_admin_referer('rdleg_spustit_porovnani_nonce')) {
        $nevyresenych_porovnani = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        if ($nevyresenych_porovnani > 0) {
            $hlaseni[] = ['typ' => 'error', 'text' => "Nelze spustit porovnání — lektor má {$nevyresenych_porovnani} nevyřízených návrhů. Nejprve je musí schválit nebo zamítnout."];
        } else {
            $vysledek = rdleg_spustit_porovnani();
            if (is_wp_error($vysledek)) {
                $hlaseni[] = ['typ' => 'error', 'text' => $vysledek->get_error_message()];
            } elseif ($vysledek['pocet_zmen_paragrafu'] === 0) {
                $hlaseni[] = ['typ' => 'success', 'text' => 'Porovnáno — nebyly nalezeny žádné změny. Žádné AI vyhodnocení nebylo potřeba.'];
            } else {
                $hlaseni[] = ['typ' => 'success', 'text' => "Porovnání hotovo — nalezeno {$vysledek['pocet_zmen_paragrafu']} paragrafů k posouzení, v {$vysledek['pocet_davek']} dávkách. Spusťte AI vyhodnocení v sekci \"Probíhající AI vyhodnocení\" níže."];
            }
        }
    }

    if (isset($_POST['rdleg_kontrola_znovu']) && check_admin_referer('rdleg_kontrola_znovu_nonce')) {
        $nevyresenych_kontrola = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        $rozpracovanych_kontrola = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo=0");
        if ($nevyresenych_kontrola > 0 || $rozpracovanych_kontrola > 0) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nelze spustit kontrolu — nejprve musí být vyřízeny čekající návrhy nebo dokončeno probíhající zpracování.'];
        } else {
            $vysledek = rdleg_provest_kontrolu_znovu();
            if (is_wp_error($vysledek)) {
                $hlaseni[] = ['typ' => 'error', 'text' => $vysledek->get_error_message()];
            } else {
                $hlaseni[] = ['typ' => 'success', 'text' => "Kontrola spuštěna — {$vysledek['pocet_zmen_paragrafu']} paragrafů k posouzení v {$vysledek['pocet_davek']} dávkách. Pokračujte tlačítkem \"Zpracovat další dávku\" níže."];
            }
        }
    }

    if (isset($_POST['rdleg_komplet_kontrola']) && check_admin_referer('rdleg_komplet_nonce')) {
        $nevyresenych_komplet = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        $rozpracovanych_komplet = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo=0");
        if ($nevyresenych_komplet > 0 || $rozpracovanych_komplet > 0) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nelze spustit kontrolu — nejprve musí být vyřízeny čekající návrhy nebo dokončeno probíhající zpracování.'];
        } else {
            $vysledek = rdleg_provest_kontrolu_znovu('komplet');
            if (is_wp_error($vysledek)) {
                $hlaseni[] = ['typ' => 'error', 'text' => $vysledek->get_error_message()];
            } else {
                $hlaseni[] = ['typ' => 'success', 'text' => "Kompletní kontrola spuštěna — celý zákon ({$vysledek['pocet_zmen_paragrafu']} paragrafů) se posoudí proti webu ve {$vysledek['pocet_davek']} dávkách. Zpracování běží automaticky v sekci \"Probíhající AI vyhodnocení\" níže; návrh vznikne jen tam, kde je na webu něco potřeba přidat, upravit nebo odebrat."];
            }
        }
    }

    if (isset($_POST['rdleg_kontrola_testu']) && check_admin_referer('rdleg_kontrola_testu_nonce')) {
        $vysledek = rdleg_zkontrolovat_test_podle_lekci();
        if (is_wp_error($vysledek)) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Kontrola testu se nezdařila: ' . $vysledek->get_error_message()];
        } else {
            $hlaseni[] = ['typ' => 'success', 'text' => "Kontrola testu dokončena — nalezeno {$vysledek['pocet_navrhu']} návrhů na úpravu otázek. " . ($vysledek['pocet_navrhu'] > 0 ? 'Návrhy čekají ve staging sekci výše.' : 'Všechny otázky jsou v souladu s lekcem.')];
        }
    }

    if (isset($_POST['rdleg_smazat_vsechny_cekajici']) && check_admin_referer('rdleg_smazat_vsechny_nonce')) {
        $pocet_smazano = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        $hlaseni[] = ['typ' => 'success', 'text' => "Smazáno {$pocet_smazano} čekajících návrhů. Schválené/zamítnuté návrhy zůstávají nedotčené."];
    }

    // POZNÁMKA: manuální konsolidace lekcí (dřív POST handler rdleg_konsolidovat_manualne
    // volající hromadně rdleg_konsolidovat_navrhy_lekci() pro všechny lekce najednou)
    // byla nahrazena AJAX handlerem rdleg_konsolidovat_lekci_ajax — jedno volání = jedna
    // lekce, viz JS smyčka u tlačítka "Konsolidovat lekce" níže na stránce.

    if (isset($_POST['rdleg_uzavrit_bez_dokonceni']) && check_admin_referer('rdleg_uzavrit_nonce')) {
        $zneni_id = intval($_POST['zneni_id'] ?? 0);
        $pozastavena = get_option('rdleg_pozastavena_zneni', []);
        if (is_array($pozastavena) && isset($pozastavena[$zneni_id])) {
            unset($pozastavena[$zneni_id]);
            update_option('rdleg_pozastavena_zneni', $pozastavena);
        }
        // Smazat i čekající návrhy tohoto znění — uživatel zpracování definitivně
        // nedokončuje, takže žádné nevyřízené návrhy z něj nemají zůstávat a blokovat
        // lektora/nahrávání nového znění. Schválené/zamítnuté návrhy zůstávají nedotčené.
        $pocet_smazano = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE zneni_id=%d AND stav IN ('ceka','ceka_admin')", $zneni_id
        ));
        // Definitivně uzavřít, i když ne všechny dávky byly zpracované — uživatel se
        // rozhodl tento běh nedokončit. Zbylé paragrafy zákona prostě nebudou posouzeny
        // AI; pokud bude potřeba, lze nahrát totéž znění znovu a zpracovat od začátku.
        $wpdb->update("{$wpdb->prefix}rd_leg_zneni", ['zpracovani_hotovo' => 1], ['id' => $zneni_id]);
        $hlaseni[] = ['typ' => 'success', 'text' => "Zpracování uzavřeno bez dokončení — smazáno {$pocet_smazano} čekajících návrhů z tohoto běhu, záznam zmizel ze seznamu probíhajících, nahrávání nového znění je teď možné."];
    }

    if (isset($_POST['rdleg_spustit_znovu']) && check_admin_referer('rdleg_spustit_znovu_nonce')) {
        $zneni_id = intval($_POST['zneni_id'] ?? 0);
        $pozastavena = get_option('rdleg_pozastavena_zneni', []);
        if (is_array($pozastavena) && isset($pozastavena[$zneni_id])) {
            unset($pozastavena[$zneni_id]);
            update_option('rdleg_pozastavena_zneni', $pozastavena);
        }
        $hlaseni[] = ['typ' => 'success', 'text' => 'Zpracování znovu spuštěno — dávky se teď budou zpracovávat automaticky na pozadí.'];
    }

    if (isset($_POST['rdleg_zastavit']) && check_admin_referer('rdleg_zastavit_nonce')) {
        $zneni_id = intval($_POST['zneni_id'] ?? 0);
        $zneni = $zneni_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE id=%d", $zneni_id)) : null;
        if (!$zneni) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Toto rozpracované zpracování už nebylo nalezeno (možná už doběhlo nebo bylo smazáno).'];
        } else {
            // Jen zastavit — čekající návrhy i progres (davek_hotovo) zůstávají nedotčené,
            // ať nic nepřijde nazmar (typicky při dlouhém čekání na obnovení API limitu).
            // Poznamenat si, že tohle znění bylo uživatelem výslovně zastaveno — dokud
            // uživatel sám neklikne na "Spustit zpracování", automatika (JS smyčka) se
            // pro tento záznam znovu nerozjede sama jen proto, že zpracovani_hotovo=0.
            $pozastavena = get_option('rdleg_pozastavena_zneni', []);
            if (!is_array($pozastavena)) $pozastavena = [];
            $pozastavena[$zneni_id] = true;
            update_option('rdleg_pozastavena_zneni', $pozastavena);
            $hlaseni[] = ['typ' => 'success', 'text' => "Zastaveno — čekající návrhy a dosavadní postup zůstávají nedotčené, nic se nesmazalo. Automatické zpracování se znovu nerozjede samo — až budete chtít pokračovat, klikněte na tlačítko \"Spustit zpracování\" níže."];
        }
    }

    if (isset($_POST['rdleg_zavrit_reset_hlasku']) && check_admin_referer('rdleg_zavrit_reset_hlasku_nonce')) {
        delete_option('rdleg_posledni_reset');
    }

    if (isset($_POST['rdleg_smazat_historii_jeden']) && check_admin_referer('rdleg_smazat_historii_nonce')) {
        $hist_id = intval($_POST['rdleg_smazat_historii_jeden']);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE id=%d AND stav IN ('schvaleno','zamitnuto','smazano')", $hist_id
        ));
        $hlaseni[] = ['typ' => 'success', 'text' => 'Záznam z historie byl smazán. Schválený obsah na webu (pokud šlo o schválení) zůstává — tohle maže jen záznam v historii, ne živý obsah.'];
    }

    if (isset($_POST['rdleg_smazat_historii_vse']) && check_admin_referer('rdleg_smazat_historii_nonce')) {
        $pocet = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('schvaleno','zamitnuto','smazano')");
        $hlaseni[] = ['typ' => 'success', 'text' => "Smazáno {$pocet} záznamů z historie vyřízených návrhů. Schválený obsah na webu zůstává nedotčený — tohle maže jen historii/audit, ne živý obsah lekcí, otázek ani novinek."];
    }

    if (isset($_POST['rdleg_pregenerovat']) && check_admin_referer('rdleg_pregenerovat_nonce')) {
        $stare = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
        if (empty($stare)) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Žádné čekající návrhy k přegenerování.'];
        } else {
            $davka_pro_ai = [];
            foreach ($stare as $sn) $davka_pro_ai[$sn->paragraf] = ['paragraf' => $sn->paragraf, 'typ' => $sn->typ_zmeny, 'stary_text' => $sn->stary_text, 'novy_text' => $sn->novy_text];
            $rdleg_rezim_pregenerovat = $wpdb->get_var($wpdb->prepare("SELECT rezim_zpracovani FROM {$wpdb->prefix}rd_leg_zneni WHERE id=%d", $stare[0]->zneni_id)) ?: 'novela';
            $nove = rdleg_ai_navrhnout_zmeny(array_values($davka_pro_ai), rdleg_sestavit_kontext_platformy(), $rdleg_rezim_pregenerovat);
            // Stejná pojistka jako v rdleg_zpracovat_jednu_davku() — v 'komplet'/'audit'
            // režimu nemá smysl cíl 'novinka', protože tu chybí jakékoli srovnání s
            // předchozí verzí zákona (viz vysvětlení tam).
            if (is_array($nove) && in_array($rdleg_rezim_pregenerovat, ['komplet', 'audit'], true)) {
                $nove = array_values(array_filter($nove, function($nn) {
                    return ($nn['cilove_misto'] ?? '') !== 'novinka';
                }));
            }
            if (is_wp_error($nove)) {
                $hlaseni[] = ['typ' => 'error', 'text' => 'Přegenerování se nezdařilo: ' . $nove->get_error_message()];
            } elseif (empty($nove)) {
                // BEZPEČNOSTNÍ POJISTKA: AI vrátila prázdný výsledek (žádný relevantní návrh
                // pro žádný z paragrafů). Staré návrhy NEMAŽEME — bez tohoto by "Přegenerovat"
                // mohlo nevratně smazat všechny čekající návrhy bez náhrady, pokud AI selže
                // tiše (vrátí prázdné pole, ne WP_Error) nebo usoudí, že nic z toho už není
                // relevantní (což by ale měl posoudit lektor, ne se to mělo ztratit beze stopy).
                $hlaseni[] = ['typ' => 'error', 'text' => 'AI nevrátila žádné návrhy — původní čekající návrhy zůstávají beze změny, nic se nesmazalo.'];
            } else {
                $zneni_id = $stare[0]->zneni_id;
                $pocet = 0;
                $nove_k_vlozeni = [];
                foreach ($nove as $nn) {
                    if (empty($nn['cilove_misto'])) continue;
                    $puvodni = $davka_pro_ai[$nn['paragraf']] ?? null;
                    $cil_misto = sanitize_text_field($nn['cilove_misto']);
                    $cil_id = sanitize_text_field($nn['cilovy_identifikator'] ?? '');
                    // Stejná pojistka jako v rdleg_zpracovat_jednu_davku() — nevkládat návrh,
                    // který je po normalizaci totožný s tím, co už na webu je.
                    if (rdleg_navrh_je_beze_zmeny($cil_misto, $cil_id, $nn['navrh_upravy'] ?? '')) continue;
                    // POJISTKA: novinka je na seznamu výjimek
                    if ($cil_misto === 'novinka_uprava' && rdleg_je_vyloucena_novinka($cil_id)) continue;
                    $typ_obsahu = (rdleg_aktualni_obsah_cile($cil_misto, $cil_id) === null) ? 'nova' : 'uprava';
                    $nove_k_vlozeni[] = [
                        'zneni_id' => $zneni_id,
                        'paragraf' => sanitize_text_field($nn['paragraf'] ?? ''),
                        'typ_zmeny' => $puvodni ? $puvodni['typ'] : 'zmena',
                        'typ_obsahu' => $typ_obsahu,
                        'stav' => 'ceka_admin',
                        'shrnuti_zmeny' => sanitize_textarea_field($nn['shrnuti_zmeny'] ?? ''),
                        'navrh_upravy' => wp_kses_post($nn['navrh_upravy'] ?? ''),
                        'cilove_misto' => $cil_misto,
                        'cilovy_identifikator' => $cil_id,
                    ];
                }
                if (empty($nove_k_vlozeni)) {
                    // Stejná pojistka jako výše — po filtraci na cilove_misto nezbylo nic platného.
                    $hlaseni[] = ['typ' => 'error', 'text' => 'AI vrátila pouze nevalidní návrhy (bez cílového místa) — původní čekající návrhy zůstávají beze změny.'];
                } else {
                    // Teprve TEĎ, když víme, že máme platnou náhradu, smažeme staré a vložíme nové.
                    $wpdb->query("DELETE FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
                    foreach ($nove_k_vlozeni as $radek) {
                        $wpdb->insert("{$wpdb->prefix}rd_leg_navrhy", $radek);
                        $pocet++;
                    }
                    $hlaseni[] = ['typ' => 'success', 'text' => "Přegenerováno — z " . count($stare) . " starých návrhů vzniklo {$pocet} nových."];
                }
            }
        }
    }

    if (isset($_POST['rdleg_zobrazit_lektorovi_vybrane']) && check_admin_referer('rdleg_hromadna_akce_nonce')) {
        $ids = array_filter(array_map('intval', (array) ($_POST['navrh_ids'] ?? [])));
        if (empty($ids)) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nebyl vybrán žádný návrh — zaškrtněte alespoň jeden a zkuste to znovu.'];
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $pocet = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rd_leg_navrhy SET stav='ceka' WHERE stav='ceka_admin' AND id IN ($placeholders)", $ids
            ));
            $hlaseni[] = ['typ' => 'success', 'text' => "Hotovo — {$pocet} " . ($pocet === 1 ? 'návrh byl zobrazen' : ($pocet >= 2 && $pocet <= 4 ? 'návrhy byly zobrazeny' : 'návrhů bylo zobrazeno')) . " lektorovi."];
        }
    }

    if (isset($_POST['rdleg_zamitnout_vybrane']) && check_admin_referer('rdleg_hromadna_akce_nonce')) {
        $ids = array_filter(array_map('intval', (array) ($_POST['navrh_ids'] ?? [])));
        if (empty($ids)) {
            $hlaseni[] = ['typ' => 'error', 'text' => 'Nebyl vybrán žádný návrh — zaškrtněte alespoň jeden a zkuste to znovu.'];
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $pocet = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rd_leg_navrhy SET stav='zamitnuto', vyreseno=%s, vyresil_kdo=%s WHERE stav='ceka_admin' AND id IN ($placeholders)",
                array_merge([current_time('mysql'), 'admin'], $ids)
            ));
            $hlaseni[] = ['typ' => 'success', 'text' => "Zamítnuto {$pocet} " . ($pocet === 1 ? 'návrh' : ($pocet >= 2 && $pocet <= 4 ? 'návrhy' : 'návrhů')) . " — lektor je neuvidí. Zůstávají v historii vyřízených návrhů."];
        }
    }

    $cil_popisky = ['novinka' => 'Novinka', 'novinka_uprava' => 'Úprava novinky', 'faq' => 'FAQ', 'lekce' => 'Lekce kurzu', 'kviz_otazka' => 'Testová otázka'];
    $typ_popisky = ['novy' => 'Nové ustanovení', 'zmena' => 'Změna', 'smazany' => 'Zrušeno'];
    $typ_barvy = ['novy' => '#15803d;background:#dcfce7', 'zmena' => '#b45309;background:#fef3c7', 'smazany' => '#b91c1c;background:#fee2e2'];
    $obsah_popisky = ['nova' => 'Nová položka', 'uprava' => 'Úprava položky'];

    $ceka_admin_navrhy = $wpdb->get_results("
        SELECT n.*, z.nazev_souboru FROM {$wpdb->prefix}rd_leg_navrhy n
        LEFT JOIN {$wpdb->prefix}rd_leg_zneni z ON z.id = n.zneni_id
        WHERE n.stav = 'ceka_admin' ORDER BY n.vytvoreno DESC
    ");
    $ceka_navrhy = $wpdb->get_results("
        SELECT n.*, z.nazev_souboru FROM {$wpdb->prefix}rd_leg_navrhy n
        LEFT JOIN {$wpdb->prefix}rd_leg_zneni z ON z.id = n.zneni_id
        WHERE n.stav = 'ceka' ORDER BY n.vytvoreno DESC
    ");
    $vyhodnoceni_log = json_decode(get_option('rdleg_vyhodnoceni_log', '{}'), true);
    if (!is_array($vyhodnoceni_log)) $vyhodnoceni_log = [];
    uasort($vyhodnoceni_log, function($a, $b) { return strcmp($b['cas'] ?? '', $a['cas'] ?? ''); }); // nejnovější nahoře
    $trvale_zamitnute = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav='zamitnuto_trvale' ORDER BY vyreseno DESC");
    $vyrizene = $wpdb->get_results("
        SELECT n.*, z.nazev_souboru FROM {$wpdb->prefix}rd_leg_navrhy n
        LEFT JOIN {$wpdb->prefix}rd_leg_zneni z ON z.id = n.zneni_id
        WHERE n.stav IN ('schvaleno','zamitnuto','smazano') ORDER BY n.vyreseno DESC LIMIT 50
    ");
    $historie_zneni = $wpdb->get_results($wpdb->prepare(
        "SELECT id, nazev_souboru, je_aktivni_reference, zpracovani_hotovo, nahrano, nahral_uzivatel, LENGTH(plny_text) AS delka_textu, LENGTH(paragrafy_json) AS delka_json FROM {$wpdb->prefix}rd_leg_zneni WHERE zakon_kod=%s ORDER BY nahrano DESC LIMIT 20",
        RDLEG_ZAKON_KOD
    ));

    // Pro každý schválený návrh dohledáme, zda odpovídající záznam na webu ještě
    // existuje a má pořád stejnou citaci (rd_leg_zdroj) — pokud admin/lektor mezitím
    // udělal "Obnovit výchozí", záznam zmizel a citace s ním. Žádný cizí klíč mezi
    // rd_leg_navrhy a cílovými tabulkami neexistuje, párujeme tedy podle typu cíle:
    // u faq/lekce podle cilovy_identifikator (otázka/název textem), u novinky podle názvu (jediná
    // dostupná vazba, protože novinka je nově VZNIKLÝ záznam bez explicitního ID).
    foreach ($vyrizene as $n) {
        $n->rdleg_live_info = null;
        if ($n->stav !== 'schvaleno') continue;

        if ($n->cilove_misto === 'faq' && $n->cilovy_identifikator) {
            $zaznam = $wpdb->get_row($wpdb->prepare("SELECT otazka, rd_leg_zdroj FROM {$wpdb->prefix}rd_faq WHERE otazka=%s", $n->cilovy_identifikator));
            $n->rdleg_live_info = $zaznam ? ['misto' => 'FAQ', 'zaznam' => $zaznam->otazka, 'zdroj' => $zaznam->rd_leg_zdroj] : false;
        } elseif ($n->cilove_misto === 'lekce' && $n->cilovy_identifikator) {
            $lekce_id = rdleg_najit_lekce_id($n->cilovy_identifikator);
            $zaznam = $lekce_id ? $wpdb->get_row($wpdb->prepare("SELECT id, nazev, poradi, rd_leg_zdroj FROM {$wpdb->prefix}rd_lekce WHERE id=%d", $lekce_id)) : null;
            if ($zaznam) {
                // Pozice v pořadí, ne syrová hodnota poradi (může mít mezery) — stejné číslo, jaké vidí lektor/student.
                $pozice = 1 + (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}rd_lekce WHERE poradi < %d", $zaznam->poradi
                ));
                $n->rdleg_live_info = ['misto' => 'Lekce', 'zaznam' => 'Lekce ' . $pozice . ': ' . $zaznam->nazev, 'zdroj' => $zaznam->rd_leg_zdroj];
            } else {
                $n->rdleg_live_info = false;
            }
        } elseif ($n->cilove_misto === 'novinka') {
            $hledany_nazev = $n->nazev_zmeny ?: 'Změna v zákoně č. 361/2000 Sb.';
            $zaznam = $wpdb->get_row($wpdb->prepare("SELECT nazev, rd_leg_zdroj FROM {$wpdb->prefix}rd_novinky WHERE nazev=%s ORDER BY id DESC LIMIT 1", $hledany_nazev));
            $n->rdleg_live_info = $zaznam ? ['misto' => 'Novinka', 'zaznam' => $zaznam->nazev, 'zdroj' => $zaznam->rd_leg_zdroj] : false;
        } elseif ($n->cilove_misto === 'novinka_uprava' && $n->cilovy_identifikator) {
            $novinka_id = rdleg_najit_novinku_id($n->cilovy_identifikator);
            $zaznam = $novinka_id ? $wpdb->get_row($wpdb->prepare("SELECT nazev, rd_leg_zdroj FROM {$wpdb->prefix}rd_novinky WHERE id=%d", $novinka_id)) : null;
            $n->rdleg_live_info = $zaznam ? ['misto' => 'Novinka', 'zaznam' => $zaznam->nazev, 'zdroj' => $zaznam->rd_leg_zdroj] : false;
        }
    }

    echo rdleg_admin_css();
    ?>
    <div class="wrap rdleg-wrap">
        <h1 style="display:flex;align-items:center;gap:10px">RefDrive — AI Sledování legislativy
            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;background:#fef3c7;color:#b45309;border-radius:99px;padding:3px 10px">Experimentální</span>
        </h1>
        <p class="desc" style="color:#6b7280">Samostatný modul — zákon č. 361/2000 Sb. Nezávislý na hlavním RefDrive pluginu, lze kdykoliv vypnout nebo odinstalovat bez vlivu na zbytek webu.</p>

        <?php
        $rdleg_pocet_lekce = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_lekce");
        $rdleg_pocet_otazky = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_otazky");
        $rdleg_pocet_novinky = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_novinky");
        $rdleg_pocet_leg_zakony_pp = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_zakony WHERE sekce='proc_povinne'");
        $rdleg_pocet_leg_zakony_post = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_zakony WHERE sekce='postihy'");
        $rdleg_pocet_leg_kroky = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_kroky WHERE sekce='obsah'");
        $rdleg_tabulky_info = [
            ['nazev' => 'rd_lekce', 'pocet' => $rdleg_pocet_lekce, 'prah' => 5, 'ma_fallback' => true],
            ['nazev' => 'rd_otazky', 'pocet' => $rdleg_pocet_otazky, 'prah' => 20, 'ma_fallback' => true],
            ['nazev' => 'rd_novinky', 'pocet' => $rdleg_pocet_novinky, 'prah' => 1, 'ma_fallback' => true],
            ['nazev' => "rd_leg_zakony (sekce='proc_povinne')", 'pocet' => $rdleg_pocet_leg_zakony_pp, 'prah' => 1, 'ma_fallback' => true],
            ['nazev' => "rd_leg_zakony (sekce='postihy')", 'pocet' => $rdleg_pocet_leg_zakony_post, 'prah' => 1, 'ma_fallback' => true],
            ['nazev' => "rd_leg_kroky (sekce='obsah')", 'pocet' => $rdleg_pocet_leg_kroky, 'prah' => 1, 'ma_fallback' => true],
        ];
        $rdleg_ma_problem = false;
        foreach ($rdleg_tabulky_info as $rdleg_t) if ($rdleg_t['ma_fallback'] && $rdleg_t['pocet'] < $rdleg_t['prah']) $rdleg_ma_problem = true;
        ?>
        <details class="rdleg-admin-card" style="margin-bottom:18px" <?php echo $rdleg_ma_problem ? 'open' : ''; ?>>
            <summary style="cursor:pointer;font-weight:600;padding:14px 18px">Diagnostika: stav tabulek (fallback na hardcoded obsah?)</summary>
            <div style="padding:0 18px 18px">
                <p class="desc" style="color:#6b7280;margin:0 0 10px">Hlavní plugin u některých tabulek, pokud mají MÉNĚ řádků než daný práh, zobrazuje na webu napevno zapsaný (hardcoded) náhradní obsah místo dat z databáze — web pak vypadá normálně, ale rd-legislativa proti té (skoro) prázdné tabulce nic nenajde, protože hledá v databázi, ne v hardcoded kódu hlavního pluginu.</p>
                <table class="widefat striped">
                    <thead><tr><th>Tabulka</th><th>Počet řádků</th><th>Práh fallbacku</th><th>Stav</th></tr></thead>
                    <tbody>
                    <?php foreach ($rdleg_tabulky_info as $rdleg_t): ?>
                        <tr>
                            <td><code><?php echo esc_html($rdleg_t['nazev']); ?></code></td>
                            <td><?php echo $rdleg_t['pocet']; ?></td>
                            <td><?php echo $rdleg_t['ma_fallback'] ? $rdleg_t['prah'] : '— (bez fallbacku)'; ?></td>
                            <td>
                                <?php if (!$rdleg_t['ma_fallback']): ?>
                                    <span style="color:#6b7280">bez rizika</span>
                                <?php elseif ($rdleg_t['pocet'] < $rdleg_t['prah']): ?>
                                    <span style="color:#b91c1c;font-weight:700">⚠ web běží na hardcoded fallbacku, ne na téhle tabulce</span>
                                <?php else: ?>
                                    <span style="color:#15803d">OK — web čte tuto tabulku</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <?php foreach ($hlaseni as $h): ?>
            <div class="notice notice-<?php echo esc_attr($h['typ']); ?>"><p><?php echo esc_html($h['text']); ?></p></div>
        <?php endforeach; ?>

        <?php $rdleg_posledni_reset = get_option('rdleg_posledni_reset'); if (is_array($rdleg_posledni_reset)): ?>
            <div class="notice notice-info" style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
                <p style="margin:0">Sekce „<?php echo esc_html($rdleg_posledni_reset['sekce'] ?? ''); ?>“ byla obnovena na výchozí hodnoty
                   (<?php echo esc_html($rdleg_posledni_reset['kdo_jmeno'] ?? 'Neznámý'); ?><?php echo !empty($rdleg_posledni_reset['kdo_typ']) ? ', ' . esc_html($rdleg_posledni_reset['kdo_typ']) : ''; ?>,
                   <?php echo esc_html(date('d.m.Y H:i', strtotime($rdleg_posledni_reset['kdy'] ?? ''))); ?>) — návrhy níže byly automaticky přegenerovány podle aktuálního stavu webu.</p>
                <form method="post" style="margin:0;flex-shrink:0">
                    <?php wp_nonce_field('rdleg_zavrit_reset_hlasku_nonce'); ?>
                    <button type="submit" name="rdleg_zavrit_reset_hlasku" value="1" title="Zavřít" style="background:none;border:none;cursor:pointer;font-size:16px;line-height:1;color:#6b7280;padding:2px 4px">✕</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="rdleg-admin-card">
            <h2>Nastavení</h2>
            <form method="post">
                <?php wp_nonce_field('rdleg_nastaveni_nonce'); ?>
                <div class="rdleg-toggle-row">
                    <div>
                        <strong>Funkce aktivní</strong>
                        <p class="desc" style="margin:2px 0 0">Pokud vypnete, nelze nahrávat nová znění ani generovat AI návrhy. Stávající historie a čekající návrhy zůstanou uložené a viditelné.</p>
                    </div>
                    <label class="rdleg-switch"><input type="checkbox" name="rdleg_aktivni" <?php checked($rdleg_aktivni, 1); ?>><span class="rdleg-slider"></span></label>
                </div>
                <div class="rdleg-toggle-row">
                    <div>
                        <strong>Viditelné pro lektora</strong>
                        <p class="desc" style="margin:2px 0 0">Pokud vypnete, lektor banner ani sekci s návrhy v portálu neuvidí. Vy jako admin můžete dál nahrávat a kontrolovat zde.</p>
                    </div>
                    <label class="rdleg-switch"><input type="checkbox" name="rdleg_lektor_viditelnost" <?php checked($rdleg_lektor_viditelnost, 1); ?>><span class="rdleg-slider"></span></label>
                </div>
                <details style="margin-top:0;border-top:1px solid #e5e7eb;padding-top:16px">
                    <summary style="cursor:pointer;font-weight:600;color:#5b21b6">Vlastní instrukce pro AI (volitelné)</summary>
                    <div style="margin-top:12px">
                        <p class="desc" style="color:#6b7280;margin:0 0 8px">Tyto instrukce se přidají na konec každého promptu, těsně před posuzovaný paragraf. Můžete zpřesnit, co AI má nebo nemá navrhovat — například zakázat určité cíle, upřesnit tón, přidat doménová pravidla specifická pro váš web. Základní instrukce pluginu zůstávají beze změny; toto pole je čistě doplňkové.</p>
                        <p class="desc" style="color:#9ca3af;margin:0 0 10px;font-size:12px">Příklad: "Nenavrhuj úpravy FAQ otázek týkajících se alkoholu za volantem — ty jsou záměrně formulovány obecně." nebo "Při navrhování úprav lekcí preferuj stručné formulace bez právnického žargonu."</p>
                        <textarea name="rdleg_vlastni_instrukce" rows="6" style="width:100%;font-family:monospace;font-size:13px;padding:10px;border:1px solid #d1d5db;border-radius:6px;resize:vertical;box-sizing:border-box"><?php echo esc_textarea(get_option('rdleg_vlastni_instrukce', '')); ?></textarea>
                        <p class="desc" style="color:#9ca3af;margin:4px 0 0;font-size:12px">Počet znaků: <span id="rdleg-instrukce-pocet"><?php echo mb_strlen(get_option('rdleg_vlastni_instrukce', '')); ?></span> (každých ~4 znaky = 1 token; doporučený limit ~2 000 znaků)</p>
                        <script>
                        document.querySelector('textarea[name="rdleg_vlastni_instrukce"]').addEventListener('input', function() {
                            document.getElementById('rdleg-instrukce-pocet').textContent = this.value.length;
                        });
                        </script>
                    </div>
                </details>
                <details style="margin-top:14px;border-top:1px solid #e5e7eb;padding-top:14px">
                    <summary style="cursor:pointer;font-weight:600;color:#5b21b6">Výjimky — novinky chráněné před AI úpravami</summary>
                    <div style="margin-top:12px">
                        <p class="desc" style="color:#6b7280;margin:0 0 8px">Novinky v tomto seznamu AI nikdy nenavrhne upravit — ani když najde formulační odchylku od přesného znění zákona. Jeden název novinky na řádek, přesně tak jak je napsaný na webu (porovnání ignoruje velikost písmen a mezery).</p>
                        <p class="desc" style="color:#9ca3af;margin:0 0 10px;font-size:12px">Příklad použití: novinka je záměrná parafráze (ne doslovná citace zákona), takže AI ji pokaždé "opravuje" — přidejte ji sem a AI ji přeskočí.</p>
                        <textarea name="rdleg_vyloucene_novinky" rows="4" style="width:100%;font-family:monospace;font-size:13px;padding:10px;border:1px solid #d1d5db;border-radius:6px;resize:vertical;box-sizing:border-box" placeholder="Řidičský průkaz nemusíte vozit&#10;Název další novinky..."><?php echo esc_textarea(get_option('rdleg_vyloucene_novinky', '')); ?></textarea>
                    </div>
                </details>
                <button type="submit" name="rdleg_ulozit_nastaveni" class="rdleg-btn-primary" style="margin-top:16px">Uložit nastavení</button>
            </form>
        </div>

        <div class="rdleg-admin-card">
            <h2>Nahrát znění zákona</h2>
            <p class="desc">Nahrajte znění zákona č. 361/2000 Sb. ve formátu .docx a vyberte, zda jde o aktuálně platné znění, nebo o znění před novelou (referenční podklad pro porovnání). Při nahrání "Aktuálního znění" systém mechanicky porovná paragrafy se zněním před novelou a u nalezených rozdílů navrhne AI úpravy obsahu platformy (Novinky, Legislativa, lekce kurzu, testové otázky).</p>
            <?php
            $rdleg_nevyresenych_predem = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rd_leg_navrhy WHERE stav IN ('ceka','ceka_admin')");
            $rdleg_pozastavena_predem = get_option('rdleg_pozastavena_zneni', []);
            if (!is_array($rdleg_pozastavena_predem)) $rdleg_pozastavena_predem = [];
            $rdleg_rozpracovana_id = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo=0");
            // Pozastavené (uživatel klikl "Zastavit zpracování", čeká na explicitní "Spustit
            // zpracování") nesmí blokovat nahrání nového znění — uživatel tím chce skončit
            // s tím starým rozpracovaným během, ne v něm pokračovat.
            $rdleg_aktivne_rozpracovanych_predem = count(array_filter($rdleg_rozpracovana_id, function($id) use ($rdleg_pozastavena_predem) {
                return !isset($rdleg_pozastavena_predem[$id]);
            }));
            $rdleg_nahravani_blokovano = $rdleg_aktivne_rozpracovanych_predem > 0;
            ?>
            <?php if ($rdleg_aktivne_rozpracovanych_predem > 0): ?>
            <div class="notice notice-warning" style="margin:10px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <p style="margin:0">Nelze nahrát nové znění — předchozí AI vyhodnocení ještě neskončilo.</p>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('rdleg_uzavrit_nonce'); ?>
                    <input type="hidden" name="zneni_id" value="<?php
                        $rdleg_akt_row = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo=0 ORDER BY nahrano DESC LIMIT 1");
                        echo $rdleg_akt_row ? (int) $rdleg_akt_row->id : 0;
                    ?>">
                    <button type="submit" name="rdleg_uzavrit_bez_dokonceni" class="rdleg-btn-secondary" style="white-space:nowrap" onclick="return confirm('Uzavřít probíhající vyhodnocení a odemknout nahrávání? Zpracované dávky a návrhy zůstanou.');">Uvolnit zámek</button>
                </form>
            </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rdleg_nahrat_nonce'); ?>
                <div class="rdleg-dropzone">
                    <input type="file" name="rdleg_docx" accept=".docx" required <?php echo $rdleg_nahravani_blokovano ? 'disabled' : ''; ?>>
                    <p class="desc" style="margin:8px 0 0">Pouze formát .docx</p>
                </div>
                <p style="margin-top:14px"><button type="submit" name="rdleg_nahrat" class="rdleg-btn-primary" <?php echo ($rdleg_aktivni && !$rdleg_nahravani_blokovano) ? '' : 'disabled'; ?>>Nahrát</button></p>
                <p class="desc" style="margin-top:6px">Soubor se jen uloží — žádné porovnání ani AI vyhodnocení se nespustí automaticky. Roli (Aktuální / Před novelou) nastavíte ručně v sekci "Historie nahraných znění" níže, a porovnání i AI vyhodnocení spustíte tlačítkem tamtéž, až budete mít jistotu, že jsou obě znění správně nastavená.</p>
                <?php if (!$rdleg_aktivni): ?><p class="desc" style="color:#b91c1c">Funkce je vypnutá v nastavení výše.</p><?php endif; ?>
            </form>
        </div>

        <?php
        $rozpracovane = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rd_leg_zneni WHERE zpracovani_hotovo = 0 ORDER BY nahrano DESC");
        $rdleg_pozastavena_zneni = get_option('rdleg_pozastavena_zneni', []);
        if (!is_array($rdleg_pozastavena_zneni)) $rdleg_pozastavena_zneni = [];
        if (!empty($rozpracovane)): ?>
        <div class="rdleg-admin-card">
            <h2>Probíhající AI vyhodnocení</h2>
            <?php foreach ($rozpracovane as $rz): ?>
            <?php $rdleg_je_pozastaveno = isset($rdleg_pozastavena_zneni[$rz->id]); ?>
            <div id="rdleg-davka-wrap-<?php echo intval($rz->id); ?>" data-zneni-id="<?php echo intval($rz->id); ?>" data-davek-celkem="<?php echo intval($rz->davek_celkem); ?>" <?php echo $rdleg_je_pozastaveno ? 'data-pozastaveno="1"' : ''; ?>>
                <p class="desc">Soubor <strong><?php echo esc_html($rz->nazev_souboru); ?></strong> — dávka <span class="rdleg-davka-cislo"><?php echo intval($rz->davek_hotovo); ?></span> z <?php echo intval($rz->davek_celkem); ?> hotová.
                    <?php if ($rdleg_je_pozastaveno): ?>
                    <span class="rdleg-davka-stav" style="font-weight:600;color:#b91c1c">Pozastaveno — čeká na vaše spuštění</span>
                    <?php else: ?>
                    <span class="rdleg-davka-stav" style="font-weight:600;color:#7c3aed">Automaticky zpracovávám…</span>
                    <?php endif; ?>
                </p>
                <div style="background:#f3f4f6;border-radius:99px;height:10px;overflow:hidden;margin:8px 0 14px">
                    <div class="rdleg-davka-progress-bar" style="background:#7c3aed;height:100%;width:<?php echo $rz->davek_celkem > 0 ? round(($rz->davek_hotovo / $rz->davek_celkem) * 100) : 0; ?>%;transition:width .3s"></div>
                </div>
                <?php if ($rdleg_je_pozastaveno): ?>
                <form method="post" style="display:inline-block;margin-right:6px">
                    <?php wp_nonce_field('rdleg_spustit_znovu_nonce'); ?>
                    <input type="hidden" name="zneni_id" value="<?php echo intval($rz->id); ?>">
                    <button type="submit" name="rdleg_spustit_znovu" class="rdleg-btn-primary">▶ Spustit zpracování</button>
                </form>
                <form method="post" style="display:inline-block" onsubmit="return confirm('Uzavřít toto zpracování bez dokončení? Zbylé paragrafy zákona se už neposoudí. Záznam zmizí ze seznamu probíhajících a bude možné nahrát nové znění.');">
                    <?php wp_nonce_field('rdleg_uzavrit_nonce'); ?>
                    <input type="hidden" name="zneni_id" value="<?php echo intval($rz->id); ?>">
                    <button type="submit" name="rdleg_uzavrit_bez_dokonceni" class="rdleg-btn-secondary">Uzavřít bez dokončení</button>
                </form>
                <?php else: ?>
                <form method="post" class="rdleg-davka-form">
                    <?php wp_nonce_field('rdleg_davka_nonce'); ?>
                    <input type="hidden" name="zneni_id" value="<?php echo intval($rz->id); ?>">
                    <button type="submit" name="rdleg_zpracovat_davku" class="rdleg-btn-primary rdleg-davka-btn">Zpracovat další dávku ručně (~10-30 s)</button>
                </form>
                <form method="post" style="margin-top:6px" onsubmit="return confirm('Zastavit toto zpracování? Čekající návrhy i dosavadní postup zůstanou nedotčené — můžete pak pokračovat tlačítkem \'Spustit zpracování\'.');">
                    <?php wp_nonce_field('rdleg_zastavit_nonce'); ?>
                    <input type="hidden" name="zneni_id" value="<?php echo intval($rz->id); ?>">
                    <button type="submit" name="rdleg_zastavit" class="rdleg-btn-secondary" style="color:#b91c1c;border-color:#fca5a5">Zastavit zpracování</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <p class="desc" style="margin-top:10px">Dávky se zpracovávají automaticky na pozadí — stránku není potřeba obnovovat ani nikam klikat. Pokud automatické zpracování z nějakého důvodu neběží (např. vypnutý JavaScript), použijte tlačítko výše manuálně, dokud progress nedosáhne 100 %.</p>
        </div>
        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('rdleg_davka_nonce')); ?>;
            document.querySelectorAll('[id^="rdleg-davka-wrap-"]').forEach(function(wrap) {
                // Pozastavené zpracování (uživatel klikl "Zastavit zpracování") nesmí
                // automatika znovu rozjet sama — čeká na explicitní "Spustit zpracování".
                if (wrap.getAttribute('data-pozastaveno') === '1') return;

                var zneniId = wrap.getAttribute('data-zneni-id');
                var davekCelkem = parseInt(wrap.getAttribute('data-davek-celkem'), 10);
                var cisloEl = wrap.querySelector('.rdleg-davka-cislo');
                var stavEl = wrap.querySelector('.rdleg-davka-stav');
                var barEl = wrap.querySelector('.rdleg-davka-progress-bar');
                var formEl = wrap.querySelector('.rdleg-davka-form');

                function zpracujDalsi() {
                    var data = new URLSearchParams();
                    data.set('action', 'rdleg_zpracovat_davku_ajax');
                    data.set('nonce', nonce);
                    data.set('zneni_id', zneniId);
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if (!res.success) {
                                stavEl.textContent = 'Chyba: ' + (res.data && res.data.message ? res.data.message : 'neznámá chyba') + ' — použijte tlačítko níže manuálně.';
                                stavEl.style.color = '#b91c1c';
                                formEl.style.display = 'block';
                                return;
                            }
                            var v = res.data;
                            if (v.davek_celkem) davekCelkem = v.davek_celkem; // serverová hodnota je vždy autoritativní (může se lišit od počátečního atributu, pokud se RDLEG_DAVKA_VELIKOST mezitím změnila)
                            if (cisloEl) cisloEl.textContent = v.davka_cislo !== undefined ? v.davka_cislo : davekCelkem;
                            if (barEl && davekCelkem > 0) {
                                var pct = Math.round(((v.davka_cislo || davekCelkem) / davekCelkem) * 100);
                                barEl.style.width = pct + '%';
                            }
                            if (v.hotovo) {
                                stavEl.textContent = 'Hotovo — stránka se za chvíli obnoví.';
                                setTimeout(function(){ window.location.reload(); }, 1200);
                            } else {
                                zpracujDalsi();
                            }
                        })
                        .catch(function(){
                            stavEl.textContent = 'Spojení selhalo — použijte tlačítko níže manuálně.';
                            stavEl.style.color = '#b91c1c';
                            formEl.style.display = 'block';
                        });
                }

                if (formEl) formEl.style.display = 'none'; // skryté, dokud automatika běží; zobrazí se jen při chybě
                zpracujDalsi();
            });
        })();
        </script>
        <?php endif; ?>

        <?php if (!empty($ceka_admin_navrhy)): ?>
        <div class="rdleg-admin-card" style="border:2px solid rgba(124,58,237,.4)">
            <h2>Návrhy čekající na vaše schválení (<?php echo count($ceka_admin_navrhy); ?>)</h2>
            <p class="desc">Rozbalte návrhy níže a zkontrolujte je. Zaškrtávátkem u každého návrhu vyberte, co odešlete lektorovi a co rovnou zamítnete — výběr se dá měnit i po částech (např. nejdřív zamítněte pár konkrétních, zbytek pak odešlete lektorovi). Nebo návrhy přegenerujte novým AI voláním, případně celé zahoďte.</p>
            <div style="display:flex;gap:8px;margin-bottom:6px;flex-wrap:wrap;align-items:center">
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('rdleg_pregenerovat_nonce'); ?>
                    <button type="submit" name="rdleg_pregenerovat" class="rdleg-btn-secondary" onclick="return confirm('Zahodit všechny nynější návrhy a vygenerovat nové s aktuálním AI promptem? Vygenerované návrhy opět čekají na vaše schválení před odesláním lektorovi.');">↺ Přegenerovat návrhy znovu</button>
                </form>
                <?php $rdleg_konsolidace_skupiny = rdleg_ziskat_skupiny_ke_konsolidaci(); ?>
                <div id="rdleg-konsolidace-wrap" data-cile='<?php echo esc_attr(wp_json_encode(array_keys($rdleg_konsolidace_skupiny))); ?>' style="display:inline-flex">
                    <button type="button" id="rdleg-konsolidace-btn" class="rdleg-btn-secondary" title="Sloučí návrhy cílené na stejnou lekci do jednoho uceleného textu pomocí AI. Zpracovává se lekce po lekci (bezpečné i u víc lekcí najednou)." <?php echo empty($rdleg_konsolidace_skupiny) ? 'disabled' : ''; ?>>⊕ Konsolidovat lekce<?php echo !empty($rdleg_konsolidace_skupiny) ? ' (' . count($rdleg_konsolidace_skupiny) . ')' : ''; ?></button>
                </div>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('rdleg_smazat_vsechny_nonce'); ?>
                    <button type="submit" name="rdleg_smazat_vsechny_cekajici" class="rdleg-btn-secondary" style="color:#b91c1c;border-color:#fca5a5" onclick="return confirm('Zahodit všech <?php echo count($ceka_admin_navrhy) + count($ceka_navrhy); ?> návrhů (staging i odeslané lektorovi)? Tato akce je nevratná.');">✕ Zahodit všechny návrhy</button>
                </form>
            </div>
            <p id="rdleg-konsolidace-stav" style="font-size:13px;color:#6b7280;margin:0 0 14px;min-height:18px"></p>
            <script>
            (function(){
                var wrap = document.getElementById('rdleg-konsolidace-wrap');
                var btn = document.getElementById('rdleg-konsolidace-btn');
                var stavEl = document.getElementById('rdleg-konsolidace-stav');
                var cile = JSON.parse(wrap.getAttribute('data-cile') || '[]');
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode(wp_create_nonce('rdleg_konsolidovat_ajax_nonce')); ?>;

                btn.addEventListener('click', function(){
                    if (cile.length === 0) return;
                    if (!confirm('Sloučit ' + cile.length + ' ' + (cile.length === 1 ? 'lekci' : 'lekcí') + ' pomocí AI? Zpracuje se postupně, lekce po lekci.')) return;
                    btn.disabled = true;
                    var i = 0, hotovo = 0, chyby = 0;
                    function dalsi() {
                        if (i >= cile.length) {
                            stavEl.textContent = 'Hotovo — sloučeno ' + hotovo + ', chyb ' + chyby + '. Stránka se za chvíli obnoví.';
                            setTimeout(function(){ window.location.reload(); }, 1200);
                            return;
                        }
                        stavEl.style.color = '#6b7280';
                        stavEl.textContent = 'Slučuji lekci ' + (i + 1) + ' z ' + cile.length + ' (' + cile[i] + ')…';
                        var data = new URLSearchParams();
                        data.set('action', 'rdleg_konsolidovat_lekci_ajax');
                        data.set('nonce', nonce);
                        data.set('cil_id', cile[i]);
                        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
                            .then(function(r){ return r.json(); })
                            .then(function(res){
                                if (res.success) { hotovo++; } else { chyby++; }
                                i++;
                                dalsi();
                            })
                            .catch(function(){
                                chyby++;
                                i++;
                                dalsi();
                            });
                    }
                    dalsi();
                });
            })();
            </script>
            <form method="post" id="rdleg-vyber-form">
                <?php wp_nonce_field('rdleg_hromadna_akce_nonce'); ?>
                <div style="display:flex;gap:14px;margin-bottom:12px;align-items:center;flex-wrap:wrap;padding:10px 12px;background:#f9fafb;border-radius:8px">
                    <label style="font-size:12px;color:#374151;display:flex;align-items:center;gap:6px;cursor:pointer">
                        <input type="checkbox" id="rdleg-vyber-vse" class="rdleg-navrh-checkbox" checked onclick="document.querySelectorAll('.rdleg-navrh-checkbox[name]').forEach(function(cb){cb.checked = document.getElementById('rdleg-vyber-vse').checked;})">
                        Vybrat/zrušit vše
                    </label>
                    <button type="submit" name="rdleg_zobrazit_lektorovi_vybrane" class="rdleg-btn-primary" onclick="return confirm('Odeslat zaškrtnuté návrhy lektorovi ke schválení?');">✓ Odeslat vybrané lektorovi</button>
                    <button type="submit" name="rdleg_zamitnout_vybrane" class="rdleg-btn-secondary" style="color:#b91c1c;border-color:#fca5a5" onclick="return confirm('Zamítnout zaškrtnuté návrhy rovnou, bez odeslání lektorovi? Lektor je vůbec neuvidí.');">✕ Zamítnout vybrané</button>
                </div>
                <div class="rdleg-navrh-list">
                    <?php foreach ($ceka_admin_navrhy as $n): ?>
                    <?php
                        $aktualni_admin = rdleg_aktualni_obsah_cile($n->cilove_misto, $n->cilovy_identifikator);
                        $typ_obsahu_admin_cerstvy = ($aktualni_admin === null) ? 'nova' : 'uprava';
                    ?>
                    <details class="rdleg-navrh-item">
                        <summary class="rdleg-navrh-summary rdleg-navrh-summary-vyber">
                            <input type="checkbox" name="navrh_ids[]" value="<?php echo (int) $n->id; ?>" checked class="rdleg-navrh-checkbox" onclick="event.stopPropagation()">
                            <span class="rdleg-navrh-par"><?php echo esc_html($n->paragraf); ?></span>
                            <span><?php echo esc_html($n->nazev_zmeny ?: '—'); ?></span>
                            <span class="rdleg-badge" style="color:<?php echo $typ_barvy[$n->typ_zmeny] ?? '#374151;background:#f3f4f6'; ?>"><?php echo esc_html($typ_popisky[$n->typ_zmeny] ?? $n->typ_zmeny); ?></span>
                            <span class="rdleg-badge rdleg-badge-obsah-<?php echo esc_attr($typ_obsahu_admin_cerstvy); ?>"><?php echo esc_html($obsah_popisky[$typ_obsahu_admin_cerstvy] ?? $typ_obsahu_admin_cerstvy); ?></span>
                            <span class="rdleg-navrh-cil"><?php echo esc_html($cil_popisky[$n->cilove_misto] ?? $n->cilove_misto); ?><?php if ($n->cilovy_identifikator): ?> (<?php echo esc_html($n->cilovy_identifikator); ?>)<?php endif; ?></span>
                        </summary>
                        <div class="rdleg-navrh-detail">
                            <p style="margin:6px 0;font-size:13px"><strong>Shrnutí změny:</strong> <?php echo esc_html($n->shrnuti_zmeny); ?></p>
                            <?php if ($n->typ_zmeny !== 'novy' && $n->stary_text): ?>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0">
                                <div style="background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Dříve:</strong><br><?php echo nl2br(esc_html($n->stary_text)); ?></div>
                                <div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Nově:</strong><br><?php echo nl2br(esc_html($n->novy_text)); ?></div>
                            </div>
                            <?php endif; ?>
                            <?php if ($aktualni_admin !== null): ?>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0">
                                <div style="background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Aktuálně na webu:</strong><br><?php echo nl2br(esc_html($aktualni_admin)); ?></div>
                                <div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Navrhovaná úprava:</strong><br><?php echo ($n->cilove_misto === 'kviz_otazka') ? rdleg_navrh_otazky_citelne($n->navrh_upravy) : nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy))); ?></div>
                            </div>
                            <?php else: ?>
                            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:13px"><strong>Navrhovaná úprava:</strong><br><?php echo ($n->cilove_misto === 'kviz_otazka') ? rdleg_navrh_otazky_citelne($n->navrh_upravy) : nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy))); ?></div>
                            <?php endif; ?>
                            <p style="font-size:11px;color:#9ca3af;margin:8px 0 0">Soubor: <?php echo esc_html($n->nazev_souboru); ?></p>
                        </div>
                    </details>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!empty($ceka_navrhy)): ?>
        <div class="rdleg-admin-card">
            <h2 style="display:flex;align-items:center;justify-content:space-between;gap:10px">
                <span>Odesláno lektorovi ke schválení (<?php echo count($ceka_navrhy); ?>)</span>
                <form method="post" style="margin:0" onsubmit="return confirm('Zahodit všech <?php echo count($ceka_navrhy); ?> návrhů odeslaných lektorovi? Lektor je přestane vidět.');">
                    <?php wp_nonce_field('rdleg_smazat_vsechny_nonce'); ?>
                    <button type="submit" name="rdleg_smazat_vsechny_cekajici" class="rdleg-btn-secondary" style="color:#b91c1c;border-color:#fca5a5">✕ Zahodit vše</button>
                </form>
            </h2>
            <p class="desc">Lektor tyto návrhy vidí ve svém portálu a může je schválit nebo zamítnout. Zde jen náhled — schvalování provádí výhradně lektor.</p>
            <div class="rdleg-navrh-list">
                <?php foreach ($ceka_navrhy as $n): ?>
                <?php
                    // Čerstvý výpočet, NE ze sloupce $n->typ_obsahu (ukládá se jen jednou při
                    // vzniku návrhu a u starších čekajících návrhů může být zastaralý) — stejná
                    // oprava jako v1.93.0 na frontendu, jen tady ve front-endu admin stránky
                    // zůstala dosud neopravená.
                    $aktualni_admin = rdleg_aktualni_obsah_cile($n->cilove_misto, $n->cilovy_identifikator);
                    $typ_obsahu_admin_cerstvy = ($aktualni_admin === null) ? 'nova' : 'uprava';
                ?>
                <details class="rdleg-navrh-item">
                    <summary class="rdleg-navrh-summary">
                        <span class="rdleg-navrh-par"><?php echo esc_html($n->paragraf); ?></span>
                        <span><?php echo esc_html($n->nazev_zmeny ?: '—'); ?></span>
                        <span class="rdleg-badge" style="color:<?php echo $typ_barvy[$n->typ_zmeny] ?? '#374151;background:#f3f4f6'; ?>"><?php echo esc_html($typ_popisky[$n->typ_zmeny] ?? $n->typ_zmeny); ?></span>
                        <span class="rdleg-badge rdleg-badge-obsah-<?php echo esc_attr($typ_obsahu_admin_cerstvy); ?>"><?php echo esc_html($obsah_popisky[$typ_obsahu_admin_cerstvy] ?? $typ_obsahu_admin_cerstvy); ?></span>
                        <span class="rdleg-navrh-cil"><?php echo esc_html($cil_popisky[$n->cilove_misto] ?? $n->cilove_misto); ?><?php if ($n->cilovy_identifikator): ?> (<?php echo esc_html($n->cilovy_identifikator); ?>)<?php endif; ?></span>
                    </summary>
                    <div class="rdleg-navrh-detail">
                        <p style="margin:6px 0;font-size:13px"><strong>Shrnutí změny:</strong> <?php echo esc_html($n->shrnuti_zmeny); ?></p>
                        <?php if ($n->typ_zmeny !== 'novy' && $n->stary_text): ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0">
                            <div style="background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Dříve:</strong><br><?php echo nl2br(esc_html($n->stary_text)); ?></div>
                            <div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Nově:</strong><br><?php echo nl2br(esc_html($n->novy_text)); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php $aktualni = $aktualni_admin; ?>
                        <?php if ($aktualni !== null): ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0">
                            <div style="background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Aktuálně na webu:</strong><br><?php echo nl2br(esc_html($aktualni)); ?></div>
                            <div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Navrhovaná úprava:</strong><br><?php echo ($n->cilove_misto === 'kviz_otazka') ? rdleg_navrh_otazky_citelne($n->navrh_upravy) : nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy))); ?></div>
                        </div>
                        <?php else: ?>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:13px"><strong>Navrhovaná úprava:</strong><br><?php echo ($n->cilove_misto === 'kviz_otazka') ? rdleg_navrh_otazky_citelne($n->navrh_upravy) : nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy))); ?></div>
                        <?php endif; ?>
                        <p style="font-size:11px;color:#9ca3af;margin:8px 0 0">Soubor: <?php echo esc_html($n->nazev_souboru); ?></p>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            </div>
        </details>
        <?php endif; ?>

        <?php if (!empty($vyhodnoceni_log)): ?>
        <div class="rdleg-admin-card">
            <details>
                <summary style="cursor:pointer"><h2 style="display:inline">Vyhodnoceno bez návrhu (<?php echo count($vyhodnoceni_log); ?>)</h2></summary>
                <p class="desc">Tyto paragrafy AI posoudila a usoudila, že nevyžadují žádnou změnu — s důvodem, který uvedla. Slouží k ověření, že "nic nenalezeno" dává smysl, ne jen k černé skříňce.</p>
                <div class="rdleg-navrh-list">
                    <?php foreach ($vyhodnoceni_log as $par => $zaznam): ?>
                    <div class="rdleg-navrh-item" style="padding:8px 12px">
                        <span class="rdleg-navrh-par"><?php echo esc_html($par); ?></span>
                        <span style="font-size:13px;color:#6b7280"><?php echo esc_html($zaznam['duvod'] ?? ''); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <?php if (!empty($trvale_zamitnute)): ?>
        <div class="rdleg-admin-card">
            <h2>Odmítnuté lektorem (<?php echo count($trvale_zamitnute); ?>)</h2>
            <p class="desc">Lektor tyto změny odmítl s poznámkou — pro toto kolo se mu znovu nezobrazí. Pokud problém na webu přetrvává, AI ho znovu nabídne při dalším nahrání zákona.</p>
            <div class="rdleg-navrh-list">
                <?php foreach ($trvale_zamitnute as $n): ?>
                <details class="rdleg-navrh-item">
                    <summary class="rdleg-navrh-summary">
                        <span class="rdleg-navrh-par"><?php echo esc_html($n->paragraf); ?></span>
                        <span><?php echo esc_html($n->nazev_zmeny ?: '—'); ?></span>
                        <span class="rdleg-badge" style="color:#b91c1c;background:#fee2e2">Odmítnuto</span>
                        <span class="rdleg-navrh-cil"><?php echo esc_html(date('d.m.Y', strtotime($n->vyreseno))); ?><?php if ($n->vyresil_kdo): ?> · <?php echo esc_html($n->vyresil_kdo); ?><?php endif; ?></span>
                    </summary>
                    <div class="rdleg-navrh-detail">
                        <p style="margin:6px 0;font-size:13px"><strong>Poznámka lektora k odmítnutí:</strong> <?php echo esc_html($n->poznamka_zamitnuti ?: '—'); ?></p>
                        <p style="margin:6px 0;font-size:13px"><strong>Původní shrnutí:</strong> <?php echo esc_html($n->shrnuti_zmeny); ?></p>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($vyrizene)): ?>
        <details class="rdleg-admin-card">
            <summary style="cursor:pointer;font-weight:600;padding:14px 18px;display:flex;align-items:center;justify-content:space-between">
                <span>Historie vyřízených návrhů (<?php echo count($vyrizene); ?>)</span>
                <form method="post" onsubmit="return confirm('Smazat celou historii vyřízených návrhů? Tohle maže jen záznamy historie/audit — schválený obsah, který už je na webu (lekce, otázky, novinky, FAQ), zůstane beze změny.');event.stopPropagation();" style="margin:0">
                    <?php wp_nonce_field('rdleg_smazat_historii_nonce'); ?>
                    <button type="submit" name="rdleg_smazat_historii_vse" value="1" style="font-size:11px;font-weight:600;padding:5px 12px;border:1px solid #fca5a5;background:transparent;border-radius:99px;cursor:pointer;color:#b91c1c">Smazat historii</button>
                </form>
            </summary>
            <div style="padding:0 18px 18px">
            <p class="desc">Posledních 50 návrhů, které lektor schválil nebo zamítl. U schválených je vidět i aktuální stav na webu — pokud byl obsah mezitím obnoven na výchozí hodnoty, citace zákona zmizela s ním.</p>
            <div class="rdleg-navrh-list">
                <?php foreach ($vyrizene as $n): ?>
                <details class="rdleg-navrh-item">
                    <summary class="rdleg-navrh-summary rdleg-navrh-summary-hist">
                        <span class="rdleg-navrh-par"><?php echo esc_html($n->paragraf); ?></span>
                        <span><?php echo esc_html($n->nazev_zmeny ?: '—'); ?></span>
                        <?php if ($n->stav === 'schvaleno'): ?>
                        <span class="rdleg-badge" style="color:#15803d;background:#dcfce7">✓ Schváleno</span>
                        <?php elseif ($n->stav === 'smazano'): ?>
                        <span class="rdleg-badge" style="color:#92400e;background:#fef3c7">🗑 Novinka smazána</span>
                        <?php else: ?>
                        <span class="rdleg-badge" style="color:#b91c1c;background:#fee2e2">✗ Zamítnuto</span>
                        <?php endif; ?>
                        <span class="rdleg-navrh-cil"><?php echo esc_html(date('d.m.Y H:i', strtotime($n->vyreseno))); ?><?php if ($n->vyresil_kdo): ?> · <?php echo esc_html($n->vyresil_kdo); ?><?php endif; ?></span>
                    </summary>
                    <div class="rdleg-navrh-detail">
                        <form method="post" onsubmit="return confirm('Smazat tento jeden záznam z historie? Schválený obsah na webu (pokud byl schválen) zůstane beze změny — maže se jen tenhle záznam historie.');" style="text-align:right;margin:0 0 6px">
                            <?php wp_nonce_field('rdleg_smazat_historii_nonce'); ?>
                            <input type="hidden" name="rdleg_smazat_historii_jeden" value="<?php echo intval($n->id); ?>">
                            <button type="submit" style="font-size:11px;padding:3px 10px;border:1px solid #e5e7eb;background:transparent;border-radius:99px;cursor:pointer;color:#9ca3af">Smazat ze historie</button>
                        </form>
                        <p style="margin:6px 0;font-size:13px"><strong>Shrnutí změny:</strong> <?php echo esc_html($n->shrnuti_zmeny); ?></p>
                        <?php if ($n->typ_zmeny !== 'novy' && $n->stary_text): ?>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0">
                            <div style="background:#fef2f2;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Dříve:</strong><br><?php echo nl2br(esc_html($n->stary_text)); ?></div>
                            <div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;max-height:160px;overflow:auto"><strong>Nově:</strong><br><?php echo nl2br(esc_html($n->novy_text)); ?></div>
                        </div>
                        <?php endif; ?>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:13px"><strong>Navrhovaná úprava:</strong><br><?php echo ($n->cilove_misto === 'kviz_otazka') ? rdleg_navrh_otazky_citelne($n->navrh_upravy) : nl2br(esc_html(rdleg_ocistit_html($n->navrh_upravy))); ?></div>
                        <?php if ($n->stav === 'schvaleno' && $n->upraveny_text_lektorem && $n->upraveny_text_lektorem !== $n->navrh_upravy): ?>
                        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;font-size:13px;margin-top:8px"><strong>Lektorem upraveno na:</strong><br><?php echo nl2br(esc_html(rdleg_ocistit_html($n->upraveny_text_lektorem))); ?></div>
                        <?php endif; ?>
                        <p style="font-size:11px;color:#9ca3af;margin:8px 0 0">Cíl: <?php echo esc_html($cil_popisky[$n->cilove_misto] ?? $n->cilove_misto); ?><?php if ($n->cilovy_identifikator): ?> (<?php echo esc_html($n->cilovy_identifikator); ?>)<?php endif; ?> · Soubor: <?php echo esc_html($n->nazev_souboru); ?>
                            · <span class="rdleg-badge rdleg-badge-obsah-<?php echo esc_attr($n->typ_obsahu); ?>"><?php echo esc_html($obsah_popisky[$n->typ_obsahu] ?? $n->typ_obsahu); ?></span>
                        </p>
                        <?php if ($n->stav === 'schvaleno'): ?>
                            <?php if ($n->rdleg_live_info === false): ?>
                            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:12px;margin-top:8px;color:#92400e">⚠ Záznam na webu pod tímto ID už neexistuje. Nejčastější příčina: lekce/otázky/novinky se v hlavním pluginu při KAŽDÉM uložení té záložky (i nesouvisející úpravy) přeukládají celé znovu, takže dostanou nová ID — obsah pravděpodobně stále existuje, jen pod jiným číslem, ne že by zmizel. Reset na výchozí hodnoty je jen jedna z možných (méně častých) příčin.</div>
                            <?php elseif (is_array($n->rdleg_live_info)): ?>
                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;font-size:12px;margin-top:8px;color:#166534">✓ Aktuálně na webu: <strong><?php echo esc_html($n->rdleg_live_info['misto']); ?></strong> — <?php echo esc_html($n->rdleg_live_info['zaznam']); ?><?php if ($n->rdleg_live_info['zdroj']): ?><br><span style="opacity:.85">Citace: <?php echo esc_html($n->rdleg_live_info['zdroj']); ?></span><?php endif; ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="rdleg-admin-card">
            <h2>Historie nahraných znění</h2>
            <p class="desc">Zde ručně nastavte roli každého nahraného znění a spusťte porovnání, až budete mít jistotu, že jsou obě znění správně přiřazená.</p>
            <?php
            $rdleg_ma_aktualni = false;
            foreach ($historie_zneni as $h) { if ($h->je_aktivni_reference) { $rdleg_ma_aktualni = true; break; } }
            ?>
            <?php if ($rdleg_ma_aktualni): ?>
            <form method="post" style="margin-bottom:16px;padding-bottom:16px;border-bottom:2px solid #7c3aed">
                <?php wp_nonce_field('rdleg_komplet_nonce'); ?>
                <p class="desc" style="margin:0 0 10px;font-weight:600;color:#5b21b6">★ HLAVNÍ KONTROLA: projde celý aktuální zákon a porovná ho s celým obsahem webu (Novinky, Legislativa, lekce, testové otázky). Navrhne JEN to, co je potřeba na webu PŘIDAT (chybí), UPRAVIT (je špatně/zastarale) nebo ODEBRAT (už neplatí). O správných informacích, které už na webu jsou, vás neinformuje. Zpracování běží automaticky.</p>
                <button type="submit" name="rdleg_komplet_kontrola" class="rdleg-btn-primary" onclick="return confirm('Spustit kompletní kontrolu celého webu proti celému zákonu? Projde se ~190 paragrafů, vznikne hodně dávek a AI volání.');">Spustit kompletní kontrolu webu</button>
            </form>
            <?php endif; ?>
            <details style="margin-bottom:20px">
                <summary style="cursor:pointer;font-size:13px;color:#6b7280">Pokročilé volby (oddělené režimy)</summary>
                <?php if ($rdleg_ma_aktualni): ?>
                <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                    <?php wp_nonce_field('rdleg_spustit_porovnani_nonce'); ?>
                    <p class="desc" style="margin:0 0 10px">Jen porovnání "Aktuálního znění" se "Zněním před novelou" — posoudí pouze paragrafy, které se mezi verzemi změnily (detekce novinek z novely). Užší než hlavní kontrola.</p>
                    <button type="submit" name="rdleg_spustit_porovnani" class="rdleg-btn-secondary" onclick="return confirm('Spustit porovnání mezi Aktuálním zněním a Zněním před novelou?');">Jen porovnání novely</button>
                </form>
                <?php endif; ?>
                <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                    <?php wp_nonce_field('rdleg_kontrola_testu_nonce'); ?>
                    <p class="desc" style="margin:0 0 10px">AI projde aktuální obsah všech lekcí a porovná ho s testovými otázkami — navrhne úpravy otázek nebo odpovědí, které jsou v rozporu nebo zastaralé. Zdrojem pravdy jsou pouze lekce (ne zákon), takže AI nemůže nic vymyslet.</p>
                    <button type="submit" name="rdleg_kontrola_testu" class="rdleg-btn-secondary" onclick="return confirm('Spustit kontrolu testových otázek podle aktuálního obsahu lekcí?');">Zkontrolovat test</button>
                </form>
            </details>
            <div style="overflow-x:auto">
            <table class="rdleg-admin-t" style="min-width:700px">
                <thead><tr><th>Soubor</th><th>Stav</th><th>Nahráno</th><th>Kdo</th><th>Délka textu</th><th>Délka dat paragrafů</th><th></th></tr></thead>
                <tbody>
                    <?php if (empty($historie_zneni)): ?>
                        <tr><td colspan="7">Zatím nebylo nahráno žádné znění.</td></tr>
                    <?php else: $rdleg_pozastavena_historie = get_option('rdleg_pozastavena_zneni', []); if (!is_array($rdleg_pozastavena_historie)) $rdleg_pozastavena_historie = []; foreach ($historie_zneni as $h): ?>
                        <tr>
                            <td><?php echo esc_html($h->nazev_souboru); ?></td>
                            <td><?php echo $h->je_aktivni_reference ? '<span class="rdleg-badge" style="color:#15803d;background:#dcfce7">Aktuální znění</span>' : '<span class="rdleg-badge" style="color:#6b7280;background:#f3f4f6">Znění před novelou</span>'; ?></td>
                            <td><?php echo esc_html(date('d.m.Y H:i', strtotime($h->nahrano))); ?></td>
                            <td><?php echo esc_html($h->nahral_uzivatel); ?></td>
                            <td><?php echo number_format((int)$h->delka_textu, 0, ',', ' '); ?> znaků</td>
                            <td><?php echo number_format((int)$h->delka_json, 0, ',', ' '); ?> znaků</td>
                            <td style="white-space:nowrap">
                                <?php if (!$h->je_aktivni_reference): ?>
                                <form method="post" style="display:inline-block;margin-right:4px">
                                    <?php wp_nonce_field('rdleg_nastavit_roli_nonce'); ?>
                                    <input type="hidden" name="zneni_id_role" value="<?php echo intval($h->id); ?>">
                                    <input type="hidden" name="nova_role" value="aktualni">
                                    <button type="submit" name="rdleg_nastavit_roli" class="rdleg-btn-secondary" style="padding:5px 12px;font-size:12px">Nastavit jako Aktuální</button>
                                </form>
                                <?php else: ?>
                                <form method="post" style="display:inline-block;margin-right:4px">
                                    <?php wp_nonce_field('rdleg_nastavit_roli_nonce'); ?>
                                    <input type="hidden" name="zneni_id_role" value="<?php echo intval($h->id); ?>">
                                    <input type="hidden" name="nova_role" value="pred_novelou">
                                    <button type="submit" name="rdleg_nastavit_roli" class="rdleg-btn-secondary" style="padding:5px 12px;font-size:12px">Nastavit jako Před novelou</button>
                                </form>
                                <?php endif; ?>
                                <?php
                                // Mazání: vždy povoleno pro "Znění před novelou". Pro "Aktuální znění"
                                // jen pokud nemá rozpracované/aktivně běžící AI zpracování — pokud běží
                                // (zpracovani_hotovo=0 a NENÍ pozastavené), musí se nejdřív zastavit, aby
                                // se nesmazaly podklady pod běžící automatikou. Pozastavené smazat lze.
                                $rdleg_aktivne_bezi = (!$h->zpracovani_hotovo) && !isset($rdleg_pozastavena_historie[$h->id]);
                                if (!$rdleg_aktivne_bezi):
                                ?>
                                <form method="post" style="display:inline-block" onsubmit="return confirm('Smazat tento záznam? Tuto akci nelze vrátit zpět.');">
                                    <?php wp_nonce_field('rdleg_smazat_zneni_nonce'); ?>
                                    <input type="hidden" name="zneni_id_smazat" value="<?php echo intval($h->id); ?>">
                                    <button type="submit" name="rdleg_smazat_zneni" class="rdleg-btn-secondary" style="padding:5px 12px;font-size:12px;color:#b91c1c;border-color:#fca5a5">Smazat</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            <p style="font-size:12px;color:#9ca3af;margin-top:10px">Pro orientaci: kompletní znění zákona č. 361/2000 Sb. má cca 370 000+ znaků a rozdělí se na cca 190+ paragrafů. Výrazně nižší čísla mohou znamenat, že se zpracování souboru nedokončilo celé.</p>
        </div>
    </div>
    <?php
}
