<?php

namespace Warrant\DSL\Parsing\Validation;

use InvalidArgumentException;
use Throwable;
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
     * @param string $reason The error alone, without the position and the
     *   excerpt of the source that the exception's message adds to it.
     * @param Span|null $span The text the error is about, which $position is
     *   the start of.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?SourcePosition $position = null,
        ?Throwable $previous = null,
        public readonly ?Span $span = null,
    ) {
        parent::__construct($position?->describe($reason) ?? $reason, previous: $previous);
    }
}
