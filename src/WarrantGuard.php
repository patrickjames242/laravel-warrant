<?php

declare(strict_types=1);

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Schema\WarrantSchema;

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
     * way the registry understands: a {@see WarrantSchema} instance or class-string,
     * a `Model` instance or class-string, or a schema key.
     */
    public function forSchema(Model|WarrantSchema|string $schema): WarrantGuardForSchema
    {
        /** @var class-string<WarrantSchema> $schemaClass */
        $schemaClass = $this->manager->registry()->resolveSchemaClassOrFail($schema);

        /* Keyed by class string, not schema key: the class is already a unique
           identifier, so this needs no reverse lookup in the schema index. */
        return $this->schemaGuards[$schemaClass] ??= new WarrantGuardForSchema(
            new $schemaClass,
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

    public function reachabilityOf(Model|WarrantSchema|string $schema, string $ability): Reachability
    {
        return $this->forSchema($schema)->reachabilityOf($ability);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHave(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->couldEverHave($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function couldEverHaveAny(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->couldEverHaveAny($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHas(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->alwaysHas($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function alwaysHasAny(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->alwaysHasAny($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHas(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->neverHas($abilities);
    }

    /**
     * @param string|array<int, string> $abilities
     */
    public function neverHasAny(Model|WarrantSchema|string $schema, string|array $abilities): bool
    {
        return $this->forSchema($schema)->neverHasAny($abilities);
    }

    /**
     * @return array<int, string>
     */
    public function possibleAbilities(Model|WarrantSchema|string $schema): array
    {
        return $this->forSchema($schema)->possibleAbilities();
    }

    /**
     * @return array<int, string>
     */
    public function guaranteedAbilities(Model|WarrantSchema|string $schema): array
    {
        return $this->forSchema($schema)->guaranteedAbilities();
    }

    /**
     * @return array<int, string>
     */
    public function impossibleAbilities(Model|WarrantSchema|string $schema): array
    {
        return $this->forSchema($schema)->impossibleAbilities();
    }

    /**
     * Split a schema-less target into the schema reference and the row target the
     * schema-bound guard understands:
     *  - a `Model` instance names the schema and is the row;
     *  - a `[ModelClass|SchemaClass, $id]` tuple names the schema and a row by key;
     *  - a model/schema class-string (or schema key) names the schema, no row.
     *
     * @param Model|string|array<int, mixed> $target
     * @return array{0: Model|WarrantSchema|string, 1: Model|string|null}
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

            if (! is_string($id) && ! is_int($id)) {
                throw new InvalidArgumentException('A tuple target key must be a string or integer id.');
            }

            return [$schemaOrModelClass, (string) $id];
        }

        return [$target, null];
    }
}
