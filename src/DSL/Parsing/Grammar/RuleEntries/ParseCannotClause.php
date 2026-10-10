<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Closure;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * The `cannot <abilities> [because <message>]` of a `they cannot` clause, from
 * the `cannot` on.
 *
 * @extends GrammarParser<CannotClauseNode>
 */
final class ParseCannotClause extends GrammarParser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): CannotClauseNode|NoMatch
    {
        if (! $this->check(TokenType::CANNOT)) {
            return self::NOTHING;
        }

        $this->advance();
        $abilities = $this->parse(new ParseClauseAbilityList($this->naming, CannotClauseNode::PART_ABILITIES))->value;
        $message = null;

        if ($this->check(TokenType::BECAUSE)) {
            $this->advance();
            $message = $this->denialMessage();
        }

        return new CannotClauseNode($abilities, $message);
    }

    /**
     * The denial message after `because`: a quoted string, or a `:name` / `?`
     * binding that resolves to a string or to a closure (the
     * `Closure(WarrantDenialContext): string|Throwable` message form). A literal
     * must be a string, and `@context` is not allowed: a message is fixed at
     * parse time, not resolved per check.
     */
    private function denialMessage(): string|Closure
    {
        $token = $this->peek();

        $message = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings()->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings()->resolvePositional($this->advance()),
            default => throw $this->errorAtCurrent(
                "Expected a denial message after 'because': a quoted string or a binding (:name or ?). "
                    .'@context is not allowed here.'
            ),
        };

        $this->part(CannotClauseNode::PART_MESSAGE, null, $token);

        if (! is_string($message) && ! $message instanceof Closure) {
            throw $this->errorAt(
                sprintf('A denial message must be a string or a closure, got %s.', get_debug_type($message)),
                $token,
            );
        }

        return $message;
    }
}
