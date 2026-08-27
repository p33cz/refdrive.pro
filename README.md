# RefDrive Suite

Monorepo pro platformu RefDrive.pro — multi-tenant WordPress systém pro povinné školení řidičů v ČR.

Autor: David Helcl

## Komponenty

| Složka | Typ | Popis |
|---|---|---|
| `refdrive-plugin/` | Plugin | Hlavní plugin — autoškoly, firmy, kurzy, certifikáty, objednávky |
| `rd-legislativa/` | Plugin | AI konzultace legislativních změn a doporučení |
| `rd-ai-asistenti/` | Plugin | AI asistenti (agent pro firmy a autoškoly) s Claude API integrací |
| `refdrive-aurora/` | Theme | Aurora — glassmorphism theme (violet-pink gradient) |

## Verzování

Každá komponenta má vlastní semver, viditelné jak v názvu ZIP balíčku, tak přímo v kódu
(`Plugin Name`/`Theme` header + verzní konstanta). Při každé změně se verze zvyšuje na obou místech.

## API klíče

Anthropic API klíč se ukládá přes WordPress `wp_options` (`register_setting`), nikdy není
natvrdo v kódu. Nastavuje se v adminu přes stránku nastavení AI asistentů.
