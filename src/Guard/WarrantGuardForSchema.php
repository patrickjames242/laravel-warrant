<?php

declare(strict_types=1);

namespace Warrant\Guard;

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Guard\Concerns\AnalyzesReachability;
use Warrant\Guard\Concerns\BuildsAccessQueries;
use Warrant\Guard\Concerns\ChecksAbilities;
use Warrant\Guard\Concerns\DiagnosesDenials;
use Warrant\Guard\Concerns\ResolvesCheckTargets;
use Warrant\Guard\Concerns\ResolvesRuleSets;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

/**
 * The Warrant authorization engine bound to one (schema, user) pair. Everything
 * user-scoped lives here: boolean and throwing checks, ability listing, query
 * filtering / per-row ability selection, denial diagnosis, and reachability. The
 * {@see WarrantSchema} it wraps supplies only the definition (abilities,
 * conditions, context policy, and the author override hooks).
 *
 * Because the guard is fixed to one user and one schema, no method takes a user
 * and none takes a schema, and the resolved {@see \Warrant\Rules\WarrantRuleSet}
 * is memoized once for the instance. Reach one through the facade:
 * `Warrant::forSchema($schemaOrModel[, $user])`.
 *
 * The engine is split across concerns mirroring the schema's own layout:
 *  - {@see ChecksAbilities}     — can/canAny/cannot/authorize/authorizeAny/abilities;
 *  - {@see ResolvesCheckTargets}— reading the caller's `$target` argument;
 *  - {@see ResolvesRuleSets}    — resolving + memoizing the ordered rule set;
 *  - {@see BuildsAccessQueries} — turning the rule set into SQL access predicates;
 *  - {@see DiagnosesDenials}    — turning a denied check into a message/exception;
 *  - {@see AnalyzesReachability}— the structural "could they ever?" analysis.
 */
final class WarrantGuardForSchema
{
    use ChecksAbilities;
    use ResolvesCheckTargets;
    use ResolvesRuleSets;
    use BuildsAccessQueries;
    use DiagnosesDenials;
    use AnalyzesReachability;

    public function __construct(
        private readonly WarrantSchema $schema,
        private readonly Authenticatable $user,
        private readonly WarrantManager $manager,
    ) {
    }

    /**
     * The schema definition this guard evaluates against.
     */
    public function schema(): WarrantSchema
    {
        return $this->schema;
    }

    /**
     * The user this guard evaluates for.
     */
    public function user(): Authenticatable
    {
        return $this->user;
    }
}
