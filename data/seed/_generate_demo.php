<?php
/**
 * One-shot script: genereert data/seed/demo.xlsx met een ERP-selectie-demo.
 * Run: php data/seed/_generate_demo.php
 *
 * Per ERP app service: 1–3 requirements (random, seeded).
 * Per NFR/VEND/IMPL/SUP/LIC thema/domein: 1–3 requirements (random, seeded).
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

mt_srand(20260425); // reproducible

// ── ERP app services + requirement-pools (Dutch, 3 per service) ───────
$ERP_SERVICES = [
    'Finance & control' => [
        ['Geconsolideerde rapportage over meerdere entiteiten', 'Het systeem ondersteunt geconsolideerde financiële rapportage over meerdere juridische entiteiten en valuta.', 'eis'],
        ['Realtime financieel dashboard', 'CFO-dashboard met liquiditeit, omzet en marge in realtime.', 'wens'],
        ['Periodeafsluiting binnen 5 werkdagen', 'Het systeem ondersteunt een maandafsluiting binnen 5 werkdagen incl. interne controles.', 'eis'],
    ],
    'Crediteuren- & debiteurenadministratie' => [
        ['Geautomatiseerde factuurmatching (3-way match)', 'Inkoopfacturen worden automatisch gematcht tegen orderbevestiging en ontvangst.', 'eis'],
        ['Automatische aanmaningen', 'Configureerbare aanmaningstrajecten met e-mail- en briefuitvoer.', 'eis'],
        ['SEPA-betaalbatches', 'Het systeem genereert SEPA XML pain.001 betaalbatches.', 'ko'],
    ],
    'Grootboek & rapportage' => [
        ['Multi-dimensionele grootboekstructuur', 'Boekingen worden vastgelegd op kostenplaats, project en business unit.', 'eis'],
        ['Audit trail op alle journaalposten', 'Elke journaalpost is herleidbaar naar gebruiker en brondocument.', 'eis'],
        ['Rapportage in Excel-export', 'Standaardrapporten kunnen direct als Excel worden geëxporteerd.', 'wens'],
    ],
    'Inkoop & leveranciersbeheer' => [
        ['Goedkeuringsworkflow voor inkoopaanvragen', 'Hiërarchische goedkeuring op basis van bedrag en categorie.', 'eis'],
        ['Leveranciersportaal', 'Leveranciers kunnen orders, facturen en bevestigingen via een portaal afhandelen.', 'wens'],
        ['Contract- en prijslijstbeheer', 'Inkoopcontracten met prijzen, kortingen en geldigheidsperiodes worden centraal beheerd.', 'eis'],
    ],
    'Voorraad- & magazijnbeheer' => [
        ['Realtime voorraadposities', 'Voorraadmutaties zijn binnen 1 minuut zichtbaar voor alle gebruikers.', 'eis'],
        ['Locatie- en batchbeheer', 'Voorraad wordt vastgelegd per locatie, batch en serienummer.', 'eis'],
        ['Mobile scanning ondersteuning', 'Het systeem ondersteunt handheld barcode-scanners voor inboek- en uitgifteprocessen.', 'wens'],
    ],
    'Productieplanning & -uitvoering' => [
        ['MRP-planning op basis van vraag en voorraad', 'Het systeem genereert productie- en inkoopvoorstellen op basis van forecast en voorraadniveaus.', 'eis'],
        ['Werkordersturing op de werkvloer', 'Operators registreren start, stop en hoeveelheden per werkorder via een touchscreen-interface.', 'eis'],
        ['Capaciteitsplanning per werkcentrum', 'Visuele planning op basis van beschikbare capaciteit en doorlooptijden.', 'wens'],
    ],
    'Kostprijsberekening' => [
        ['Standaard- en werkelijke kostprijs', 'Per artikel kunnen zowel standaardkostprijs als werkelijke kostprijs worden berekend en vergeleken.', 'eis'],
        ['Kostprijsopbouw per onderdeel', 'De kostprijs is uit te splitsen naar materiaal, arbeid en overhead.', 'wens'],
        ['Herwaardering van voorraad', 'Het systeem ondersteunt periodieke voorraadherwaardering op basis van nieuwe kostprijzen.', 'eis'],
    ],
    'Projectadministratie' => [
        ['Urenregistratie per project', 'Medewerkers boeken uren op project, fase en activiteit.', 'eis'],
        ['Project P&L rapportage', 'Per project zijn werkelijke kosten, opbrengsten en marge zichtbaar.', 'eis'],
        ['Onderhanden werk (OHW) waardering', 'Periodieke OHW-waardering wordt automatisch in het grootboek geboekt.', 'wens'],
    ],
    'Productforecast & demand planning' => [
        ['Statistische forecast op basis van historie', 'Het systeem rekent forecasts uit met meerdere modellen (moving average, exponential smoothing).', 'eis'],
        ['Sales & Operations Planning (S&OP) cyclus', 'Ondersteuning voor maandelijkse S&OP-cyclus met scenario-vergelijking.', 'wens'],
        ['Forecast accuracy KPI', 'Per artikelgroep wordt forecast accuracy (MAPE) gerapporteerd.', 'wens'],
    ],
    'Vaste activa beheer' => [
        ['Afschrijvingsmethodes', 'Lineair, degressief en handmatig per activum instelbaar.', 'eis'],
        ['Activa-administratie met locatie', 'Vaste activa worden vastgelegd met locatie, verantwoordelijke en status.', 'eis'],
        ['Investeringsaanvragen workflow', 'CAPEX-aanvragen volgen een goedkeuringsworkflow voor vrijgave als activum.', 'wens'],
    ],
];

// ── NFR / VEND / IMPL / SUP / LIC pools (3 per subcat, in scope-volgorde) ─
$SUBCAT_POOLS = [
    'NFR' => [
        'Security' => [
            ['SSO via SAML 2.0 of OIDC', 'Single sign-on integratie met de bestaande IdP (Entra ID).', 'eis'],
            ['Encryptie at-rest en in-transit', 'AES-256 at-rest en TLS 1.2+ voor alle netwerkcommunicatie.', 'eis'],
            ['Pen-test rapport beschikbaar', 'Jaarlijks extern uitgevoerde pen-test met deelbare rapportage.', 'wens'],
        ],
        'Portability' => [
            ['Data-export in open format', 'Volledige export van klantdata in CSV/JSON binnen 30 dagen na aanvraag.', 'eis'],
            ['Migratie-ondersteuning bij contractbeëindiging', 'De leverancier biedt migratie-ondersteuning bij overstap naar een andere oplossing.', 'wens'],
        ],
        'Reliability' => [
            ['SLA 99,9% beschikbaarheid', 'Maandelijkse uptime van minimaal 99,9% buiten geplande onderhoudsvensters.', 'ko'],
            ['Geografisch redundante back-ups', 'Back-ups in minimaal twee EU-regio\'s met dagelijkse herstel-test.', 'eis'],
            ['RTO < 4 uur, RPO < 1 uur', 'Disaster recovery met RTO 4 uur en RPO 1 uur.', 'eis'],
        ],
        'Compatibility' => [
            ['Open REST/JSON API', 'Volledig gedocumenteerde REST API met OpenAPI-spec.', 'eis'],
            ['Webhook-events voor key entiteiten', 'Webhooks voor orders, facturen en voorraadmutaties.', 'wens'],
            ['Browser-compatibel (laatste 2 versies)', 'Werkt op de laatste twee versies van Chrome, Edge, Firefox en Safari.', 'eis'],
        ],
        'Usability' => [
            ['WCAG 2.1 AA toegankelijk', 'De UI voldoet aan WCAG 2.1 niveau AA.', 'wens'],
            ['Nederlandstalige UI', 'De gebruikersinterface is volledig in het Nederlands beschikbaar.', 'eis'],
            ['Personaliseerbare dashboards', 'Eindgebruikers kunnen hun eigen startscherm samenstellen.', 'wens'],
        ],
        'Performance Efficiency' => [
            ['Pagina-laadtijd < 2s p95', 'P95 laadtijd van standaardschermen onder 2 seconden bij 200 concurrent users.', 'eis'],
            ['Schaalbaarheid tot 1000 concurrent users', 'Het systeem schaalt tot 1000 gelijktijdige gebruikers zonder degradatie.', 'eis'],
        ],
        'Maintainability' => [
            ['Configuratie zonder maatwerk', '80% van de inrichtingsbehoefte is via configuratie afhandelbaar.', 'eis'],
            ['Versiebeheer op customisaties', 'Maatwerk en configuratie zijn herleidbaar via versiebeheer.', 'wens'],
            ['Sandbox-omgeving beschikbaar', 'Een aparte sandbox is op aanvraag beschikbaar voor test en training.', 'eis'],
        ],
    ],
    'VEND' => [
        'Financiële gezondheid & continuïteit' => [
            ['Jaarlijkse jaarrekening overlegbaar', 'De leverancier deelt op verzoek de laatste twee jaarrekeningen.', 'eis'],
            ['Solvabiliteit > 25%', 'De leverancier toont een solvabiliteit van minimaal 25%.', 'wens'],
        ],
        'Organisatie & procesmaturiteit' => [
            ['ISO 9001 of vergelijkbaar', 'De leverancier is ISO 9001 (of equivalent) gecertificeerd.', 'wens'],
            ['Heldere escalatieroutes', 'Beschreven escalatieroutes voor incidenten en disputes.', 'eis'],
            ['Roadmap-transparantie', 'De productroadmap voor de komende 12 maanden is deelbaar.', 'wens'],
        ],
        'Delivery & executiekracht' => [
            ['Implementatie binnen 6 maanden', 'De leverancier kan een full-scope implementatie binnen 6 maanden leveren.', 'eis'],
            ['Vaste implementatieprijs mogelijk', 'Een fixed-price implementatie behoort tot de mogelijkheden.', 'wens'],
        ],
        'Expertise & innovatievermogen' => [
            ['Branche-expertise (maakindustrie)', 'Aantoonbare ervaring met implementaties in de maakindustrie.', 'eis'],
            ['R&D-investering > 15% van omzet', 'De leverancier investeert minimaal 15% van de omzet in R&D.', 'wens'],
            ['AI/ML roadmap', 'De roadmap omvat concrete AI/ML-functionaliteit binnen 18 maanden.', 'wens'],
        ],
        'Marktpositie & referenties' => [
            ['Minimaal 3 referenties in NL', 'Minstens drie vergelijkbare Nederlandse referenties beschikbaar.', 'eis'],
            ['Marktpositie in Gartner / Forrester', 'Genoemd in een recente Gartner Magic Quadrant of Forrester Wave.', 'wens'],
        ],
        'Relatie & samenwerking' => [
            ['Vaste accountmanager', 'Een vaste accountmanager wordt toegewezen voor de gehele looptijd.', 'eis'],
            ['Klantadviesraad / user community', 'Toegang tot een klantadviesraad of actieve user community.', 'wens'],
        ],
        'Risico & afhankelijkheid' => [
            ['Geen vendor lock-in op data', 'Data is op elk moment portable in open formaten.', 'eis'],
            ['Source-code escrow', 'De leverancier biedt source-code escrow als optie.', 'wens'],
        ],
        'Compliance, security & certificeringen' => [
            ['ISO 27001 gecertificeerd', 'De leverancier of hostingpartij is ISO 27001 gecertificeerd.', 'eis'],
            ['SOC 2 Type II rapport', 'Recent SOC 2 Type II rapport beschikbaar.', 'wens'],
            ['GDPR/AVG-compliance', 'Volledige AVG-compliance incl. verwerkersovereenkomst.', 'ko'],
        ],
    ],
    'IMPL' => [
        'Implementatiemethodiek & projectaanpak' => [
            ['Hybride aanpak (waterfall + agile)', 'De leverancier hanteert een gestructureerde aanpak met agile sprints binnen fase-toll-gates.', 'eis'],
            ['Standaard fit-gap workshops', 'Fit-gap workshops zijn onderdeel van de standaardaanpak.', 'wens'],
        ],
        'Datamigratie & conversie' => [
            ['Migratietooling beschikbaar', 'Standaard migratietooling voor stamdata en historische transacties.', 'eis'],
            ['Migratie-iteraties (mock loads)', 'Minimaal twee mock data loads voorzien in de planning.', 'eis'],
            ['Data quality assessment', 'Een initiële data quality assessment is onderdeel van de aanpak.', 'wens'],
        ],
        'Change management & gebruikersadoptie' => [
            ['Stakeholder-analyse', 'Aan de start wordt een stakeholder- en impact-analyse opgeleverd.', 'eis'],
            ['Adoptie-KPI\'s gedurende project', 'Adoptie wordt gemeten met KPI\'s tijdens en na go-live.', 'wens'],
        ],
        'Go-live begeleiding & hypercare' => [
            ['Hypercare 4 weken na go-live', 'Minimaal 4 weken hypercare na go-live met dagelijkse standups.', 'eis'],
            ['Cutover-plan met rollback', 'Gedetailleerd cutover-plan met rollback-scenario.', 'eis'],
        ],
        'Beschikbaarheid van implementatiepartners' => [
            ['Minimaal 3 NL implementatiepartners', 'Er zijn minstens drie ervaren NL implementatiepartners beschikbaar.', 'eis'],
            ['Partner-certificering', 'Implementatiepartners zijn formeel gecertificeerd door de leverancier.', 'wens'],
        ],
        'Configuratie & maatwerk aanpak' => [
            ['Configuration-first principe', 'Maatwerk is laatste resort; configuratie krijgt voorrang.', 'eis'],
            ['Maatwerk meeneembaar bij upgrades', 'Maatwerk is upgrade-veilig of geïsoleerd in extensies.', 'eis'],
            ['Lifecycle voor extensies', 'Extensies hebben een gedocumenteerd lifecycle-model.', 'wens'],
        ],
        'Testing & acceptatieprocedure' => [
            ['Geautomatiseerde regressietests', 'De leverancier ondersteunt geautomatiseerde regressietests.', 'wens'],
            ['Acceptatie-criteria per scenario', 'Acceptatiecriteria zijn vooraf vastgelegd per scenario.', 'eis'],
        ],
    ],
    'SUP' => [
        'Bereikbaarheid & responstijd' => [
            ['24/7 support voor P1-incidenten', '24/7 bereikbaarheid voor P1 (productie-stilstand).', 'ko'],
            ['Responstijd P2 < 4 uur', 'Reactie op P2-incidenten binnen 4 werkuren.', 'eis'],
            ['Nederlandstalige supportlijn', 'Eerstelijns support in het Nederlands.', 'wens'],
        ],
        'Kwaliteit & deskundigheid' => [
            ['Aantoonbaar functioneel + technisch support', 'Support biedt zowel functionele als technische kennis op niveau.', 'eis'],
            ['Customer satisfaction KPI', 'Maandelijkse CSAT-rapportage op support-tickets.', 'wens'],
        ],
        'Proactiviteit & communicatie' => [
            ['Statuspagina voor incidenten', 'Publieke statuspagina met realtime incidentmeldingen.', 'eis'],
            ['Proactieve melding bij security-issues', 'Proactieve communicatie bij security-incidenten en patches.', 'eis'],
        ],
        'Omgevingsbeheer & updates' => [
            ['Maximaal 2 major updates per jaar', 'Niet meer dan 2 disruptive updates per jaar.', 'wens'],
            ['Geplande onderhoudsvensters buiten kantooruren', 'Onderhoud vindt plaats buiten 08:00–18:00 NL-tijd.', 'eis'],
            ['Versie-overslag mogelijk', 'Klant mag maximaal 1 major versie overslaan.', 'wens'],
        ],
        'Documentatie & kennisoverdracht' => [
            ['Online kennisbank', 'Toegang tot online kennisbank met zoekfunctie.', 'eis'],
            ['Releasenotes per release', 'Per release publiceert de leverancier gestructureerde releasenotes.', 'eis'],
        ],
        'Gebruikerstraining & onboarding' => [
            ['Train-the-trainer programma', 'Een train-the-trainer programma is beschikbaar voor key users.', 'eis'],
            ['E-learning bibliotheek', 'Toegang tot een e-learning bibliotheek voor eindgebruikers.', 'wens'],
        ],
    ],
    'LIC' => [
        'Initiële investering' => [
            ['Transparante one-time fees', 'Eenmalige kosten zijn vooraf gespecificeerd en gegarandeerd.', 'eis'],
            ['Geen verplichte premium-modules', 'Kernfunctionaliteit zit niet achter optionele premium-modules.', 'wens'],
        ],
        'Terugkerende kosten & licentiestructuur' => [
            ['Per-named-user licentie', 'Licentiemodel op basis van named users met optie tot concurrent.', 'eis'],
            ['Maandelijkse opzegbaar', 'Maandelijkse opzegtermijn na initiële periode.', 'wens'],
            ['Jaarlijkse indexatie maximaal CPI', 'Indexatie is gemaximeerd op het CBS-consumentenprijsindex.', 'eis'],
        ],
        'Total Cost of Ownership (TCO)' => [
            ['5-jaars TCO transparant', 'TCO over 5 jaar is uit te rekenen op basis van de offerte.', 'eis'],
            ['Geen verborgen infra-kosten', 'Hosting, back-ups en bandbreedte zijn inbegrepen.', 'wens'],
        ],
        'Prijstransparantie & voorspelbaarheid' => [
            ['Prijslijst publiek beschikbaar', 'De standaard-prijslijst is publiek of op aanvraag beschikbaar.', 'wens'],
            ['Geen onaangekondigde prijswijzigingen', 'Prijswijzigingen worden minimaal 6 maanden vooraf aangekondigd.', 'eis'],
        ],
        'Contractvorm & exit' => [
            ['Exit-clausule met data-export', 'Contractuele exit met dataportabiliteit zonder extra kosten.', 'ko'],
            ['Geen automatische verlenging', 'Geen automatische langjarige verlenging zonder bevestiging.', 'eis'],
        ],
        'Schaalbaarheid van kosten' => [
            ['Volume-staffel op users', 'Prijsstaffels op aantal gebruikers en transacties.', 'eis'],
            ['Down-scale mogelijk', 'Aantal licenties kan jaarlijks naar beneden worden bijgesteld.', 'wens'],
        ],
    ],
];

// ── Bouw de Spreadsheet ───────────────────────────────────────────────
$ss = new Spreadsheet();
$ss->removeSheetByIndex(0);

// Instructies
$info = $ss->createSheet();
$info->setTitle('Instructies');
$info->fromArray([
    ['Demo-data: ERP-selectie'],
    [''],
    ['Genereert één traject "ERP-selectie demo" met de ERP app-soort en alle NFR/VEND/IMPL/SUP/LIC themas/domeinen.'],
    ['Per ERP app service en per thema/domein zijn 1–3 voorbeeld-requirements opgenomen.'],
    [''],
    ['Importeer via install-wizard (stap 3) of via instellingen → structuur (na seed van structuur.xlsx).'],
], null, 'A1');
$info->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$info->getColumnDimension('A')->setWidth(110);

$DEMO_SHEETS = [
    'Trajecten'    => ['name', 'description', 'status', 'start_date', 'end_date', 'demo_weight_pct',
                       'app_soorten', 'nfr_subs', 'vend_subs', 'impl_subs', 'sup_subs', 'lic_subs'],
    'Leveranciers' => ['traject_name', 'name', 'contact_name', 'contact_email', 'website', 'status', 'notes'],
    'Requirements' => ['traject_name', 'scope', 'app_soort', 'subcategorie', 'code', 'titel', 'omschrijving', 'type'],
];

foreach ($DEMO_SHEETS as $title => $cols) {
    $sh = $ss->createSheet();
    $sh->setTitle($title);
    $sh->fromArray([$cols], null, 'A1');
    $colLetter = function (int $n): string {
        $s = ''; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s;
    };
    $sh->getStyle('A1:' . $colLetter(count($cols)) . '1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EC4899']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);
    foreach ($cols as $i => $_) $sh->getColumnDimensionByColumn($i + 1)->setWidth(28);
    $sh->freezePane('A2');
}

// Trajecten
$trajName = 'ERP-selectie demo';
$trajRow = [
    $trajName,
    'Demo-traject voor ERP-selectie incl. functionele, non-functionele, leveranciers-, implementatie-, support- en licentie-criteria.',
    'actief',
    '2026-01-15',
    '2026-09-30',
    25,
    'ERP',
    implode('; ', array_keys($SUBCAT_POOLS['NFR'])),
    implode('; ', array_keys($SUBCAT_POOLS['VEND'])),
    implode('; ', array_keys($SUBCAT_POOLS['IMPL'])),
    implode('; ', array_keys($SUBCAT_POOLS['SUP'])),
    implode('; ', array_keys($SUBCAT_POOLS['LIC'])),
];
$ss->getSheetByName('Trajecten')->fromArray([$trajRow], null, 'A2');

// Leveranciers
$levRows = [
    [$trajName, 'Leverancier Alpha', 'Anna Aalders', 'a.aalders@alpha.example', 'https://alpha.example', 'actief', 'Sterk in maakindustrie.'],
    [$trajName, 'Leverancier Bravo', 'Bart Bos',     'b.bos@bravo.example',     'https://bravo.example', 'onder_review', 'Cloud-native, jonge speler.'],
    [$trajName, 'Leverancier Charlie', 'Carla de Cock','c.decock@charlie.example','https://charlie.example','actief', 'Gevestigde naam, breed portfolio.'],
];
$ss->getSheetByName('Leveranciers')->fromArray($levRows, null, 'A2');

// Requirements: random 1–3 uit elke pool
$reqRows = [];
foreach ($ERP_SERVICES as $service => $pool) {
    $n = mt_rand(1, min(3, count($pool)));
    $picks = (array)array_rand($pool, $n);
    foreach ($picks as $idx) {
        [$titel, $omsch, $type] = $pool[$idx];
        $reqRows[] = [$trajName, 'FUNC', 'ERP', $service, '', $titel, $omsch, $type];
    }
}
foreach ($SUBCAT_POOLS as $scope => $subs) {
    foreach ($subs as $sub => $pool) {
        $n = mt_rand(1, min(3, count($pool)));
        $picks = (array)array_rand($pool, $n);
        foreach ($picks as $idx) {
            [$titel, $omsch, $type] = $pool[$idx];
            $reqRows[] = [$trajName, $scope, '', $sub, '', $titel, $omsch, $type];
        }
    }
}
$ss->getSheetByName('Requirements')->fromArray($reqRows, null, 'A2');

$ss->setActiveSheetIndex(0);
$out = __DIR__ . '/demo.xlsx';
(new XlsxWriter($ss))->save($out);
echo "Wrote $out\n";
echo "Requirements: " . count($reqRows) . "\n";
