<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseBinding;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The `cannot <abilities> [because <message>]` of a `they cannot` clause, from
 * the `cannot` on.
 *
 * @extends Parser<CannotClauseNode>
 */
final class ParseCannotClause extends Parser
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
     * binding, whose value binding resolution checks is a string or a closure
     * (the `Closure(WarrantDenialContext): string|Throwable` message form).
     * `@context` is not allowed: a message is fixed before any check, not
     * resolved per check.
     */
    private function denialMessage(): string|BindingNode
    {
        $token = $this->peek();

        $message = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING,
            TokenType::POSITIONAL => $this->parse(ParseBinding::class)->value,
            default => throw $this->errorAtCurrent(
                "Expected a denial message after 'because': a quoted string or a binding (:name or ?). "
                    .'@context is not allowed here.'
            ),
        };

        $this->part(CannotClauseNode::PART_MESSAGE, null, $token);

        return $message;
    }
}
