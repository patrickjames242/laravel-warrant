<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\ParseName;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * The `as <alias>` after a handle, naming the rows the reference selects.
 * Whether the handle may take one is left to the validator: an unbound handle
 * selects nothing to name.
 *
 * @extends Parser<?NameNode>
 */
final class ParseHandleAlias extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::AS);
    }

    protected function read(): ?NameNode
    {
        $this->advance(); // consume 'as'

        $alias = $this->parse(ParseName::class)?->value;

        // An `as` with no name after it is read as no alias.
        if ($alias === null) {
            $this->report($this->nameError("an alias name after 'as'"));
        }

        return $alias;
    }
}
