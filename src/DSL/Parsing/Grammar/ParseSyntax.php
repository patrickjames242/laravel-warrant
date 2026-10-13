<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\RuleEntries\LooksAheadAtRuleEntries;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The whole of a text, read with {@see \Warrant\DSL\Parsing\Parsers\Parser::run()}:
 * empty, `for <schema>` blocks, rules with no `for` header, or one bare boolean
 * expression. Its children say which form the text took. A text that starts
 * with `{` is blocks whose first is missing its `for <schema>` header.
 *
 * @extends Parser<WarrantSyntax>
 */
final class ParseSyntax extends Parser
{
    use ReportsMissingSyntax;
    use LooksAheadAtRuleEntries;

    protected function read(): WarrantSyntax
    {
        if ($this->check(TokenType::EOF)) {
            return new WarrantSyntax([]);
        }

        $children = $this->parse(ParseForSchemaBlocks::class)
            ?? ($this->ruleEntryAhead() ? $this->parse(ParseRulesWithoutForHeader::class) : null);

        if ($children !== null) {
            return new WarrantSyntax($children->value);
        }

        $this->assertNoAbilityListWithoutCanThey();

        return new WarrantSyntax([
            $this->expressionOrMissing(ParseBooleanExpression::class),
        ]);
    }
}
