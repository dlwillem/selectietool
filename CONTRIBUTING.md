# Bijdragen

Dit is een maatwerkapplicatie zonder publieke issue tracker. Bijdragen
gaan via overleg met de projectbeheerder. Hieronder de werkafspraken voor
wie wèl in de code duikt.

## Lokale setup

1. Clone de repo en draai `composer install`.
2. MAMP / lokale Apache + MySQL — host `127.0.0.1`, port 8889, gebruiker
   `root`/`root` als defaults.
3. Open `http://localhost:8888/<jouw-pad>/install.php` en doorloop de
   wizard. Bij stap 3 kun je `data/seed/structuur.xlsx` + `data/seed/demo.xlsx`
   automatisch laten inlezen.
4. Inloggen met het admin-account dat je in stap 4 hebt ingevuld.

## Code-stijl

- **PHP 8.2** features mogen (named args, readonly, enums, first-class
  callable syntax).
- **4 spaties** indent, **geen tabs**.
- **Strict typing** waar mogelijk: `declare(strict_types=1)` is niet
  consequent gebruikt — niet aan toevoegen tenzij je het hele bestand
  doorloopt.
- **Geen frameworks** of build-tools toevoegen zonder overleg.
- HTML-output altijd door `h($string)` halen tegen XSS.
- DB-queries via `db()`, `db_value()`, `db_all()`, `db_insert()`,
  `db_update()` — nooit `mysqli_*` of string-concat.
- Iedere mutatie die een gebruiker initieert hoort in de **audit-log**
  (`audit_log_write()`) tenzij het uit een installer draait.

## Bestand-conventies

- **Pages** (`pages/*.php`) zijn flat routes; elke pagina:
  ```php
  require_once __DIR__ . '/../includes/bootstrap.php';
  require_login();
  require_can('relevante.cap');
  // … logica …
  $bodyRenderer = function () use (…) { /* HTML */ };
  require __DIR__ . '/../templates/layout.php';
  ```
- **Includes** bevatten herbruikbare logica; iedere include opent met
  `if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }`.
- **Excel-I/O** centraal in `includes/structure_*.php`,
  `includes/requirements_excel.php`, `includes/leverancier_excel.php`,
  `includes/demo_seed.php` — niet duplicaten, sluit aan bij bestaande
  patronen (toArray + header-validatie).

## Migraties

Schema-wijzigingen gaan via een idempotente check in `install.php` of een
los `pages/migrate_*.php`-script (architect-only, web-bereikbaar). Format:

- Detect of de wijziging al gedraaid heeft via `information_schema`.
- Voer de DDL uit met `IF NOT EXISTS` of equivalent.
- Backfill rijen die door de wijziging nieuw nodig zijn.
- Schrijf een korte uitleg + run-instructie in de header-comment.

Eenmalige migraties die zeker voor alle deployments gedraaid hebben mogen
verwijderd worden — verifieer eerst met de DB.

## Commit-stijl

- Korte imperatieve subject (≤ 70 chars), Nederlands of Engels — wees
  consistent binnen één commit.
- Body optioneel: leg het *waarom* uit, niet het *wat* (de diff toont het
  wat).
- Geen "WIP"-commits naar `main` — squash lokaal eerst.

## Tests

Er zijn nog geen geautomatiseerde tests. Voor risicovolle wijzigingen
(score-pipeline, auto-classificatie, Excel-import) testen door:

1. Een schone install te doen met `data/seed/structuur.xlsx` + `demo.xlsx`.
2. Het demo-traject te openen, requirements en leveranciers door te lopen.
3. Auto-score uit te voeren en het resultaat tegen de input te leggen.

## Licentie

Bijdragen vallen onder dezelfde [AGPL-3.0](LICENSE) als de rest van de repo.
