<?php

namespace Warrant\DSL\Validation;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\Span;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * The checks a {@see SyntaxValidator} makes of a text's bindings: that they are
 * all named or all positional, and, when its {@see ValidationInput} says to,
 * that they fit the values given for them. Each binding is checked against the
 * source it was read from.
 *
 * @internal
 */
trait ValidatesBindings
{
    private const BINDING_IN_ARGUMENT_SLOT = 'argument';
    private const BINDING_IN_SQL_BODY_SLOT = 'sql body';
    private const BINDING_IN_DENIAL_MESSAGE_SLOT = 'denial message';

    /**
     * The bindings of each text $syntax was read from fit the values given for
     * them. A text's bindings are either all named or all positional, since its
     * values are given one way or the other: the first binding written decides,
     * and each of the other kind is a mistake. When the input checks binding
     * values, every placeholder has a value, every value given is used, and an
     * `@sql` body or a denial message has a value that can stand there.
     */
    private function assertBindingsFit(WarrantSyntax $syntax): void
    {
        $sources = [spl_object_id($syntax->source) => $syntax->source];
        $bindingsBySource = [spl_object_id($syntax->source) => []];

        $bindings = array_merge([], ...array_map(static fn (INode $child): array => self::bindingsIn($child), $syntax->children));

        foreach ($bindings as [$binding, $slot]) {
            $source = $binding->location->source;
            $sources[spl_object_id($source)] = $source;
            $bindingsBySource[spl_object_id($source)][] = [$binding, $slot];
        }

        foreach ($bindingsBySource as $sourceId => $bindings) {
            usort($bindings, static fn (array $a, array $b): int => $a[0]->location->span?->offset <=> $b[0]->location->span?->offset);

            $this->assertBindingsOfOneTextFit($sources[$sourceId], $bindings);
        }
    }

    /**
     * @param list<array{0: BindingNode, 1: string}> $bindings Every binding read
     *   from $source, with the slot it is written in, in the order written.
     */
    private function assertBindingsOfOneTextFit(SyntaxSource $source, array $bindings): void
    {
        $first = $bindings[0][0] ?? null;

        foreach ($bindings as [$binding, $slot]) {
            if ($binding->isPositional() !== $first->isPositional()) {
                $this->fail(DiagnosticCode::MixedBindings, 'Cannot mix named and positional bindings.', $binding->location);

                continue;
            }

            if (! $this->input->checkBindingValues) {
                continue;
            }

            if (! $binding->hasValue()) {
                $this->fail(
                    DiagnosticCode::MissingBindingValue,
                    $binding->isPositional()
                        ? 'More positional placeholders (?) than bindings provided.'
                        : sprintf('No binding provided for ":%s".', $binding->name),
                    $binding->location,
                );

                continue;
            }

            $this->assertValueFits($binding, $slot);
        }

        if ($this->input->checkBindingValues) {
            $this->assertEveryValueUsed($source, $bindings, $first?->isPositional() ?? false);
        }
    }

    private function assertValueFits(BindingNode $binding, string $slot): void
    {
        $value = $binding->value();

        if ($slot === self::BINDING_IN_SQL_BODY_SLOT && ! is_string($value)) {
            $this->fail(
                DiagnosticCode::InvalidBindingValue,
                sprintf('An @sql binding must resolve to a string, got %s.', get_debug_type($value)),
                $binding->location,
            );
        }

        if ($slot === self::BINDING_IN_DENIAL_MESSAGE_SLOT && ! is_string($value) && ! $value instanceof Closure) {
            $this->fail(
                DiagnosticCode::InvalidBindingValue,
                sprintf('A denial message must be a string or a closure, got %s.', get_debug_type($value)),
                $binding->location,
            );
        }
    }

    /**
     * Every value given for $source's text is used by one of its $bindings. A
     * mistake points at the end of the text, since no placeholder is to blame.
     *
     * @param list<array{0: BindingNode, 1: string}> $bindings
     * @param bool $positional Whether the text's bindings are positional, as its
     *   first one decides.
     */
    private function assertEveryValueUsed(SyntaxSource $source, array $bindings, bool $positional): void
    {
        $values = $source->bound->bindings ?? [];
        $used = array_filter($bindings, static fn (array $found): bool => $found[0]->isPositional() === $positional);

        if ($positional) {
            $unused = count($values) - count($used);

            if ($unused > 0) {
                $this->fail(
                    DiagnosticCode::UnusedBinding,
                    sprintf('%d positional binding(s) were provided but never used.', $unused),
                    self::endOf($source),
                );
            }

            return;
        }

        $unused = array_diff(
            array_keys($values),
            array_map(static fn (array $found): string => $found[0]->name, $used),
        );

        if ($unused !== []) {
            $this->fail(
                DiagnosticCode::UnusedBinding,
                sprintf('Binding(s) provided but never used: %s.', implode(', ', $unused)),
                self::endOf($source),
            );
        }
    }

    /**
     * Every binding in $node, with the slot it is written in: the body of an `@sql`
     * reference, a denial message, or else an argument.
     *
     * @return list<array{0: BindingNode, 1: string}>
     */
    private static function bindingsIn(INode $node, string $slot = self::BINDING_IN_ARGUMENT_SLOT): array
    {
        if ($node instanceof BindingNode) {
            return [[$node, $slot]];
        }

        $found = [];

        foreach ($node->children() as $child) {
            $found = [...$found, ...self::bindingsIn($child, match (true) {
                $node instanceof SqlRef && $child === $node->sql => self::BINDING_IN_SQL_BODY_SLOT,
                ($node instanceof CanClauseNode || $node instanceof CannotClauseNode) && $child === $node->message => self::BINDING_IN_DENIAL_MESSAGE_SLOT,
                default => self::BINDING_IN_ARGUMENT_SLOT,
            })];
        }

        return $found;
    }

    /**
     * The end of the text of $source, where a mistake about the text as a whole
     * is placed.
     */
    private static function endOf(SyntaxSource $source): SyntaxLocation
    {
        $end = strlen($source->bound->syntax ?? '');

        return SyntaxLocation::in($source, new Span($end, $end));
    }
}
