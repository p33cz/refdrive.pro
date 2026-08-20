<?php
if (!defined('ABSPATH')) exit;

/**
 * Centrální popis automatizovaných mechanismů platformy RefDrive.pro.
 * Tento text je sestaven na základě skutečné implementace v kódu
 * (cron joby, e-mailové notifikace, stavové přechody) — NE z FAQ databáze.
 * Slouží jako doplňkový kontext pro AI asistenty (autoškola i firma),
 * aby uměli přesně odpovídat na otázky o tom, "jak to funguje".
 *
 * Při změně automatizační logiky v hlavním pluginu je třeba tento
 * dokument aktualizovat, jinak agenti budou vycházet ze zastaralých informací.
 */

function rdai_get_platform_mechanics_kontext() {
    $text = "TECHNICKÉ MECHANISMY PLATFORMY REFDRIVE.PRO (automatizace, notifikace):\n\n";

    $text .= "1) EXPIRACE NEVYUŽITÝCH KÓDŮ — AUTOMATICKÁ NOTIFIKACE FIRMĚ:\n"
        . "Každý den v 8:00 systém zkontroluje všechny nevyužité kódy (pouzit=0), kterým zbývá přesně 30 dní do expirace. "
        . "Pro každou firmu, která má takové kódy, se automaticky odešle e-mail na firemní e-mailovou adresu se seznamem kódů a daty expirace, "
        . "s upozorněním, aby zaměstnanci absolvovali školení včas. Toto se odesílá automaticky, firma o to nemusí žádat.\n\n";

    $text .= "2) PŘEDPLATNÉ AUTOŠKOLY — AUTOMATICKÉ NOTIFIKACE:\n"
        . "Každý den v 9:00 systém kontroluje stav předplatného každé autoškoly. Probíhá ve třech fázích:\n"
        . "- 7 dní před expirací: autoškole se odešle e-mail s upozorněním, že předplatné vyprší za 7 dní, a odkazem na obnovení balíčku.\n"
        . "- V den expirace (den 0): kapacita kódů autoškoly se nastaví na neomezenou (interně 99999, což ve výpočtech znamená 'bez limitu pro nové kódy', ale prakticky autoškola už nemůže nové kódy efektivně nabízet bez aktivního předplatného), spustí se 'grace období', odešle se e-mail autoškole i interní notifikace administrátorovi platformy.\n"
        . "- 7 dní po expiraci (konec grace období): pokud autoškola předplatné neobnovila, stav se změní na 'predplatne_expirovano' — profil autoškoly zmizí ze seznamu autoškol viditelného firmám/zákazníkům. Odešle se e-mail autoškole i administrátorovi.\n\n";

    $text .= "3) POPTÁVKOVÝ FLOW — AUTOMATICKÁ KONTROLA KAŽDOU HODINU:\n"
        . "Pokud firma poptá víc kódů, než kolik má autoškola volné kapacity, vznikne poptávka ve stavu 'nova'. "
        . "Každou hodinu systém zkontroluje poptávky starší 12 hodin, které jsou stále 'nova':\n"
        . "- Pokud autoškola mezitím obnovila kapacitu (např. zvýšila tier nebo jí klesl měsíční odběr), firma dostane e-mail 'kapacita obnovena' s odkazem na objednávkový formulář u dané autoškoly, a poptávka se uzavře (stav 'kapacita_obnovena').\n"
        . "- Pokud kapacita stále chybí, systém zkusí nabídnout alternativní autoškolu (pokud byla u poptávky určena) nebo firmu informuje/omluví se, podle aktuální dostupnosti.\n"
        . "- Firma může poptávku kdykoliv předtím zrušit tlačítkem 'Nechci čekat'.\n\n";

    $text .= "4) CERTIFIKÁTY:\n"
        . "Po úspěšném absolvování testu (skóre 80 % a více) se vygeneruje certifikát s unikátním identifikátorem. "
        . "Certifikát je dostupný jako webová stránka (HTML náhled) přes zabezpečený odkaz s přístupovým tokenem — "
        . "v aktuální verzi platformy NEJDE o stažitelné PDF, ale o HTML stránku, kterou lze otevřít v prohlížeči, vytisknout nebo uložit jako PDF přes tiskovou funkci prohlížeče. "
        . "Hromadný export 'Certifikáty (ZIP)' ve firemním portálu stahuje tyto HTML stránky zabalené v ZIP archivu.\n\n";

    $text .= "5) EMAILOVÁ ADRESA PRO INTERNÍ NOTIFIKACE PLATFORMY:\n"
        . "Administrátor platformy má nastavenou e-mailovou adresu, na kterou chodí veškerá komunikace mezi autoškolami a platformou — registrace, schválení, expirace předplatného, nové objednávky apod. "
        . "Tato adresa slouží primárně pro vnitřní provoz platformy, autoškoly a firmy ji běžně nepotřebují znát.\n\n";

    $text .= "5) OBNOVENÍ PŘEDPLATNÉHO PO EXPIRACI — ŽÁDNÁ NOVÁ REGISTRACE:\n"
        . "Pokud autoškole vypršelo předplatné a profil zmizel ze seznamu (stav 'predplatne_expirovano'), "
        . "k návratu NENÍ potřeba nová registrace ani nové schválení. Stačí uhradit platbu balíčku (přes 'Obnovit předplatné' v administraci) a po potvrzení platby "
        . "se stav účtu automaticky vrátí na 'aktivni', obnoví se měsíční limit kódů a profil je znovu viditelný v seznamu autoškol pro firmy/zákazníky. "
        . "Existující data (napojené firmy, historie kódů, řidiči) zůstávají zachována. "
        . "Toto obnovení je možné kdykoliv BĚHEM PRVNÍCH 6 MĚSÍCŮ od deaktivace — viz bod 6 pro lhůtu a co se stane po jejím uplynutí.\n\n";

    $text .= "6) LHŮTA 6 MĚSÍCŮ A ANONYMIZACE PROFILU PŘI DLOUHODOBÉ NEAKTIVITĚ:\n"
        . "Pokud autoškola po deaktivaci (vypršení předplatného) neobnoví předplatné, platí tato časová osa:\n"
        . "- Den 0 (vypršení): profil zmizí ze seznamu, kapacita kódů nulová. Email autoškole + administrátorovi.\n"
        . "- 150 dní po vypršení (cca 5 měsíců): pokud stále neobnoveno, odešle se VAROVNÝ EMAIL — upozornění, že za 30 dní dojde k anonymizaci profilu, a DOPORUČENÍ stáhnout si export absolventů (CSV) a jejich certifikáty (ZIP), protože zaměstnavatelé mají ZÁKONNOU POVINNOST tyto doklady o školení archivovat.\n"
        . "- 180 dní po vypršení (6 měsíců): pokud stále neobnoveno, profil autoškoly se AUTOMATICKY ANONYMIZUJE — odstraní se název, email, telefon, IČO, DIČ a adresa autoškoly, napojení na firmy se zruší. Stav účtu se změní na 'anonymizovano'.\n"
        . "- CO ZŮSTANE ZACHOVÁNO i po anonymizaci: agregovaná historie absolventů (jména řidičů, výsledky testů, data školení, certifikáty) v tabulce řidičů — tato data slouží k archivaci pro zaměstnavatele a NEJSOU mazána.\n"
        . "- CO SE ANONYMIZUJE/SMAŽE: identifikační a kontaktní údaje samotné autoškoly (název, email, IČO, adresa) a vazby na napojené firmy.\n"
        . "- Po anonymizaci NENÍ možný standardní návrat k 'aktivni' obnovením platby — profil je trvale anonymizován. Pro znovuobnovení provozu by bylo nutné novou registraci/zásah administrátora platformy.\n\n";

    $text .= "7) DOBA UCHOVÁNÍ DAT O ABSOLVOVANÝCH ŠKOLENÍCH (ŘIDIČI/CERTIFIKÁTY) — 2 ROKY:\n"
        . "Platforma RefDrive.pro standardně uchovává záznamy o proškolených řidičích (jméno, výsledek testu, datum, certifikát) po dobu 2 let od data absolvování (datum_skoleni). "
        . "30 dní před uplynutím této lhůty systém automaticky odešle firmě e-mail s upozorněním, že záznamy a certifikáty budou za 30 dní trvale smazány, a doporučí stáhnout export absolventů (CSV) a certifikáty (ZIP). "
        . "Po uplynutí přesně 2 let jsou záznamy AUTOMATICKY A TRVALE smazány — včetně možnosti veřejného ověření certifikátu (viz bod 8), to už po smazání nefunguje. "
        . "ZÁSADNÍ UPOZORNĚNÍ — TATO 2LETÁ LHŮTA JE SLUŽBA, NE ZÁRUKA: RefDrive.pro neposkytuje žádnou garanci, že data budou po celé 2 roky dostupná (možné výjimky: technická chyba, anonymizace profilu autoškoly při dlouhodobé neaktivitě, vyšší moc). "
        . "Zákonnou povinnost ARCHIVOVAT doklady o proškolení svých zaměstnanců má VŽDY ZAMĚSTNAVATEL (firma), nikoliv RefDrive.pro — a tato povinnost trvá obvykle déle než 2 roky (po dobu trvání pracovního poměru, doporučeně i několik let po jeho skončení). "
        . "Z tohoto důvodu DŮRAZNĚ DOPORUČUJ firmě stáhnout si certifikát IHNED PO VYSTAVENÍ, nečekat na 30denní upozornění — odpovědnost je vždy na zaměstnavateli. "
        . "Pokud se uživatel zeptá 'jak dlouho RefDrive uchovává naše data o absolventech' nebo 'je to bezpečné spoléhat se na to', odpověz: standardně 2 roky, ale jde o službu bez záruky — doporuč okamžité stažení a vlastní archivaci, protože zákonná odpovědnost je na firmě.\n\n";

    $text .= "8) VEŘEJNÉ OVĚŘENÍ CERTIFIKÁTU (funguje 2 roky od vydání, poté záznam zmizí):\n"
        . "Každý vydaný certifikát má unikátní identifikátor (cert_uuid). Platforma má veřejnou ověřovací stránku na adrese /overit/?id=CERT_UUID — "
        . "kdokoliv s tímto identifikátorem (např. úřad, kontrola, zaměstnavatel) si může ověřit, že certifikát je platný, a zobrazit jméno řidiče, firmu a datum absolvování. "
        . "Toto ověření funguje BEZ přístupového tokenu, ale jen do doby, než je záznam smazán podle 2leté retenční lhůty (bod 7) — po jejím uplynutí ověření vrátí 'certifikát nenalezen'.\n\n";

    $text .= "9) PLATNOST/EXPIRACE JEDNOTLIVÝCH KÓDŮ (rd_kody.expiruje) — VIDITELNÉ V UI:\n"
        . "Každý vygenerovaný kód má své vlastní datum expirace (sloupec 'expiruje'), typicky 1 rok od vygenerování (výchozí nastavení 365 dní, lze administrátorem změnit). Po tomto datu kód nelze použít ke školení. "
        . "30 dní před expirací nevyužitého kódu systém odešle firmě automatický e-mail (viz bod 1). "
        . "Ve firemním portálu se navíc PŘÍMO NA STRÁNCE zobrazují dva banner: žlutý/amber banner 'Počet nevyužitých kódů expirujících do 30 dní' (pokud > 0) a červený banner 'Počet expirovaných nevyužitých kódů' (pokud > 0) — oba nad tabulkou 'Přehled řidičů'. "
        . "V tabulce 'Přehled řidičů' jsou navíc viditelné tři samostatné datumové sloupce — nepleť si je: "
        . "'Absolvováno' = datum dokončení školení (kdy řidič udělal test), prázdné pokud kód ještě nebyl využit; "
        . "'Zakoupeno' = datum, kdy byl kód vygenerován/zakoupen (rd_kody.vytvoreno); "
        . "'Platnost do' = datum expirace kódu (rd_kody.expiruje) — pokud je kód nevyužitý A toto datum už uplynulo, je v tabulce zvýrazněno červeně. "
        . "Toto datum expirace je VLASTNOST KÓDU SAMOTNÉHO — je nezávislé na stavu autoškoly, která kód vydala.\n\n";

    $text .= "10) CO SE STANE S NEVYUŽITÝMI KÓDY FIRMY, POKUD JE AUTOŠKOLA ANONYMIZOVÁNA/DEAKTIVOVÁNA:\n"
        . "Anonymizace nebo deaktivace autoškoly (body 2 a 6) NEMÁ ŽÁDNÝ přímý vliv na tabulku kódů (rd_kody) — existující kódy firmy zůstávají v databázi se svým původním stavem (využitý/nevyužitý) a svým vlastním datem expirace (bod 9) beze změny. "
        . "Jediná změna je, že se ukončí VAZBA mezi firmou a autoškolou (rd_firma_autoskola) — firma už neuvidí tuto autoškolu jako 'kmenovou' pro nové objednávky. "
        . "Pro JIŽ VYDANÉ kódy to v praxi znamená: kód je platný a použitelný až do svého data expirace, bez ohledu na to, že autoškola byla anonymizována. "
        . "OTEVŘENÁ OTÁZKA — PŘIZNAT NEJISTOTU: pokud kód EXPIRUJE jako nevyužitý PŘESNĚ V DOBĚ, kdy je autoškola už anonymizovaná/nedostupná, mechanismus REKLAMACE/VÝMĚNY takového kódu (vrácení peněz firmě) NENÍ v současné verzi platformy implementován jako automatizovaný proces. "
        . "Pokud se uživatel zeptá na tento konkrétní scénář (expirace kódu PO anonymizaci autoškoly), NEVYMÝŠLEJ konkrétní postup/garance — vysvětli, že kód samotný zůstává nezávisle platný do svého data expirace, ale že konkrétní řešení v případě nedostupné autoškoly doporučuješ probrat s podporou RefDrive.pro, protože automatizovaný proces pro tento edge-case není zdokumentovaný.\n\n";

    $text .= "OBECNÉ PRAVIDLO PRO ODPOVÍDÁNÍ: pokud se otázka týká toho, ZDA se něco děje automaticky (notifikace, upozornění, kontroly), "
        . "vycházej z tohoto popisu mechanismů — je založen na skutečné implementaci platformy, ne na předpokladech. "
        . "Pokud otázka přesahuje rozsah tohoto popisu (např. detail konkrétní e-mailové šablony), odkaž na podporu RefDrive.pro.\n";

    return $text;
}
