# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Monorepo for the RefDrive.pro platform — a multi-tenant WordPress system for mandatory
driver-safety training of company car drivers in the Czech Republic ("referentští řidiči"). Plain PHP,
no build step, no package manager, no automated test suite. Each folder is deployed as a standalone
WordPress plugin or theme (installed directly into `wp-content/plugins/` / `wp-content/themes/`).

## Components

| Folder | Type | Purpose |
|---|---|---|
| `refdrive-plugin/` | Plugin | Core plugin — driving schools (autoškoly), companies (firmy), courses, quizzes, certificates, orders, Stripe payments, all admin UI |
| `rd-legislativa/` | Plugin | AI-assisted tracking of legislative changes (zákon č. 361/2000 Sb.), proposes content updates for instructors to approve |
| `rd-ai-asistenti/` | Plugin | AI chat assistants (Claude API) for driving schools and companies |
| `refdrive-aurora/` | Theme | "Aurora" glassmorphism WordPress theme (violet-pink gradient), also serves as a PWA shell |

There is no shared package/library — components integrate loosely at runtime (see Cross-plugin integration
below), each with its own version.

## Versioning & conventions

### Versioning
- Na každou změnu v tématu/pluginu zvyš semver na DVOU místech současně:
  - themes: `style.css` Theme header verze + `functions.php` verzní konstanta
  - plugins: Plugin Name header verze + verzní konstanta v kódu
  - Verze musí být viditelná v WP adminu.
- ZIP název balíčku při release odpovídá téhle verzi.

(Konstanty: `RD_VERSION`, `RDLEG_VERSION`, `RDAI_VERSION`, `RDAURORA_VERSION`.)

### refdrive-plugin JS rules
- Veškerý JS patří do existujícího `admin_footer` hooku (kde je `rdSortTable`).
- JS musí používat POUZE dvojité uvozovky (žádné jednoduché) — jinak selže PHP string parser.
- Wrap element musí být vytvořen PŘED voláním `render()`.
- Používej `for` smyčku místo `forEach`+closure kvůli scope.
- Používej přímé `.onclick` místo `addEventListener`.

### refdrive-plugin tables
- Všechny widefat `rd-sortable` tabulky mají automatické show/hide přes `rdShowMore()`:
  zobrazí 3 řádky, tlačítko "Zobrazit vše", pak stránkování po 10 s "Sbalit" / ← 1–10/N →.
- Overview table queries: `LIMIT 100`.
- Historical section queries: `LIMIT 50`.

## Working in this repo

- No build/lint/test commands exist — there is no `package.json`, `composer.json`, or CI config. Validate
  changes by reading the PHP carefully (`php -l <file>` for a syntax check) and, where practical, testing
  in a real WordPress install.
- `refdrive-plugin/refdrive-plugin.php` is a single ~17,000-line file — read it with `Grep`/targeted
  `Read` offsets rather than loading it whole (it exceeds normal read limits). Function names are
  prefixed `rd_`; admin page renderers follow `rd_admin_*`; AJAX handlers `rd_ajax_*`.
- `rd-legislativa/rd-legislativa.php` (~3,700 lines) uses the `rdleg_` prefix throughout.
- `rd-ai-asistenti` is split into `rd-ai-asistenti.php` (bootstrap, admin settings, shared Claude API call
  helper) plus `includes/agent-autoskola.php`, `includes/agent-firma.php` (per-agent logic) and
  `includes/platform-mechanics.php` (a hand-maintained prose description of platform automation, fed to
  the AI as context — see below). Prefix `rdai_`.
- Comments in this codebase are written in Czech and are often load-bearing: they explain *why* a
  non-obvious workaround exists (e.g. JSON control-character repair, wp_kses filters, session-cookie
  ordering). Read them before changing the code around them, and match their depth/style — don't strip
  them out or reduce them to English one-liners.
- All user-facing strings and code comments in this codebase are Czech; keep new code consistent with
  that unless told otherwise.

## Architecture: multi-tenant identity model

There is no single "logged in user" concept. Four independent identities are tracked, all via native PHP
`$_SESSION` (not WordPress auth), each restored from its own long-lived cookie on `init`:

- **`$_SESSION['rd_firma_id']`** (+ `rd_firma_login` cookie) — a company (firma) portal login.
- **`$_SESSION['rd_lektor']`** (+ `rd_lektor_login` cookie) — an instructor (lektor), who reviews and
  approves AI-proposed content changes on the `/lektor/` page.
- **`$_SESSION['rd_kod']`** (+ `rd_ridic` cookie) — an individual driver (řidič) taking the course, identified
  by a single-use training code.
- WordPress users with the **`refdrive_admin`** role / **`refdrive_access`** capability — platform and
  driving-school (autoškola) admin, using normal `wp-admin` auth. `manage_options` (super-admin) sees all
  driving schools; `refdrive_access` alone is scoped to the admin's own autoškola.

Session cookies are hardened (`secure`, `httponly`, `samesite=Lax`) and set **before** `session_start()` is
ever called — this ordering is deliberate, see the comment at the top of `refdrive-plugin.php`.
`session_start()` is guarded everywhere with `!rd_is_rest() && !DOING_CRON` checks to avoid starting
sessions on REST/cron requests.

## Architecture: data model (refdrive-plugin)

Custom tables (prefixed `{$wpdb->prefix}rd_`), created in `rd_aktivace()` on activation and patched
idempotently in `rd_migrace()` on `plugins_loaded`:

