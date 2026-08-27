<?php

namespace Warrant\Guard\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use RuntimeException;
use Warrant\AbilityMatchMode;
use Warrant\RuleSyntaxTree\RuleSetCompiler;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

/**
 * The SQL runtime: turns this guard's resolved {@see WarrantRuleSet} into
 * access-control predicates and attaches them to entity queries (row filtering
 * and per-row ability selection). All condition SQL is produced by the
 * {@see RuleSetCompiler}, which dispatches condition emission back into the
 * schema (the {@see \Warrant\RuleSyntaxTree\ConditionResolver}).
 *
 * Resolving the rule set itself lives in {@see ResolvesRuleSets}; diagnosing a
 * denial into a message lives in {@see DiagnosesDenials}.
 */
trait BuildsAccessQueries
{
    /**
     * Restricts the provided entity query to rows the guard's user can access.
     *
     * `AbilityMatchMode::ALL` requires every requested ability to match for a row.
     * `AbilityMatchMode::ANY` allows a row through if any requested ability matches.
     */
    public function filterQuery(
        Builder $query,
        string $targetSqlId,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = []
    ): Builder
    {
        $abilities = $this->schema->normalizeAbilities($abilities);

        if ($abilities === []) {
            return $query;
        }

        $context = $this->schema->resolveEffectiveContext($context);
        $this->schema::assertAbilitiesHaveRequiredContext($abilities, $context);
        $ruleSet = $this->resolvedRuleSet();

        return $query->where(function (Builder $outerWhereClause) use (
            $abilities,
            $matchMode,
            $query,
            $targetSqlId,
            $ruleSet,
            $context,
        ) {
            foreach ($abilities as $ability) {
                $abilityConditionQuery = $this->buildAbilityConditionQuery(
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
     * The computed column contains a JSON array of abilities the guard's user has
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

        if ($onlyAbilities === null) {
            $abilities = $this->schema::abilityNames();
        } else {
            $abilities = $this->schema->normalizeAbilities($onlyAbilities);
        }

        if ($abilities === []) {
            return $query->selectRaw("'[]' as {$selectedAbilitiesKey}");
        }

        $context = $this->schema->resolveEffectiveContext($context);

        /* A per-row list enumerates abilities, so any whose per-ability required
           context wasn't supplied is skipped rather than throwing. */
        $abilities = $this->schema::partitionAbilitiesByContext($abilities, $context)['satisfied'];

        if ($abilities === []) {
            return $query->selectRaw("'[]' as {$selectedAbilitiesKey}");
        }

        $abilitySelectQuery = $this->buildAvailableAbilitiesQuery(
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
     * Returns the abilities the guard's user can perform without a target.
     *
     * The evaluation uses only conditions that do not require a target SQL id
     * (targeted conditions are forced false). When abilities are provided
     * explicitly, `AbilityMatchMode::ALL` returns an empty array unless every
     * requested ability matches in that context.
     *
     * @return array<int, string>
     */
    public function getAbilitiesWithoutTarget(
        string|array|null $abilities = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ANY,
        array $context = []
    ): array
    {
        /* Enumeration ($abilities === null): every declared ability the user holds,
           skipping (never throwing) any whose per-ability required context is absent. */
        if ($abilities === null) {
            $context = $this->schema->resolveEffectiveContext($context);

            $declared = $this->schema::partitionAbilitiesByContext($this->schema::abilityNames(), $context)['satisfied'];

            return $declared === []
                ? []
                : $this->runNoTargetAbilityQuery($declared, $context);
        }

        /* Explicit named abilities: a missing per-ability requirement throws. */
        $requestedAbilities = $this->schema->normalizeAbilities($abilities);

        if ($requestedAbilities === []) {
            return [];
        }

        $context = $this->schema->resolveEffectiveContext($context);
        $this->schema::assertAbilitiesHaveRequiredContext($requestedAbilities, $context);

        $allowedAbilities = $this->runNoTargetAbilityQuery($requestedAbilities, $context);

        if (
            $matchMode === AbilityMatchMode::ALL
            && count($allowedAbilities) !== count($requestedAbilities)
        ) {
            return [];
        }

        return $allowedAbilities;
    }

    /**
     * Run the no-target ability predicate for the given (already context-resolved)
     * abilities and return the ones the user holds.
     *
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    private function runNoTargetAbilityQuery(array $abilities, array $context): array
    {
        /* A connection to evaluate the ability predicates on (rule-set lookup
           itself is the resolver's job, on its own connection). No-target
           conditions may reference tenant tables, so a capability schema uses
           the default connection — the current tenant under tenancy. */
        $connection = $this->schema::model !== ''
            ? (new ($this->schema::model))->getConnection()
            : app('db')->connection();
        $baseQuery = $connection->query();
        $allowedAbilityQuery = $this->buildAvailableAbilitiesQuery(
            query: $baseQuery,
            abilities: $abilities,
            context: $context
        );

        return $baseQuery->newQuery()
            ->fromSub($allowedAbilityQuery, 'available_abilities')
            ->pluck('ability')
            ->all();
    }

    protected function compiler(): RuleSetCompiler
    {
        return new RuleSetCompiler($this->schema, $this->manager);
    }

    /**
     * @param array<int, string> $abilities
     */
    protected function buildAvailableAbilitiesQuery(
        Builder $query,
        array $abilities,
        ?string $targetSqlId = null,
        array $context = []
    ): Builder
    {
        $abilitySelectQuery = null;
        $ruleSet = $this->resolvedRuleSet();

        foreach ($abilities as $ability) {
            $singleAbilitySelectQuery = $query->newQuery()
                ->selectRaw('? as "ability"', [$ability]);

            $abilityConditionQuery = $this->buildAbilityConditionQuery(
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
        Builder $query,
        string $ability,
        WarrantRuleSet $ruleSet,
        ?string $targetSqlId = null,
        array $context = []
    ): Builder
    {
        return $this->compiler()->compileAbility(
            $this->user,
            $query,
            $ability,
            $ruleSet,
            $targetSqlId,
            $context,
        );
    }
}
