<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * `not <operand>`, written `not` or `!`. It applies to the one operand after
 * it, so `not a and b` is `(not a) and b`, and `not not a` negates twice.
 *
 * @extends GrammarParser<NotNode>
 */
final class ParseNegation extends GrammarParser
{
    protected function read(): NotNode|NoMatch
    {
        if (! $this->check(TokenType::NOT)) {
            return self::NOTHING;
        }

        $this->advance();

        return new NotNode(
            ($this->parse(ParseBooleanOperand::class) ?? throw $this->missingBooleanExpressionError())->value,
        );
    }
}
