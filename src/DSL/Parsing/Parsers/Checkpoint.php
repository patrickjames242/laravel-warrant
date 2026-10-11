<?php

namespace Warrant\DSL\Parsing\Parsers;

use Warrant\DSL\Parsing\BindingState;

/**
 * Everything a parser can change in a {@see ParsingState}, as it stood at one
 * moment, so a parser that does not match can be put back as though it never
 * ran.
 */
final readonly class Checkpoint
{
    /**
     * @param int $index The token the parser started on.
     * @param BindingState $bindings A copy of the bindings, so a `?` read by a
     *   parser that did not match does not use up a positional value.
     * @param int $records How many records had been made.
     * @param int $unclaimed How many parts were waiting for a node.
     * @param int $diagnostics How many diagnostics had been made.
     */
    public function __construct(
        public int $index,
        public BindingState $bindings,
        public int $records,
        public int $unclaimed,
        public int $diagnostics,
    ) {
    }
}
