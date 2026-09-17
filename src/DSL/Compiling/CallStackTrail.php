<?php

namespace Warrant\DSL\Compiling;

use Warrant\Rules\IncludeInvocation;
use Warrant\Rules\IncludeTrail;

/**
 * The {@see IncludeTrail} for an expansion asked for during a compile.
 *
 * Every descent becomes a {@see Call} on the compiler's own {@see CallStack}, so
 * a template that will not terminate is caught by the one depth budget that
 * bounds the whole compile, and the trace it throws with names the ability and
 * check hops that led to the rule set — which a chain of template names alone
 * could not say.
 *
 * The stack it carries lives only as long as the expansion. Expansion runs before
 * a rule set's rules are folded, and what comes out are ordinary rules compiled
 * under the context the rule set already had, so the include frames bound and
 * report the expansion rather than following the rules it produced.
 */
final readonly class CallStackTrail implements IncludeTrail
{
    /**
     * @param class-string $schemaClass The schema whose templates are expanding —
     *   the one the including rule set targets.
     */
    public function __construct(
        private CallStack $callStack,
        private string $schemaClass,
    ) {
    }

    public function entering(IncludeInvocation $include): static
    {
        return new self(
            $this->callStack->enter(Call::include($this->schemaClass, $include->templateKey, $include->arguments)),
            $this->schemaClass,
        );
    }
}
