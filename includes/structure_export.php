<?php
/**
 * Export van de applicatie-structuur (categorieën, applicatiesoorten,
 * subcategorie-templates, DEMO-catalog) naar één .xlsx.
 *
 * Modes:
 *   - 'current'  : met alle huidige data
 *   - 'template' : lege sheets, alleen headers + instructies
 *
 * Kolomvolgorde is tevens het import-contract (zie structure_import.php).
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

const STRUCT_SHEETS = [
    'Categorieen'      => ['code', 'name', 'type'],
    'Applicatiesoorten'=> ['name', 'description', 'bron'],
    'Subcategorieen'   => ['categorie_code', 'applicatiesoort_name', 'name', 'bron'],
    'DEMO-vragen'      => ['block', 'text'],
];

function structure_export_xlsx(string $mode, string $filename): void {
    $ss = new Spreadsheet();
    $ss->removeSheetByIndex(0);

    // ── Instructies-tab ────────────────────────────────────────────
    $info = $ss->createSheet();
    $info->setTitle('Instructies');
    $info->fromArray([
        ['Structuur-template'],
        [''],
        ['Vul onderstaande tabbladen. Kolomvolgorde en -namen niet wijzigen.'],
        [''],
        ['LET OP: Categorieen-code moet exact een van deze zes zijn: FUNC, NFR, VEND, IMPL, SUP, LIC.'],
        ['Alle zes codes zijn verplicht; naam mag je vrij kiezen. Eigen codes worden afgekeurd.'],
        [''],
        ['Categorieen       — code (FUNC/NFR/VEND/IMPL/SUP/LIC, alle zes verplicht), name, type (functional/non_functional/other)'],
        ['Applicatiesoorten — name (uniek), description, bron'],
        ['Subcategorieen    — categorie_code (verwijst naar Categorieen), applicatiesoort_name (optioneel, verwijst naar Applicatiesoorten), name, bron'],
        ['DEMO-vragen       — block (1..n), text'],
        [''],
        ['Import overschrijft de huidige structuur niet; upload alleen op een lege structuur.'],
    ], null, 'A1');
    $info->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $info->getColumnDimension('A')->setWidth(110);

    // ── Data-sheets ────────────────────────────────────────────────
    foreach (STRUCT_SHEETS as $title => $cols) {
        $sh = $ss->createSheet();
        $sh->setTitle($title);
        $sh->fromArray([$cols], null, 'A1');
        $sh->getStyle('A1:' . _col_letter(count($cols)) . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EC4899']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        foreach ($cols as $i => $_) $sh->getColumnDimensionByColumn($i + 1)->setWidth(24);
        $sh->freezePane('A2');
    }

    if ($mode === 'current') {
        _struct_fill_current($ss);
    }

    $ss->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    (new XlsxWriter($ss))->save('php://output');
    exit;
}

function _struct_fill_current(Spreadsheet $ss): void {
    $cats = db_all('SELECT code, name, type FROM categorieen ORDER BY sort_order, id');
    $apps = db_all('SELECT name, description, bron FROM applicatiesoorten ORDER BY name');
    $subs = db_all(
        'SELECT c.code AS categorie_code, a.name AS applicatiesoort_name, t.name, t.bron
           FROM subcategorie_templates t
           JOIN categorieen c ON c.id = t.categorie_id
           LEFT JOIN applicatiesoorten a ON a.id = t.applicatiesoort_id
          ORDER BY c.sort_order, a.name, t.name, t.id'
    );
    $demo = db_all('SELECT block, text FROM demo_question_catalog WHERE active = 1 ORDER BY block, sort_order, id');

    _struct_write_rows($ss->getSheetByName('Categorieen'),       $cats, ['code','name','type']);
    _struct_write_rows($ss->getSheetByName('Applicatiesoorten'), $apps, ['name','description','bron']);
    _struct_write_rows($ss->getSheetByName('Subcategorieen'),    $subs, ['categorie_code','applicatiesoort_name','name','bron']);
    _struct_write_rows($ss->getSheetByName('DEMO-vragen'),       $demo, ['block','text']);
}

function _struct_write_rows($sheet, array $rows, array $cols): void {
    $r = 2;
    foreach ($rows as $row) {
        $out = [];
        foreach ($cols as $c) $out[] = $row[$c] ?? '';
        $sheet->fromArray([$out], null, 'A' . $r++);
    }
}

function _col_letter(int $n): string {
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/**
 * Wipe-check: mag de structuur weg?
 * Gate: 0 requirements én 0 leveranciers in de hele app.
 */
function structure_wipe_allowed(): bool {
    $req = (int)db()->query('SELECT COUNT(*) FROM requirements')->fetchColumn();
    $lev = (int)db()->query('SELECT COUNT(*) FROM leveranciers')->fetchColumn();
    return $req === 0 && $lev === 0;
}

/**
 * Verwijdert de hele structuur. Veronderstelt dat structure_wipe_allowed()
 * de caller al heeft bewaakt; deze functie zelf controleert ook nogmaals.
 *
 * Wipe-scope: weights → subcategorieen → subcategorie_templates
 *             → demo_question_catalog → categorieen → applicatiesoorten.
 */
function structure_wipe(): void {
    if (!structure_wipe_allowed()) {
        throw new RuntimeException('Wipe niet toegestaan zolang er requirements of leveranciers bestaan.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM weights');
        $pdo->exec('DELETE FROM subcategorieen');
        $pdo->exec('DELETE FROM subcategorie_templates');
        $pdo->exec('DELETE FROM demo_question_catalog');
        $pdo->exec('DELETE FROM categorieen');
        $pdo->exec('DELETE FROM applicatiesoorten');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
