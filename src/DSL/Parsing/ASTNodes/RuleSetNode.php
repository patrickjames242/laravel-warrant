<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

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

        return new self(
            new NameNode($schemaKey, SyntaxLocation::noProducer()),
            array_map(static fn (WarrantRuleBuilder $builder): WarrantRuleNode => $builder->toRule(), $builders),
            SyntaxLocation::noProducer(),
        );
    }

    public function children(): array
    {
        return [
            $this->schemaKey,
            ...$this->entries,
        ];
    }
}
