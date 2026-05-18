<?php
/**
 * Excel-import/export voor requirements.
 *
 * Format (sinds redesign): één tabblad per scope (FUNC/NFR/VEND/IMPL/SUP/LIC).
 * De hoofdcategorie wordt afgeleid uit de tabbladnaam — net als bij de
 * structuur-export. Export en template hebben hetzelfde format, dus de
 * download is direct round-trip-importeerbaar.
 *
 * Kolommen per scope:
 *   FUNC :  code, app_soort, subcategorie, titel, omschrijving, type
 *   NFR  :  code, subcategorie, titel, omschrijving, type
 *   VEND :  code, subcategorie, titel, omschrijving, type
 *   IMPL :  code, subcategorie, titel, omschrijving, type
 *   SUP  :  code, subcategorie, titel, omschrijving, type
 *   LIC  :  code, subcategorie, titel, omschrijving, type
 *
 * code leeg → nieuw requirement; code ingevuld → update op bestaande.
 * type ∈ {eis, wens, ko}.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/requirements.php';
require_once __DIR__ . '/leverancier_excel.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const REQ_EXCEL_SCOPES = ['FUNC', 'NFR', 'VEND', 'IMPL', 'SUP', 'LIC'];

/**
 * Blauwe-blok configuratie voor de interne export. Wordt door
 * lev_excel_build_sheet() opgepikt om naast het groene "in te vullen"-blok
 * een blauw "intern"-blok te tekenen met owner + interne opmerking.
 */
const REQ_INTERNAL_BLUE_BLOCK = [
    'banner'  => 'Interne opmerkingen',
    'columns' => [
        'interne_opmerking' => 'Interne opmerking',
        'owner'             => 'Bespreken met',
    ],
    'widths'  => ['interne_opmerking' => 40, 'owner' => 22],
];

/**
 * Download .xlsx met alle requirements van dit traject — round-trip-importeerbaar.
 */
function requirements_excel_export(int $trajectId, string $filename): void {
    $traject = db_one('SELECT name FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if (!$traject) { http_response_code(404); exit('Traject niet gevonden.'); }

    // Volle dataset incl. cat/sub/app namen (voor het Domein-veld) +
    // interne velden voor het blauwe blok.
    $reqs = db_all(
        'SELECT r.id, r.code, r.title, r.description, r.type, r.fase, r.internal_note,
                s.name AS sub_name,
                a.name AS app_name,
                td.name AS owner_name,
                c.name AS cat_name, c.code AS cat_code,
                c.sort_order AS cat_order, s.sort_order AS sub_order,
                r.sort_order AS req_order
           FROM requirements r
           JOIN subcategorieen s ON s.id = r.subcategorie_id
           JOIN categorieen    c ON c.id = s.categorie_id
           LEFT JOIN applicatiesoorten   a  ON a.id  = s.applicatiesoort_id
           LEFT JOIN traject_deelnemers  td ON td.id = r.owner_deelnemer_id
          WHERE r.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, r.sort_order, r.id',
        [':t' => $trajectId]
    );
    $byScope = [];
    $blueValuesByReq = [];
    foreach ($reqs as $r) {
        $byScope[$r['cat_code']][] = $r;
        $blueValuesByReq[(int)$r['id']] = [
            'interne_opmerking' => (string)($r['internal_note'] ?? ''),
            'owner'             => (string)($r['owner_name'] ?? ''),
        ];
    }

    // Subcategorie-namen per scope (voor lege scopes — net als bij leverancier-export)
    $subRows = db_all(
        'SELECT s.id, s.name, c.code AS cat_code
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, s.id',
        [':t' => $trajectId]
    );
    $subsByScope = [];
    foreach ($subRows as $sr) $subsByScope[$sr['cat_code']][] = $sr['name'];

    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    // Toelichting-tab (instructies, lijst van subcategoriën etc.)
    $info = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, 'Toelichting');
    $ss->addSheet($info);
    _req_excel_build_info($info, $trajectId, (string)$traject['name']);

    // Per scope een tab via de gedeelde sheet-renderer.
    foreach (REQ_EXCEL_SCOPES as $scope) {
        $rows = $byScope[$scope] ?? [];
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, $scope);
        $ss->addSheet($sheet);
        lev_excel_build_sheet(
            $sheet,
            $scope,
            $rows,                              // requirements
            [],                                 // aByReq: leeg (groene blok blijft leeg)
            'nl',
            $subsByScope[$scope] ?? [],
            REQ_INTERNAL_BLUE_BLOCK,
            $blueValuesByReq
        );
    }
    $ss->setActiveSheetIndexByName('Toelichting');
    requirements_excel_send($ss, $filename);
}

