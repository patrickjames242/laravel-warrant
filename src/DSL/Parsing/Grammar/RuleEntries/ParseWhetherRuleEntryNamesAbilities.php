<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * Whether the rule entry here, written with no `for` header, names the
 * abilities it applies to; {@see NOTHING} when no rule entry starts here. Read with
 * {@see \Warrant\DSL\Parsing\Parsers\Parser::lookahead()}, ahead of reading the
 * entries for real.
 *
 * An ability block names them in its header. An include names them when a
 * `for` list follows its arguments. A clause, after its `if` condition when
 * it has one, names them unless its `can` / `cannot` is followed by what
 * follows a finished clause.
 *
 * Where the entry is malformed before that is settled, the answer is false:
 * reading the entry for real fails at the same place, before its naming is
 * looked at.
 *
 * @extends GrammarParser<bool>
 */
final class ParseWhetherRuleEntryNamesAbilities extends GrammarParser
{
    protected function read(): bool|NoMatch
    {
        if ($this->abilityBlockAhead()) {
            return true;
        }

        if ($this->check(TokenType::INCLUDE_REF)) {
            $this->advance();

            if (! $this->check(TokenType::IDENTIFIER)) {
                return false;
            }

            $this->advance();
            $this->parse(new ParseArguments(IncludeInvocationNode::PART_ARGUMENTS, 'the @include arguments'));

            return $this->check(TokenType::FOR);
        }

        if ($this->check(TokenType::IF)) {
            $this->advance();

            if ($this->parse(ParseBooleanExpression::class) === null) {
                return false;
            }
        } elseif (! $this->check(TokenType::THEY)) {
            return self::NOTHING;
        }

        if (! $this->check(TokenType::THEY)) {
            return false;
        }

        $this->advance();

        if (! $this->check(TokenType::CAN) && ! $this->check(TokenType::CANNOT)) {
            return false;
        }

        $this->advance();

        return ! $this->clauseEndAhead();
    }
}
