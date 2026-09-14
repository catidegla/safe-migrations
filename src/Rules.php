<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * What is unsafe, and on which database, at which version.
 *
 * Two ideas run through every rule here.
 *
 * The first is that almost nothing is unsafe in general. Adding a column with
 * a default rewrote the table on PostgreSQL 10 and has been instant since 11.
 * A rule that warns regardless is telling a team on 16 to work around a
 * problem they stopped having seven years ago, and the second time that
 * happens they switch the linter off. So every rule is handed the target and
 * decides for itself, and where the answer depends on something a migration
 * file cannot show, the rule says which part it could not see.
 *
 * The second is that there are two different dangers and conflating them
 * wastes people's time. A lock is a database problem: the migration runs and
 * the table is unavailable while it does. A rolling deploy break is an
 * application problem: the migration is instant and perfectly safe, and the
 * old copies of your code still running are now selecting a column that no
 * longer exists. Those need different fixes, so they are different severities.
 */
final class Rules
{
    /**
     * Rules whose finding is about the table rather than about the line.
     *
     * One exclusive lock is taken for the block, not one per column, so a
     * migration adding six nullable columns has one lock queue and not six.
     * Reported six times it would read as six problems, and the rule that
     * exists to be noticed becomes the rule people scroll past.
     *
     * @return string[]
     */
    public static function oncePerTable(): array
    {
        return ['lock-queue'];
    }

    /**
     * @return array<string, callable(Operation, Target): ?array{string, string, string, string}>
     *         rule id => a check returning [severity, summary, because, instead]
     */
    public static function all(): array
    {
        return [
            'add-column-with-default' => self::addColumnWithDefault(...),
            'add-not-null-column' => self::addNotNullColumn(...),
            'lock-queue' => self::lockQueue(...),
            'add-index' => self::addIndex(...),
            'add-foreign-key' => self::addForeignKey(...),
            'change-column' => self::changeColumn(...),
            'drop-column' => self::dropColumn(...),
            'rename-column' => self::renameColumn(...),
            'rename-table' => self::renameTable(...),
            'drop-table' => self::dropTable(...),
            'backfill-in-migration' => self::backfill(...),
            'raw-statement' => self::rawStatement(...),
        ];
    }

    private static function addColumnWithDefault(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::ADD_COLUMN || ! $op->isOnExistingTable() || ! $op->hasDefault()) {
            return null;
        }

        // A default the engine evaluates per row has no single value to put in
        // the catalogue, so the fast path does not apply to it and the version
        // does not save you. This is the one case where a modern target is
        // still rewritten, and the one a version check on its own gets wrong.
        $volatile = $op->hasVolatileDefault();

        if ($target->defaultOnAddIsInstant() && ! $volatile) {
            // Nothing to say. Saying it anyway is how a linter becomes noise.
            return null;
        }

        $reason = match (true) {
            $volatile => 'the default is computed for each row, so there is no single value for the catalogue to store and the table is rewritten under an exclusive lock on every version, including the ones where a constant default is instant',
            $target->isVersioned() => "on {$target->label()} this writes the default into every existing row, holding an exclusive lock for the whole rewrite",
            default => 'no database version was configured, and on older versions of every engine this rewrites the whole table under an exclusive lock',
        };

