<?php
declare(strict_types=1);

namespace StageCms\Infrastructure;

use PDO;
use StageCms\Input;
use Throwable;

final readonly class Database
{
    public PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Cannot create the data directory.');
            }
        }
        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
        if ($path !== ':memory:') {
            chmod($path, 0600);
        }
    }

    public function migrate(): void
    {
        $version = $this->schemaVersion();
        if ($version === 3) {
            return;
        }
        if ($version === 2) {
            $this->transaction(function (): void {
                if ($this->schemaVersion() !== 2) {
                    return;
                }
                $this->pdo->exec("ALTER TABLE pages ADD COLUMN translation_group TEXT NOT NULL DEFAULT '';
                    ALTER TABLE pages ADD COLUMN locale TEXT NOT NULL DEFAULT 'en';
                    UPDATE pages SET translation_group = id, locale = (SELECT locale FROM revisions WHERE page_id = pages.id AND version = pages.version);
                    CREATE UNIQUE INDEX page_languages ON pages(translation_group, locale);
                    CREATE TABLE page_bindings (name TEXT NOT NULL, locale TEXT NOT NULL, page_id TEXT NOT NULL UNIQUE REFERENCES pages(id), path TEXT NOT NULL UNIQUE, type TEXT NOT NULL, PRIMARY KEY(name, locale));
                    CREATE TABLE page_redirects (slug TEXT PRIMARY KEY, page_id TEXT NOT NULL REFERENCES pages(id));
                    PRAGMA user_version = 3;");
            });
            return;
        }
        if ($version === 1) {
            $this->transaction(function (): void {
                if ($this->schemaVersion() !== 1) {
                    return;
                }
                $this->pdo->exec("ALTER TABLE revisions ADD COLUMN type TEXT NOT NULL DEFAULT 'page';
                    ALTER TABLE revisions ADD COLUMN locale TEXT NOT NULL DEFAULT 'en';
                    ALTER TABLE revisions ADD COLUMN fields TEXT NOT NULL DEFAULT '{}';
                    ALTER TABLE settings ADD COLUMN theme TEXT NOT NULL DEFAULT '';
                    PRAGMA user_version = 2;");
            });
            $this->migrate();
            return;
        }
        if ($version !== 0) {
            throw new \RuntimeException('This database requires a newer Stage CMS version.');
        }
        $this->transaction(function (): void {
            if ($this->schemaVersion() !== 0) {
                return;
            }
            $this->pdo->exec(<<<SQL
                CREATE TABLE owner (id INTEGER PRIMARY KEY CHECK (id = 1), name TEXT NOT NULL, email TEXT NOT NULL, password_hash TEXT NOT NULL);
                CREATE TABLE sessions (hash TEXT PRIMARY KEY, csrf TEXT NOT NULL, authenticated INTEGER NOT NULL DEFAULT 0, expires INTEGER NOT NULL);
                CREATE TABLE login_attempts (key TEXT PRIMARY KEY, attempts INTEGER NOT NULL, expires INTEGER NOT NULL);
                CREATE TABLE tokens (id TEXT PRIMARY KEY, hash TEXT NOT NULL UNIQUE, name TEXT NOT NULL, scopes TEXT NOT NULL, expires INTEGER NOT NULL, created_at TEXT NOT NULL, last_used TEXT, revoked_at TEXT);
                CREATE TABLE settings (id INTEGER PRIMARY KEY CHECK (id = 1), title TEXT NOT NULL, description TEXT NOT NULL);
                INSERT INTO settings VALUES (1, 'Your publication', '');
                CREATE TABLE media (id TEXT PRIMARY KEY, name TEXT NOT NULL, mime TEXT NOT NULL, bytes INTEGER NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, alt TEXT NOT NULL, sha256 TEXT NOT NULL, created_at TEXT NOT NULL);
                CREATE TABLE pages (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE, version INTEGER NOT NULL, published_version INTEGER, published_slug TEXT UNIQUE, archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL);
                CREATE TABLE revisions (page_id TEXT NOT NULL REFERENCES pages(id), version INTEGER NOT NULL, title TEXT NOT NULL, slug TEXT NOT NULL, excerpt TEXT NOT NULL, body TEXT NOT NULL, cover TEXT REFERENCES media(id), action TEXT NOT NULL, actor TEXT NOT NULL, created_at TEXT NOT NULL, PRIMARY KEY(page_id, version));
                CREATE TABLE revision_media (page_id TEXT NOT NULL, version INTEGER NOT NULL, media_id TEXT NOT NULL REFERENCES media(id), PRIMARY KEY(page_id, version, media_id), FOREIGN KEY(page_id, version) REFERENCES revisions(page_id, version));
                CREATE INDEX revisions_updated ON revisions(created_at);
                PRAGMA user_version = 1;
            SQL);
        });
        $this->migrate();
    }

    private function schemaVersion(): int
    {
        $statement = $this->pdo->query('PRAGMA user_version');
        $version = $statement === false ? false : $statement->fetchColumn();
        if (!is_int($version)) {
            throw new \RuntimeException('Cannot read the database version.');
        }
        return $version;
    }

    /**
     * @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch();
        return $row === false ? null : Input::object($row);
    }

    /**
     * @param array<string, int|string|null> $parameters
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            $rows[] = Input::object($row);
        }
        return $rows;
    }

    /** @param array<string, int|string|null> $parameters */
    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $operation();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->pdo->exec('ROLLBACK');
            throw $error;
        }
    }
}
