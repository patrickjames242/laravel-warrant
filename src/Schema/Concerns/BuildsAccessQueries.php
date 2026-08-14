<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Warrant\AbilityMatchMode;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\RuleSetCompiler;
use Warrant\RuleSyntaxTree\RuleSetValidator;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\WarrantAuthorizationException;
use Warrant\WarrantDenialContext;

/**
 * The SQL runtime: turns the resolved {@see WarrantRuleSet} into access-control
 * predicates and attaches them to entity queries (row filtering and per-row
 * ability selection). All condition SQL is produced by the {@see RuleSetCompiler}.
 */
trait BuildsAccessQueries
{
    /**
     * Restricts the provided entity query to rows the current user can access.
     *
     * `AbilityMatchMode::ALL` requires every requested ability to match for a row.
     * `AbilityMatchMode::ANY` allows a row through if any requested ability matches.
     */
    public function filterQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $targetSqlId,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): Builder
    {
        $abilities = $this->normalizeAbilities($abilities);

        if ($abilities === []) {
            return $query;
        }

        $context = $this->resolveEffectiveContext($context);
        $ruleSet = $this->resolveRuleSet($currentUser);

        return $query->where(function (Builder $outerWhereClause) use (
            $abilities,
            $matchMode,
            $currentUser,
            $query,
            $targetSqlId,
            $ruleSet,
            $context,
        ) {
            foreach ($abilities as $ability) {
                $abilityConditionQuery = $this->buildAbilityConditionQuery(
                    currentUser: $currentUser,
                    query: $query,
                    targetSqlId: $targetSqlId,
                    ability: $ability,
                    ruleSet: $ruleSet,
                    context: $context,
                );

                if ($matchMode === AbilityMatchMode::ALL) {
                    $outerWhereClause->addNestedWhereQuery($abilityConditionQuery);
                } else {
                    $outerWhereClause->addNestedWhereQuery($abilityConditionQuery, 'or');
                }

            }
        });
    }

    /**
     * Adds a computed abilities column to every row in the provided entity query.
     *
     * The computed column contains a JSON array of abilities the current user has
     * for that specific row. The base row selection is preserved by ensuring `*`
     * is selected when needed.
     *
     * @param array<int, string>|null $onlyAbilities When given, compute only these
     *   per-row abilities instead of the full declared set. Use it when the UI
     *   gates on a known subset (e.g. just `update` for an Edit button): the
     *   attached subquery grows one UNION branch per ability, so narrowing it from
     *   all abilities to one is a large per-row cost reduction on list endpoints.
     */
    public function selectAbilitiesInQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $targetSqlId,
        string $selectedAbilitiesKey = 'abilities',
        ?array $onlyAbilities = null,
        array $context = []
    ): Builder
    {
        if ($query->columns === null) {
            $query->select('*');
        }

        $abilities = $onlyAbilities === null
            ? static::declaredAbilities()
            : static::normalizeAbilities($onlyAbilities);

        if ($abilities === []) {
            return $query->selectRaw("'[]' as {$selectedAbilitiesKey}");
        }

        $context = $this->resolveEffectiveContext($context);

        $abilitySelectQuery = $this->buildAvailableAbilitiesQuery(
            currentUser: $currentUser,
            query: $query,
            abilities: $abilities,
            targetSqlId: $targetSqlId,
            context: $context
        );

        /* The JSON aggregate function differs per driver; default to an empty
           array (json_agg/json_arrayagg yield null over an empty set). */
        $wrappedAbility = $query->getGrammar()->wrap('ability');
        $driver = $query->getConnection()->getDriverName();
        $abilitiesJsonAggregate = match ($driver) {
            'pgsql' => "coalesce(json_agg({$wrappedAbility}), '[]'::json)",
            'mysql', 'mariadb' => "coalesce(json_arrayagg({$wrappedAbility}), json_array())",
            'sqlite' => "coalesce(json_group_array({$wrappedAbility}), json_array())",
            default => throw new RuntimeException(
                sprintf('Warrant ability selection does not support the [%s] database driver.', $driver)
            ),
        };

        $aggregateQuery = $query->newQuery()
            ->fromSub($abilitySelectQuery, 'available_abilities')
            ->selectRaw($abilitiesJsonAggregate);

        $query->selectSub(
            $aggregateQuery,
            $selectedAbilitiesKey
        );

        return $query;
    }

    /**
     * Returns the abilities the current user can perform without a target.
     *
     * The evaluation uses only conditions that do not require a target SQL id
     * (targeted conditions are forced false). When abilities are provided
     * explicitly, `AbilityMatchMode::ALL` returns an empty array unless every
     * requested ability matches in that context.
     *
     * @return array<int, string>
     */
    public function getAbilitiesWithoutTarget(
        Authenticatable $currentUser,
        string|array|null $abilities = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ANY,
        array $context = []
    ): array
    {
        $requestedAbilities = $abilities === null
            ? static::declaredAbilities()
            : $this->normalizeAbilities($abilities);

        if ($requestedAbilities === []) {
            return [];
        }

        $context = $this->resolveEffectiveContext($context);

        /* A connection to evaluate the ability predicates on (rule-set lookup
           itself is the resolver's job, on its own connection). No-target
           conditions may reference tenant tables, so a capability schema uses
           the default connection — the current tenant under tenancy. */
        $connection = static::model !== ''
            ? (new (static::model))->getConnection()
            : app('db')->connection();
        $baseQuery = $connection->query();
        $allowedAbilityQuery = $this->buildAvailableAbilitiesQuery(
            currentUser: $currentUser,
            query: $baseQuery,
            abilities: $requestedAbilities,
            context: $context
        );

        $allowedAbilities = $baseQuery->newQuery()
            ->fromSub($allowedAbilityQuery, 'available_abilities')
            ->pluck('ability')
            ->all();

        if (
            $matchMode === AbilityMatchMode::ALL
            && count($allowedAbilities) !== count($requestedAbilities)
        ) {
            return [];
        }

        return $allowedAbilities;
    }

    /**
     * Diagnose which rule denied a check and build the exception to throw. Runs
     * only on the denial path (after a normal check already returned false), so
     * its extra queries never touch the grant path.
     *
     * For each individually-denied ability (requested order) we surface the
     * earliest message-bearing `cannot` rule (resolver order — implicit rules
     * first) whose condition matches. Only a `cannot` can be the cause: under
     * deny-overrides a matching `cannot` is the sufficient, unique reason for a
     * denial, whereas "no `can` granted" is the absence of a grant and names no
     * rule.
     *
     * With a singular target the query is rebuilt with global scopes removed —
     * matching how the singular check itself reads the row (`getQuery()` returns
     * the base query before Eloquent applies scopes), so diagnosis never
     * disagrees with the decision it explains. With no target only global /
     * unconditional `cannot` rules can match; a targeted condition is forced
     * false without a row, exactly as in the check.
     *
     * Returns null when no rule is attributable (missing row, or a "no `can`
     * granted" denial with no matching message-bearing `cannot`), in which case
     * the caller throws a generic exception.
     *
     * @param string|array<int, string> $abilities
     * @param array<string, mixed> $context
     */
    protected function diagnoseDenial(
        Authenticatable $currentUser,
        string|array $abilities,
        Model|string|null $target,
        array $context = []
    ): ?Throwable
    {
        $abilities = $this->normalizeAbilities($abilities);

        if ($abilities === []) {
            return null;
        }

        $context = $this->resolveEffectiveContext($context);
        $ruleSet = $this->resolveRuleSet($currentUser);
        $compiler = $this->compiler();

        if ($target !== null) {
            /** @var Model $model */
            $model = new (static::model);
            $targetId = $target instanceof Model ? $target->getKey() : $target;
            $targetSqlId = $model->getQualifiedKeyName();
            $baseQuery = fn (): Builder => $model->newQueryWithoutScopes()->whereKey($targetId)->getQuery();

            // Nothing to blame if the row does not exist even without scopes.
            if (! $baseQuery()->exists()) {
                return null;
            }

            $targetModel = $target instanceof Model
                ? $target
                : $model->newQueryWithoutScopes()->whereKey($targetId)->first();
        } else {
            // No target: evaluate the ability/condition predicates against a bare
            // one-row query on the entity's connection, exactly like the no-target
            // check does. targetSqlId is null, so targeted conditions force false.
            $connection = static::model !== ''
                ? (new (static::model))->getConnection()
                : app('db')->connection();
            $targetSqlId = null;
            $baseQuery = fn (): Builder => $connection->query();
            $targetModel = null;
        }

        // Which requested abilities are individually denied?
        $deniedAbilities = [];
        foreach ($abilities as $ability) {
            $query = $baseQuery();
            $predicate = $this->buildAbilityConditionQuery(
                currentUser: $currentUser,
                query: $query,
                targetSqlId: $targetSqlId,
                ability: $ability,
                ruleSet: $ruleSet,
                context: $context,
            );

            $granted = $query
                ->selectRaw('1')
                ->where(fn (Builder $where) => $where->addNestedWhereQuery($predicate))
                ->exists();

            if (! $granted) {
                $deniedAbilities[] = $ability;
            }
        }

        if ($deniedAbilities === []) {
            return null;
        }

        foreach ($deniedAbilities as $ability) {
            foreach ($ruleSet->rules as $rule) {
                if ($rule->message === null || ! $this->ruleDeniesAbility($rule, $ability)) {
                    continue;
                }

                $query = $baseQuery();
                $predicate = $compiler->matchesCondition(
                    $currentUser,
                    $query,
                    $rule->conditions,
                    $targetSqlId,
                    $context,
                );

                $matches = $query
                    ->selectRaw('1')
                    ->where(fn (Builder $where) => $where->addNestedWhereQuery($predicate))
                    ->exists();

                if ($matches) {
                    return $this->buildDenialException($rule, $currentUser, $targetModel, $ability, $context);
                }
            }
        }

        return null;
    }

    /**
     * Whether $rule's `cannot` clause lists $ability (exact match or `*`).
     */
    private function ruleDeniesAbility(WarrantRule $rule, string $ability): bool
    {
        return in_array($ability, $rule->cannotAbilities, true)
            || in_array('*', $rule->cannotAbilities, true);
    }

    /**
     * Resolve a rule's message into the Throwable to throw. A string is wrapped in
     * a {@see WarrantAuthorizationException}; a closure receives the denial context
     * and returns either a string (wrapped) or a Throwable (thrown as-is). Any
     * other closure return falls back to a generic denial (null).
     *
     * @param array<string, mixed> $context
     */
    private function buildDenialException(
        WarrantRule $rule,
        Authenticatable $currentUser,
        ?Model $targetModel,
        string $ability,
        array $context,
    ): ?Throwable
    {
        $denialContext = new WarrantDenialContext(
            user: $currentUser,
            target: $targetModel,
            ability: $ability,
            schema: static::class,
            context: $context,
            rule: $rule,
        );

        $message = $rule->message;

        if (is_string($message)) {
            return new WarrantAuthorizationException($message, $denialContext);
        }

        $result = $message($denialContext);

        if ($result instanceof Throwable) {
            return $result;
        }

        if (is_string($result)) {
            return new WarrantAuthorizationException($result, $denialContext);
        }

        return null;
    }

    /**
     * Resolve and validate the rule set that governs this user's access to the
     * managed entity.
     */
    protected function resolveRuleSet(Authenticatable $currentUser): WarrantRuleSet
    {
        $resolver = app(RuleResolver::class);

        $ruleSet = $resolver->resolve(new RuleResolutionContext(
            schemaKey: static::schemaKey(),
            schema: static::class,
            user: $currentUser,
            model: static::model !== '' ? static::model : null,
        ));

        $implicitRules = $this->implicitRules();

        if ($implicitRules !== []) {
            $ruleSet = new WarrantRuleSet($ruleSet->schemaKey, [
                ...$implicitRules,
                ...$ruleSet->rules,
            ]);
        }

        (new RuleSetValidator($this))->validate($ruleSet);

        return $ruleSet;
    }

    protected function compiler(): RuleSetCompiler
    {
        return new RuleSetCompiler($this);
    }

    /**
     * @param array<int, string> $abilities
     */
    protected function buildAvailableAbilitiesQuery(
        Authenticatable $currentUser,
        Builder $query,
        array $abilities,
        ?string $targetSqlId = null,
        array $context = []
    ): Builder
    {
        $abilitySelectQuery = null;
        $ruleSet = $this->resolveRuleSet($currentUser);

        foreach ($abilities as $ability) {
            $singleAbilitySelectQuery = $query->newQuery()
                ->selectRaw('? as "ability"', [$ability]);

            $abilityConditionQuery = $this->buildAbilityConditionQuery(
                currentUser: $currentUser,
                query: $query,
                targetSqlId: $targetSqlId,
                ability: $ability,
                ruleSet: $ruleSet,
                context: $context,
            );

            $singleAbilitySelectQuery->where(
                fn(Builder $abilityWhereClause) => $abilityWhereClause->addNestedWhereQuery($abilityConditionQuery)
            );

            if ($abilitySelectQuery === null) {
                $abilitySelectQuery = $singleAbilitySelectQuery;
            } else {
                $abilitySelectQuery->unionAll($singleAbilitySelectQuery);
            }

        }

        return $abilitySelectQuery;
    }

    protected function buildAbilityConditionQuery(
        Authenticatable $currentUser,
        Builder $query,
        string $ability,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = []
    ): Builder
    {
        return $this->compiler()->compileAbility(
            $currentUser,
            $query,
            $ability,
            $ruleSet,
            $targetSqlId,
            $context,
        );
    }

    /**
     * Merge the explicitly-passed context over the schema's {@see defaultContext},
     * then enforce that every required context key is present. Explicit values win
     * over defaults; partial explicit context is allowed. Throws when a
     * `#[ContextKey(required: true)]` key is missing from the effective context —
     * for every check on the schema, so a required frame can never be silently
     * skipped (which would lift a context-gated `cannot`).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function resolveEffectiveContext(array $context): array
    {
        $effective = array_merge($this->defaultContext(), $context);

        $missing = array_values(array_diff(static::requiredContextKeys(), array_keys($effective)));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().',
                static::class,
                implode(', ', $missing),
            ));
        }

        return $effective;
    }
}
