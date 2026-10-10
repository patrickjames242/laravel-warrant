<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * `and` operands joined by `or`, grouped from the left: a whole condition
 * expression, since `or` binds loosest.
 *
 * @extends GrammarParser<IBooleanExpressionNode>
 */
final class ParseOr extends GrammarParser
{
    protected function read(): IBooleanExpressionNode|NoMatch
    {
        $start = $this->peek();
        $left = $this->parse(ParseAnd::class)?->value;

        if ($left === null) {
            return self::NOTHING;
        }

        while ($this->check(TokenType::OR)) {
            $this->advance();
            $right = ($this->parse(ParseAnd::class) ?? throw $this->missingBooleanExpressionError())->value;
            $left = $this->node(new OrNode($left, $right), $start);
        }

        return $left;
    }
}
