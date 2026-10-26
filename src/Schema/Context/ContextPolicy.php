<?php

namespace Warrant\Schema\Context;

use InvalidArgumentException;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;

/**
 * What a schema's context declarations mean for a check: the context it runs
 * under, and whether that context has the keys the schema and its abilities
 * require.
 *
 * It reads only what the schema declares, through {@see IProvidesContext} and the
 * abilities' required context, so it takes no user and decides nothing about
 * access. The engine asks it at each place a context is formed:
 *
 *  - a check names abilities, and a missing key throws ({@see resolveEffectiveContext},
 *    {@see assertAbilitiesHaveRequiredContext});
 *  - a listing enumerates abilities, and one missing a key is left out
 *    ({@see partitionAbilitiesByContext});
 *  - a `can(<ability>)` in the same schema answers unknown when a key is missing,
 *    since the caller did not pass it ({@see contextForReference});
 *  - a hop into this schema throws, since only the rule text's `with` map can
 *    supply the key ({@see resolveBoundaryContext}).
 *
 * Each merges the schema's default context under what it is given, explicit
 * values winning.
 */
final readonly class ContextPolicy
{
    public function __construct(private IAbilitySchema $schema)
    {
    }

    /**
     * $context merged over the schema's default context, after checking it has
     * every key the schema requires. A required key can never be skipped, since
     * that would lift a `cannot` gated on it.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveEffectiveContext(array $context): array
    {
        $effective = $this->merged($context);
        $missing = $this->missingSchemaKeys($effective);

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().',
                $this->schema::class,
                implode(', ', $missing),
            ));
        }

        return $effective;
    }

    /**
     * The context a `can(<ability>)` of this schema compiles under: $context, the
     * context the rule holding the reference was given, merged over the default.
     * Null when it lacks a key the schema or $ability requires: the missing key is
     * one the caller did not pass, so the reference answers unknown rather than
     * throwing, and neither grants nor lifts a deny.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function contextForReference(array $context, string $ability): ?array
    {
        $effective = $this->merged($context);

        return $this->missingSchemaKeys($effective) === [] && $this->missingAbilityKeys($ability, $effective) === []
            ? $effective
            : null;
    }

    /**
     * The context this schema sees when another schema's rule reaches it through
     * `can(... for <schema>)` or `check(... for <schema>)`: the reference's `with`
     * map merged over the default.
     *
     * The calling schema's context never crosses, so a missing key is one the
     * `with` map does not name and this schema does not default. Only the rule
     * text can supply it, so this throws.
     *
     * @param array<string, mixed> $with
     * @param string $schemaKey The key the reference names this schema by, for the
     *   error message.
     * @param string|null $ability The ability a `can(...)` asks about; null for a
     *   `check(...)`, which names none.
     * @return array<string, mixed>
     */
    public function resolveBoundaryContext(array $with, string $schemaKey, ?string $ability = null): array
    {
        $effective = $this->merged($with);
        $schemaMissing = $this->missingSchemaKeys($effective);

        if ($schemaMissing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] requires context key(s) [%s]; pass them in the `with` map of the reference to '
                    .'[%s], or via its defaultContext().',
                $this->schema::class,
                implode(', ', $schemaMissing),
                $schemaKey,
            ));
        }

        $abilityMissing = $ability === null ? [] : $this->missingAbilityKeys($ability, $effective);

        if ($abilityMissing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Ability [%s] requires context key(s) [%s]; pass them in the `with` map of the reference to '
                    .'[%s], or via its defaultContext().',
                $ability,
                implode(', ', $abilityMissing),
                $schemaKey,
            ));
        }

        return $effective;
    }

    /**
     * $abilities split by whether $context has the keys each requires.
     * `satisfied` keeps its input order; `missing` maps each unsatisfied ability
     * to the keys it lacks. A caller that named the abilities throws on `missing`;
     * one that only enumerates them keeps `satisfied`.
     *
     * @param list<string> $abilities
     * @param array<string, mixed> $context
     * @return array{satisfied: list<string>, missing: array<string, list<string>>}
     */
    public function partitionAbilitiesByContext(array $abilities, array $context): array
    {
        $satisfied = [];
        $missing = [];

        foreach ($abilities as $ability) {
            $needed = $this->missingAbilityKeys($ability, $context);

            if ($needed === []) {
                $satisfied[] = $ability;
            } else {
                $missing[$ability] = $needed;
            }
        }

        return ['satisfied' => $satisfied, 'missing' => $missing];
    }

    /**
     * Throws when $context lacks a key one of the named $abilities requires.
     *
     * @param list<string> $abilities
     * @param array<string, mixed> $context
     */
    public function assertAbilitiesHaveRequiredContext(array $abilities, array $context): void
    {
        $missing = $this->partitionAbilitiesByContext($abilities, $context)['missing'];

        if ($missing === []) {
            return;
        }

        $ability = array_key_first($missing);

        throw new InvalidArgumentException(sprintf(
            'Ability [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().',
            $ability,
            implode(', ', $missing[$ability]),
        ));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function merged(array $context): array
    {
        return array_merge($this->schema->defaultContext(), $context);
    }

    /**
     * @param array<string, mixed> $effective
     * @return list<string>
     */
    private function missingSchemaKeys(array $effective): array
    {
        return array_values(array_diff($this->schema->requiredContextKeys(), array_keys($effective)));
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function missingAbilityKeys(string $ability, array $context): array
    {
        return array_values(array_diff($this->schema->ability($ability)?->requiredContext ?? [], array_keys($context)));
    }
}
