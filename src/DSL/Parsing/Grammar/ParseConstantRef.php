<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A constant: `<name>`, or `<schema>.<name>` for a constant of the schema keyed
 * `<schema>`.
 *
 * @extends Parser<ConstantRef>
 */
final class ParseConstantRef extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::IDENTIFIER);
    }

    protected function read(): ConstantRef
    {
        $first = $this->parse(ParseName::class)->value;

        if (! $this->check(TokenType::DOT)) {
            return new ConstantRef(null, $first, $this->locationEndingHere());
        }

        $this->advance(); // consume '.'

        return new ConstantRef($first, $this->nameOrMissing("a constant name after '{$first->name}.'"), $this->locationEndingHere());
    }
}
