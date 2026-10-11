<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Lexing\Token;

/**
 * Resolves placeholder arguments (`:name` and `?`) to concrete values as the
 * parser encounters them, and enforces the binding rules:
 *
 *  - Named and positional bindings may not be mixed in one parse.
 *  - Every `:name` must have a matching binding; every `?` must have a value.
 *  - Every provided binding must be referenced by at least one placeholder.
 *
 * Text being analysed for an editor has no values to bind;
 * {@see placeholdersAsTheirOwnText()} makes a state for it, where only the
 * first rule applies.
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
     * @param bool $placeholdersAsTheirOwnText Whether there are no values at
     *   all, and each placeholder resolves to its own text.
     */
    public function __construct(
        private readonly string $source,
        private readonly array $bindings,
        private readonly bool $placeholdersAsTheirOwnText = false,
    ) {
        $this->positional = array_values($bindings);
    }

    /**
     * The state for text whose values are not known, as an editor has it. Each
     * placeholder resolves to its own text, `:name` or `?`: a string, because a
     * denial message and an `@sql` body must be one, and the text says which
     * placeholder it stands for.
     */
    public static function placeholdersAsTheirOwnText(string $source): self
    {
        return new self($source, [], placeholdersAsTheirOwnText: true);
    }

    public function resolveNamed(Token $token): mixed
    {
        if ($this->mode === self::MODE_POSITIONAL) {
            throw WarrantSyntaxException::at('Cannot mix named and positional bindings.', $this->source, $token);
        }

        $this->mode = self::MODE_NAMED;

        if ($this->placeholdersAsTheirOwnText) {
            return $token->lexeme;
        }

        $name = $token->value;

        if (! array_key_exists($name, $this->bindings)) {
            throw WarrantSyntaxException::at(sprintf('No binding provided for ":%s".', $name), $this->source, $token);
        }

        $this->usedNamed[$name] = true;

        return $this->bindings[$name];
    }

    public function resolvePositional(Token $token): mixed
    {
        if ($this->mode === self::MODE_NAMED) {
            throw WarrantSyntaxException::at('Cannot mix named and positional bindings.', $this->source, $token);
        }

        $this->mode = self::MODE_POSITIONAL;

        if ($this->placeholdersAsTheirOwnText) {
            return $token->lexeme;
        }

        if ($this->positionalCursor >= count($this->positional)) {
            throw WarrantSyntaxException::at('More positional placeholders (?) than bindings provided.', $this->source, $token);
        }

        return $this->positional[$this->positionalCursor++];
    }

    /**
     * Assert that every provided binding was consumed. Called once parsing is done.
     */
    public function finalize(Token $eof): void
    {
        if ($this->placeholdersAsTheirOwnText) {
            return;
        }

        if ($this->mode === self::MODE_POSITIONAL) {
            $remaining = count($this->positional) - $this->positionalCursor;

            if ($remaining > 0) {
                throw WarrantSyntaxException::at(
                    sprintf('%d positional binding(s) were provided but never used.', $remaining),
                    $this->source,
                    $eof,
                );
            }

            return;
        }

        // Named mode, or no placeholders were used at all: any provided binding
        // that went unreferenced is an error.
        $unused = array_diff(array_keys($this->bindings), array_keys($this->usedNamed));

        if ($unused !== []) {
            throw WarrantSyntaxException::at(
                sprintf('Binding(s) provided but never used: %s.', implode(', ', $unused)),
                $this->source,
                $eof,
            );
        }
    }
}
