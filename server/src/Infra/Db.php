<?php

declare(strict_types=1);

namespace BetterCal\Infra;

final class Db
{
    private ?\PDO $pdo = null;

    /**
     * The credentials checked with a lock in the transaction open now, each
     * by the identity the check recorded (RequestAuthority::checked), and
     * the serial of that transaction. The serial moves on as each
     * transaction begins or ends here, so a check in one isn't taken for a
     * check in the next.
     */
    private int $txSerial = 0;
    private int $checkedIn = -1;
    /** @var array<string,true> */
    private array $checked = [];

    /**
     * While a request's credential is registered (RequestAuthority::during):
     * called with each write statement made on any connection, and the
     * connection making it, which says what was checked in its transaction.
     */
    private static ?\Closure $onWrite = null;

    /** Set (or with null, clear) the watch on writes; returns the one it replaces. */
    public static function watchWrites(?\Closure $onWrite): ?\Closure
    {
        $was = self::$onWrite;
        self::$onWrite = $onWrite;
        return $was;
    }

    /** Was a credential checked in the transaction open now: any, or, with $identity, that one? */
    public function credentialCheckedNow(?string $identity = null): bool
    {
        if ($this->checkedIn !== $this->txSerial || !$this->pdo()->inTransaction()) {
            return false;
        }
        return $identity === null || isset($this->checked[$identity]);
    }

    /** A credential was checked, locked, in the transaction open now; $identity says which one (RequestAuthority::checked). */
    public function credentialChecked(string $identity): void
    {
        if (!$this->pdo()->inTransaction()) {
            return;
        }
        if ($this->checkedIn !== $this->txSerial) {
            $this->checked = [];
            $this->checkedIn = $this->txSerial;
        }
        $this->checked[$identity] = true;
    }

    public function __construct(private readonly array $cfg)
    {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO($this->cfg['dsn'], $this->cfg['user'], $this->cfg['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            // MySQL-only; tests run domain code against in-memory SQLite.
            if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->pdo->exec("SET time_zone = '+00:00'");
            }
        }
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $pdo = $this->pdo();
        if (self::$onWrite !== null && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1) {
            (self::$onWrite)($sql, $this);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $val = $this->run($sql, $params)->fetchColumn();
        return $val === false ? null : $val;
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($row));
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $fields, string $where, array $whereParams): int
    {
        if ($fields === []) {
            return 0;
        }
        $set = implode(', ', array_map(static fn($c) => "`$c` = ?", array_keys($fields)));
        $stmt = $this->run("UPDATE `$table` SET $set WHERE $where", [...array_values($fields), ...$whereParams]);
        return $stmt->rowCount();
    }

    /**
     * Upsert a full row by primary key (used by undo restore). VALUES(col)
     * rather than MySQL 8's row alias (`AS new_row`): MariaDB accepts only
     * this form, and MySQL still does (deprecated, not removed in 8.4).
     */
    public function upsert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $colSql = implode(', ', array_map(static fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $updates = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $cols));
        $sql = "INSERT INTO `$table` ($colSql) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updates";
        $this->run($sql, array_values($row));
    }

    /**
     * upsert() for many rows with the same columns, in one statement: undo
     * putting back tens of thousands of events. All or none of them.
     *
     * @param list<array<string,mixed>> $rows
     */
    public function upsertMany(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $cols = array_keys($rows[0]);
        $colSql = implode(', ', array_map(static fn($c) => "`$c`", $cols));
        $tuple = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $updates = implode(', ', array_map(static fn($c) => "`$c` = VALUES(`$c`)", $cols));
        $params = [];
        foreach ($rows as $row) {
            foreach ($cols as $c) {
                $params[] = $row[$c] ?? null;
            }
        }
        $this->run("INSERT INTO `$table` ($colSql) VALUES " . implode(', ', array_fill(0, count($rows), $tuple)) . " ON DUPLICATE KEY UPDATE $updates", $params);
    }

    public function tx(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        $this->txSerial++;
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            $this->txSerial++;
        }
    }

    /**
     * tx(), run afresh when the database rolls it back to break a deadlock
     * (MySQL rolls back the whole transaction, so running it again is safe
     * for work that only touches the database). Inside an outer
     * transaction, it's the outer one that was rolled back: not retried.
     */
    public function txRetrying(callable $fn, int $tries = 3): mixed
    {
        for ($i = 1; ; $i++) {
            $outer = $this->pdo()->inTransaction();
            try {
                return $this->tx($fn);
            } catch (\PDOException $e) {
                if ($outer || $i >= $tries || !self::isDeadlock($e)) {
                    throw $e;
                }
            }
        }
    }

    public static function isDeadlock(\PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1213 || (string) $e->getCode() === '40001';
    }

    /** A row refused because a row it refers to isn't there (or one referring to it still is). */
    public static function isForeignKeyViolation(\PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1451, 1452], true);
    }

    /**
     * " FOR UPDATE" on MySQL, where it locks the selected rows for the
     * transaction; empty elsewhere (SQLite, in the tests, has no row locks:
     * its transactions already hold the whole database).
     */
    public function forUpdate(): string
    {
        return $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /**
     * "INSERT IGNORE" on MySQL, SQLite's "INSERT OR IGNORE" elsewhere (the
     * tests): the same statement, so a path that copies tags and people onto
     * a new row runs in the tests as it does in production.
     */
    public function insertIgnore(): string
    {
        return $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    }

    /** Build an IN clause: returns [sqlFragment, params]. */
    public static function in(array $values): array
    {
        if ($values === []) {
            return ['(NULL)', []];
        }
        return ['(' . implode(', ', array_fill(0, count($values), '?')) . ')', array_values($values)];
    }
}
