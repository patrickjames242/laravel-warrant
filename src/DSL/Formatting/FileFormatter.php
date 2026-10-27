<?php

namespace Warrant\DSL\Formatting;

use Warrant\DSL\Fragments\Fragment;
use Warrant\DSL\Fragments\FragmentExtractor;
use Warrant\PhpSource\RuleProviderClasses;

/**
 * Formats the rule text in a file: all of a `.warrant` file, and each PHP string
 * {@see FragmentExtractor} reads as rule text.
 *
 * A heredoc or nowdoc is formatted in full, its lines indented four spaces past
 * its closing marker. A quoted string written over several lines is formatted
 * in full too: it keeps the space before its first line of rule text and after
 * its last, and every line takes the indentation its first line of rule text
 * has. A quoted string on one line is formatted on one line, and only when it
 * holds no comment. Either is escaped again for its quotes. A heredoc that
 * interpolates is left as it is, and so is text that does not parse or that
 * could not be formatted without changing it.
 */
final class FileFormatter
{
    private readonly RuleProviderClasses $classes;

    /** @var list<array{line: int, message: string}> Why text was left as it is, from the last call. */
    private array $problems = [];

    /**
     * @param string|null $projectRoot The project the files are in, whose
     *   classes say which `rules()` methods return rule text; with none, only a
     *   class of this library's is known to.
     */
    public function __construct(?string $projectRoot = null, private readonly WarrantFormatter $formatter = new WarrantFormatter)
    {
        $this->classes = RuleProviderClasses::ofProject($projectRoot);
    }

    /**
     * The edits that format the rule text in a file, in file order: the byte
     * offset each starts at, where it ends, and the text that replaces it.
     *
     * @param string $path The file's path, whose extension says what it holds.
     * @param string $text The file's contents.
     * @return list<array{0: int, 1: int, 2: string}>
     */
    public function edits(string $path, string $text): array
    {
        $this->problems = [];
        $edits = [];
        $isWarrantFile = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'warrant';

        // Formatting reads no schemas, so the extractor is told only which classes provide rules.
        $classes = $isWarrantFile ? $this->classes : $this->classes->knowing($path, $text);
        $extractor = new FragmentExtractor(providesRules: $classes->providesRules(...));

        foreach ($extractor->extract($path, $text) as $fragment) {
            try {
                $edit = $isWarrantFile
                    ? [0, strlen($text), $this->formatter->format($text)]
                    : $this->phpStringEdit($text, $fragment);
            } catch (RuleTextFormattingException $error) {
                $this->problems[] = ['line' => substr_count(substr($text, 0, $fragment->fileStart), "\n") + 1, 'message' => $error->getMessage()];

                continue;
            }

            if ($edit !== null && substr($text, $edit[0], $edit[1] - $edit[0]) !== $edit[2]) {
                $edits[] = $edit;
            }
        }

        return $edits;
    }

    /**
     * The file's contents with the rule text in it formatted.
     *
     * @param string $path The file's path.
     * @param string $text The file's contents.
     */
    public function formatted(string $path, string $text): string
    {
        foreach (array_reverse($this->edits($path, $text)) as [$start, $end, $replacement]) {
            $text = substr($text, 0, $start).$replacement.substr($text, $end);
        }

        return $text;
    }

