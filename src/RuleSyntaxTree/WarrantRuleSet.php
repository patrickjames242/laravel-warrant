<?php

namespace Warrant\RuleSyntaxTree;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Facades\Warrant;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\Schema\WarrantSchema;

readonly class WarrantRuleSet
{
    use NormalizesRuleSchema;

    public string $schemaKey;

    /**
     * The schema this rule set targets may be given as a schema key string, a
     * {@see WarrantSchema} instance or class-string, or a {@see Model} instance or
     * class-string; it is normalized to the schema key.
     *
     * Every rule's own schema must be null or equal to this set's schema; a rule
     * that names a different schema is rejected.
     *
     * @param Model|WarrantSchema|string $schema
     * @param array<int, WarrantRule> $rules
     */
    public function __construct(
        Model|WarrantSchema|string $schema,
        public array $rules,
    ){
        $this->schemaKey = Warrant::resolveSchemaKey($schema);

        foreach ($rules as $rule) {
            if ($rule->schemaKey !== null && $rule->schemaKey !== $this->schemaKey) {
                throw new InvalidArgumentException(sprintf(
                    'A rule targets schema [%s] but the rule set targets [%s]; every rule must be null or match the set.',
                    $rule->schemaKey,
                    $this->schemaKey,
                ));
            }
        }
    }

    /**
     * Build a rule set by parsing raw Warrant syntax, resolving any named (:name)
     * or positional (?) placeholders against $bindings.
     *
     * The schema may be given by a `for <schema>` header in $syntax and/or the
     * $schema argument; at least one is required (neither → error), and if both
     * are given they must agree.
     *
     * @param Model|WarrantSchema|string|null $schema
     */
    public static function fromSyntax(
        string $syntax,
        Model|WarrantSchema|string|null $schema = null,
        array $bindings = [],
    ): self {
        $parsed = WarrantParser::parseSingleRuleSet($syntax, $bindings);

        $paramKey = $schema === null ? null : Warrant::resolveSchemaKey($schema);

        $schemaKey = self::reconcileSchemaKey($parsed->schemaKey, $paramKey, required: true);

        return new self($schemaKey, $parsed->rules);
    }

    /**
     * Merge this rule set with another for the same schema, concatenating their
     * rules (this set's first) into a new set. The schema keys must match.
     */
    public function mergeWith(WarrantRuleSet $other): self
    {
        if ($this->schemaKey !== $other->schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'Cannot merge rule sets for different schemas: [%s] and [%s].',
                $this->schemaKey,
                $other->schemaKey,
            ));
        }

        return new self($this->schemaKey, [...$this->rules, ...$other->rules]);
    }

    /**
     * Merge two or more rule sets for the same schema into one, in argument order.
     */
    public static function merge(WarrantRuleSet $first, WarrantRuleSet ...$rest): self
    {
        return array_reduce(
            $rest,
            static fn (self $carry, self $next): self => $carry->mergeWith($next),
            $first,
        );
    }

    /**
     * Build a rule set from already-resolved rules. Accepts a variadic list or a
     * single array, and each element may be a WarrantRule or a WarrantRuleBuilder
     * (which is finalized via toRule()). Does not accept bindings, and does not
     * allow mixing raw syntax with resolved rules.
     *
     * @param Model|WarrantSchema|string $schema
     * @param WarrantRule|WarrantRuleBuilder|array<int, WarrantRule|WarrantRuleBuilder> ...$rules
     */
    public static function fromRules(
        Model|WarrantSchema|string $schema,
        WarrantRule|WarrantRuleBuilder|array ...$rules,
    ): self {
        $flattened = [];

        foreach ($rules as $rule) {
            foreach (is_array($rule) ? $rule : [$rule] as $one) {
                if ($one instanceof WarrantRuleBuilder) {
                    $one = $one->toRule();
                }

                if (! $one instanceof WarrantRule) {
                    throw new InvalidArgumentException(
                        sprintf('fromRules expects WarrantRule or WarrantRuleBuilder instances, got %s.', get_debug_type($one))
                    );
                }

                $flattened[] = $one;
            }
        }

        return new self($schema, $flattened);
    }

    /**
     * Build a rule set with a callback, one rule per `$rule()` call.
     *
     * The callback receives a factory; each invocation of it appends a fresh
     * {@see WarrantRuleBuilder} to the set and returns it for chaining. Rules are
     * finalized automatically — there is no need to call toRule().
     *
     * ```php
     * WarrantRuleSet::build('timesheets', function ($rule) {
     *     $rule()->if('is_self')->theyCan('edit', 'view');
     *     $rule()->theyCan('list');
     * });
     * ```
     *
     * @param Model|WarrantSchema|string $schema
     * @param Closure(callable():WarrantRuleBuilder):void $callback
     */
    public static function build(Model|WarrantSchema|string $schema, Closure $callback): self
    {
        $builders = [];

        $make = function () use (&$builders): WarrantRuleBuilder {
            return $builders[] = new WarrantRuleBuilder;
        };

        $callback($make);

        return self::fromRules($schema, $builders);
    }

    /**
     * Render the set back to the string DSL as a `for <schema> { ... }` block with
     * scalar condition parameters inlined as literals. Throws if a parameter has
     * no inline representation — use {@see toBoundSyntax()} for those. Round-trips
     * via `WarrantRuleSet::fromSyntax($syntax)` (the schema rides in the header).
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::ruleSetToSyntax($this);
    }

    /**
     * Render the set to a `for <schema> { ... }` block with `?`-parameterized
     * conditions plus one flat, left-to-right positional bindings list spanning
     * the whole set. Lossless for any value. Round-trips via
     * `WarrantRuleSet::fromSyntax($result->syntax, bindings: $result->bindings)`.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::ruleSetToBoundSyntax($this);
    }

    /**
     * Validate every condition and ability name against the schema registered
     * for this set's schema key, throwing on the first unknown name. Runs before
     * compilation so mistakes surface loudly rather than silently producing an
     * empty predicate.
     *
     * To validate against a schema you already hold, construct a
     * {@see RuleSetValidator} directly rather than routing through the registry.
     */
    public function validate(): void
    {
        $schemaClass = Warrant::getSchemaForKey($this->schemaKey);

        (new RuleSetValidator(new $schemaClass, $this->schemaKey))->validate($this);
    }

    /**
     * Validate several rule sets, each against the schema registered for its own
     * schema key. Throws on the first unknown name across the whole batch.
     *
     * Accepts a variadic list or arrays of rule sets.
     *
     * @param WarrantRuleSet|array<int, WarrantRuleSet> ...$ruleSets
     */
    public static function validateAll(WarrantRuleSet|array ...$ruleSets): void
    {
        foreach ($ruleSets as $ruleSet) {
            foreach (is_array($ruleSet) ? $ruleSet : [$ruleSet] as $one) {
                if (! $one instanceof self) {
                    throw new InvalidArgumentException(
                        sprintf('validateAll expects WarrantRuleSet instances, got %s.', get_debug_type($one))
                    );
                }

                $one->validate();
            }
        }
    }

}