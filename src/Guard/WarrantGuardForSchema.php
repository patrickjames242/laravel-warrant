<?php

declare(strict_types=1);

namespace Warrant\Guard;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Warrant\Guard\Concerns\AnalyzesReachability;
use Warrant\Guard\Concerns\BuildsAccessQueries;
use Warrant\Guard\Concerns\ChecksAbilities;
use Warrant\Guard\Concerns\DiagnosesDenials;
use Warrant\Guard\Concerns\ResolvesCheckTargets;
use Warrant\Guard\Concerns\ResolvesRuleSets;
use Warrant\Schema\Context\ContextPolicy;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\WarrantManager;

/**
 * The Warrant authorization engine bound to one (schema, user) pair. Everything
 * user-scoped lives here: boolean and throwing checks, ability listing, query
 * filtering / per-row ability selection, denial diagnosis, and reachability. The
 * {@see IAbilitySchema} it wraps supplies only the definition (abilities,
 * conditions, context policy, and the author override hooks).
 *
 * Because the guard is fixed to one user and one schema, no method takes a user
 * and none takes a schema, and the resolved {@see \Warrant\DSL\Parsing\ASTNodes\RuleSetNode}
 * is memoized once for the instance. Reach one through the facade:
 * `Warrant::forSchema($schemaOrModel[, $user])`.
 *
 * The engine is split across concerns:
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

    private ?ContextPolicy $contextPolicy = null;

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
     * What the schema's context declarations mean for a check.
     */
    private function contextPolicy(): ContextPolicy
    {
        return $this->contextPolicy ??= new ContextPolicy($this->schema);
    }

    /**
     * The model a model schema names, or null for any other schema.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private function modelClass(): ?string
    {
        return $this->schema instanceof IModelSchema ? $this->schema::model() : null;
    }

    /**
     * The requested abilities as a list, with empty names dropped, after checking
     * the schema declares each one.
     *
     * @param string|array<int, mixed> $abilities
     * @return list<string>
     */
    private function normalizeAbilities(string|array $abilities): array
    {
        $abilities = array_values(array_filter(
            is_array($abilities) ? $abilities : [$abilities],
            static fn (mixed $ability): bool => is_string($ability) && $ability !== '',
        ));

        foreach ($abilities as $ability) {
            if ($this->schema->ability($ability) === null) {
                throw new InvalidArgumentException(
                    sprintf('Ability [%s] is not defined on schema [%s].', $ability, $this->schema::class)
                );
            }
        }

        return $abilities;
    }

    /**
     * A check about one row needs a schema with rows, so a schema without them
     * answers only checks with no target.
     */
    private function assertSupportsTargetedChecks(): void
    {
        if (! $this->schema instanceof IRowSchema) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] has no rows and does not support targeted checks; use a no-target check instead.',
                $this->schema::class,
            ));
        }
    }
}
