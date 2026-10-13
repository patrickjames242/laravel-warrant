<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;
use Warrant\DSL\Lexing\TokenType;

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
     * The tokens each subclass can start at, by its class, worked out on the
     * first parse that asks.
     *
     * @var array<class-string<ParseOneOf>, list<TokenType>|null>
     */
    private static array $startsWithByClass = [];

    /**
     * The parsers to try, in the order to try them.
     *
     * @return list<class-string<Parser<T>>|Parser<T>>
     */
    abstract protected static function parsers(): array;

    /**
     * Every token one of the parsers can start at, or null when one of them may
     * start at any.
     *
     * @return list<TokenType>|null
     */
    protected static function startsWith(): ?array
    {
        if (array_key_exists(static::class, self::$startsWithByClass)) {
            return self::$startsWithByClass[static::class];
        }

        $tokens = [];

        foreach (static::parsers() as $parser) {
            $startsWith = $parser::startsWith();

            if ($startsWith === null) {
                $tokens = null;
                break;
            }

            array_push($tokens, ...(is_array($startsWith) ? $startsWith : [$startsWith]));
        }

        return self::$startsWithByClass[static::class] = $tokens;
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

        // Its start is every one of its parsers' starts, so one of them starts here.
        throw new LogicException(sprintf('None of the parsers of %s starts here.', static::class));
    }
}
