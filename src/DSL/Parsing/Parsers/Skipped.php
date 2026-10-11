<?php

namespace Warrant\DSL\Parsing\Parsers;

/**
 * What {@see Parser::parseOrSkip()} reads in place of text it stepped over after
 * an error: {@see Parser::SKIPPED}. No value read from rule text is ever
 * identical to it, so a caller can drop it from what it collects.
 */
enum Skipped
{
    case Skipped;
}
