<?php

declare(strict_types=1);

namespace Warrant\Guard;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Reachability;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\ISchema;
use Warrant\WarrantManager;

/**
 * The Warrant authorization engine bound to a user but not to a schema. Every
 * check names its schema through the target — a `Model` instance, a model/schema
 * class-string (no-target), or a `[ModelClass|SchemaClass, $id]` tuple (a row by
 * key) — and each method resolves that schema and defers to the matching
 * {@see WarrantGuardForSchema}.
 *
 * Reachability methods take the schema (class, key, or instance) directly, since
 * they are structural and need no row.
 *
 * Reach one through the facade: `Warrant::guard($user)` (or omit the user for the
 * current one).
 */
final class WarrantGuard
{
    /** @var array<string, WarrantGuardForSchema> */
    private array $schemaGuards = [];

    public function __construct(
        private readonly Authenticatable $user,
        private readonly WarrantManager $manager,
    ) {
    }

    /**
     * The engine for one schema and this guard's user. The schema may be named any
     * way the registry understands: a schema instance or class-string, a `Model`
     * instance or class-string, or a schema key. Checks are made only against a
     * schema that declares abilities, an {@see IAbilitySchema}.
     */
    public function forSchema(Model|ISchema|string $schema): WarrantGuardForSchema
    {
        $resolved = $this->manager->registry()->resolveOrFail($schema);

        if (! $resolved->schema instanceof IAbilitySchema) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] declares no abilities, so no check can be made against it; checks are made '
                    .'against a %s.',
                $resolved->key,
                IAbilitySchema::class,
            ));
        }

        return $this->schemaGuards[$resolved->key] ??= new WarrantGuardForSchema(
            $resolved->schema,
            $resolved->key,
            $this->user,
            $this->manager,
        );
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function can(string|array $abilities, Model|string|array $target, array $context = []): bool
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        return $this->forSchema($schema)->can($abilities, $rowTarget, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function canAny(string|array $abilities, Model|string|array $target, array $context = []): bool
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        return $this->forSchema($schema)->canAny($abilities, $rowTarget, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function cannot(string|array $abilities, Model|string|array $target, array $context = []): bool
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        return $this->forSchema($schema)->cannot($abilities, $rowTarget, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorize(string|array $abilities, Model|string|array $target, array $context = []): void
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        $this->forSchema($schema)->authorize($abilities, $rowTarget, $context);
    }

    /**
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorizeAny(string|array $abilities, Model|string|array $target, array $context = []): void
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        $this->forSchema($schema)->authorizeAny($abilities, $rowTarget, $context);
    }

    /**
     * @return array<int, string>
     */
    public function abilities(Model|string|array $target, array $context = []): array
    {
        [$schema, $rowTarget] = $this->splitTarget($target);

        return $this->forSchema($schema)->abilities($rowTarget, $context);
    }

    public function reachabilityOf(Model|ISchema|string $schema, string $ability): Reachability
    {
        return $this->forSchema($schema)->reachabilityOf($ability);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHave(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->couldEverHave($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHaveAny(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->couldEverHaveAny($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHas(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->alwaysHas($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHasAny(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->alwaysHasAny($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHas(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->neverHas($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHasAny(Model|ISchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->neverHasAny($abilities);
    }

    /**
     * @return array<int, string>
     */
    public function possibleAbilities(Model|ISchema|string $schema): array
    {
        return $this->forSchema($schema)->possibleAbilities();
    }

    /**
     * @return array<int, string>
     */
    public function guaranteedAbilities(Model|ISchema|string $schema): array
    {
        return $this->forSchema($schema)->guaranteedAbilities();
    }

    /**
     * @return array<int, string>
     */
    public function impossibleAbilities(Model|ISchema|string $schema): array
    {
        return $this->forSchema($schema)->impossibleAbilities();
    }

    /**
     * Split a schema-less target into the schema reference and the row target the
     * schema-bound guard understands:
     *  - a `Model` instance names the schema and is the row;
     *  - a `[ModelClass|SchemaClass, $id]` tuple names the schema and a row by key,
     *    where `$id` may itself be a list — the arguments a schema's row key takes
     *    when it has several parts;
     *  - a model/schema class-string (or schema key) names the schema, no row.
     *
     * @param Model|string|array<int, mixed> $target
     * @return array{0: Model|ISchema|string, 1: Model|string|int|array<int, mixed>|null}
     */
    private function splitTarget(Model|string|array $target): array
    {
        if ($target instanceof Model) {
            return [$target, $target];
        }

        if (is_array($target)) {
            if (count($target) !== 2 || ! is_string($target[0] ?? null)) {
                throw new InvalidArgumentException(
                    'A tuple target must be [ModelClass|SchemaClass, $id] — a schema/model class-string and a key.'
                );
            }

            [$schemaOrModelClass, $id] = $target;

            if (is_array($id)) {
                /* Empty included: that is a row addressed by a key taking no
                   arguments, and a key that does require some rejects it itself. */
                $id = array_values($id);
            } elseif (! is_string($id) && ! is_int($id)) {
                throw new InvalidArgumentException(
                    'A tuple target key must be a string or integer id, or a list of the arguments the '
                        .'schema\'s row key takes.'
                );
            }

            /* Handed on as written — an int id stays an int, so it reaches the
               schema's row key without the database having to coerce a bound
               string. */
            return [$schemaOrModelClass, $id];
        }

        return [$target, null];
    }
}
