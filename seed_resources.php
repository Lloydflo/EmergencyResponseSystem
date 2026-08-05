<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$pdo = get_db_connection();
if (!$pdo) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$personnelSeeds = [
    ['name' => 'Responder Ana Reyes', 'role' => 'Paramedic'],
    ['name' => 'Responder Mark Santos', 'role' => 'EMT'],
    ['name' => 'Responder Leo Cruz', 'role' => 'Nurse'],
];

$equipmentSeeds = [
    ['name' => 'Portable Defibrillator', 'location' => 'Station 1'],
    ['name' => 'Trauma Kit', 'location' => 'Station 2'],
    ['name' => 'Oxygen Tank', 'location' => 'Station 3'],
];

$insertedPersonnel = 0;
$insertedEquipment = 0;

function has_primary_key(PDO $pdo, string $table): bool {
    $stmt = $pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function is_auto_increment(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $extra = strtolower((string)($row['EXTRA'] ?? ''));
    return strpos($extra, 'auto_increment') !== false;
}

function ensure_auto_increment_id(PDO $pdo, string $table, bool $requirePrimaryKey): void {
    if ($requirePrimaryKey && !has_primary_key($pdo, $table)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
    }

    if (!is_auto_increment($pdo, $table, 'id')) {
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$table}`")->fetchColumn();
        $nextId = $maxId + 1;
        $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT={$nextId}");
    }
}

try {
    // Normalize schema so inserts work reliably on this DB snapshot.
    ensure_auto_increment_id($pdo, 'staff', true);
    ensure_auto_increment_id($pdo, 'resources', false);
    ensure_auto_increment_id($pdo, 'resource_requests', false);

    $pdo->beginTransaction();

    $existsStaff = $pdo->prepare('SELECT id FROM staff WHERE name = ? LIMIT 1');
    $insertStaff = $pdo->prepare(
        "INSERT INTO staff (name, role, phone, email, status, assigned_resource_id, created_at, updated_at)
         VALUES (?, ?, NULL, NULL, 'available', NULL, CURRENT_TIMESTAMP, NULL)"
    );

    foreach ($personnelSeeds as $seed) {
        $existsStaff->execute([$seed['name']]);
        if ($existsStaff->fetch()) {
            continue;
        }
        $insertStaff->execute([$seed['name'], $seed['role']]);
        $insertedPersonnel++;
    }

    $existsEquipment = $pdo->prepare("SELECT id FROM resources WHERE type = 'equipment' AND name = ? LIMIT 1");
    $insertEquipment = $pdo->prepare(
        "INSERT INTO resources (type, name, code, status, location, notes, created_at, updated_at)
         VALUES ('equipment', ?, ?, 'available', ?, 'Seeded from setup script', CURRENT_TIMESTAMP, NULL)"
    );

    foreach ($equipmentSeeds as $index => $seed) {
        $existsEquipment->execute([$seed['name']]);
        if ($existsEquipment->fetch()) {
            continue;
        }
        $code = 'EQ-SEED-' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        $insertEquipment->execute([$seed['name'], $code, $seed['location']]);
        $insertedEquipment++;
    }

    $pdo->commit();

    $totalVehicles = (int)$pdo->query("SELECT COUNT(*) FROM units WHERE status != 'maintenance'")->fetchColumn();
    $totalPersonnel = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status IN ('available','on_duty')")->fetchColumn();
    $totalEquipment = (int)$pdo->query("SELECT COUNT(*) FROM resources WHERE type = 'equipment' AND status != 'maintenance'")->fetchColumn();

    echo "Seed complete.\n";
    echo "Inserted personnel: {$insertedPersonnel}\n";
    echo "Inserted equipment: {$insertedEquipment}\n";
    echo "Cards totals => vehicles: {$totalVehicles}, personnel: {$totalPersonnel}, equipment: {$totalEquipment}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
