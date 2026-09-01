<?php

namespace Warrant\DSL\Parsing;

use InvalidArgumentException;

/**
 * Reconciles the two ways a rule or rule set may name its schema: a `for <schema>`
 * header in the syntax and an explicit `$schema` argument. Shared by
 * {@see WarrantRule} and {@see WarrantRuleSet}.
 *
 * These are factory-level semantic checks that run after parsing, where no source
 * token remains, so they throw {@see InvalidArgumentException} — parse-position
 * errors (bad headers, stray braces) are raised earlier by the parser as
 * {@see WarrantSyntaxException}.
 */
trait NormalizesRuleSchema
{
    /**
     * Reconcile a header-derived schema key with a param-derived one (both already
     * normalized to keys, or null). Both present and unequal → error. When
     * $required, neither present → error. Otherwise returns the header if set,
     * else the param, else null.
     */
    private static function reconcileSchemaKey(?string $header, ?string $param, bool $required): ?string
    {
        if ($header !== null && $param !== null && $header !== $param) {
            throw new InvalidArgumentException(sprintf(
                "The `for %s` header disagrees with the \$schema argument '%s'.",
                $header,
                $param,
            ));
        }

        $resolved = $header ?? $param;

        if ($required && $resolved === null) {
            throw new InvalidArgumentException(
                'A rule set must name a schema: give a `for <schema>` header in the syntax or pass the $schema argument.'
            );
        }

        return $resolved;
    }
}
