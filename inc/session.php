<?php
/*
 * Snip — MySQL-backed session handler.
 *
 * The default PHP handler stores sessions as files inside the web container, so
 * they don't survive across multiple web instances — the one thing that blocks
 * horizontal scaling. With SESSION_DRIVER=db, sessions live in the `sessions`
 * table instead, shared by every instance that talks to the same database
 * (the single box today; an ECS fleet + RDS later) with no extra infrastructure.
 *
 * For very high traffic, a Redis/ElastiCache handler is the next step (a
 * per-request DB write is fine at Snip's scale, where every request already
 * hits the DB). See docs/AWS-SCALE-ARCHITECTURE.md.
 *
 * Session payloads are stored in a BLOB column: PHP's serializer emits arbitrary
 * bytes, which a TEXT/utf8mb4 column could corrupt.
 */
final class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'sessions')
    {
        $this->pdo = $pdo;
        // Table name is not user input (config constant); still, allow only a
        // safe identifier so it can never become an injection vector.
        $this->table = preg_match('/^[A-Za-z0-9_]+$/', $table) ? $table : 'sessions';
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE id = ? AND expires >= ?");
        $stmt->execute(array($id, time()));
        $row = $stmt->fetch();
        return $row ? (string) $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $ttl = (int) ini_get('session.gc_maxlifetime');
        if ($ttl <= 0) {
            $ttl = 1440;
        }
        $expires = time() + $ttl;
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (id, data, expires) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), expires = VALUES(expires)"
        );
        return $stmt->execute(array($id, $data, $expires));
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute(array($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires < ?");
        $stmt->execute(array(time()));
        return $stmt->rowCount();
    }
}
