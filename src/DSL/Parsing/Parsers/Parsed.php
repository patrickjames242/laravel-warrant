<?php

namespace Warrant\DSL\Parsing\Parsers;

/**
 * What a parser read, when it matched. The value may be null, as an argument
 * written `null` is.
 *
 * @template-covariant T
 */
final readonly class Parsed
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}
