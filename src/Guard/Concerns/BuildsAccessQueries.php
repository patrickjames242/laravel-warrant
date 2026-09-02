<?php

namespace Warrant\Guard\Concerns;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\Rules\WarrantRuleSet;
use Warrant\WarrantGate;

/**
 * The SQL runtime: turns this guard's resolved {@see WarrantRuleSet} into
 * access-control predicates and attaches them to entity queries (row filtering
 * and per-row ability selection). All condition SQL is produced by the
 * {@see RuleSetCompiler}, which dispatches condition emission back into the
 * schema (the {@see \Warrant\DSL\ConditionResolver}).
 *
 * Producing a predicate and attaching one are separate steps here:
 * {@see compileGateWhereClause} returns the compiled where clause folded, which is a
 * literal `true`/`false` whenever the rules settled the gate without a row.
 * {@see filterQuery} is one consumer of that — it always wants SQL — while a
 * boolean check reads the literal and skips the database entirely.
 *
 * Resolving the rule set itself lives in {@see ResolvesRuleSets}; diagnosing a
 * denial into a message lives in {@see DiagnosesDenials}.
 */
trait BuildsAccessQueries
{
    private ?RuleSetCompiler $compiler = null;

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

        /* The compiler owns the ANY/ALL combination: one predicate for the whole
           gate, spliced onto the host query as a single parenthesized group. A
           gate that folded to a constant is spelled out as `1 = 1` / `1 = 0`,
           since a row filter still has to say something out loud — reach for
           compileGateWhereClause() directly when the constant is the answer you want. */
        return $this->spliceWhereClauseIntoQuery(
            $query,
            $this->compileGateWhereClause($query, $targetSqlId, $abilities, $matchMode, $context),
        );
    }

    /**
     * The gate's compiled where clause, folded but not yet written as SQL —
     * either a literal `true`/`false` when the rules settled the outcome on
     * their own, or the {@see Builder} carrying the predicate.
     *
     * This is the decision {@see filterQuery} throws away: a predicate spliced
     * into a host query has to spell a constant out as `1 = 1` / `1 = 0`, so a
     * caller that only wants a yes/no answer would send SQL to be told what the
     * compiler already knew. Read the literal here and you can skip the query
     * (see {@see ChecksAbilities}); hand whatever you get to
     * {@see spliceWhereClauseIntoQuery} when you do need the SQL, and nothing is
     * compiled twice.
     *
     * Validation matches `filterQuery` exactly — abilities are normalized and
     * their required context asserted — so folding never skips an error a query
     * would have raised. An **empty ability set folds to `true`**, the match-all
     * an empty gate has always meant; callers that treat "nothing requested" as
     * a failure (a boolean check does) handle that before calling.
     *
     * @param string|array<int, string> $abilities
     * @param Model|null $targetModel The loaded row the check names, when there is
     *   exactly one and the caller supplied it. Reaches a row condition as
     *   `$c->model`, letting it answer in PHP; every caller filtering more than one
     *   row leaves this null.
     */
    public function compileGateWhereClause(
        Builder $query,
        ?string $targetSqlId,
        string|array $abilities,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = [],
        ?Model $targetModel = null
    ): bool|Builder
    {
        $abilities = $this->schema->normalizeAbilities($abilities);
        $context = $this->schema->resolveEffectiveContext($context);
        $this->schema::assertAbilitiesHaveRequiredContext($abilities, $context);

        return $this->compiler()->gateWhereClauseNode(
            $this->user,
            $query,
            new WarrantGate($abilities, $matchMode),
            $this->resolvedRuleSet(),
            $targetSqlId,
            $targetModel,
            $context,
        )->buildWhereClause($query);
    }

    /**
     * Attach a where clause from {@see compileGateWhereClause} to its host query
     * as one parenthesized group — the single place a folded gate becomes SQL.
     */
    protected function spliceWhereClauseIntoQuery(Builder $query, bool|Builder $whereClause): Builder
    {
        return $query->addNestedWhereQuery($this->compiler()->materializeWhereClause($query, $whereClause));
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

        /* One branch per ability, each compiled against the target row. Unlike the
           no-target path, nothing is folded away here: a per-row list has to name
           every ability it was asked about, and a constant is per-row too. */
        $ruleSet = $this->resolvedRuleSet();
        $branches = [];

        foreach ($abilities as $ability) {
            $branches[] = [$ability, $this->buildAbilityConditionQuery(
                query: $query,
                targetSqlId: $targetSqlId,
                ability: $ability,
                ruleSet: $ruleSet,
                context: $context,
            )];
        }

        $abilitySelectQuery = $this->unionAbilityBranches($query, $branches);

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
        $ruleSet = $this->resolvedRuleSet();

        /* Without a row to consult, a great many abilities fold outright — every
           targeted condition is forced false, so anything gated on one is settled
           here. Sort those out before building the union: a folded ability needs no
           branch, and with none left there is nothing to ask the database. */
        $held = [];
        $branches = [];

        foreach ($abilities as $ability) {
            $whereClause = $this->compiler()->abilityWhereClauseNode(
                $this->user,
                $baseQuery,
                $ability,
                $ruleSet,
                null,
                null,
                $context,
            )->buildWhereClause($baseQuery);

            if ($whereClause === true) {
                $held[] = $ability;
            } elseif ($whereClause !== false) {
                $branches[] = [$ability, $whereClause];
            }
        }

        if ($branches === []) {
            return $held;
        }

        $queriedAbilities = $baseQuery->newQuery()
            ->fromSub($this->unionAbilityBranches($baseQuery, $branches), 'available_abilities')
            ->pluck('ability')
            ->all();

        /* Merge the two sources back into the order asked for — the caller sees
           one list and cannot tell which abilities took a query to decide. */
        $allowed = [...$held, ...$queriedAbilities];

        return array_values(array_filter(
            $abilities,
            fn (string $ability): bool => in_array($ability, $allowed, true),
        ));
    }

    /**
     * Memoized for the guard's lifetime: a single check now compiles through it
     * more than once (folding, then materializing), and it is stateless anyway.
     */
    protected function compiler(): RuleSetCompiler
    {
        return $this->compiler ??= new RuleSetCompiler($this->schema, $this->manager);
    }

    /**
     * Assemble one `select '<ability>' as "ability" where (<predicate>)` branch
     * per entry, UNION ALL'd into a single query — the row set an ability list is
     * read out of, whether per-row ({@see selectAbilitiesInQuery}) or with no
     * target at all ({@see getAbilitiesWithoutTarget}).
     *
     * Taking the predicates already built lets the no-target path compile once:
     * it folds each ability to decide whether a branch is needed at all, and
     * passes only the survivors here.
     *
     * At least one branch is required — a union of nothing is not a query. Both
     * callers have already established that: the per-row path rejects an empty
     * ability set upstream, and the no-target path returns its folded answer
     * before getting here.
     *
     * @param list<array{string, Builder}> $branches Ability name with its predicate.
     */
    private function unionAbilityBranches(Builder $query, array $branches): Builder
    {
        $abilitySelectQuery = null;

        foreach ($branches as [$ability, $predicate]) {
            $singleAbilitySelectQuery = $query->newQuery()
                ->selectRaw('? as "ability"', [$ability]);

            $singleAbilitySelectQuery->where(
                fn(Builder $abilityWhereClause) => $abilityWhereClause->addNestedWhereQuery($predicate)
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
        array $context = [],
        ?Model $targetModel = null
    ): Builder
    {
        return $this->compiler()->compileAbility(
            $this->user,
            $query,
            $ability,
            $ruleSet,
            $targetSqlId,
            $targetModel,
            $context,
        );
    }
}
