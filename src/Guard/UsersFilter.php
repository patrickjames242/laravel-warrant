<?php

declare(strict_types=1);

namespace Warrant\Guard;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Compiling\Units\GateUnit;
use Warrant\Guard\Concerns\ReadsAbilitySchema;
use Warrant\Guard\Concerns\ResolvesCheckTargets;
use Warrant\Schema\Conditions\UserRow;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\Schema\Types\Interfaces\ISchema;
use Warrant\WarrantGate;
use Warrant\WarrantManager;

/**
 * Narrows a query over users to those who hold abilities on one schema's row, or
 * on the schema itself for abilities with no target.
 *
 * It answers for every user at once, so it is not a guard: there is no one user.
 * The schema's rules are resolved for no particular user, its conditions are
 * handed a null `$c->user`, and `$c->userColumn()` names a column of each row of
 * the users query. Rules a provider picks per user in PHP are not part of the
 * answer: only what the provider returns for a null user is.
 *
 * A row target becomes a correlated `exists` over the schema's rows, narrowed to
 * that row by the schema's row matcher, with the abilities compiled inside it:
 *
 *     select * from "users" where exists (
 *         select * from "documents"
 *         where "documents"."id" = 100
 *           and ("documents"."team_id" = "users"."team_id")
 *     )
 *
 * Inside it the target is a real row, so a condition builds its predicate as it
 * would for any check. With no target, the abilities compile straight onto the
 * users query, where a row condition answers unknown as in any no-target check.
 */
final class UsersFilter
{
    use ReadsAbilitySchema;
    use ResolvesCheckTargets;

    /**
     * @param string $schemaKey The key $schema is registered under.
     */
    private function __construct(
        private readonly IAbilitySchema $schema,
        private readonly string $schemaKey,
        private readonly WarrantManager $manager,
    ) {
    }

    /**
     * The filter for the schema $schema names, any way the registry understands
     * one.
     */
    public static function forSchema(WarrantManager $manager, Model|ISchema|string $schema): self
    {
        $resolved = $manager->registry()->resolveAbilitySchemaOrFail($schema);

        /** @var IAbilitySchema $abilitySchema */
        $abilitySchema = $resolved->schema;

        return new self($abilitySchema, $resolved->key, $manager);
    }

    /**
     * $users narrowed to the users who hold $abilities on $target: a row of this
     * schema, named as a check names one, or null for abilities with no target.
     *
     * @param string|array<int, string> $abilities
     * @param Model|string|int|array<int, mixed>|null $target
     * @param array<string, mixed> $context
     * @param string|null $rowsAs The name the users query's rows go by, for a
     *   query whose `from` does not say. Null reads it from the `from`.
     */
    public function apply(
        EloquentBuilder $users,
        string|array $abilities,
        Model|string|int|array|null $target = null,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = [],
        ?string $rowsAs = null,
    ): EloquentBuilder {
        $abilities = $this->normalizeAbilities($abilities);

        if ($abilities === []) {
            return $users;
        }

        $target = $this->resolveCheckTarget($target);
        $context = $this->contextPolicy()->resolveEffectiveContext($context);
        $this->contextPolicy()->assertAbilitiesHaveRequiredContext($abilities, $context);

        $usersQuery = $users->getQuery();
        $usersQueries = QueryFactory::for($usersQuery, $rowsAs);
        $usersQualifier = $usersQueries->rowQualifier() ?? $users->getModel()->getTable();
        $userRow = UserRow::forUsersQuery($usersQualifier, $usersQueries);

        $gate = new GateUnit(
            new WarrantGate($abilities, $matchMode),
            $this->manager->ruleSet($this->schemaKey, null),
        );

        /* Every compile starts from a scope that reserves the users query's
           qualifier, so no frame inside it takes that name and hides the rows
           `userColumn()` reads. */
        if ($target === null) {
            $this->compiler()->compile(
                (new CompilationContext($gate, $usersQueries, $userRow))
                    ->withoutTarget()
                    ->withCheckContext($context)
                    ->withAliases($this->schema instanceof IRowSchema
                        ? AliasScope::root($this->schemaKey, null, $usersQualifier)
                        : AliasScope::none($usersQualifier)),
            )->spliceInto($usersQuery);

            return $users;
        }

        $this->assertSupportsTargetedChecks();

        /** @var IRowSchema $schema */
        $schema = $this->schema;

        if ($schema->connection()->getName() !== $usersQueries->connectionName()) {
            throw new InvalidArgumentException(sprintf(
                'Cannot filter users by schema [%s]: it is on database connection [%s] but the users query runs on [%s].',
                $this->schemaKey,
                $schema->connection()->getName(),
                $usersQueries->connectionName(),
            ));
        }

        /* The target's rows take the schema's own name unless the users query
           already goes by it, as it does when users are filtered by what they may
           do to a user. They are selected into a fresh query on the users query's
           connection, which the schema shares: `fromRows()` brings the schema's
           own source and its bindings, once. */
        $rowName = AliasScope::none($usersQualifier)->freeQualifier($schema->rowName());
        $rows = $schema->fromRows($usersQuery->newQuery(), $rowName);

        if (! $this->narrowQueryToTarget($rows, $target, $context, $userRow, $rowName)) {
            $usersQuery->whereRaw('1 = 0');

            return $users;
        }

        $this->compiler()->compile(
            (new CompilationContext($gate, QueryFactory::for($rows, $rowName), $userRow))
                ->forTargetRow($this->trustedTargetModel($target))
                ->withCheckContext($context)
                ->withAliases(AliasScope::root($this->schemaKey, $rowName, $usersQualifier)),
        )->spliceInto($rows);

        $usersQuery->addWhereExistsQuery($rows);

        return $users;
    }

    private function compiler(): RuleSetCompiler
    {
        return new RuleSetCompiler($this->schema, $this->schemaKey, $this->manager);
    }
}