    /**
     * Why rule text was left as it is in the last file formatted: the line the
     * text starts on, and the reason.
     *
     * @return list<array{line: int, message: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The edit that formats the PHP string $fragment was read from, or null to
     * leave it as it is.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function phpStringEdit(string $file, Fragment $fragment): ?array
    {
        $quote = $file[$fragment->fileStart - 1] ?? '';

        if (($quote === "'" || $quote === '"') && ($file[$fragment->fileEnd] ?? '') === $quote) {
            if (str_contains($fragment->text, "\n")) {
                return [$fragment->fileStart, $fragment->fileEnd, self::escapedFor($quote, $this->overLines($file, $fragment))];
            }

            if (str_contains($fragment->text, '#')) {
                return null;
            }

            return [$fragment->fileStart, $fragment->fileEnd, self::escapedFor($quote, $this->formatter->formatOnOneLine($fragment->text))];
        }

        return $this->heredocEdit($file, $fragment);
    }

    /**
     * The text of a quoted string written over several lines, formatted: the
     * space before its first line of rule text and after its last kept, and
     * every line indented as its first line of rule text is. When the rule
     * text starts on the quote's line, the lines after it go one level past
     * the line the quote is on.
     */
    private function overLines(string $file, Fragment $fragment): string
    {
        $text = $fragment->text;
        $start = strlen($text) - strlen(ltrim($text, " \t\r\n"));
        $end = strlen(rtrim($text, " \t\r\n"));

        if ($start >= $end) {
            return $text;
        }

        $leading = substr($text, 0, $start);
        $lineBreak = strrpos($leading, "\n");

        if ($lineBreak === false) {
            $quoteLine = substr($file, (int) strrpos("\n".substr($file, 0, $fragment->fileStart), "\n"));
            $indent = substr($quoteLine, 0, strspn($quoteLine, " \t")).'    ';
            $leading = '';
        } else {
            $indent = substr($leading, $lineBreak + 1);
            $leading = substr($leading, 0, $lineBreak + 1);
        }

        $lines = explode("\n", rtrim($this->formatter->format($text), "\n"));

        foreach ($lines as $index => $line) {
            $lines[$index] = $line === '' || ($index === 0 && $lineBreak === false) ? $line : $indent.$line;
        }

        return $leading.implode("\n", $lines).substr($text, $end);
    }

    /**
     * $text written inside a PHP string that $quote opens, escaped so PHP reads
     * it back as it is.
     */
    private static function escapedFor(string $quote, string $text): string
    {
        return $quote === "'"
            ? str_replace(['\\', "'"], ['\\\\', "\\'"], $text)
            : str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $text);
    }

    /**
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function heredocEdit(string $file, Fragment $fragment): ?array
    {
        $opener = strrpos(substr($file, 0, $fragment->fileStart), '<<<');

        if ($opener === false || preg_match('/\G<<<[ \t]*(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n/', $file, $match, 0, $opener) !== 1) {
            return null;
        }

        $isNowdoc = $match[1] === "'";
        $bodyStart = $opener + strlen($match[0]);

        if (preg_match('/^([ \t]*)'.$match[2].'\b/m', $file, $closing, PREG_OFFSET_CAPTURE, $bodyStart) !== 1) {
            return null;
        }

        $closingStart = $closing[0][1];
        $bodyEnd = $closingStart - (substr($file, $closingStart - 2, 2) === "\r\n" ? 2 : 1);

        if ($bodyEnd <= $bodyStart) {
            return null;
        }

        $body = substr($file, $bodyStart, $bodyEnd - $bodyStart);

        if (! $isNowdoc && preg_match('/(?<!\\\\)(\$[A-Za-z_{]|\{\$)/', $body) === 1) {
            return null;
        }

        $formatted = rtrim($this->formatter->format($fragment->text), "\n");

        if ($formatted === '') {
            return null;
        }

        $indent = $closing[1][0].'    ';
        $lines = array_map(
            static fn (string $line): string => $line === '' ? '' : $indent.($isNowdoc ? $line : self::escapedForHeredoc($line)),
            explode("\n", $formatted),
        );

        return [$bodyStart, $bodyEnd, implode("\n", $lines)];
    }

    /**
     * $line written so a heredoc reads it back unchanged: a `$`, and a backslash
     * before another backslash or a `$`, escaped.
     */
    private static function escapedForHeredoc(string $line): string
    {
        return (string) preg_replace(['/\\\\(?=[\\\\$])/', '/\$/'], ['\\\\\\\\', '\\\\$'], $line);
    }
}
