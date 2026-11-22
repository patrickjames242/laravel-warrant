<?php

namespace Warrant\Guard\Concerns;

use InvalidArgumentException;
use Warrant\Schema\Context\ContextPolicy;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * What a schema's definition says about a request made against it: which of the
 * abilities asked for it declares, what its context declarations require, which
 * model it names, and whether it has rows to target.
 *
 * Used by everything that answers a request against one schema, a guard and a
 * filter over users alike.
 *
 * @property-read IAbilitySchema $schema
 */
trait ReadsAbilitySchema
{
    private ?ContextPolicy $contextPolicy = null;

    /**
     * What the schema's context declarations mean for a request.
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
     * A request about one row needs a schema with rows, so a schema without them
     * refuses it.
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
