<?php

namespace Warrant\DSL\Resolution;

use InvalidArgumentException;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;

/**
 * A schema listed in the `warrant.global` config, which has no key.
 *
 * It is only ever made for a global schema: a class that does not implement
 * {@see IGlobalSchema} is refused here, so whatever holds one may take it as
 * naming a global.
 */
final readonly class GlobalSchemaId implements ISchemaId
{
    /**
     * @var class-string<IGlobalSchema>
     */
    public string $class;

    /**
     * @param string $class A class implementing {@see IGlobalSchema}. Checking it
     *   loads the class.
     */
    public function __construct(string $class)
    {
        if (! is_a($class, IGlobalSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] does not implement %s, so it is not a global schema.',
                $class,
                IGlobalSchema::class,
            ));
        }

        $this->class = $class;
    }

    public function schema(): IGlobalSchema
    {
        return Warrant::registry()->globalSchema($this->class);
    }
}
