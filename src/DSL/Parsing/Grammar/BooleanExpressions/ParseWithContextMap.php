<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArgument;
use Warrant\DSL\Parsing\Grammar\GrammarParser;
use Warrant\DSL\Parsing\Parsers\NoMatch;

/**
 * A `with` context map: `with key = arg (, key = arg)*`, or {@see NOTHING}
 * when no `with` is here. Keys are the target schema's context key names, and each may be
 * given once.
 *
 * @extends GrammarParser<array<string, mixed>>
 */
final class ParseWithContextMap extends GrammarParser
{
    /**
     * @param class-string<CrossSchemaCanNode|CrossSchemaConditionNode> $owner The
     *   node the map belongs to, whose constants name the parts read here.
     */
    public function __construct(private readonly string $owner)
    {
    }

    /**
     * @return array<string, mixed>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        if (! $this->check(TokenType::WITH)) {
            return self::NOTHING;
        }

        $this->advance();
        $map = [];

        do {
            if (! $this->check(TokenType::IDENTIFIER)) {
                throw $this->nameError('a context key name');
            }

            $keyToken = $this->advance();
            $key = $keyToken->lexeme;

            if (array_key_exists($key, $map)) {
                throw $this->errorAt(sprintf("Duplicate key '%s' in the 'with' map.", $key), $keyToken);
            }

            $this->part($this->owner::PART_CONTEXT_MAP_KEY, $key, $keyToken);

            $this->expect(TokenType::EQUALS, "Expected '=' after the 'with' key.");
            $value = $this->peek();
            $map[$key] = ($this->parse(ParseArgument::class) ?? throw $this->missingArgumentError())->value;
            $this->part($this->owner::PART_CONTEXT_MAP, $key, $value);
        } while ($this->check(TokenType::COMMA) && $this->advance());

        return $map;
    }
}
