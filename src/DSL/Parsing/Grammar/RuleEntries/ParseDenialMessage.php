<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArgument;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\ReadonlyParsingState;

/**
 * The denial message after `because`, from the `because` on, noted as the
 * $part of the clause it follows. The message is a part of that clause, not a
 * node of its own, so the clause reads this without recording what it returns.
 *
 * A message is a quoted string or a `:name` / `?` binding, whose value binding
 * resolution checks is a string or a closure (the
 * `Closure(WarrantDenialContext): string|Throwable` message form). Whatever
 * argument is written here is read, though: one that cannot be a message, such
 * as `@context` — a message is fixed before any check, not resolved per check —
 * is the validator's to report, as is a message after `they can`.
 *
 * @extends Parser<mixed>
 */
final class ParseDenialMessage extends Parser
{
    public function __construct(private readonly string $part)
    {
    }

    protected static function startsHere(ReadonlyParsingState $state): bool
    {
        return $state->nextIs(TokenType::BECAUSE);
    }

    protected function read(): mixed
    {
        $this->advance(); // consume 'because'
        $first = $this->peek();

        $message = ($this->parse(ParseArgument::class) ?? throw $this->errorAtCurrent(
            "Expected a denial message after 'because': a quoted string or a binding (:name or ?)."
        ))->value;

        $this->part($this->part, null, $first);

        return $message;
    }
}
