<?php

namespace Warrant\DSL\Parsing\Positions;

/**
 * A place in a text as an editor names it over the Language Server Protocol: a
 * line, and a character within it counted in UTF-16 code units.
 */
final readonly class LinePosition
{
    /**
     * @param int $line 0-based line number, where a line ends at "\n", "\r\n" or "\r".
     * @param int $character 0-based count of UTF-16 code units before the place on
     *   its line.
     */
    public function __construct(
        public int $line,
        public int $character,
    ) {
    }
}
