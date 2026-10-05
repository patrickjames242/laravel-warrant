<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\Writing\BoundSyntax;
use Warrant\DSL\Parsing\Writing\RuleSyntaxWriter;

/**
 * The rules for one schema: the body of a `for <schema>` header, or rules given
 * their schema by the caller. The body is rule entries in source order — rules,
 * ability blocks and `@include` directives.
 *
 * The schema key is a plain key. Resolving a model or schema class to one is the
 * caller's business, which keeps a rule set constructible without a booted
 * application.
 */
final readonly class RuleSetNode implements ISchemaScopedNode
{
    /**
     * Outside an ability block nothing names abilities on a clause's behalf, so
     * every rule and include held directly here names its own. A headless one
     * would grant or deny nothing.
     *
     * @param list<IRuleEntryNode> $entries
     */
    public function __construct(
        public string $schemaKey,
        public array $entries = [],
    ) {
        foreach ($entries as $entry) {
            if (! $entry instanceof IRuleEntryNode) {
                throw new InvalidArgumentException(sprintf(
                    'A rule set holds rules, ability blocks and includes, got %s.',
                    get_debug_type($entry),
                ));
            }

            $headless = match (true) {
                $entry instanceof WarrantRuleNode => $entry->hasHeadlessClause(),
                $entry instanceof IncludeInvocationNode => $entry->isHeadless(),
                default => false,
            };

            if ($headless) {
                throw new InvalidArgumentException(sprintf(
                    'Every clause and @include outside an ability block names the abilities it applies to; '
                        .'the rule set for [%s] holds one that names none.',
                    $schemaKey,
                ));
            }
        }
    }

    public function schemaKey(): string
    {
        return $this->schemaKey;
    }

    /**
     * The entries with every ability block opened up, in source order, each
     * block's header applied to its entries. The flattened list grants and denies
     * exactly what the blocks do.
     *
     * @return list<WarrantRuleNode|IncludeInvocationNode>
     */
    public function flatEntries(): array
    {
        $flat = [];

        foreach ($this->entries as $entry) {
            if ($entry instanceof AbilityBlockNode) {
                array_push($flat, ...$entry->expand());

                continue;
            }

            /** @var WarrantRuleNode|IncludeInvocationNode $entry */
            $flat[] = $entry;
        }

        return $flat;
    }

    /**
     * The rules, ability blocks opened up and includes left out.
     *
     * @return list<WarrantRuleNode>
     */
    public function rules(): array
    {
        return array_values(array_filter(
            $this->flatEntries(),
            static fn (IRuleEntryNode $entry): bool => $entry instanceof WarrantRuleNode,
        ));
    }

    /**
     * The `@include` directives, ability blocks opened up.
     *
     * @return list<IncludeInvocationNode>
     */
    public function includes(): array
    {
        return array_values(array_filter(
            $this->flatEntries(),
            static fn (IRuleEntryNode $entry): bool => $entry instanceof IncludeInvocationNode,
        ));
    }

    /**
     * This rule set's entries followed by $other's, as one rule set. The schema
     * keys must match.
     */
    public function mergeWith(RuleSetNode $other): self
    {
        if ($this->schemaKey !== $other->schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'Cannot merge rule sets for different schemas: [%s] and [%s].',
                $this->schemaKey,
                $other->schemaKey,
            ));
        }

        return new self($this->schemaKey, [...$this->entries, ...$other->entries]);
    }

    /**
     * Merge two or more rule sets for the same schema into one, in argument order.
     */
    public static function merge(RuleSetNode $first, RuleSetNode ...$rest): self
    {
        return array_reduce(
            $rest,
            static fn (self $carry, self $next): self => $carry->mergeWith($next),
            $first,
        );
    }

    /**
     * Build a rule set from already-resolved rules. Accepts a variadic list or a
     * single array, and each element may be a WarrantRuleNode or a WarrantRuleBuilder
     * (which is finalized via toRule()).
     *
     * @param WarrantRuleNode|WarrantRuleBuilder|array<int, WarrantRuleNode|WarrantRuleBuilder> ...$rules
     */
    public static function fromRules(string $schemaKey, WarrantRuleNode|WarrantRuleBuilder|array ...$rules): self
    {
        $flattened = [];

        foreach ($rules as $rule) {
            foreach (is_array($rule) ? $rule : [$rule] as $one) {
                if ($one instanceof WarrantRuleBuilder) {
                    $one = $one->toRule();
                }

                if (! $one instanceof WarrantRuleNode) {
                    throw new InvalidArgumentException(sprintf(
                        'fromRules expects WarrantRuleNode or WarrantRuleBuilder instances, got %s.',
                        get_debug_type($one),
                    ));
                }

                $flattened[] = $one;
            }
        }

        return new self($schemaKey, $flattened);
    }

    /**
     * Build a rule set with a callback, one rule per `$rule()` call.
     *
     * The callback receives a factory; each invocation of it appends a fresh
     * {@see WarrantRuleBuilder} to the set and returns it for chaining. Rules are
     * finalized automatically — there is no need to call toRule().
     *
     * ```php
     * RuleSetNode::build('timesheets', function ($rule) {
     *     $rule()->if('is_self')->theyCan('edit', 'view');
     *     $rule()->theyCan('list');
     * });
     * ```
     *
     * @param Closure(callable():WarrantRuleBuilder):void $callback
     */
    public static function build(string $schemaKey, Closure $callback): self
    {
        $builders = [];

        $make = function () use (&$builders): WarrantRuleBuilder {
            return $builders[] = new WarrantRuleBuilder;
        };

        $callback($make);

        return self::fromRules($schemaKey, $builders);
    }

    /**
     * Render the rule set as a `for <schema> { ... }` block with scalar condition
     * parameters inlined as literals. Throws if a parameter has no inline
     * representation — use {@see toBoundSyntax()} for those.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::ruleSetToSyntax($this);
    }

    /**
     * Render the rule set to a `for <schema> { ... }` block with `?`-parameterized
     * values plus one flat, left-to-right positional bindings list. Lossless for
     * any value.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::ruleSetToBoundSyntax($this);
    }
}
