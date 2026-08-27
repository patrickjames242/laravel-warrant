<?php

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Schema\WarrantSchema;

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
 * Bound as a singleton and reached through the Warrant facade.
 */
class WarrantManager
{
    public function __construct(private readonly SchemaRegistry $registry)
    {
    }

    /**
     * The schema registry: model/schema-key/schema-class maps and their resolvers.
     */
    public function registry(): SchemaRegistry
    {
        return $this->registry;
    }

    /**
     * The authorization engine for a user (defaults to the current user). Reach a
     * schema-bound engine through it: `Warrant::guard($user)->forSchema(...)`.
     */
    public function guard(?Authenticatable $user = null): WarrantGuard
    {
        return new WarrantGuard($this->resolveUser($user), $this);
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

    public function reachabilityOf(Model|WarrantSchema|string $schema, string $ability, ?Authenticatable $user = null): Reachability
    {
        return $this->guard($user)->reachabilityOf($schema, $ability);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHave(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->couldEverHave($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHaveAny(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->couldEverHaveAny($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHas(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->alwaysHas($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHasAny(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->alwaysHasAny($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHas(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->neverHas($schema, $abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHasAny(Model|WarrantSchema|string $schema, string|array $abilities, ?Authenticatable $user = null): bool
    {
        return $this->guard($user)->neverHasAny($schema, $abilities);
    }

    /**
     * @return array<int, string>
     */
    public function possibleAbilities(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): array
    {
        return $this->guard($user)->possibleAbilities($schema);
    }

    /**
     * @return array<int, string>
     */
    public function guaranteedAbilities(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): array
    {
        return $this->guard($user)->guaranteedAbilities($schema);
    }

    /**
     * @return array<int, string>
     */
    public function impossibleAbilities(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): array
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
