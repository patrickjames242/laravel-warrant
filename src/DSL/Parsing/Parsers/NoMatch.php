<?php

namespace Warrant\DSL\Parsing\Parsers;

/**
 * What a parser returns when what it reads is not here: {@see Parser::NOTHING}.
 * No value read from rule text is ever identical to it, so null and every
 * other value stay free to be what was read.
 */
enum NoMatch
{
    case NoMatch;
}
