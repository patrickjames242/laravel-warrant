<?php

namespace Warrant;

use Attribute;

/**
 * Marks a schema method as a **computed ability**: an ability answered by
 * running the method (returning `bool` or an Illuminate `Response`) rather than
 * by compiling a rule to SQL. It is evaluated only when the ability is **named**
 * in a check and is **no-target** (naming one against a concrete row throws). A
 * named computed ability may be combined with compiled abilities in one no-target
 * check, resolved under the match mode.
 *
 * It is **excluded from every enumeration** — the per-row SQL lists
 * (`selectUserAbilities`/`loadUserAbilities`) and the no-target lists
 * (`getUserAbilities()`, `getNoTargetAbilitiesBag()`) alike: a list of "what the
 * user can do" is the compiled vocabulary only. Query scopes never resolve a
 * computed ability and reject one named explicitly.
 *
 * The method receives a single {@see \Warrant\Schema\ComputedAbilityContext}
 * (the user + effective context) and may implement whatever logic it likes.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class ComputedAbility
{
    /**
     * @param string|null $name Ability name; defaults to the snake_cased method name.
     * @param array<int, string> $requiredContext Context keys required when this ability is checked.
     */
    public function __construct(
        public ?string $name = null,
        public array $requiredContext = [],
    ) {}
}
