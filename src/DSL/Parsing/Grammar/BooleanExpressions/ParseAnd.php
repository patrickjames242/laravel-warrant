<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * `not` operands joined by `and`, grouped from the left.
 *
 * @extends GrammarParser<IBooleanExpressionNode>
 */
final class ParseAnd extends GrammarParser
{
    protected function read(): IBooleanExpressionNode|NoMatch
    {
        $start = $this->peek();
        $left = $this->parse(ParseNot::class)?->value;

        if ($left === null) {
            return self::NOTHING;
        }

        while ($this->check(TokenType::AND)) {
            $this->advance();
            $right = ($this->parse(ParseNot::class) ?? throw $this->missingBooleanExpressionError())->value;
            $left = $this->node(new AndNode($left, $right), $start);
        }

        return $left;
    }
}
