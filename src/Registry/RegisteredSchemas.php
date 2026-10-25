<?php

namespace Warrant\Registry;

use OutOfBoundsException;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\ISchemaVocabulary;
use Warrant\Facades\Warrant;

/**
 * The schemas the application registers, as an {@see ISchemaCatalog}: each found
 * through the {@see SchemaRegistry}, which reads `config/warrant.php`.
 *
 * Finding a schema loads its class, and with it the model it names, so this is
 * for use inside a running application. The registry is reached only when a
 * schema is first asked for.
 */
final class RegisteredSchemas implements ISchemaCatalog
{
    public function find(string $schemaKey): ?ISchemaVocabulary
    {
        try {
            $schemaClass = Warrant::registry()->resolveSchemaClassOrFail($schemaKey);
        } catch (OutOfBoundsException) {
            return null;
        }

        return new $schemaClass;
    }
}
