<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;

/**
 * An item read again and again, back to back, for as long as one is here;
 * {@see Parser::NOTHING} when not even the first is. A caller that takes none
 * reads the NOTHING as an empty list, and one that needs at least one reports
 * it.
 *
 * @template T
 * @extends Parser<list<T>>
 */
final class ParseRepeated extends Parser
{
    /**
     * @param class-string<Parser<T>>|Parser<T> $item
     */
    public function __construct(private readonly string|Parser $item)
    {
    }

    /**
     * @return list<T>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        $items = [];

        while (true) {
            $before = $this->peek();
            $item = $this->parse($this->item);

            if ($item === null) {
                return $items === [] ? self::NOTHING : $items;
            }

            // An item that matches without reading would match here forever.
            if ($this->peek() === $before) {
                throw new LogicException(sprintf(
                    'An item of a repeated list matched without reading anything, as %s.',
                    get_debug_type($item->value),
                ));
            }

            $items[] = $item->value;
        }
    }
}
