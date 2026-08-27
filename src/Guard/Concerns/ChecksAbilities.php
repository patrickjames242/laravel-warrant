<?php

namespace Warrant\Guard\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\AbilityMatchMode;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantAuthorizationException;

/**
 * The check front door for a (schema, user) guard. `can`/`canAny` are the boolean
 * checks (ALL vs ANY via the method name); `authorize`/`authorizeAny` are their
 * throwing siblings, diagnosing a denial into a message-bearing exception.
 *
 * `$target` is optional and, when present, must belong to this guard's schema: a
 * `Model` instance, a scalar key, or null / the model|schema class-string for a
 * no-target check.
 */
trait ChecksAbilities
{
    /**
     * Whether the user holds every requested ability (optionally against a target).
     *
     * @param string|array<int, string> $abilities
     */
    public function can(string|array $abilities, Model|string|null $target = null, array $context = []): bool
    {
        return $this->hasAbilities($abilities, $target, AbilityMatchMode::ALL, $context);
    }

    /**
     * Whether the user holds at least one of the requested abilities.
     *
     * @param string|array<int, string> $abilities
     */
    public function canAny(string|array $abilities, Model|string|null $target = null, array $context = []): bool
    {
        return $this->hasAbilities($abilities, $target, AbilityMatchMode::ANY, $context);
    }

    /**
     * The inverse of {@see can}.
     *
     * @param string|array<int, string> $abilities
     */
    public function cannot(string|array $abilities, Model|string|null $target = null, array $context = []): bool
    {
        return ! $this->can($abilities, $target, $context);
    }

    /**
     * Assert the user holds every requested ability, throwing a diagnosed
     * {@see WarrantAuthorizationException} (403) on denial.
     *
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorize(string|array $abilities, Model|string|null $target = null, array $context = []): void
    {
        $this->assertHasAbilities($abilities, $target, AbilityMatchMode::ALL, $context);
    }

    /**
     * Assert the user holds at least one of the requested abilities.
     *
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    public function authorizeAny(string|array $abilities, Model|string|null $target = null, array $context = []): void
    {
        $this->assertHasAbilities($abilities, $target, AbilityMatchMode::ANY, $context);
    }

    /**
     * The abilities the user holds for the given target (or with no target).
     *
     * @return array<int, string>
     */
    public function abilities(Model|string|null $target = null, array $context = []): array
    {
        $target = $this->resolveCheckTarget($target);

        if ($target === null) {
            return $this->getAbilitiesWithoutTarget(context: $context);
        }

        $this->schema::assertSupportsTargetedChecks();

        /** @var Model $model */
        $model = new ($this->schema::model);
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        // selectAbilitiesInQuery adds the abilities list via selectSub aliased
        // AS abilities — NOT a real column on the underlying table. Using
        // ->value('abilities') here would call Laravel's first(['abilities']),
        // whose onceWithColumns mechanism replaces the SELECT clause with
        // ['abilities'], wiping the selectSub and yielding null. Read the
        // hydrated row instead so the alias survives.
        $row = (array) $this->selectAbilitiesInQuery(
            query: $model->newQuery()->whereKey($targetId)->getQuery(),
            targetSqlId: $model->getQualifiedKeyName(),
            context: $context,
        )->first();
        $selectedAbilities = $row['abilities'] ?? null;

        if (is_array($selectedAbilities)) {
            return $selectedAbilities;
        }

        if (! is_string($selectedAbilities) || $selectedAbilities === '') {
            return [];
        }

        $decodedSelectedAbilities = json_decode($selectedAbilities, true);

        return is_array($decodedSelectedAbilities) ? $decodedSelectedAbilities : [];
    }

    /**
     * @param string|array<int, string> $abilities
     */
    private function hasAbilities(
        string|array $abilities,
        Model|string|null $target,
        AbilityMatchMode $matchMode,
        array $context
    ): bool {
        $target = $this->resolveCheckTarget($target);
        $abilities = $this->schema->normalizeAbilities($abilities);

        if ($target !== null) {
            $this->schema::assertSupportsTargetedChecks();

            /** @var Model $model */
            $model = new ($this->schema::model);
            $targetId = $target instanceof Model ? $target->getKey() : $target;

            return $this->filterQuery(
                query: $model->newQuery()->whereKey($targetId)->getQuery(),
                targetSqlId: $model->getQualifiedKeyName(),
                abilities: $abilities,
                matchMode: $matchMode,
                context: $context,
            )->exists();
        }

        /* No target: evaluate to the subset the user holds (under ANY, so it yields
           the held subset rather than an all-or-nothing result), then apply the
           match mode across the whole requested set. */
        return $this->noTargetCheckPasses($abilities, $this->heldNoTargetAbilities($abilities, $context), $matchMode);
    }

    /**
     * @param string|array<int, string> $abilities
     * @throws \Throwable
     */
    private function assertHasAbilities(
        string|array $abilities,
        Model|string|null $target,
        AbilityMatchMode $matchMode,
        array $context
    ): void {
        if ($this->hasAbilities($abilities, $target, $matchMode, $context)) {
            return;
        }

        /* Both the targeted and no-target denials are diagnosed from the rules,
           differing only in whether a row (target) is in play. */
        throw $this->diagnoseDenial($abilities, $this->resolveCheckTarget($target), $matchMode, $context)
            ?? new WarrantAuthorizationException;
    }

    /**
     * The subset of the requested abilities the user holds without a target. Runs
     * under `ANY` so it yields the held subset rather than an all-or-nothing result;
     * the match mode is applied across the full request by {@see noTargetCheckPasses}.
     *
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    private function heldNoTargetAbilities(array $abilities, array $context): array
    {
        return $abilities === []
            ? []
            : $this->getAbilitiesWithoutTarget($abilities, AbilityMatchMode::ANY, $context);
    }

    /**
     * Whether a no-target check passes given the held subset. An empty request never
     * passes. `ALL` requires every requested ability held; `ANY` requires at least one.
     *
     * @param array<int, string> $abilities
     * @param array<int, string> $held
     */
    private function noTargetCheckPasses(array $abilities, array $held, AbilityMatchMode $matchMode): bool
    {
        if ($abilities === []) {
            return false;
        }

        return $matchMode === AbilityMatchMode::ALL
            ? count($held) === count($abilities)
            : $held !== [];
    }

    /**
     * Normalize the `$target` argument of a check into either a concrete row
     * (a `Model` instance or a key string) or `null` (a no-target check).
     *
     * A **class-string** is not a row: naming this schema's own model class — or the
     * schema class itself — is how a no-target check is expressed positionally; it
     * resolves to `null`. A class-string for a *different* `Model`/`WarrantSchema`
     * is a mistake — the ability belongs to another schema — and throws. Any other
     * string is a target key and is left untouched for the row path.
     *
     * `null` and a `Model` instance pass straight through.
     */
    private function resolveCheckTarget(Model|string|null $target): Model|string|null
    {
        if ($target === null || $target instanceof Model) {
            return $target;
        }

        $model = $this->schema::model;

        if (($model !== '' && is_a($target, $model, true)) || is_a($target, $this->schema::class, true)) {
            return null;
        }

        if (is_a($target, Model::class, true) || is_a($target, WarrantSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Target [%s] does not belong to schema [%s]%s; pass this schema\'s model or schema class for a no-target check, or an instance/key for a row check.',
                $target,
                $this->schema::class,
                $model !== '' ? sprintf(' (model [%s])', $model) : '',
            ));
        }

        return $target;
    }
}
