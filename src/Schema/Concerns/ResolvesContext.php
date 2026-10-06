<?php

namespace Warrant\Schema\Concerns;

use InvalidArgumentException;

/**
 * Schema-side context policy: merging the schema's {@see \Warrant\Schema\WarrantSchema::defaultContext()}
 * under an explicit check context and enforcing the schema's required-context
 * rules. This is pure schema configuration — it takes no user — so it stays on
 * the schema (the definition), and the {@see \Warrant\Guard\WarrantGuardForSchema}
 * engine calls into it when evaluating a check.
 */
trait ResolvesContext
{
    /**
     * Merge the explicitly-passed context over the schema's {@see \Warrant\Schema\WarrantSchema::defaultContext},
     * then enforce that every schema-wide required context key is present. Explicit
     * values win over defaults; partial explicit context is allowed. Throws when a
     * `#[RequiredContext]` key is missing from the effective context — for every
     * check on the schema, so a required frame can never be silently skipped (which
     * would lift a context-gated `cannot`). Per-ability requirements are enforced
     * separately by {@see assertAbilitiesHaveRequiredContext}.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveEffectiveContext(array $context): array
    {
        $effective = array_merge($this->defaultContext(), $context);

        $missing = array_values(array_diff(static::requiredContextKeys(), array_keys($effective)));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] requires context key(s) [%s]; supply them at the check or via defaultContext().',
                static::class,
                implode(', ', $missing),
            ));
        }

        return $effective;
    }

    /**
     * The context a `can(<ability>)` of this schema compiles under: $context, the
     * context the rule holding the reference was given, merged over
     * {@see \Warrant\Schema\WarrantSchema::defaultContext}. Answers null when a
     * `#[RequiredContext]` key, or a key $ability requires, is missing from it.
     *
     * The missing key is one the caller did not pass, and a check made with it
     * answers the reference, so the reference answers unknown rather than throwing:
     * it neither grants nor lifts a deny.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public function contextForReference(array $context, string $ability): ?array
    {
        $effective = array_merge($this->defaultContext(), $context);

        return $this->missingRequiredContext($effective, $ability) === [[], []] ? $effective : null;
    }

    /**
     * The context this schema sees when another schema's rule reaches it through a
     * `can(... for <schema>)` or `check(... for <schema>)`: the reference's `with`
     * map merged over {@see \Warrant\Schema\WarrantSchema::defaultContext}.
     *
     * Throws when a `#[RequiredContext]` key, or a key $ability requires, is missing
     * from it. The calling schema's context never crosses, so the key is one the
     * `with` map does not name and this schema does not default: no context a
     * caller passes can supply it, and only the rule text can.
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
        $effective = array_merge($this->defaultContext(), $with);

        [$schemaMissing, $abilityMissing] = $this->missingRequiredContext($effective, $ability);

        if ($schemaMissing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] requires context key(s) [%s]; pass them in the `with` map of the reference to '
                    .'[%s], or via its defaultContext().',
                static::class,
                implode(', ', $schemaMissing),
                $schemaKey,
            ));
        }

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
     * The `#[RequiredContext]` keys missing from $effective, and the keys $ability
     * requires that are missing from it.
     *
     * @param array<string, mixed> $effective
     * @return array{list<string>, list<string>}
     */
    private function missingRequiredContext(array $effective, ?string $ability): array
    {
        return [
            array_values(array_diff(static::requiredContextKeys(), array_keys($effective))),
            $ability === null ? [] : static::partitionAbilitiesByContext([$ability], $effective)['missing'][$ability] ?? [],
        ];
    }

    /**
     * Throw when a *named* ability's per-ability required context (declared via
     * `#[Ability(requiredContext: [...])]`) is missing from the effective context. Used
     * by the assertion paths (a targeted check / an explicit no-target check);
     * enumeration paths skip such abilities instead via
     * {@see \Warrant\Schema\Concerns\ReflectsSchemaDefinition::partitionAbilitiesByContext}.
     *
     * @param array<int, string> $abilities
     * @param array<string, mixed> $context
     */
    public static function assertAbilitiesHaveRequiredContext(array $abilities, array $context): void
    {
        $missing = static::partitionAbilitiesByContext($abilities, $context)['missing'];

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
}
