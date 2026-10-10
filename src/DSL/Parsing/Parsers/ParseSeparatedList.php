<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;
use Throwable;
use Warrant\DSL\Lexing\TokenType;

/**
 * Items with a separator token between them, `a, b, c`, for as long as a
 * separator follows an item; {@see Parser::NOTHING} when not even the first
 * item is here. A caller that takes none reads the NOTHING as an empty list,
 * and one that needs at least one reports it.
 *
 * A separator promises another item, so an item missing after one is an error
 * at that spot rather than the end of the list.
 *
 * @template T
 * @extends Parser<list<T>>
 */
final class ParseSeparatedList extends Parser
{
    /**
     * @param class-string<Parser<T>>|Parser<T> $item
     * @param Closure(): Throwable $missingAfterSeparator The error for an item
     *   missing after a separator, built only when one is.
     * @param string|null $part The part of the node around them each item is,
     *   as that node names it, noted by the item's index; null to note none.
     */
    public function __construct(
        private readonly string|Parser $item,
        private readonly TokenType $separator,
        private readonly Closure $missingAfterSeparator,
        private readonly ?string $part = null,
    ) {
    }

    /**
     * @return list<T>|NoMatch
     */
    protected function read(): array|NoMatch
    {
        $first = $this->peek();
        $item = $this->parse($this->item);

        if ($item === null) {
            return self::NOTHING;
        }

        $items = [];

        while (true) {
            $items[] = $item->value;

            if ($this->part !== null) {
                $this->part($this->part, count($items) - 1, $first);
            }

            if (! $this->check($this->separator)) {
                return $items;
            }

            $this->advance();
            $first = $this->peek();
            $item = $this->parse($this->item) ?? throw ($this->missingAfterSeparator)();
        }
    }
}
