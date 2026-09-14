<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations\Tests;

use Catidegla\SafeMigrations\Finding;
use Catidegla\SafeMigrations\Linter;
use Catidegla\SafeMigrations\Target;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules, and the thing they are built around: almost none of this is true
 * in general, so nearly every test here is really a test that the answer
 * changes with the database and the version.
 */
final class RulesTest extends TestCase
{
    /** @return Finding[] */
    private function lint(string $body, string $driver = 'pgsql', string $version = '16', array $disabled = []): array
    {
        $code = "<?php\nreturn new class extends Migration {\n    public function up(): void\n    {\n{$body}\n    }\n};\n";

        return (new Linter(Target::of($driver, $version), $disabled))->lint(['migration.php' => $code]);
    }

    private function rules(array $findings): array
    {
        return array_values(array_unique(array_map(fn (Finding $f) => $f->rule, $findings)));
    }

    private function firstOf(array $findings, string $rule): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->rule === $rule) {
                return $finding;
            }
        }

        return null;
    }

    private const ALTER = <<<'PHP'
        Schema::table('users', function (Blueprint $table) {
%s
        });
PHP;

    private function onExisting(string $line, string $driver = 'pgsql', string $version = '16'): array
    {
        return $this->lint(sprintf(self::ALTER, $line), $driver, $version);
    }

    /* ------------------------------------------------------ the new table case */

    #[Test]
    public function nothing_in_a_create_table_is_ever_a_finding(): void
    {
        // The most important quieting rule in the tool. A brand new table has
        // no rows and no readers, so nothing in it can lock anybody out. A
        // linter that warns here is the kind people pass --force to.
        $findings = $this->lint(<<<'PHP'
                Schema::create('posts', function (Blueprint $table) {
                    $table->id();
                    $table->string('title');
                    $table->string('slug')->default('x');
                    $table->index('slug');
                    $table->foreignId('author_id')->constrained();
                });
        PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function the_create_context_does_not_leak_into_the_next_block(): void
    {
        // The parser bug this replaced: a brace counter that never came back
        // down treated every operation after the first create as safe.
        $findings = $this->lint(<<<'PHP'
                Schema::create('posts', function (Blueprint $table) {
                    $table->id();
                });

                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('legacy');
                });
        PHP);

        $this->assertSame(['drop-column'], $this->rules($findings));
    }

    /* ------------------------------------------------ version dependent rules */

    #[Test]
    public function adding_a_column_with_a_default_depends_entirely_on_the_version(): void
    {
        $line = "            \$table->string('country')->default('BJ');";

        $this->assertNotNull($this->firstOf($this->onExisting($line, 'pgsql', '10'), 'add-column-with-default'));
        $this->assertNull($this->firstOf($this->onExisting($line, 'pgsql', '11'), 'add-column-with-default'));
        $this->assertNull($this->firstOf($this->onExisting($line, 'pgsql', '16'), 'add-column-with-default'));

        $this->assertNotNull($this->firstOf($this->onExisting($line, 'mysql', '5.7'), 'add-column-with-default'));
        $this->assertNull($this->firstOf($this->onExisting($line, 'mysql', '8.0'), 'add-column-with-default'));

        $this->assertNotNull($this->firstOf($this->onExisting($line, 'mariadb', '10.2'), 'add-column-with-default'));
        $this->assertNull($this->firstOf($this->onExisting($line, 'mariadb', '10.3'), 'add-column-with-default'));
    }

    #[Test]
    public function a_computed_default_is_rewritten_on_every_version(): void
    {
        // The exception that makes the version rule above worth stating
        // carefully. The fast path works by storing one value in the
        // catalogue, so it only exists while there is one value. now() and
        // gen_random_uuid() have to be evaluated per row, which means the
        // table is rewritten on 16 exactly as it was on 10.
        //
        // Missing this is worse than the false positive the version check was
        // added to avoid: a warning somebody dismisses costs a glance, and a
        // rewrite nobody warned about costs an exclusive lock in production.
        foreach ([
            "            \$table->timestamp('seen_at')->default(DB::raw('now()'));",
            "            \$table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));",
            "            \$table->timestamp('created')->default(DB::raw('CURRENT_TIMESTAMP'));",
        ] as $line) {
            foreach (['16', '11', '10'] as $version) {
                $this->assertNotNull(
                    $this->firstOf($this->onExisting($line, 'pgsql', $version), 'add-column-with-default'),
                    "a computed default should be reported on PostgreSQL {$version}: {$line}",
                );
            }
        }

        $finding = $this->firstOf(
            $this->onExisting("            \$table->uuid('public_id')->default(DB::raw('gen_random_uuid()'));", 'pgsql', '16'),
            'add-column-with-default',
        );

        // The report has to say which of the two reasons it is, or the reader
        // goes and checks their version for nothing.
        $this->assertStringContainsString('computed default', $finding->summary);
        $this->assertStringContainsString('for each row', $finding->because);
    }

    #[Test]
    public function a_constant_default_is_not_mistaken_for_a_computed_one(): void
    {
        // The list of volatile functions is matched against the source line,
        // so the thing to prove is that it does not catch a constant that
        // merely mentions one. Getting this wrong would reintroduce the false
        // positive on the common case in order to fix the rare one.
        foreach ([
            "            \$table->string('label')->default('now');",
            "            \$table->string('note')->default(DB::raw(\"'BJ'\"));",
            "            \$table->integer('attempts')->default(0);",
        ] as $line) {
            $this->assertNull(
                $this->firstOf($this->onExisting($line, 'pgsql', '16'), 'add-column-with-default'),
                "a constant default should stay quiet on PostgreSQL 16: {$line}",
            );
        }
    }

    #[Test]
    public function an_unknown_version_assumes_the_old_behaviour(): void
    {
        // The noisy direction on no evidence, because a rule that stays quiet
        // when it does not know is the one that lets an outage through.
        $finding = $this->firstOf(
            $this->onExisting("            \$table->string('c')->default('x');", 'pgsql', ''),
            'add-column-with-default',
        );

        $this->assertNotNull($finding);
        $this->assertStringContainsString('no database version was configured', $finding->because);
    }

    #[Test]
    public function an_index_blocks_on_postgres_and_is_a_notice_on_mysql(): void
    {
        $line = "            \$table->index('email');";

        $postgres = $this->firstOf($this->onExisting($line, 'pgsql', '16'), 'add-index');
        $mysql = $this->firstOf($this->onExisting($line, 'mysql', '8.0'), 'add-index');

        $this->assertSame(Finding::BLOCKING, $postgres->severity);
        $this->assertStringContainsString('concurrently', $postgres->instead);

        $this->assertSame(Finding::NOTICE, $mysql->severity);
        $this->assertStringContainsString('metadata lock', $mysql->because);
    }

    #[Test]
    public function a_concurrent_index_on_postgres_is_not_a_finding(): void
    {
        $findings = $this->onExisting("            \$table->index('email')->algorithm('concurrently');", 'pgsql', '16');

        $this->assertNull($this->firstOf($findings, 'add-index'));
    }

    /* ---------------------------------------------------------- locking rules */

    #[Test]
    public function a_not_null_column_with_no_default_is_flagged_on_every_database(): void
    {
        foreach ([['pgsql', '16'], ['mysql', '8.0'], ['sqlite', '3']] as [$driver, $version]) {
            $finding = $this->firstOf($this->onExisting("            \$table->string('handle');", $driver, $version), 'add-not-null-column');

            $this->assertNotNull($finding, "{$driver} {$version}");
            $this->assertSame(Finding::BLOCKING, $finding->severity);
        }
    }

    #[Test]
    public function nullable_or_defaulted_columns_are_not_flagged(): void
    {
        $this->assertNull($this->firstOf($this->onExisting("            \$table->string('a')->nullable();"), 'add-not-null-column'));
        $this->assertNull($this->firstOf($this->onExisting("            \$table->string('a')->default('x');"), 'add-not-null-column'));
    }

    #[Test]
    public function columns_that_bring_their_own_nullability_are_not_flagged(): void
    {
        foreach (['softDeletes', 'rememberToken', 'nullableMorphs'] as $method) {
            $this->assertNull(
                $this->firstOf($this->onExisting("            \$table->{$method}();"), 'add-not-null-column'),
                $method,
            );
        }
    }

    #[Test]
    public function a_foreign_key_is_reported_as_locking_two_tables(): void
    {
        $finding = $this->firstOf($this->onExisting("            \$table->foreign('team_id')->references('id')->on('teams');"), 'add-foreign-key');

        $this->assertSame(Finding::BLOCKING, $finding->severity);
        $this->assertStringContainsString('referenced table is locked', $finding->because);
    }

    #[Test]
    public function constrained_is_reported_as_a_foreign_key_as_well_as_a_column(): void
    {
        // Reported as both, because a reader told only about the column will
        // not think about the lock on the table it points at.
        $findings = $this->onExisting("            \$table->foreignId('team_id')->constrained();");

        $this->assertContains('add-foreign-key', $this->rules($findings));
    }

    #[Test]
    public function changing_a_column_is_a_rewrite(): void
    {
        $finding = $this->firstOf($this->onExisting("            \$table->text('bio')->change();"), 'change-column');

        $this->assertSame(Finding::BLOCKING, $finding->severity);
        $this->assertStringContainsString('loses anything it did not know about', $finding->because);
    }

    /* --------------------------------------------------------- rolling deploy */

    #[Test]
    public function dropping_and_renaming_are_deploy_problems_not_lock_problems(): void
    {
        // Different danger, different fix, so a different severity. The
        // database is perfectly happy with both of these.
        foreach ([
            ["            \$table->dropColumn('legacy');", 'drop-column'],
            ["            \$table->renameColumn('a', 'b');", 'rename-column'],
        ] as [$line, $rule]) {
            $finding = $this->firstOf($this->onExisting($line), $rule);

            $this->assertSame(Finding::ROLLING, $finding->severity, $rule);
        }
    }

    #[Test]
    public function dropping_a_table_says_that_a_rollback_will_not_bring_it_back(): void
    {
        $finding = $this->firstOf($this->lint("        Schema::dropIfExists('old_things');"), 'drop-table');

        $this->assertSame(Finding::ROLLING, $finding->severity);
        $this->assertStringContainsString('restores the code and not the rows', $finding->because);
    }

    /* ---------------------------------------------------------------- notices */

    #[Test]
    public function a_backfill_inside_a_migration_is_a_notice(): void
    {
        $finding = $this->firstOf($this->lint("        DB::table('users')->update(['x' => 1]);"), 'backfill-in-migration');

        $this->assertSame(Finding::NOTICE, $finding->severity);
    }

    #[Test]
    public function raw_sql_is_declared_unread_rather_than_passed_over_silently(): void
    {
        $finding = $this->firstOf($this->lint("        DB::statement('ALTER TABLE users DROP COLUMN x');"), 'raw-statement');

        $this->assertNotNull($finding);
        $this->assertStringContainsString('passes through unexamined', $finding->because);
    }

    /* ------------------------------------------------------------- the summary */

    #[Test]
    public function notices_alone_are_a_clean_run(): void
    {
        // A check that fails on things it admits are usually fine is a check
        // somebody passes --force to for the rest of its life.
        $findings = $this->lint("        DB::table('users')->update(['x' => 1]);");
        $summary = Linter::summarise($findings);

        $this->assertSame(1, $summary['notice']);
        $this->assertTrue($summary['clean']);
    }

    #[Test]
    public function findings_are_ordered_worst_first(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('legacy');
                    $table->string('handle');
                });

                DB::table('users')->update(['x' => 1]);
        PHP);

        $severities = array_map(fn (Finding $f) => $f->severity, $findings);

        $this->assertSame([Finding::BLOCKING, Finding::ROLLING, Finding::NOTICE], $severities);
    }

    /* ------------------------------------------------------------- overriding */

    #[Test]
    public function a_line_comment_turns_off_one_rule_on_one_line(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    // safe-migrations-ignore: drop-column
                    $table->dropColumn('legacy');
                    $table->dropColumn('other');
                });
        PHP);

        // The second drop still reports. An ignore is about a line, not a
        // file, and asserting on the column rather than the line number keeps
        // this test about that rather than about heredoc indentation.
        $this->assertCount(1, $findings);
        $this->assertStringContainsString("dropColumn('other')", $findings[0]->source);
    }

    #[Test]
    public function a_bare_ignore_turns_off_every_rule_on_that_line(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    $table->foreignId('team_id')->constrained(); // safe-migrations-ignore
                });
        PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function naming_a_different_rule_does_not_silence_this_one(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    // safe-migrations-ignore: add-index
                    $table->dropColumn('legacy');
                });
        PHP);

        $this->assertSame(['drop-column'], $this->rules($findings));
    }

    #[Test]
    public function a_rule_disabled_in_config_never_runs(): void
    {
        $findings = $this->lint(
            "        Schema::table('users', function (Blueprint \$table) {\n            \$table->dropColumn('legacy');\n        });",
            'pgsql', '16', ['drop-column'],
        );

        $this->assertSame([], $findings);
    }

    /* --------------------------------------------------------- the lock queue */

    #[Test]
    public function an_instant_column_add_still_reports_the_lock_queue(): void
    {
        // The rule for the migration that passes every other rule. Nothing
        // here is slow; the wait for the lock is, and everything arriving
        // behind that wait is stuck for the length of it.
        $finding = $this->firstOf($this->onExisting("            \$table->string('phone')->nullable();"), 'lock-queue');

        $this->assertNotNull($finding);
        $this->assertSame(Finding::NOTICE, $finding->severity);
        $this->assertStringContainsString("SET lock_timeout = '3s'", $finding->instead);
    }

    #[Test]
    public function a_constant_default_on_a_modern_version_is_instant_and_still_queues(): void
    {
        $this->assertNotNull($this->firstOf($this->onExisting("            \$table->string('a')->default('x');", 'pgsql', '16'), 'lock-queue'));
    }

    #[Test]
    public function the_queue_is_not_mentioned_where_the_table_is_rewritten_anyway(): void
    {
        // add-column-with-default has already reported it and already said to
        // do something else. A footnote underneath a blocking finding is how a
        // report gets skimmed.
        $rules = $this->rules($this->onExisting("            \$table->string('a')->default('x');", 'pgsql', '10'));

        $this->assertContains('add-column-with-default', $rules);
        $this->assertNotContains('lock-queue', $rules);
    }

    #[Test]
    public function a_volatile_default_is_a_rewrite_on_every_version_so_the_queue_stays_quiet(): void
    {
        $rules = $this->rules($this->onExisting("            \$table->timestamp('seen_at')->default(DB::raw('now()'));", 'pgsql', '16'));

        $this->assertContains('add-column-with-default', $rules);
        $this->assertNotContains('lock-queue', $rules);
    }

    #[Test]
    public function a_not_null_column_with_no_default_is_reported_once_and_not_twice(): void
    {
        $rules = $this->rules($this->onExisting("            \$table->string('a');"));

        $this->assertContains('add-not-null-column', $rules);
        $this->assertNotContains('lock-queue', $rules);
    }

    #[Test]
    public function nothing_in_a_create_table_reaches_the_queue_rule(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::create('posts', function (Blueprint $table) {
                    $table->string('title')->nullable();
                });
        PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function one_lock_is_taken_for_the_block_so_one_finding_is_reported(): void
    {
        // Six nullable columns are one ALTER and one queue. Six notices would
        // read as six problems and teach people to skim the section.
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    $table->string('a')->nullable();
                    $table->string('b')->nullable();
                    $table->string('c')->nullable();
                });
        PHP);

        $this->assertCount(1, array_filter($findings, fn (Finding $f) => $f->rule === 'lock-queue'));
    }

    #[Test]
    public function two_tables_in_one_migration_each_get_their_own(): void
    {
        $findings = $this->lint(<<<'PHP'
                Schema::table('users', function (Blueprint $table) {
                    $table->string('a')->nullable();
                });
                Schema::table('posts', function (Blueprint $table) {
                    $table->string('b')->nullable();
                });
        PHP);

        $this->assertCount(2, array_filter($findings, fn (Finding $f) => $f->rule === 'lock-queue'));
    }

    #[Test]
    public function a_project_that_sets_a_timeout_is_not_asked_again(): void
    {
        $code = "<?php\nSchema::table('users', function (Blueprint \$table) {\n    \$table->string('a')->nullable();\n});\n";

        $silent = (new Linter(Target::of('pgsql', '16', '3s')))->lint(['m.php' => $code]);

        $this->assertSame([], $this->rules($silent));
    }

    #[Test]
    public function zero_means_no_timeout_on_postgres_and_the_strictest_one_on_sql_server(): void
    {
        // Same digit, inverse meaning. Postgres reads 0 as "wait for ever",
        // which is the state the rule exists to warn about; SQL Server reads
        // it as "do not wait at all", which is the strongest guard there is.
        $this->assertFalse(Target::of('pgsql', '16', '0')->guardsLockQueue());
        $this->assertTrue(Target::of('sqlsrv', '16', '0')->guardsLockQueue());
        $this->assertFalse(Target::of('pgsql', '16', '')->guardsLockQueue());
        $this->assertTrue(Target::of('pgsql', '16', '3s')->guardsLockQueue());
    }

    #[Test]
    public function each_engine_is_told_to_set_its_own_setting(): void
    {
        // Seconds on MySQL, milliseconds on Postgres. Getting that backwards
        // is three orders of magnitude in the direction of no timeout at all.
        $mysql = $this->firstOf($this->onExisting("            \$table->string('a')->nullable();", 'mysql', '8.0'), 'lock-queue');
        $this->assertStringContainsString('SET SESSION lock_wait_timeout = 3', $mysql->instead);

        $sqlsrv = $this->firstOf($this->onExisting("            \$table->string('a')->nullable();", 'sqlsrv', '16'), 'lock-queue');
        $this->assertStringContainsString('SET LOCK_TIMEOUT 3000', $sqlsrv->instead);
    }

    #[Test]
    public function sqlite_takes_one_writer_at_a_time_so_there_is_no_queue_to_bound(): void
    {
        $this->assertNull($this->firstOf($this->onExisting("            \$table->string('a')->nullable();", 'sqlite', '3'), 'lock-queue'));
    }

    /* ------------------------------------------------------------- the target */

    #[Test]
    public function version_strings_from_real_servers_parse(): void
    {
        $this->assertTrue(Target::of('pgsql', '16.2 (Debian 16.2-1)')->atLeast(16));
        $this->assertTrue(Target::of('mysql', '8.0.36-0ubuntu0.22.04.1')->atLeast(8));
        $this->assertTrue(Target::of('mariadb', '10.11.6-MariaDB')->atLeast(10, 11));
        $this->assertFalse(Target::of('pgsql', 'unknown')->isVersioned());
    }

    #[Test]
    public function driver_aliases_all_land_on_one_name(): void
    {
        foreach (['postgres', 'postgresql', 'pgsql'] as $alias) {
            $this->assertTrue(Target::of($alias, '16')->isPostgres(), $alias);
        }

        foreach (['sqlsrv', 'mssql', 'sqlserver'] as $alias) {
            $this->assertSame(Target::SQLSERVER, Target::of($alias)->driver, $alias);
        }
    }
}
