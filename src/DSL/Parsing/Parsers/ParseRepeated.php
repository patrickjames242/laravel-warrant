<?php

namespace Warrant\DSL\Parsing\Parsers;

use LogicException;

/**
 * An item read again and again, back to back, for as long as one starts here;
 * an empty list when none does. A caller that needs at least one reports the
 * empty list.
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
     * @return list<T>
     */
    protected function read(): array
    {
        $items = [];

        while (true) {
            $before = $this->peek();
            $item = $this->parse($this->item);

            if ($item === null) {
                return $items;
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