        return [
            Finding::BLOCKING,
            $volatile
                ? "adding {$op->column} with a computed default rewrites the table"
                : "adding {$op->column} with a default rewrites the table",
            $reason,
            'add the column nullable, backfill in batches outside the migration, then set the default and the not null constraint separately',
        ];
    }

    private static function addNotNullColumn(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::ADD_COLUMN || ! $op->isOnExistingTable()) {
            return null;
        }

        if (! $op->failsOnAPopulatedTable()) {
            return null;
        }

        return [
            Finding::BLOCKING,
            "adding {$op->column} as not null with no default fails on a table with rows",
            'every existing row needs a value and there is none to give them, so the statement is rejected outright on a populated table and the migration stops halfway',
            'add it nullable, backfill, then tighten the constraint in a later migration once every row has a value',
        ];
    }

    /**
     * The lock nobody waits for, and the queue that forms behind the wait.
     *
     * This is the rule for the migration that passes every other rule. Adding
     * a nullable column, or a constant default on a version where that is a
     * catalogue write, is genuinely instant: the work takes no measurable
     * time and the report is clean and right to be.
     *
     * What is not instant is getting the exclusive lock to do it in. That
     * request waits behind any transaction already touching the table, an
     * open one, a long report, a leaked connection. And it waits *in front of*
     * everything that arrives afterwards, because a lock request of this
     * strength is not overtaken by the weaker ones behind it. So the table
     * stops serving, not for the length of the migration, but for the length
     * of whatever was already running, and the migration that caused it
     * finishes in eleven milliseconds once it finally starts.
     *
     * A linter cannot see the reader. It is in another session, on another
     * machine, and it may not have started yet. What it can see is whether
     * anything has been set to stop the wait being unbounded, which is why
     * this asks the project rather than the file, and says nothing once the
     * project has answered.
     */
    private static function lockQueue(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::ADD_COLUMN || ! $op->isOnExistingTable()) {
            return null;
        }

        $statement = $target->lockTimeoutStatement();

        if ($statement === null || $target->guardsLockQueue()) {
            return null;
        }

        // Only where the add is otherwise clean. Where it rewrites the table
        // or is rejected outright, another rule has already reported it and
        // already said to do something else, and the queue is the smaller half
        // of a problem that has a larger half. Two findings on one line, one
        // of which is a footnote to the other, is how a report gets skimmed.
        if ($op->failsOnAPopulatedTable()) {
            return null;
        }

        if ($op->hasDefault() && (! $target->defaultOnAddIsInstant() || $op->hasVolatileDefault())) {
            return null;
        }

        return [
            Finding::NOTICE,
            "adding {$op->column} is instant, and the lock it needs may not be",
            'the change is a catalogue write and the lock it needs is not: the request queues behind whatever is already reading the table, and every statement arriving after it queues behind the request rather than behind the reader. One long select is then an outage for as long as that select runs, caused by a migration that finishes in milliseconds once it starts',
            "run {$statement} first so the attempt gives up rather than queueing, and retry the migration, because failing fast only helps if something tries again",
        ];
    }

    private static function addIndex(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::ADD_INDEX || ! $op->isOnExistingTable()) {
            return null;
        }

        if ($target->supportsConcurrentIndex() && ! $op->hasModifier('algorithm')) {
            return [
                Finding::BLOCKING,
                'building this index blocks writes to the table',
                "{$target->label()} holds a lock against writes for the whole build unless the index is created concurrently",
                "add ->algorithm('concurrently'), and run it outside a transaction, since Postgres refuses a concurrent build inside one",
            ];
        }

        if ($target->isMysqlFamily()) {
            return [
                Finding::NOTICE,
                'building this index takes a metadata lock',
                "{$target->label()} builds most indexes online, but the statement still waits for a metadata lock, and that lock queues behind every transaction already open on the table",
                'run it when long transactions are unlikely, and set a lock_wait_timeout so it fails fast rather than queueing behind traffic',
            ];
        }

        return null;
    }

    private static function addForeignKey(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::ADD_FOREIGN_KEY || ! $op->isOnExistingTable()) {
            return null;
        }

        return [
            Finding::BLOCKING,
            'adding a foreign key locks two tables, not one',
            'the constraint is validated against every existing row, and the referenced table is locked for the duration as well as this one, which is the part that surprises people',
            $target->isPostgres()
                ? 'add it NOT VALID first, which takes a brief lock, then VALIDATE CONSTRAINT in a second migration, which does not block reads or writes'
                : 'add it during a quiet window, or keep the relationship enforced in the application if the table is large enough that the validation scan matters',
        ];
    }

    private static function changeColumn(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::CHANGE_COLUMN || ! $op->isOnExistingTable()) {
            return null;
        }

        return [
            Finding::BLOCKING,
            "changing {$op->column} rewrites the table",
            $target->isMysqlFamily()
                ? "{$target->label()} copies the table for most type changes, and Laravel emits a full column definition on change() which loses anything it did not know about"
                : 'a type change rewrites every row under an exclusive lock, and Laravel emits a full column definition on change() which loses anything it did not know about',
            'add a new column, write to both for one deploy, backfill in batches, then read from the new one and drop the old',
        ];
    }

    private static function dropColumn(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::DROP_COLUMN || ! $op->isOnExistingTable()) {
            return null;
        }

        return [
            Finding::ROLLING,
            "dropping {$op->column} breaks the copies of your application still running",
            'the database is fine, the application is not: until every old process has stopped, code that still selects this column is being served requests and will fail on them, and a select * makes it certain',
            'stop referencing it, deploy, let the old processes drain, then drop it in a separate migration',
        ];
    }

    private static function renameColumn(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::RENAME_COLUMN || ! $op->isOnExistingTable()) {
            return null;
        }

        return [
            Finding::ROLLING,
            "renaming {$op->column} breaks old and new code at once",
            'there is no moment when both the old name and the new one exist, so whichever version of the application is not deployed yet is broken for the length of the rollout',
            'add the new column, write to both, backfill, move reads across, then drop the old one. Four deploys rather than one, and no window where anything is broken',
        ];
    }

    private static function renameTable(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::RENAME_TABLE) {
            return null;
        }

        return [
            Finding::ROLLING,
            "renaming {$op->table} breaks every running copy of your application at once",
            'the same problem as renaming a column and larger, because everything touching the table breaks rather than everything touching one field',
            'create the new table, write to both, copy across, move reads, then drop the old one',
        ];
    }

    private static function dropTable(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::DROP_TABLE) {
            return null;
        }

        return [
            Finding::ROLLING,
            "dropping {$op->table} cannot be undone by rolling the deploy back",
            'a rollback restores the code and not the rows, so this is the one migration where being wrong is permanent',
            'rename it out of the way first and drop it a release later, once nothing has referred to it for long enough to be sure',
        ];
    }

    private static function backfill(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::DATA_CHANGE) {
            return null;
        }

        return [
            Finding::NOTICE,
            'this migration changes data as well as schema',
            'a single update across a large table holds its locks until the whole statement finishes, and inside a migration transaction it holds any schema locks taken earlier for that long too',
            'move the backfill to a command or a job that works in batches, so it can be paused, resumed and watched',
        ];
    }

    private static function rawStatement(Operation $op, Target $target): ?array
    {
        if ($op->kind !== Operation::RAW_STATEMENT) {
            return null;
        }

        return [
            Finding::NOTICE,
            'raw SQL, which this cannot read',
            'the rules here work on Laravel schema calls, so anything written as a string passes through unexamined and is not covered by the rest of this report',
            'check this statement by hand, and consider squawk if it is Postgres, which lints SQL itself',
        ];
    }
}
