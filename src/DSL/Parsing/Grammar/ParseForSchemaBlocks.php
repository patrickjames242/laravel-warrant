<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * The `for <schema>` blocks of a text: one unbraced block running to the end of
 * the input, or any number of braced ones. The unbraced form has no end of its
 * own short of the input's, so a second block needs braces, and so does the
 * first.
 *
 * Blocks are returned in source order and are not merged;
 * {@see \Warrant\DSL\Parsing\ASTNodes\ValidSyntax::forSchema()} folds
 * same-schema rule sets together.
 *
 * The first block may be a `{` with no `for <schema>` before it: a mistake,
 * which a tolerant parse reads as a braced block whose header names no schema,
 * so its rules are kept. After that, a block without its header, or any block
 * after an unbraced one, is reported; a tolerant parse reads it, and every block
 * after it, all the same.
 *
 * @extends Parser<list<ISchemaScopedNode>>
 */
final class ParseForSchemaBlocks extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::FOR) || $ahead->nextIs(TokenType::LBRACE);
    }

    /**
     * @return list<ISchemaScopedNode>
     */
    protected function read(): array
    {
        if (! $this->bracedBlockAhead()) {
            $blocks = [$this->parse(new ParseForSchemaBlock(braced: false))->value];

            if ($this->check(TokenType::FOR) || $this->check(TokenType::LBRACE)) {
                $this->report($this->errorAtCurrent(
                    DiagnosticCode::UnbracedBlockBesideAnother,
                    'Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.'
                ));

                $blocks = [...$blocks, ...$this->blocksAfterAMistake()];
            }

            return $blocks;
        }

        $blocks = [$this->parse(new ParseForSchemaBlock(braced: true))->value];

        while ($this->check(TokenType::FOR)) {
            $blocks[] = $this->parse(new ParseForSchemaBlock(braced: true))->value;
        }

        if (! $this->check(TokenType::EOF)) {
            $this->report($this->errorAtCurrent(
                DiagnosticCode::BlockWithoutHeader,
                'Expected `for <schema> { ... }`; every rule set beside a braced one needs a `for` header and braces.'
            ));

            $blocks = [...$blocks, ...$this->blocksAfterAMistake()];
        }

        return $blocks;
    }

    /**
     * Whether the block ahead is braced. `for <schema> {` opens a braced block,
     * and so do a header missing its name, `for {`, and a `{` missing its
     * header; anything else after the header is an unbraced one.
     */
    private function bracedBlockAhead(): bool
    {
        return ! $this->check(TokenType::FOR)
            || $this->peekAhead()->type === TokenType::LBRACE
            || $this->peekAhead(2)->type === TokenType::LBRACE;
    }

    /**
     * The blocks a tolerant parse goes on to read after one the text should not
     * have had where it is, which it has reported: each `for` block or bare `{`
     * that follows, braced or not as it is written, so their rules are kept. A
     * strict parse has thrown by then, and reads none.
     *
     * @return list<ISchemaScopedNode>
     */
    private function blocksAfterAMistake(): array
    {
        $blocks = [];

        while ($this->check(TokenType::FOR) || $this->check(TokenType::LBRACE)) {
            $blocks[] = $this->parse(new ParseForSchemaBlock(braced: $this->bracedBlockAhead()))->value;
        }

        return $blocks;
    }
}
