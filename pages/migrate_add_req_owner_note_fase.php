<?php
/**
 * Eenmalige migratie: voeg owner_deelnemer_id, internal_note en fase
 * toe aan de requirements-tabel.
 *
 *   - owner_deelnemer_id  → FK naar traject_deelnemers.id (NULL toegestaan)
 *   - internal_note       → vrije tekst (intern, niet zichtbaar voor leveranciers)
 *   - fase                → kleine integer (1..5 in UI, kolom 1..255)
 *
 * Idempotent: checkt eerst of de kolom of constraint al bestaat.
 *
 * Uitvoeren: open /pages/migrate_add_req_owner_note_fase.php als architect.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_can('users.edit');

if (is_demo_mode()) {
    http_response_code(403);
    exit('Schema-migraties zijn uitgeschakeld in de demo-omgeving.');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$dbn = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

function _col_exists(PDO $pdo, string $db, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $st->execute([':s' => $db, ':t' => $table, ':c' => $col]);
    return (int)$st->fetchColumn() > 0;
}

function _constraint_exists(PDO $pdo, string $db, string $table, string $name): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND CONSTRAINT_NAME = :n'
    );
    $st->execute([':s' => $db, ':t' => $table, ':n' => $name]);
    return (int)$st->fetchColumn() > 0;
}

echo "Migratie: kolommen toevoegen aan requirements\n";
echo str_repeat('-', 50) . "\n";

if (!_col_exists($pdo, $dbn, 'requirements', 'owner_deelnemer_id')) {
    $pdo->exec(
        'ALTER TABLE requirements
            ADD COLUMN owner_deelnemer_id INT UNSIGNED NULL AFTER type,
            ADD KEY idx_req_owner (owner_deelnemer_id)'
    );
    echo "✓ Kolom owner_deelnemer_id toegevoegd\n";
} else {
    echo "· owner_deelnemer_id bestaat al\n";
}

if (!_constraint_exists($pdo, $dbn, 'requirements', 'fk_req_owner')) {
    $pdo->exec(
        'ALTER TABLE requirements
            ADD CONSTRAINT fk_req_owner
            FOREIGN KEY (owner_deelnemer_id) REFERENCES traject_deelnemers(id)
            ON DELETE SET NULL'
    );
    echo "✓ Foreign key fk_req_owner toegevoegd\n";
} else {
    echo "· fk_req_owner bestaat al\n";
}

if (!_col_exists($pdo, $dbn, 'requirements', 'internal_note')) {
    $pdo->exec('ALTER TABLE requirements ADD COLUMN internal_note TEXT NULL AFTER owner_deelnemer_id');
    echo "✓ Kolom internal_note toegevoegd\n";
} else {
    echo "· internal_note bestaat al\n";
}

if (!_col_exists($pdo, $dbn, 'requirements', 'fase')) {
    $pdo->exec('ALTER TABLE requirements ADD COLUMN fase TINYINT UNSIGNED NULL AFTER internal_note');
    echo "✓ Kolom fase toegevoegd\n";
} else {
    echo "· fase bestaat al\n";
}

echo "\nKlaar. Verwijder dit script wanneer alle omgevingen gemigreerd zijn.\n";
