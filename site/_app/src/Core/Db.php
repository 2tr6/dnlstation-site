<?php
declare(strict_types=1);

namespace Dnl\Core;

defined('DNL_APP') || exit;

use PDO;
use PDOException;
use PDOStatement;

/**
 * İnce PDO sarmalayıcı. Tüm sorgular hazır ifadelerle (prepared statements) çalışır;
 * SQL içine asla değişken birleştirilmez. Tablo/kolon adları yalnızca koddaki sabitlerden gelir.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $c = Config::get('db');
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (($c['driver'] ?? 'mysql') === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
            // FOUND_ROWS: UPDATE etkilenen değil eşleşen satır sayısını döndürsün (SQLite ile aynı davranış; koşullu kilitler buna dayanır)
            $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts + [PDO::MYSQL_ATTR_FOUND_ROWS => true]);
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string
    {
        return (string)Config::get('db.driver', 'mysql');
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        // MySQL yerel (emüle edilmeyen) hazırlanmış ifadelerde aynı isimli parametre iki kez kullanılamaz.
        // Tekrarlananları otomatik olarak benzersiz isimlere çevirip aynı değeri bağlarız.
        $seen = [];
        $sql = (string)preg_replace_callback('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)/', static function ($m) use (&$seen, &$params) {
            $n = $m[1];
            if (!array_key_exists($n, $params)) {
                return $m[0];
            }
            $seen[$n] = ($seen[$n] ?? 0) + 1;
            if ($seen[$n] === 1) {
                return $m[0];
            }
            $alias = $n . '__' . $seen[$n];
            $params[$alias] = $params[$n];
            return ':' . $alias;
        }, $sql);
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** UPDATE/DELETE: etkilenen satır sayısı */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $data kolon adları koddan gelir, değerler bağlanır */
    public static function insert(string $table, array $data): int
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        foreach ($cols as $c) {
            self::assertIdent($c);
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdent($table);
        $set = [];
        $params = [];
        foreach ($data as $c => $v) {
            self::assertIdent($c);
            $set[] = "$c = :s_$c";
            $params["s_$c"] = $v;
        }
        $cond = [];
        foreach ($where as $c => $v) {
            self::assertIdent($c);
            $cond[] = "$c = :w_$c";
            $params["w_$c"] = $v;
        }
        return self::exec('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond), $params);
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    /** Veritabanı UTC zamanını ISO 8601 biçimine çevirir (tarayıcılar için) */
    public static function iso(?string $s): ?string
    {
        return $s === null ? null : str_replace(' ', 'T', $s) . 'Z';
    }

    /** UTC zaman damgası (veritabanında tüm zamanlar UTC saklanır) */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function assertIdent(string $s): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $s)) {
            throw new \InvalidArgumentException('Geçersiz tanımlayıcı');
        }
    }
}
