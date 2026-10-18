<?php

namespace Warrant\DSL\Validation;

use InvalidArgumentException;
use Throwable;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\Parsing\Positions\Span;

/**
 * A rule that breaks a rule of the language, reported by the validator. When
 * the rule was parsed from text, the error says where in that text: the line
 * and column, and the line itself with a caret under the column, as a syntax
 * error does. A rule built in code has no text, and its error says only what
 * is wrong.
 */
class RuleValidationException extends InvalidArgumentException
{
    /**
     * @param DiagnosticCode $diagnosticCode Which kind of mistake this is, as a
     *   {@see \Warrant\DSL\Parsing\Diagnostic} made from it carries it. Named
     *   so as not to be the base Exception's own integer $code.
     * @param string $reason The error alone, without the position and the
     *   excerpt of the source that the exception's message adds to it.
     * @param SourcePosition|null $position Where in the text the error is, when
     *   the rule it is about was read from text.
     * @param Throwable|null $previous The error this one places or rewords.
     * @param Span|null $span The text the error is about, which $position is
     *   the start of.
     */
    public function __construct(
        public readonly DiagnosticCode $diagnosticCode,
        public readonly string $reason,
        public readonly ?SourcePosition $position = null,
        ?Throwable $previous = null,
        public readonly ?Span $span = null,
    ) {
        parent::__construct($position?->describe($reason) ?? $reason, previous: $previous);
    }
}
