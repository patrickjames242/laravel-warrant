<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;

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
     * every rule and include held directly here should name its own; a generic
     * one would grant or deny nothing. The node holds the entries as they were
     * written, and the validator and the expander hold them to that.
     *
     * @param NameNode $schemaKey The schema the `for` header names.
     * @param list<IRuleEntryNode> $entries
     */
    public function __construct(
        public NameNode $schemaKey,
        public array $entries,
        public SyntaxLocation $location,
    ) {
        foreach ($entries as $entry) {
            if (! $entry instanceof IRuleEntryNode) {
                throw new InvalidArgumentException(sprintf(
                    'A rule set holds rules, ability blocks and includes, got %s.',
                    get_debug_type($entry),
                ));
            }

        }
    }

    public function schemaKey(): string
    {
        return $this->schemaKey->name;
    }

    /**
     * This rule set's entries followed by $other's, as one rule set. The schema
     * keys must match. The merged set was written nowhere, so it is built in
     * code; its entries keep where each was written.
     */
    public function mergeWith(RuleSetNode $other): self
    {
        if ($this->schemaKey() !== $other->schemaKey()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot merge rule sets for different schemas: [%s] and [%s].',
                $this->schemaKey(),
                $other->schemaKey(),
            ));
        }

        return new self($this->schemaKey, [...$this->entries, ...$other->entries], SyntaxLocation::builtBy(SyntaxProducer::code()));
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
     * (which is finalized via toRule()). The set is built in code.
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

        return new self(
            new NameNode($schemaKey, SyntaxLocation::builtBy(SyntaxProducer::code())),
            $flattened,
            SyntaxLocation::builtBy(SyntaxProducer::code()),
        );
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
}
