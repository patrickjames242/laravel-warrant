<?php

namespace Warrant\Registry;

use Warrant\DSL\ISchemaCatalog;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;
use Warrant\Schema\Vocabulary;

/**
 * The schemas the application registers, as an {@see ISchemaCatalog}: each found
 * through the {@see SchemaRegistry}, which reads `config/warrant.php`.
 *
 * Finding a schema loads its class, and with it the model it names, so this is
 * for use inside a running application. The registry is reached only when a
 * schema is first asked for, and each schema's vocabulary is read once.
 */
final class RegisteredSchemas implements ISchemaCatalog
{
    /**
     * @var array<string, Vocabulary|null>
     */
    private array $vocabularies = [];

    /**
     * @var array<class-string<IGlobalSchema>, Vocabulary>
     */
    private array $globalVocabularies = [];

    public function find(string $schemaKey): ?Vocabulary
    {
        if (! array_key_exists($schemaKey, $this->vocabularies)) {
            $resolved = Warrant::registry()->resolveOrNull($schemaKey);

            $this->vocabularies[$schemaKey] = $resolved === null ? null : Vocabulary::of($resolved->schema);
        }

        return $this->vocabularies[$schemaKey];
    }

    public function globalClasses(): array
    {
        return Warrant::registry()->globalClasses();
    }

    public function findGlobal(string $class): Vocabulary
    {
        return $this->globalVocabularies[$class] ??= Vocabulary::of(Warrant::registry()->globalSchema($class));
    }
}
