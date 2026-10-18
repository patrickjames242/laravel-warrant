<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArgument;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One entry of a `with` map: `key = arg`. An entry whose key is missing starts
 * at its `=`, and is read with the key in place of the missing one.
 *
 * @extends Parser<WithEntryNode>
 */
final class ParseWithEntry extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::IDENTIFIER) || $ahead->nextIs(TokenType::EQUALS);
    }

    protected function read(): WithEntryNode
    {
        $key = $this->nameOrMissing('a context key name');

        $this->expectOrAssume(TokenType::EQUALS, "Expected '=' after the 'with' key.");

        $value = $this->parse(ParseArgument::class)?->value;

        if ($value === null) {
            $this->report($this->missingArgumentError());
            $value = new MissingArgumentNode($this->emptyLocationHere());
        }

        return new WithEntryNode($key, $value, $this->locationEndingHere());
    }
}