- `rd_autoskoly` — driving schools (tenants), with tier/subscription state.
- `rd_as_tiery`, `rd_as_platby` — subscription tiers and payments (Stripe) for driving schools.
- `rd_firmy`, `rd_firma_autoskola` — companies and their (many-to-many-ish) link to a driving school.
- `rd_kody` — training codes issued to a company; each has its own expiry (`expiruje`), independent of the
  issuing driving school's subscription state.
- `rd_ridici` — enrolled/completed drivers, exam scores, certificate UUIDs — the retained training record.
- `rd_objednavky` — orders (code purchases).
- `rd_zmeny_zadosti` — pending "change requests" (e.g. driving-school profile edits awaiting admin approval).
- `rd_poptavky` — capacity inquiries: a company's request for more codes than a driving school currently
  has free capacity for; resolved hourly by cron (see platform mechanics below).
- `rd_lekce`, `rd_otazky`, `rd_faq`, `rd_novinky` — course lessons, quiz questions, FAQ, news content
  (editable, and the target of `rd-legislativa`'s AI-proposed updates).
- `rd_lektor` — instructor accounts.

Key lifecycle rules encoded in cron/notification logic (see `rd-ai-asistenti/includes/platform-mechanics.php`
for the authoritative prose description, kept in sync with the actual cron implementation):
subscription expiry → grace period → after 6 months of inactivity the driving school profile is
**anonymized** (PII stripped) but historical driver/certificate records are kept; driver training records
and public certificate verification (`/overit/?id=...`) are retained for **2 years** then hard-deleted.

## Architecture: cross-plugin integration

`rd-legislativa` and `rd-ai-asistenti` are designed to be optional/removable without touching
`refdrive-plugin`:

- They never edit `refdrive-plugin.php`. `rd-legislativa` inserts its instructor-review UI into the
  `/lektor/` page **non-invasively** via output buffering (`ob_start()` on `template_redirect`, splicing
  HTML after a known marker `<div class="rd-lektor-tabs">`); if that marker isn't found (main plugin
  changed), it silently no-ops.
- Both check the main plugin is active/ready by checking a core table exists (e.g.
  `rdleg_hlavni_plugin_pripraven()` checks for `rd_faq`) before doing anything.
- `rd-legislativa` owns its own tables (`rd_leg_zneni`, `rd_leg_navrhy`) and additively patches core
  content tables with an extra `rd_leg_zdroj` column (never destructive).
- `rd-legislativa` listens for `do_action('rd_obsah_resetovan', $co, $kdo)`, fired by `refdrive-plugin`
  right after content resets, to react in the same request.
- Both AI plugins share one Anthropic API key stored in `wp_options` (`rd_ai_api_key`, set via
  `register_setting` in `rd-ai-asistenti`'s admin page) and call `https://api.anthropic.com/v1/messages`
  directly over `wp_remote_post` — no SDK. Model used: `claude-sonnet-4-6`. The key is never hardcoded.

## AI-generation patterns worth knowing before touching this code

- Prompts to Claude are large, carefully-worded Czech blocks built by string concatenation — see
  `rdleg_ai_navrhnout_zmeny()` in `rd-legislativa.php` and `rdai_call_claude()` in `rd-ai-asistenti.php`.
  Changes to prompt wording are effectively behavior changes; read the surrounding rationale comments
  before editing them.
- `rd-legislativa` has three distinct AI review modes (`novela` — diff-driven, triggered by a new law-text
  upload; `komplet` / `audit` — exhaustive per-paragraph compliance sweep) with different rules for when a
  "novinka" (news item) target is allowed — don't conflate them.
- AI responses are expected as JSON but arrive wrapped/imperfect; `rdleg_json_decode_s_opravou()` repairs
  unescaped control characters and extracts a balanced JSON value from surrounding prose before parsing.
  Reuse this rather than adding ad hoc parsing when handling new AI-JSON responses.
- Long lesson content is deliberately excluded from "return the full rewritten text" instructions above
  `RDLEG_LEKCE_DLOUHA_PRAH` (3500 chars) to avoid `max_tokens` truncation — see the constant's comment.

## Planned features (not yet implemented)

### Reklamace kódů
Plánovaná funkce pro vracení nevyužitých kódů v refdrive-plugin:
1. Tlačítko "Vrátit kód" pro nevyužité kódy ve firemním portálu (včetně hromadné akce).
2. Validace: kód patří firmě + je nevyužitý + je do 14 dnů od přiřazení.
3. Okamžitá deaktivace kódu.
4. Notifikace autoškole ke schválení (5 dní na reakci, jinak auto-schválení).
5. Notifikace na rd_admin_notif_email.
6. Refundaci řeší přímo autoškola (mimo systém).
7. Stav zobrazený ve firemním portálu: "čeká na refundaci" / "vráceno".

Až se začne implementovat, tahle sekce se přesune do hlavního popisu funkcí a označí jako hotová.

## Standard workflow after any code change

Po každé dokončené úpravě kódu v libovolné komponentě (bez nutnosti to explicitně žádat):
1. Zvyš verzi podle pravidel výše (Versioning).
2. Vytvoř aktualizovaný ZIP balíček dané komponenty (název odpovídá nové verzi) do rootu repa.
3. Proveď git commit s výstižnou zprávou popisující změnu.
4. Pushni na origin/main.
Tohle prováděj automaticky po dokončení úpravy, pokud uživatel výslovně neřekne, že chce nejdřív jen náhled bez commitu/ZIPu.
