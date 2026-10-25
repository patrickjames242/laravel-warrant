<?php

namespace Warrant\DSL\Validation;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\Node;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\Span;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * The checks a {@see SyntaxValidator} makes of a text's bindings, when its
 * {@see ValidationInput} says to: that they fit the values given for them. Each
 * binding is checked against the source it was read from.
 *
 * @internal
 */
trait ValidatesBindings
{
    private const BINDING_IN_ARGUMENT_SLOT = 'argument';
    private const BINDING_IN_SQL_BODY_SLOT = 'sql body';
    private const BINDING_IN_DENIAL_MESSAGE_SLOT = 'denial message';
    private const BINDING_IN_ABILITY_LIST_SLOT = 'ability list';
    private const BINDING_IN_ONE_ABILITY_SLOT = 'one ability';

    /**
     * The bindings of each text $syntax was read from fit the values given for
     * them: every placeholder has a value, every value given is used, and an
     * `@sql` body, a denial message, an ability list or the ability a
     * `can(...)` asks about has a value that can stand there.
     */
    private function assertBindingsFit(WarrantSyntax $syntax): void
    {
        if (! $this->input->checkBindingValues) {
            return;
        }

        $sources = [spl_object_id($syntax->source) => $syntax->source];
        $bindingsBySource = [spl_object_id($syntax->source) => []];

        $bindings = array_merge([], ...array_map(static fn (Node $child): array => self::bindingsIn($child), $syntax->children));

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
        foreach ($bindings as [$binding, $slot]) {
            if (! $binding->hasValue()) {
                $this->fail(
                    DiagnosticCode::MissingBindingValue,
                    sprintf('No binding provided for ":%s".', $binding->name),
                    $binding->location,
                );

                continue;
            }

            $this->assertValueFits($binding, $slot);
        }

        $this->assertEveryValueUsed($source, $bindings);
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

        $abilityMistake = match ($slot) {
            self::BINDING_IN_ABILITY_LIST_SLOT => AbilityMistakes::inBoundList($binding),
            self::BINDING_IN_ONE_ABILITY_SLOT => AbilityMistakes::inBoundAbility($binding),
            default => null,
        };

        if ($abilityMistake !== null) {
            $this->fail(DiagnosticCode::InvalidBindingValue, $abilityMistake, $binding->location);
        }
    }

    /**
     * Every value given for $source's text is used by one of its $bindings. A
     * mistake points at the end of the text, since no placeholder is to blame.
     *
     * @param list<array{0: BindingNode, 1: string}> $bindings
     */
    private function assertEveryValueUsed(SyntaxSource $source, array $bindings): void
    {
        $unused = array_diff(
            array_keys($source->bound->bindings ?? []),
            array_map(static fn (array $found): string => $found[0]->name, $bindings),
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
     * reference, a denial message, an ability list, the ability a `can(...)`
     * asks about, or else an argument.
     *
     * @return list<array{0: BindingNode, 1: string}>
     */
    private static function bindingsIn(Node $node, string $slot = self::BINDING_IN_ARGUMENT_SLOT): array
    {
        if ($node instanceof BindingNode) {
            return [[$node, $slot]];
        }

        $found = [];

        foreach ($node->children() as $child) {
            $found = [...$found, ...self::bindingsIn($child, match (true) {
                $node instanceof SqlRef && $child === $node->sql => self::BINDING_IN_SQL_BODY_SLOT,
                ($node instanceof CanClauseNode || $node instanceof CannotClauseNode) && $child === $node->message => self::BINDING_IN_DENIAL_MESSAGE_SLOT,
                ($node instanceof CanClauseNode || $node instanceof CannotClauseNode
                    || $node instanceof AbilityBlockNode || $node instanceof IncludeInvocationNode)
                    && in_array($child, $node->abilities, true) => self::BINDING_IN_ABILITY_LIST_SLOT,
                $node instanceof CrossSchemaCanNode && $child === $node->ability => self::BINDING_IN_ONE_ABILITY_SLOT,
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
