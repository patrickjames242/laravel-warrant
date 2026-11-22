<?php

declare(strict_types=1);

namespace Warrant\Guard;

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\Guard\Concerns\AnalyzesReachability;
use Warrant\Guard\Concerns\BuildsAccessQueries;
use Warrant\Guard\Concerns\ChecksAbilities;
use Warrant\Guard\Concerns\DiagnosesDenials;
use Warrant\Guard\Concerns\ReadsAbilitySchema;
use Warrant\Guard\Concerns\ResolvesCheckTargets;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\WarrantManager;

/**
 * The Warrant authorization engine bound to one (schema, user) pair. Everything
 * user-scoped lives here: boolean and throwing checks, ability listing, query
 * filtering / per-row ability selection, denial diagnosis, and reachability. The
 * {@see IAbilitySchema} it wraps supplies only the definition (abilities,
 * conditions, context policy, and the author override hooks).
 *
 * Because the guard is fixed to one user and one schema, no method takes a user
 * and none takes a schema. Its rules come from {@see WarrantManager::ruleSet()},
 * which memoizes them per (schema, user), so a guard holds nothing worth keeping
 * and is cheap to build again. Reach one through the facade:
 * `Warrant::forSchema($schemaOrModel[, $user])`.
 *
 * The engine is split across concerns:
 *  - {@see ReadsAbilitySchema}  — what the schema's definition says about a request;
 *  - {@see ChecksAbilities}     — can/canAny/cannot/authorize/authorizeAny/abilities;
 *  - {@see ResolvesCheckTargets}— reading the caller's `$target` argument;
 *  - {@see BuildsAccessQueries} — turning the rule set into SQL access predicates;
 *  - {@see DiagnosesDenials}    — turning a denied check into a message/exception;
 *  - {@see AnalyzesReachability}— the structural "could they ever?" analysis.
 */
final class WarrantGuardForSchema
{
    use ReadsAbilitySchema;
    use ChecksAbilities;
    use ResolvesCheckTargets;
    use BuildsAccessQueries;
    use DiagnosesDenials;
    use AnalyzesReachability;

    /**
     * @param string $schemaKey The key $schema is registered under, which its rules
     *   use for its own rows and its messages give it.
     */
    public function __construct(
        private readonly IAbilitySchema $schema,
        private readonly string $schemaKey,
        private readonly Authenticatable $user,
        private readonly WarrantManager $manager,
    ) {
    }

    /**
     * The schema definition this guard evaluates against.
     */
    public function schema(): IAbilitySchema
    {
        return $this->schema;
    }

    /**
     * The key the schema is registered under.
     */
    public function schemaKey(): string
    {
        return $this->schemaKey;
    }

    /**
     * The user this guard evaluates for.
     */
    public function user(): Authenticatable
    {
        return $this->user;
    }

    /**
     * This guard's rules, validated and expanded, as
     * {@see WarrantManager::ruleSet()} resolves them for its schema and user.
     */
    public function expandedRuleSet(): ExpandedSyntax
    {
        return $this->manager->ruleSet($this->schemaKey, $this->user);
    }
}
