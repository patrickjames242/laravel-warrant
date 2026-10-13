<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * Items with a separator token between them, `a, b, c`, for as long as a
 * separator follows an item; an empty list when no first item starts here. A
 * caller that needs at least one reports the empty list.
 *
 * A separator promises another item, so an item missing after one is an error
 * at that spot rather than the end of the list. A tolerant parse reports it and
 * ends the list there.
 *
 * @template T
 * @extends Parser<list<T>>
 */
final class ParseSeparatedList extends Parser
{
    /**
     * @param class-string<Parser<T>>|Parser<T> $item What reads each item.
     * @param TokenType $separator The token between one item and the next.
     * @param Closure(): WarrantSyntaxException $missingAfterSeparator The error
     *   for an item missing after a separator, built only when one is.
     * @param string|null $part The part of the node around them each item is,
     *   as that node names it, noted by the item's index; null to note none.
     * @param (Closure(): T)|null $inPlaceOfMissingItem Makes what a tolerant parse
     *   puts at the end of the list in place of an item missing after a
     *   separator, so the items keep their count; null to put nothing there.
     */
    public function __construct(
        private readonly string|Parser $item,
        private readonly TokenType $separator,
        private readonly Closure $missingAfterSeparator,
        private readonly ?string $part = null,
        private readonly ?Closure $inPlaceOfMissingItem = null,
    ) {
    }

    /**
     * @return list<T>
     */
    protected function read(): array
    {
        $first = $this->peek();
        $item = $this->parse($this->item);

        if ($item === null) {
            return [];
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
            $item = $this->parse($this->item);

            // The list ends at the item that is missing, with what stands in for it.
            if ($item === null) {
                $this->report(($this->missingAfterSeparator)());

                if ($this->inPlaceOfMissingItem !== null) {
                    $items[] = ($this->inPlaceOfMissingItem)();
                }

                return $items;
            }
        }
    }
}
