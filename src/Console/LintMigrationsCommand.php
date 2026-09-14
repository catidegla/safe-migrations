<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations\Console;

use Catidegla\SafeMigrations\Linter;
use Catidegla\SafeMigrations\Reporter;
use Catidegla\SafeMigrations\Target;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The artisan front end.
 *
 * Exists mostly to answer one question the standalone command has to be told:
 * which database, at which version. Inside a booted application that is
 * knowable, and asking the server beats asking the developer, because the
 * developer will eventually be describing the database they had last year.
 */
class LintMigrationsCommand extends Command
{
    protected $signature = 'migrate:lint
        {--path= : Where the migrations are, defaulting to the configured path}
        {--database= : Override the driver rather than reading the connection}
        {--db-version= : Override the server version rather than asking it}
        {--lock-timeout= : What you set before the DDL runs, if anything}
        {--since= : Only migrations added since this git ref}
        {--strict : Fail on notices as well}
        {--json : Machine readable output}
        {--github : GitHub annotations, so findings land on the diff}';

    protected $description = 'Find migrations that will lock a table or break a rolling deploy';

    public function handle(): int
    {
        $path = $this->option('path') ?? config('safe-migrations.path', 'database/migrations');
        $path = $this->absolute($path);

        if (! is_dir($path)) {
            $this->error("{$path} is not a directory.");

            return self::INVALID;
        }

        $files = [];
        foreach (glob(rtrim($path, '/' . DIRECTORY_SEPARATOR) . '/*.php') ?: [] as $file) {
            $files[$this->relative($file)] = (string) file_get_contents($file);
        }

        if ($this->option('since')) {
            $files = $this->onlyAddedSince($files, (string) $this->option('since'));
        }

        if ($files === []) {
            $this->line('');
            $this->line('  No migrations to check.');
            $this->line('');

            return self::SUCCESS;
        }

        $target = $this->resolveTarget();
        $findings = (new Linter($target, (array) config('safe-migrations.disabled', [])))->lint($files);

        $reporter = new Reporter(colour: ! $this->option('json') && ! $this->option('github') && $this->output->isDecorated());

        if ($this->option('json')) {
            $this->output->write($reporter->json($findings, $target, count($files)));
        } elseif ($this->option('github')) {
            $this->output->write($reporter->annotations($findings));
        } else {
            $this->output->write($reporter->terminal($findings, $target, count($files)));
        }

        $summary = Linter::summarise($findings);

        if (! $summary['clean']) {
            return self::FAILURE;
        }

        return $this->option('strict') && $summary['notice'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ask the server what it is, and fall back rather than guess.
     *
     * The flags win, then the config, then the live connection. A version
     * nobody could establish stays empty, and every version dependent rule
     * then assumes the oldest behaviour: the noisy direction rather than the
     * quiet one, because a rule that stays quiet on no evidence is the one
     * that lets an outage through.
     */
    private function resolveTarget(): Target
    {
        $driver = $this->option('database')
            ?? config('safe-migrations.database')
            ?? config('database.default');

        if (! $this->option('database') && ! config('safe-migrations.database')) {
            $driver = config("database.connections.{$driver}.driver", $driver);
        }

        $version = $this->option('db-version') ?? config('safe-migrations.version');

        if ($version === null) {
            try {
                $version = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (Throwable) {
                // No database, which is the normal state of a CI job. Saying
                // so in the report is better than inventing a version.
                $version = null;
            }
        }

        // Not asked of the server on purpose. A session variable read here
        // says what this connection has, not what the one running the
        // migration will have, and those are different processes.
        $lockTimeout = $this->option('lock-timeout') ?? config('safe-migrations.lock_timeout');

        return Target::of(
            (string) $driver,
            (string) ($version ?? ''),
            $lockTimeout === null ? null : (string) $lockTimeout,
        );
    }

    /** @param array<string, string> $files */
    private function onlyAddedSince(array $files, string $ref): array
    {
        $changed = [];
        exec('git diff --name-only --diff-filter=A ' . escapeshellarg($ref) . ' 2>&1', $changed, $status);

        if ($status !== 0) {
            $this->warn("Could not ask git what changed since {$ref}, so everything was checked.");

            return $files;
        }

        $forward = fn (string $p): string => strtr($p, DIRECTORY_SEPARATOR, '/');
        $keep = array_flip(array_map(fn ($f) => $forward(trim($f)), $changed));

        return array_filter($files, fn ($f) => isset($keep[$forward($f)]), ARRAY_FILTER_USE_KEY);
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return $path;
        }

        return function_exists('base_path') ? base_path($path) : $path;
    }

    private function relative(string $path): string
    {
        $root = function_exists('base_path') ? base_path() : getcwd();
        $forward = fn (string $p): string => strtr((string) $p, DIRECTORY_SEPARATOR, '/');

        $path = $forward($path);
        $root = rtrim($forward((string) $root), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
