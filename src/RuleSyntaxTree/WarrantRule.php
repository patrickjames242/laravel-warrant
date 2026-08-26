<?php

namespace Warrant\RuleSyntaxTree;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Facades\Warrant;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantDenialContext;

readonly class WarrantRule
{
    use NormalizesRuleSchema;

    /**
     * The schema this rule targets, or null when the rule is schema-less. A rule
     * may name its schema via a `for <schema>` header in the syntax or the
     * `$schema` argument to {@see fromSyntax()}; when it is placed in a
     * {@see WarrantRuleSet} the two must agree (see the rule set constructor).
     */
    public ?string $schemaKey;

    /**
     * @param list<string> $canAbilities Granted ability names (or `*`).
     * @param list<CannotClause> $cannotClauses The denied abilities, grouped into
     *   clauses so each group can carry its own denial message. Flatten with
     *   {@see cannotAbilities()}; resolve an ability's message with
     *   {@see messageFor()}. A denial message is only ever surfaced for a matching
     *   `cannot`, so it lives on a clause — a rule that only grants has no place
     *   for one.
     * @param Model|WarrantSchema|string|null $schema The target schema (key,
     *   schema/model instance or class-string), normalized to a key; null leaves
     *   the rule schema-less.
     */
    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canAbilities,
        public array $cannotClauses,
        Model|WarrantSchema|string|null $schema = null,
    ) {
        $this->schemaKey = Warrant::registry()->resolveSchemaKeyOrFail($schema, passThroughNull: true);
    }

    /**
     * Build a single rule by parsing raw Warrant syntax, resolving any
     * named (:name) or positional (?) placeholders against $bindings.
     *
     * The schema may be given by a `for <schema>` header in $syntax and/or the
     * $schema argument; either, both, or neither is allowed, but if both are given
     * they must agree.
     *
     * @param Model|WarrantSchema|string|null $schema
     */
    public static function fromSyntax(
        string $syntax,
        Model|WarrantSchema|string|null $schema = null,
        array $bindings = [],
    ): self {
        $rule = WarrantParser::parseSingleRule($syntax, $bindings);

        $paramKey = Warrant::registry()->resolveSchemaKeyOrFail($schema, passThroughNull: true);

        return $rule->withSchemaKey(self::reconcileSchemaKey($rule->schemaKey, $paramKey, required: false));
    }

    /**
     * Return a copy of this rule targeting $schemaKey (an already-resolved key, or
     * null to clear it). Used to bake a parsed `for` header onto a rule and to
     * reconcile it with the `$schema` argument.
     */
    public function withSchemaKey(?string $schemaKey): self
    {
        return new self($this->conditions, $this->canAbilities, $this->cannotClauses, $schemaKey);
    }

    /**
     * Start a fluent, query-builder-style rule construction.
     */
    public static function build(): WarrantRuleBuilder
    {
        return new WarrantRuleBuilder;
    }

    /**
     * Every denied ability, flattened across all clauses in order. Used by the
     * compiler / reachability / validator as a plain membership list; messages
     * are irrelevant there.
     *
     * @return list<string>
     */
    public function cannotAbilities(): array
    {
        $abilities = [];

        foreach ($this->cannotClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }

    /**
     * Whether this rule denies $ability — it appears in some clause, or a clause
     * denies `*`.
     */
    public function deniesAbility(string $ability): bool
    {
        foreach ($this->cannotClauses as $clause) {
            if (in_array($ability, $clause->abilities, true) || in_array('*', $clause->abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denial message for $ability: the first clause that lists it wins; else
     * the first clause that denies `*`; else null (no message). An exact listing
     * always beats a `*` clause.
     *
     * @return string|Closure(WarrantDenialContext):(string|\Throwable)|null
     */
    public function messageFor(string $ability): string|Closure|null
    {
        $wildcard = null;
        $wildcardFound = false;

        foreach ($this->cannotClauses as $clause) {
            if (in_array($ability, $clause->abilities, true)) {
                return $clause->message;
            }

            if (! $wildcardFound && in_array('*', $clause->abilities, true)) {
                $wildcard = $clause->message;
                $wildcardFound = true;
            }
        }

        return $wildcard;
    }

    public function hasCannot(): bool
    {
        return $this->cannotClauses !== [];
    }

    /**
     * Return a copy of this rule carrying a denial message. Works for any rule,
     * however it was constructed — notably a {@see fromSyntax()} rule, which the
     * inline string DSL can also give a message via `because`.
     *
     * By default the message applies to every denied ability; pass $abilities to
     * scope it to specific ones. A denial message can only ride on a `cannot`, so
     * targeting an ability the rule does not deny — or attaching any message to a
     * rule with no `cannot` clause — throws.
     *
     * @param string|Closure(WarrantDenialContext):(string|\Throwable) $message
     * @param list<string>|null $abilities
     */
    public function withDenialMessage(string|Closure $message, ?array $abilities = null): self
    {
        // Flatten to ability => current message (first clause wins, as messageFor).
        $map = [];

        foreach ($this->cannotClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                $map[$ability] ??= $clause->message;
            }
        }

        $targets = $abilities ?? array_keys($map);

        if ($targets === []) {
            throw new InvalidArgumentException(
                'A denial message requires a `they cannot ...` clause; it can never be surfaced by a rule that only grants.'
            );
        }

        foreach ($targets as $ability) {
            if (! array_key_exists($ability, $map)) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot attach a denial message to ability [%s]: the rule does not deny it.',
                    $ability,
                ));
            }

            $map[$ability] = $message;
        }

        return new self($this->conditions, $this->canAbilities, self::clausesFromMessageMap($map), $this->schemaKey);
    }

    /**
     * Render this rule back to the string DSL with scalar condition parameters
     * inlined as literals. Throws if a parameter has no inline representation —
     * use {@see toBoundSyntax()} for those. Round-trips via {@see fromSyntax()}.
     *
     * Note: a string denial message round-trips as a `because '...'` clause, but
     * a closure message has no inline form and throws here — use
     * {@see toBoundSyntax()}, which carries a closure message as a `?` binding.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::ruleToSyntax($this);
    }

    /**
     * Render this rule to `?`-parameterized syntax plus the positional bindings
     * that fill it. Lossless for any parameter value. Round-trips via
     * `WarrantRule::fromSyntax($result->syntax, bindings: $result->bindings)` (the
     * schema rides along in the rendered `for` header when the rule has one).
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::ruleToBoundSyntax($this);
    }

    /**
     * Rebuild clauses from an ordered ability => message map, grouping abilities
     * that share an identical message (`===`, so a shared closure instance groups
     * together and `null` groups the message-less ones) into one clause, in
     * first-appearance order.
     *
     * @param array<string, string|Closure|null> $map
     * @return list<CannotClause>
     */
    private static function clausesFromMessageMap(array $map): array
    {
        /** @var list<array{message: string|Closure|null, abilities: list<string>}> $groups */
        $groups = [];

        foreach ($map as $ability => $message) {
            $matched = null;

            foreach ($groups as $index => $group) {
                if ($group['message'] === $message) {
                    $matched = $index;
                    break;
                }
            }

            if ($matched === null) {
                $groups[] = ['message' => $message, 'abilities' => [(string) $ability]];
            } else {
                $groups[$matched]['abilities'][] = (string) $ability;
            }
        }

        return array_map(
            static fn (array $group): CannotClause => new CannotClause($group['abilities'], $group['message']),
            $groups,
        );
    }
}
