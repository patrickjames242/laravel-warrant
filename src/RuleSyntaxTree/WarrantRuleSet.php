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
    public string $schemaKey;

    /**
     * The schema this rule set targets may be given as a schema key string, a
     * {@see WarrantSchema} instance or class-string, or a {@see Model} instance or
     * class-string; it is normalized to the schema key.
     *
     * @param Model|WarrantSchema|string $schema
     * @param array<int, WarrantRule> $rules
     */
    public function __construct(
        Model|WarrantSchema|string $schema,
        public array $rules,
    ){
        $this->schemaKey = Warrant::resolveSchemaKey($schema);
    }

    /**
     * Build a rule set by parsing raw Warrant syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     *
     * @param Model|WarrantSchema|string $schema
     */
    public static function fromSyntax(
        Model|WarrantSchema|string $schema,
        string $syntax,
        array $bindings = [],
    ): self {
        return new self($schema, WarrantParser::parse($syntax, $bindings));
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
     * Render every rule back to the string DSL with scalar condition parameters
     * inlined as literals, one blank line between rules. Throws if a parameter
     * has no inline representation — use {@see toBoundSyntax()} for those.
     * Round-trips via `WarrantRuleSet::fromSyntax($this->schemaKey, $syntax)`.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::toSyntax(...$this->rules);
    }

    /**
     * Render every rule to `?`-parameterized syntax plus one flat, left-to-right
     * positional bindings list spanning the whole set. Lossless for any value.
     * Round-trips via
     * `WarrantRuleSet::fromSyntax($this->schemaKey, $result->syntax, $result->bindings)`.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::toBoundSyntax(...$this->rules);
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