<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * A rule with a condition: `if <boolean expression>` and then the
 * `they can` / `they cannot` clauses it applies to.
 *
 * @extends GrammarParser<WarrantRuleNode>
 */
final class ParseConditionalRule extends GrammarParser
{
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): WarrantRuleNode|NoMatch
    {
        if (! $this->check(TokenType::IF)) {
            return self::NOTHING;
        }

        $this->advance();
        $conditions = ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value;
        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses($this->naming))->value;

        return new WarrantRuleNode($conditions, $canClauses, $cannotClauses);
    }
}
