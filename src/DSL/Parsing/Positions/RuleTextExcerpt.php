<?php

namespace Warrant\DSL\Parsing\Positions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * Where an error in rule text is: the file and line the text is written in,
 * when that file can be found, and otherwise the line and column in the text
 * and what answered with it.
 *
 * An error's message is one line, {@see summary()}: the file and line are the
 * exception's own, which an error page, a log and a terminal all show, so the
 * message names the place only when no file was found. {@see describe()} lays
 * it out over several lines, with the lines around it and what it is about
 * underlined, for code that shows an error in a fixed-width font.
 */
final readonly class RuleTextExcerpt
{
    /** How many lines are shown on each side of the one the error is on. */
    private const AROUND = 1;

    /**
     * @param string $text The rule text.
     * @param int $offset Byte offset in $text where what the error is about starts.
     * @param int $endOffset Byte offset in $text just past where it ends.
     * @param PlaceInFile|null $file Where it is written, when that is known.
     * @param string|null $origin What answered with the text, as
     *   {@see \Warrant\DSL\Parsing\SyntaxProducer::describe()} says it.
     */
    public function __construct(
        public string $text,
        public int $offset,
        public int $endOffset,
        public ?PlaceInFile $file,
        public ?string $origin,
    ) {
    }

    /**
     * Bytes $offset to $endOffset of $text, looked up in the file $source says
     * the text came from. Reading a PHP file tokenizes it, so this is for an
     * error that is thrown.
     */
    public static function of(string $text, int $offset, int $endOffset, ?SyntaxSource $source): self
    {
        $endOffset = max($offset, $endOffset);
        $bound = $source?->bound;
        $producer = $source?->producer();
        $file = $bound === null || $bound->syntax !== $text ? null : RuleTextInFile::find($bound, $producer, $offset, $endOffset);

        return new self($text, $offset, $endOffset, $file, $producer?->describe());
    }

    /**
     * Bytes $offset to $endOffset of $text, with nothing known of where the text
     * came from.
     */
    public static function inText(string $text, int $offset, int $endOffset): self
    {
        return new self($text, $offset, max($offset, $endOffset), null, null);
    }

    /** The 1-based line the error starts on: the file's, when the file is known. */
    public function line(): int
    {
        [$text, $offset] = $this->shown();

        return substr_count(substr($text, 0, $offset), "\n") + 1;
    }

    /** The 1-based byte column the error starts at, on {@see line()}. */
    public function column(): int
    {
        [$text, $offset] = $this->shown();
        $lineBreak = strrpos(substr($text, 0, $offset), "\n");

        return $offset - ($lineBreak === false ? 0 : $lineBreak + 1) + 1;
    }

    /**
     * $reason and $hint on one line, as an error's message: with the line and
     * column in the text, and what answered with it, when no file was found.
     *
     * @param string|null $hint What may fix it, as a sentence or two.
     */
    public function summary(string $reason, ?string $hint = null): string
    {
        if ($this->file === null) {
            $where = $this->origin === null
                ? sprintf('line %d, column %d', $this->line(), $this->column())
                : sprintf('line %d, column %d of %s', $this->line(), $this->column(), lcfirst($this->origin));
            $reason = "{$reason} ({$where})";
        }

        return $hint === null ? $reason : "{$reason} {$hint}";
    }

    /**
     * $reason, then where it is and the lines around it, then $hint, over
     * several lines for a fixed-width font.
     *
     * @param string|null $hint What may fix it, as a sentence or two.
     */
    public function describe(string $reason, ?string $hint = null): string
    {
        $origin = $this->origin === null ? null : lcfirst($this->origin);

        $where = match (true) {
            $this->file !== null => sprintf('%s:%d:%d', self::shortPath($this->file->path), $this->line(), $this->column()),
            $origin !== null => sprintf('line %d, column %d of %s', $this->line(), $this->column(), $origin),
            default => sprintf('line %d, column %d', $this->line(), $this->column()),
        };

        $message = "{$reason}\n\n  --> {$where}\n";

        if ($this->file !== null && $origin !== null) {
            $message .= "      in {$origin}\n";
        }

        $message .= "\n".$this->lines();

        return $hint === null ? rtrim($message) : "{$message}\n{$hint}";
    }

    /**
     * The line the error starts on, with those around it, numbered, and a line
     * of carets under what it is about, which stops at the end of its line.
     */
    private function lines(): string
    {
        [$text, $offset, $endOffset] = $this->shown();
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [''];
        $line = $this->line();
        $first = max(1, $line - self::AROUND);
        $last = min(count($lines), $line + self::AROUND);
        $width = strlen((string) $last);

        $lineText = $lines[$line - 1];
        $start = $this->column() - 1;
        $length = max(1, min($endOffset - $offset, strlen($lineText) - $start));
        $underline = preg_replace('/[^\t]/', ' ', substr($lineText, 0, $start)).str_repeat('^', $length);

        $shown = '';

        for ($number = $first; $number <= $last; $number++) {
            $shown .= sprintf("  %{$width}d | %s\n", $number, $lines[$number - 1]);

            if ($number === $line) {
                $shown .= sprintf("  %s | %s\n", str_repeat(' ', $width), $underline);
            }
        }

        return $shown;
    }

    /**
     * The text the lines are shown from, and where the error is in it: the file
     * when it is known, and otherwise the rule text.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function shown(): array
    {
        return $this->file === null
            ? [$this->text, $this->offset, $this->endOffset]
            : [$this->file->text, $this->file->offset, $this->file->endOffset];
    }

    /** $path from the application's base directory, when it is under it. */
    private static function shortPath(string $path): string
    {
        $application = class_exists(Container::class) ? Container::getInstance() : null;

        if (! $application instanceof Application) {
            return $path;
        }

        $base = rtrim($application->basePath(), '/\\').DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
