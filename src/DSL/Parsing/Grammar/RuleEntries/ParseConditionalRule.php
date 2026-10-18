<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A rule with a condition: `if <boolean expression>` and then the
 * `they can` / `they cannot` clauses it applies to.
 *
 * @extends Parser<WarrantRuleNode>
 */
final class ParseConditionalRule extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::IF);
    }

    protected function read(): WarrantRuleNode
    {
        $this->advance(); // consume 'if'
        $conditions = $this->expressionOrMissing(ParseBooleanExpression::class);
        [$canClauses, $cannotClauses] = $this->parse(new ParseTheyCanAndCannotClauses)->value;

        return new WarrantRuleNode($conditions, $canClauses, $cannotClauses, $this->locationEndingHere());
    }
}
