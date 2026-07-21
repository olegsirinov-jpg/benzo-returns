<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use RuntimeException;

class Db
{
    /** @var PDO|null */
    private static $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::str('DB_HOST', '127.0.0.1'),
            Env::int('DB_PORT', 3306),
            Env::str('DB_NAME', 'povirnenya')
        );

        try {
            self::$pdo = new PDO($dsn, Env::str('DB_USER', 'root'), Env::str('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Не вдалося підключитися до БД: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return mixed
     */
    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
              . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,mixed>    $whereParams
     */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $set = [];
        foreach (array_keys($data) as $col) {
            $set[] = '`' . $col . '`=?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(',', $set) . ' WHERE ' . $where;
        return self::run($sql, array_merge(array_values($data), $whereParams))->rowCount();
    }

    public static function begin(): void
    {
        self::pdo()->beginTransaction();
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
