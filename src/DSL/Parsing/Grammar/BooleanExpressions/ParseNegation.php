<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * `not <operand>`, written `not` or `!`. It applies to the one operand after
 * it, so `not a and b` is `(not a) and b`, and `not not a` negates twice.
 *
 * @extends Parser<NotNode>
 */
final class ParseNegation extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsWith(): TokenType
    {
        return TokenType::NOT;
    }

    protected function read(): NotNode
    {
        $this->advance(); // consume 'not' or '!'

        return new NotNode(
            ($this->parse(ParseBooleanOperand::class) ?? throw $this->missingBooleanExpressionError())->value,
        );
    }
}
