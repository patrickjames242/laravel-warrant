<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\Guard\WarrantGuard;
use Warrant\Guard\UsersFilter;
use Warrant\Guard\WarrantGuardForSchema;
use Warrant\Registry\SchemaRegistry;
use Warrant\Rules\RuleSetResolver;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * Entry point for Warrant. Holds the {@see SchemaRegistry} (reached through
 * {@see registry()}) and hands out the authorization engine: {@see guard()}
 * returns a {@see WarrantGuard} for a user, {@see forSchema()} a
 * {@see WarrantGuardForSchema} for a (schema, user) pair.
 *
 * The check/reachability methods below mirror {@see WarrantGuard}'s surface with a
 * trailing nullable `$user` that defaults to the currently authenticated user;
 * each is a one-liner delegating to `$this->guard($user)`.
 *
 * Rule sets are memoized per (schema, user) by the {@see RuleSetResolver} behind
 * {@see ruleSet()}, so a schema's rules are resolved and validated once rather
 * than once per check. Guards hold nothing worth keeping, so each call builds a
 * new one. See {@see flush()} for the lifetime of the memo.
 *
 * Bound as a singleton and reached through the Warrant facade.
 */
class WarrantManager
{
    private readonly RuleSetResolver $ruleSetResolver;

    public function __construct(private readonly SchemaRegistry $registry)
    {
        $this->ruleSetResolver = new RuleSetResolver($registry);
    }

    /**
     * The schema registry: model/schema-key/schema-class maps and their resolvers.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
    }

    /**
     * The authorization engine for a user (defaults to the current user).
     */
    public function guard(?Authenticatable $user = null): WarrantGuard
    {
        return new WarrantGuard($this->resolveUser($user), $this);
    }

    /**
     * The rules for the schema registered under $schemaKey, resolved for exactly
     * $user, where null means no particular user and never the current one. See
     * {@see RuleSetResolver::ruleSet()}.
     */
    public function ruleSet(string $schemaKey, ?Authenticatable $user): ExpandedSyntax
    {
        return $this->ruleSetResolver->ruleSet($schemaKey, $user);
    }

    /**
     * $users narrowed to the users who hold $abilities on $target, which names its
     * schema as a check's target does: a model, a `[ModelClass|SchemaClass, $id]`
     * tuple, or a model or schema class for abilities with no target. See
     * {@see UsersFilter}.
     *
     * @param string|array<int, string> $abilities
     * @param Model|string|array<int, mixed> $target
     * @param array<string, mixed> $context
     * @param string|null $rowsAs The name the users query's rows go by, for a
     *   query whose `from` does not say.
     */
    public function filterUsers(
        EloquentBuilder $users,
        string|array $abilities,
        Model|string|array $target,
        AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
        array $context = [],
        ?string $rowsAs = null,
    ): EloquentBuilder {
        [$schema, $rowTarget] = WarrantGuard::splitTarget($target);

        return UsersFilter::forSchema($this, $schema)
            ->apply($users, $abilities, $rowTarget, $matchMode, $context, $rowsAs);
    }

    /**
     * Drop memoized rule sets: $user's alone, or with no user, every one. See
     * {@see RuleSetResolver::flush()}.
     */
    public function flush(?Authenticatable $user = null): void
    {
        $this->ruleSetResolver->flush($user);
    }

    /**
     * The schema-bound engine for a schema and a user (defaults to the current user).
     * The schema may be named any way the registry understands: a schema instance or
     * class-string, a `Model` instance or class-string, or a schema key.
     */
    public function forSchema(Model|ISchema|string $schema, ?Authenticatable $user = null): WarrantGuardForSchema
    {
        return $this->guard($user)->forSchema($schema);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function can(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->can($abilities, $target, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function canAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->canAny($abilities, $target, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function cannot(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->cannot($abilities, $target, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorize(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void
    {
        $this->guard($user)->authorize($abilities, $target, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorizeAny(string|array $abilities, Model|string|array $target, array $context = [], ?Authenticatable $user = null): void
    {
        $this->guard($user)->authorizeAny($abilities, $target, $context);
    }

    /**
     * @return array<int, string>
     */
    public function abilities(Model|string|array $target, array $context = [], ?Authenticatable $user = null): array
    {
        return $this->guard($user)->abilities($target, $context);
    }

    public function reachabilityOf(Model|ISchema|string $schema, string $ability, ?Authenticatable $user = null): Reachability
    {
        return $this->guard($user)->reachabilityOf($schema, $ability);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHave(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->couldEverHave($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHaveAny(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->couldEverHaveAny($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHas(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->alwaysHas($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHasAny(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->alwaysHasAny($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHas(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->neverHas($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHasAny(Model|ISchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->neverHasAny($schema, $abilities);
    }

    /**
     * @return array<int, string>
     */
    public function possibleAbilities(Model|ISchema|string $schema, ?Authenticatable $user = null): array
    {
        return $this->guard($user)->possibleAbilities($schema);
    }

    /**
     * @return array<int, string>
     */
    public function guaranteedAbilities(Model|ISchema|string $schema, ?Authenticatable $user = null): array
    {
        return $this->guard($user)->guaranteedAbilities($schema);
    }

    /**
     * @return array<int, string>
     */
    public function impossibleAbilities(Model|ISchema|string $schema, ?Authenticatable $user = null): array
    {
        return $this->guard($user)->impossibleAbilities($schema);
    }

    private function resolveUser(?Authenticatable $user): Authenticatable
    {
        $user ??= auth()->user();

        if (! $user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                'Warrant requires an authenticated user or an explicit user instance.'
            );
        }

        return $user;
    }
}
