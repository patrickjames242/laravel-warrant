<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArgument;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * The denial message after `because`: the argument written after it, as the
 * node it reads as.
 *
 * A message is a quoted string or a `:name` / `?` binding, whose value the
 * validator checks is a string or a closure (the
 * `Closure(WarrantDenialContext): string|Throwable` message form). Whatever
 * argument is written here is read, though: one that cannot be a message, such
 * as `@context` — a message is fixed before any check, not resolved per check —
 * is the validator's to report, as is a message after `they can`.
 *
 * @extends Parser<\Warrant\DSL\Parsing\ASTNodes\INode|null>
 */
final class ParseDenialMessage extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::BECAUSE);
    }

    protected function read(): mixed
    {
        $this->advance(); // consume 'because'

        $message = $this->parse(ParseArgument::class);

        // A `because` with no message after it is read as giving none.
        if ($message === null) {
            $this->report($this->errorAtCurrent(
                DiagnosticCode::MissingDenialMessage,
                "Expected a denial message after 'because': a quoted string or a binding (:name or ?)."
            ));

            return null;
        }

        return $message->value;
    }
}
