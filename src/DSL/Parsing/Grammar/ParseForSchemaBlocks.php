<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The `for <schema>` blocks of a text: one unbraced block running to the end of
 * the input, or any number of braced ones. The unbraced form has no end of its
 * own short of the input's, so a second block needs braces, and so does the
 * first.
 *
 * Blocks are returned in source order and are not merged;
 * {@see \Warrant\DSL\Parsing\ASTNodes\WarrantSyntax::forSchema()} folds
 * same-schema rule sets together.
 *
 * @extends Parser<list<ISchemaScopedNode>>
 */
final class ParseForSchemaBlocks extends Parser
{
    /**
     * @return list<ISchemaScopedNode>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        if (! $this->check(TokenType::FOR)) {
            return self::NOTHING;
        }

        // `for <schema> {` opens a braced block; anything else after the header is an unbraced one.
        if ($this->peekAhead(2)->type !== TokenType::LBRACE) {
            $block = $this->parse(new ParseForSchemaBlock(braced: false))->value;

            if ($this->check(TokenType::FOR) || $this->check(TokenType::LBRACE)) {
                $this->report($this->errorAtCurrent(
                    'Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.'
                ));
            }

            return [$block];
        }

        $blocks = $this->parseRepeated(new ParseForSchemaBlock(braced: true))->value;

        if (! $this->check(TokenType::EOF)) {
            $this->report($this->errorAtCurrent(
                'Expected `for <schema> { ... }`; every rule set beside a braced one needs a `for` header and braces.'
            ));
        }

        return $blocks;
    }
}
