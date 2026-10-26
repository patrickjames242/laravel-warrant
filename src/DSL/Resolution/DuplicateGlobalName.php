<?php

namespace Warrant\DSL\Resolution;

use Warrant\Schema\Types\Interfaces\IGlobalSchema;

/**
 * A condition or rule template name declared by two global schemas.
 *
 * A name is looked up in the global schemas in the order they are listed, so the
 * later global's construct could never be reached. That is a mistake, not a way
 * to override one, and is refused.
 */
final readonly class DuplicateGlobalName
{
    /**
     * @param 'condition'|'rule template' $kind What the name names.
     * @param string $name The name.
     * @param class-string<IGlobalSchema> $declaredFirst The global listed first
     *   that declares it.
     * @param class-string<IGlobalSchema> $declaredAgain A global listed after it
     *   that declares it too.
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $declaredFirst,
        public string $declaredAgain,
    ) {
    }

    public function message(): string
    {
        return sprintf(
            'Global schemas [%s] and [%s] both declare %s [%s]; a name may be declared by only one global schema, '
                .'since the one listed first would always be found. Rename one of them.',
            $this->declaredFirst,
            $this->declaredAgain,
            $this->kind,
            $this->name,
        );
    }
}
