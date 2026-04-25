<?php
/**
 * Import van de structuur-Excel (zie structure_export.php voor het format).
 * Strict, transactioneel, alleen op een lege structuur.
 */

if (!defined('APP_BOOT')) { http_response_code(403); exit('Forbidden'); }

use PhpOffice\PhpSpreadsheet\IOFactory;

function structure_is_empty(): bool {
    $pdo = db();
    foreach (['categorieen','applicatiesoorten','subcategorie_templates','demo_question_catalog'] as $t) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) return false;
    }
    return true;
}

/**
 * Leest het uploaded xlsx-bestand en schrijft de structuur weg.
 * Gooit RuntimeException bij elke validatiefout; transactie rolt terug.
 *
 * @return array{cat:int,app:int,sub:int,demo:int}
 */
function structure_import_xlsx(string $tmpPath): array {
    if (!structure_is_empty()) {
        throw new RuntimeException('Upload alleen mogelijk op een lege structuur — gebruik eerst Wipe.');
    }

    try {
        $ss = IOFactory::load($tmpPath);
    } catch (Throwable $e) {
        throw new RuntimeException('Kon Excel niet lezen: ' . $e->getMessage());
    }

    $required = ['Categorieen', 'Applicatiesoorten', 'Subcategorieen', 'DEMO-vragen'];
    foreach ($required as $t) {
        if ($ss->getSheetByName($t) === null) {
            throw new RuntimeException("Tabblad '$t' ontbreekt.");
        }
    }

    $cats = _struct_read_sheet($ss->getSheetByName('Categorieen'),       ['code','name','type']);
    $apps = _struct_read_sheet($ss->getSheetByName('Applicatiesoorten'), ['name','description','bron']);
    $subs = _struct_read_sheet($ss->getSheetByName('Subcategorieen'),    ['categorie_code','applicatiesoort_name','name','bron']);
    $demo = _struct_read_sheet($ss->getSheetByName('DEMO-vragen'),       ['block','text']);

    $validTypes = ['functional','non_functional','other'];
    // De applicatie heeft vaste top-level categorie-codes waaraan scoring,
    // wizard-stappen, Excel-exports en rapportage vasthangen. Eigen codes
    // zouden orphan-requirements opleveren — afkeuren bij import.
    $allowedCatCodes = ['FUNC','NFR','VEND','IMPL','SUP','LIC'];
    $catByCode = [];
    foreach ($cats as $i => $r) {
        $code = trim((string)$r['code']);
        $name = trim((string)$r['name']);
        $type = trim((string)$r['type']);
        if ($code === '' || $name === '' || $type === '') {
            throw new RuntimeException('Categorieen rij ' . ($i + 2) . ': code, name en type zijn verplicht.');
        }
        if (!in_array($code, $allowedCatCodes, true)) {
            throw new RuntimeException(
                'Categorieen rij ' . ($i + 2) . ": code '$code' is niet toegestaan. "
                . 'Toegestane codes: ' . implode(', ', $allowedCatCodes)
                . '. Naam mag je vrij kiezen, de code zelf niet.'
            );
        }
        if (!in_array($type, $validTypes, true)) {
            throw new RuntimeException('Categorieen rij ' . ($i + 2) . ": type '$type' ongeldig (functional/non_functional/other).");
        }
        if (isset($catByCode[$code])) {
            throw new RuntimeException("Categorieen: dubbele code '$code'.");
        }
        $catByCode[$code] = true;
    }
    $missing = array_diff($allowedCatCodes, array_keys($catByCode));
    if ($missing) {
        throw new RuntimeException(
            'Categorieen: alle zes vaste codes zijn verplicht. Ontbreekt: '
            . implode(', ', $missing) . '.'
        );
    }

    $appByName = [];
    foreach ($apps as $i => $r) {
        $name = trim((string)$r['name']);
        if ($name === '') {
            throw new RuntimeException('Applicatiesoorten rij ' . ($i + 2) . ': name is verplicht.');
        }
        if (isset($appByName[$name])) {
            throw new RuntimeException("Applicatiesoorten: dubbele naam '$name'.");
        }
        $appByName[$name] = true;
    }

    foreach ($subs as $i => $r) {
        $cc = trim((string)$r['categorie_code']);
        $an = trim((string)$r['applicatiesoort_name']);
        $nm = trim((string)$r['name']);
        if ($cc === '' || $nm === '') {
            throw new RuntimeException('Subcategorieen rij ' . ($i + 2) . ': categorie_code en name zijn verplicht.');
        }
        if (!isset($catByCode[$cc])) {
            throw new RuntimeException("Subcategorieen rij " . ($i + 2) . ": onbekende categorie_code '$cc'.");
        }
        if ($an !== '' && !isset($appByName[$an])) {
            throw new RuntimeException("Subcategorieen rij " . ($i + 2) . ": onbekende applicatiesoort_name '$an'.");
        }
    }

    foreach ($demo as $i => $r) {
        $block = (int)($r['block'] ?? 0);
        $text  = trim((string)$r['text']);
        if ($block < 1) {
            throw new RuntimeException('DEMO-vragen rij ' . ($i + 2) . ': block moet >= 1 zijn.');
        }
        if ($text === '') {
            throw new RuntimeException('DEMO-vragen rij ' . ($i + 2) . ': text is verplicht.');
        }
    }

    // ── Schrijven (transactioneel) ────────────────────────────────
    // sort_order in de DB is een implementatie-detail (vaste volgorde
    // FUNC/NFR/VEND/IMPL/SUP/LIC voor categorieen; alfabetisch voor de rest).
    $catSortOrder = ['FUNC' => 10, 'NFR' => 20, 'VEND' => 30, 'IMPL' => 35, 'SUP' => 40, 'LIC' => 50];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $catIds = [];
        $st = $pdo->prepare('INSERT INTO categorieen (code, name, type, sort_order) VALUES (:c,:n,:t,:o)');
        foreach ($cats as $r) {
            $code = trim((string)$r['code']);
            $st->execute([
                ':c' => $code,
                ':n' => trim((string)$r['name']),
                ':t' => trim((string)$r['type']),
                ':o' => $catSortOrder[$code] ?? 99,
            ]);
            $catIds[$code] = (int)$pdo->lastInsertId();
        }

        $appIds = [];
        $st = $pdo->prepare('INSERT INTO applicatiesoorten (name, description, bron, sort_order) VALUES (:n,:d,:b,0)');
        foreach ($apps as $r) {
            $name = trim((string)$r['name']);
            $st->execute([
                ':n' => $name,
                ':d' => trim((string)($r['description'] ?? '')),
                ':b' => trim((string)($r['bron'] ?? '')) ?: null,
            ]);
            $appIds[$name] = (int)$pdo->lastInsertId();
        }

        $st = $pdo->prepare(
            'INSERT INTO subcategorie_templates (categorie_id, applicatiesoort_id, name, bron, sort_order)
             VALUES (:c,:a,:n,:b,0)'
        );
        foreach ($subs as $r) {
            $an = trim((string)$r['applicatiesoort_name']);
            $st->execute([
                ':c' => $catIds[trim((string)$r['categorie_code'])],
                ':a' => $an !== '' ? $appIds[$an] : null,
                ':n' => trim((string)$r['name']),
                ':b' => trim((string)($r['bron'] ?? '')) ?: null,
            ]);
        }

        $st = $pdo->prepare(
            'INSERT INTO demo_question_catalog (block, sort_order, text, active, created_at, updated_at)
             VALUES (:b,:o,:t,1,NOW(),NOW())'
        );
        $blockCounters = [];
        foreach ($demo as $r) {
            $blk = (int)$r['block'];
            $blockCounters[$blk] = ($blockCounters[$blk] ?? 0) + 10;
            $st->execute([
                ':b' => $blk,
                ':o' => $blockCounters[$blk],
                ':t' => trim((string)$r['text']),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Import mislukt: ' . $e->getMessage());
    }

    return [
        'cat'  => count($cats),
        'app'  => count($apps),
        'sub'  => count($subs),
        'demo' => count($demo),
    ];
}

function _struct_read_sheet($sheet, array $expectedCols): array {
    $rows = $sheet->toArray(null, true, true, false);
    if (!$rows) return [];
    $header = array_map(fn($v) => trim((string)$v), $rows[0]);
    foreach ($expectedCols as $i => $c) {
        if (($header[$i] ?? '') !== $c) {
            throw new RuntimeException("Tabblad '{$sheet->getTitle()}': kolom " . ($i + 1) . " moet '$c' heten (gevonden: '" . ($header[$i] ?? '') . "').");
        }
    }
    $out = [];
    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $allEmpty = true;
        foreach ($row as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
        if ($allEmpty) continue;
        $assoc = [];
        foreach ($expectedCols as $i => $c) $assoc[$c] = $row[$i] ?? '';
        $out[] = $assoc;
    }
    return $out;
}
