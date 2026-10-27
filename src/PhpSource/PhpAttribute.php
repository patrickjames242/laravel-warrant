<?php

namespace Warrant\PhpSource;

/**
 * One attribute as written on a class member, read from source: its class, and
 * the arguments that are literals.
 */
final readonly class PhpAttribute
{
    /**
     * @param string $name The attribute's class, resolved against the file's
     *   namespace and imports, without a leading backslash.
     * @param list<mixed> $positional The arguments passed by position, each read
     *   as {@see PhpSourceReader::literal()} reads a value.
     * @param array<string, mixed> $named The arguments passed by name, read the
     *   same way.
     */
    public function __construct(
        public string $name,
        public array $positional = [],
        public array $named = [],
    ) {
    }

    /**
     * The argument passed as $name, or else at $position; null when there is
     * none.
     *
     * @param string $name The parameter's name.
     * @param int $position Its 0-based position.
     */
    public function argument(string $name, int $position): mixed
    {
        return $this->named[$name] ?? $this->positional[$position] ?? null;
    }
}
