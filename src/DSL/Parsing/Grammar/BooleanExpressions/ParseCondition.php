<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ParseName;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A condition: its name, and its arguments when it is written with
 * parentheses. A condition written without them takes no arguments.
 *
 * @extends Parser<ConditionNode>
 */
final class ParseCondition extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::IDENTIFIER);
    }

    protected function read(): ConditionNode
    {
        return new ConditionNode(
            $this->parse(ParseName::class)->value,
            $this->parse(new ParseArguments('the condition arguments'))?->value ?? [],
            $this->locationEndingHere(),
        );
    }
}
