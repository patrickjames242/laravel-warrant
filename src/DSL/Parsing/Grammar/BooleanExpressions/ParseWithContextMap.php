<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArgument;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A `with` context map: `with key = arg (, key = arg)*`, which starts at `with`,
 * so a parse finds none when no `with` is here. Keys are the target schema's
 * context key names, and each may be given once.
 *
 * @extends Parser<array<string, mixed>>
 */
final class ParseWithContextMap extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @param class-string<CrossSchemaCanNode|CrossSchemaConditionNode> $owner The
     *   node the map belongs to, whose constants name the parts read here.
     */
    public function __construct(private readonly string $owner)
    {
    }

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::WITH);
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        $this->advance(); // consume 'with'
        $map = [];

        do {
            /* With no key, an entry whose `=` is written is read and left out of the
               map; with neither, the map read so far is kept, and what follows is
               the reader's to deal with. */
            if (! $this->check(TokenType::IDENTIFIER)) {
                $this->report($this->nameError('a context key name'));

                if (! $this->check(TokenType::EQUALS)) {
                    return $map;
                }

                $key = null;
                $keep = false;
            } else {
                $keyToken = $this->advance();
                $key = $keyToken->lexeme;
                $keep = ! array_key_exists($key, $map);

                // A key written twice keeps its first value.
                if ($keep) {
                    $this->part($this->owner::PART_CONTEXT_MAP_KEY, $key, $keyToken);
                } else {
                    $this->report($this->errorAt(DiagnosticCode::DuplicateContextKey, sprintf("Duplicate key '%s' in the 'with' map.", $key), $keyToken));
                }
            }

            $this->expectOrAssume(TokenType::EQUALS, "Expected '=' after the 'with' key.");
            $valueToken = $this->peek();
            $value = $this->parse(ParseArgument::class);

            if ($value === null) {
                $this->report($this->missingArgumentError());
            }

            if ($keep) {
                $map[$key] = $value?->value ?? new MissingArgumentNode;

                if ($value !== null) {
                    $this->part($this->owner::PART_CONTEXT_MAP, $key, $valueToken);
                }
            }
        } while ($this->check(TokenType::COMMA) && $this->advance());

        return $map;
    }
}
