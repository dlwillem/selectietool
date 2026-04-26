# Architectuur

Korte technische rondleiding voor wie de codebase wil begrijpen of eraan
bijdragen. Voor functionele uitleg: zie de in-app FAQ (`/pages/faq.php`).

## Stack

- **PHP 8.2+** — geen framework; flat routing via `pages/*.php`.
- **MySQL 8 / MariaDB 10.6+** — InnoDB, PDO met prepared statements (geen
  emulation, strict mode).
- **Apache** — `mod_rewrite` voor HTTPS-redirect, `.htaccess` voor security
  headers en het blokkeren van interne mappen.
- **PhpSpreadsheet** voor Excel-I/O, **PHPMailer** voor SMTP/log mail,
  **ZipStream** voor het verpakken van leveranciers-uploads.
- **Frontend** — server-rendered HTML met vanilla JS-enhancements; geen build
  step. Nunito Sans als typografie, accentkleur cyaan (`#0891b2`).

## Boot-flow

```
index.php  → includes/bootstrap.php → laadt:
                config/env.php       (.env reader; functie-guard)
                config/config.php
                config/db.php        (PDO factory: db(), db_value, db_all, …)
                config/mail.php
                includes/functions.php   (h, csrf, redirect, input_*)
                includes/db_functions.php
                includes/settings.php
                includes/crypto.php       (AES-256-GCM, APP_KEY)
                includes/auth.php         (session_boot, login flow)
                includes/authz.php        (rolmatrix, traject-scoping)
              → session_boot()
```

Alle entry-points (pages, AJAX, exports) `require` `includes/bootstrap.php`
als eerste regel. Daarna controleert de pagina zelf met `require_login()` en
`require_can('cap.naam')` op autorisatie.

## Domeinmodel — de zes scopes

Een **traject** is één softwareselectie. Per traject worden requirements
verzameld in zes vaste hoofdcategorieën (de **scopes**):

| Scope  | Betekenis        | Subcategorieën heten   | Bron-templates |
|--------|------------------|------------------------|----------------|
| `FUNC` | Functioneel      | App services           | per applicatiesoort |
| `NFR`  | Non-functioneel  | Domeinen               | platte lijst |
| `VEND` | Leverancier      | Thema's                | platte lijst |
| `IMPL` | Implementatie    | Thema's                | platte lijst |
| `SUP`  | Support          | Thema's                | platte lijst |
| `LIC`  | Licentie         | Thema's                | platte lijst |

De zeven hoofdcategorieën zijn hardcoded (zie `STRUCT_FIXED_CATEGORIES` in
`includes/structure_import.php`). De zevende is **`DEMO`** — een aparte
catalogus van vragen voor leveranciersdemo's, beheerd via de DEMO-vragen-tab
in de structuur-Excel.

Alle subcategorieën zijn een kopie uit `subcategorie_templates`. Bij het
aanmaken van een traject wordt per geselecteerde applicatiesoort en per
geselecteerd thema een rij naar `subcategorieen` gekopieerd met
`traject_id`. Wijzigingen aan een traject muteren de templates niet — en
omgekeerd.

## Stamdata-flow

```
data/seed/structuur.xlsx ──► install-wizard (stap 3)
                            ─OF── Instellingen → Structuur (upload)
                              │
                              ▼
                     applicatiesoorten
                     subcategorie_templates  (per categorie)
                     demo_question_catalog
```

De installer kan optioneel ook `data/seed/demo.xlsx` inlezen voor één
voorbeeldtraject met requirements en leveranciers.

Wipe + upload is alleen toegestaan op een schone structuur (`requirements`
+ `leveranciers` beide leeg). Daarna kunnen architecten op
`Instellingen → Structuur` opnieuw uploaden.

## Excel-formats (round-trip)

| Bestand              | Tabs |
|----------------------|------|
| `structuur.xlsx`     | App soorten, App services (FUNC), NFR, VEND, IMPL, SUP, LIC, DEMO-vragen |
| `demo.xlsx`          | Trajecten, Leveranciers, Requirements |
| Requirements export  | FUNC (`code, app_soort, subcategorie, titel, omschrijving, type`) + 5× scope-tabs zonder `app_soort` |
| Leveranciers-uitvraag| Per scope een tab met `code, domein, titel, omschrijving, MoSCoW, Standaard, Toelichting` |

Alle Excel-imports zijn **strict all-or-nothing**: één fout rolt de
transactie terug. Foutmelding bevat tabbladnaam + rijnummer.

## Score-pipeline

```
score (1–5, per beoordelaar, per requirement)
    ↓ gemiddelde over beoordelaars
score per requirement
    ↓ gewogen gemiddelde binnen subcategorie (Eis 2×, Wens 1×, KO niet)
subcategoriescore
    ↓ gewogen som met sub-gewichten (samen 100% per categorie)
hoofdcategoriescore
    ↓ gewogen som met traject-weging (categorieën samen 100%)
eindscore  +  ⚠️-flag bij KO-gemiddelde ≤ 2
            +  DEMO-aandeel (instelbaar % per traject)
```

KO-requirements vallen buiten de berekening maar leveren wel een hard signaal.
`auto`-scores komen uit `lev_classify()` in `includes/lev_answers.php`;
handmatige scores overschrijven ze met `source=manual`.

## Beveiliging

- Alle POST-formulieren bevatten een CSRF-token (verplicht gevalideerd
  via `csrf_require()`).
- Sessies met `__Host-` prefix + `SameSite=Strict` in productie.
- Bcrypt voor wachtwoorden, **SHA-256-hash** voor scoring-tokens (lookup
  hasht input, originelen niet opgeslagen).
- SMTP-wachtwoord AES-256-GCM-encrypted in DB; sleutel `APP_KEY` in `.env`.
- HTTPS-redirect en security-headers (HSTS, CSP, X-Frame-Options,
  Referrer-Policy) in root `.htaccess`.
- Rol- en traject-scoping in `includes/authz.php`: architecten zien alles,
  overige rollen alleen via `traject_deelnemers` (match op e-mail).
- Login rate-limiting (5 pogingen / 15 min / IP).

## Audit trail

Alle relevante mutaties (login, CRUD trajecten/requirements/leveranciers,
upload-commit, score-wijziging, KO-flag, leverancier-status) gaan via
`audit_log_write()` naar `audit_log`. Architecten kunnen de log inzien op
`/pages/audit.php`.

De **demo-importer** schrijft bewust *zonder* audit-log, omdat hij ook in de
install-wizard draait — vóór er een ingelogde gebruiker bestaat.
