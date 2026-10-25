<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\RuleEntries\LooksAheadAtRuleEntries;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The whole of a text, read with {@see \Warrant\DSL\Parsing\Parsers\Parser::run()}:
 * empty, `for <schema>` blocks, rules with no `for` header, or one bare boolean
 * expression. It reads the nodes the text holds at the top, and which form
 * the text took is what they are. A text that starts with `{` is blocks whose
 * first is missing its `for <schema>` header.
 *
 * @extends Parser<list<INode>>
 */
final class ParseSyntax extends Parser
{
    use ReportsMissingSyntax;
    use LooksAheadAtRuleEntries;

    /**
     * @return list<INode>
     */
    protected function read(): array
    {
        if ($this->check(TokenType::EOF)) {
            return [];
        }

        $children = $this->parse(ParseForSchemaBlocks::class)
            ?? ($this->ruleEntryAhead() ? $this->parse(ParseRulesWithoutForHeader::class) : null);

        if ($children !== null) {
            return $children->value;
        }

        $this->assertNoAbilityListWithoutCanThey();

        return [$this->expressionOrMissing(ParseBooleanExpression::class)];
    }
}
