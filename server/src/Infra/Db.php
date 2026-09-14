<?php

declare(strict_types=1);

namespace BetterCal\Infra;

final class Db
{
    private ?\PDO $pdo = null;

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
        $stmt = $this->pdo()->prepare($sql);
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

    /** Upsert a full row by primary key (used by undo restore). */
    public function upsert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $colSql = implode(', ', array_map(static fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $updates = implode(', ', array_map(static fn($c) => "`$c` = new_row.`$c`", $cols));
        $sql = "INSERT INTO `$table` ($colSql) VALUES ($placeholders) AS new_row ON DUPLICATE KEY UPDATE $updates";
        $this->run($sql, array_values($row));
    }

    public function tx(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
