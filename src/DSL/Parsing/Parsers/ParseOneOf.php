<?php

namespace Warrant\DSL\Parsing\Parsers;

/**
 * What the first of several parsers that matches here read, or
 * {@see Parser::NOTHING} when none does.
 *
 * @extends Parser<mixed>
 */
final class ParseOneOf extends Parser
{
    /**
     * @param list<class-string<Parser>|Parser> $parsers In the order to try them.
     *   A list rather than any iterable, because one instance is read again and
     *   again, and a generator could be walked only once.
     */
    public function __construct(private readonly array $parsers)
    {
    }

    protected function read(): mixed
    {
        foreach ($this->parsers as $parser) {
            $parsed = $this->parse($parser);

            if ($parsed !== null) {
                return $parsed->value;
            }
        }

        return self::NOTHING;
    }
}
