<?php

namespace Warrant\Console;

use Illuminate\Console\Command;
use Warrant\DSL\Formatting\FileFormatter;
use Warrant\DSL\Formatting\RuleTextFiles;
use Warrant\DSL\Formatting\UnifiedDiff;

/**
 * `php artisan warrant:format`: formats the rule text in `.warrant` files and in
 * PHP files, as `vendor/bin/warrant-format` does, from the application's root.
 *
 * Each path is a file, a directory or a glob pattern, read as
 * {@see RuleTextFiles} reads them; with none, the whole application. The exit
 * code is 0 when everything is formatted, 1 when `--check` or `--diff` found a
 * file to change, and 2 when rule text did not parse, unless
 * `--ignore-unparsable` is given, or when a path matched nothing.
 */
final class FormatRuleTextCommand extends Command
{
    protected $signature = 'warrant:format
        {paths?* : Files, directories or glob patterns; the whole application when none is given}
        {--check : Write nothing; list the files that would change}
        {--diff : Write nothing; show what would change in each file}
        {--ignore-unparsable : Report rule text that does not parse, but do not fail}
        {--stdin-filepath= : Format standard input as the file at this path would be, and print it}';

    protected $description = 'Format the Warrant rule text in .warrant and PHP files';

    public function handle(): int
    {
        $formatter = new FileFormatter($this->laravel->basePath());
        $ignoreUnparsable = (bool) $this->option('ignore-unparsable');
        $stdinPath = $this->option('stdin-filepath');

        if (is_string($stdinPath) && $stdinPath !== '') {
            $this->output->write($formatter->formatted($stdinPath, (string) file_get_contents('php://stdin')));

            foreach ($formatter->problems() as $problem) {
                $this->output->getErrorStyle()->writeln("{$stdinPath}:{$problem['line']}: {$problem['message']}");
            }

            return $formatter->problems() !== [] && ! $ignoreUnparsable ? 2 : self::SUCCESS;
        }

        $check = (bool) $this->option('check');
        $diff = (bool) $this->option('diff');
        $base = $this->laravel->basePath();
        $found = RuleTextFiles::matching(array_values((array) $this->argument('paths')), $base);
        $failed = false;

        foreach ($found->unmatched as $argument) {
            $this->components->error("No file, directory or pattern matches \"{$argument}\".");
            $failed = true;
        }

        foreach ($found->unsupported as $argument) {
            $this->components->error("{$argument} is not a .warrant or .php file.");
            $failed = true;
        }

        $changed = $unchanged = $skipped = 0;

        foreach ($found->files as $file) {
            $shown = RuleTextFiles::relativeTo($file, $base);
            $text = (string) file_get_contents($file);
            $formatted = $formatter->formatted($file, $text);

            foreach ($formatter->problems() as $problem) {
                $this->line("  <fg=yellow>Skipped</>    {$shown}:{$problem['line']}  {$problem['message']}");
            }

            if ($formatter->problems() !== []) {
                $skipped++;
                $failed = $failed || ! $ignoreUnparsable;
            }

            if ($formatted === $text) {
                $unchanged += $formatter->problems() === [] ? 1 : 0;

                continue;
            }

            $changed++;

            if ($check || $diff) {
                $this->line("  <fg=cyan>Would format</>  {$shown}");
            } else {
                file_put_contents($file, $formatted);
                $this->line("  <info>Formatted</>  {$shown}");
            }

            if ($diff) {
                $this->output->write(UnifiedDiff::of($text, $formatted, $shown), false, $this->output::OUTPUT_RAW);
            }
        }

        $files = static fn (int $count): string => $count === 1 ? '1 file' : "{$count} files";
        $this->newLine();
        $this->line(sprintf(
            '  %s %s, %d skipped, %d already formatted.',
            $files($changed),
            $check || $diff ? 'would be formatted' : 'formatted',
            $skipped,
            $unchanged,
        ));

        return $failed ? 2 : (($check || $diff) && $changed > 0 ? 1 : self::SUCCESS);
    }
}
