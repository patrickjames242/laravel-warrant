<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A `with` context map: `with key = arg (, key = arg)*`, which starts at `with`,
 * so a parse finds none when no `with` is here. Keys are the target schema's
 * context key names. Each entry is read as written, a key given twice
 * included: that is the validator's to report.
 *
 * @extends Parser<list<WithEntryNode>>
 */
final class ParseWithContextMap extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::WITH);
    }

    /**
     * @return list<WithEntryNode>
     */
    protected function read(): array
    {
        $this->advance(); // consume 'with'

        $missingEntry = fn () => $this->nameError('a context key name');
        $entries = $this->parseSeparatedList(ParseWithEntry::class, TokenType::COMMA, $missingEntry);

        if ($entries === []) {
            $this->report($missingEntry());
        }

        return $entries;
    }
}
