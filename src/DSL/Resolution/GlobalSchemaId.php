<?php

namespace Warrant\DSL\Resolution;

use InvalidArgumentException;
use Warrant\DSL\ISchemaCatalog;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;

/**
 * A schema listed in the `warrant.global` config, which has no key.
 *
 * It is only ever made for a global schema: a class that is not among a
 * catalog's global schemas is refused here, and a catalog lists only classes it
 * knows are global schemas. So whatever holds one may take it as naming a
 * global, and making one loads no class.
 */
final readonly class GlobalSchemaId implements ISchemaId
{
    /**
     * @var class-string<IGlobalSchema>
     */
    public string $class;

    /**
     * @param string $class The global schema's class.
     * @param ISchemaCatalog $listedIn A catalog that lists it among its global
     *   schemas.
     */
    public function __construct(string $class, ISchemaCatalog $listedIn)
    {
        foreach ($listedIn->globalClasses() as $global) {
            if (strcasecmp($global, ltrim($class, '\\')) === 0) {
                $this->class = $global;

                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Schema [%s] is not a global schema; the catalog lists no global schema by that class.',
            $class,
        ));
    }

    public function schema(): IGlobalSchema
    {
        return Warrant::registry()->globalSchema($this->class);
    }
}
