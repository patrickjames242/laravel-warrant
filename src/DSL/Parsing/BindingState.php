<?php

namespace Warrant\DSL\Parsing;

/**
 * Resolves placeholders (`:name` and `?`) to concrete values, one at a time in
 * the order they were written, and enforces the binding rules:
 *
 *  - Named and positional bindings may not be mixed in one text.
 *  - Every `:name` must have a matching binding; every `?` must have a value.
 *  - Every provided binding must be referenced by at least one placeholder.
 *
 * Each placeholder is given by the byte offset it was written at in $source,
 * which is where an error about it points.
 */
final class BindingState
{
    private const MODE_NONE = 0;
    private const MODE_NAMED = 1;
    private const MODE_POSITIONAL = 2;

    private int $mode = self::MODE_NONE;

    /** @var list<mixed> Positional binding values in order. */
    private array $positional;

    private int $positionalCursor = 0;

    /** @var array<string, true> Named binding keys that have been referenced. */
    private array $usedNamed = [];

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function __construct(
        private readonly string $source,
        private readonly array $bindings,
    ) {
        $this->positional = array_values($bindings);
    }

    public function resolveNamed(string $name, int $offset): mixed
    {
        if ($this->mode === self::MODE_POSITIONAL) {
            throw WarrantSyntaxException::atSourceOffset(DiagnosticCode::MixedBindings, 'Cannot mix named and positional bindings.', $this->source, $offset);
        }

        $this->mode = self::MODE_NAMED;

        if (! array_key_exists($name, $this->bindings)) {
            throw WarrantSyntaxException::atSourceOffset(DiagnosticCode::MissingBindingValue, sprintf('No binding provided for ":%s".', $name), $this->source, $offset);
        }

        $this->usedNamed[$name] = true;

        return $this->bindings[$name];
    }

    public function resolvePositional(int $offset): mixed
    {
        if ($this->mode === self::MODE_NAMED) {
            throw WarrantSyntaxException::atSourceOffset(DiagnosticCode::MixedBindings, 'Cannot mix named and positional bindings.', $this->source, $offset);
        }

        $this->mode = self::MODE_POSITIONAL;

        if ($this->positionalCursor >= count($this->positional)) {
            throw WarrantSyntaxException::atSourceOffset(DiagnosticCode::MissingBindingValue, 'More positional placeholders (?) than bindings provided.', $this->source, $offset);
        }

        return $this->positional[$this->positionalCursor++];
    }

    /**
     * Assert that every provided binding was consumed. Called once every
     * placeholder is resolved; an error points at the end of the source.
     */
    public function finalize(): void
    {
        $end = strlen($this->source);

        if ($this->mode === self::MODE_POSITIONAL) {
            $remaining = count($this->positional) - $this->positionalCursor;

            if ($remaining > 0) {
                throw WarrantSyntaxException::atSourceOffset(
                    DiagnosticCode::UnusedBinding,
                    sprintf('%d positional binding(s) were provided but never used.', $remaining),
                    $this->source,
                    $end,
                );
            }

            return;
        }

        // Named mode, or no placeholders were used at all: any provided binding
        // that went unreferenced is an error.
        $unused = array_diff(array_keys($this->bindings), array_keys($this->usedNamed));

        if ($unused !== []) {
            throw WarrantSyntaxException::atSourceOffset(
                DiagnosticCode::UnusedBinding,
                sprintf('Binding(s) provided but never used: %s.', implode(', ', $unused)),
                $this->source,
                $end,
            );
        }
    }
}
