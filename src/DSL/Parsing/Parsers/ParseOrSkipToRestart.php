<?php

namespace Warrant\DSL\Parsing\Parsers;

use Closure;

/**
 * What an item read, or in an analysis, where the item has an error,
 * {@see Parser::SKIPPED} for the text stepped over to where reading can start
 * again. See {@see Parser::parseOrSkip()}.
 *
 * @template T
 * @extends Parser<T|Skipped>
 */
final class ParseOrSkipToRestart extends Parser
{
    /**
     * @param class-string<Parser<T>>|Parser<T> $item
     * @param Closure(): bool $restartsHere Whether reading can start again at
     *   the current token.
     */
    public function __construct(
        private readonly string|Parser $item,
        private readonly Closure $restartsHere,
    ) {
    }

    protected function read(): mixed
    {
        return $this->parseOrSkip($this->item, $this->restartsHere)?->value ?? self::NOTHING;
    }
}
