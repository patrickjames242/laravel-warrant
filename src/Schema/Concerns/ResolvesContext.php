<?php

namespace Warrant\Schema\Concerns;

use InvalidArgumentException;

/**
 * Schema-side context policy: merging the schema's {@see \Warrant\Schema\WarrantSchema::defaultContext()}
 * under an explicit check context and enforcing the schema's required-context
 * rules. This is pure schema configuration — it takes no user — so it stays on
 * the schema (the definition), and the {@see \Warrant\WarrantGuardForSchema}
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