/**
 * Toelichting-tab: instructies + lijst beschikbare subcategorieën.
 */
function _req_excel_build_info(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $s,
    int $trajectId,
    string $trajectName
): void {
    $s->fromArray([
        ['Requirements-export — ' . $trajectName],
        [''],
        ['Eén tabblad per scope (FUNC/NFR/VEND/IMPL/SUP/LIC). Layout identiek aan de leverancier-template.'],
        [''],
        ['Voor IMPORT:'],
        ['  · Tabbladnamen, kolomvolgorde en kolomnamen niet wijzigen.'],
        ['  · "Nr" leeg → nieuw requirement; ingevuld → update op die code in dit traject.'],
        ['  · "Domein" formaat: "<hoofdcategorie> → <subcategorie>" — exact zoals dit traject ze kent.'],
        ['  · "Titel" verplicht. "Omschrijving" optioneel.'],
        ['  · "Fase" optioneel, integer 1..5.'],
        ['  · "MoSCoW" verplicht: Must / Should / Knock-out (NL: Must/Should/Knock-out).'],
        ['  · Groene blok ("Standaard…" en "Toelichting") wordt bij interne import genegeerd.'],
        ['  · Blauwe blok: "Interne opmerking" tekst; "Bespreken met" = naam van een deelnemer.'],
        [''],
        ['Beschikbare subcategorieën in dit traject (per scope):'],
    ], null, 'A1');
    $s->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $s->getColumnDimension('A')->setWidth(28);
    $s->getColumnDimension('B')->setWidth(36);
    $s->getColumnDimension('C')->setWidth(60);

    $row = 18;
    $s->fromArray([['scope', 'app_soort', 'subcategorie']], null, 'A' . $row);
    $s->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $row++;
    $subs = requirement_subcats_for_traject_with_app($trajectId);
    foreach ($subs as $sub) {
        $s->fromArray([[$sub['cat_code'], (string)($sub['app_name'] ?? ''), $sub['name']]], null, 'A' . $row++);
    }
}

/**
 * Download .xlsx template (lege scope-sheets met de drie-blok-layout).
 * De gebruiker kan rijen toevoegen onder de header-rij.
 */
function requirements_excel_template(int $trajectId, string $filename): void {
    $traject = db_one('SELECT name FROM trajecten WHERE id = :id', [':id' => $trajectId]);
    if (!$traject) { http_response_code(404); exit('Traject niet gevonden.'); }

    // Subcategorie-namen per scope (voor de "geen requirements"-melding op lege tabs)
    $subRows = db_all(
        'SELECT s.name, c.code AS cat_code
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, s.sort_order, s.id',
        [':t' => $trajectId]
    );
    $subsByScope = [];
    foreach ($subRows as $sr) $subsByScope[$sr['cat_code']][] = $sr['name'];

    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    $info = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, 'Toelichting');
    $ss->addSheet($info);
    _req_excel_build_info($info, $trajectId, (string)$traject['name']);

    foreach (REQ_EXCEL_SCOPES as $scope) {
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, $scope);
        $ss->addSheet($sheet);
        lev_excel_build_sheet(
            $sheet,
            $scope,
            [],                                 // lege rows
            [],                                 // aByReq leeg
            'nl',
            $subsByScope[$scope] ?? [],
            REQ_INTERNAL_BLUE_BLOCK,
            []
        );
    }

    $ss->setActiveSheetIndexByName('Toelichting');
    requirements_excel_send($ss, $filename);
}

/** Subcats met app-soort-info voor de toelichtings-tab + import-lookup. */
function requirement_subcats_for_traject_with_app(int $trajectId): array {
    return db_all(
        'SELECT s.id, s.name, s.sort_order,
                c.id AS cat_id, c.code AS cat_code, c.name AS cat_name, c.sort_order AS cat_order,
                a.name AS app_name
           FROM subcategorieen s
           JOIN categorieen c ON c.id = s.categorie_id
           LEFT JOIN applicatiesoorten a ON a.id = s.applicatiesoort_id
          WHERE s.traject_id = :t
          ORDER BY c.sort_order, a.name, s.sort_order, s.id',
        [':t' => $trajectId]
    );
}

function requirements_excel_send(Spreadsheet $ss, string $filename): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new XlsxWriter($ss))->save('php://output');
    exit;
}

/**
 * Strict, transactioneel, all-or-nothing import.
 * Leest alle 6 scope-tabbladen in het nieuwe drie-blok-format (zelfde
 * kolomstructuur als de leverancier-template, met optioneel een blauwe
 * "Interne opmerkingen"-blok rechts).
 *
 * Headers worden gezocht in rij 1..3 (eerste rij met "Nr"); de groene
 * kolommen "Standaard…" en "Toelichting" worden bij intern-import genegeerd.
 *
 * @return array{ok:bool, created:int, updated:int, errors: string[], rows: int}
 */
