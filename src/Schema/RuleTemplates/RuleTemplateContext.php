<?php

namespace Warrant\Schema\RuleTemplates;

use LogicException;

/**
 * What a `#[RuleTemplate]` method may be told about the `@include` it is
 * expanding: the abilities its rules will take.
 *
 * A template asks for it by declaring it as its first parameter; the DSL
 * arguments follow. The abilities are the ones the include names, from its own
 * `for` list, the ability block around it, or the include that expanded the
 * template it sits in, exactly as written, so the wildcard arrives as `*`.
 *
 * The template still answers with rules that name no abilities. Knowing them
 * lets it choose what those rules say, and the include applies them as it always
 * does.
 */
final readonly class RuleTemplateContext
{
    /**
     * @param list<string> $abilities The abilities this call's rules take: all of
     *   the include's, or one of them for a template declared
     *   `#[RuleTemplate(callOncePerAbility: true)]`.
     * @param bool $calledOncePerAbility Whether the template is declared
     *   `callOncePerAbility`, so this call is for one ability alone.
     */
    public function __construct(
        public array $abilities,
        public bool $calledOncePerAbility = false,
    ) {
    }

    /**
     * The one ability this call is for.
     *
     * Only a template declared `callOncePerAbility` is called for one ability,
     * so any other is refused here, even when its include happens to name only
     * one: the template would break the day a second is added.
     */
    public function ability(): string
    {
        if (! $this->calledOncePerAbility) {
            throw new LogicException(
                'RuleTemplateContext::ability() is only for a rule template declared '
                    .'#[RuleTemplate(callOncePerAbility: true)]; any other is called once for all of its '
                    .'include\'s abilities, which are in $abilities.',
            );
        }

        return $this->abilities[0];
    }
}
