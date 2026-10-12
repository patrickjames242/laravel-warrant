<?php

namespace Warrant\DSL\Parsing\Parsers;

/**
 * Everything a parser can change in a {@see ParsingState}, as it stood at one
 * moment, so a parser that does not match can be put back as though it never
 * ran.
 */
final readonly class Checkpoint
{
    /**
     * @param int $index The token the parser started on.
     * @param int $records How many records had been made.
     * @param int $unclaimed How many parts were waiting for a node.
     */
    public function __construct(
        public int $index,
        public int $records,
        public int $unclaimed,
    ) {
    }
}
