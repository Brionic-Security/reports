<?php

declare(strict_types=1);

/**
 * Migration runner. Applies every migrations/*.sql file not yet recorded in the
 * "migrations" table, in filename order.
 *
 *   php scripts/migrate.php          # apply pending migrations
 *   php scripts/migrate.php --fresh  # drop everything and re-run (DESTRUCTIVE)
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Support\Database;

$fresh = in_array('--fresh', $argv, true);
$driver = Database::driver();
$pdo = Database::connection();

echo "Using {$driver} database.\n";

if ($fresh) {
    echo "Dropping all tables (--fresh)...\n";
    dropAllTables($pdo, $driver);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(190) NOT NULL,
    applied_at VARCHAR(25) NOT NULL
)');

$applied = [];
foreach (Database::select('SELECT name FROM migrations') as $row) {
    $applied[$row['name']] = true;
}

$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }

    echo "Applying {$name}... ";
    $sql = applyTokens(file_get_contents($file) ?: '', $driver);

    $pdo->beginTransaction();
    try {
        foreach (splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
        Database::insert('INSERT INTO migrations (name, applied_at) VALUES (?, ?)', [$name, gmdate('Y-m-d H:i:s')]);
        $pdo->commit();
        echo "done\n";
        $ran++;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Applied {$ran} migration(s).\n";

/* ------------------------------------------------------------------ */

function applyTokens(string $sql, string $driver): string
{
    if ($driver === 'mysql') {
        return strtr($sql, [
            '__ID__' => 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            '__FK__' => 'BIGINT UNSIGNED',
        ]);
    }
    return strtr($sql, [
        '__ID__' => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
        '__FK__' => 'INTEGER',
    ]);
}

/** @return string[] */
function splitStatements(string $sql): array
{
    $clean = [];
    foreach (explode("\n", $sql) as $line) {
        if (str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $statements = [];
    foreach (explode(';', implode("\n", $clean)) as $piece) {
        $piece = trim($piece);
        if ($piece !== '') {
            $statements[] = $piece;
        }
    }
    return $statements;
}

function dropAllTables(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Database::select('SHOW TABLES') as $row) {
            $pdo->exec('DROP TABLE IF EXISTS `' . array_values($row)[0] . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } else {
        foreach (Database::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $row) {
            $pdo->exec('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }
    }
}
