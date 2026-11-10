<?php

namespace Warrant\DSL\Validation;

use InvalidArgumentException;
use Throwable;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\RuleTextExcerpt;
use Warrant\DSL\Parsing\Positions\Span;

/**
 * A rule that breaks a rule of the language, reported by the validator. When
 * the rule was parsed from text, the error says where in that text: the file
 * and line when the file it is written in can be found, and the lines around it
 * with what it is about underlined, as a syntax error does. A rule built in code
 * has no text, and its error says only what is wrong.
 *
 * When the file is known, the exception's own file and line are the place in
 * it, so an error page opens the rule text rather than the validator.
 */
class RuleValidationException extends InvalidArgumentException
{
    /**
     * @param DiagnosticCode $diagnosticCode Which kind of mistake this is, as a
     *   {@see \Warrant\DSL\Parsing\Diagnostic} made from it carries it. Named
     *   so as not to be the base Exception's own integer $code.
     * @param string $reason The error alone, without the place, the excerpt of
     *   the source and the hint that the exception's message adds to it.
     * @param RuleTextExcerpt|null $excerpt Where in the text the error is, when
     *   the rule it is about was read from text and the error is thrown.
     * @param Throwable|null $previous The error this one places or rewords.
     * @param Span|null $span The text the error is about.
     * @param string|null $hint What may fix it, as a sentence or two, such as the
     *   names it could have meant.
     */
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        public readonly string $reason,
        public readonly ?RuleTextExcerpt $excerpt = null,
        ?Throwable $previous = null,
        public readonly ?Span $span = null,
        public readonly ?string $hint = null,
    ) {
        parent::__construct(match (true) {
            $excerpt !== null => $excerpt->describe($reason, $hint),
            $hint !== null => "{$reason}\n\n{$hint}",
            default => $reason,
        }, previous: $previous);

        if ($excerpt?->file !== null) {
            $this->file = $excerpt->file->path;
            $this->line = $excerpt->line();
        }
    }

    /** $reason with its hint after it, as a diagnostic in the editor says it. */
    public function reasonWithHint(): string
    {
        return $this->hint === null ? $this->reason : "{$this->reason} {$this->hint}";
    }
}
