<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * Which database, at which version.
 *
 * This is the argument the tool is built around, and the reason a linter that
 * does not ask for it is either crying wolf or missing things.
 *
 * Almost nothing about migration safety is true in general. Adding a column
 * with a default rewrote the whole table on PostgreSQL 10 and has been instant
 * since 11. Most ALTER TABLE work copied the table on MySQL 5.6 and has been
 * in place since 5.7, with a further set becoming genuinely instant in 8.0.
 * A rule that warns about all of them tells a team on Postgres 16 to work
 * around a problem they have not had for seven years, and they will switch it
 * off. A rule that warns about none of them misses the one that takes
 * production down.
 *
 * So every rule receives this and decides for itself.
 */
final class Target
{
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';
    public const POSTGRES = 'pgsql';
    public const SQLITE = 'sqlite';
    public const SQLSERVER = 'sqlsrv';

    private function __construct(
        public readonly string $driver,
        public readonly int $major,
        public readonly int $minor,
        public readonly ?string $lockTimeout = null,
    ) {}

    /**
     * @param string $version "8.0", "16", "10.11.2". Anything unparseable
     *                        becomes 0.0, which every rule treats as "assume
     *                        the old behaviour", because guessing a modern
     *                        version on no evidence is the direction that
     *                        stays quiet about a real problem.
     * @param string|null $lockTimeout what the project sets before its DDL, if
     *                        it sets anything. Not a property of the server
     *                        and it sits here anyway, because this is the
     *                        object holding everything true about the run
     *                        rather than about the file, and whether a lock
     *                        request is allowed to queue forever is exactly
     *                        that: unknowable from a migration, decided once
     *                        per project.
     */
    public static function of(string $driver, string $version = '', ?string $lockTimeout = null): self
    {
        $driver = strtolower(trim($driver));

        $normalised = match ($driver) {
            'postgres', 'postgresql', 'pgsql' => self::POSTGRES,
            'mysql' => self::MYSQL,
            'mariadb' => self::MARIADB,
            'sqlite', 'sqlite3' => self::SQLITE,
            'sqlsrv', 'mssql', 'sqlserver' => self::SQLSERVER,
            default => $driver,
        };

        preg_match('/^(\d+)(?:\.(\d+))?/', trim($version), $matches);

        $lockTimeout = $lockTimeout === null ? null : trim($lockTimeout);

        return new self(
            $normalised,
            (int) ($matches[1] ?? 0),
            (int) ($matches[2] ?? 0),
            $lockTimeout === '' ? null : $lockTimeout,
        );
    }

    public function isPostgres(): bool
    {
        return $this->driver === self::POSTGRES;
    }

    public function isMysqlFamily(): bool
    {
        return in_array($this->driver, [self::MYSQL, self::MARIADB], true);
    }

    /** Whether the version is known at all. */
    public function isVersioned(): bool
    {
        return $this->major > 0;
    }

    public function atLeast(int $major, int $minor = 0): bool
    {
        if (! $this->isVersioned()) {
            return false;
        }

        return $this->major > $major || ($this->major === $major && $this->minor >= $minor);
    }

    /**
     * Whether adding a column with a default rewrites the table.
     *
     * PostgreSQL 11 stores the default in the catalogue instead of writing it
     * into every row. Before that the operation rewrote the table under an
     * exclusive lock, which on a large table is an outage.
     *
     * MySQL 8.0 gained INSTANT ADD COLUMN, with conditions: the column has to
     * go at the end, the table cannot use ROW_FORMAT=COMPRESSED, and there is
     * a limit on how many instant additions a table can accumulate. Those
     * conditions cannot be checked from a migration file, so this answers for
     * the common case and the rule says what it could not see.
     */
    public function defaultOnAddIsInstant(): bool
    {
        return match (true) {
            $this->isPostgres() => $this->atLeast(11),
            $this->driver === self::MYSQL => $this->atLeast(8),
            $this->driver === self::MARIADB => $this->atLeast(10, 3),
            default => false,
        };
    }

    /**
     * Whether the project bounds how long its DDL waits for a lock.
     *
     * Zero is the trap here and it does not mean the same thing twice. On
     * Postgres lock_timeout = 0 disables the timeout and the statement waits
     * for ever, which is the state this rule exists to warn about, so zero
     * reads as unset. On SQL Server LOCK_TIMEOUT 0 is the opposite, giving up
     * the instant the lock is unavailable, which is the strictest guard there
     * is. Same digit, inverse meaning, so the driver has to be asked.
     */
    public function guardsLockQueue(): bool
    {
        if ($this->lockTimeout === null) {
            return false;
        }

        if ($this->lockTimeout === '0' && ! ($this->driver === self::SQLSERVER)) {
            return false;
        }

        return true;
    }

    /** What the setting is called here, for naming it in a report. */
    public function lockTimeoutSetting(): ?string
    {
        return match (true) {
            $this->isPostgres() => 'lock_timeout',
            $this->isMysqlFamily() => 'lock_wait_timeout',
            $this->driver === self::SQLSERVER => 'LOCK_TIMEOUT',
            // SQLite takes one writer at a time and has no queue of this
            // shape, so there is nothing here to bound and nothing to say.
            default => null,
        };
    }

    /** The statement that bounds the wait, spelled the way this engine takes it. */
    public function lockTimeoutStatement(): ?string
    {
        return match (true) {
            $this->isPostgres() => "SET lock_timeout = '3s'",
            // Seconds on MySQL, milliseconds on Postgres, and getting that
            // backwards is a three order of magnitude mistake in the
            // direction of no timeout at all.
            $this->isMysqlFamily() => 'SET SESSION lock_wait_timeout = 3',
            $this->driver === self::SQLSERVER => 'SET LOCK_TIMEOUT 3000',
            default => null,
        };
    }

    /** Whether an index can be built without blocking writes. */
    public function supportsConcurrentIndex(): bool
    {
        // Postgres has CREATE INDEX CONCURRENTLY. MySQL builds most indexes
        // online since 5.6 but the DDL still takes a brief metadata lock, and
        // that lock queues behind every open transaction, which is the part
        // that surprises people.
        return $this->isPostgres();
    }

    public function label(): string
    {
        $name = match ($this->driver) {
            self::POSTGRES => 'PostgreSQL',
            self::MYSQL => 'MySQL',
            self::MARIADB => 'MariaDB',
            self::SQLITE => 'SQLite',
            self::SQLSERVER => 'SQL Server',
            default => $this->driver,
        };

        return $this->isVersioned() ? "{$name} {$this->major}.{$this->minor}" : $name;
    }
}