function requirements_excel_import(int $trajectId, string $path): array {
    $result = ['ok' => false, 'created' => 0, 'updated' => 0, 'errors' => [], 'rows' => 0];

    try {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly'))   $reader->setReadDataOnly(true);
        if (method_exists($reader, 'setReadEmptyCells')) $reader->setReadEmptyCells(false);
        $ss = $reader->load($path);
    } catch (Throwable $e) {
        $result['errors'][] = 'Kon bestand niet openen: ' . $e->getMessage();
        return $result;
    }

    foreach (REQ_EXCEL_SCOPES as $scope) {
        if ($ss->getSheetByName($scope) === null) {
            $result['errors'][] = "Tabblad '$scope' ontbreekt.";
        }
    }
    if ($result['errors']) return $result;

    // Lookup-tabellen voor subcategorie-resolutie via "Domein" (cat_name → sub_name).
    // Voor FUNC kunnen sub-namen voorkomen onder meerdere app_soorten — dan
    // hebben we meerdere id's, en moeten we de import-rij als ambigu markeren.
    $subRows = requirement_subcats_for_traject_with_app($trajectId);
    $subLookup = []; // scope||cat_name_lower||sub_name_lower → [id, ...]
    foreach ($subRows as $sr) {
        $key = $sr['cat_code'] . '||' . mb_strtolower((string)$sr['cat_name']) . '||' . mb_strtolower((string)$sr['name']);
        $subLookup[$key][] = (int)$sr['id'];
    }

    // Bestaande codes in dit traject
    $codeRows = db_all(
        'SELECT id, code FROM requirements WHERE traject_id = :t',
        [':t' => $trajectId]
    );
    $existingByCode = [];
    foreach ($codeRows as $r) $existingByCode[mb_strtoupper($r['code'])] = (int)$r['id'];

    // Deelnemers (voor owner-naam-match)
    $tdRows = db_all(
        'SELECT id, name FROM traject_deelnemers WHERE traject_id = :t',
        [':t' => $trajectId]
    );
    $ownerByName = [];
    foreach ($tdRows as $td) $ownerByName[mb_strtolower(trim((string)$td['name']))] = (int)$td['id'];

    // MoSCoW-label → enum
    $moscowMap = [
        'must' => 'eis', 'eis' => 'eis',
        'should' => 'wens', 'wens' => 'wens',
        'knock-out' => 'ko', 'knockout' => 'ko', 'knock out' => 'ko', 'ko' => 'ko',
    ];

    // Header-aliases (case-insensitive, NL primair)
    $headerAliases = [
        'code'              => ['nr', 'no.', 'no', 'number', 'code'],
        'domein'            => ['domein', 'domain'],
        'titel'             => ['titel', 'title'],
        'omschrijving'      => ['omschrijving', 'description'],
        'fase'              => ['fase', 'phase'],
        'type'              => ['moscow', 'type'],
        'interne_opmerking' => ['interne opmerking', 'interne opmerkingen', 'internal note', 'internal comment'],
        'owner'             => ['bespreken met', 'owner', 'eigenaar'],
    ];

    // Per scope-tab: header detecteren + rijen verzamelen
    $plan = [];
    foreach (REQ_EXCEL_SCOPES as $scope) {
        $sheet = $ss->getSheetByName($scope);
        $rows  = $sheet->toArray(null, true, true, false);
        if (!$rows) continue;

        // Zoek header-rij (eerste rij waarin "Nr" / "Code" voorkomt) in rij 1..3
        $headerRow = null;
        $colByField = [];
        for ($rn = 0; $rn < min(3, count($rows)); $rn++) {
            $row = $rows[$rn];
            $lower = array_map(fn($v) => mb_strtolower(trim((string)$v)), $row);
            foreach ($lower as $i => $name) {
                if ($name === '') continue;
                foreach ($headerAliases as $field => $aliases) {
                    if (in_array($name, $aliases, true) && !isset($colByField[$field])) {
                        $colByField[$field] = $i;
                    }
                }
            }
            if (isset($colByField['code'], $colByField['titel'], $colByField['type'])) {
                $headerRow = $rn;
                break;
            }
            $colByField = [];
        }
        if ($headerRow === null) {
            $result['errors'][] = "Tab '$scope': kolommen 'Nr', 'Titel' en 'MoSCoW' niet gevonden in rij 1-3.";
            continue;
        }

        for ($idx = $headerRow + 1; $idx < count($rows); $idx++) {
            $raw = $rows[$idx];

            $rowNo = $idx + 1;
            $get = fn($field) => isset($colByField[$field])
                ? trim((string)($raw[$colByField[$field]] ?? '')) : '';

            // Beschouw als data-rij alleen als minimaal Titel én MoSCoW gezet
            // zijn. Voorkomt dat de merged "Geen requirements gedefinieerd…"-
            // melding op lege scope-tabs als data wordt gelezen (die staat
            // alleen in kolom A en triggert anders een fake "Nr"-waarde).
            if ($get('titel') === '' && $get('type') === '') continue;

            $result['rows']++;

            $code   = $get('code');
            $domein = $get('domein');
            $title  = $get('titel');
            $desc   = $get('omschrijving');
            $fase   = $get('fase');
            $type   = mb_strtolower($get('type'));
            $note   = $get('interne_opmerking');
            $owner  = $get('owner');

            $rowErr = [];
            if ($title === '') $rowErr[] = 'titel leeg';

            // MoSCoW: vertaal Must/Should/Knock-out → eis/wens/ko
            $typeNorm = $moscowMap[$type] ?? null;
            if ($typeNorm === null) {
                $rowErr[] = "MoSCoW '$type' ongeldig (Must/Should/Knock-out)";
            }

            // Fase: optioneel, integer 1..5
            $faseVal = null;
            if ($fase !== '') {
                $fi = (int)$fase;
                if (!in_array($fi, REQUIREMENT_FASES, true)) {
                    $rowErr[] = "fase '$fase' ongeldig (toegestaan: " . implode(', ', REQUIREMENT_FASES) . ')';
                } else {
                    $faseVal = $fi;
                }
            }

            // Owner: optioneel, naam moet matchen op een deelnemer van dit traject
            $ownerId = null;
            if ($owner !== '') {
                $ownerId = $ownerByName[mb_strtolower(trim($owner))] ?? null;
                if ($ownerId === null) {
                    $rowErr[] = "Bespreken met / owner '$owner' is geen deelnemer van dit traject";
                }
            }

            // Subcategorie afleiden uit "Domein" — formaat "<cat_name> → <sub_name>"
            $subId = null;
            if ($domein === '') {
                $rowErr[] = 'Domein leeg';
            } else {
                // Splits op " → " (pijl met spaties) — fallback op " -> " of " | "
                $parts = preg_split('/\s*(?:→|->|\|)\s*/u', $domein, 2);
                if (count($parts) !== 2) {
                    $rowErr[] = "Domein '$domein' niet in formaat 'Hoofdcategorie → Subcategorie'";
                } else {
                    [$catN, $subN] = array_map('trim', $parts);
                    $key = $scope . '||' . mb_strtolower($catN) . '||' . mb_strtolower($subN);
                    $matches = $subLookup[$key] ?? [];
                    if (count($matches) === 0) {
                        $rowErr[] = "subcategorie '$subN' onbekend binnen $scope/$catN";
                    } elseif (count($matches) > 1) {
                        $rowErr[] = "subcategorie '$subN' is dubbelzinnig (komt voor onder meerdere App soorten in $scope); hernoem unique";
                    } else {
                        $subId = $matches[0];
                    }
                }
            }

            $reqId = null;
            if ($code !== '') {
                $reqId = $existingByCode[mb_strtoupper($code)] ?? null;
                if ($reqId === null) $rowErr[] = "Nr '$code' bestaat niet in dit traject";
            }

            if ($rowErr) {
                $result['errors'][] = "Tab '$scope' rij $rowNo: " . implode(', ', $rowErr);
                continue;
            }

            $common = [
                'sub'      => $subId,
                'title'    => $title,
                'desc'     => $desc,
                'type'     => $typeNorm,
                'fase'     => $faseVal,
                'owner_id' => $ownerId,
                'note'     => $note !== '' ? $note : null,
            ];
            $plan[] = $reqId === null
                ? ['op' => 'create'] + $common
                : ['op' => 'update', 'id' => $reqId] + $common;
        }
    }

    if ($result['errors']) return $result;

    db_transaction(function () use ($plan, $trajectId, &$result) {
        foreach ($plan as $item) {
            $data = [
                'subcategorie_id'    => $item['sub'],
                'title'              => $item['title'],
                'description'        => $item['desc'],
                'type'               => $item['type'],
                'fase'               => $item['fase'],
                'owner_deelnemer_id' => $item['owner_id'],
                'internal_note'      => $item['note'],
            ];
            if ($item['op'] === 'create') {
                requirement_create($trajectId, $data);
                $result['created']++;
            } else {
                requirement_update($item['id'], $trajectId, $data);
                $result['updated']++;
            }
        }
    });
    $result['ok'] = true;
    return $result;
}
