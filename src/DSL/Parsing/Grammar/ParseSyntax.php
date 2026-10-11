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
 * expression. Its children say which form the text took.
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

        if ($this->check(TokenType::LBRACE)) {
            throw $this->errorAtCurrent('A `{ ... }` block needs a `for <schema>` header before it.');
        }

        $children = $this->parse(ParseForSchemaBlocks::class)
            ?? $this->parse(ParseRulesWithoutForHeader::class);

        if ($children !== null) {
            return new WarrantSyntax($children->value);
        }

        $this->assertNoAbilityListWithoutCanThey();

        return new WarrantSyntax([
            ($this->parse(ParseBooleanExpression::class) ?? throw $this->missingBooleanExpressionError())->value,
        ]);
    }
}
