<div align="center">

# safe-migrations

The migration ran in eleven milliseconds on your laptop. The table had four rows.

[![CI](https://github.com/catidegla/safe-migrations/actions/workflows/ci.yml/badge.svg)](https://github.com/catidegla/safe-migrations/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json)
[![Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

</div>

---

```bash
composer require --dev catidegla/safe-migrations
php artisan migrate:lint
```

```
  1 migration(s) against PostgreSQL 16.0

  database/migrations/2026_01_01_000000_tighten_users.php
    lock   line 7  building this index blocks writes to the table
           $table->index('email');
           PostgreSQL 16.0 holds a lock against writes for the whole build unless
           the index is created concurrently
           instead: add ->algorithm('concurrently'), and run it outside a transaction
           safe-migrations-ignore: add-index

    deploy line 8  dropping legacy_flag breaks the copies of your application still running
           $table->dropColumn('legacy_flag');
           the database is fine, the application is not: until every old process has
           stopped, code that still selects this column is being served requests
           instead: stop referencing it, deploy, let the old processes drain, then drop it

  1 that will lock a table, 1 that will break a rolling deploy
```

## Almost none of this is true in general

That is the idea the whole tool is built around, and the reason a linter that does not ask which database you run is either crying wolf or missing things.

Adding a column with a default **rewrote the whole table on PostgreSQL 10** and has been **instant since 11**. Most `ALTER TABLE` work copied the table on MySQL 5.6, has been in place since 5.7, and a further set became genuinely instant in 8.0.

A rule that warns regardless is telling a team on Postgres 16 to work around a problem they stopped having seven years ago. The second time that happens, they switch the linter off. So every rule is handed the target and decides for itself:

```bash
php artisan migrate:lint            # asks your connection what it is
safe-migrations --database pgsql --version 16
```

Inside a booted application the driver comes from your connection and the version is asked of the server, because the developer will eventually be describing the database they had last year. In CI there is usually no database, so set it in config. **Without a version, every version-dependent rule assumes the oldest behaviour**, the direction that stays loud rather than the one that stays quiet about a real outage.

## Two different dangers

Conflating them wastes people's time, so they are separate:

| | |
| :--- | :--- |
| `lock` | A **database** problem. The migration runs and the table is unavailable while it does. |
| `deploy` | An **application** problem. The migration is instant and perfectly safe, and the old copies of your code still running are now selecting a column that no longer exists. |
| `note` | Worth reading. Never fails the run. |

`dropColumn` is the clearest case. Postgres does it in microseconds. Your half-deployed application breaks on every request that hits an old process, and a `select *` makes it certain.

## The instant migration that takes the table down

The rule worth reading about, because it fires on the migration that passes every other rule.

Adding a nullable column is instant. Adding a constant default on Postgres 11+ is instant. The work is a catalogue write and it is genuinely over in microseconds, so the report is clean and right to be.

Getting the exclusive lock to do it in is not instant. That request waits behind any transaction already touching the table, and **every statement arriving afterwards queues behind the request** rather than behind the reader, because a lock that strong is not overtaken by the weaker ones stacking up underneath it.

So the table stops serving for the length of whatever was already running. One long `SELECT`, one open transaction somebody left in a psql window, one leaked connection, and the migration that caused the outage completes in eleven milliseconds once it finally starts.

```
note   line 12  adding phone is instant, and the lock it needs may not be
       $table->string('phone')->nullable();
       instead: run SET lock_timeout = '3s' first so the attempt gives up
       rather than queueing, and retry the migration
```

A linter cannot see the reader. It is in another session, on another machine, and it may not have started yet. What it can see is whether anything bounds the wait. So tell it once and it stops asking:

```php
'lock_timeout' => '3s',   // 3 on MySQL, 3000 on SQL Server
```

```bash
safe-migrations --database pgsql --version 16 --lock-timeout 3s
```

One finding per table, not one per column, because a block adding six nullable columns takes one lock and not six. Nothing is reported where the add rewrites the table anyway, because `add-column-with-default` has already said so and already said what to do instead. Nothing is reported on SQLite, which takes one writer at a time and has no queue of this shape.

**`0` is not a timeout on Postgres**, it disables the timeout and waits for ever, so it reads here as unset. On SQL Server the same digit is the strictest guard there is, and reads as set.

## What it checks

Adding a column with a default, adding one `not null` with no default, an instant add whose lock can still queue, building an index, adding a foreign key, changing a column type, dropping or renaming a column or table, backfilling data inside a migration, and raw SQL it cannot read.

**Nothing inside a `Schema::create` is ever a finding.** A brand new table has no rows and no readers, so nothing in it can lock anybody out. A linter that warns there is the kind people pass `--force` to.

Every finding prints three lines: what it saw, why that matters *on your database at your version*, and what to do instead. The third is the one that decides whether anything changes; a linter that names a problem and stops has told a developer under deadline pressure to go and research it, and what they will actually do is add an ignore comment.

## Overruling it

```php
// safe-migrations-ignore: drop-column
$table->dropColumn('legacy');
```

Naming a rule turns off that rule on that line. A bare `safe-migrations-ignore` turns off all of them. A linter that cannot be overruled locally is a linter that gets removed globally.

## In CI

```yaml
- uses: catidegla/safe-migrations@v0.3.0
  with:
    database: pgsql
    version: '16'
    lock-timeout: '3s'   # omit it and the lock-queue rule will ask
```

On a pull request it checks only the migrations that pull request added, because
the ones already merged are already running and warning about them on every
build is how the report stops being read. Findings arrive as annotations on the
diff itself.

Or call the binary, which needs no `composer install` and no booted app:

```bash
vendor/bin/safe-migrations --database pgsql --version 16 --since origin/main --github
```

```yaml
- run: vendor/bin/safe-migrations --database pgsql --version 16 --since origin/main --github
```

`--since` checks only the migrations a pull request **added**, because the ones already merged are already running and warning about them on every build is how the report stops being read. `--github` emits annotations so findings land on the diff itself.

Exit codes: `0` nothing that locks or breaks a deploy, `1` something that does, `2` bad arguments. Notices never fail a run, since a check that fails on things it admits are usually fine is one somebody passes `--force` to for the rest of its life.

The standalone binary needs no `composer install` and no booted app, so it runs on a fresh checkout.

## It reads, it never runs

A migration is arbitrary PHP, and a linter that executes what it is inspecting can be made to do anything by the file it is inspecting. This reads tokens, via `token_get_all`, which ships with PHP. That is also why the package has no dependencies.

What it gives up is a real syntax tree. It looks for the shapes Laravel migrations actually take, and where it cannot tell, it says so rather than guessing: raw SQL is reported as **unread** rather than passed over silently.

## What it cannot do

It cannot know how big your table is. Everything here is about a table with enough rows for a lock to matter, and on a table with four rows none of it does.

It cannot read raw SQL. If you are on Postgres and write a lot of it, [squawk](https://github.com/sbdchd/squawk) lints SQL itself and is very good at it. This covers the ground squawk structurally cannot: a `Schema::table()` call is not SQL yet.

It cannot see a migration's effect on code it has not read. The rolling deploy rules assume you deploy without stopping; if you take the site down to migrate, disable them in config and keep the rest.

## Testing

```bash
composer install
vendor/bin/phpunit    # 24 tests
```

Most of them are really one test: that the answer changes with the database and the version. Adding a column with a default is asserted against PostgreSQL 10 and 11, MySQL 5.7 and 8.0, and MariaDB 10.2 and 10.3, because those are the versions where the behaviour actually changed.

## Requirements

PHP 8.2. Laravel 12 or 13 for the artisan command; the binary works without either.

## License

MIT.
