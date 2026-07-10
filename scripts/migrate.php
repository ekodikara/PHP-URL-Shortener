<?php
/*
 * Snip — minimal forward-only SQL migration runner.
 *
 * Applies migrations/*.sql in filename order, each exactly once, tracked in a
 * `schema_migrations` table. `schema.sql` remains the initial baseline (loaded
 * only on a fresh DB volume); this runner layers every change on top, so it is
 * safe — and required — on EVERY deploy, whether the volume is fresh or existing.
 *
 *   Local:  docker compose exec -T web php scripts/migrate.php
 *   Prod:   docker compose -f docker-compose.prod.yml exec -T web php scripts/migrate.php
 *
 * Migrations must be forward-only. Each file is recorded once on success; a
 * failure aborts with a non-zero exit and the remaining files are not applied.
 */

require __DIR__ . '/../config.php';   // provides $pdo + env-based DB config

$dir = __DIR__ . '/../migrations';

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at INT UNSIGNED NOT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));

$files = glob($dir . '/*.sql');
sort($files);
$ran = 0;

foreach ($files as $file) {
    $version = basename($file);
    if (isset($applied[$version])) {
        continue;
    }
    $sql = (string) file_get_contents($file);
    // Strip full-line SQL comments first (so a comment preceding a statement
    // can't swallow it), then split on ';' terminators. Migrations must not
    // contain ';' inside string literals (keep them DDL-only).
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+|;\s*$/', $sql)));

    echo "applying {$version} …\n";
    try {
        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
        $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
            ->execute(array($version, time()));
        $ran++;
        echo "  ok\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  FAILED {$version}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo $ran ? "done: {$ran} migration(s) applied.\n" : "up to date; nothing to apply.\n";
