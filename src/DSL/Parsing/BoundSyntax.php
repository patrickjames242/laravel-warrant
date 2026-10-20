<?php

namespace Warrant\DSL\Parsing;

use InvalidArgumentException;

/**
 * Rule syntax as it was handed over, not yet parsed: the syntax itself, the
 * values for its `:name` bindings, keyed by name, and the file it was read
 * from, if any. It says nothing about where the syntax is used; that is known
 * only to what parses it.
 *
 * The writer makes one too: a tree rendered back to the string DSL with every
 * value extracted as a named binding of the writer's own, paired with the
 * values those bindings are given. `WarrantSyntax::parse($bound)` reconstructs
 * an equivalent tree, with any schema riding in the rendered `for` header.
 */
readonly class BoundSyntax
{
    /**
     * @param array<string, mixed> $bindings
     * @param string|null $path The file the syntax was read from; null for
     *   syntax given in code.
     */
    public function __construct(
        public string $syntax,
        public array $bindings = [],
        public ?string $path = null,
    ) {
    }

    /**
     * The rule syntax in the file at $path. The `.warrant` extension may be left
     * off: a path naming no file is read with `.warrant` appended.
     *
     * @param array<string, mixed> $bindings
     */
    public static function fromFile(string $path, array $bindings = []): self
    {
        if (! is_file($path) && ! str_ends_with($path, '.warrant')) {
            $path .= '.warrant';
        }

        $syntax = @file_get_contents($path);

        if ($syntax === false) {
            throw new InvalidArgumentException(sprintf('Unable to read Warrant rule file [%s].', $path));
        }

        return new self($syntax, $bindings, $path);
    }
}
