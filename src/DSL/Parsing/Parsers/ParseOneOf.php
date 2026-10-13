<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;

/**
 * What the first of several parsers that starts here reads. A subclass names
 * the parsers in {@see parsers()}, and so where it can start is known from its
 * class alone.
 *
 * @template T
 * @extends Parser<T>
 */
abstract class ParseOneOf extends Parser
{
    /**
     * The parsers to try, in the order to try them.
     *
     * @return list<class-string<Parser<T>>|Parser<T>>
     */
    abstract protected static function parsers(): array;

    /**
     * Whether one of the parsers starts here.
     */
    protected static function startsHere(TokensAhead $ahead): bool
    {
        foreach (static::parsers() as $parser) {
            if ($parser::startsHere($ahead)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return T
     */
    protected function read(): mixed
    {
        foreach (static::parsers() as $parser) {
            $parsed = $this->parse($parser);

            if ($parsed !== null) {
                return $parsed->value;
            }
        }

        // It starts only where one of its parsers does.
        throw new LogicException(sprintf('None of the parsers of %s starts here.', static::class));
    }
}
