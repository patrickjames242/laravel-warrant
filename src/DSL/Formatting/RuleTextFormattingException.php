<?php

namespace Warrant\DSL\Formatting;

use RuntimeException;
use Warrant\DSL\Parsing\Diagnostic;

/**
 * Why rule text was left as it is: it does not parse, or the formatter could
 * not lay it out without changing what it says.
 */
final class RuleTextFormattingException extends RuntimeException
{
    /**
     * @param list<Diagnostic> $diagnostics The syntax errors, when it does not parse.
     */
    private function __construct(string $message, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }

    /**
     * @param string $source The text.
     * @param list<Diagnostic> $diagnostics Its syntax errors.
     */
    public static function syntaxErrors(string $source, array $diagnostics): self
    {
        $first = $diagnostics[0];
        $line = substr_count(substr($source, 0, $first->offset), "\n") + 1;
        $column = $first->offset - (int) strrpos("\n".substr($source, 0, $first->offset), "\n") + 1;

        return new self("Line {$line}, column {$column}: {$first->message}", $diagnostics);
    }

    public static function notKept(string $what): self
    {
        return new self("The text was left as it is, because formatting it would have changed {$what}.");
    }

    public static function hasComments(): self
    {
        return new self('Text with comments cannot be laid out on one line.');
    }
}
