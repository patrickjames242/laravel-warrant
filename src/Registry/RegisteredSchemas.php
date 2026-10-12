<?php

namespace Warrant\Registry;

use OutOfBoundsException;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;

/**
 * The schemas the application registers, as a {@see SchemaCatalog}: each found
 * through the {@see SchemaRegistry}, which reads `config/warrant.php`.
 *
 * Finding a schema loads its class, and with it the model it names, so this is
 * for use inside a running application. The registry is reached only when a
 * schema is first asked for.
 */
final class RegisteredSchemas implements SchemaCatalog
{
    public function find(string $schemaKey): ?SchemaVocabulary
    {
        try {
            $schemaClass = Warrant::registry()->resolveSchemaClassOrFail($schemaKey);
        } catch (OutOfBoundsException) {
            return null;
        }

        return new $schemaClass;
    }
}
