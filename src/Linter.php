<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

/**
 * Running the rules over a set of migration files.
 *
 * The only judgement in here is about which migrations to read. Everything
 * else is delegated, so that adding a rule never means editing this.
 */
final class Linter
{
    /** @param string[] $disabled rule ids the project has decided not to run */
    public function __construct(
        private readonly Target $target,
        private readonly array $disabled = [],
        private readonly ?Parser $parser = null,
    ) {}

    /**
     * @param array<string, string> $files path => contents
     * @return Finding[]
     */
    public function lint(array $files): array
    {
        $parser = $this->parser ?? new Parser();
        $rules = Rules::all();
        $oncePerTable = Rules::oncePerTable();
        $findings = [];

        foreach ($files as $path => $code) {
            $operations = $parser->expand($parser->parse($code));
            $reported = [];

            foreach ($operations as $operation) {
                foreach ($rules as $id => $check) {
                    if (in_array($id, $this->disabled, true)) {
                        continue;
                    }

                    // Some findings are about the table and would otherwise
                    // repeat once per column in the same block. Which ones is
                    // Rules' business, so that adding one still never means
                    // editing this file.
                    $key = "{$id}\0{$operation->table}";

                    if (isset($reported[$key])) {
                        continue;
                    }

                    // A line the author marked is skipped without argument.
                    // A linter that cannot be overruled locally is a linter
                    // that gets removed globally.
                    if ($this->isIgnored($code, $operation->line, $id)) {
                        continue;
                    }

                    $result = $check($operation, $this->target);

                    if ($result === null) {
                        continue;
                    }

                    if (in_array($id, $oncePerTable, true)) {
                        $reported[$key] = true;
                    }

                    [$severity, $summary, $because, $instead] = $result;

                    $findings[] = new Finding(
                        $severity, $id, $path, $operation->line,
                        $summary, $because, $instead, $operation->source,
                    );
                }
            }
        }

        usort($findings, function (Finding $a, Finding $b): int {
            $rank = [Finding::BLOCKING => 0, Finding::ROLLING => 1, Finding::NOTICE => 2];

            return [$rank[$a->severity], $a->file, $a->line] <=> [$rank[$b->severity], $b->file, $b->line];
        });

        return $findings;
    }

    /**
     * An ignore comment on the line itself or the one above it.
     *
     * Both spellings are accepted because people write both, and a bare
     * safe-migrations-ignore turns off every rule on that line while naming
     * one turns off only that rule, which is the version worth encouraging.
     */
    private function isIgnored(string $code, int $line, string $rule): bool
    {
        $lines = explode("\n", $code);

        foreach ([$line - 1, $line - 2] as $index) {
            $text = $lines[$index] ?? '';

            if (preg_match('/safe-migrations-ignore(?::\s*([a-z0-9,\- ]+))?/i', $text, $matches) !== 1) {
                continue;
            }

            if (! isset($matches[1]) || trim($matches[1]) === '') {
                return true;
            }

            $named = array_map('trim', explode(',', $matches[1]));

            if (in_array($rule, $named, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Finding[] $findings
     * @return array{blocking: int, rolling: int, notice: int, total: int, clean: bool}
     */
    public static function summarise(array $findings): array
    {
        $count = fn (string $severity) => count(array_filter($findings, fn (Finding $f) => $f->severity === $severity));

        $blocking = $count(Finding::BLOCKING);
        $rolling = $count(Finding::ROLLING);

        return [
            'blocking' => $blocking,
            'rolling' => $rolling,
            'notice' => $count(Finding::NOTICE),
            'total' => count($findings),
            // Notices never fail a run. They exist to be read, and a check
            // that fails on things it admits are usually fine is a check
            // somebody will pass --force to for the rest of its life.
            'clean' => $blocking === 0 && $rolling === 0,
        ];
    }
}
