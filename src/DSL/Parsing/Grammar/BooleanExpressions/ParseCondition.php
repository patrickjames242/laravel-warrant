<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A condition: its name, and its arguments when it is written with
 * parentheses. A condition written without them takes no arguments.
 *
 * @extends Parser<ConditionNode>
 */
final class ParseCondition extends Parser
{
    protected function read(): ConditionNode|CouldNotParse
    {
        if (! $this->check(TokenType::IDENTIFIER)) {
            return self::COULD_NOT_PARSE;
        }

        $name = $this->advance();
        $this->part(ConditionNode::PART_CONDITION_KEY, null, $name);

        return new ConditionNode(
            $name->lexeme,
            $this->parse(new ParseArguments(ConditionNode::PART_PARAMETERS, 'the condition arguments'))?->value ?? [],
        );
    }
}
