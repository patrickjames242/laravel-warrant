<?php

namespace Warrant\Schema\RuleTemplates;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class RuleTemplate
{
    /**
     * @param bool $callOncePerAbility Call the template once for each ability the
     *   `@include` names, each call's rules taking that ability alone, instead of
     *   once for all of them. Only a template taking a {@see RuleTemplateContext}
     *   can tell the calls apart, so the flag requires one.
     */
    public function __construct(
        public ?string $key = null,
        public bool $callOncePerAbility = false,
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('RuleTemplate key cannot be empty.');
        }
    }
}
