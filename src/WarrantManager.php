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
 * Guards are memoized per user for the life of the manager, so the rule set a
 * {@see WarrantGuardForSchema} resolves is resolved and validated once rather
 * than once per check. See {@see flush()} for the lifetime that implies.
 *
 * Bound as a singleton and reached through the Warrant facade.
 */
class WarrantManager
{
    /**
     * The cap on memoized guards. A long-lived process that sweeps many users
     * (a queue job checking access for every account) would otherwise grow the
     * map without bound. Past the cap the map is dropped whole rather than
     * evicted entry by entry: guards are cheap to rebuild, and a predictable
     * reset beats LRU bookkeeping on the check hot path.
     */
    private const MAX_MEMOIZED_GUARDS = 1000;

    /** @var array<string, WarrantGuard> Memoized guards, keyed by user. */
    private array $guards = [];

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
     * The authorization engine for a user (defaults to the current user).
     */
    public function guard(?Authenticatable $user = null): WarrantGuard
    {
        $user = $this->resolveUser($user);
        $key = $this->guardCacheKey($user);

        if (isset($this->guards[$key])) {
            return $this->guards[$key];
        }

        if (count($this->guards) >= self::MAX_MEMOIZED_GUARDS) {
            $this->guards = [];
        }

        return $this->guards[$key] = new WarrantGuard($user, $this);
    }

    /**
     * Drop memoized guards, and with them the rule sets those guards resolved.
     *
     * Given a user, only that user's guard is dropped; given nothing, the whole
     * memo is cleared. Note that `$user` is *not* defaulted to the authenticated
     * user the way the check methods default theirs — a bare `flush()` means
     * "everyone", and silently narrowing that to the current user would make the
     * common case (a rule change affecting many users) quietly wrong.
     *
     * The memo lives as long as the manager singleton — under PHP-FPM, one
     * request. Long-lived runtimes (Octane, queue workers, tinker) reuse the
     * container across requests and jobs, so the service provider flushes at
     * those boundaries. Call this directly whenever rules change *within* a
     * single request or test: the memo is keyed by user identity, not by rule
     * content, so it cannot notice a role change on its own.
     *
     * Flushing a user whose identifier is absent or non-scalar drops only the
     * instance passed, since such a user is memoized by object identity
     * ({@see guardCacheKey}).
     */
    public function flush(?Authenticatable $user = null): void
    {
        if ($user === null) {
            $this->guards = [];

            return;
        }

        unset($this->guards[$this->guardCacheKey($user)]);
    }

    /**
     * The schema-bound engine for a schema and a user (defaults to the current user).
     * The schema may be named any way the registry understands: a {@see WarrantSchema}
     * instance or class-string, a `Model` instance or class-string, or a schema key.
     */
    public function forSchema(Model|WarrantSchema|string $schema, ?Authenticatable $user = null): WarrantGuardForSchema
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

    /**
     * The memoization key for a user.
     *
     * Two instances of the same persisted user share a guard — rules follow the
     * identifier, not the object. A user whose identifier is absent or non-scalar
     * (an unsaved model) falls back to object identity, which is safe because the
     * memoized guard holds the user instance alive: its object id cannot be
     * recycled while the entry that uses it exists.
     */
    private function guardCacheKey(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        return is_scalar($identifier)
            ? $user::class."\0".$identifier
            : $user::class."\0#".spl_object_id($user);
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
